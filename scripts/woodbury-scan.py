"""
Scan every Woodbury Reports issue in the media library for the programs it
writes about, and cut each program's pages into their own small PDF.

    python scripts/woodbury-scan.py [--out C:/tmp/kop-woodbury] [--only 1348,1349] [--no-cut]

Reads the issue list and the facility names from tmp/prod.sqlite (run
scripts/sync-prod-sqlite.py first), downloads each issue once into
<out>/issues/, and writes <out>/pending/: one PDF per candidate plus
candidates.json. Nothing reaches the site from here. Copy the pending folder
to the server (~/kop-import/woodbury/) and review the candidates at KOP Data
Tools > Woodbury Reports (inc/woodbury-mentions.php); approving one files its
PDF in the program's "Woodbury Reports Mentions" folder.

Two kinds of candidate:
  section  an article that starts with the program's header, "MONTANA ACADEMY"
           over "Kalispell, MT". Its pages run to the next header, plus the page
           a "CONTINUED: MONTANA/ 4" jump points to.
  mention  the program's full name somewhere else in the issue (news items,
           other programs' articles). One per issue and program, up to three
           pages.

The PDFs are never committed: the repository is public.
"""

import argparse
import hashlib
import json
import os
import re
import sqlite3
import sys
import time
import unicodedata
import urllib.request

import fitz  # PyMuPDF

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
SITE = 'https://kidsoverprofits.org'
UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      '(KHTML, like Gecko) Chrome/128.0 Safari/537.36')
MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july',
          'august', 'september', 'october', 'november', 'december']
STATES = {
    'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas', 'CA': 'California',
    'CO': 'Colorado', 'CT': 'Connecticut', 'DE': 'Delaware', 'FL': 'Florida', 'GA': 'Georgia',
    'HI': 'Hawaii', 'ID': 'Idaho', 'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa',
    'KS': 'Kansas', 'KY': 'Kentucky', 'LA': 'Louisiana', 'ME': 'Maine', 'MD': 'Maryland',
    'MA': 'Massachusetts', 'MI': 'Michigan', 'MN': 'Minnesota', 'MS': 'Mississippi',
    'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada', 'NH': 'New Hampshire',
    'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York', 'NC': 'North Carolina',
    'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma', 'OR': 'Oregon', 'PA': 'Pennsylvania',
    'RI': 'Rhode Island', 'SC': 'South Carolina', 'SD': 'South Dakota', 'TN': 'Tennessee',
    'TX': 'Texas', 'UT': 'Utah', 'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington',
    'WV': 'West Virginia', 'WI': 'Wisconsin', 'WY': 'Wyoming',
}
STATE_BY_NAME = {v.lower(): k for k, v in STATES.items()}
PLACE_RE = re.compile(
    r"^(?!By\b)(?:(?:[A-Z][A-Za-z.'\-]*|de|la|del|of|the)\.?\s+){0,3}[A-Z][A-Za-z.'\-]*\.?,\s*(" + '|'.join(STATES) + '|' + '|'.join(STATES.values())
    + r'|British Columbia|Ontario|Alberta|Quebec|Canada|Mexico|Costa Rica|Jamaica|Samoa'
    r'|Dominican Republic|Czech Republic|England|Scotland|Wales|Ireland|Australia|New Zealand)\b')
CONTINUED_RE = re.compile(r"CONTINUED:?\s*([A-Z][A-Za-z .'&\-]{1,40}?)\s*/\s*(\d{1,2})\b")
NEWS_DATE_RE = re.compile(r'^\((' + '|'.join(m.title() for m in MONTHS) + r')\.?\s+\d{1,2},?\s+\d{4}\)')
MASTHEAD_RE = re.compile(r'Woodbury Reports,? Inc\.?\W+([A-Za-z]+)\s+(\d{4})\W+#\s*(\d+)')

# Header lines that are all capitals but are not a program.
NOT_PROGRAMS = re.compile(
    r'^(CONTINUED|WOODBURY|PLACES FOR STRUGGLING|NEWS ?& ?VIEWS|NEWS AND VIEWS|VISIT|VISITS|'
    r'SCHOOL/? ?PROGRAM|TABLE OF CONTENTS|INSIDE|ESSAY|OPINION|NEW PERSPECTIVES|CONTACT|'
    r'WE THANK|ADVERTISERS|BOOK REVIEW|LETTERS?|UPDATE|RESOURCES|CALENDAR|EDITORIAL)\b')
# Name words too common to count as a mention on their own.
GENERIC_NAMES = {
    'new horizons', 'new beginnings', 'family services', 'youth services', 'second chance',
    'turning point', 'new directions', 'crossroads', 'the bridge', 'new hope', 'hope center',
    'boys ranch', 'girls ranch', 'youth ranch', 'academy', 'the academy', 'summit', 'the summit',
    'horizon', 'oak ridge', 'pine ridge', 'boarding school', 'therapeutic boarding school',
    'residential treatment center', 'wilderness program', 'young adult', 'transitional living',
    'behavioral health', 'mental health', 'treatment center', 'recovery center', 'youth academy',
    'education group', 'wilderness therapy', 'outdoor behavioral', 'learning center',
}
SPONSOR_PAGE = re.compile(r'WE THANK THE ADVERTISERS|ADVERTISERS AND SPONSORS', re.I)


def clean(s):
    """Undo the PDF text layer's quirks: curly quotes, and the replacement character it leaves for them."""
    s = (s or '').replace('\u2019', "'").replace('\u2018', "'").replace('\u201c', '"').replace('\u201d', '"')
    s = re.sub(r'(?<=[A-Za-z])\ufffd(?=[sS]\b)', "'", s)   # ST. PAUL?S
    s = re.sub(r'(?m)^\ufffd(?=LAN\b)', '\u00c9', s)         # ?LAN SCHOOL
    return s.replace('\ufffd', ' ')


def key(s):
    s = unicodedata.normalize('NFKD', clean(s)).encode('ascii', 'ignore').decode()
    s = s.lower().replace('&', ' and ')
    s = re.sub(r"'s\b", 's', s)
    s = re.sub(r'[^a-z0-9]+', ' ', s).strip()
    s = re.sub(r'^the ', '', s)
    s = re.sub(r' (inc|llc|ltd|co|corp|l l c)$', '', s)
    return s


GENERIC_TAIL = {'school', 'schools', 'academy', 'program', 'programs', 'center', 'centers', 'centre', 'rtc',
                'inc', 'llc', 'services', 'the', 'of', 'for', 'and', 'at', 'preparatory', 'prep', 'residential',
                'treatment', 'wilderness', 'therapeutic', 'boarding', 'group', 'home', 'homes'}


def core(k):
    """A name key without its generic words: "island view residential treatment center" -> "island view"."""
    k = k.replace('residential treatment center', 'rtc')
    words = [w for w in k.split() if w not in GENERIC_TAIL]
    words = [w[:-1] if len(w) > 4 and w.endswith('s') else w for w in words]
    return ' '.join(words) if len(' '.join(words)) >= 5 else ''


def title_case(s):
    small = {'a', 'an', 'and', 'at', 'by', 'for', 'in', 'of', 'on', 'or', 'the', 'to'}
    out = []
    for i, w in enumerate(s.lower().split()):
        out.append(w if i and w in small else w[:1].upper() + w[1:])
    return ' '.join(out)


# --------------------------------------------------------------------------
# Inputs
# --------------------------------------------------------------------------

def issue_code(text):
    """(year, month) from a file name or title, or None."""
    s = text.lower()
    m = re.search(r'(' + '|'.join(MONTHS) + r')[\s_-]+(\d{4})', s)
    if m:
        return int(m.group(2)), MONTHS.index(m.group(1)) + 1
    m = re.search(r'woodbury[\s_-]+(?:[a-z]+[\s_-]+)?(\d{2})(\d{2})(?![\d])', s)
    if m and 1 <= int(m.group(1)) <= 12:
        return 2000 + int(m.group(2)), int(m.group(1))
    return None


def load_issues(con):
    """Full monthly issues in the media library, one per month (the copy with the most pages wins later)."""
    rows = con.execute(
        "SELECT p.ID, p.post_title, m.meta_value FROM wpdl_posts p "
        "JOIN wpdl_postmeta m ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' "
        "WHERE p.post_type = 'attachment' AND p.post_mime_type = 'application/pdf' "
        "AND (p.post_title LIKE '%woodbury%' OR m.meta_value LIKE '%woodbury%')").fetchall()
    issues = []
    for aid, title, path in rows:
        blob = (title or '') + ' ' + os.path.basename(path)
        if re.search(r'extract|discovery[\s-]ranch|expert|natsap|bill|workshop|empowerment|compass|poison', blob, re.I):
            continue
        code = issue_code(os.path.basename(path)) or issue_code(title or '')
        if not code or not (1990 <= code[0] <= 2030):
            continue
        issues.append({'id': aid, 'title': title, 'path': path, 'code': code})
    return issues


def load_facilities(con):
    """name key -> [facility], and pastNames key -> [facility] (a different name era)."""
    names, past = {}, {}
    for fid, name, state, js in con.execute('SELECT id, name, state, json_data FROM facilities_v2'):
        try:
            d = json.loads(js)
        except Exception:
            d = {}
        ident = d.get('identification') or {}
        f = {'id': fid, 'name': name, 'state': (state or '').upper()}
        for i, n in enumerate([name] + [x for x in (ident.get('otherNames') or []) if isinstance(x, str)]):
            k = key(n)
            if len(k) >= 4:
                names.setdefault(k, [])
                if f in names[k]:
                    continue
                if i == 0:
                    # A record's own name outranks another record's alias ("Montana Academy"
                    # is its own record and an other name of Embark at Flathead Valley).
                    at = sum(1 for g in names[k] if key(g['name']) == k)
                    names[k].insert(at, f)
                else:
                    names[k].append(f)
        for n in [x for x in (ident.get('pastNames') or []) if isinstance(x, str)]:
            k = key(n)
            if len(k) >= 4:
                past.setdefault(k, [])
                if f not in past[k]:
                    past[k].append(f)
    return names, past


def load_filed(con):
    """(facility folder name key, year, month) already filed by hand under a "Woodbury Reports Mentions" folder."""
    filed = set()
    rows = con.execute(
        "SELECT parent.name, p.post_title FROM wpdl_fbv f "
        "JOIN wpdl_fbv parent ON parent.id = f.parent "
        "JOIN wpdl_fbv_attachment_folder a ON a.folder_id = f.id "
        "JOIN wpdl_posts p ON p.ID = a.attachment_id "
        "WHERE f.name LIKE 'Woodbury Reports%'").fetchall()
    for folder, title in rows:
        code = issue_code(title or '')
        if code:
            filed.add((key(folder), code[0], code[1]))
    return filed


def download(issue, out):
    dest = os.path.join(out, 'issues', '%d.pdf' % issue['id'])
    if os.path.exists(dest) and os.path.getsize(dest) > 1000:
        return dest
    os.makedirs(os.path.dirname(dest), exist_ok=True)
    url = SITE + '/wp-content/uploads/' + urllib.request.quote(issue['path'])
    for attempt in range(3):
        try:
            req = urllib.request.Request(url, headers={'User-Agent': UA})
            with urllib.request.urlopen(req, timeout=120) as r, open(dest + '.part', 'wb') as fh:
                fh.write(r.read())
            os.replace(dest + '.part', dest)
            return dest
        except Exception as e:  # noqa: BLE001
            print('  download failed (%s), retrying: %s' % (e, url), file=sys.stderr)
            time.sleep(3 * (attempt + 1))
    return None


# --------------------------------------------------------------------------
# Scanning one issue
# --------------------------------------------------------------------------

def is_caps_line(s):
    letters = [c for c in s if c.isalpha()]
    if len(letters) < 4 or len(s) > 70:
        return False
    if sum(c.isupper() for c in letters) / len(letters) < 0.9:
        return False
    if re.search(r'\d{3,}|@|www\.|\.com|:', s):
        return False
    return not NOT_PROGRAMS.match(s)


def find_headers(lines):
    """[(line index, name, place)] for "NAME" over "City, ST" pairs on one page."""
    out = []
    for i, line in enumerate(lines):
        if not PLACE_RE.match(line) or i == 0:
            continue
        name = lines[i - 1]
        if not is_caps_line(name):
            continue
        # A name broken over two capitals lines ("ASPEN" / "RANCH").
        if i >= 2 and is_caps_line(lines[i - 2]) and len(lines[i - 2]) + len(name) < 60 \
                and not (out and out[-1][0] >= i - 2):
            name = lines[i - 2] + ' ' + name
        out.append((i, re.sub(r'\s+', ' ', name).strip(' -*'), line.strip()))
    return out


def place_state(place):
    tail = place.split(',')[-1].strip()
    m = re.match(r'([A-Z]{2})\b', tail)
    if m and m.group(1) in STATES:
        return m.group(1)
    return STATE_BY_NAME.get(tail.lower(), '')


def match_header(name, state, names, past):
    """(facility or None, alternatives, kind, note)."""
    k = key(name)
    note = ''
    kind = 'section'
    cands = names.get(k, [])
    if not cands and k in past:
        cands = past[k]
        note = 'Header uses a past name of this record (a different name era).'
    if not cands and state:
        # Header adds or drops a word ("MONTANA ACADEMY" vs "Montana Academy for Girls",
        # "... PROGRAM" vs "... Programs", "RESIDENTIAL TREATMENT CENTER" vs "RTC"). Same state only.
        ck = core(k)
        loose = []
        for nk, fs in names.items():
            if len(nk) < 8:
                continue
            nc = core(nk)
            short = min(ck, nc, key=len)
            if (nk.startswith(k + ' ') or k.startswith(nk + ' ')
                    or (ck and nc and (ck == nc or (' ' in short and (nc.startswith(ck + ' ') or ck.startswith(nc + ' ')))))):
                loose.extend(f for f in fs if f['state'] == state and f not in loose)
        if loose:
            cands, kind, note = loose, 'fuzzy', 'Close name, same state: check it is the same program.'
    if not cands:
        return None, [], 'unmatched', ''
    same = [f for f in cands if f['state'] == state]
    if same:
        cands = same + [f for f in cands if f not in same]
    elif state and kind == 'section':
        note = (note + ' ' if note else '') + 'Record is in %s, header says %s.' % (cands[0]['state'] or '?', state)
    return cands[0], cands[1:6], kind, note.strip()


def issue_label(doc, code):
    """('March 2009', '#175') from the running masthead, falling back to the file name."""
    for pn in range(min(4, doc.page_count)):
        m = MASTHEAD_RE.search(doc[pn].get_text())
        if m and m.group(1).lower() in MONTHS:
            return '%s %s' % (m.group(1).title(), m.group(2)), '#' + m.group(3), (int(m.group(2)), MONTHS.index(m.group(1).lower()) + 1)
    return '%s %d' % (MONTHS[code[1] - 1].title(), code[0]), '', code


def scan_issue(doc, name_index, headline_index):
    pages = [clean(doc[p].get_text()) for p in range(doc.page_count)]
    lines = [[l.strip() for l in t.splitlines() if l.strip()] for t in pages]
    headers = []  # (page, line, name, place)
    for p, ls in enumerate(lines):
        for li, name, place in find_headers(ls):
            headers.append((p, li, name, place))

    sections = []
    for n, (p, li, name, place) in enumerate(headers):
        nxt = headers[n + 1] if n + 1 < len(headers) else None
        end = doc.page_count - 1 if nxt is None else nxt[0]
        if nxt is not None and nxt[0] > p and nxt[1] <= 6:
            end = nxt[0] - 1        # next article starts at the top of its page
        span = list(range(p, max(p, end) + 1))[:4]
        # A jump ("CONTINUED: MONTANA/ 4") ends the run and adds the page it points to.
        tag_pages = []
        for q in list(span):
            text = pages[q]
            if q == p:
                text = '\n'.join(lines[p][li:])
            for m in CONTINUED_RE.finditer(text):
                tag = key(m.group(1)).split(' ')[0]
                if tag and tag in key(name):
                    span = [x for x in span if x <= q]
                    target = int(m.group(2)) - 1
                    if 0 <= target < doc.page_count and target not in span:
                        tag_pages.append(target)
                        # A second jump from the continuation page.
                        m2 = CONTINUED_RE.search(pages[target])
                        if m2 and key(m2.group(1)).split(' ')[0] == tag:
                            t2 = int(m2.group(2)) - 1
                            if 0 <= t2 < doc.page_count:
                                tag_pages.append(t2)
                    break
        span = sorted(set(span + tag_pages))[:8]
        body = '\n'.join(lines[p][li + 1:])
        sections.append({'page': p, 'name': name, 'place': place, 'pages': span,
                         'snippet': re.sub(r'\s+', ' ', body)[:600]})

    # News items ("Seen N' Heard"): a capitals headline over "(January 16, 2009) ...".
    news = {}  # name key -> [{page, headline, text}]
    for p, ls in enumerate(lines):
        starts = [i for i, l in enumerate(ls) if NEWS_DATE_RE.match(l)]
        for n, i in enumerate(starts):
            head_from = i
            while head_from > 0 and i - head_from < 2 and is_caps_line(ls[head_from - 1]) \
                    and not (n and head_from - 1 <= starts[n - 1]):
                head_from -= 1
            if head_from == i:
                continue
            headline = ' '.join(ls[head_from:i])
            stop = len(ls)
            if n + 1 < len(starts):
                stop = starts[n + 1]
                while stop > i + 1 and is_caps_line(ls[stop - 1]):
                    stop -= 1
            body = re.sub(r'\s+', ' ', ' '.join(ls[i:stop]))
            found = find_names(key(headline), headline_index) | find_names(key(body), name_index)
            for k in found:
                news.setdefault(k, []).append({'page': p, 'headline': headline, 'text': body})

    # Mentions: full names on pages that are not the masthead or the sponsor list.
    mentions = {}
    for p, text in enumerate(pages):
        if p == 1 or SPONSOR_PAGE.search(text):
            continue
        for k in find_names(key(text), name_index):
            mentions.setdefault(k, {})[p] = mention_snippet(text, k)
    return sections, news, mentions


def find_names(text_key, index):
    """Name keys from the index in a normalized text; the longest name starting at a word wins."""
    words = text_key.split()
    found = set()
    i = 0
    while i < len(words):
        hit = 0
        for n in range(6, 1, -1):
            if i + n <= len(words) and ' '.join(words[i:i + n]) in index:
                hit = n
                found.add(' '.join(words[i:i + n]))
                break
        i += hit or 1
    return found


def mention_snippet(text, k):
    flat = re.sub(r'\s+', ' ', text)
    words = k.split()
    pat = r'\b' + r'\W+'.join(re.escape(w) for w in words) + r'\b'
    m = re.search(pat, flat, re.I)
    if not m:
        return flat[:300]
    a, b = max(0, m.start() - 220), min(len(flat), m.end() + 220)
    return ('...' if a else '') + flat[a:b] + ('...' if b < len(flat) else '')


# --------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------

def safe_file(s):
    return re.sub(r'[^A-Za-z0-9 ._-]+', '', s).strip()[:90]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default='C:/tmp/kop-woodbury')
    ap.add_argument('--only', default='', help='comma-separated issue attachment ids')
    ap.add_argument('--no-cut', action='store_true', help='report only, write no PDFs')
    args = ap.parse_args()

    if not os.path.exists(DB):
        sys.exit('tmp/prod.sqlite not found: run scripts/sync-prod-sqlite.py first')
    con = sqlite3.connect(DB)
    names, past = load_facilities(con)
    filed = load_filed(con)
    # Mention index: distinctive names only.
    name_index = {k: fs for k, fs in names.items()
                  if len(k.split()) >= 2 and len(k) >= 10 and k not in GENERIC_NAMES}
    # A news headline is short and about one program, so shorter names count there.
    headline_index = {k: fs for k, fs in names.items()
                      if len(k.split()) >= 2 and len(k) >= 7 and k not in GENERIC_NAMES}

    only = {int(x) for x in args.only.split(',') if x.strip()}
    issues = [i for i in load_issues(con) if not only or i['id'] in only]
    pending = os.path.join(args.out, 'pending')
    os.makedirs(pending, exist_ok=True)

    # Open each copy; one issue per month, the copy with the most pages.
    by_month = {}
    for iss in sorted(issues, key=lambda i: i['id']):
        path = download(iss, args.out)
        if not path:
            continue
        try:
            doc = fitz.open(path)
        except Exception as e:  # noqa: BLE001
            print('  cannot open %s: %s' % (path, e), file=sys.stderr)
            continue
        label, number, code = issue_label(doc, iss['code'])
        iss.update(local=path, label=label, number=number, code=code, pages=doc.page_count)
        doc.close()
        cur = by_month.get(code)
        if cur is None or iss['pages'] > cur['pages']:
            by_month[code] = iss
    print('%d issue files, %d distinct issues' % (len(issues), len(by_month)))

    candidates = []
    stats = {'sections': 0, 'matched': 0, 'fuzzy': 0, 'unmatched': 0, 'news': 0, 'mentions': 0, 'already_filed': 0}
    for code in sorted(by_month):
        iss = by_month[code]
        doc = fitz.open(iss['local'])
        sections, news, mentions = scan_issue(doc, name_index, headline_index)
        issue_url = SITE + '/wp-content/uploads/' + iss['path']
        date = '%04d-%02d' % code
        covered = {}  # facility id -> pages already in its section
        rows = []
        for s in sections:
            st = place_state(s['place'])
            fac, alts, kind, note = match_header(s['name'], st, names, past)
            stats['sections'] += 1
            stats['matched' if kind == 'section' else kind] += 1
            if fac:
                covered.setdefault(fac['id'], set()).update(s['pages'])
            rows.append({'kind': kind, 'pages': s['pages'], 'header': s['name'], 'place': s['place'],
                         'facility': fac, 'alternatives': alts, 'note': note, 'snippet': s['snippet']})
        # One news row and one mention row per program, however many of its names matched.
        news_by = {}
        for k, items in news.items():
            fs = headline_index[k]
            e = news_by.setdefault(fs[0]['id'], {'fs': fs, 'items': [], 'names': []})
            e['names'].append(k)
            for it in items:
                if it not in e['items']:
                    e['items'].append(it)
        news_pages = {}
        for fid, e in news_by.items():
            items = sorted(e['items'], key=lambda it: it['page'])
            pages = sorted({it['page'] for it in items})[:3]
            news_pages[fid] = set(pages)
            stats['news'] += 1
            rows.append({'kind': 'news', 'pages': pages, 'header': title_case(items[0]['headline']), 'place': '',
                         'facility': e['fs'][0], 'alternatives': e['fs'][1:6], 'note': '',
                         'snippet': ' | '.join(it['headline'] + ': ' + it['text'][:420] for it in items[:3]),
                         'matched_name': ', '.join(e['names'])})
        mention_by = {}
        for k, pages in mentions.items():
            fs = name_index[k]
            e = mention_by.setdefault(fs[0]['id'], {'fs': fs, 'pages': {}, 'names': []})
            e['names'].append(k)
            for p, snip in pages.items():
                e['pages'].setdefault(p, snip)
        for fid, e in mention_by.items():
            fs, pages = e['fs'], e['pages']
            left = [p for p in sorted(pages) if p not in covered.get(fid, set()) and p not in news_pages.get(fid, set())]
            if not left or any(f['id'] in covered and set(left) <= covered[f['id']] for f in fs):
                continue
            left = left[:3]
            stats['mentions'] += 1
            rows.append({'kind': 'mention', 'pages': left, 'header': '', 'place': '',
                         'facility': fs[0], 'alternatives': fs[1:6],
                         'note': 'Named on %d pages of this issue.' % len(pages) if len(pages) > 1 else '',
                         'snippet': ' | '.join(pages[p] for p in left)[:900], 'matched_name': ', '.join(e['names'])})

        for r in rows:
            fac = r['facility']
            prog = fac['name'] if fac else title_case(r['header'])
            if fac and (key(fac['name']), code[0], code[1]) in filed:
                stats['already_filed'] += 1
                continue
            printed = [p + 1 for p in r['pages']]
            prange = ('p. %d' % printed[0]) if len(printed) == 1 else (
                'pp. %d-%d' % (printed[0], printed[-1]) if printed == list(range(printed[0], printed[-1] + 1))
                else 'pp. ' + ', '.join(str(x) for x in printed))
            title = 'Woodbury Reports, %s%s, %s: %s' % (
                iss['label'], (' (' + iss['number'] + ')') if iss['number'] else '', prange, prog)
            ident = '%d|%s|%s|%s' % (iss['id'], fac['id'] if fac else key(r['header']), r['kind'] in ('mention', 'news'), ','.join(map(str, printed)))
            ck = hashlib.sha1(ident.encode()).hexdigest()[:16]
            fname = safe_file('woodbury %s %s %s' % (date, prog, ck[:6])) + '.pdf'
            cand = {
                'key': ck, 'kind': r['kind'], 'issue_id': iss['id'], 'issue_url': issue_url,
                'issue_label': iss['label'], 'issue_number': iss['number'], 'issue_date': date,
                'pages': printed, 'header': r['header'], 'place': r['place'],
                'matched_name': r.get('matched_name', ''),
                'facility_id': fac['id'] if fac else 0, 'facility_name': fac['name'] if fac else '',
                'facility_state': fac['state'] if fac else place_state(r['place']),
                'alternatives': [{'id': a['id'], 'name': a['name'], 'state': a['state']} for a in r['alternatives']],
                'note': r['note'], 'snippet': r['snippet'], 'title': title, 'file': fname,
            }
            if not args.no_cut:
                dest = os.path.join(pending, fname)
                if not os.path.exists(dest):
                    out = fitz.open()
                    for p in r['pages']:
                        out.insert_pdf(doc, from_page=p, to_page=p)
                    out.set_metadata({'title': title, 'subject': 'Extract of ' + issue_url})
                    out.save(dest, garbage=4, deflate=True)
                    out.close()
                with open(dest, 'rb') as fh:
                    cand['md5'] = hashlib.md5(fh.read()).hexdigest()
                cand['bytes'] = os.path.getsize(dest)
            candidates.append(cand)
        doc.close()
        print('  %s %-15s %2d pages  %d candidates' % (date, iss['label'], iss['pages'], len(rows)))

    with open(os.path.join(pending, 'candidates.json'), 'w', encoding='utf-8') as fh:
        json.dump({'built': time.strftime('%Y-%m-%dT%H:%M:%SZ', time.gmtime()), 'candidates': candidates},
                  fh, ensure_ascii=False, indent=1)
    print(json.dumps(stats))
    print('%d candidates -> %s' % (len(candidates), pending))


if __name__ == '__main__':
    main()
