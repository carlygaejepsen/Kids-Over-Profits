"""
Back up the Unsilenced archive documents KOP lists, and title them from their content.

The facility and operator pages list Unsilenced documents KOP has no copy of
(js/data/unsilenced/, scripts/build-unsilenced-links.py), under Unsilenced's
own file names, which are often opaque ("0235.pdf", "DocumentInquiry-45.pdf").
Owner decisions (2026-10-02, docs/PLAN.md 3.11):

  - keep a copy of every listed file on the owner's Google Drive
    (default destination "I:/My Drive/Unsilenced archive backup"), in
    Unsilenced's own folder layout, each checked against Unsilenced's md5;
  - title the links from the content. Titles made here never introduce a
    personal name: court papers by document type, case number and filing
    date; state reports by report type, program and date; newspaper
    clippings by paper, date and headline (the headline is kept as printed).
    Anything else keeps Unsilenced's name.

Commands
  run [--limit N] [--dest DIR] [--no-keep] [--no-ocr]
      Download every listed file not done yet (resumable), verify its md5,
      save it under DEST/<state>/<program>/<subfolders>/<name>, and write
      title evidence (PDF Title, first two pages of text, OCR of a scan's
      first page, OCR of small images) to tmp/unsilenced-titles/evidence.jsonl.
      --no-keep only reads PDFs and images for titles and keeps nothing.
      Pauses while C: (where Google Drive for desktop buffers uploads) has
      under 4 GB free, or DEST has under the file's size + 2 GB.
  titles
      tmp/unsilenced-titles/titles.json {id: title} from the evidence, for
      the files whose content gave a better title than their name; report in
      tmp/unsilenced-titles/titles-report.md. scripts/build-unsilenced-links.py
      reads titles.json.
  status
      Done, failed and remaining counts, GB saved, and whether a run is
      working or paused.

The full run is long (about 59,000 files, 118 GB): start it detached from
Windows PowerShell so it survives the session,
  Start-Process python -ArgumentList 'scripts/unsilenced-backup.py','run' -WindowStyle Hidden
and follow tmp/unsilenced-titles/run.log or `status`.
"""
import argparse
import collections
import ctypes
import datetime
import glob
import hashlib
import html
import json
import os
import random
import re
import shutil
import subprocess
import sys
import tempfile
import time
import zipfile

import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA = os.path.join(ROOT, 'js', 'data', 'unsilenced')
FILES = os.path.join(ROOT, 'tmp', 'unsilenced', 'files.jsonl')
WORK = os.path.join(ROOT, 'tmp', 'unsilenced-titles')
STATE = os.path.join(WORK, 'state.jsonl')
EVIDENCE = os.path.join(WORK, 'evidence.jsonl')
STATUS = os.path.join(WORK, 'status.json')
LOG = os.path.join(WORK, 'run.log')
LOCK = os.path.join(WORK, 'run.lock')
TITLES = os.path.join(WORK, 'titles.json')
REPORT = os.path.join(WORK, 'titles-report.md')
DEFAULT_DEST = 'I:/My Drive/Unsilenced archive backup'

UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
      'Chrome/126.0.0.0 Safari/537.36')
INTERVAL = 1.75          # seconds between download starts
C_MIN_FREE = 4 << 30     # pause while C: has less than this
DEST_MARGIN = 2 << 30    # DEST must keep this much beyond the file
BIG = 200 << 20          # files this large go to the end of the queue
OCR_IMAGE_MAX = 8 << 20  # images larger than this are not OCR'd
MAX_ATTEMPTS = 3
PERMANENT = {'not found', 'not public', 'google shortcut', 'google file not exportable'}

GOOGLE_EXPORT = {
    'application/vnd.google-apps.spreadsheet': ('https://docs.google.com/spreadsheets/d/%s/export?format=xlsx', '.xlsx'),
    'application/vnd.google-apps.document': ('https://docs.google.com/document/d/%s/export?format=docx', '.docx'),
    'application/vnd.google-apps.presentation': ('https://docs.google.com/presentation/d/%s/export/pptx', '.pptx'),
}


def tool(name):
    """A poppler or Tesseract executable, also when started outside Git Bash."""
    found = shutil.which(name)
    if found:
        return found
    for d in (r'C:\Program Files\poppler-24.08.0\Library\bin', r'C:\Program Files\Tesseract-OCR',
              r'C:\Program Files\Git\mingw64\bin'):
        p = os.path.join(d, name + '.exe')
        if os.path.exists(p):
            return p
    for p in glob.glob(r'C:\Program Files\poppler-*\Library\bin\%s.exe' % name):
        return p
    return None


# ---------------------------------------------------------------- the listing

def listed_files():
    """Every file the KOP pages list, with its archive metadata:
    [{id, name, mime, md5, size, path, folder}]."""
    shard_ids = {}
    for p in glob.glob(os.path.join(DATA, '[fo]', '*.json')):
        with open(p, encoding='utf8') as fh:
            for f in json.load(fh).get('files') or []:
                shard_ids.setdefault(f['id'], f)
    rows = {}
    with open(FILES, encoding='utf8') as fh:
        for line in fh:
            line = line.strip()
            if not line:
                continue
            r = json.loads(line)
            if r['id'] in shard_ids and r['id'] not in rows:
                r['size'] = int(r.get('size') or 0)
                rows[r['id']] = r
    for i, f in shard_ids.items():
        if i not in rows:  # listed but not in the file list: should not happen
            rows[i] = {'id': i, 'name': f['name'], 'mime': '', 'md5': '', 'size': 0, 'path': []}
    return rows


NOISE = set('doc docs document documents documentinquiry inquiry scan scanned img image images file files page pages '
            'copy compliance history incident incidents report reports citations reportcitations redacted main '
            'clipping version final pdf jpg jpeg png tif tiff part new untitled screenshot screen shot photo '
            'gov uscourts llo cbs ccm the and for from with exhibit attachment'.split())


def name_words(name):
    base = re.sub(r'\.[A-Za-z0-9]{2,4}$', '', name or '')
    base = re.sub(r'([a-z])([A-Z])', r'\1 \2', base)  # DocumentInquiry -> Document Inquiry
    return [w.lower() for w in re.findall(r'[A-Za-z]{3,}', base)]


def opaque(name, path=()):
    """True when a file name says little about the document: numbers,
    camera or scanner names, Facebook ids, "Compliance History 38", or only
    the program's own name ("Cinnamon Hills 3 - 12" in Cinnamon Hills)."""
    base = re.sub(r'\.[A-Za-z0-9]{2,4}$', '', name or '')
    if re.fullmatch(r'[A-Za-z0-9_\-]{20,}', base) and not re.search(r'[a-z]{4,}', base):
        return True
    folder = set()
    for p in path or ():
        folder |= set(name_words(p))
    told = [w for w in name_words(name) if w not in NOISE]
    new = [w for w in told if w not in folder]
    # "Cherry-Gulch-Application" says what it is; "Abraxas C5538" in
    # Abraxas's folder and "McNamara 15SV00041" do not.
    return not new or (len(new) == 1 and len(told) <= 1)


def queue_order(rows):
    """Opaque names first (they gain most from a title), very large files
    last; otherwise a stable shuffle, so any prefix is a fair sample."""
    def key(r):
        return (r['size'] > BIG, not opaque(r['name'], r.get('path')), hashlib.sha1(r['id'].encode()).hexdigest())
    return sorted(rows.values(), key=key)


ILLEGAL = re.compile(r'[<>:"/\\|?*\x00-\x1f]')
RESERVED = re.compile(r'^(con|prn|aux|nul|com[1-9]|lpt[1-9])(\..*)?$', re.I)


def clean_part(s, limit=100, keep_ext=False):
    s = ILLEGAL.sub('_', s or '').strip()
    s = s.rstrip(' .') or '_'
    if RESERVED.match(s):
        s = '_' + s
    if len(s) > limit:
        if keep_ext:
            stem, ext = os.path.splitext(s)
            ext = ext if len(ext) <= 8 else ''
            s = stem[:limit - len(ext)].rstrip(' .') + ext
        else:
            s = s[:limit].rstrip(' .')
    return s


def file_name(r):
    name = r['name'] or r['id']
    exp = GOOGLE_EXPORT.get(r.get('mime') or '')
    if exp and not name.lower().endswith(exp[1]):
        name += exp[1]
    return name


def plan_paths(rows):
    """{id: relative path} mirroring Unsilenced's folders, Windows-safe and
    unique ignoring case: a second file of the same name gets its id."""
    out, used = {}, set()
    for r in sorted(rows.values(), key=lambda r: r['id']):
        folders = [clean_part(p, 80) for p in (r.get('path') or [])] or ['_unfiled']
        name = clean_part(file_name(r), 120, keep_ext=True)
        # Keep the whole path well under Windows' 260 characters.
        while len('/'.join(folders + [name])) > 200 and any(len(f) > 30 for f in folders):
            i = max(range(len(folders)), key=lambda k: len(folders[k]))
            folders[i] = folders[i][:max(30, len(folders[i]) - 20)].rstrip(' .')
        rel = '/'.join(folders + [name])
        if rel.lower() in used:
            stem, ext = os.path.splitext(name)
            rel = '/'.join(folders + ['%s [%s]%s' % (stem, r['id'][:10], ext)])
        used.add(rel.lower())
        out[r['id']] = rel
    return out


# ---------------------------------------------------------------- state files

def read_jsonl(path):
    out = {}
    if os.path.exists(path):
        with open(path, encoding='utf8') as fh:
            for line in fh:
                line = line.strip()
                if not line:
                    continue
                try:
                    r = json.loads(line)
                except ValueError:
                    continue  # a line cut off when a run was killed
                out[r['id']] = r
    return out


def append_jsonl(path, rec):
    with open(path, 'a', encoding='utf8', newline='\n') as fh:
        fh.write(json.dumps(rec, ensure_ascii=False) + '\n')


def log(msg):
    line = '%s %s' % (datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S'), msg)
    print(line, flush=True)
    with open(LOG, 'a', encoding='utf8', newline='\n') as fh:
        fh.write(line + '\n')


def write_status(**kw):
    kw.update(pid=os.getpid(), updated=datetime.datetime.now().isoformat(timespec='seconds'))
    tmp = STATUS + '.tmp'
    with open(tmp, 'w', encoding='utf8') as fh:
        json.dump(kw, fh)
    os.replace(tmp, STATUS)


def pid_alive(pid):
    if not pid:
        return False
    if os.name == 'nt':
        # os.kill(pid, 0) would terminate the process on Windows.
        h = ctypes.windll.kernel32.OpenProcess(0x1000, False, int(pid))  # QUERY_LIMITED_INFORMATION
        if not h:
            return False
        code = ctypes.c_ulong()
        ctypes.windll.kernel32.GetExitCodeProcess(h, ctypes.byref(code))
        ctypes.windll.kernel32.CloseHandle(h)
        return code.value == 259  # STILL_ACTIVE
    try:
        os.kill(int(pid), 0)
        return True
    except OSError:
        return False


def free_bytes(path):
    p = os.path.abspath(path)
    while not os.path.exists(p):
        parent = os.path.dirname(p)
        if parent == p:
            break
        p = parent
    return shutil.disk_usage(p).free


# ---------------------------------------------------------------- downloading

class Backoff(Exception):
    """Google is limiting this client: wait, then try the same file again."""


class Failed(Exception):
    pass


def classify_html(body, status):
    low = body.lower()
    if status == 404 or 'not found' in low[:3000] and 'error 404' in low:
        return 'not found'
    if 'download-form' in low or 'virus scan' in low or "can't scan" in low or 'can&#39;t scan' in low:
        return 'confirm'
    if 'quota exceeded' in low or 'too many users have viewed' in low:
        return 'quota'
    if 'unusual traffic' in low or '/sorry/' in low or 'captcha' in low:
        return 'rate limited'
    if 'servicelogin' in low or 'you need access' in low or 'request access' in low:
        return 'not public'
    return 'html page'


def confirm_request(body):
    """The large-file page ("can't scan this file for viruses") holds a form
    whose hidden inputs make the real download request."""
    m = re.search(r'<form[^>]*id="download-form"[^>]*action="([^"]+)"', body)
    action = html.unescape(m.group(1)) if m else 'https://drive.usercontent.google.com/download'
    params = {}
    for name, value in re.findall(r'<input[^>]*type="hidden"[^>]*name="([^"]+)"[^>]*value="([^"]*)"', body):
        params[name] = html.unescape(value)
    return action, params


def fetch(session, r, out_path):
    """Stream one file to out_path; (bytes, md5). Raises Backoff or Failed."""
    exp = GOOGLE_EXPORT.get(r.get('mime') or '')
    if (r.get('mime') or '').startswith('application/vnd.google-apps.') and not exp:
        raise Failed('google shortcut' if r['mime'].endswith('shortcut') else 'google file not exportable')
    url, params = (exp[0] % r['id'], {}) if exp else (
        'https://drive.usercontent.google.com/download', {'id': r['id'], 'export': 'download'})
    server_errors = 0
    for _ in range(5):
        try:
            resp = session.get(url, params=params, stream=True, timeout=(30, 180))
        except requests.RequestException as e:
            raise Failed('connection: %s' % type(e).__name__)
        if resp.status_code == 429:
            resp.close()
            raise Backoff('HTTP 429')
        ctype = resp.headers.get('Content-Type', '')
        if resp.status_code >= 400 or 'text/html' in ctype:
            body = resp.raw.read(1 << 20, decode_content=True).decode('utf8', 'replace')
            resp.close()
            kind = classify_html(body, resp.status_code)
            if kind == 'confirm':
                url, params = confirm_request(body)
                continue
            if kind == 'rate limited':
                raise Backoff('rate limited page')
            if resp.status_code >= 500 and server_errors < 2:
                server_errors += 1  # Google's own hiccup: wait and ask again
                time.sleep(20 * server_errors)
                continue
            if resp.status_code == 403 and kind == 'html page':
                raise Failed('HTTP 403')
            raise Failed(kind if kind != 'html page' else 'HTTP %d html' % resp.status_code)
        h, n = hashlib.md5(), 0
        try:
            with open(out_path, 'wb') as fh:
                for chunk in resp.iter_content(1 << 20):
                    fh.write(chunk)
                    h.update(chunk)
                    n += len(chunk)
        except requests.RequestException as e:
            raise Failed('cut off: %s' % type(e).__name__)
        finally:
            resp.close()
        return n, h.hexdigest()
    raise Failed('confirm page loop')


# ---------------------------------------------------------------- evidence

def run_tool(args, timeout=90):
    try:
        p = subprocess.run(args, capture_output=True, timeout=timeout,
                           creationflags=getattr(subprocess, 'CREATE_NO_WINDOW', 0))
        return p.stdout.decode('utf8', 'replace')
    except (subprocess.TimeoutExpired, OSError):
        return ''


def ocr(image_path):
    exe = tool('tesseract')
    if not exe:
        return ''
    return run_tool([exe, image_path, 'stdout', '--psm', '3'], timeout=90)[:2500]


def ocr_pdf_first_page(pdf_path):
    exe = tool('pdftoppm')
    if not exe:
        return ''
    d = tempfile.mkdtemp(prefix='kop-ocr-')
    try:
        run_tool([exe, '-r', '200', '-gray', '-f', '1', '-l', '1', '-png', pdf_path, os.path.join(d, 'p')], timeout=90)
        pngs = glob.glob(os.path.join(d, '*.png'))
        return ocr(pngs[0]) if pngs else ''
    finally:
        shutil.rmtree(d, ignore_errors=True)


def words(text):
    return len(re.findall(r'[A-Za-z]{3,}', text or ''))


def kind_of(path, r):
    with open(path, 'rb') as fh:
        head = fh.read(8)
    mime = r.get('mime') or ''
    if head.startswith(b'%PDF'):
        return 'pdf'
    if mime.startswith('image/') or head[:3] == b'\xff\xd8\xff' or head.startswith(b'\x89PNG'):
        return 'image'
    if head.startswith(b'PK') and (r['name'].lower().endswith('.docx') or 'wordprocessingml' in mime):
        return 'docx'
    return 'other'


def extract(path, r, do_ocr=True):
    """Title evidence for one downloaded file."""
    ev = {'id': r['id'], 'name': r['name'], 'kind': kind_of(path, r)}
    if ev['kind'] == 'pdf':
        info = run_tool([tool('pdfinfo') or 'pdfinfo', '-enc', 'UTF-8', path], timeout=60)
        for key in ('Title', 'Pages', 'Creator', 'Producer'):
            m = re.search(r'^%s:\s*(.*)$' % key, info, re.M)
            if m and m.group(1).strip():
                ev[key.lower()] = m.group(1).strip()[:300]
        ev['text'] = run_tool([tool('pdftotext') or 'pdftotext', '-enc', 'UTF-8', '-l', '2', path, '-'],
                              timeout=90)[:3000]
        if words(ev['text']) < 15 and do_ocr:
            ev['ocr'] = ocr_pdf_first_page(path)
    elif ev['kind'] == 'image':
        if do_ocr and os.path.getsize(path) <= OCR_IMAGE_MAX:
            ev['ocr'] = ocr(path)
    elif ev['kind'] == 'docx':
        try:
            with zipfile.ZipFile(path) as z:
                xml = z.read('word/document.xml').decode('utf8', 'replace')
            xml = re.sub(r'</w:p>', '\n', xml)
            ev['text'] = html.unescape(re.sub(r'<[^>]+>', '', xml))[:3000]
        except (zipfile.BadZipFile, KeyError, OSError):
            pass
    return ev


def md5_of(path):
    h = hashlib.md5()
    with open(path, 'rb') as fh:
        for chunk in iter(lambda: fh.read(1 << 20), b''):
            h.update(chunk)
    return h.hexdigest()


# ---------------------------------------------------------------- run

def wait_for_space(dest, need, keep):
    """Block while C: or DEST is short of space; True when a pause happened."""
    paused_at, last_log = None, 0
    while True:
        c_free = free_bytes(os.environ.get('SystemDrive', 'C:') + '\\')
        d_free = free_bytes(dest)
        short = []
        if c_free < C_MIN_FREE + (0 if keep else need):
            short.append('C: has %.1f GB free' % (c_free / 1e9))
        if keep and d_free < need + DEST_MARGIN:
            short.append('%s has %.1f GB free, this file needs %.1f GB + 2' % (dest, d_free / 1e9, need / 1e9))
        if not short:
            if paused_at:
                log('space is back, resuming after %d min' % ((time.time() - paused_at) // 60))
                write_status(state='running')
            return bool(paused_at)
        if not paused_at:
            paused_at = time.time()
        if time.time() - last_log > 600:
            log('PAUSED: ' + '; '.join(short))
            last_log = time.time()
        write_status(state='paused', reason='; '.join(short), since=datetime.datetime.fromtimestamp(paused_at).isoformat(timespec='seconds'))
        time.sleep(60)


def cmd_run(args):
    os.makedirs(WORK, exist_ok=True)
    if os.path.exists(LOCK):
        try:
            other = int(open(LOCK).read().strip() or 0)
        except ValueError:
            other = 0
        if other and other != os.getpid() and pid_alive(other):
            sys.exit('A run is already working (pid %d). See: python scripts/unsilenced-backup.py status' % other)
    with open(LOCK, 'w') as fh:
        fh.write(str(os.getpid()))
    try:
        _run(args)
    finally:
        try:
            os.remove(LOCK)
        except OSError:
            pass


def _run(args):
    keep = not args.no_keep
    dest = os.path.abspath(args.dest)
    rows = listed_files()
    paths = plan_paths(rows)
    state = read_jsonl(STATE)
    evidence = read_jsonl(EVIDENCE)
    scratch = os.path.join(WORK, 'work')
    os.makedirs(scratch, exist_ok=True)

    def done(i):
        s = state.get(i)
        if not s:
            return False
        if s['status'] in ('ok', 'mismatch'):
            if not keep:
                return True
            # Done for this destination only: a test run elsewhere does not count.
            p = os.path.join(dest, s['rel'])
            return os.path.exists(p) and os.path.getsize(p) == s.get('bytes')
        if s['status'] == 'read':
            return not keep
        if s['status'] == 'skipped':
            return True
        return s['status'] == 'failed' and (s.get('attempts', 1) >= MAX_ATTEMPTS or s.get('reason') in PERMANENT)

    todo = [r for r in queue_order(rows) if not done(r['id'])]
    if not keep:  # reading for titles only: documents and images
        todo = [r for r in todo if r['id'] not in evidence]
    log('run: %d listed, %d to do, dest=%s, keep=%s' % (len(rows), len(todo), dest if keep else '-', keep))
    session = requests.Session()
    session.headers.update({'User-Agent': UA, 'Accept': '*/*', 'Accept-Language': 'en-US,en;q=0.9'})
    backoff, ok_streak, consecutive_403, last_start = 0, 0, 0, 0.0
    counts = collections.Counter()
    t_run = time.time()
    n = 0
    queue = collections.deque(todo)
    while queue and (not args.limit or n < args.limit):
        r = queue[0]
        rel = (state.get(r['id']) or {}).get('rel') or paths[r['id']]
        if not keep and not ((r.get('mime') or '').startswith('image/') or (r.get('mime') or '') == 'application/pdf'):
            queue.popleft()
            continue
        wait_for_space(dest, r['size'], keep)
        target = os.path.join(dest, rel) if keep else os.path.join(scratch, r['id'] + os.path.splitext(rel)[1])
        os.makedirs(os.path.dirname(target), exist_ok=True)
        rec = {'id': r['id'], 'rel': rel, 'dest': dest if keep else '', 'size': r['size']}

        # A copy already on disk (state lost, or a second destination) counts when its md5 matches.
        if keep and os.path.exists(target) and r.get('md5') and os.path.getsize(target) == r['size'] \
                and md5_of(target) == r['md5']:
            rec.update(status='ok', bytes=r['size'], md5_ok=True, note='already on disk')
        else:
            wait = INTERVAL - (time.time() - last_start)
            if wait > 0:
                time.sleep(wait)
            last_start = time.time()
            part = target + '.part'
            try:
                got, md5 = fetch(session, r, part)
                if r.get('md5') and md5 != r['md5']:
                    time.sleep(INTERVAL)
                    got, md5 = fetch(session, r, part)
                os.replace(part, target)
                consecutive_403 = 0
                ok_streak += 1
                if ok_streak >= 20:
                    backoff = 0
                md5_ok = (md5 == r['md5']) if r.get('md5') else None
                rec.update(status='ok' if md5_ok is not False else 'mismatch', bytes=got, md5_ok=md5_ok,
                           md5=md5, secs=round(time.time() - last_start, 2))
                if md5_ok is False:
                    log('md5 MISMATCH %s %s (kept the copy)' % (r['id'], rel))
            except Backoff as e:
                backoff = min(backoff + 1, 6)
                pause = [60, 120, 300, 600, 1200, 1800][backoff - 1]
                log('BACKOFF %s on %s: waiting %d s' % (e, r['id'], pause))
                write_status(state='backoff', reason=str(e), seconds=pause)
                ok_streak = 0
                time.sleep(pause)
                continue  # the same file again
            except Failed as e:
                reason = str(e)
                if reason == 'HTTP 403':
                    consecutive_403 += 1
                    if consecutive_403 >= 3:
                        consecutive_403 = 0
                        backoff = min(backoff + 1, 6)
                        pause = [60, 120, 300, 600, 1200, 1800][backoff - 1]
                        log('BACKOFF three 403s in a row: waiting %d s' % pause)
                        time.sleep(pause)
                prev = state.get(r['id']) or {}
                rec.update(status='failed', reason=reason,
                           attempts=(prev.get('attempts', 0) + 1) if prev.get('status') == 'failed' else 1)
                try:
                    os.remove(part)
                except OSError:
                    pass
            except OSError as e:
                rec.update(status='failed', reason='disk: %s' % e.__class__.__name__, attempts=1)
        queue.popleft()
        n += 1
        if rec['status'] in ('ok', 'mismatch'):
            if r['id'] not in evidence or args.reextract:
                try:
                    ev = extract(target, r, do_ocr=not args.no_ocr)
                    ev['path'] = rel
                    append_jsonl(EVIDENCE, ev)
                    evidence[r['id']] = ev
                except OSError as e:
                    log('evidence failed for %s: %s' % (r['id'], e))
            if not keep:
                rec['status'] = 'read'
                try:
                    os.remove(target)
                except OSError:
                    pass
        append_jsonl(STATE, rec)
        state[r['id']] = rec
        counts[rec['status'] if rec['status'] != 'failed' else 'failed: ' + rec['reason']] += 1
        log('%5d %-8s %7.1f MB %s%s' % (n, rec['status'], (rec.get('bytes') or r['size']) / 1e6, rel,
                                      ('  (' + rec['reason'] + ')') if rec.get('reason') else ''))
        if n % 10 == 0:
            write_status(state='running', done_this_run=n, left=len(queue), rate=round((time.time() - t_run) / n, 2))
    took = time.time() - t_run
    log('run finished: %d files in %.0f s (%.2f s/file): %s' % (n, took, took / max(n, 1), dict(counts)))
    write_status(state='finished', done_this_run=n, left=len(queue), rate=round(took / max(n, 1), 2))


# ---------------------------------------------------------------- titles

MONTHS = {m: i for i, m in enumerate('jan feb mar apr may jun jul aug sep oct nov dec'.split(), 1)}
MONTH_NAMES = 'Jan Feb Mar Apr May Jun Jul Aug Sep Oct Nov Dec'.split()
US_STATES = set('alabama alaska arizona arkansas california colorado connecticut delaware florida georgia hawaii '
                'idaho illinois indiana iowa kansas kentucky louisiana maine maryland massachusetts michigan '
                'minnesota mississippi missouri montana nebraska nevada ohio oklahoma oregon pennsylvania '
                'tennessee texas utah vermont virginia washington wisconsin wyoming columbia'.split()) | {
    'new hampshire', 'new jersey', 'new mexico', 'new york', 'north carolina', 'north dakota', 'rhode island',
    'south carolina', 'south dakota', 'west virginia', 'puerto rico'}
SEP = r'[\u00b7\u2022\ufffd|\-]'
LIMIT = 118


def nice_date(y, m, d):
    try:
        return '%s %d, %d' % (MONTH_NAMES[int(m) - 1], int(d), int(y))
    except (ValueError, IndexError):
        return ''


def us_date(s):
    """'11/18/11' or '2/3/2021' -> 'Nov 18, 2011'."""
    m = re.match(r'(\d{1,2})/(\d{1,2})/(\d{2,4})$', (s or '').strip())
    if not m:
        return ''
    y = int(m.group(3))
    if y < 100:
        y += 2000 if y < 50 else 1900
    return nice_date(y, m.group(1), m.group(2))


def long_date(s):
    """'SEPTEMBER 6, 2019' or 'May 21, 2018' -> 'Sep 6, 2019'."""
    m = re.match(r'([A-Za-z]{3})[A-Za-z]*\.? (\d{1,2}), ?(\d{4})$', (s or '').strip())
    if not m or m.group(1).lower() not in MONTHS:
        return ''
    return nice_date(m.group(3), MONTHS[m.group(1).lower()], m.group(2))


LONG_DATE = (r'((?:January|February|March|April|May|June|July|August|September|October|November|December)'
             r' \d{1,2}, ?\d{4})')


def clip(s, n):
    s = re.sub(r'\s+', ' ', s or '').strip()
    if len(s) <= n:
        return s
    cut = s[:n - 1].rsplit(' ', 1)[0].rstrip(' ,;:-')
    return cut + '\u2026'


def tidy_name(s):
    """A program name from a report, in title case when it was shouted."""
    s = re.sub(r'\s+', ' ', s or '').strip(' ,.-')
    if s.isupper():
        small = {'of', 'and', 'the', 'for', 'at', 'in', 'on'}
        parts = []
        for i, w in enumerate(s.lower().split()):
            if w in ('llc', 'inc', 'inc.', 'prtf', 'rtc', 'ii', 'iii', 'usa'):
                parts.append(w.upper().replace('INC', 'Inc'))
            elif i and w in small:
                parts.append(w)
            else:
                parts.append(w[:1].upper() + w[1:])
        s = ' '.join(parts)
    return s


def t_newspaper(ev, text):
    meta = ev.get('title') or ''
    if 'newspapers.com' not in meta.lower() and 'newspapers.com' not in text.lower():
        return None
    paper = date = page = ''
    m = re.search(r'^\s*(.+?)\s*\(([^)\n]*)\)\s*' + SEP + r'?\s*(?:(\d{1,2}) ([A-Z][a-z]{2}) (\d{4})|'
                  r'(?:[A-Z][a-z]{2}, )?([A-Z][a-z]{2}) (\d{1,2}), (\d{4}))[^\n]*?Page\s+(\S+)', text, re.M)
    if m:
        paper = re.sub(r'^\W+', '', m.group(1)).strip()
        if m.group(3):
            date = nice_date(m.group(5), MONTHS.get(m.group(4).lower(), 0), m.group(3))
        else:
            date = nice_date(m.group(8), MONTHS.get(m.group(6).lower(), 0), m.group(7))
        page = m.group(9)
    headline = re.sub(r'\s*-\s*Newspapers\.com\s*$', '', meta, flags=re.I) if 'newspapers.com' in meta.lower() else ''
    if not headline:
        # The line after "Downloaded on ..." is the clipping's title.
        lm = re.search(r'Downloaded on [A-Z][a-z]{2} \d{1,2}, \d{4}\s*\n\s*([^\n]+)', text)
        if lm and 'copyright' not in lm.group(1).lower():
            headline = lm.group(1)
    if re.match(r'Clipping from ', headline):  # newspapers.com's default, no headline
        headline = ''
    headline = re.sub(r'(^|\s-\s*)\d{4}-\d{2}-?\d{2}(\s*-\s|$)', ' - ', headline).strip(' -')
    headline = re.sub(r'\s*-?\s*\b[Pp]art (\d+)\b', r', part \1', headline)
    headline = re.sub(r'_+', ' ', headline).strip(' -')
    where = ', '.join(x for x in (paper, date, ('p. ' + page) if page else '') if x)
    if not headline:
        return 'Newspaper clipping' + (' - ' + where if where else '')
    room = LIMIT - len(where) - 3
    return clip(headline, max(room, 40)) + (' (%s)' % where if where else '')


COURT_TYPES = [
    ('CIVIL COVER SHEET', 'Civil cover sheet'), ('REPORT AND RECOMMENDATION', 'Report and recommendation'),
    ('AMENDED COMPLAINT', 'Amended complaint'), ('COMPLAINT', 'Complaint'), ('PETITION', 'Petition'),
    ('MEMORANDUM DECISION', 'Memorandum decision'), ('MEMORANDUM OPINION', 'Memorandum opinion'),
    ('MEMORANDUM', 'Memorandum'), ('MOTION', 'Motion'), ('STIPULATION', 'Stipulation'),
    ('JUDGMENT', 'Judgment'), ('ORDER', 'Order'), ('ANSWER', 'Answer'), ('DECLARATION', 'Declaration'),
    ('AFFIDAVIT', 'Affidavit'), ('DEPOSITION', 'Deposition'), ('TRANSCRIPT', 'Transcript'),
    ('EXHIBIT', 'Exhibit'), ('SUMMONS', 'Summons'), ('NOTICE', 'Notice'), ('BRIEF', 'Brief'),
    ('INDICTMENT', 'Indictment'), ('PLEA AGREEMENT', 'Plea agreement'), ('VERDICT', 'Verdict'),
    ('OPINION', 'Opinion'), ('REPLY', 'Reply'), ('RESPONSE', 'Response'), ('OPPOSITION', 'Opposition'),
    ('SUBPOENA', 'Subpoena'), ('DOCKET', 'Docket'),
]


def court_doc_type(text):
    """The document type from the first shouted line naming one (never the
    line itself, which may name a party)."""
    for line in text.splitlines()[:80]:
        letters = re.sub(r'[^A-Za-z]', '', line)
        if len(letters) < 4:
            continue
        if sum(c.isupper() for c in letters) < 0.8 * len(letters):
            # "MOTION FOR ORDER OF COMPLIANCE Plaintiff, ... hereby moves"
            lead = re.match(r"\s*((?:[A-Z][A-Z'.,&-]*\s+){1,10}[A-Z][A-Z'.,&-]*)(?=\s+[A-Z]?[a-z])", line)
            if not lead:
                continue
            line = lead.group(1)
        if re.search(r'DISTRICT COURT|COURT OF APPEALS|SUPREME COURT|^\s*CASE NO', line, re.I):
            continue
        hits = [(line.upper().find(k), label) for k, label in COURT_TYPES
                if re.search(r'\b%s\b' % k, line.upper())]
        if hits:
            return min(hits)[1]
    return ''


def court_state(text):
    for m in re.finditer(r'DISTRICT OF ([A-Za-z]+(?: [A-Za-z]+)?)', text[:4000], re.I):
        for cand in (m.group(1).lower(), m.group(1).lower().split()[0]):
            if cand in US_STATES:
                return cand.title()
    m = re.search(r'USDC ([A-Z][a-z]+(?: [A-Z][a-z]+)?)', text[:400])
    if m and m.group(1).lower() in US_STATES:
        return m.group(1)
    return ''


def t_court(ev, text):
    m = re.search(r'Case:?\s*(\d{1,2}:\d{2}-[a-zA-Z]{2,4}-\d{2,6})[\w\-]*\s+(?:Document|Doc\.?|Dkt\.?)\s*#?:?\s*'
                  r'(\d+(?:-\d+)?)\s+Filed:?\s*(\d{1,2}/\d{1,2}/\d{2,4})', text[:600], re.I)
    if m:
        kind = court_doc_type(text[m.end():]) or 'Court filing'
        st = court_state(text)
        bits = ['case %s' % m.group(1).lower()] + (['US District Court, %s' % st] if st else []) + [
            'doc. %s' % m.group(2), 'filed %s' % us_date(m.group(3))]
        return clip('%s - %s' % (kind, ', '.join(b for b in bits if b and not b.endswith('filed '))), LIMIT)
    m = re.search(r'^\s*(\d{1,2}:\d{2}-[a-zA-Z]{2,4}-\d{2,6})[\w\-]*\s+Date Filed:?\s*(\d{1,2}/\d{1,2}/\d{2,4})\s+'
                  r'Entry Number\s*(\d+)', text[:600])
    if m:
        kind = court_doc_type(text[m.end():]) or 'Court filing'
        st = court_state(text)
        bits = ['case %s' % m.group(1).lower()] + (['US District Court, %s' % st] if st else []) + [
            'doc. %s' % m.group(3), 'filed %s' % us_date(m.group(2))]
        return clip('%s - %s' % (kind, ', '.join(bits)), LIMIT)
    # Connecticut Superior Court (the judicial branch's DocumentInquiry files).
    m = re.search(r'^\s*DOCKET NO[.:]*\s*([A-Z0-9][A-Z0-9\-]{5,24})', text[:300], re.M)
    if m and 'SUPERIOR COURT' in text[:800]:
        kind = court_doc_type(text[m.end():]) or 'Court filing'
        dm = re.search(r'^\s*((?:JANUARY|FEBRUARY|MARCH|APRIL|MAY|JUNE|JULY|AUGUST|SEPTEMBER|OCTOBER|NOVEMBER|DECEMBER)'
                       r' \d{1,2}, \d{4}|\d{1,2}/\d{1,2}/\d{4})\s*$', text[:1500], re.M | re.I)
        when = (us_date(dm.group(1)) or long_date(dm.group(1))) if dm else ''
        return clip('%s - Connecticut Superior Court, docket %s%s' % (kind, m.group(1), ', ' + when if when else ''), LIMIT)
    m = re.search(r'(?:Appellate )?Case:\s*(\d{2}-\d{3,6})\s+Document:\s*(\d+)[\s\S]{0,80}?(?:Date )?Filed:\s*'
                  r'(\d{1,2}/\d{1,2}/\d{2,4})', text[:600])
    if m:
        kind = court_doc_type(text[m.end():]) or 'Court filing'
        return clip('%s - US Court of Appeals, case %s, filed %s' % (kind, m.group(1), us_date(m.group(3))), LIMIT)
    # State courts: "IN THE CIRCUIT COURT OF ... COUNTY" and a case number.
    cm = re.search(r'IN THE ((?:[A-Z][A-Za-z.]* ){0,4}?(?:CIRCUIT|SUPERIOR|DISTRICT|CHANCERY|JUVENILE|PROBATE|'
                   r'COUNTY|JUSTICE|MUNICIPAL|FAMILY|SUPREME|CRIMINAL|CIVIL) COURT)'
                   r'(?:\s+(?:OF|FOR|IN AND FOR)\s+(?:THE\s+)?(?:STATE OF\s+)?([A-Z][A-Z ]{2,30}?)\s*(?:COUNTY|,|\n|$))?',
                   text[:3000])
    nm = re.search(r'(?:Case|Cause|Civil Action|Docket|Criminal)\s*(?:No\.?|Number|#)\s*:?\s*'
                   r'([A-Z0-9][A-Z0-9\-:./]{3,24})', text[:3000], re.I)
    if cm and nm:
        court = tidy_name(cm.group(1))
        place = tidy_name(cm.group(2) or '')
        kind = court_doc_type(text[cm.end():]) or 'Court filing'
        fm = re.search(r'FILED\W{0,5}(\d{1,2}/\d{1,2}/\d{2,4})', text[:3000], re.I)
        bits = [court + (', ' + place + (' County' if 'county' in text[cm.start():cm.end() + 10].lower() else '')
                         if place else ''), 'case ' + nm.group(1).rstrip('.')]
        if fm:
            bits.append('filed ' + us_date(fm.group(1)))
        return clip('%s - %s' % (kind, ', '.join(bits)), LIMIT)
    return None


def t_texas(ev, text):
    if not re.search(r'Compliance History Details List|Child Care Search', text[:400]):
        return None
    m = re.search(r'Operation Name:\s*([^\n]+?)\s*(?:Operation (?:Number|Type)|E-mail|\n)', text)
    num = re.search(r'Operation Number:\s*(\d+)', text)
    otype = re.search(r'Operation Type:\s*([^\n]+?)\s*(?:Program Provided|\n|$)', text)
    name = tidy_name(m.group(1)) if m else ''
    if otype and re.search(r'foster|family home|agency home', otype.group(1), re.I):
        name = ''  # a foster home is named after the family
    kind = 'Texas compliance history' if 'Compliance History' in text[:400] else 'Texas child care search record'
    bits = [b for b in (name, ('operation %s' % num.group(1)) if num else '') if b]
    # A long history prints as many files: say which entries this one holds.
    seen = []
    for d in re.findall(r'\b(\d{1,2})/(\d{1,2})/(\d{4})\b', text):
        try:
            seen.append(datetime.date(int(d[2]), int(d[0]), int(d[1])))
        except ValueError:
            pass
    if seen:
        lo, hi = min(seen), max(seen)
        span = nice_date(lo.year, lo.month, lo.day) + ('' if lo == hi else ' to ' + nice_date(hi.year, hi.month, hi.day))
        bits.append('entries ' + span)
    return clip(kind + (' - ' + ', '.join(bits) if bits else ''), LIMIT) if bits else None


def t_arizona(ev, text):
    m = re.search(r'^\s*((?:Complaint )?Statement of Deficiencies)\s*\n\s*Survey Date\s*-\s*(\d{1,2}/\d{1,2}/\d{4})'
                  r'\s*\n\s*([^\n]+)', text, re.M)
    if not m:
        return None
    return clip('Arizona %s - %s, survey %s' % (m.group(1).lower(), tidy_name(m.group(3)), us_date(m.group(2))), LIMIT)


def t_arkansas(ev, text):
    if 'Division of Provider Services & Quality Assurance' in text[:200] and 'AR 722' in text[:300]:
        dm = re.search(LONG_DATE, text[:600])
        return clip('Arkansas DHS provider services (DPSQA) letter' + (' - ' + long_date(dm.group(1)) if dm else ''), LIMIT)
    if 'Arkansas Department of Human Services' not in text[:300] and not (
            'Early Childhood Education' in text[:300] and 'Little Rock, AR' in text[:300]):
        return None
    rm = re.search(r'^\s*((?:\d{3} )?[A-Z][A-Za-z ]{3,50}(?:Report|Notice|Record))\s*$', text[:1500], re.M)
    lm = re.search(r'Licensee:\s*\n\s*([^\n]+)', text)
    dm = re.search(r'(?:Visit|Incident|Report) Date:\s*\n?\s*(\d{1,2}/\d{1,2}/\d{4})', text)
    kind = re.sub(r'^\d{3} ', '', rm.group(1)).strip().lower() if rm else 'licensing report'
    bits = [b for b in (tidy_name(lm.group(1)) if lm else '', us_date(dm.group(1)) if dm else '') if b]
    return clip('Arkansas DHS %s - %s' % (kind, ', '.join(bits)) if bits else 'Arkansas DHS ' + kind, LIMIT)


def t_form990(ev, text):
    if not re.search(r'Form\s*990', text[:3000]) or not re.search(r'Exempt From Income Tax|Return of Private Foundation',
                                                                   text[:3000], re.I):
        return None
    form = '990-PF' if re.search(r'990-PF', text[:3000]) else '990-EZ' if re.search(r'990-EZ', text[:3000]) else '990'
    ym = (re.search(r'For the (\d{4}) calendar year', text) or re.search(r'tax year beginning[^\n\d]{0,20}'
                                                                         r'(?:\d{1,2}/\d{1,2}/)?(\d{4})', text)
          or re.search(r'\b(19|20)(\d{2})\b\s*\n?\s*(?:Open to Public|OMB)', text))
    year = ''.join(g for g in ym.groups() if g) if ym else ''
    om = re.search(r'Name of organization\s*\n\s*([^\n]{4,80})', text)
    org = tidy_name(om.group(1)) if om and not re.search(r'Doing business|Number and street', om.group(1)) else ''
    bits = [b for b in (org, ('tax year ' + year) if year else '') if b]
    return clip('IRS Form %s' % form + (' - ' + ', '.join(bits) if bits else ''), LIMIT)


def t_nc(ev, text):
    if 'Division of Health Service Regulation' not in text[:300] or 'STATEMENT OF DEFICIENCIES' not in text[:400]:
        return None
    nm = re.search(r'STREET ADDRESS, CITY, STATE, ZIP CODE\s*\n\s*([^\n]+)', text)
    dm = re.search(r'\(X3\) DATE SURVEY COMPLETED[\s\S]{0,200}?(?<!PRINTED: )\b(\d{2}/\d{2}/\d{4})', text[:1500])
    bits = [b for b in (tidy_name(nm.group(1)) if nm else '', ('survey ' + us_date(dm.group(1))) if dm else '') if b]
    return clip('North Carolina statement of deficiencies' + (' - ' + ', '.join(bits) if bits else ''), LIMIT)


def t_cms(ev, text):
    if 'CENTERS FOR MEDICARE' not in text[:300] or 'STATEMENT OF DEFICIENCIES' not in text[:400]:
        return None
    nm = re.search(r'NAME OF PROVIDER OR SUPPLIER[ \t]+([^\n]+)', text[:1500])
    dm = re.search(r'B\. WING[^\n]*\n\s*(?:[A-C]\s+)?(\d{2}/\d{2}/\d{4})', text[:1500])
    bits = [b for b in (tidy_name(nm.group(1)) if nm else '', ('survey ' + us_date(dm.group(1))) if dm else '') if b]
    return clip('CMS statement of deficiencies' + (' - ' + ', '.join(bits) if bits else ''), LIMIT)


def t_sod_facility(ev, text):
    """Georgia's "STATEMENT OF DEFICIENCIES / FACILITY INFORMATION" printout (and any like it)."""
    m = re.match(r'\s*STATEMENT OF DEFICIENCIES\s*\n\s*FACILITY INFORMATION\s*\n\s*Facility ID:[^\n]*\n\s*([^\n]+)', text)
    if not m:
        return None
    st = re.search(r'\n\s*([A-Z]{2}) \d{5}\s*\n', text[:600])
    dm = re.search(r'Start Date:\s*Exit Date:\s*\n\s*\S+\s+(\d{1,2}/\d{1,2}/\d{4})', text[:900])
    kind = 'Georgia statement of deficiencies' if st and st.group(1) == 'GA' else 'Statement of deficiencies'
    bits = [tidy_name(re.sub(r'^.*\bDBA\s+', '', m.group(1)))] + (['survey ' + us_date(dm.group(1))] if dm else [])
    return clip(kind + ' - ' + ', '.join(bits), LIMIT)


def t_michigan(ev, text):
    if not re.search(r'STATE OF MICHIGAN\s*(?:\n\s*)?DEPARTMENT OF', text[:400]):
        return None
    dm = re.search(LONG_DATE, text[:600])
    low = text.lower()
    kind = ('special investigation' if 'special investigation report' in low else
            'renewal inspection' if 'renewal' in low[:2000] else
            'licensing study' if 'licensing study' in low else 'inspection' if 'inspection' in low[:2000] else '')
    lic = re.search(r'License #:\s*([A-Z]{2}\d{6,})', text)
    fac = re.search(r'RE: License #[^\n]*\n\s*([^\n]{3,80})', text)
    fac = tidy_name(fac.group(1)) if fac and not re.match(r'(Dear|[A-Z]{2}\d{6}|[A-Z]+ [A-Z]+\s*$|[A-Z]+ [A-Z]+ DIRECTOR)',
                                                           fac.group(1)) else ''
    # Foster and family homes are licensed in the family's name: only
    # institutions (CI), placing agencies (CB) and county homes (CO) are named.
    if not lic or lic.group(1)[:2] not in ('CI', 'CB', 'CO'):
        fac = ''
    bits = [b for b in (fac, ('license ' + lic.group(1)) if lic else '', long_date(dm.group(1)) if dm else '') if b]
    return clip('Michigan DHHS licensing letter' + (' (%s)' % kind if kind else '') + (' - ' + ', '.join(bits) if bits else ''), LIMIT)


def t_minnesota(ev, text):
    lic = re.search(r'License (?:Number|No\.?):?\s*(\d{5,})', text[:1500])
    if not lic or not re.search(r'\bMN \d{5}|Minnesota', text[:1500]):
        return None
    dm = re.search(LONG_DATE, text[:400])
    low = text[:2500].lower()
    kind = next((k for k in ('order of conditional license', 'correction order', 'investigation report', 'licensing review',
                             'order to forfeit', 'order of revocation', 'order of suspension') if k in low), 'licensing letter')
    pm = re.search(r'Authorized Agent\s*\n\s*([^\n]{3,80})', text[:800])
    bits = [b for b in (tidy_name(pm.group(1)) if pm else '', 'license ' + lic.group(1),
                        long_date(dm.group(1)) if dm else '') if b]
    return clip('Minnesota DHS %s - %s' % (kind, ', '.join(bits)), LIMIT)


def t_oregon(ev, text):
    if 'Restraint and Involuntary Seclusion Report' not in text[:200] or 'Oregon' not in text[:600]:
        return None
    q = re.search(r'\bQ([1-4]),?\s*(\d{2,4})\b', text[:1500])
    when = ''
    if q:
        y = int(q.group(2))
        when = 'Q%s %d' % (q.group(1), y + 2000 if y < 100 else y)
    return 'Oregon restraint and seclusion report' + (' - ' + when if when else '')


def t_ohio(ev, text):
    if 'Ohio Department of Mental Health' not in text[:200] or 'Notification of Incident' not in text[:300]:
        return None
    im = re.search(r'WEIRS Assigned Incident Number:\s*(\S+)', text)
    dm = re.search(r'Date/Time of Incident:\s*(\d{1,2}/\d{1,2}/\d{4})', text)
    bits = [b for b in (('incident ' + im.group(1)) if im else '', us_date(dm.group(1)) if dm else '') if b]
    return clip('Ohio MHAS incident notification' + (' - ' + ', '.join(bits) if bits else ''), LIMIT)


def t_pennsylvania(ev, text):
    if 'Office of Children, Youth and Families' in text[:300] and 'Pennsylvania' in text[:1500]:
        dm = re.search(LONG_DATE, text[:400])
        lic = re.search(r'LICENSE/COC#:\s*(\d+)', text[:1500])
        bits = [b for b in (('license ' + lic.group(1)) if lic else '', long_date(dm.group(1)) if dm else '') if b]
        return clip('Pennsylvania DHS licensing letter' + (' - ' + ', '.join(bits) if bits else ''), LIMIT)
    m = re.search(r'^\s*CERTIFICATE OF COMPLIANCE[\s\S]{0,300}?To operate\s+([^\n]{3,80})', text[:600], re.M)
    if m:
        return clip('Certificate of compliance - ' + tidy_name(m.group(1).split(':')[0]), LIMIT)
    return None


def t_oklahoma(ev, text):
    m = re.match(r'\s*Summary of Facility Monitoring\s*\n\s*Facility Name:\s*(.+?)\s+Case Number', text)
    if not m:
        return None
    dm = re.search(r'Date:\s*(\d{1,2}/\d{1,2}/\d{4})', text[:400])
    return clip('Oklahoma facility monitoring summary - %s%s' % (
        tidy_name(m.group(1)), (', printed ' + us_date(dm.group(1))) if dm else ''), LIMIT)


SHAPES = [('newspaper', t_newspaper), ('court', t_court), ('tx compliance', t_texas), ('az deficiencies', t_arizona),
          ('ar dhs', t_arkansas), ('form 990', t_form990), ('nc deficiencies', t_nc), ('cms deficiencies', t_cms),
          ('deficiencies', t_sod_facility), ('mi licensing', t_michigan), ('mn licensing', t_minnesota),
          ('or restraint report', t_oregon), ('oh incident', t_ohio), ('pa licensing', t_pennsylvania),
          ('ok monitoring', t_oklahoma)]


def make_title(ev, row):
    """(shape, title) or (None, None). Newspapers always get their headline;
    other shapes only replace a name that says little."""
    texts = [t.replace('\r', '') for t in (ev.get('text'), ev.get('ocr')) if t and t.strip()]
    if not texts and ev.get('title'):
        texts = ['']
    for text in texts:
        for shape, fn in SHAPES:
            try:
                t = fn(ev, text)
            except (IndexError, ValueError, AttributeError, KeyError):
                t = None
            if t:
                if shape != 'newspaper' and not opaque(row['name'], row.get('path')):
                    return shape + ' (name kept)', None
                return shape, t
    return None, None


def cmd_titles(args):
    rows = listed_files()
    evidence = read_jsonl(EVIDENCE)
    state = read_jsonl(STATE)
    titles, shapes, samples = {}, collections.Counter(), collections.defaultdict(list)
    ocr_now = 0
    for i, ev in evidence.items():
        row = rows.get(i)
        if not row:
            continue
        # A scan the run did not OCR: read its first page now, if the copy is kept.
        if ev.get('kind') == 'pdf' and words(ev.get('text')) < 15 and 'ocr' not in ev and not args.no_ocr:
            s = state.get(i) or {}
            p = os.path.join(s.get('dest') or '', s.get('rel') or '')
            if s.get('dest') and os.path.exists(p):
                ev['ocr'] = ocr_pdf_first_page(p)
                append_jsonl(EVIDENCE, ev)
                ocr_now += 1
        shape, title = make_title(ev, row)
        label = shape or ('scan, no text' if ev.get('kind') == 'pdf' and words(ev.get('text')) < 15 and
                          words(ev.get('ocr')) < 15 else 'no rule (%s)' % ev.get('kind'))
        shapes[label] += 1
        if title and title != row['name']:
            titles[i] = title
            samples[shape].append((row['name'], title))
    with open(TITLES, 'w', encoding='utf8', newline='\n') as fh:
        json.dump(titles, fh, ensure_ascii=False, indent=0, sort_keys=True)
    read = sum(1 for i in evidence if i in rows)
    lines = ['# Unsilenced titles', '',
             '%d listed files, %d read, %d given a title from their content (%.0f%% of those read).' % (
                 len(rows), read, len(titles), 100.0 * len(titles) / max(read, 1)), '',
             '| Shape | Files |', '|---|---:|'] + ['| %s | %d |' % kv for kv in shapes.most_common()]
    for shape, pairs in sorted(samples.items()):
        lines += ['', '## ' + shape, '']
        random.Random(1).shuffle(pairs)
        lines += ['- `%s` -> %s' % (a, b) for a, b in pairs[:15]]
    with open(REPORT, 'w', encoding='utf8', newline='\n') as fh:
        fh.write('\n'.join(lines) + '\n')
    for k, v in shapes.most_common():
        print('%7d  %s' % (v, k))
    print('%d titles -> %s (report: %s)%s' % (len(titles), os.path.relpath(TITLES, ROOT), os.path.relpath(REPORT, ROOT),
                                             ', %d scans OCR\'d now' % ocr_now if ocr_now else ''))


# ---------------------------------------------------------------- status

def cmd_status(args):
    rows = listed_files()
    state = read_jsonl(STATE)
    evidence = read_jsonl(EVIDENCE)
    c = collections.Counter()
    saved = 0
    reasons = collections.Counter()
    for i, s in state.items():
        if i not in rows:
            continue
        c[s['status']] += 1
        if s['status'] in ('ok', 'mismatch'):
            saved += s.get('bytes') or 0
        if s['status'] == 'failed':
            reasons[s.get('reason')] += 1
    total_bytes = sum(r['size'] for r in rows.values())
    kept = c['ok'] + c['mismatch']
    print('listed files      %7d  (%.1f GB)' % (len(rows), total_bytes / 1e9))
    print('backed up         %7d  (%.1f GB, %d of them md5 mismatch)' % (kept, saved / 1e9, c['mismatch']))
    print('read only         %7d' % c['read'])
    print('failed            %7d  %s' % (c['failed'], dict(reasons.most_common(8))))
    print('remaining         %7d' % (len(rows) - kept - c['failed'] - c['skipped']))
    print('title evidence    %7d' % sum(1 for i in evidence if i in rows))
    if os.path.exists(STATUS):
        st = json.load(open(STATUS, encoding='utf8'))
        alive = pid_alive(st.get('pid'))
        print('last run          %s (pid %s, %s) at %s%s' % (
            st.get('state'), st.get('pid'), 'alive' if alive else 'not running', st.get('updated'),
            ('  - ' + st['reason']) if st.get('reason') else ''))
        if st.get('rate'):
            left = len(rows) - kept - c['failed']
            print('pace              %.2f s/file, about %.1f days left' % (st['rate'], left * st['rate'] / 86400))
    print('free space        C: %.1f GB' % (free_bytes('C:\\') / 1e9))


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    sub = ap.add_subparsers(dest='cmd', required=True)
    r = sub.add_parser('run', help='download, verify, keep and read the listed files')
    r.add_argument('--limit', type=int, default=0, help='stop after this many files')
    r.add_argument('--dest', default=DEFAULT_DEST)
    r.add_argument('--no-keep', action='store_true', help='read PDFs and images for titles, keep nothing')
    r.add_argument('--no-ocr', action='store_true', help='skip OCR (titles can OCR kept scans later)')
    r.add_argument('--reextract', action='store_true', help='read evidence again for files already read')
    t = sub.add_parser('titles', help='titles.json from the evidence')
    t.add_argument('--no-ocr', action='store_true')
    sub.add_parser('status', help='progress')
    args = ap.parse_args()
    os.makedirs(WORK, exist_ok=True)
    if not os.path.exists(FILES):
        sys.exit('Missing %s (see scripts/build-unsilenced-links.py).' % FILES)
    {'run': cmd_run, 'titles': cmd_titles, 'status': cmd_status}[args.cmd](args)


if __name__ == '__main__':
    main()
