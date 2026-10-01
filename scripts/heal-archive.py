"""
HEAL (Human Earth Animal Liberation, heal-online.org) as the Wayback Machine
kept it, downloaded and turned into text for fact extraction.

    python scripts/heal-archive.py cdx            # list every capture -> tmp/heal/cdx.txt
    python scripts/heal-archive.py fetch [--limit=N] [--kind=html|pdf]
    python scripts/heal-archive.py text           # raw/ -> text/ + issues.json, pdftext/ + pdfs.json
    python scripts/heal-archive.py all

Then readers follow tmp/heal/INSTRUCTIONS.md (pages -> facts/) and
DOCS-INSTRUCTIONS.md (PDFs -> docs/), and:

    python scripts/woodbury-facts.py --also tmp/heal   # staff and program facts -> KOP Tools > Woodbury Facts
    python scripts/heal-docs.py                         # documents -> KOP Tools > Drive Docs

HEAL's site (2004-2022) was flat: one page per program (thayer.htm), staff
rosters (tcut.htm = Teen Challenge of Utah), and about 1,600 PDFs at the root,
mostly news articles saved by date (guns091807.pdf). Only those root-level
.htm/.html/.pdf files are kept; the "tinc" message board, images and the
paths other sites' crawls left under the domain are skipped.

A page gets its earliest and latest HEAL captures (staff rosters and program
pages grew over the years); a PDF gets its latest. A capture that is not
HEAL's (a parked domain) is passed over for the next older one.

Everything lands in tmp/heal/ (gitignored): the repository is public and the
pages carry survivors' accounts and contact details, so none of it is
committed. Fetching is resumable and slow on purpose (the Archive throttles).
"""

import argparse
import gzip
import json
import os
import re
import sys
import time

import requests

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tmp', 'heal')
RAW = os.path.join(OUT, 'raw')
TEXT = os.path.join(OUT, 'text')
CDX = os.path.join(OUT, 'cdx.txt')
UA = {'User-Agent': 'Mozilla/5.0 (KidsOverProfits archive research)'}
ROOT_FILE = re.compile(r'^org,heal-online\)/([^/?]+\.(?:htm|html|pdf))$', re.I)


PAUSE = 6  # The Archive allows about 15 requests a minute and refuses connections for a while past that.


def get(url, binary=False, tries=10):
    wait = 30
    for _ in range(tries):
        try:
            r = requests.get(url, headers=UA, timeout=90, stream=True)
            # The Archive replays the original headers: a page saved with
            # "Content-Encoding: gzip" may not be gzip at all, so read the
            # bytes as they are and unpack only real gzip.
            body = r.raw.read(decode_content=False)
            if body[:2] == b'\x1f\x8b':
                try:
                    body = gzip.decompress(body)
                except OSError:
                    pass
            if r.status_code == 200 and b'Temporarily Offline' not in body[:3000]:
                return body if binary else body.decode('utf-8', 'replace')
            if r.status_code in (404, 403):
                return None
            if r.status_code == 429:
                wait = max(wait, 120)
        except requests.ConnectionError:
            # Refused: this address is blocked for a few minutes.
            wait = max(wait, 300)
        except requests.RequestException:
            pass
        print(f'  waiting {wait}s ({url[-60:]})', flush=True)
        time.sleep(wait)
        wait = min(wait * 2, 1200)
    return None


def cmd_cdx():
    os.makedirs(OUT, exist_ok=True)
    body = get('https://web.archive.org/cdx/search/cdx?url=heal-online.org&matchType=domain'
               '&fl=urlkey,timestamp,original,mimetype,digest&filter=statuscode:200&collapse=digest')
    if not body:
        sys.exit('CDX request failed')
    with open(CDX, 'w', encoding='utf-8') as f:
        f.write(body)
    print(f'{len(body.splitlines())} captures -> {CDX}')


def page_name(urlkey):
    m = ROOT_FILE.match(urlkey)
    if not m:
        return None
    name = m.group(1).lower()
    return re.sub(r'\.html$', '.htm', name)


def captures():
    """{name: [(timestamp, original, mime, digest), ...] oldest first}"""
    pages = {}
    for line in open(CDX, encoding='utf-8'):
        parts = line.split()
        if len(parts) < 5:
            continue
        name = page_name(parts[0])
        if name:
            pages.setdefault(name, []).append((parts[1], parts[2], parts[3], parts[4]))
    for v in pages.values():
        v.sort()
    return pages


def raw_path(name, ts):
    base, ext = os.path.splitext(name)
    return os.path.join(RAW, f'{base}@{ts}{ext}')


def looks_heal(data, is_pdf):
    if is_pdf:
        return data[:5] == b'%PDF-'
    low = data[:200000].lower()
    # The server's own error pages were saved as if they were the page.
    if any(w in low for w in (b'404.0 - not found', b'detailed error', b'<title>404', b'page not found',
                              b'<title>object moved', b'automatically redirect', b'automatically re-direct',
                              # The parked domain after HEAL let it go.
                              b'sedoparking', b'data-adblockkey', b'resources and information.</title>',
                              # Gambling spam on the squatted domain.
                              b'envato', b'slot gacor', b'slot online', b'judi online', b'situs slot')):
        return False
    # A parked or reused domain has none of HEAL's own words.
    return any(w in low for w in (b'heal', b'abuse', b'program', b'teen'))


def cmd_clean():
    """Drop copies the current checks reject (error, redirect and parking pages), so fetch tries an older one."""
    n = 0
    for fn in os.listdir(RAW):
        path = os.path.join(RAW, fn)
        if '@none' in fn:
            os.remove(path)
            n += 1
            continue
        with open(path, 'rb') as f:
            data = f.read()
        if not looks_heal(data, fn.endswith('.pdf')):
            os.remove(path)
            n += 1
    print(f'removed {n} rejected copies')


def cmd_fetch(limit=0, kind=''):
    os.makedirs(RAW, exist_ok=True)
    pages = captures()
    done = 0
    # Pages first (they carry the staff lists), then the PDFs.
    names = sorted(pages, key=lambda n: (n.endswith('.pdf'), n))
    if kind:
        names = [n for n in names if n.endswith('.pdf') == (kind == 'pdf')]
    for i, name in enumerate(names):
        caps = pages[name]
        is_pdf = name.endswith('.pdf')
        have = [c for c in caps if os.path.exists(raw_path(name, c[0]))]
        if have or os.path.exists(raw_path(name, 'none')):
            continue
        got = []
        # HEAL's own latest copy first: captures before 2023, when HEAL still
        # ran the site (the 2025 re-crawl is mostly duplicates, parking pages
        # and spam, and the Archive refuses it most), then the later ones.
        order = ([c for c in reversed(caps) if c[0] < '2023'] + [c for c in reversed(caps) if c[0] >= '2023'])[:4]
        for c in order:
            data = get(f'https://web.archive.org/web/{c[0]}id_/{c[1]}', binary=True)
            time.sleep(PAUSE)
            if data and looks_heal(data, is_pdf):
                with open(raw_path(name, c[0]), 'wb') as f:
                    f.write(data)
                got.append(c[0])
                break
        if not got:
            open(raw_path(name, 'none'), 'w').close()
        elif not is_pdf and caps[0][0] < got[0] and b'staff list' in data[:20000].lower():
            # Staff lists only: the earliest copy adds the staff who had left.
            c = caps[0]
            data = get(f'https://web.archive.org/web/{c[0]}id_/{c[1]}', binary=True)
            time.sleep(PAUSE)
            if data and looks_heal(data, False):
                with open(raw_path(name, c[0]), 'wb') as f:
                    f.write(data)
        done += 1
        if done % 25 == 0:
            print(f'{i + 1}/{len(names)} {name}', flush=True)
        if limit and done >= limit:
            break
    print(f'fetched {done} pages', flush=True)


def html_text(data):
    from bs4 import BeautifulSoup
    head = data[:3000].decode('ascii', 'ignore').lower()
    m = re.search(r'charset=["\']?([a-z0-9_-]+)', head)
    enc = m.group(1) if m else 'windows-1252'
    # Later copies are UTF-8 whatever the old meta tag still says.
    for e in ('utf-8', enc, 'windows-1252'):
        try:
            html = data.decode(e)
            break
        except (LookupError, UnicodeDecodeError):
            continue
    else:
        html = data.decode('windows-1252', 'replace')
    soup = BeautifulSoup(html, 'html.parser')
    for t in soup(['script', 'style', 'xml', 'head']):
        t.decompose()
    title = ''
    tt = BeautifulSoup(html, 'html.parser').title
    if tt:
        title = re.sub(r'\s+', ' ', tt.get_text()).strip()
    # Line breaks in the HTML source are only wrapping; the tags decide lines.
    from bs4 import NavigableString
    for s in list(soup.find_all(string=True)):
        if isinstance(s, NavigableString) and re.search(r'\s\s|\n', s):
            s.replace_with(re.sub(r'\s+', ' ', s))
    for br in soup.find_all(['br']):
        br.replace_with('\n')
    # Innermost rows only: HEAL laid every page out in tables, and flattening
    # the outer layout row would run the whole page into one line.
    for row in soup.find_all('tr'):
        if row.find('table'):
            continue
        cells = [re.sub(r'\s+', ' ', c.get_text(' ')).strip() for c in row.find_all(['td', 'th'])]
        row.replace_with('\n' + ' | '.join(c for c in cells if c) + '\n')
    for blk in soup.find_all(['p', 'div', 'li', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'table']):
        blk.insert_after('\n')
    links = [a.get('href', '') for a in soup.find_all('a') if a.get('href')]
    text = soup.get_text()
    text = text.replace('\xa0', ' ')
    lines = [unmangle(re.sub(r'[ \t]+', ' ', ln).strip()) for ln in text.splitlines()]
    text = '\n'.join(w for ln in lines if ln for w in wrap(ln))
    return title, text, links


def unmangle(s):
    """The 2025 crawl saved UTF-8 read as Windows-1252 ("donâ€™t"): put it back."""
    if 'Ã' not in s and 'â€' not in s:
        return s
    try:
        return s.encode('windows-1252').decode('utf-8')
    except (UnicodeEncodeError, UnicodeDecodeError):
        return s.replace('â€™', '’').replace('â€œ', '“').replace('â€\x9d', '”').replace('â€"', '—')


def wrap(line, width=1200):
    """Long lines (whole articles pasted into one table cell) broken at sentence ends, so readers see all of them."""
    out = []
    while len(line) > width:
        cut = max(line.rfind('. ', 0, width), line.rfind('? ', 0, width), line.rfind('! ', 0, width))
        if cut < width // 3:
            cut = line.rfind(' ', 0, width)
        if cut <= 0:
            cut = width
        out.append(line[:cut + 1].strip())
        line = line[cut + 1:].strip()
    out.append(line)
    return [x for x in out if x]


def pdf_text(path):
    import fitz
    doc = fitz.open(path)
    parts, ocr = [], False
    for i, pg in enumerate(doc):
        t = pg.get_text().strip()
        if len(t) < 40:
            try:
                tp = pg.get_textpage_ocr(dpi=200, full=True)
                t = pg.get_text(textpage=tp).strip()
                ocr = True
            except Exception:
                pass
        parts.append(f'=== PAGE {i + 1} ===\n{t}')
    return '\n'.join(parts), ocr, len(doc)


def stamp(ts):
    return f'{ts[0:4]}-{ts[4:6]}-{ts[6:8]}'


GENERIC_TITLE = re.compile(r'^(heal\b|program staff information$|new page|untitled|home$)', re.I)
UPDATED = re.compile(r'(?:last\s+)?updated:?\s*(?:on\s+)?(?:[A-Za-z]+\.?\s+\d{1,2}(?:st|nd|rd|th)?,?\s+|\d{1,2}/\d{1,2}/)?((?:19|20)\d\d)', re.I)


def speaks_for(body, ts, caps):
    """
    The year a copy's words are from: its own "Last Updated" line, else the
    capture year. HEAL stopped editing the site around 2022 and the 2025 crawl
    re-saved old pages, so a later copy with no date takes the year of the last
    capture before 2023.
    """
    ys = [int(y) for y in UPDATED.findall(body) if 1995 <= int(y) <= int(ts[:4])]
    if ys:
        return max(ys)
    if int(ts[:4]) >= 2023:
        older = [c[0] for c in caps if c[0] < '2023']
        if older:
            return int(max(older)[:4])
    return int(ts[:4])


def label_for(name, title, body):
    m = re.search(r'This is a (?:partial )?staff list for (.{4,120}?)(?:\s+\(|\s+in\s+[A-Z][a-z]+(?:\s+[A-Z][a-z]+)?,\s*[A-Z]{2}\b|$|\n)', body)
    if m:
        return 'staff list for ' + m.group(1).strip(' .,')
    if title and not GENERIC_TITLE.search(title):
        return title[:120]
    first = next((ln for ln in body.splitlines() if len(ln) > 12 and not ln.startswith('[')), '')
    return (first[:100] + '...') if len(first) > 100 else (first or name)


def classify(body):
    head = body[:1500].lower()
    if 'staff list' in head or re.search(r'^name \| (unit/)?position', body[:4000], re.I | re.M):
        return 'staff'
    return 'page'


def cmd_text():
    """
    Pages -> text/<name>.txt in the Woodbury reading format (=== PAGE n ===,
    page 1 the latest copy, page 2 the earliest) and issues.json, so
    scripts/woodbury-facts.py --also tmp/heal reads them like an issue.
    PDFs -> pdftext/<name>.txt and pdfs.json for the document pass.
    """
    os.makedirs(TEXT, exist_ok=True)
    pdf_dir = os.path.join(OUT, 'pdftext')
    os.makedirs(pdf_dir, exist_ok=True)
    pages = captures()
    files = {}
    for fn in os.listdir(RAW):
        m = re.match(r'^(.*)@(\d{14})(\.\w+)$', fn)
        if m:
            files.setdefault(m.group(1) + m.group(3).lower().replace('.html', '.htm'), []).append(m.group(2))
    issues, pdfs = [], []
    # Site-wide lines (the menu, the warning boxes, the footer) are on hundreds
    # of pages: counted first and left out, so readers see only the page's own words.
    texts = {}
    common = {}
    for name in sorted(files):
        if name.endswith('.pdf'):
            continue
        texts[name] = []
        for ts in sorted(files[name], reverse=True):
            with open(raw_path(name, ts), 'rb') as f:
                t, body, _links = html_text(f.read())
            texts[name].append((ts, t, body))
        for ln in set(ln for _ts, _t, b in texts[name] for ln in b.splitlines()):
            common[ln] = common.get(ln, 0) + 1
    shared = {ln for ln, n in common.items() if n >= min(50, max(8, len(texts) // 5))}
    for name in sorted(files):
        caps = pages.get(name, [])
        by_ts = {c[0]: c for c in caps}
        base = re.sub(r'\.\w+$', '', name)
        is_pdf = name.endswith('.pdf')
        stamps = sorted(files[name], reverse=True)
        if is_pdf:
            ts = stamps[0]
            orig = by_ts.get(ts, (ts, 'http://www.heal-online.org/' + name))[1]
            try:
                body, ocr, npages = pdf_text(raw_path(name, ts))
            except Exception as e:  # noqa: BLE001
                body, ocr, npages = f'(unreadable: {e})', False, 0
            with open(os.path.join(pdf_dir, base + '.txt'), 'w', encoding='utf-8') as f:
                f.write(body + '\n')
            pdfs.append({'name': name, 'file': base + '.txt', 'date': stamp(ts),
                         'first_capture': stamp(caps[0][0]) if caps else stamp(ts),
                         'url': f'https://web.archive.org/web/{ts}/{orig}', 'ocr': ocr, 'pages': npages,
                         'chars': len(body)})
            continue
        bodies, title = [], ''
        for ts, t, body in texts[name]:
            title = title or t
            bodies.append((ts, '\n'.join(ln for ln in body.splitlines() if ln not in shared)))
        # The earlier copy keeps only the lines the later one lost (staff who
        # had left by then, older allegations); none left, it is dropped.
        if len(bodies) == 2:
            later = set(bodies[0][1].splitlines())
            gone = [ln for ln in bodies[1][1].splitlines() if ln not in later]
            bodies = bodies[:1] + ([(bodies[1][0], '\n'.join(gone))] if gone else [])
        out, urls, dates, years = [], {}, {}, {}
        for n, (ts, body) in enumerate(bodies, 1):
            orig = by_ts.get(ts, (ts, 'http://www.heal-online.org/' + name))[1]
            urls[str(n)] = f'https://web.archive.org/web/{ts}/{orig}'
            dates[str(n)] = stamp(ts)
            years[str(n)] = speaks_for(body, ts, caps)
            note = ' (only what the later copy no longer has)' if n == 2 else ''
            out.append(f'=== PAGE {n} ===\n[Archived copy of {stamp(ts)}{note}]\n{body}')
        with open(os.path.join(TEXT, base + '.txt'), 'w', encoding='utf-8') as f:
            f.write(f'HEAL {name} title={title!r}\n' + '\n'.join(out) + '\n')
        latest = bodies[0][1]
        issues.append({'date': 'heal-' + base, 'file': base + '.txt', 'pub': 'HEAL',
                       'label': label_for(name, title, latest), 'number': '', 'id': 0,
                       'url': urls['1'], 'page_urls': urls, 'page_dates': dates, 'page_years': years,
                       'kind': classify(latest), 'title': title, 'chars': len(latest)})
    with open(os.path.join(OUT, 'issues.json'), 'w', encoding='utf-8') as f:
        json.dump(issues, f, indent=1, ensure_ascii=False)
    with open(os.path.join(OUT, 'pdfs.json'), 'w', encoding='utf-8') as f:
        json.dump(pdfs, f, indent=1, ensure_ascii=False)
    print(f'{len(issues)} pages -> {TEXT}, {len(pdfs)} PDFs -> {pdf_dir}')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['cdx', 'fetch', 'text', 'clean', 'all'])
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--kind', choices=['html', 'pdf'], default='')
    a = ap.parse_args()
    if a.cmd in ('cdx', 'all') or not os.path.exists(CDX):
        cmd_cdx()
    if a.cmd in ('fetch', 'all'):
        cmd_fetch(a.limit, a.kind)
    if a.cmd == 'clean':
        cmd_clean()
    if a.cmd in ('text', 'all'):
        cmd_text()


if __name__ == '__main__':
    main()
