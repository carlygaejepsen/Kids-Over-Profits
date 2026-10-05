"""
Documents on survivor-run websites that KOP has no copy of, per facility.

Like the Unsilenced archive (scripts/build-unsilenced-links.py), the facility
and operator pages list the documents these sites hold that KOP does not,
linked to the site itself. Nothing is copied to this site.

  ssi    Surviving Straight Inc. (survivingstraightinc.com): Straight Inc. and
         its spin-offs, ~1,500 PDFs (newspaper articles by branch, corporate
         and government documents, internal program papers)
  wwasp  WWASP Survivors (wwaspsurvivors.com): the Ben Trane trial record,
         WWASP seminar manuals, a parent manual, a lawsuit
  straights  thestraights.net (http only), nhym  nhym-alumni.org
  sciad  SCIAD NET: a Zotero library whose
         Google Drive files (state records, court records, program documents,
         clippings) are listed; not crawled, read from the survey in tmp/sciad/
         (docs/PLAN.md 3.10), see build_sciad()

Only documents the site links from one of its public pages are listed: the
WWASP media library also holds duplicate uploads and sentencing letters it
never published.

Which record a document belongs to comes from RULES below (the folder the
site files it in, the page section, the linking post), never from a guess:
a document no rule ties to a record is left out and named in the report.

A document counts as held by KOP when its md5 equals a media library file's
(mdd_hash / _kop_import_md5), or a media library file has the same
distinctive name (>= 12 characters, used at most twice on the source site).

Steps
  python scripts/survivor-archives.py fetch [--site ssi|wwasp|straights|nhym]
      crawls the site, downloads every linked document once to hash it
      (resumable; nothing is kept but the md5) -> tmp/survivor-archives/<site>/
  python scripts/survivor-archives.py build
      needs tmp/prod.sqlite (scripts/sync-prod-sqlite.py) ->
      js/data/survivor-archives/<site>/{index.json, f/<id>.json, o/<id>.json}
      (read by inc/survivor-archives.php) and tmp/survivor-archives/build-report.md
"""
import argparse
import collections
import datetime
import hashlib
import html
import json
import os
import re
import sqlite3
import sys
import time
import unicodedata
import urllib.error
import urllib.parse
import urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'js', 'data', 'survivor-archives')
WORK = os.path.join(ROOT, 'tmp', 'survivor-archives')
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126 Safari/537.36'
DOC_EXT = re.compile(r'\.(pdf|docx?|odt|rtf|txt)$', re.I)

SITES = {
    'ssi': {'label': 'Surviving Straight Inc.', 'url': 'https://survivingstraightinc.com/', 'host': 'survivingstraightinc.com'},
    'wwasp': {'label': 'WWASP Survivors', 'url': 'https://wwaspsurvivors.com/', 'host': 'wwaspsurvivors.com'},
    # thestraights.net has no https (the TLS handshake fails): its documents link over http.
    'straights': {'label': 'thestraights.net', 'url': 'http://thestraights.net/', 'host': 'thestraights.net'},
    'nhym': {'label': 'New Horizons Alumni Association', 'url': 'https://www.nhym-alumni.org/', 'host': 'nhym-alumni.org'},
    # SCIAD NET is a Zotero library whose documents sit on Google Drive (docs.google.com for
    # its few Google Docs); the credit links to the archive's page (decision 18).
    'sciad': {'label': 'SCIAD NET', 'url': 'https://web.archive.org/web/20221007171605/https://www.sciad.net/',
              'host': 'drive.google.com'},
}

# KOP records: f<id> facilities_v2, o<id> wpdl_kop_operators.
STRAIGHT = 'o21'            # Straight Inc.
KIDS_CENTERS = 'o14'        # KIDS Centers of America (Miller Newton)
WWASP = 'o29'
BRANCH = {
    'atlanta': 'f9625', 'cincinnati': 'f9626', 'dallas': 'f9627', 'orlando': 'f9628', 'detroit': 'f9629',
    'sarasota': 'f9630', 'springfield': 'f9631', 'tampa': 'f9632', 'stoughton': 'f9633', 'columbia': 'f9634',
    'hampton': 'f9635', 'yorba': 'f9636',
}
KHK = 'f100123'             # Pathway Family Center, Ohio (formerly Kids Helping Kids)
PFC = 'f13089'              # Pathway Family Center, Michigan (Straight Detroit renamed)

# (field, pattern, targets), first match wins. Fields: file (file name),
# section (the heading above the link), folder (the site's folder), path (the whole document path),
# page (the page path or the linking post's title). Empty targets: the
# document is about no one program KOP has a record of.
RULES = {
    'ssi': [
        ('file', r'^(GrowingTogether|.*_GT\.pdf$)', []),           # Growing Together, Lake Worth FL: no KOP record
        ('folder', r'^(GrowingTogether|InternalDocumentsFromTim2004-2006|Alexandra-2004-2005|SecondChance|new\.items)$', []),
        ('section', r'^STEP$|^Second Chance', []),
        ('section', r'KIDS EL PASO', ['f10613']),
        ('section', r'KIDS-UTAH', ['f10365']),
        ('section', r'LIFELINE, UTAH', ['f10466']),
        ('section', r'^KIDS\b', ['f12760']),
        ('section', r'Pathway Family Center = Straight Inc \(Michigan\)', [PFC]),
        ('section', r'Newton Was National Clinical Director of Straight', [KIDS_CENTERS, STRAIGHT]),
        ('file', r'(KHK|StraightMidwest|Straight-Midwest)', [KHK]),
        ('file', r'Pathway', [PFC]),
        ('file', r'(SAFE|Substance-Abuse-and-Family|AssociatedCounseling)', ['f11222']),
        ('file', r'Possibilit', ['f13873']),
        ('file', r'^LIFE-', ['f100168']),
        ('folder', r'^(StPetersburgFLnewspaperArticles|TampaTribuneArticles)$', [BRANCH['tampa']]),
        ('folder', r'^Cincinnati', [BRANCH['cincinnati']]),
        ('folder', r'^Springfield', [BRANCH['springfield']]),
        ('folder', r'^Atlanta', [BRANCH['atlanta']]),
        ('folder', r'^Orlando', [BRANCH['orlando']]),
        ('folder', r'^Dallas', [BRANCH['dallas']]),
        ('folder', r'^ColumbiaMD', [BRANCH['columbia']]),
        ('folder', r'^(VirginiaBeachVA|HamptonRoadsVA)', [BRANCH['hampton']]),
        ('folder', r'^YorbaLinda', [BRANCH['yorba']]),
        ('folder', r'^StoughtonMA', [BRANCH['stoughton']]),
        ('folder', r'^Sarasota', [BRANCH['sarasota']]),
        ('folder', r'^(Seed|SeedArticles|Seed-otherArticlesandDocs|Seed\w+)$', ['f11229']),
        ('folder', r'^KIDS$', ['f10365']),                       # Utah corporate filings (corporate docs page)
        ('folder', r'^Life-Line$', ['f10466']),
        ('folder', r'^RotaryAdolescentTreatmentCenter', ['f11898']),
        ('folder', r'^SAFE$', ['f11222']),
        ('folder', r'^LIFE$', ['f100168']),
        ('folder', r'^(KidsHelpingKids|KHK\w*|PFCaquiresKHK|Section[56])$', [KHK]),
        ('folder', r'^PathwayFamilyCenter', [PFC]),
        ('folder', r'^PossibilitiesUnlimited$', ['f13873']),
        ('folder', r'^Kidscope$', ['f13254']),
        ('folder', r'^MillerNewton$', [KIDS_CENTERS]),
        ('folder', r'^documents$', ['f13339']),                  # New Horizons Youth Ministries, Indiana
        ('page', r'^/(document_library|newspaper__magazine|straight_articles|executive_staff|survivors_request|home$|$|links_to_more)', [STRAIGHT]),
    ],
    'straights': [
        ('path', r'/the-straights/kids-nj/', ['f12760']),               # KIDS of Bergen County, NJ (the only KIDS NJ record)
        ('path', r'presskit\.doc$', []),                                 # a Scientology press kit: another organisation
        ('path', r'DrBurksWellspringReport', ['f11222']),               # Wellspring's report on SAFE Orlando
        ('path', r'/documents/ststpetelicenseturnin', [BRANCH['tampa']]),   # Straight St. Petersburg gave up its licence in 1993
        ('path', r'newton|/heath-sued-june03/', [KIDS_CENTERS, STRAIGHT]),  # Miller Newton, National Clinical Director of Straight
        ('path', r'.', [STRAIGHT]),     # Bradbury v. Sembler filings, Sembler depositions, fliers, staff papers: Straight-wide
    ],
    'nhym': [
        ('file', r'Escuela_Caribe', ['f14115']),              # Caribbean Mountain Academy (past name Escuela Caribe), Dominican Republic
        ('file', r'.', ['f13339']),                           # New Horizons Youth Ministries, Indiana: its own papers, forms, filings
    ],
    'wwasp': [
        ('page', r'Trane|Ben Trial|Midwest Academy', ['f13398']),
        ('page', r'Paradise Cove', ['f14149']),
        ('page', r'WWASP', [WWASP]),
    ],
}

# Section headings that only say when a link was added or how to read it.
NOISE_SECTION = re.compile(r'^(\W*|added\b.*|coming soon|here|new|\(email this website.*|\*.*|.*link no longer working.*|'
                           r'name/identity withheld.*|\d+\)\s*)$', re.I)


def get(url, timeout=60):
    req = urllib.request.Request(url, headers={'User-Agent': UA})
    return urllib.request.urlopen(req, timeout=timeout)


def clean(s):
    s = html.unescape(re.sub(r'<[^>]+>', ' ', s or '')).replace('\ufeff', '').replace('\u200b', '')
    s = s.replace('SaveSave', '')  # the site editor's leftovers in headings
    return re.sub(r'\s+', ' ', s).strip()


# Link texts that do not say what the document is.
GENERIC_LINK = re.compile(r'^\W*(pdf( version)?|part \d+|here|click here|link|download|\d{4}|"?news"?)\W*$', re.I)


def quote_url(u):
    p = urllib.parse.urlsplit(u)
    return urllib.parse.urlunsplit((p.scheme, p.netloc, urllib.parse.quote(urllib.parse.unquote(p.path)), p.query, ''))


# ---- fetch ---------------------------------------------------------------------

YEAR = re.compile(r'^[\s\-\u2013]*((19|20)\d\d\s*([-\u2013]\s*((19|20)\d\d|\?|present))?|\d{4}s|undated|date unknown)[\s:]*$', re.I)


def extract_links(page, text, host):
    """The document links of one HTML page: each with the page, the bold
    heading above it and the folder it sits in."""
    title = clean((re.search(r'<title>(.*?)</title>', text, re.S | re.I) or [None, ''])[1])
    body = text[text.find('id="bd"'):] if 'id="bd"' in text else text
    href_re = r'href\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))'
    links = []
    section, last, prev_end, joinable = '', None, 0, False
    for m in re.finditer(r'<(h[1-6]|strong|b)\b[^>]*>(.*?)</\1>|<a\b[^>]*?' + href_re + r'[^>]*>(.*?)</a>', body, re.S | re.I):
        before = clean(body[prev_end:m.start()])
        prev_end = m.end()
        if m.group(1):
            c = clean(m.group(2))
            if c and joinable and not before and (c[0].islower() or c[0] in '-–'):
                # One heading split over bold runs: "Bush Pr" + "esidential Library",
                # "SAFE" + "- Orlando, FL 1992-2009".
                section += c if c[0].islower() else ' ' + c
            elif c and not YEAR.match(c) and len(c) < 120:
                section, joinable = c, True
                continue
            joinable = joinable and bool(c) and not before and not YEAR.match(c)
            continue
        joinable = False
        href = urllib.parse.urljoin(page, html.unescape(m.group(3) or m.group(4) or m.group(5) or ''))
        parts = urllib.parse.urlsplit(href)
        if parts.netloc.lower().replace('www.', '') != host or not DOC_EXT.search(parts.path):
            last = None
            continue
        href = quote_url(href)
        text_ = clean(m.group(6))
        if GENERIC_LINK.match(text_) and before:
            # "In the Middle of A Nightmare - PDF Version": the title is the text before it.
            context = re.sub(r'^Source:\s*', '', re.split(r'(?<=[.!?])\s', before)[-1].strip(' -:|'), flags=re.I)
            text_ = context[:160] + ' (' + text_.strip(' "') + ')'
        # A title split over several anchors ("A" + "rticles of Dissolution").
        if last is not None and last['url'] == href:
            last['name'] = (last['name'] + text_).strip()
            continue
        segs = urllib.parse.unquote(parts.path).strip('/').split('/')
        last = {'url': href, 'name': text_, 'page': urllib.parse.urlsplit(page).path or '/', 'page_title': title,
                'section': section, 'folder': segs[-2] if len(segs) > 1 else ''}
        links.append(last)
    return links


def crawl_ssi():
    """Every document link on every page of the sitemap."""
    sitemap = get(SITES['ssi']['url'] + 'sitemap.xml').read().decode('utf8', 'replace')
    links = []
    for loc in re.findall(r'<loc>([^<]+)</loc>', sitemap):
        page = quote_url(loc.replace('http://', 'https://'))
        try:
            text = get(page).read().decode('utf8', 'replace')
        except (urllib.error.URLError, OSError) as e:
            print('page failed', page, e)
            continue
        found = extract_links(page, text, SITES['ssi']['host'])
        for l in found:
            l['page_title'] = re.sub(r'^Surviving Straight Inc\s*-\s*', '', l['page_title'])
        links += found
        time.sleep(0.5)
    return links


SKIP_EXT = re.compile(r'\.(jpe?g|png|gif|bmp|ico|svg|webp|tiff?|css|js|mp3|mp4|wav|avi|mov|wmv|mpe?g|zip|rar|exe|swf|wma|flv|xml|rss)$', re.I)


def decode_page(raw, content_type):
    m = re.search(r'charset=([\w-]+)', content_type or '', re.I)
    enc = m.group(1) if m else ''
    if not enc:
        m = re.search(rb'charset=["\']?([\w-]+)', raw[:3000], re.I)
        enc = m.group(1).decode() if m else 'cp1252'
    try:
        return raw.decode(enc, 'replace')
    except LookupError:
        return raw.decode('cp1252', 'replace')


def crawl_site(site):
    """Breadth first from the home page, one request a second, same host only:
    every document link on every page (images, styles and scripts skipped)."""
    meta = SITES[site]
    host, scheme = meta['host'], meta['url'].split(':')[0]

    def norm(u):
        p = urllib.parse.urlsplit(u)
        if p.scheme not in ('http', 'https') or p.netloc.lower().replace('www.', '') != host:
            return None
        if p.query and re.match(r'^C=[NMSD];O=[AD]$', p.query):
            return None  # a directory index's sort links
        netloc = urllib.parse.urlsplit(meta['url']).netloc
        return urllib.parse.urlunsplit((scheme, netloc, urllib.parse.quote(urllib.parse.unquote(p.path) or '/'), p.query, ''))

    first = norm(meta['url'])
    queue, seen, links, failed = [first], {first}, [], 0
    try:  # a sitemap.xml, when there is one, names pages no link leads to
        sitemap = get(meta['url'] + 'sitemap.xml').read().decode('utf8', 'replace')
        for loc in re.findall(r'<loc>([^<]+)</loc>', sitemap):
            u = norm(html.unescape(loc.strip()))
            if u and u not in seen and not DOC_EXT.search(u) and not SKIP_EXT.search(u):
                seen.add(u)
                queue.append(u)
        print('%s: %d pages from sitemap.xml' % (site, len(queue)))
    except (urllib.error.URLError, OSError):
        pass
    while queue:
        page = queue.pop(0)
        try:
            with get(page, timeout=60) as r:
                ctype = r.headers.get('Content-Type', '')
                raw = r.read(5 << 20)
        except (urllib.error.URLError, OSError) as e:
            print('page failed', page, e)
            failed += 1
            time.sleep(1)
            continue
        time.sleep(1)
        if 'html' not in ctype.lower() and 'text' not in ctype.lower():
            continue
        text = decode_page(raw, ctype)
        for l in extract_links(page, text, host):
            l['url'] = norm(l['url'])
            links.append(l)
        page_path = urllib.parse.urlsplit(page).path or '/'
        on_page = {l['url'] for l in links if l['page'] == page_path}
        for href in re.findall(r'<(?:a|frame|iframe|area)\b[^>]*?(?:href|src)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s>]+))', text, re.I):
            u = norm(urllib.parse.urljoin(page, html.unescape(href[0] or href[1] or href[2])))
            if not u:
                continue
            path = urllib.parse.urlsplit(u).path
            if DOC_EXT.search(path) and u not in on_page:  # a document linked from an image map or frame
                segs = urllib.parse.unquote(path).strip('/').split('/')
                title = clean((re.search(r'<title>(.*?)</title>', text, re.S | re.I) or [None, ''])[1])
                links.append({'url': u, 'name': '', 'page': page_path, 'page_title': title,
                              'section': '', 'folder': segs[-2] if len(segs) > 1 else ''})
                on_page.add(u)
            if DOC_EXT.search(path) or SKIP_EXT.search(path) or u in seen:
                continue
            seen.add(u)
            queue.append(u)
        if len(seen) % 50 == 0:
            print('  %d pages seen, %d queued, %d links' % (len(seen), len(queue), len(links)))
    print('%s: %d pages crawled, %d failed' % (site, len(seen), failed))
    return links


def crawl_wwasp():
    """Documents in the WordPress media library that a published post or page
    links, named by their media title, grouped by the linking post."""
    base = SITES['wwasp']['url'] + 'wp-json/wp/v2/'

    def all_pages(kind):
        out, page = [], 1
        while True:
            with get(base + kind + '?per_page=100&page=%d' % page) as r:
                out += json.loads(r.read())
                total = int(r.headers.get('X-WP-TotalPages') or 1)
            if page >= total:
                return out
            page += 1

    media = {}
    for m in all_pages('media'):
        media[quote_url(m['source_url'])] = clean(m['title']['rendered'])
    links = []
    for kind in ('posts', 'pages'):
        for p in all_pages(kind):
            content = p['content']['rendered']
            seen = set()
            for u in re.findall(r'(?:href|src|data)="(https?://(?:www\.)?wwaspsurvivors\.com/wp-content/uploads/[^"]+)"', content, re.I):
                u = quote_url(html.unescape(u).replace('://www.', '://'))
                if not DOC_EXT.search(urllib.parse.urlsplit(u).path) or u in seen:
                    continue
                seen.add(u)
                links.append({'url': u, 'name': media.get(u, ''), 'page': clean(p['title']['rendered']),
                              'page_title': clean(p['title']['rendered']), 'section': '', 'folder': '',
                              'post_url': p['link']})
    return links


def hash_files(site, links):
    """md5 of every linked document, once; resumable."""
    path = os.path.join(WORK, site, 'files.jsonl')
    done = {}
    if os.path.exists(path):
        for line in open(path, encoding='utf8'):
            if line.strip():
                r = json.loads(line)
                done[r['url']] = r
    urls = sorted({l['url'] for l in links} - {u for u, r in done.items() if r.get('md5') or r.get('status') == 404})
    print('%s: %d documents, %d to hash' % (site, len({l['url'] for l in links}), len(urls)))
    with open(path, 'a', encoding='utf8', newline='\n') as out:
        for n, u in enumerate(urls, 1):
            row = {'url': u, 'md5': '', 'size': 0, 'status': 0}
            for attempt in range(3):
                try:
                    h = hashlib.md5()
                    with get(u, timeout=120) as r:
                        row['status'] = r.status
                        row['type'] = r.headers.get('Content-Type', '')
                        for chunk in iter(lambda: r.read(1 << 16), b''):
                            h.update(chunk)
                            row['size'] += len(chunk)
                    row['md5'] = h.hexdigest()
                    break
                except urllib.error.HTTPError as e:
                    row['status'] = e.code
                    if e.code in (403, 404, 410):
                        break
                except (urllib.error.URLError, OSError) as e:
                    row['status'] = -1
                    row['error'] = str(e)[:200]
                time.sleep(5 * (attempt + 1))
            out.write(json.dumps(row) + '\n')
            out.flush()
            if n % 50 == 0:
                print('  %d/%d' % (n, len(urls)))
            time.sleep(0.3)


def cmd_fetch(args):
    for site in ([args.site] if args.site else SITES):
        if site == 'sciad':
            print('sciad: nothing to fetch; the build reads the SCIAD NET survey in tmp/sciad/ (tmp/sciad/fetch.py, analyze.py)')
            continue
        os.makedirs(os.path.join(WORK, site), exist_ok=True)
        links = {'ssi': crawl_ssi, 'wwasp': crawl_wwasp}.get(site, lambda: crawl_site(site))()
        with open(os.path.join(WORK, site, 'links.json'), 'w', encoding='utf8', newline='\n') as fh:
            json.dump(links, fh, ensure_ascii=False, indent=1)
        print('%s: %d links on its pages' % (site, len(links)))
        hash_files(site, links)


# ---- build ---------------------------------------------------------------------

def file_key(name):
    """As in build-unsilenced-links.py: a file name reduced for comparison."""
    n = unicodedata.normalize('NFKD', name or '').encode('ascii', 'ignore').decode().lower().strip()
    n = re.sub(r'-pdf\.jpe?g$', '', n)
    n = re.sub(r'\.(pdf|jpe?g|png|gif|webp|docx?|tiff?|rtf|txt|odt|xlsx?|mp4|html?)$', '', n)
    n = re.sub(r'\s*\(\d+\)$', '', n)
    n = re.sub(r'^copy of ', '', n)
    return re.sub(r'[^a-z0-9]', '', n)


def file_name(url):
    return urllib.parse.unquote(urllib.parse.urlsplit(url).path.rsplit('/', 1)[-1])


def targets_for(site, link):
    fields = {'file': file_name(link['url']), 'path': urllib.parse.unquote(urllib.parse.urlsplit(link['url']).path), 'section': link['section'], 'folder': link['folder'], 'page': link['page']}
    for field, pattern, targets in RULES[site]:
        if re.search(pattern, fields[field], re.I):
            return targets, '%s ~ %s' % (field, pattern)
    return None, ''


VAGUE_NAME = re.compile(r'^(https?://.*|www\..*|doc|reply|link|story|page \d+|pages? [\d -]+|translated link|\W*\d*\W*)$', re.I)


def display_name(link, site=''):
    name = link['name']
    if site in ('straights', 'nhym') and (VAGUE_NAME.match(name) or len(re.sub(r'\W', '', name)) < 5):
        # A link text that says nothing: the file name, with the folder it sits in.
        base = re.sub(r'[_-]+', ' ', urllib.parse.unquote(DOC_EXT.sub('', file_name(link['url'])))).strip()
        folder = re.sub(r'[_-]+', ' ', link['folder']).strip()
        return (folder + ': ' + base) if folder and folder.lower() not in base.lower() else base
    if site in ('straights', 'nhym') and DOC_EXT.search(name):
        return re.sub(r'\s+', ' ', re.sub(r'[_-]+', ' ', urllib.parse.unquote(DOC_EXT.sub('', name)))).strip()
    if len(re.sub(r'\W', '', name)) < 3:
        name = re.sub(r'[_-]+', ' ', DOC_EXT.sub('', file_name(link['url']))).strip()
    return name


def group_label(site, link):
    """What the page shows the document under: the site's page (SSI) or the
    linking post (WWASP), with a meaningful section heading."""
    label = re.sub(r'\s+[-–]\s+Index Page$', '', link['page_title'])
    if site == 'straights' and (not label or label.lower() in ('untitled document', 'untitled')):
        label = re.sub(r'[_-]+', ' ', re.sub(r'\.s?html?$', '', link['page'].strip('/'))) or 'The Straights'
    sec = link['section']
    if sec and not NOISE_SECTION.match(sec) and sec.lower() not in label.lower():
        label = label + ': ' + re.sub(r'^\d+\)\s*', '', sec)
    return label


# ---- privacy ---------------------------------------------------------------------
# KOP only links, so it cannot redact a file: a document that is itself a private
# record is not listed, and a private person's name is taken out of every title and
# group KOP shows. The patterns below run on the file name, the link text, the
# section heading and the page title. Blank forms, handbooks, brochures, manuals,
# corporate filings, tax returns, court filings, transcripts, newspaper articles and
# government documents are not matched.

PRIVATE_PATTERNS = [
    # (reason, pattern, wide): wide patterns also look at the page title, the page path and the group label;
    # the others only at the file name, link text and section, so a group of mixed documents is not lost whole.
    ('yearbook', r'year[\s_-]?books?\b', True),
    ('client, student or medical record',
     r'\b(client|patient|resident|student|youth|intake|admission|discharge|medical|psychological|psychiatric|'
     r'school|academic|case|health|counseling|disciplinary)[\s_-]+(records?|charts?|evaluations?|histor(y|ies))\b', False),
    ('client, student or medical record',
     r'\b(psychological|psychiatric|medical|patient|client|resident)[\s_-]+(assessments?|summar(y|ies))\b|'
     r'\breport[\s_-]?cards?\b|\bgrade[\s_-]+reports?\b|\b(school|student|academic)[\s_-]+transcripts?\b', False),
    ('progress report about a youth',
     r'\b(progress|quarterly|monthly|weekly|treatment|case)[\s_-]+(reports?|plans?|updates?)[\s_-]+(on|for|of|about|re)\b|'
     r'\b(student|client|resident)[\s_-]+progress\b', False),
    ('application filled in by a family',
     r'\b(filled|completed|signed)[\s_-]+(in|out)?[\s_-]*(application|enrollment|form|packet)', False),
    ('personal or victim letter',
     r'\bletters?[\s_-]+(of[\s_-]+)?(support|reference|character)\b|\bcharacter[\s_-]+letters?\b|'
     r'\bsentencing[\s_-]+letters?\b|\bletters?[\s_-]+to[\s_-]+(the[\s_-]+)?(judge|court)\b|'
     r'\bletters?[\s_-]+(from|by)[\s_-]+(a[\s_-]+|the[\s_-]+)?(parents?|mother|father|student|survivor|former|alumn|resident)|'
     r'\b(parent|family|survivor|student)[\s_-]+letters?\b|\bpersonal[\s_-]+letters?\b', False),
    ('victim statement', r'\bvictim[\s_-]+(impact[\s_-]+)?(statements?|letters?)\b', True),
    ('survivor story or named victims', r'\bmy[\s_-]?story\b', True),
    ('survivor story or named victims',
     r'\b(survivor|personal)[\s_-]+(stor(y|ies)|accounts?|testimon(y|ies))\b|\banonymous\b|\bsuicides\b', False),
]
PRIVATE_RE = [(why, re.compile(p, re.I), wide) for why, p, wide in PRIVATE_PATTERNS]


def private_reason(*own, wide=()):
    """Why a document is a private record, or '' when it is not."""
    for why, rx, is_wide in PRIVATE_RE:
        for t in own + (tuple(wide) if is_wide else ()):
            if t and rx.search(t):
                return why
    return ''


def url_hash(url):
    return hashlib.sha1(url.encode('utf8')).hexdigest()


def load_privacy():
    """js/data/survivor-archives/privacy.json: {exclude: [sha1 of url], include:
    [sha1 of url: reviewed, not private, though a privacy pattern matches it],
    titles: {sha1 of url: redacted title}, groups: {sha1 of the group label:
    redacted label}}. Keys are hashes: the repo is public and holds no private name."""
    path = os.path.join(OUT, 'privacy.json')
    p = {}
    if os.path.exists(path):
        p = json.load(open(path, encoding='utf8'))
    return {'exclude': set(p.get('exclude') or []), 'include': set(p.get('include') or []),
            'titles': dict(p.get('titles') or {}), 'groups': dict(p.get('groups') or {})}


def cmd_privacy_review(args):
    """Every listed document across all sites, with the hashes privacy.json uses."""
    rows = []
    for site, meta in SITES.items():
        base = os.path.join(OUT, site)
        if not os.path.exists(os.path.join(base, 'index.json')):
            continue
        idx = json.load(open(os.path.join(base, 'index.json'), encoding='utf8'))
        seen = {}
        for kind, key in (('f', 'facilities'), ('o', 'operators')):
            for i in idx[key]:
                for e in json.load(open(os.path.join(base, kind, i + '.json'), encoding='utf8'))['files']:
                    seen.setdefault(e['url'], {'e': e, 'on': []})['on'].append(kind + i)
        rows += [(site, v['e'], v['on']) for v in seen.values()]
    priv = load_privacy()
    out = ['# Survivor archives privacy review', '',
           'Built %s. %d documents listed. Add to js/data/survivor-archives/privacy.json: "exclude" (sha1 of the url, '
           'for a private record or a url that carries a private name), "titles" (sha1 of the url -> redacted title), '
           '"groups" (sha1 of the group label -> redacted label). Never put a private name in that file.' % (
               datetime.date.today().isoformat(), len(rows)), '']
    for site, e, on in sorted(rows, key=lambda r: (list(SITES).index(r[0]), r[1]['group'], r[1]['name'])):
        out += ['- site %s | sha1 `%s` | group sha1 `%s` | records %s' % (site, url_hash(e['url']), url_hash(e['group']), ' '.join(sorted(on))),
                '  - title: %s' % e['name'], '  - group: %s' % e['group'], '  - url: %s' % e['url']]
    os.makedirs(WORK, exist_ok=True)
    with open(os.path.join(WORK, 'privacy-review.md'), 'w', encoding='utf8', newline='\n') as fh:
        fh.write('\n'.join(out) + '\n')
    print('%d documents -> tmp/survivor-archives/privacy-review.md (privacy.json now: %d excluded, %d titles, %d groups)' % (
        len(rows), len(priv['exclude']), len(priv['titles']), len(priv['groups'])))


def cmd_selftest(args):
    """Checks of the privacy rules and the privacy file, no network, no database."""
    must = ['1988-1989_New_Horizons_Academy_Yearbook.pdf', 'Student Records 1989', 'Medical records of J. Smith',
            'Psychological evaluation', 'Progress report on John', 'Victim impact statement', 'Letters of support for Trane',
            'Sentencing letters', 'Letter from a parent', 'Completed application packet', 'School records', 'report card 1991', '/MyStory/todd-sarasota-81-82-anonymous.doc', 'the suicides']
    keep = ['1991_Escuela_Caribe_Student_Handbook.pdf', '1988_Escuela_Caribe_School_Enrollment_Agreement.pdf',
            '2001_NHYM_Form_990.pdf', '2004_NHYM_Annual_Report.pdf', 'Court transcript of the Trane trial',
            'Letter to the Attorney General', 'Application blank form', 'Employment Application', 'Articles of Incorporation',
            'Parent Manual', 'Newspaper article: school closes', 'Progress report: state inspection of the facility']
    bad = [t for t in must if not private_reason(t)] + [t for t in keep if private_reason(t)]
    assert not bad, 'privacy patterns wrong for: %s' % bad
    assert url_hash('https://a.example/x.pdf') == hashlib.sha1(b'https://a.example/x.pdf').hexdigest()
    sample = {'exclude': {url_hash('https://a.example/x.pdf')}, 'titles': {url_hash('https://a.example/y.pdf'): 'Letter'}, 'groups': {}}
    assert url_hash('https://a.example/x.pdf') in sample['exclude']
    pj = os.path.join(OUT, 'privacy.json')
    if os.path.exists(pj):
        raw = json.load(open(pj, encoding='utf8'))
        keys = list(raw.get('exclude') or []) + list(raw.get('include') or []) + list((raw.get('titles') or {})) + list((raw.get('groups') or {}))
        assert not set(raw.get('exclude') or []) & set(raw.get('include') or []), 'privacy.json: a url both excluded and included'
        # Every value privacy.json shows (a redacted title or group) must be neutral words, never a hash or a url.
        assert all(isinstance(v, str) and v.strip() and '://' not in v for v in list((raw.get('titles') or {}).values()) + list((raw.get('groups') or {}).values()))
        assert all(re.fullmatch(r'[0-9a-f]{40}', k) for k in keys), 'privacy.json keys must be sha1 hex'
    # Cross-site dedupe: the same bytes for one record list once, whichever site comes first.
    seen = collections.defaultdict(set)
    first = [t for t in ('f1', 'f2') if 'm' not in seen[t] and not seen[t].add('m')]
    second = [t for t in ('f2', 'f3') if 'm' not in seen[t] and not seen[t].add('m')]
    assert first == ['f1', 'f2'] and second == ['f3']
    # SCIAD NET: court titles carry no party name; private program papers stay out.
    court = {'Jane Roe vs Hyde School Motion for Extension of Time': 'Motion, 2021',
             'Smith vs Grove School Answer of the Defendant to Plaintiffs Complaint': 'Answer, 2021',
             'Roe v. Program - Order Granting Defendants Joint Motion to Sever': 'Order, 2021',
             'Doe v. X 297 Exhibit A - Deposition of John Roe': 'Exhibit A, 2021',
             '30 - Minute entry: The court grants motion to dismiss Roe\'s cause of action': 'Minutes, 2021',
             '42-1': 'Court record, 2021, docket entry 42-1',
             'Roe v. Program, U.S. District Court, Case No. 2:05-cv-00123 Complaint': 'Complaint, U.S. District Court (UT), 2021, case 2:05-cv-00123'}
    for t, want in court.items():
        got = sciad_court_title({'title': t, 'date': '2021-03-01'}, 'UT')
        assert got == want, 'court title %r -> %r, wanted %r' % (t, got, want)
        assert not re.search(r'\b(Roe|Smith|Doe|John|Jane)\b', got), got
    row = lambda title, cat='programdoc', sub=('Program Documents',): {'title': title, 'cat': cat, 'privacy': 'institutional', 'subcats': list(sub)}
    for t in ('Jane Roe Face Sheet', 'Consent to Medical Treatment & Health Summary', 'Employee Wages 2010', 'Triangle Cross Program Application - 2019',
              'Discovery Registration Form', 'Monthly Phone Logs', 'Shift Leader Notes - 07-23-07 Redacted', 'Letter from John Roe', 'IMG_2201.jpg', 'Home Pass 1.pdf'):
        assert sciad_private(row(t)), 'should stay out: ' + t
    for t in ('Student Manual 2012', 'Parent Handbook 2006', 'Application Procedure', 'Olympus Academy Blank VOB Final', 'Seminar Information Sheet (Blank)',
              'Canyon State Academy Brochure', 'Corrective Action Plan 8.27.15'):
        assert not sciad_private(row(t)), 'should be listed: ' + t
    assert sciad_private(row('Incident-6.PNG', 'govrecord', ('DHS Records',))), 'state portal screenshots stay out'
    assert not sciad_private(row('Notice of Non-Compliance 05-04-2018.TIF', 'govrecord', ('DHS Records',))), 'scanned state notices stay'
    assert sciad_drive_url('https://drive.google.com/file/d/1e-bkBuDKecWt2NhJpuQwbnfSzBMSBDKz/view?usp=sharing')[1] == \
        'https://drive.google.com/file/d/1e-bkBuDKecWt2NhJpuQwbnfSzBMSBDKz/view'
    assert all(t[0] in 'fo' and t[1:].isdigit() for ts in SCIAD_RULES.values() for t in ts)
    print('selftest passed: %d private and %d kept samples, %d court titles' % (len(must), len(keep), len(court)))


# ---- SCIAD NET -------------------------------------------------------------------
# SCIAD NET (https://api.zotero.org/groups/4552235) is a Zotero library; three quarters of its items are Google Drive files. It is not crawled:
# the survey (docs/PLAN.md 3.10 step 3, tmp/sciad/survey.md) fetched every item, and its
# analysis writes what the build reads:
#   tmp/sciad/items.jsonl    one row per item: title, url, date, category, privacy class,
#                            SCIAD subcollections, program collections
#   tmp/sciad/programs.json  each program collection -> the KOP record its name matches exactly
#   tmp/sciad/4552235/items/ the raw pages, for the tags
# Only Drive files are listed (news links go to the review queue, part B). Drive gives
# no md5 without an API key, so what KOP already has is found by name:
#   - a distinctive file name (>= 12 characters) that Unsilenced also has
#     (tmp/unsilenced/files.jsonl): the two archives are copies of one collection
#     (owner decision 2026-10-01); a short name counts when Unsilenced has it in the same
#     program's folder, as many times as it has it there;
#   - a distinctive name in KOP's media library;
#   - a dated state record KOP holds for the same program on the same date.
# The record is the program collection's exact match (programs.json) or SCIAD_RULES
# below, never a near match; the tags only when the collection matches nothing, and only
# when they name exactly one record in its state. Groups are SCIAD's categories.
# Privacy: every private class stays out (survivor stories, photos and event media, police
# records, juvenile/custody files, intake/medical/school records, yearbooks, letters,
# forum posts, blogs, videos); court records are listed under titles made only from the
# kind of document, the court, the year and the case or docket number, never a name; no
# abstract, creator, tag or Zotero user is ever written out.

SCIAD_DIR = os.path.join(ROOT, 'tmp', 'sciad')
UNSILENCED_FILES = os.path.join(ROOT, 'tmp', 'unsilenced', 'files.jsonl')

DEVEREUX, SEQUEL = 'o8', 'o20'
# SCIAD program collection key -> KOP records, where the exact name match fails or finds
# two records: HQ collections, renamed programs, a chain's campuses KOP has no record of
# (their parent company's page), and KOP's own duplicates (the record under that name).
SCIAD_RULES = {
    'P8TRWRKL': [WWASP],                # UT WWASP HQ
    'Q286RXE6': [SEQUEL],               # AL Sequel Services HQ
    # Sequel and Devereux campuses with no KOP record: the parent company's page.
    '32CPBKVL': [SEQUEL], 'FHI74F3A': [SEQUEL], 'FYLLWVMD': [SEQUEL], 'KBD3G7RG': [SEQUEL], 'GG7SNQAI': [SEQUEL],
    'RU3IXN88': [SEQUEL], 'WCYQB7QA': [SEQUEL], 'CELBXJ4P': [SEQUEL], 'W8ZZEK78': [SEQUEL], '6NI2MI7I': [SEQUEL],
    'NPVKKB2E': [SEQUEL],
    'QI4G8FL3': [DEVEREUX], 'U226WRSM': [DEVEREUX], 'GICE27RR': [DEVEREUX], 'QBE945IA': [DEVEREUX], 'P453NUES': [DEVEREUX],
    'RV4LV5IW': [DEVEREUX], '2SVW4AA2': [DEVEREUX], 'EZAAIQ4X': [DEVEREUX], '68J68QZZ': [DEVEREUX], 'X6EJTMN6': [DEVEREUX],
    'A5R867G3': [DEVEREUX], '795TJSC6': [DEVEREUX], '78TGVVXC': [DEVEREUX], 'GYZ79JMM': [DEVEREUX], 'WWPE7GPC': [DEVEREUX],
    'IN6TKSPF': [DEVEREUX], 'C68BEEGU': [DEVEREUX],
    # Same program under another name or spelling.
    'EKPJ5WLC': ['f12729'],             # MT Spring Creek Lodge = Spring Creek Lodge Academy
    'RGCFMLFL': ['f11122'],             # FL Sandy Pines = SandyPines RTC
    'LAD7RB2N': ['f12293'],             # AZ Spring Ridge Academy - New Day Rising
    'DCZIFWVK': ['f10375'],             # UT Majestic Ranch = Old West Academy (past name Majestic Ranch Academy)
    'CHM2ICJ2': ['f10412'],             # UT Center For Change
    'VXQEGVAA': ['f13431'],             # WY Red Top Meadows Residential Treatment Center
    'KN3ESNK6': ['f10858'],             # TX Pegasus Schools, Inc = Pegasus School
    'KVMN8FGU': ['f10779'],             # TX High Frontier = High Frontier RTC
    '8PKN8YGV': ['f10871'],             # TX Resolution Ranch = Resolution Ranch Academy
    'XAC94R69': ['f10740'],             # TX Freedom Place = Freedom Place RTC
    'A2CRWSGY': ['f12341'],             # OR Catherine Freer Wilderness
    '7HI35GBV': ['f14033'],             # WV Greenbrier Academy = Greenbrier Academy for Girls
    'QVGSYP6A': ['f12031'],             # NY Aurora Concepts Inc
    'VYARTTYX': ['f13241'],             # OH Cornell Abraxas
    'RL44ULBE': ['f10554'],             # UT Turning Point Family Care
    'GYA7VFF4': ['f10649'],             # TX Azleway Valley View
    'HLENZVP7': ['f13151'],             # MI Holy Cross Services - St Vincent Home (of Saginaw)
    'IY32N7TT': ['f10665'],             # TX Boysville Inc = Boysville Texas GRO
    'GQBMWJXN': ['f13417'],             # WY Cathedral Home for Children = Cathedral Home for Youth (Laramie)
    'IKLDGG4R': ['f13414'],             # WY Big Horn Basin Association Adolescent Program
    'WXWP4A2Q': ['f12204'],             # AZ Cottonwood de Tucson
    'BEYZV8XF': ['f100006'],            # CO Cedar Springs Behavioral Health Systems = The Brown Schools at Cedar Springs
    '3XDDW2SB': ['f11429'],             # MO Mountain Park Boarding Academy = Mountain Park Baptist Boarding Academy
    'SNCM94N2': ['f12652'], 'QAD2XHCJ': ['f12652'],   # Open Sky Wilderness (SCIAD files it under UT; it is in Durango, CO)
    # One name, two KOP records: the record that carries the name now; "A/B" names both.
    'CLVLWGI2': ['f100076'],            # AL Sequel TSI Madison
    '2G4AHYMS': ['f12155', 'f12161'],   # AZ Copper Canyon Academy/Sedona Sky Academy
    'CBTJ6S7C': ['o5418'],              # AZ Four Directions (two facility records; the operator record)
    'JRVCENTB': ['o5454'],              # AZ New Hope of Arizona, Inc (six facility records; the operator record)
    'EPJX7U6L': ['f11468'],             # AR Teen Challenge Adventure Ranch
    'NPQ32K6U': ['f9814'],              # CA Bell Academy
    'ZRKZ7I9T': ['f11092'],             # FL Charles Britt Academy
    'EGRGHSMN': ['f11095'],             # FL Marion Youth Academy
    '7RH9DFKR': ['f11201'],             # FL Okeechobee Youth Development Center
    'ILI2WUHQ': ['f11098'],             # FL St. John's Youth Academy
    '4V3ZI32N': ['f13746', 'f13795'],   # LA Red River Academy/US Youth Services
    'W4CQ36EQ': ['f12713'],             # MT Montana Academy
    'HXLEFUSU': ['f100097'],            # NV Horizon Academy
    'FY6XCXGK': ['f14109', 'f14111'],   # Academy at Dundee Ranch/Pillars of Hope (Costa Rica)
    'AIYHFX4W': ['f14116', 'f100025'],  # The Academy/Coral Island Academy
    'XET6N3QH': ['f11638'],             # PA South Mountain Secure Treatment Unit
    'E65JETCB': ['f13983'],             # SC Carolina Springs Academy
    'REUV2EU4': ['f10618'],             # TX San Marcos Treatment Center
    'W85F8LN5': ['f10943'],             # TX Texas NeuroRehab Center
    'QGAJ2PKA': ['f10346'],             # UT Aspen Institute for Behavioral Assessment
    'S8CUAG7R': ['f10425'],             # UT Diamond Ranch Academy
    '4H86CTRT': ['f100193'],            # UT Zion Hills Academy
    'WHISADZK': ['f10644'],             # TX Austin Oaks Hospital
    'DSPI2UYF': ['f9637'],              # NC Auldern Academy
    '75LX3GL7': ['f11653'],             # NC Stone Mountain School
    '2SSZQQU8': ['f11249'],             # TN Oak Plains Academy
    'LNEMBR7N': ['f11101'],             # FL Anderson Academy
    '9CD3HQJP': ['f13884', 'f13885'],   # KY Bellewood and Brooklawn
    'YIBC6525': ['f12685'],             # MT Embark at Flathead Valley
    'X6WEXUDR': ['f11145'],             # FL Brooksville Youth Academy
    'HZG3P572': ['f11572'],             # PA Embark at the Poconos
}

# SCIAD subcollection -> the group the page shows, in this order.
SCIAD_GOV_GROUP = {'DHS Records': 'DHS Records', 'DHS Record': 'DHS Records', 'DHS Reports': 'DHS Records',
                   'Oregon DHS Records': 'DHS Records', 'Public Records': 'Public Records', 'Public Record': 'Public Records',
                   'Public Documents': 'Public Records', 'Reports': 'Public Records', 'Government': 'Public Records',
                   'Hospital Inspections': 'Hospital Inspections', 'State Cables': 'State Cables',
                   'Financials': 'Financials', 'Workforce Unemployment': 'Workforce Unemployment'}
SCIAD_CAT_GROUP = {'legal': 'Court records', 'programdoc': 'Program Documents', 'programinfo': 'Program Info',
                   'news': 'News clippings', 'media': 'Media', 'research': 'Research', 'other': 'Other documents'}
SCIAD_GROUP_ORDER = ['DHS Records', 'Public Records', 'Hospital Inspections', 'State Cables', 'Financials',
                     'Workforce Unemployment', 'Court records', 'Program Documents', 'Newsletters', 'Program Info',
                     'News clippings', 'Media', 'Research', 'Other documents']
# What a document with a name that says nothing ("4557397.pdf", "Incident-6.PNG") is called.
SCIAD_SINGULAR = {'DHS Records': 'State record', 'Public Records': 'Public record', 'Hospital Inspections': 'Hospital inspection',
                  'State Cables': 'State cable', 'Financials': 'Financial record', 'Workforce Unemployment': 'Unemployment record',
                  'Program Documents': 'Program document', 'Newsletters': 'Newsletter', 'Program Info': 'Program information',
                  'News clippings': 'News clipping', 'Media': 'Media file', 'Research': 'Paper', 'Other documents': 'Document'}

# Leave out (reason, pattern, categories it applies to or None for all), on the item's title.
AGENCY = (r'(department|dept\b|\bdhs\b|dcfs|\bdcs\b|dshs|licens|state of|attorney general|governor|senat|congress|'
          r'commission|\bboard\b|division|agency|office of|ombuds|inspector|deficienc|citation|corrective|'
          r'compliance|violation|survey|of (concern|warning|intent|findings|determination|revocation|suspension|'
          r'probation|approval|denial|reprimand|censure)|warning letter|demand letter|closure letter|'
          r'determination letter|accreditation|county|city of|school district|irs\b|internal revenue)')
SCIAD_PRIVATE = [
    ('video or audio recording', r'\.(avi|mp4|m4v|mov|wmv|mpe?g|3gp|flv|webm|ram|rm|mp3|m4a|wav|wma|aac|ogg|05m)$', None),
    # Screenshots of a state portal (Texas CCL's compliance history, which KOP's TX inspection pages
    # already carry): no date or name says which report; the rest are photos. Scanned state notices (.tif) stay.
    ('state portal screenshot (KOP has the state record)', r'\.(jpe?g|png|gif|bmp|webp)$', {'govrecord'}),
    ('photo or image', r'\.(jpe?g|png|gif|heic|bmp|webp)$', None),
    ('photo or image', r'\.tiff?$', {'programdoc', 'programinfo', 'other', 'media', 'news', 'research'}),
    ('juvenile, custody or guardianship file',
     r'\b(juvenile court|custody|guardian(ship)?|divorce|dependency|adoption|parental rights|protective order|'
     r'restraining order|probate|conservator(ship)?|delinquen\w*|in re\b|in the (matter|interest) of|minor child|'
     r'estate of)', None),
    ('police record', r'\b(police|sheriff\W?s? (report|call|record)|arrest(ed)? report|booking|mug ?shot|911 call|cad report|'
                      r'offense report|case report)\b', None),
    ('special education case about a student', r'\b(due process (hearing|decision|complaint)|special ed(ucation)? '
                                               r'(case|hearing|decision)|\biep\b)', None),
    ('client, student or medical record',
     r'(intake|admissions? (form|packet|papers|paperwork|agreement|application)|medical (history|record|form|file)|'
     r'health (history|information|record|form)|release of (medical )?information|authori[sz]ation (to|for) '
     r'(release|disclose|treat)|consent (form|to treat)|home ?pass|progress (report|note)s?|treatment plan|'
     r'discharge|psych(ological|iatric)? (eval|assessment|report)|evaluation (of|for)\b|report card|grade report|'
     r'transcripts? of grades|school (record|transcript)|student (file|record|transcript)|case (file|notes?)|'
     r'clinical (notes?|records?)|level (sheet|chart|card)|point sheet|behavior (contract|log)|'
     r'enrollment (form|application|agreement|contract)|application for (admission|enrollment)|'
     r'parent choose ?outs?|family history|social history|sibling|contract (with|for) (parents?|student))', None),
    ('client, student or medical record', r'\bincident (report|log)', {'programdoc', 'programinfo', 'other', 'media', 'news', 'research'}),
    # A program's papers that are filled in for one young person, family or employee (read title by
    # title in the 2026-10-01 review): face sheets, medical and consent forms, logs and notes, surveys,
    # applications, payroll and payment papers. Blank forms and templates stay.
    ('client, student or medical record',
     r'^(?!.*\b(blank|template)\b).*(face ?sheet|admissions? record|merit sheet|permission slip|medical (information|memo|report)|'
     r'immuni[sz]ation|physical exam|medication|phone (logs?|contact)|skills development report|psycho ?social|'
     r'shift (leader )?notes|\bnotes?\b.*redacted|random notes|relapse prevention plan|\bicpc\b|insurance verification|'
     r'\bvob\b|what state did youth|acceptance list|rep assignments|\bsurveys?\b|completion checklist|'
     r'information worksheet|referral (form|information)|registration|release[\s_-]+(form|of[\s_-]+information)|'
     r'authori[sz]ation to (use|disclose)|receipt[\s_-]+of[\s_-]+privacy|consent|life contract|coming home contract|'
     r'foster parent contract|certification|recording sheet|activity log|school-?forms|fep-reg)',
     {'programdoc', 'programinfo', 'other'}),
    ('personal financial or employment record',
     r'^(?!.*\b(blank|template)\b).*(\bw-?9\b|eligibility verification|\bwages\b|allowance|credit card|payment authori|'
     r'financial sponsor|\bloans?\b|sallie mae|slm financial|personal monthly budget)', {'programdoc', 'programinfo', 'other'}),
    ('application filled in by a family or applicant',
     r'^(?!.*\b(procedure|checklist|information|requirements|instructions|process|guide|blank|template)\b).*(\bapplication\b|\bapp\b)',
     {'programdoc', 'programinfo', 'other'}),
    ('form filled in for one person', r'^(?!.*\b(blank|template)\b).*\bform\b', {'programdoc'}),
    ('survivor story, testimony or blog',
     r'(my story|story of|testimon|journal entr|diary|\bblog|poem|essay|memoir|survivor|interview with|'
     r'what happened to me|own words|my (son|daughter|child|time|experience))', {'programdoc', 'programinfo', 'other', 'media', 'news', 'research', 'govrecord'}),
    ('yearbook, photos or event media', r'\b(yearbooks?|photos?|pictures?|album|scrapbook|graduation|prom|reunion|'
                                        r'protest|rally|vigil|slideshow|video)\b', None),
    ('obituary or memorial', r'(obituar|memorial|funeral|in memory)', None),
    ('forum or social post', r'\b(facebook|reddit|fornits|forum|tweets?|instagram|tiktok|message board|chat log|'
                             r'text messages?|screen ?shots?)\b', None),
]
SCIAD_PRIVATE_RE = [(why, re.compile(p, re.I), cats) for why, p, cats in SCIAD_PRIVATE]
LETTER_RE = re.compile(r'\b(letters?|e-?mails?|correspondence|note (to|from)|card (to|from))\b', re.I)
AGENCY_RE = re.compile(AGENCY, re.I)
SCIAD_CLASS = {'survivor': 'survivor story or forum post', 'photo': 'photo or event media', 'police': 'police record'}


def sciad_private(row):
    """Why a SCIAD Drive file stays out, or ''."""
    title, cat, subcats = row['title'], row['cat'], set(row['subcats'])
    if row['privacy'] == 'private':
        return SCIAD_CLASS.get(cat) or ('juvenile, custody or guardianship file' if cat == 'legal' else 'private record (title)')
    if 'State Special Ed Cases' in subcats:
        return 'special education case about a student'
    for why, rx, cats in SCIAD_PRIVATE_RE:
        if (cats is None or cat in cats) and rx.search(title):
            return why
    if cat != 'legal' and LETTER_RE.search(title) and not AGENCY_RE.search(title):
        return 'personal letter'
    why = private_reason(title)
    return why


def name_key(s):
    """As tmp/sciad/common.py: lowercase ascii letters and digits, '&' = and, no 'the', no Inc/LLC."""
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower().replace('&', ' and ')
    s = re.sub(r"['`]", '', s)
    s = re.sub(r'\([^)]*\)', ' ', s)
    s = re.sub(r'[^a-z0-9]+', ' ', s).strip()
    s = re.sub(r'^the ', '', s)
    s = re.sub(r' (inc|llc|ltd|corp|co|lp|pllc|incorporated)$', '', s)
    return s.replace(' ', '')


def paren_names(s):
    out = [s]
    for m in re.findall(r'\(([^)]*)\)', s or ''):
        out.append(re.sub(r'^(formerly|fka|f/k/a|aka|a\.k\.a\.|now|previously|later|also)\s+', '', m.strip(), flags=re.I))
    base = re.sub(r'\([^)]*\)', '', s or '')
    out += re.split(r'\s*/\s*|\s+aka\s+|\s+a\.k\.a\.\s+|\s+fka\s+|\s+-\s+formerly\s+', base, flags=re.I)
    return [x for x in dict.fromkeys(o.strip() for o in out) if x]


DRIVE_FILE = re.compile(r'drive\.google\.com/(?:a/[^/]+/)?(?:file/d/|open\?(?:.*&)?id=|uc\?(?:.*&)?id=)([A-Za-z0-9_-]{20,})')
GOOGLE_DOC = re.compile(r'docs\.google\.com/(document|spreadsheets|presentation)/d/([A-Za-z0-9_-]{20,})')


def sciad_drive_url(url):
    """(file id, the Drive view url) for a Drive file or Google Doc, else (None, None)."""
    m = DRIVE_FILE.search(url or '')
    if m:
        return m.group(1), 'https://drive.google.com/file/d/%s/view' % m.group(1)
    m = GOOGLE_DOC.search(url or '')
    if m:
        return m.group(2), 'https://docs.google.com/%s/d/%s/edit?usp=sharing' % (m.group(1), m.group(2))
    return None, None


def sciad_date(row):
    """YYYY, YYYY-MM or YYYY-MM-DD from the item's date, else from its title, else ''."""
    for t in (row.get('date') or '', row['title']):
        m = re.search(r'\b((?:19|20)\d\d)(?:[-_ /](\d\d)(?:[-_ /](\d\d))?)?\b', t)
        if m:
            y, mo, d = m.groups()
            if mo and not 1 <= int(mo) <= 12:
                mo = d = None
            if d and not 1 <= int(d) <= 31:
                d = None
            return '-'.join(x for x in (y, mo, d) if x)
    return ''


COURT_KIND = [
    (r'\bexhibit|\battachment', 'Exhibit'),
    (r'minute (entry|order)|\bminutes\b', 'Minutes'),
    (r'amended complaint', 'Amended complaint'),
    (r'\bcomplaint', 'Complaint'), (r'counter-?claim', 'Counterclaim'),
    (r'\bmotion', 'Motion'), (r'memorand', 'Memorandum'), (r'\b(order|ruling)\b', 'Order'),
    (r'\bopinion', 'Opinion'), (r'judg(e)?ment', 'Judgment'), (r'\bverdict', 'Verdict'),
    (r'\bdeposition|\bdepo\b', 'Deposition'), (r'transcript', 'Transcript'), (r'affidavit', 'Affidavit'),
    (r'declaration', 'Declaration'), (r'\bbrief\b', 'Brief'), (r'petition', 'Petition'), (r'subpoena', 'Subpoena'),
    (r'stipulat', 'Stipulation'), (r'settlement', 'Settlement'), (r'indictment', 'Indictment'),
    (r'\bplea\b', 'Plea'), (r'sentenc', 'Sentencing record'), (r'interrogator', 'Interrogatories'),
    (r'docket', 'Docket'), (r'summons', 'Summons'), (r'\banswer', 'Answer'), (r'\bnotice', 'Notice'),
    (r'\b(response|reply|opposition|objection|surreply)', 'Response'), (r'\bappeal', 'Appeal'), (r'hearing', 'Hearing record'),
    (r'warrant', 'Warrant'), (r'consent decree', 'Consent decree'), (r'\bappearance', 'Appearance'),
    (r'\bwithdraw', 'Withdrawal'), (r'\bletter', 'Letter'), (r'e-?mails?\b', 'Email'),
    (r'\bcharg', 'Charging document'), (r'\breport\b', 'Report'), (r'\bdiscovery\b|request for production|admissions\b', 'Discovery'),
]
COURT_KIND_RE = [(re.compile(p, re.I), k) for p, k in COURT_KIND]
COURT_NAME = [
    (r'u\.?\s?s\.? district court|united states district court|federal (district )?court|\bd\. ?(utah|mont|idaho)', 'U.S. District Court'),
    (r'court of appeals|appellate court|\bcir(cuit)?\.? court of appeals', 'Court of Appeals'),
    (r'supreme court', 'Supreme Court'), (r'bankruptcy', 'Bankruptcy Court'), (r'superior court', 'Superior Court'),
    (r'circuit court', 'Circuit Court'), (r'district court', 'District Court'), (r'court of common pleas', 'Court of Common Pleas'),
    (r'chancery', 'Chancery Court'), (r'tax court', 'Tax Court'),
]
COURT_NAME_RE = [(re.compile(p, re.I), k) for p, k in COURT_NAME]
CASE_NO_RE = [re.compile(r'\b(\d:\d{2}-?[a-z]{2}-?\d{3,6})(?:-[a-z]{2,4})?\b', re.I),
              re.compile(r'\b(?:case|civil action|cause|docket)\s*(?:no\.?|number|#)?\s*:?\s*([A-Z0-9]{0,4}[-:]?\d{2,}[-:A-Z0-9]*\d)\b', re.I),
              re.compile(r'\bno\.\s*([A-Z0-9]{0,4}[-:]?\d{2,}[-:A-Z0-9]*\d)\b', re.I)]


def sciad_court_title(row, state):
    """A court record's title from the kind of document, the court, the year and the case or
    docket number alone: never a party, parent, witness or minor name."""
    t = row['title']
    # The kind word that comes first: "Motion for Order of Compliance" is a motion,
    # "Order Granting ... Motion" an order, "Answer ... to Complaint" an answer.
    hits = [(m.start(), n, k) for n, (rx, k) in enumerate(COURT_KIND_RE) for m in [rx.search(t)] if m]
    kind = min(hits)[2] if hits else 'Court record'
    court = next((k for rx, k in COURT_NAME_RE if rx.search(t)), '')
    parts = [kind]
    if court:
        parts.append(court + (' (%s)' % state if state and court != 'U.S. Supreme Court' else ''))
    year = (sciad_date(row) or '')[:4]
    if year:
        parts.append(year)
    case = next((m.group(1) for rx in CASE_NO_RE for m in [rx.search(t)] if m), '')
    if case and sum(c.isdigit() for c in case) >= 3:
        parts.append('case ' + case)
    m = re.fullmatch(r'\s*(\d{1,4})(?:[-_ ](\d{1,3}|main))?(?:\.pdf)?\s*', t, re.I)
    if m:
        parts.append('docket entry ' + m.group(1) + ('-' + m.group(2) if m.group(2) and m.group(2) != 'main' else ''))
    m = re.search(r'\bexhibit\s+([A-Z]{1,3}|\d{1,3})\b', t, re.I)
    if m and kind == 'Exhibit':
        parts[0] = 'Exhibit ' + m.group(1).upper()
    return ', '.join(parts)


MEDIA_EXT = re.compile(r'\.(pdf|docx?|odt|rtf|txt|xlsx?|pptx?|jpe?g|png|gif|tiff?|html?)$', re.I)


def sciad_title(row, group):
    """The title shown: the item's own, tidied, or the group and date when it says nothing."""
    t = MEDIA_EXT.sub('', row['title'].strip())
    t = t.replace('___', ', ').replace('_', ' ')
    t = re.sub(r'\b((?:19|20)\d\d) (\d\d) (\d\d)\b', r'\1-\2-\3', t)
    t = re.sub(r'\s+', ' ', t).strip(' ,-')
    if len(t) > 180:
        t = t[:177].rsplit(' ', 1)[0] + '...'
    if len(file_key(row['title'])) < 12:
        date = sciad_date(row)
        lead = SCIAD_SINGULAR.get(group, 'Document')
        return ('%s, %s (%s)' % (lead, date, t)) if date else ('%s (%s)' % (lead, t)) if t else lead
    return t


def sciad_group(row):
    if row['cat'] == 'govrecord':
        return next((SCIAD_GOV_GROUP[c] for c in row['subcats'] if c in SCIAD_GOV_GROUP), 'Public Records')
    if row['cat'] == 'programdoc' and set(row['subcats']) & {'Newsletters', 'Newsletter', 'Cross Creek Chronicles', 'Dundee Update'}:
        return 'Newsletters'
    return SCIAD_CAT_GROUP.get(row['cat'], 'Other documents')


def build_sciad(db, privacy, kop_names, report):
    """SCIAD NET's Drive files -> (lists, stats, unmatched, documents), or None without the survey."""
    items_path, programs_path = os.path.join(SCIAD_DIR, 'items.jsonl'), os.path.join(SCIAD_DIR, 'programs.json')
    if not (os.path.exists(items_path) and os.path.exists(programs_path) and os.path.exists(UNSILENCED_FILES)):
        return None
    programs = json.load(open(programs_path, encoding='utf8'))
    rows = [json.loads(l) for l in open(items_path, encoding='utf8') if l.strip()]

    # Unsilenced's names: every distinctive one, and each short one by the program folder it sits in.
    uns_names, uns_folder = set(), collections.Counter()
    for line in open(UNSILENCED_FILES, encoding='utf8'):
        if not line.strip():
            continue
        r = json.loads(line)
        k = file_key(r.get('name', ''))
        if len(k) >= 12:
            uns_names.add(k)
        elif k:
            for seg in (r.get('path') or [])[1:]:
                uns_folder[(name_key(seg), k)] += 1
    used_folder = collections.Counter()

    # Tags, for items in a collection that matches no record (never written out).
    tags, item_cols = {}, {}
    for f in sorted(os.listdir(os.path.join(SCIAD_DIR, '4552235', 'items'))):
        if f.endswith('.json'):
            for it in json.load(open(os.path.join(SCIAD_DIR, '4552235', 'items', f), encoding='utf8')):
                tags[it['key']] = [t['tag'] for t in it['data'].get('tags', [])]
                item_cols[it['key']] = it['data'].get('collections', [])
    # Court records sit in one subcollection per case, Programs / <state> / <program> / Legal / <case>:
    # the page groups them by case, under a label made from the case number or years, never its name.
    cols = {}
    for f in sorted(os.listdir(os.path.join(SCIAD_DIR, '4552235', 'collections'))):
        if f.endswith('.json'):
            for c in json.load(open(os.path.join(SCIAD_DIR, '4552235', 'collections', f), encoding='utf8')):
                cols[c['key']] = (c['data']['name'], c['data'].get('parentCollection') or None)

    def case_of(item_key):
        for ck in item_cols.get(item_key, []):
            chain, k = [], ck
            while k and k in cols and len(chain) < 20:
                chain.insert(0, k)
                k = cols[k][1]
            names = [cols[k][0] for k in chain]
            if len(chain) >= 5 and names[0] == 'Programs' and names[3] == 'Legal':
                return chain[4]
        return None
    fac_keys = collections.defaultdict(set)
    fac_names = {}
    for fid, name, state, js in db.execute('SELECT id, name, state, json_data FROM facilities_v2'):
        ident = (json.loads(js or '{}') or {}).get('identification') or {}
        fac_names[fid] = name or ''
        for n in [name, ident.get('name'), ident.get('currentName')] + list(ident.get('pastNames') or []) + list(ident.get('otherNames') or []):
            if isinstance(n, dict):
                n = n.get('name') or ''
            if isinstance(n, str) and len(name_key(n)) >= 5:
                fac_keys[name_key(n)].add((fid, (state or '').upper()))
    state_names = {k.lower() for k in ('Alabama Alaska Arizona Arkansas California Colorado Connecticut Delaware Florida Georgia '
                                       'Hawaii Idaho Illinois Indiana Iowa Kansas Kentucky Louisiana Maine Maryland Massachusetts '
                                       'Michigan Minnesota Mississippi Missouri Montana Nebraska Nevada Ohio Oklahoma Oregon '
                                       'Pennsylvania Tennessee Texas Utah Vermont Virginia Washington Wisconsin Wyoming').split()}

    def tag_target(key, state):
        ids = set()
        for t in tags.get(key, []):
            if t.lower() in state_names or len(name_key(t)) < 5:
                continue
            ids |= {fid for fid, st in fac_keys.get(name_key(t), ()) if st == state}
        return {'f%d' % ids.pop()} if len(ids) == 1 else set()

    # KOP's own state inspection reports: (state, program name key) -> dates.
    insp = collections.defaultdict(set)
    for st, fname, pname, rdate in db.execute(
            'SELECT f.state, f.facility_name, f.program_name, r.report_date FROM inspection_reports r '
            'JOIN inspection_facilities f ON f.id = r.facility_id'):
        m = re.match(r'(\d{1,2})/(\d{1,2})/(\d{4})', rdate or '')
        d8 = '%s-%02d-%02d' % (m.group(3), int(m.group(1)), int(m.group(2))) if m else (rdate or '')[:10]
        for n in (fname, pname):
            if n:
                insp[((st or '').upper(), name_key(n))].add(d8)

    def kop_has_report(row, targets):
        m = re.search(r'((?:19|20)\d\d)-?(\d\d)-?(\d\d)', row['title'])
        if not m:
            return False
        d8 = '%s-%s-%s' % m.groups()
        for pk in row['programs']:
            p = programs.get(pk) or {}
            keys = {name_key(v) for v in paren_names(p.get('name', ''))}
            keys |= {name_key(fac_names.get(int(t[1:]), '')) for t in targets if t[0] == 'f'}
            if any(d8 in insp.get((p.get('state', ''), k), ()) for k in keys):
                return True
        return False

    stats, unmatched = collections.Counter(), collections.Counter()
    placed = collections.OrderedDict()     # drive id -> {row, targets, url, group, name, programs}
    seen_ids = set()
    review = []
    for row in rows:
        did, url = sciad_drive_url(row.get('url'))
        if not did:
            continue
        if did in seen_ids:
            # The same Drive file filed twice: add the other collection's records to the first.
            if did in placed:
                for pk in row['programs']:
                    if pk not in placed[did]['row']['programs']:
                        placed[did]['row']['programs'].append(pk)
            stats['the same Drive file filed twice'] += 1
            continue
        seen_ids.add(did)
        k = file_key(row['title'])
        if len(k) >= 12 and k in uns_names:
            stats['held: Unsilenced has a file of the same name'] += 1
            continue
        if len(k) < 12 and k:
            hit = None
            for pk in row['programs']:
                for v in paren_names((programs.get(pk) or {}).get('name', '')):
                    fk = (name_key(v), k)
                    if uns_folder[fk] > used_folder[fk]:
                        hit = fk
                        break
                if hit:
                    break
            if hit:
                used_folder[hit] += 1
                stats['held: Unsilenced has a file of the same short name for the program'] += 1
                continue
        if len(k) >= 12 and kop_names.get(k):
            stats['held: KOP has a copy under the same name'] += 1
            continue
        # What KOP already has goes first, so the private counts are what privacy alone keeps out.
        h = url_hash(url)
        why = 'private: reviewed (privacy.json)' if h in privacy['exclude'] else ''
        if not why and h not in privacy['include']:
            why = sciad_private(row)
            why = 'private: ' + why if why else ''
        if why:
            stats[why] += 1
            continue
        placed[did] = {'row': row, 'url': url}

    lists = {'f': collections.defaultdict(list), 'o': collections.defaultdict(list)}
    for did, d in placed.items():
        row, url = d['row'], d['url']
        targets, by, states, prefix = set(), collections.Counter(), set(), {}
        open_progs = []
        for pk in row['programs']:
            p = programs.get(pk) or {}
            ts = set()
            if pk in SCIAD_RULES:
                ts = set(SCIAD_RULES[pk])
                by['rule'] += 1
            elif p.get('result') == 'facility':
                ts = {'f%d' % i for i in p['facilities']}
            elif p.get('result') == 'operator':
                ts = {'o%d' % i for i in p['operators']}
            elif p.get('result') == 'unmatched':
                open_progs.append(p)
            for t in ts:
                # On a parent company's page a campus's documents say which campus.
                if t[0] == 'o' and not re.search(r'\b(HQ|Headquarters)$', p.get('name', '')) and p.get('result') != 'operator':
                    prefix[t] = p['name']
            targets |= ts
            if p.get('state'):
                states.add(p['state'])
        how = 'collection'
        if not targets and open_progs and len({p['state'] for p in open_progs}) == 1 and open_progs[0]['state']:
            targets = tag_target(row['key'], open_progs[0]['state'])
            how = 'tags'
        if not targets:
            stats['no KOP record: ' + ('program collection matches none' if row['programs'] else 'not in a program collection')] += 1
            for pk in row['programs']:
                p = programs.get(pk) or {}
                unmatched['%s (%s, %s)' % (p.get('name', pk), p.get('state') or 'non-US', p.get('result', '?'))] += 1
            if not row['programs']:
                unmatched['(%s)' % ', '.join(row['subcats'][:2] or ['no collection'])] += 1
            continue
        if row['cat'] == 'govrecord' and kop_has_report(row, targets):
            stats['held: KOP has the state report for that program and date'] += 1
            continue
        group = sciad_group(row)
        state = next(iter(states)) if len(states) == 1 else ''
        name = sciad_court_title(row, state) if row['cat'] == 'legal' else sciad_title(row, group)
        name = privacy['titles'].get(url_hash(url), name)
        stats['listed'] += 1
        stats['listed, tied by ' + how] += 1
        case = case_of(row['key']) if row['cat'] == 'legal' else None
        for t in sorted(targets):
            lists[t[0]][int(t[1:])].append({'url': url, 'name': name, 'group': group, '_prefix': prefix.get(t, ''),
                                            '_case': case, '_date': sciad_date(row) or '9999'})
        review.append((url_hash(url), row['cat'], group, ' '.join(sorted(targets)), name, row['title'], row.get('date') or ''))

    # A case's label: its number when the collection name holds one, else the years its records span.
    case_years = collections.defaultdict(set)
    for kind in lists:
        for entries in lists[kind].values():
            for e in entries:
                if e['_case'] and e['_date'] != '9999':
                    case_years[e['_case']].add(e['_date'][:4])

    def case_label(case, n):
        """'case 2:03-cv-00123' when the collection name holds a number, else 'case 2 (2002-2004)'."""
        no = next((m.group(1) for rx in CASE_NO_RE for m in [rx.search(cols[case][0])] if m), '')
        if no and sum(ch.isdigit() for ch in no) >= 3:
            return 'case ' + no
        ys = sorted(case_years.get(case, ()))
        span = (ys[0] if ys[0] == ys[-1] else ys[0] + '-' + ys[-1]) if ys else ''
        return 'case %d' % n + (' (%s)' % span if span else '')

    # Each record: groups in SCIAD_GROUP_ORDER (court records by case), dated documents in date
    # order; a title shown twice in a group gets its date, then a number.
    for kind in lists:
        for i, entries in lists[kind].items():
            cases = sorted({e['_case'] for e in entries if e['_case']},
                           key=lambda c: (min(case_years.get(c) or {'9999'}), c))
            labels = {c: case_label(c, n) for n, c in enumerate(cases, 1)}
            for e in entries:
                base = e['group']
                g = base + (': ' + labels[e['_case']] if e['_case'] and len(cases) > 1 else '')
                g = (e['_prefix'] + ': ' + g) if e['_prefix'] else g
                e['group'] = privacy['groups'].get(url_hash(g), g)
                e['_order'] = (e['_prefix'], SCIAD_GROUP_ORDER.index(base), cases.index(e['_case']) if e['_case'] in labels else -1,
                               e['group'], e['_date'], e['name'])
            entries.sort(key=lambda e: e['_order'])
            count = collections.Counter((e['group'], e['name']) for e in entries)
            for e in entries:
                if count[(e['group'], e['name'])] > 1 and e['_date'] != '9999' and e['_date'] not in e['name']:
                    e['name'] = '%s, %s' % (e['name'], e['_date'])
            count = collections.Counter((e['group'], e['name']) for e in entries)
            n = collections.Counter()
            for e in entries:
                for k in ('_order', '_prefix', '_case', '_date'):
                    del e[k]
                key = (e['group'], e['name'])
                if count[key] > 1:
                    n[key] += 1
                    e['name'] = '%s (%d)' % (e['name'], n[key])
    os.makedirs(WORK, exist_ok=True)
    with open(os.path.join(WORK, 'sciad-review.tsv'), 'w', encoding='utf8', newline='\n') as fh:
        fh.write('sha1\tcategory\tgroup\trecords\tshown as\tSCIAD title\tdate\n')
        for r in sorted(review, key=lambda r: (r[1], r[2], r[5])):
            fh.write('\t'.join(re.sub(r'\s', ' ', x) for x in r) + '\n')
    report += ['SCIAD NET: the review list of every listed document (sha1, shown title, SCIAD title) is '
               'tmp/survivor-archives/sciad-review.tsv (gitignored: it holds the original titles).', '']
    return lists, stats, unmatched, len(seen_ids)


def build_crawled(site, meta, privacy, md5s, kop_names, seen_md5):
    """A crawled site (fetch): its links and their md5s -> (lists, stats, unmatched, documents)."""
    links_path = os.path.join(WORK, site, 'links.json')
    files_path = os.path.join(WORK, site, 'files.jsonl')
    if not os.path.exists(links_path) or not os.path.exists(files_path):
        sys.exit('Missing %s; run: python scripts/survivor-archives.py fetch --site %s' % (links_path, site))
    # Each site serves its documents over one scheme (thestraights.net has no https); some pages link the other.
    scheme = meta['url'].split(':')[0]
    https = lambda u: re.sub(r'^https?://', scheme + '://', u)
    links = json.load(open(links_path, encoding='utf8'))
    for l in links:
        l['url'] = https(l['url'])
    hashed = {}
    for line in open(files_path, encoding='utf8'):
        if line.strip():
            r = json.loads(line)
            hashed[https(r['url'])] = r
    site_names = collections.Counter(file_key(file_name(u)) for u in {l['url'] for l in links})

    # One entry per document: the targets of every page linking it.
    docs = collections.OrderedDict()
    for l in links:
        t, how = targets_for(site, l)
        d = docs.setdefault(l['url'], {'link': l, 'targets': set(), 'unruled': True, 'how': set()})
        if t is not None:
            d['unruled'] = False
            d['targets'] |= set(t)
            d['how'].add(how)

    stats = collections.Counter()
    unmatched = collections.Counter()
    lists = {'f': collections.defaultdict(list), 'o': collections.defaultdict(list)}
    for url, d in docs.items():
        l = d['link']
        h = hashed.get(url) or {}
        if not h.get('md5'):
            stats['not downloadable (%s)' % (h.get('status') or 'not fetched')] += 1
            continue
        if h.get('size', 0) < 1024 or 'text/html' in (h.get('type') or ''):
            stats['not a document (error page)'] += 1
            continue
        name_ = display_name(l, site)
        group_ = group_label(site, l)
        why = 'private: reviewed (privacy.json)' if url_hash(url) in privacy['exclude'] else ''
        if not why and url_hash(url) not in privacy['include']:
            why = private_reason(file_name(url), urllib.parse.unquote(urllib.parse.urlsplit(url).path), l['name'], l['section'],
                                wide=(l['page_title'], l['page'], group_))
            why = 'private: ' + why if why else ''
        if why:
            stats[why] += 1
            continue
        if not d['targets']:
            stats['about no program KOP has a record of' if not d['unruled'] else 'no rule ties it to a record'] += 1
            unmatched[group_label(site, l)] += 1
            continue
        if h['md5'] in md5s:
            stats['KOP has the same file'] += 1
            continue
        k = file_key(file_name(url))
        if len(k) >= 12 and kop_names.get(k, 0) >= 1 and site_names[k] <= 2:
            stats['KOP has a copy under the same name'] += 1
            continue
        entry = {'url': url, 'name': privacy['titles'].get(url_hash(url), name_),
                 'group': privacy['groups'].get(url_hash(group_), group_)}
        placed = 0
        for t in sorted(d['targets']):
            # The same bytes uploaded twice, or listed by an earlier site, list once per record.
            if h['md5'] in seen_md5[t]:
                continue
            seen_md5[t].add(h['md5'])
            lists[t[0]][int(t[1:])].append(entry)
            placed += 1
        stats['listed' if placed else 'same file already listed for the record'] += 1
    return lists, stats, unmatched, len(docs)


def cmd_build(args):
    if not os.path.exists(args.db):
        sys.exit('Missing %s (python scripts/sync-prod-sqlite.py).' % args.db)
    db = sqlite3.connect(args.db)
    facility_ids = {r[0] for r in db.execute('SELECT id FROM facilities_v2')}
    operator_ids = {r[0] for r in db.execute('SELECT id FROM wpdl_kop_operators')}
    md5s = set()
    for (h,) in db.execute("SELECT meta_value FROM wpdl_postmeta WHERE meta_key IN ('mdd_hash', '_kop_import_md5')"):
        if h and re.fullmatch(r'[0-9a-fA-F]{32}', h.strip()):
            md5s.add(h.strip().lower())
    kop_names = collections.Counter()
    for title, path in db.execute(
            "SELECT p.post_title, m.meta_value FROM wpdl_posts p JOIN wpdl_postmeta m "
            "ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment'"):
        for k in {file_key(os.path.basename(path or '')), file_key(title or '')}:
            if k:
                kop_names[k] += 1

    # Every target must still be a record: a merged or deleted id is a rule to fix.
    bad = sorted({t for ts in [ts for rules in RULES.values() for _, _, ts in rules] + list(SCIAD_RULES.values()) for t in ts
                  if not ((t[0] == 'f' and int(t[1:]) in facility_ids) or (t[0] == 'o' and int(t[1:]) in operator_ids))})
    if bad:
        sys.exit('RULES name records that no longer exist: %s' % ', '.join(bad))

    report = ['# Survivor site archives build', '', 'Built %s.' % datetime.date.today().isoformat(), '']
    totals = {}
    privacy = load_privacy()
    seen_md5 = collections.defaultdict(set)   # record -> md5s already listed, across sites in SITES order
    for site, meta in SITES.items():
        if site == 'sciad':
            built = build_sciad(db, privacy, kop_names, report)
            if built is None:
                print('sciad: no survey in tmp/sciad/ (items.jsonl, programs.json); its build is left as it is')
                continue
            lists, stats, unmatched, ndocs = built
        else:
            lists, stats, unmatched, ndocs = build_crawled(site, meta, privacy, md5s, kop_names, seen_md5)
        site_out = os.path.join(OUT, site)
        index = {'built': datetime.date.today().isoformat(), 'label': meta['label'], 'url': meta['url'],
                 'facilities': {}, 'operators': {}}
        for kind, key in (('f', 'facilities'), ('o', 'operators')):
            shard_dir = os.path.join(site_out, kind)
            os.makedirs(shard_dir, exist_ok=True)
            for old in os.listdir(shard_dir):
                if old.endswith('.json'):
                    os.remove(os.path.join(shard_dir, old))
            for i, entries in sorted(lists[kind].items()):
                with open(os.path.join(shard_dir, '%d.json' % i), 'w', encoding='utf8', newline='\n') as fh:
                    json.dump({'files': entries}, fh, ensure_ascii=False, separators=(',', ':'))
                index[key][str(i)] = len(entries)
        with open(os.path.join(site_out, 'index.json'), 'w', encoding='utf8', newline='\n') as fh:
            json.dump(index, fh, ensure_ascii=False, separators=(',', ':'))
        totals[site] = (len(index['facilities']), len(index['operators']), stats['listed'])

        names = {}
        for i, n in index['facilities'].items():
            r = db.execute('SELECT name, state FROM facilities_v2 WHERE id = ?', (int(i),)).fetchone()
            names['f' + i] = ('%s (%s)' % (r[0], r[1] or 'non-US'), n)
        for i, n in index['operators'].items():
            r = db.execute('SELECT name FROM wpdl_kop_operators WHERE id = ?', (int(i),)).fetchone()
            names['o' + i] = ('%s (parent company)' % r[0], n)
        report += ['## %s' % meta['label'], '', '%d documents %s.' % (ndocs, 'in its Drive files' if site == 'sciad' else 'linked from its pages'), '',
                   '| Outcome | Documents |', '|---|---:|'] + \
                  ['| %s | %d |' % (k, v) for k, v in stats.most_common()] + \
                  ['', '### Listed on', ''] + \
                  ['- %s: %d' % (label, n) for label, n in sorted(names.values(), key=lambda x: -x[1])] + \
                  ['', '### Not tied to a record', ''] + \
                  ['- %s: %d' % (k, v) for k, v in unmatched.most_common()] + ['']
        print('%s:' % meta['label'])
        for k, v in stats.most_common():
            print('%8d  %s' % (v, k))

    os.makedirs(WORK, exist_ok=True)
    with open(os.path.join(WORK, 'build-report.md'), 'w', encoding='utf8', newline='\n') as fh:
        fh.write('\n'.join(report) + '\n')
    for site, (nf, no, n) in totals.items():
        print('%s: %d documents on %d facility and %d operator pages' % (site, n, nf, no))
    print('-> js/data/survivor-archives/ (report: tmp/survivor-archives/build-report.md)')


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    sub = ap.add_subparsers(dest='cmd', required=True)
    f = sub.add_parser('fetch')
    f.add_argument('--site', choices=sorted(SITES))
    sub.add_parser('privacy-review')
    sub.add_parser('selftest')
    b = sub.add_parser('build')
    b.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    args = ap.parse_args()
    {'fetch': cmd_fetch, 'build': cmd_build, 'privacy-review': cmd_privacy_review, 'selftest': cmd_selftest}[args.cmd](args)


if __name__ == '__main__':
    main()
