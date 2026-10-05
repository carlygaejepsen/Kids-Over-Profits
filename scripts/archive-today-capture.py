"""
Read SCIAD NET's archive.today pages (archive.ph, .is, .md, .vn) in a Chrome
window while the owner answers archive.today's CAPTCHA, so the AI readers on
the server get their text and the links get their original address.

archive.today puts a CAPTCHA in front of every page a script asks for, from
the server and from this PC alike, and SCIAD NET's records never kept the
address a page was saved from. This opens each link in a visible Chrome window
(its own profile in tmp/archive-today/profile/, so a solved CAPTCHA lasts as
long as archive.today allows). When the CAPTCHA comes up, the window comes to
the front with a beep and the script waits for the person to solve it; nothing
here answers it. Between CAPTCHAs it reads one page every 6-12 seconds:
  - the original address from the page's "Saved from" header,
  - the page title and capture date,
  - the archived page's text (#CONTENT).

    python scripts/archive-today-capture.py [--limit N] [--kinds news,reference] [--gap 6-12]
    python scripts/archive-today-capture.py upload     # text + index to ~/kop-import/archive-today/ on the server
    python scripts/archive-today-capture.py status

Reads tmp/sciad/sciad-links.json (court records skipped: their text names
parties). Writes tmp/archive-today/ (gitignored, never committed: whole
articles): text/<md5 of the normalized archive address>.txt, index.json
(md5 of the original address -> md5 of the archive address) and captures.json
(archive key -> original, title, date, characters). Resumable.

After a run: `upload`, then `python scripts/sciad-links.py` (a news row goes in
under its original address, the archive.today link beside it, keeping its key
so a row already decided at KOP Tools > Drive Docs stays decided) and copy
tmp/sciad/sciad-links.json to ~/kop-import/gdocs/. On the server
api/lib-archive-captures.php hands the text to "Fill empty fields with AI",
the hourly news enrich and the lawsuit reader before they try the link.
"""

import argparse
import hashlib
import io
import json
import os
import random
import re
import subprocess
import sys
import tarfile
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tmp', 'archive-today')
TEXT = os.path.join(OUT, 'text')
CAPTURES = os.path.join(OUT, 'captures.json')
INDEX = os.path.join(OUT, 'index.json')
LINKS = os.path.join(ROOT, 'tmp', 'sciad', 'sciad-links.json')
SSH = ['ssh', '-i', os.path.expanduser('~/.ssh/kop_nixihost'), '-p', '1157', 'kidsover@dfw-s07.nixihost.com']
REMOTE_DIR = 'kop-import/archive-today'

ARCHIVE_TODAY = re.compile(r'^https?://archive\.(?:ph|today|is|li|vn|fo|md)/', re.I)
TRACKING = re.compile(r'^(utm_[a-z]+|fbclid|gclid|msclkid|igshid|mc_cid|mc_eid|cmpid|smid|ref)$')
CAPTCHA_WAIT = 30 * 60   # seconds to wait for a person before stopping
# Every mirror serves the same short ids, and a solved CAPTCHA holds on the one domain it
# was solved on: read every link through this one.
MIRROR = 'https://archive.ph/'


def normalize_url(url):
    """kop_normalize_url() (api/url-dedupe.php), fragment dropped."""
    if not isinstance(url, str) or not url.strip():
        return None
    u = re.sub(r'^[a-z][a-z0-9+.\-]*://', '', url.strip(), flags=re.I)
    u = re.sub(r'^www\.', '', u, flags=re.I)
    u = re.sub(r'#.*$', '', u)
    if '?' in u:
        path, q = u.split('?', 1)
        kept = [p for p in q.split('&') if not TRACKING.match(p.split('=', 1)[0].lower())]
        u = path + ('?' + '&'.join(kept) if kept else '')
    u = u.rstrip('/').strip().lower()
    return u or None


def md5(s):
    return hashlib.md5(s.encode('utf-8')).hexdigest()


def load_json(path, default):
    try:
        with open(path, encoding='utf-8') as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return default


def save_json(path, data):
    tmp = path + '.tmp'
    with open(tmp, 'w', encoding='utf-8') as fh:
        json.dump(data, fh, ensure_ascii=False, indent=0, sort_keys=True)
    os.replace(tmp, path)


def todo_rows(kinds):
    seen, rows = set(), []
    for r in load_json(LINKS, []):
        url = re.sub(r'#.*$', '', (r.get('url') or '').strip())
        if not ARCHIVE_TODAY.match(url) or r.get('category') not in kinds:
            continue
        key = normalize_url(url)
        if key and key not in seen:
            seen.add(key)
            rows.append({'key': key, 'url': url, 'kind': r['category'], 'label': r.get('label', '')})
    return rows


EXTRACT = r"""() => {
  const isArchive = h => /(^|\.)archive\.(ph|today|is|li|vn|fo|md)$/i.test(h);
  const outside = u => { try { const x = new URL(u); return /^https?:$/.test(x.protocol) && !isArchive(x.hostname); } catch (e) { return false; } };
  let orig = '';
  for (const q of document.querySelectorAll('input[name="q"]')) {
    if (outside((q.value || '').trim())) { orig = q.value.trim(); break; }
  }
  if (!orig) {
    // The "Saved from" header: the first address written out before the archived page.
    for (const a of document.querySelectorAll('a')) {
      if (a.closest('#CONTENT')) break;
      const t = (a.textContent || '').trim();
      if (/^https?:\/\//i.test(t) && outside(t)) { orig = t; break; }
    }
  }
  const time = document.querySelector('time[itemprop="pubdate"], time[datetime]');
  const content = document.querySelector('#CONTENT') || document.body;
  return { orig, title: document.title || '', captured: time ? (time.getAttribute('datetime') || '') : '',
           text: content ? content.innerText : '', header: orig ? '' : document.body.innerHTML.slice(0, 6000) };
}"""


def is_captcha(page):
    try:
        body = page.evaluate("() => (document.title + ' ' + (document.body ? document.body.innerText.slice(0, 600) : ''))")
    except Exception:
        return False
    return bool(re.search(r'one more step|complete the security check|completing the captcha', body, re.I))


def beep():
    try:
        import winsound
        winsound.Beep(880, 300)
        winsound.Beep(660, 300)
    except Exception:
        print('\a', end='', flush=True)


def wait_for_person(page):
    """True once the page behind the CAPTCHA has loaded, False after CAPTCHA_WAIT."""
    page.bring_to_front()
    beep()
    print('  CAPTCHA: solve it in the Chrome window; the script carries on by itself.', flush=True)
    end = time.time() + CAPTCHA_WAIT
    while time.time() < end:
        time.sleep(2)
        try:
            if not is_captcha(page) and ARCHIVE_TODAY.match(page.url or ''):
                page.wait_for_load_state('domcontentloaded', timeout=30000)
                return True
        except Exception:
            pass
    return False


def clean(text):
    text = text.replace('\r', '')
    text = re.sub(r'[ \t ]+', ' ', text)
    text = re.sub(r' *\n *', '\n', text)
    text = re.sub(r'\n{3,}', '\n\n', text)
    return text.strip()[:60000]


def capture(args):
    from playwright.sync_api import sync_playwright

    kinds = tuple(k.strip() for k in args.kinds.split(',') if k.strip())
    lo, hi = (float(x) for x in args.gap.split('-')) if '-' in args.gap else (float(args.gap), float(args.gap))
    os.makedirs(TEXT, exist_ok=True)
    caps = load_json(CAPTURES, {})
    index = load_json(INDEX, {})
    rows = [r for r in todo_rows(kinds) if r['key'] not in caps or (args.retry_thin and caps[r['key']].get('thin'))]
    if args.limit:
        rows = rows[:args.limit]
    print('%d archive.today links to read (%d read before)' % (len(rows), len(caps)), flush=True)
    done = captchas = 0
    with sync_playwright() as p:
        ctx = p.chromium.launch_persistent_context(os.path.join(OUT, 'profile'), channel='chrome', headless=False, viewport=None)
        page = ctx.pages[0] if ctx.pages else ctx.new_page()
        for i, row in enumerate(rows):
            if page.is_closed():
                print('The Chrome window was closed; stopping. Run again to carry on.')
                break
            try:
                page.goto(ARCHIVE_TODAY.sub(MIRROR, row['url']), wait_until='domcontentloaded', timeout=60000)
            except Exception as e:
                if page.is_closed() or 'closed' in str(e):
                    print('The Chrome window was closed; stopping. Run again to carry on.')
                    break
                print('  could not open %s: %s' % (row['url'], str(e).splitlines()[0][:120]), flush=True)
                time.sleep(hi)
                continue
            captchas += is_captcha(page)
            if is_captcha(page) and not wait_for_person(page):
                print('No answer to the CAPTCHA for %d minutes; stopping. Run again to carry on.' % (CAPTCHA_WAIT // 60))
                break
            if page.url and not ARCHIVE_TODAY.match(page.url):
                print('  %s went to %s; skipped' % (row['url'], page.url), flush=True)
                continue
            try:
                page.wait_for_selector('#CONTENT', timeout=15000)
            except Exception:
                pass
            try:
                d = page.evaluate(EXTRACT)
            except Exception as e:
                if page.is_closed() or 'closed' in str(e):
                    print('The Chrome window was closed; stopping. Run again to carry on.')
                    break
                print('  could not read %s: %s' % (row['url'], str(e).splitlines()[0][:120]), flush=True)
                continue
            text = clean(d.get('text') or '')
            orig = (d.get('orig') or '').strip()
            h = md5(row['key'])
            with open(os.path.join(TEXT, h + '.txt'), 'w', encoding='utf-8') as fh:
                fh.write(text)
            if orig and normalize_url(orig):
                index[md5(normalize_url(orig))] = h
            elif d.get('header') and not os.path.exists(os.path.join(OUT, 'header-sample.html')):
                with open(os.path.join(OUT, 'header-sample.html'), 'w', encoding='utf-8') as fh:
                    fh.write(d['header'])  # for fixing EXTRACT when the header changes
            caps[row['key']] = {'url': row['url'], 'original': orig, 'title': re.sub(r'\s+', ' ', d.get('title') or '').strip()[:300],
                                'captured': d.get('captured') or '', 'chars': len(text), 'thin': len(text) < 400,
                                'kind': row['kind'], 'at': time.strftime('%Y-%m-%d %H:%M')}
            done += 1
            print('%4d/%d %s  %s  %d chars' % (i + 1, len(rows), row['key'], orig or '(no original found)', len(text)), flush=True)
            if done % 10 == 0:
                save_json(CAPTURES, caps)
                save_json(INDEX, index)
            time.sleep(random.uniform(lo, hi))
        save_json(CAPTURES, caps)
        save_json(INDEX, index)
        try:
            ctx.close()
        except Exception:
            pass
    print('%d CAPTCHAs for %d pages.' % (captchas, done))
    print('read %d pages this run; %d in all. Next: python scripts/archive-today-capture.py upload' % (done, len(caps)))


def status(args):
    caps = load_json(CAPTURES, {})
    rows = todo_rows(tuple(args.kinds.split(',')))
    keys = {r['key'] for r in rows}
    got = [c for k, c in caps.items() if k in keys]
    print('%d archive.today links; %d read (%d with the original address, %d with little or no text); %d to go'
          % (len(rows), len(got), sum(1 for c in got if c.get('original')), sum(1 for c in got if c.get('thin')), len(keys) - len(got)))


def upload(args):
    """Send text/ and index.json to ~/kop-import/archive-today/ as one tar stream over SSH."""
    buf = io.BytesIO()
    with tarfile.open(fileobj=buf, mode='w:gz') as tar:
        tar.add(INDEX, arcname='index.json')
        tar.add(TEXT, arcname='text')
    n = len(os.listdir(TEXT))
    cmd = SSH + ['mkdir -p %s && tar xzf - -C %s && ls %s/text | wc -l' % (REMOTE_DIR, REMOTE_DIR, REMOTE_DIR)]
    r = subprocess.run(cmd, input=buf.getvalue(), capture_output=True)
    if r.returncode != 0:
        sys.exit('upload failed: ' + r.stderr.decode('utf-8', 'replace')[-500:])
    print('sent %d pages (%.1f MB); the server now holds %s' % (n, len(buf.getvalue()) / 1e6, r.stdout.decode().strip()))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', nargs='?', default='capture', choices=('capture', 'upload', 'status'))
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--kinds', default='news,reference,government,archive', help='Drive Docs kinds to read (court is left out)')
    ap.add_argument('--gap', default='6-12', help='seconds between pages, e.g. 6-12')
    ap.add_argument('--retry-thin', action='store_true', help='read again the pages that gave little or no text')
    args = ap.parse_args()
    {'capture': capture, 'upload': upload, 'status': status}[args.cmd](args)


if __name__ == '__main__':
    main()
