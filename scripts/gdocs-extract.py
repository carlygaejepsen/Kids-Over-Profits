"""Links pass over the exported Google Docs and Sheets (docs/PLAN.md 3.9, step 2).

Reads the export made by tmp/gdocs/export.gs (G:\\My Drive\\KOP Doc Export:
<id>.html per Doc, <id>__<gid>.csv per Sheet tab, manifest.json) and, for
every link, keeps:
  - the linked words and the paragraph, list item or table row around them,
    and the nearest heading;
  - the facility it is about: a record name (or past/other name) in that
    paragraph, else in the heading, else in the doc's folder path or title,
    matched with woodbury-scan.py's name index against tmp/prod.sqlite;
  - what kind of link it is (news, court, legislation, government, archive
    copy, social, people, program site, other);
  - whether it is already on file: the same normalized URL (api/url-dedupe.php's
    kop_normalize_url) in news_submissions, lawsuits, legislation, Websites
    Sent In, or a facility record. The review screen checks again live with
    kop_ext_find_duplicates() before anything is added.
The same link in several docs becomes one item with every place it was seen.

Writes tmp/gdocs/links.json and tmp/gdocs/links-report.md.

Usage: python scripts/gdocs-extract.py [--export "G:/My Drive/KOP Doc Export"]
"""
import argparse
import collections
import csv
import importlib.util
import io
import json
import os
import re
import sqlite3
import sys
import urllib.parse

from bs4 import BeautifulSoup

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
TMP = os.path.join(ROOT, 'tmp', 'gdocs')
spec = importlib.util.spec_from_file_location('woodbury_scan', os.path.join(ROOT, 'scripts', 'woodbury-scan.py'))
ws = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ws)

URL_RE = re.compile(r'https?://[^\s<>"\')\]]+', re.I)
TRACKING = re.compile(r'^(utm_[a-z]+|fbclid|gclid|msclkid|igshid|mc_cid|mc_eid|cmpid|smid|ref)$')
# Doc and folder words that are not part of a program's name.
TITLE_NOISE = re.compile(r'\s*(?:\b(?:links?(?: (?:&|and) (?:info|notes))?|notes(?: (?:&|and) links)?|profile|info|'
                         r'summary of info|cheat sheet|company profile)\b|\[in progress\]|\(\d+\)|copy of)\s*', re.I)

COURT = re.compile(r'courtlistener\.com|law\.justia\.com|dockets\.justia\.com|casetext\.com|pacermonitor\.com|'
                   r'uscourts\.gov|govinfo\.gov/app/details/USCOURTS|courts?\.[a-z]{2}\.gov|\.courts\.|'
                   r'unicourt\.com|trellis\.law|leagle\.com|casemine\.com|scholar\.google\.com/scholar_case|'
                   r'classaction\.org|topclassactions\.com|docketalarm\.com|clearinghouse\.net|'
                   r'clearinghouse-umich', re.I)
# A legislature's site holds fiscal notes, hearings and reports too: only a bill is legislation.
BILL = re.compile(r'legiscan\.com|congress\.gov/bill|govtrack\.us/congress/bills|openstates\.org|/bills?/|billtext|'
                  r'bill_?(?:status|info|history|number)|[?&](?:bill|billnumber|billid)=|[/_-](?:h|s|a)b[_-]?\d+|'
                  r'[/_-](?:hf|sf|hb|sb|ab|ld|lb)\d+', re.I)
# State licensing and inspection report hosts (the scrapers' sources).
INSPECTION = re.compile(r'ccld\.dss\.ca\.gov|ccl\.utah\.gov|licensing\.utah\.gov|hslicensing\.utah\.gov|'
                        r'djj\.state\.fl\.us|myflfamilies\.com|apps\.hhs\.texas\.gov|childcare\.az\.gov|'
                        r'ncdhhs\.gov|dhhs\.nc\.gov|dcyf\.wa\.gov|oregon\.gov/odhs|licensing\.az|ccld|'
                        r'/inspection|facilityreports', re.I)
LEGISLATION = re.compile(r'legislature|legis\.|legiscan\.com|congress\.gov|govtrack\.us|openstates\.org|'
                         r'le\.utah\.gov|malegislature\.gov|capitol\.|leg\.[a-z.]+\.(?:gov|us)|'
                         r'senate\.gov|house\.gov|/bills?/', re.I)
GOVERNMENT = re.compile(r'\.gov(?:/|$)|\.state\.[a-z]{2}\.us|\.mil(?:/|$)|\.gc\.ca|\.gov\.[a-z]{2}', re.I)
ARCHIVE = re.compile(r'^(?:archive\.(?:ph|is|vn|md|today|li)|archive\.org|ghostarchive\.org|webcitation\.org)$')
SOCIAL = {'reddit.com', 'facebook.com', 'youtube.com', 'youtu.be', 'instagram.com', 'twitter.com', 'x.com',
          'tiktok.com', 'fornits.com', 'threads.net', 'medium.com', 'substack.com', 'tumblr.com'}
PEOPLE = {'linkedin.com', 'indeed.com', 'glassdoor.com', 'findagrave.com', 'legacy.com', 'zoominfo.com',
          'opencorporates.com', 'bizapedia.com', 'rocketreach.co'}
REFERENCE = {'en.wikipedia.org', 'wikipedia.org', 'strugglingteens.com', 'unsilenced.org', 'splcenter.org',
             'disabilityrightsohio.org', 'ndrn.org', 'autisticadvocacy.org', 'breakingcodesilence.org',
             'documentcloud.org', 'change.org', 'crunchbase.com', 'prweb.com', 'yelp.com'}
# Never a program's own site, even when a record's links name it.
NOT_PROGRAM_SITE = re.compile(r'wordpress\.com|blogspot\.|wixsite\.|weebly\.|squarespace\.|google\.|yelp\.|'
                              r'patch\.com|psychologytoday\.|guidestar\.|charitynavigator\.|natsap\.org', re.I)
# Local TV and radio call signs: wral.com, kutv.com, cleveland19.com is caught by the word list.
CALL_SIGN = re.compile(r'^[kw][a-z]{2,3}(?:tv|fm|am|\d{1,2})?\.(?:com|org|net)$')
INTERNAL = {'drive.google.com', 'docs.google.com', 'g.co', 'goo.gl', 'kidsoverprofits.org', 'notebooklm.google.com'}
CATEGORY_LABEL = {
    'news': 'News article', 'court': 'Court record', 'inspection': 'Licensing/inspection report', 'legislation': 'Legislation', 'government': 'Government page',
    'archive': 'Archive copy', 'social': 'Survivor/social post', 'people': 'Person (profile, obituary, company)',
    'reference': 'Reference', 'program_site': "Program's own site", 'internal': 'KOP, Drive or search page', 'other': 'Other',
}


def normalize_url(url):
    """Python copy of kop_normalize_url() (api/url-dedupe.php), fragment dropped."""
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


def unwrap(href):
    """Google Docs wraps every link in google.com/url?q=..."""
    href = (href or '').strip()
    p = urllib.parse.urlparse(href)
    if p.netloc.endswith('google.com') and p.path == '/url':
        q = urllib.parse.parse_qs(p.query).get('q')
        if q:
            href = q[0]
    return href


def wayback_original(url):
    m = re.match(r'https?://web\.archive\.org/web/[0-9a-z_*]+/(.+)$', url, re.I)
    if not m:
        return ''
    orig = m.group(1)
    return orig if re.match(r'https?://', orig, re.I) else 'http://' + orig


def domain_of(url):
    d = urllib.parse.urlparse(url).netloc.lower().split('@')[-1].split(':')[0]
    d = re.sub(r'^(?:www\d?|m|mobile|amp|old|np)\.', '', d)
    return d


def base_domain(d):
    parts = d.split('.')
    if len(parts) > 2 and len(parts[-1]) == 2 and parts[-2] in ('co', 'com', 'org', 'gov', 'net', 'ac'):
        return '.'.join(parts[-3:])
    return '.'.join(parts[-2:])


def link_label(anchor, row, url):
    """What to call a link: its linked words, else the first plain cell of
    its sheet row, else a short form of the address."""
    a = flat(anchor)
    if len(a) >= 3 and not URL_RE.match(a) and not re.fullmatch(r'[\W\d]+', a):
        return a[:300]
    for cell in (row or '').split(' | '):
        c = flat(cell)
        if len(c) >= 3 and not URL_RE.search(c) and not re.fullmatch(r'[\W\d/:.-]+', c):
            return c[:300]
    p = urllib.parse.urlparse(url)
    tail = urllib.parse.unquote(p.path.rstrip('/').split('/')[-1]) if p.path.strip('/') else ''
    tail = re.sub(r'\.(html?|php|aspx?)$', '', tail).replace('-', ' ').replace('_', ' ')
    host = re.sub(r'^www\.', '', p.netloc)
    return (host + (': ' + tail if tail and not tail.isdigit() else ''))[:300]


def flat(s):
    return re.sub(r'\s+', ' ', (s or '').replace('\u00a0', ' ')).strip()


# --------------------------------------------------------------------------
# What is already on file
# --------------------------------------------------------------------------

def urls_in(text):
    return URL_RE.findall(text or '')


def load_on_file(con):
    """normalized url -> [(type, id, title)]"""
    on = collections.defaultdict(list)

    def add(kind, rid, title, *texts):
        seen = set()
        for t in texts:
            for u in urls_in(t if isinstance(t, str) else ''):
                for cand in (u, wayback_original(u)):
                    n = normalize_url(cand)
                    if n and n not in seen:
                        seen.add(n)
                        on[n].append((kind, rid, (title or '')[:120]))

    for rid, title, url, js in con.execute('SELECT id, article_title, article_url, json_data FROM news_submissions'):
        add('news', rid, title, url, js)
    cols = [c[1] for c in con.execute('PRAGMA table_info(lawsuits)')]
    for row in con.execute('SELECT * FROM lawsuits'):
        d = dict(zip(cols, row))
        add('lawsuit', d['id'], d['case_name'], *[v for v in d.values() if isinstance(v, str)])
    cols = [c[1] for c in con.execute('PRAGMA table_info(legislation)')]
    for row in con.execute('SELECT * FROM legislation'):
        d = dict(zip(cols, row))
        add('legislation', d['id'], d['bill_title'], *[v for v in d.values() if isinstance(v, str)])
    for rid, name, js in con.execute('SELECT id, name, json_data FROM facilities_v2'):
        add('facility', rid, name, js)
    for rid, name, js in con.execute('SELECT id, unique_name, json_data FROM referrers_master'):
        add('referrer', rid, name, js)
    for rid, fid, url in con.execute('SELECT id, facility_id, report_url FROM inspection_reports WHERE report_url IS NOT NULL'):
        add('inspection', rid, 'report for inspection facility #%s' % fid, url)
    # CA reports are stored without a URL; report_id is "<facNum>-<inx>[-hash]", the
    # transparencyapi FacilityReports?facNum=&inx= the docs link to.
    for rid, rep, name in con.execute(
            "SELECT r.id, r.report_id, f.facility_name FROM inspection_reports r "
            "JOIN inspection_facilities f ON f.id = r.facility_id WHERE f.state = 'CA'"):
        mm = re.match(r'^0*(\d+)-(\d+)(?:-|$)', rep or '')
        if mm:
            on['ca-ccl:%s:%s' % (mm.group(1), mm.group(2))].append(('inspection', rid, name or ''))
    for pid, title, url in con.execute(
            "SELECT p.ID, p.post_title, m.meta_value FROM wpdl_posts p JOIN wpdl_postmeta m ON m.post_id = p.ID "
            "WHERE p.post_type = 'kop_source' AND m.meta_key = '_kop_url'"):
        add('website', pid, title, url)
    return on


def load_news_domains(con):
    c = collections.Counter()
    for (url,) in con.execute('SELECT article_url FROM news_submissions'):
        d = domain_of(url or '')
        if d:
            c[base_domain(d)] += 1
    return {d for d in c if d not in SOCIAL and d not in PEOPLE and d not in INTERNAL and not ARCHIVE.match(d)}


def load_program_domains(con, news_domains):
    """Program website domain -> facility ids, from profileLinks and operator websites."""
    out = collections.defaultdict(set)
    for fid, js in con.execute('SELECT id, json_data FROM facilities_v2'):
        try:
            d = json.loads(js)
        except Exception:  # noqa: BLE001
            continue
        links = list(d.get('profileLinks') or []) + list(((d.get('provenance') or {}).get('sourceOperator') or {}).get('websites') or [])
        for u in links:
            if not isinstance(u, str):
                continue
            u = wayback_original(u) or u
            dom = domain_of(u)
            b = base_domain(dom)
            if (dom and not ARCHIVE.match(dom) and b not in SOCIAL | PEOPLE | REFERENCE | INTERNAL
                    and b not in news_domains and not NOT_PROGRAM_SITE.search(dom)
                    and not GOVERNMENT.search('//' + dom + '/') and not INSPECTION.search(u)):
                out[b].add(fid)
    return out


OP_FILLER = {'and', 'of', 'the', 'for', 'inc', 'group', 'healthcare', 'health', 'services', 'companies',
             'corporation', 'behavioral', 'schools', 'programs', 'specialty', 'association'}


def load_operators(con):
    """Operator name key -> operator, plus the short forms a folder uses: the
    first word ("Sequel" for Sequel TSI, "Acadia"), a bracketed acronym
    ("UHS", "WWASPS", "CARE") and initials ("FHW" for Family Help & Wellness)."""
    ops, short = {}, collections.defaultdict(list)
    for oid, name in con.execute('SELECT id, name FROM wpdl_kop_operators'):
        k = ws.key(name or '')
        if len(k) < 3:
            continue
        op = {'id': oid, 'name': name}
        ops[k] = op
        for acro in re.findall(r'\(([A-Za-z]{2,8})\)', name or ''):
            short[acro.lower()].append(op)
            if acro.lower().endswith('s'):
                short[acro.lower()[:-1]].append(op)
        words = re.sub(r'\([^)]*\)', ' ', k).split()
        if words and len(words[0]) >= 5 and words[0] not in GENERIC_WORDS | ws.GENERIC_TAIL | {'center', 'centers'}:
            short[words[0]].append(op)
        initials = ''.join(w[0] for w in words if w not in ('and', 'of', 'the', 'for', 'inc'))
        if len(initials) >= 3:
            short[initials].append(op)
    for k, lst in short.items():
        uniq = {o['id']: o for o in lst}
        if len(uniq) == 1 and k not in ops:
            ops[k] = next(iter(uniq.values()))
    return ops


# --------------------------------------------------------------------------
# Facility for a piece of text
# --------------------------------------------------------------------------

class Matcher:
    def __init__(self, con):
        self.names, self.past = ws.load_facilities(con)
        # A name made only of generic and kind words ("Juvenile Detention Center")
        # names no one program in free text.
        self.index = {k for k in list(self.names) + list(self.past)
                      if k not in ws.GENERIC_NAMES and len(k) >= 6 and ' ' in k
                      and set(ws.core(k).split()) - GENERIC_WORDS}
        self.ops = load_operators(con)
        self.by_id = {}
        for fs in list(self.names.values()) + list(self.past.values()):
            for f in fs:
                self.by_id[f['id']] = f

    def in_text(self, text):
        """Facilities named in free text: [(facility, name key, past?)]."""
        out = []
        text = URL_RE.sub(' ', text or '')  # the words around a link, not the link
        for k in sorted(ws.find_names(ws.key(text), self.index)):
            fs = self.names.get(k) or self.past.get(k) or []
            if len(fs) == 1:
                out.append((fs[0], k, k not in self.names))
            elif fs:
                out.append((fs[0], k, k not in self.names))
        return out

    def title(self, title, state=''):
        """A heading, doc title or folder name that is (mostly) just a program name."""
        name = flat(TITLE_NOISE.sub(' ', title or '')).strip(' -:,&')
        if len(ws.key(name)) < 4 or ws.key(name) in ws.GENERIC_NAMES:
            return None, ''
        k = ws.key(name)
        if k in PLACES or k in self.ops:
            return None, ''
        f, alts, kind, note = ws.match_header(name, state, self.names, self.past)
        if f and kind == 'section':
            return f, 'past name' if 'past name' in note else 'exact'
        found = self.in_text(name)
        if len({x[0]['id'] for x in found}) == 1:
            return found[0][0], 'name in title'
        # Every distinctive word of the title in one record's name and no other's:
        # "Judge Rotenberg Center" -> Judge Rotenberg Educational Center.
        words = set(ws.core(k).split()) - GENERIC_WORDS
        if len(words) >= 2:
            hits = {f['id']: f for nk, fs in self.names.items() if words <= set(nk.split()) for f in fs}
            if len(hits) == 1:
                return next(iter(hits.values())), 'close name'
        return None, ''

    def operator(self, text):
        k = ws.key(TITLE_NOISE.sub(' ', text or ''))
        # Whole names and short forms only: a title that merely starts with one
        # ("The Ridge RTC" vs the company Ridge House) is not that company.
        return self.ops.get(k)


GENERIC_WORDS = {'juvenile', 'detention', 'youth', 'boy', 'girl', 'children', 'child', 'teen', 'adolescent',
                 'county', 'regional', 'state', 'facility', 'shelter', 'camp', 'ranch', 'house', 'village', 'care',
                 'mental', 'health', 'behavioral', 'hospital', 'psychiatric', 'correctional', 'development', 'family',
                 'christian', 'military', 'boot', 'girls', 'boys', 'new', 'hope', 'life', 'living', 'recovery',
                 'counseling', 'service', 'east', 'west', 'north', 'south', 'central', 'valley'}
SEARCH_PAGE = re.compile(r'linkedin\.com/search/|google\.[a-z.]+/search|facebook\.com/search|bing\.com/search|'
                         r'duckduckgo\.com/\?|/search\?q=|youtube\.com/results', re.I)
WEBSITE_COLUMN = re.compile(r'^\s*(?:web ?site|website url|url|homepage|home page|site)s?\s*$', re.I)
STATE_FOLDERS = {v.lower(): k for k, v in ws.STATES.items()}
PLACES = {ws.key(v) for v in ws.STATES.values()} | {'israel', 'canada', 'mexico', 'jamaica', 'costa rica', 'samoa',
                                                     'massachusettes', 'new england', 'mid atlantic', 'midwest',
                                                     'pacific', 'south atlantic', 'south central', 'west',
                                                     'international', 'utah ccl', 'ohio policy recommendations'}


def path_state(path):
    for seg in path.split('/'):
        st = STATE_FOLDERS.get(seg.strip().lower().replace('massachusettes', 'massachusetts'))
        if st:
            return st
    return ''


# --------------------------------------------------------------------------
# Reading the export
# --------------------------------------------------------------------------

BLOCKS = ('p', 'li', 'td', 'th', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6')


def doc_links(html):
    """[(anchor, href, block text, heading)] in document order."""
    soup = BeautifulSoup(html, 'html.parser')
    body = soup.body or soup
    out = []
    heading = ''
    for el in body.find_all(BLOCKS):
        if el.find(BLOCKS):
            continue  # a table cell holding paragraphs: read the paragraphs
        text = flat(el.get_text(' '))
        if el.name in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'):
            if text:
                heading = text
        block = text
        tr = el.find_parent('tr')
        if tr is not None and el.name in ('td', 'th', 'p'):
            block = flat(' | '.join(c.get_text(' ') for c in tr.find_all(['td', 'th'])))
        seen = set()
        for a in el.find_all('a', href=True):
            href = unwrap(a['href'])
            if not re.match(r'https?://', href, re.I) or href in seen:
                continue
            seen.add(href)
            out.append((flat(a.get_text(' ')), href, block, heading if el.name[0] != 'h' else ''))
        for u in URL_RE.findall(text):
            u = u.rstrip('.,;:')
            if u not in seen:
                seen.add(u)
                out.append(('', u, block, heading))
        if el.name in ('h1', 'h2', 'h3', 'h4', 'h5', 'h6'):
            continue
        # A short bold line acts as a heading in many of these docs.
        if text and len(text) < 80 and not el.find('a') and el.find(['b', 'strong']) is not None:
            heading = text
    return out


def sheet_links(raw):
    """[(anchor, url, row text, column header)]"""
    out = []
    rows = list(csv.reader(io.StringIO(raw)))
    header = rows[0] if rows else []
    for r in rows[1:] if len(rows) > 1 else rows:
        row_text = flat(' | '.join(c for c in r if c.strip()))
        for i, cell in enumerate(r):
            for u in URL_RE.findall(cell):
                out.append(('', u.rstrip('.,;:'), row_text, header[i] if i < len(header) else ''))
    return out


# --------------------------------------------------------------------------
# Main
# --------------------------------------------------------------------------

def classify(url, dom, news_domains, program_domains):
    b = base_domain(dom)
    if dom in INTERNAL or b in INTERNAL or SEARCH_PAGE.search(url):
        return 'internal'
    if ARCHIVE.match(dom) or dom == 'web.archive.org':
        return 'archive'
    if INSPECTION.search(url):
        return 'inspection'
    if COURT.search(url):
        return 'court'
    if LEGISLATION.search(url):
        return 'legislation' if BILL.search(url) else 'government'
    if b in SOCIAL or dom in SOCIAL:
        return 'social'
    if b in PEOPLE or dom in PEOPLE:
        return 'people'
    if dom in REFERENCE or b in REFERENCE:
        return 'reference'
    if GOVERNMENT.search('//' + dom + '/'):
        return 'government'
    if b in program_domains:
        return 'program_site'
    if b in news_domains or CALL_SIGN.match(b) or re.search(r'news|times|post|herald|tribune|gazette|journal|daily|press|courier|'
                                       r'observer|chronicle|register|globe|sentinel|inquirer|(?<!guide)star|telegraph|'
                                       r'radio|tv|npr|pbs|abc|cbs|nbc|fox|kare|kutv|ksl|wral|wbur|vice|vox|'
                                       r'magazine|mag\b|insider|atlantic|guardian|independent|reuters|apnews|'
                                       r'bbc|cnn|wsj|bloomberg|huffpost|huffingtonpost|slate|salon|rollingstone|'
                                       r'teenvogue|motherjones|theappeal|thecity|patch|banner|apmreports|cleveland|missoulian|chron\.com|ocala|eagle|edge|'
                                       r'midland|edweek|refinery29|pulitzer|abcnews|investigate|jjie|kmaland|'
                                       r'wgbh|kuer|wpln|wbur|kqed|opb|current|ledger|bee\.com|dispatch|enquirer|'
                                       r'statesman|republic|citizen|examiner|beacon|monitor|bulletin|mercury', b):
        return 'news'
    return 'other'


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--export', default='G:/My Drive/KOP Doc Export')
    ap.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    args = ap.parse_args()

    with open(os.path.join(args.export, 'manifest.json'), encoding='utf-8') as fh:
        manifest = json.load(fh)
    con = sqlite3.connect(args.db)
    on_file = load_on_file(con)
    news_domains = load_news_domains(con)
    program_domains = load_program_domains(con, news_domains)
    m = Matcher(con)
    print('%d URLs on file, %d news domains, %d program site domains' % (len(on_file), len(news_domains), len(program_domains)))

    items = {}
    per_doc = []
    failed = []
    for doc_id, entry in manifest.items():
        if not entry.get('ok'):
            failed.append(entry)
            continue
        path = entry['path']
        state = path_state(path)
        # Facility from the doc's folder path and title, nearest folder first.
        segs = path.replace('My Drive/Kids Over Profits/', '').replace('My Drive/', '').split('/')
        doc_fac, doc_how, doc_op = None, '', None
        for seg in reversed(segs):
            # A program name first ("Ridge RTC Maine" is The Ridge Maine, not the
            # company Ridge House); else a company folder ("Sequel", "Three Springs
            # Company Profile") names the operator.
            if not doc_fac and not doc_op:
                f, kind = m.title(seg, state)
                if f:
                    doc_fac = f
                    doc_how = ('folder' if seg != segs[-1] else 'doc title') + (' (close name)' if kind == 'close name' else '')
                    continue
            doc_op = doc_op or m.operator(seg)

        found = []
        if entry['kind'] == 'doc':
            with open(os.path.join(args.export, doc_id + '.html'), encoding='utf-8') as fh:
                for anchor, href, block, heading in doc_links(fh.read()):
                    found.append((anchor, href, block, heading, ''))
        else:
            for tab in entry.get('tabs') or []:
                with open(os.path.join(args.export, tab['file']), encoding='utf-8', errors='replace') as fh:
                    for anchor, href, row, col in sheet_links(fh.read()):
                        found.append((anchor, href, row, col, tab['name']))

        # A list of programs (a sheet, or a doc naming many records) does not hand
        # its folder's program to every link; only a doc about that program does.
        if doc_fac:
            named_in_doc = collections.Counter()
            for anchor, href, block, heading, tab in found:
                for f, _k, _p in m.in_text(anchor + ' ' + block[:1500]):
                    named_in_doc[f['id']] += 1
            top = [fid for fid, _n in named_in_doc.most_common(2)]
            if entry['kind'] != 'doc' or (len(named_in_doc) > 3 and doc_fac['id'] not in top):
                doc_fac, doc_how = None, ''

        heading_cache = {}
        n_links = 0
        for anchor, href, block, heading, tab in found:
            url = href.strip()
            orig = wayback_original(url)
            dom = domain_of(orig or url)
            norm = normalize_url(url)
            if not norm:
                continue
            n_links += 1
            cat = classify(orig or url, dom, news_domains, program_domains)
            # A sheet's "Website" column holds the program's own site.
            if cat == 'other' and tab and WEBSITE_COLUMN.search(heading or ''):
                cat = 'program_site'
            if orig and cat not in ('internal',):
                cat_note = 'archive of ' + CATEGORY_LABEL.get(cat, cat).lower()
            else:
                cat_note = ''

            # Facility: the paragraph, then the heading, then the doc.
            context = block if len(block) <= 1500 else (anchor or block[:1500])
            named = m.in_text(anchor + ' ' + context)
            ids = list(dict.fromkeys(x[0]['id'] for x in named))
            fac, how = None, ''
            if len(ids) == 1:
                fac, how = named[0][0], 'past name in text' if named[0][2] else 'name in text'
            if not fac and heading and not tab:  # a sheet's "heading" is its column name
                if heading not in heading_cache:
                    heading_cache[heading] = m.title(heading, state)
                f, kind = heading_cache[heading]
                if f:
                    fac, how = f, 'heading' + (' (close name)' if kind == 'close name' else '')
            if not fac and doc_fac:
                fac, how = doc_fac, doc_how
            if not fac and cat == 'program_site':
                cands = program_domains.get(base_domain(dom)) or set()
                if len(cands) == 1:
                    fac, how = m.by_id.get(next(iter(cands))), 'website on record'
            others = [x[0] for x in named if not fac or x[0]['id'] != fac['id']]

            hit = on_file.get(norm, []) + (on_file.get(normalize_url(orig), []) if orig else [])
            ca = re.search(r'ccld\.dss\.ca\.gov/.*facNum=0*(\d+).*?[?&]inx=(\d+)', url, re.I)
            if ca:
                hit = hit + on_file.get('ca-ccl:%s:%s' % ca.groups(), [])
            seen_at = {
                'doc': entry.get('title') or path.split('/')[-1], 'path': path, 'doc_id': doc_id, 'tab': tab,
                'heading': heading[:200], 'anchor': anchor[:300], 'text': context[:700],
            }
            it = items.get(norm)
            if not it:
                it = items[norm] = {
                    'key': norm, 'url': url, 'original': orig, 'domain': dom, 'category': cat,
                    'category_note': cat_note, 'facility': None, 'facility_how': '', 'also_named': [],
                    'operator': None, 'on_file': [], 'seen': [],
                }
            it['seen'].append(seen_at)
            if not it.get('label'):
                it['label'] = link_label(anchor, block if tab else '', orig or url)
                if cat == 'inspection' and tab:
                    it['label'] = 'Licensing report'
            if fac and not it['facility']:
                it['facility'] = {'id': fac['id'], 'name': fac['name'], 'state': fac['state']}
                it['facility_how'] = how
            for o in others:
                if all(a['id'] != o['id'] for a in it['also_named']) and (not it['facility'] or it['facility']['id'] != o['id']):
                    it['also_named'].append({'id': o['id'], 'name': o['name'], 'state': o['state']})
            if doc_op and not it['operator']:
                it['operator'] = doc_op
            for kind, rid, title in hit:
                if all(not (x['type'] == kind and x['id'] == rid) for x in it['on_file']):
                    it['on_file'].append({'type': kind, 'id': rid, 'title': title})
        per_doc.append({'path': path, 'kind': entry['kind'], 'links': n_links,
                        'facility': doc_fac['name'] if doc_fac else '', 'operator': doc_op['name'] if doc_op else ''})

    out = sorted(items.values(), key=lambda i: (i['facility']['name'] if i['facility'] else '~', i['category'], i['url']))
    os.makedirs(TMP, exist_ok=True)
    with open(os.path.join(TMP, 'links.json'), 'w', encoding='utf-8') as fh:
        json.dump(out, fh, indent=1, ensure_ascii=False)
    write_report(out, per_doc, failed)


def write_report(items, per_doc, failed):
    new = [i for i in items if not i['on_file'] and i['category'] != 'internal']
    lines = ['# Google Docs links pass', '',
             '%d distinct links from %d files (%d failed to export).' % (len(items), len(per_doc), len(failed)), '']
    lines += ['| Kind | Links | Already on file | New | New, matched to a facility |', '|---|---:|---:|---:|---:|']
    by = collections.defaultdict(list)
    for i in items:
        by[i['category']].append(i)
    for cat in CATEGORY_LABEL:
        g = by.get(cat) or []
        if not g:
            continue
        n_new = [i for i in g if not i['on_file']]
        lines.append('| %s | %d | %d | %d | %d |' % (CATEGORY_LABEL[cat], len(g), len(g) - len(n_new), len(n_new),
                                                  sum(1 for i in n_new if i['facility'])))
    lines += ['', '## How new links were matched to a facility', '']
    hows = collections.Counter(i['facility_how'] or 'no facility' for i in new)
    for h, n in hows.most_common():
        lines.append('- %s: %d' % (h, n))
    lines += ['', '## Facilities with the most new links', '']
    fc = collections.Counter('%s (%s, #%d)' % (i['facility']['name'], i['facility']['state'], i['facility']['id'])
                             for i in new if i['facility'])
    for f, n in fc.most_common(40):
        lines.append('- %s: %d' % (f, n))
    lines += ['', '## Per file', '', '| File | Kind | Links | Facility from path | Operator |', '|---|---|---:|---|---|']
    for d in sorted(per_doc, key=lambda d: -d['links']):
        lines.append('| %s | %s | %d | %s | %s |' % (d['path'].replace('|', '/'), d['kind'], d['links'], d['facility'], d['operator']))
    if failed:
        lines += ['', '## Not exported', '']
        for e in failed:
            lines.append('- %s: %s' % (e['path'], (e.get('error') or '')[:120]))
    with open(os.path.join(TMP, 'links-report.md'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(lines) + '\n')
    print('\n'.join(lines[:40]))


if __name__ == '__main__':
    sys.exit(main())
