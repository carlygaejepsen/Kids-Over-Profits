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
    """js/data/survivor-archives/privacy.json: {exclude: [sha1 of url], titles:
    {sha1 of url: redacted title}, groups: {sha1 of the group label: redacted
    label}}. Keys are hashes: the repo is public and holds no private name."""
    path = os.path.join(OUT, 'privacy.json')
    p = {}
    if os.path.exists(path):
        p = json.load(open(path, encoding='utf8'))
    return {'exclude': set(p.get('exclude') or []), 'titles': dict(p.get('titles') or {}), 'groups': dict(p.get('groups') or {})}


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
        keys = list(raw.get('exclude') or []) + list((raw.get('titles') or {})) + list((raw.get('groups') or {}))
        assert all(re.fullmatch(r'[0-9a-f]{40}', k) for k in keys), 'privacy.json keys must be sha1 hex'
    # Cross-site dedupe: the same bytes for one record list once, whichever site comes first.
    seen = collections.defaultdict(set)
    first = [t for t in ('f1', 'f2') if 'm' not in seen[t] and not seen[t].add('m')]
    second = [t for t in ('f2', 'f3') if 'm' not in seen[t] and not seen[t].add('m')]
    assert first == ['f1', 'f2'] and second == ['f3']
    print('selftest passed: %d private and %d kept samples' % (len(must), len(keep)))


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
    bad = sorted({t for rules in RULES.values() for _, _, ts in rules for t in ts
                  if not ((t[0] == 'f' and int(t[1:]) in facility_ids) or (t[0] == 'o' and int(t[1:]) in operator_ids))})
    if bad:
        sys.exit('RULES name records that no longer exist: %s' % ', '.join(bad))

    report = ['# Survivor site archives build', '', 'Built %s.' % datetime.date.today().isoformat(), '']
    totals = {}
    privacy = load_privacy()
    seen_md5 = collections.defaultdict(set)   # record -> md5s already listed, across sites in SITES order
    for site, meta in SITES.items():
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
            if not why:
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
        report += ['## %s' % meta['label'], '', '%d documents linked from its pages.' % len(docs), '',
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
