"""
SCIAD NET's links that are not Drive documents (news, court records, program
info, archived program sites, media), each tied to its facility or company,
offered at KOP Tools > Drive Docs beside the Google Docs and HEAL links
(docs/PLAN.md 3.10 step 4, part B).

    python scripts/sciad-links.py [--out tmp/sciad/sciad-links.json]

Inputs (tmp/sciad/, gitignored; the survey built them, see tmp/sciad/survey.md):
  4552235/, 772277/  the Zotero pages of SCIAD NET (WWASP Survivor Truth's
                     archive) and of the WWASP Survivors group
  items.jsonl        one row per SCIAD NET item: category, privacy class,
                     program collections, record targets, duplicate flags
  programs.json      each program collection -> facility / operator / none
and tmp/prod.sqlite, tmp/gdocs/links.json, tmp/heal/heal-links.json, tmp/wiki/wiki-links.json.

Rules (owner decisions 2026-10-01, PLAN.md Waiting on the owner item 18):
  - Drive files are left to the survivor-archives `sciad` site (part A).
  - Private classes stay out: survivor stories, photos and event media, police
    records, juvenile/custody files, intake/medical/school records, yearbooks,
    letters, emails, forum and social posts, personal blogs, obituaries,
    testimony videos and podcasts. Nothing from an item's abstract, tags,
    creators or Zotero user is written.
  - Court records get a neutral title (document type, page, record, year, case
    number or reporter citation); a party, parent, witness or minor name never
    reaches the file. A court record whose address carries a party's name, and
    a family case (the same surname on both sides), is left out.
  - A record only from an exact program-collection match (programs.json);
    ambiguous, near and other-state collections carry no record.
  - Anything KOP holds is dropped: the same address (Wayback unwrapped) in the
    news, lawsuits, legislation, facility, referrer, inspection or Websites
    Sent In records, a published post, the Google Docs, HEAL or wiki links files, or
    a news headline already in news_submissions.
  - News goes in under its original address where the Wayback link gives it
    (the archived copy rides along for the reviewer and the queue note).

The output names private documents' collections, so it is never committed:
copy it to ~/kop-import/gdocs/sciad-links.json on the server. Nothing reaches a
record or a queue until it is accepted on the Drive Docs screen.
"""

import argparse
import collections
import html
import importlib.util
import json
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SCIAD = os.path.join(ROOT, 'tmp', 'sciad')
sys.path.insert(0, SCIAD)
import common as sc  # noqa: E402  (tmp/sciad/common.py: loaders, unwrap, drive_id)

spec = importlib.util.spec_from_file_location('gdocs_extract', os.path.join(ROOT, 'scripts', 'gdocs-extract.py'))
gx = importlib.util.module_from_spec(spec)
spec.loader.exec_module(gx)

CREDIT = 'SCIAD NET, the WWASP Survivor Truth archive'
CREDIT_URL = 'https://wwaspsurvivorstruth.com/program-archive/'

ARCHIVE_TODAY = re.compile(r'^https?://archive\.(?:ph|today|is|li|vn|fo|md)/', re.I)
# Sub-collections whose items are private by what they are (beyond the survey's classes).
PRIVATE_FOLDER = re.compile(r'letter|e-?mail|survivor|account|statement|\bkids?\b|police|obituar|support group|photo|'
                            r'picture|yearbook|program records|testimon|journal|diary|intake|medical|'
                            r'school record|hacked|event media|parent|\bstor(?:y|ies)\b', re.I)
PRIVATE_TITLE = re.compile(r'\b(yearbook|intake|admission|medical record|school record|transcript of grades|report card|'
                           r'my (story|son|daughter|time|experience|life|journey)|letters? (to|from)|open letter|home letter|'
                           r'family letter|journal entry|diary|diaries|testimon|survivor|obituar|memorial|in memory|tribute|'
                           r'birth certificate|discharge summary|treatment plan|progress note|incident report|selfie|'
                           r'ask me anything|story of)'
                           # A private person's statement (a parent's to Congress); a state's statement of deficiencies is not one.
                           r"|\w['\u2019\ufffd]s statement|\bstatement (to|by)\b|\bstatement of (?!deficienc)", re.I)
# A first-person title (a blog post or forum post, not a headline).
FIRST_PERSON = re.compile(r"\bI('m| am| was| spent| went| survived| lived| attended)\b|\bAMA\b|\bmy\b|\bme\b", re.I)
# Pages of survivor-run or personal sites (their own stories), never a news outlet.
PERSONAL_HOST = re.compile(r'survivor|diaries|horrors|victim|truth|exposed|stories|memorial|unite|alumni|blog|journey', re.I)
# (files.wordpress.com is a site's file store, scanned records, not a post.)
BLOG_HOST = re.compile(r'(^|\.)((?<!\.files\.)wordpress\.com|blogspot\.[a-z.]+|medium\.com|tumblr\.com|livejournal\.com|wixsite\.com|'
                       r'weebly\.com|substack\.com|typepad\.com|tripod\.com|angelfire\.com|geocities\.\w+|netfirms\.com|'
                       r'legacy\.com|tributes\.com|findagrave\.com|facebook\.com|instagram\.com|tiktok\.com|twitter\.com|'
                       r'x\.com|reddit\.com|redd\.it|fornits\.com|change\.org|gofundme\.com)$', re.I)
VIDEO_HOST = re.compile(r'(^|\.)(youtube\.com|youtu\.be|vimeo\.com|spotify\.com|podcasts\.apple\.com|anchor\.fm|'
                        r'stitcher\.com|blogtalkradio\.com|soundcloud\.com|podbean\.com|buzzsprout\.com|rumble\.com|'
                        r'dailymotion\.com)$', re.I)
# A video or podcast that is a person telling their own story (testimony) stays out.
PERSONAL_MEDIA = re.compile(r'survivor|\bstory\b|stories|\bmy\b|\bi\b|\bme\b|interview|conversation|testimon|experience|'
                            r'journey|\btells\b|\btalks\b|\bspeaks\b|\bwith\b|#|vlog|q ?& ?a|\blive\b|deposition|family|'
                            r'\bpart \d+ of \d+\b|reunion|memories|remember', re.I)
PIRACY = re.compile(r'(^|\.)(sci-hub\.[a-z]+|libgen\.[a-z]+|z-lib\.[a-z]+)$', re.I)

FAMILY_OR_JUVENILE = re.compile(r'\bin re\b|\bin the (matter|interest) of\b|\bminor\b|\bjuvenile\b|\bguardian(ship)?\b|'
                                r'\bcustody\b|\bdependency\b|\bdivorce\b|\badoption\b|\bestate of\b', re.I)
COURT_TYPES = [
    (r'amicus', 'Amicus brief'), (r'amended complaint', 'Amended complaint'), (r'complaint', 'Complaint'),
    (r'indictment', 'Indictment'), (r'deposition|depo\b', 'Deposition'), (r'affidavit', 'Affidavit'),
    (r'declaration', 'Declaration'), (r'motion to dismiss', 'Motion to dismiss'), (r'motion to compel', 'Motion to compel'),
    (r'summary judg', 'Motion for summary judgment'), (r'motion', 'Motion'),
    (r'request (for|of) (the )?production|interrogator', 'Discovery request'), (r'\breply\b|\bresponse\b', 'Reply'),
    (r'memorandum', 'Memorandum'), (r'\border\b', 'Order'), (r'opinion', 'Opinion'), (r'judg(e)?ment', 'Judgment'),
    (r'verdict', 'Verdict'), (r'settlement|consent decree', 'Settlement'), (r'petition', 'Petition'),
    (r'\bbrief\b', 'Brief'), (r'transcript', 'Transcript'), (r'docket', 'Docket'), (r'appeal', 'Appeal'),
    (r'decision|ruling', 'Decision'), (r'\bplea\b', 'Plea'), (r'sentenc', 'Sentencing'), (r'subpoena', 'Subpoena'),
    (r'exhibit', 'Exhibit'), (r'lawsuit|\bsuit\b', 'Lawsuit filing'),
]
CRIMINAL = re.compile(r'^\s*(the )?(state|people|commonwealth|united states|usa|u\.s\.|us|government)( of [a-z ]+)?\s+(v\.?|vs\.?)\s', re.I)
CASE_NO = [
    re.compile(r'\b(\d{1,2}:\d{2,4}-?(?:cv|cr|mc|md|ap)-?\d{2,6})\b', re.I),
    re.compile(r'\b(\d{2,4}-(?:cv|cr|ca|ci)-\d{2,6})\b', re.I),
    re.compile(r'\b((?:cv|cr)\d{2}[- ]?\d{3,6})\b', re.I),
]
CITATION = re.compile(r'\b(\d{1,4} (?:F\.(?: ?Supp\.)?(?: ?[23]d)?|F\.[34]th|P\.[23]d|U\.S\.|S\. ?Ct\.|So\. ?[23]d|'
                      r'N\.[EW]\.[23]d|A\.[23]d|S\.[EW]\.[23]d|Cal\. ?Rptr\.(?: ?[23]d)?) \d{1,5})')
# Words in a case name that are not a person's name.
INSTITUTION_WORDS = set('''
academy academies school schools ranch ranches center centers centre inc llc ltd corp co company companies group groups
education educational healthcare health hospital hospitals state states county district department dept united america
american behavioral services service youth village villages home homes boys girls town foundation society institute
association associates church ministries ministry christian baptist services insurance mutual program programs
wilderness treatment residential recovery camp camps care systems system medical medicine university college board
city commonwealth people government usa department office trust bank partners partnership holdings enterprises
national international the and of for et al v vs re matter interest supreme superior court appeals commission
agency authority public private charter family families children child childrens kids teen teens hope new life
house ridge mountain valley lake river canyon springs spring creek north south east west international inc. llc.
'''.split())


def flat(s):
    return re.sub(r'\s+', ' ', html.unescape(s or '').replace(chr(0xa0), " ")).strip()


def unwrap(url):
    """The original address inside a Wayback or archive.today link (common.unwrap), with a
    scheme the Wayback Machine squashed ('https:/host') put back."""
    u = sc.unwrap(url)
    if u:
        u = re.sub(r'^http://(?=https?:/)', '', u)
        u = re.sub(r'^(https?):/+(?=[^/])', r'\1://', u)
    return u


def strip_fragment(url):
    """archive.today links carry '#selection-...' highlights; drop every fragment."""
    return re.sub(r'#.*$', '', (url or '').strip())


MONTHS = {m: i + 1 for i, m in enumerate(['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'])}


def full_date(s):
    """YYYY-MM-DD when the date names a day, else '' (the news queue would read '1998' as a time)."""
    s = (s or '').strip()
    m = re.match(r'^(\d{4})-(\d{2})-(\d{2})', s)
    if m:
        return '%s-%s-%s' % m.groups()
    m = re.search(r'\b([A-Za-z]{3})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{4})\b', s)
    if m and m.group(1).lower() in MONTHS:
        return '%s-%02d-%02d' % (m.group(3), MONTHS[m.group(1).lower()], int(m.group(2)))
    m = re.match(r'^(\d{1,2})/(\d{1,2})/(\d{4})$', s)
    if m:
        return '%s-%02d-%02d' % (m.group(3), int(m.group(1)), int(m.group(2)))
    return ''


def year_of(*texts):
    for t in texts:
        m = re.search(r'\b(1[89]\d\d|20\d\d)\b', t or '')
        if m:
            return m.group(1)
    return ''


def party_tokens(title, program_name):
    """Capitalised words in a case title that may be a private person's name."""
    prog = set(re.findall(r'[a-z]+', (program_name or '').lower()))
    out = set()
    for w in re.findall(r"[A-Za-z][A-Za-z'\-]{3,}", title or ''):
        lw = w.lower().strip("'-")
        if lw in INSTITUTION_WORDS or lw in prog or len(lw) < 4:
            continue
        if w[0].isupper():
            out.add(lw)
    return out


NOT_A_NAME = set('''
straight cedu wwasp wwasps synanon elan provo hyde aspen sequel acadia devereux kids bethel hephzibah teen challenge
report news update video part episode school academy ranch center program camp boot inc llc the a an of and in at on
for to from by is are was were be how why what who when where this that these those new old about after before over
'''.split()) | INSTITUTION_WORDS


def name_title(title):
    """A title that is only a person's name ("Jane Q Doe", "John Smith - 2003", "The Doe Family")."""
    t = re.sub(r'\([^)]*\)|\[[^\]]*\]', ' ', title or '')
    t = re.sub(r'[-|,:]\s*(?:\d{4}|\d{1,2}/\d{1,2}/\d{2,4})\s*$', ' ', t)
    t = re.sub(r'\b(?:1[89]|20)\d\d\b', ' ', t)
    if re.search(r'\b[Tt]he [A-Z][a-z]+ [Ff]amily\b', t):
        return True
    words = re.findall(r"[A-Za-z][A-Za-z.'\-]*", t)
    if not 2 <= len(words) <= 4:
        return False
    for w in words:
        lw = w.lower().strip(".'-")
        if lw in NOT_A_NAME or not (w[0].isupper()):
            return False
        if lw in ('jr', 'sr', 'ii', 'iii'):
            continue
    return bool(re.fullmatch(r"[\sA-Za-z.'\-]+", t.strip()))


def family_case(title):
    m = re.split(r'\s+(?:v\.?|vs\.?)\s+', title or '', maxsplit=1, flags=re.I)
    if len(m) != 2:
        return False
    a = {w.lower() for w in re.findall(r'[A-Za-z]{3,}', m[0])} - INSTITUTION_WORDS
    b = {w.lower() for w in re.findall(r'[A-Za-z]{3,}', m[1])} - INSTITUTION_WORDS
    return bool(a & b)


def court_title(title, url, date, record_name):
    """A neutral title: what the document is, which record, the year, a case number or citation."""
    t = title or ''
    kind = ''
    for rx, label in COURT_TYPES:
        if re.search(rx, t, re.I):
            kind = label
            break
    if not kind:
        u = url.lower()
        if '/docket' in u or 'pacermonitor' in u or 'unicourt' in u:
            kind = 'Docket'
        elif any(h in u for h in ('casetext.com', 'leagle.com', 'findlaw.com', 'casemine.com', 'law.justia.com',
                                    'courtlistener.com/opinion', 'scholar.google', 'oyez.org', 'findacase.com')):
            kind = 'Court opinion'
        else:
            kind = 'Court record'
    if CRIMINAL.search(t):
        kind = 'Criminal case: ' + kind.lower() if kind not in ('Court record', 'Court opinion') else 'Criminal case record'
    m = re.search(r'\b(page|part|pt\.?)\s*(\d{1,3})\b', t, re.I)
    if m:
        kind += ', %s %s' % ('page' if m.group(1).lower() == 'page' else 'part', m.group(2))
    bits = [b for b in (record_name, year_of(date, t)) if b]
    out = kind + (' - ' + ', '.join(bits) if bits else '')
    ref = ''
    for rx in CASE_NO:
        mm = rx.search(t) or rx.search(url)
        if mm:
            ref = 'case no. ' + mm.group(1)
            break
    if not ref:
        mm = CITATION.search(t)
        if mm:
            ref = mm.group(1)
    return out + (' (' + ref + ')' if ref else '')


def title_key(s):
    s = re.sub(r'[^a-z0-9 ]', ' ', (s or '').lower())
    return ' '.join(w for w in s.split() if w not in ('the', 'a', 'an'))


def load_news_titles(con):
    out = {}
    for nid, t1, t2 in con.execute("SELECT id, article_title, alternate_title FROM news_submissions WHERE status <> 'deleted'"):
        for t in (t1, t2):
            k = title_key(t)
            if len(k) >= 15:
                out.setdefault(k, nid)
    return out


def news_on_file(title, news):
    k = title_key(title)
    if len(k) < 15:
        return None
    if k in news:
        return news[k]
    for nk, v in news.items():
        if len(nk) >= 25 and len(k) >= 25 and (nk in k or k in nk):
            return v
    return None


def load_post_urls(con):
    out = set()
    for (content,) in con.execute("SELECT post_content FROM wpdl_posts WHERE post_type IN ('post','page') AND post_status = 'publish'"):
        for x in re.findall(r'https?://[^\s"\'<>]+', content or ''):
            for cand in (x, gx.wayback_original(x)):
                n = gx.normalize_url(cand)
                if n:
                    out.add(n)
    return out


def load_queued():
    """Addresses the Google Docs, HEAL and wiki files already offer on the same screen."""
    out = set()
    for p in (os.path.join(ROOT, 'tmp', 'gdocs', 'links.json'), os.path.join(ROOT, 'tmp', 'heal', 'heal-links.json'),
              os.path.join(ROOT, 'tmp', 'wiki', 'wiki-links.json')):
        if not os.path.exists(p):
            continue
        for it in json.load(open(p, encoding='utf-8')):
            for u in (it.get('url'), it.get('original'), it.get('key')):
                n = gx.normalize_url(u) if u and '://' in u else (u or None)
                if n:
                    out.add(n)
                w = gx.wayback_original(u or '')
                if w:
                    out.add(gx.normalize_url(w))
    out.discard(None)
    return out


class Out:
    def __init__(self):
        self.items = collections.OrderedDict()
        self.drop = collections.Counter()
        self.merged = 0

    def add(self, it):
        k = it['key']
        have = self.items.get(k)
        if not have:
            self.items[k] = it
            return
        self.merged += 1
        for s in it['seen']:
            if s['doc'] not in {x['doc'] for x in have['seen']} and len(have['seen']) < 12:
                have['seen'].append(s)
        if not have['facility'] and it['facility']:
            for f in ('facility', 'facility_how', 'operator'):
                have[f] = it[f]
        ids = {have['facility']['id']} if have['facility'] else set()
        for a in ([it['facility']] if it['facility'] else []) + it['also_named']:
            if a['id'] not in ids and all(x['id'] != a['id'] for x in have['also_named']):
                have['also_named'].append(a)
        if not have['operator'] and it['operator'] and not have['facility']:
            have['operator'] = it['operator']


# Made-up case titles in SCIAD NET's shapes, with the private names each must not show.
SELFTEST_COURT = [
    ('Doe v. Aspen Education Group', 'https://casetext.com/case/x', '', 'Aspen Education Group', ['doe']),
    ('Jane Roe\'s Affidavit Page 1', 'https://web.archive.org/web/2004/http://example.com/a.htm', '2002-10-12', 'Mountain Park Academy', ['jane', 'roe']),
    ('Roe vs Poe First Complaint Page 5', 'https://example.com/b5.jpg', '2002-09-26', 'Mountain Park Academy', ['roe', 'poe']),
    ('Deposition of Mary Moe Part 4', 'https://example.org/d4', '', 'Hephzibah House', ['mary', 'moe']),
    ('Doe v Island View Academy et al 3', 'https://law.justia.com/cases/federal/district-courts/utah/utdce/1:2099cv00001/1/3/',
     '', 'Island View Academy', ['doe']),
    ('State of Tennessee vs John Quincy Roe', 'https://example.gov/op.pdf', '2010', 'Teen Challenge', ['john', 'quincy', 'roe']),
    ('Poe v. Charter Medical Corp., 999 F. Supp. 9999', 'https://casetext.com/case/poe', '1997', 'Charter Medical', ['poe']),
    ('Janie R vs Redemption Ranch Inc Et Al Complaint', 'https://example.org/c.pdf', '1982-02-17', 'Redemption Ranch', ['janie']),
]


def selftest():
    bad = 0
    for title, url, date, record, private in SELFTEST_COURT:
        label = court_title(title, url, date, record)
        leak = [p for p in private if p in label.lower()]
        ok = not leak and not re.search(r'\bvs?\.?\s', label)
        bad += not ok
        print(('PASS ' if ok else 'FAIL ') + repr(title) + ' -> ' + repr(label) + ('  leaks ' + ', '.join(leak) if leak else ''))
    checks = [
        (party_tokens('Exampleton v. Aspen Education Group', 'Aspen Education Group') == {'exampleton'}, 'party tokens keep the plaintiff, drop the program'),
        (family_case('ROE vs ROE') and not family_case('Doe v. Cedars Academy'), 'a family case is the same surname on both sides'),
        (bool(FAMILY_OR_JUVENILE.search('In re the Matter of J.R., a minor')), 'juvenile files are recognised'),
        (name_title('John Quincy Doe - 2003') and name_title('The Roe Family') and not name_title('Hyde School, 1972'),
         'a title that is only a name'),
        (bool(PRIVATE_TITLE.search('Richard Roe Obituary')) and bool(PRIVATE_TITLE.search('Survivors speak out')), 'stems match whole words'),
        (full_date('Sunday, Nov. 14, 2004') == '2004-11-14' and full_date('1998') == '' and full_date('2010-07-30') == '2010-07-30',
         'only a full day becomes a publication date'),
    ]
    for ok, what in checks:
        bad += not ok
        print(('PASS ' if ok else 'FAIL ') + what)
    print('All passed.' if not bad else '%d FAILED' % bad)
    return 1 if bad else 0


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=os.path.join(SCIAD, 'sciad-links.json'))
    ap.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    ap.add_argument('--selftest', action='store_true', help='check the court titles and privacy rules on a fixture, then stop')
    args = ap.parse_args()
    if args.selftest:
        return selftest()

    con = sqlite3.connect(args.db)
    on_file = gx.load_on_file(con)
    news_domains = gx.load_news_domains(con)
    program_domains = gx.load_program_domains(con, news_domains)
    news_titles = load_news_titles(con)
    post_urls = load_post_urls(con)
    queued = load_queued()
    facs = {fid: {'id': fid, 'name': name, 'state': (st or '').upper()}
            for fid, name, st in con.execute('SELECT id, name, state FROM facilities_v2')}
    ops = {oid: name for oid, name in con.execute('SELECT id, name FROM wpdl_kop_operators')}
    programs = json.load(open(os.path.join(SCIAD, 'programs.json'), encoding='utf-8'))
    print('%d URLs on file, %d news titles, %d post URLs, %d already offered by Google Docs/HEAL/wiki'
          % (len(on_file), len(news_titles), len(post_urls), len(queued)))

    out = Out()
    D = out.drop

    def held(urls, cat, title):
        norms = set()
        for u in urls:
            if not u:
                continue
            for cand in (u, gx.wayback_original(u), unwrap(u)):
                n = gx.normalize_url(cand) if cand else None
                if n:
                    norms.add(n)
        for n in norms:
            if on_file.get(n):
                return 'held: ' + on_file[n][0][0] + ' record has this address'
            if n in post_urls:
                return 'held: a published KOP post links it'
            if n in queued:
                return 'held: already offered from the Google Docs, HEAL or wiki'
        if cat == 'news' and news_on_file(title, news_titles):
            return 'held: headline already in news_submissions'
        return ''

    def emit(group, zkey, d, title, url, cat, cats_paths, prog_keys, date):
        """One item that has passed the privacy rules: tie, dedupe, kind, label."""
        orig = unwrap(url) or ''
        if orig:
            orig = strip_fragment(orig)
        url = strip_fragment(url)
        target_url = orig or url
        h = sc.host(target_url)
        if PIRACY.search(h) or PIRACY.search(sc.host(url)):
            D['left out: pirated copy (sci-hub, libgen)'] += 1
            return
        # Tie: exact program collections only.
        fac_ids, op_ids, amb, prog_name = [], [], [], ''
        for pk in prog_keys:
            p = programs.get(pk)
            if not p:
                continue
            prog_name = prog_name or p['name']
            if p['result'] == 'facility':
                fac_ids += [f for f in p['facilities'] if f in facs and f not in fac_ids]
            elif p['result'] == 'operator':
                op_ids += [o for o in p['operators'] if o in ops and o not in op_ids]
            elif p['result'] == 'ambiguous':
                amb += [f for f in p['facilities'] if f in facs and f not in amb]
        record_name = facs[fac_ids[0]]['name'] if fac_ids else (ops[op_ids[0]] if op_ids else prog_name)

        # Kind.
        if cat == 'news':
            kind = 'reference' if VIDEO_HOST.search(h) else 'news'
            if kind == 'news' and BLOG_HOST.search(h):
                D['left out: personal blog or social post filed as news'] += 1
                return
        elif cat == 'legal':
            kind = 'court'
        elif cat == 'govrecord':
            kind = 'legislation' if d.get('itemType') == 'bill' else gx.classify(target_url, gx.domain_of(target_url), news_domains, program_domains)
            if kind not in ('legislation', 'inspection', 'court', 'government'):
                kind = 'government'
        elif cat == 'programdoc':
            kind = 'archive' if (orig or ARCHIVE_TODAY.match(url)) else 'other'
        elif cat in ('programinfo', 'research', 'media'):
            kind = 'reference'
        else:
            kind = gx.classify(target_url, gx.domain_of(target_url), news_domains, program_domains)
            if kind in ('internal', 'social', 'people', 'program_site'):
                kind = 'other'
            if orig or ARCHIVE_TODAY.match(url):
                kind = kind if kind in ('news', 'court', 'legislation', 'inspection', 'government', 'reference') else 'archive'

        outlet = flat(d.get('publicationTitle') or d.get('websiteTitle') or d.get('blogTitle') or d.get('programTitle')
                      or d.get('seriesTitle') or d.get('network') or '')
        if kind != 'court':
            what_host = gx.classify(target_url, gx.domain_of(target_url), news_domains, program_domains)
            if what_host == 'internal':
                D['left out: a search or map page'] += 1
                return
            if PERSONAL_HOST.search(h) and what_host != 'news':
                D['private: page of a survivor-run or personal site'] += 1
                return
            in_news = any(cp[-1:] in (['News'], ['Breaking Code Silence News']) for cp in cats_paths)
            # A headline in a News folder may name people as journalism does; anywhere else a
            # title that is only a name is a person (a death list entry, a survivor, a video).
            if name_title(title) and not (kind == 'news' and in_news):
                D['private: title is only a person\'s name'] += 1
                return
            in_random = any(cp[-1:] == ['Random'] for cp in cats_paths)
            if FIRST_PERSON.search(title) and (kind != 'news' or in_random):
                D['private: first-person title (a blog or forum post)'] += 1
                return
            if in_random and not (what_host in ('news', 'government', 'court', 'legislation', 'inspection', 'reference')
                                  or kind in ('government', 'legislation', 'inspection') or outlet):
                D['private: "Random" folder item not from a news outlet, court or government'] += 1
                return

        # The address: news under the original where known (the queue's duplicate check reads it).
        final = orig if (kind == 'news' and orig) else url
        archive = url if final != url else ''
        if not re.match(r'^https?://', final, re.I):
            D['left out: not a web address'] += 1
            return
        why = held([url, orig], kind, title)
        if why:
            D[why] += 1
            return

        if kind == 'court':
            label = court_title(title, target_url, date, record_name)
            outlet = ''
        else:
            label = flat(title) or gx.link_label('', '', target_url)
            if label.lower().startswith('http'):
                label = gx.link_label('', '', target_url)
            bits = [b for b in (outlet, date) if b and b.lower() not in label.lower()]
            label = (label + (' (' + ', '.join(bits) + ')' if bits else ''))[:300]
        what = gx.CATEGORY_LABEL.get(kind, kind)
        dated = (' Published by %s, %s.' % (outlet, date)) if outlet and date else (' Dated %s.' % date if date else '')
        seen = [{'doc': 'SCIAD NET: ' + ' / '.join(cp), 'text': '%s filed in %s under %s.%s' % (what, CREDIT, ' / '.join(cp), dated),
                 'date': date, 'published': full_date(date), 'outlet': outlet, 'archive': archive,
                 'credit': CREDIT, 'credit_url': CREDIT_URL}
                for cp in cats_paths[:12]]
        it = {
            'key': gx.normalize_url(final),
            'url': final,
            'original': orig if final == url else '',
            'domain': gx.domain_of(final) + (' (archived)' if final == url and (orig or ARCHIVE_TODAY.match(url)) else ''),
            'category': kind,
            'label': label,
            'facility': None, 'facility_how': '', 'also_named': [], 'operator': None, 'on_file': [],
            'source': 'sciad',
            'seen': seen,
        }
        if fac_ids:
            it['facility'] = facs[fac_ids[0]]
            it['facility_how'] = 'SCIAD collection (exact name)'
            it['also_named'] = [facs[f] for f in fac_ids[1:]]
        elif op_ids:
            it['operator'] = {'id': op_ids[0], 'name': ops[op_ids[0]]}
        elif amb:
            it['also_named'] = [facs[f] for f in amb]  # one name, several KOP records: the reviewer picks
        D['offered'] += 1
        out.add(it)

    # ------------------------------------------------------------------ SCIAD NET (4552235)
    cols = sc.load_collections(4552235)
    raw = {i['key']: i for i in sc.load_items(4552235)}
    for line in open(os.path.join(SCIAD, 'items.jsonl'), encoding='utf-8'):
        r = json.loads(line)
        d = raw.get(r['key'], {}).get('data', {})
        url = (r['url'] or '').strip()
        if r['type'] in ('note', 'attachment'):
            continue
        if not url:
            D['left out: no address'] += 1
            continue
        if sc.drive_id(url)[0]:
            D['left out: Drive document (part A, survivor archives)'] += 1
            continue
        if r['privacy'] == 'private':
            D['private: ' + r['cat']] += 1
            continue
        if r.get('dup'):
            D['held: ' + r['dup']] += 1
            continue
        # Full collection paths (the survey kept only the category level).
        paths = [cols[c]['path'] for c in d.get('collections', []) if c in cols]
        if any(PRIVATE_FOLDER.search(seg) for p in paths for seg in (p[3:4] if p[:1] == ['Programs'] else p[1:2])
               ) or any(PRIVATE_FOLDER.search(seg) for p in paths if p[:1] == ['Programs'] and len(p) > 4 and p[3] != 'Legal' for seg in p[4:]):
            D['private: letters, emails, accounts, records folder'] += 1
            continue
        title = flat(r['title'])
        cat = r['cat']
        if r['privacy'] == 'review' and cat != 'legal':
            D['private: names a private person (letter, statement, obituary, testimony)'] += 1
            continue
        if cat != 'legal' and PRIVATE_TITLE.search(title):
            D['private: title words (letter, testimony, survivor, obituary...)'] += 1
            continue
        h = sc.host(unwrap(url) or url)
        if (cat in ('other', 'media', 'research', 'programinfo') and d.get('itemType') in ('blogPost', 'forumPost', 'email', 'letter', 'interview')
                or BLOG_HOST.search(h) and 'wiki' not in (unwrap(url) or url)):
            D['private: personal blog, forum or social post'] += 1
            continue
        if cat == 'media' and (VIDEO_HOST.search(h) or d.get('itemType') in ('podcast', 'videoRecording', 'audioRecording')) and PERSONAL_MEDIA.search(title):
            D['private: testimony or personal video/podcast'] += 1
            continue
        if cat == 'news' and VIDEO_HOST.search(h) and PERSONAL_MEDIA.search(title):
            D['private: testimony or personal video/podcast'] += 1
            continue
        date = sc.item_date(d) or r.get('date') or ''
        if cat == 'legal':
            if FAMILY_OR_JUVENILE.search(title) or family_case(title):
                D['private: family or juvenile court file'] += 1
                continue
            rn = ''
            for pk in r['programs']:
                p = programs.get(pk) or {}
                rn += ' ' + (p.get('name') or '') + ' ' + ' '.join(facs[f]['name'] for f in p.get('facilities', []) if f in facs)
            toks = party_tokens(title, rn)
            addr = (url + ' ' + (unwrap(url) or '')).lower()
            if any(t in addr for t in toks):
                D['private: court address carries a party name'] += 1
                continue
        seen_paths = []
        for p in paths:
            if p[:1] == ['Programs'] and len(p) >= 3:
                seg = [p[1], p[2]] + ([p[3]] if len(p) >= 4 else [])
            else:
                seg = p[:2]
            if seg not in seen_paths:
                seen_paths.append(seg)
        if not seen_paths:
            seen_paths = [['Not in a collection']]
        prog_keys = r['programs']
        emit(4552235, r['key'], d, title, url, cat, seen_paths, prog_keys, date)

    # ------------------------------------------------------------------ WWASP Survivors (772277)
    cols2 = sc.load_collections(772277)
    tar = None
    for pk, p in programs.items():
        if sc.name_key(p['name']) == 'turnaboutranch' and p['result'] == 'facility':
            tar = pk
    for it in sc.load_items(772277):
        d = it['data']
        t = d['itemType']
        if t in ('attachment', 'note'):
            continue
        url = (d.get('url') or '').strip()
        title = flat(sc.item_title(d))
        if not url:
            D['772277 left out: no address'] += 1
            continue
        if sc.drive_id(url)[0]:
            D['772277 left out: Drive document'] += 1
            continue
        h = sc.host(unwrap(url) or url)
        if t in ('blogPost', 'forumPost', 'letter', 'email', 'interview', 'manuscript', 'presentation') or BLOG_HOST.search(h):
            D['772277 private: blog, forum, letter or social post'] += 1
            continue
        if PRIVATE_TITLE.search(title):
            D['772277 private: title words'] += 1
            continue
        if t in ('newspaperArticle', 'magazineArticle', 'tvBroadcast', 'radioBroadcast'):
            cat = 'news'
        elif t in ('case', 'hearing'):
            cat = 'legal'
        elif t in ('bill', 'statute'):
            cat = 'govrecord'
        elif t in ('book', 'bookSection', 'journalArticle', 'report', 'thesis', 'encyclopediaArticle'):
            cat = 'research'
        elif t in ('videoRecording', 'film', 'audioRecording', 'podcast'):
            if PERSONAL_MEDIA.search(title):
                D['772277 private: testimony or personal video'] += 1
                continue
            cat = 'media'
        else:
            k = gx.classify(unwrap(url) or url, gx.domain_of(unwrap(url) or url), news_domains, program_domains)
            cat = 'news' if k == 'news' else 'other'
        if cat == 'legal':
            if FAMILY_OR_JUVENILE.search(title) or family_case(title):
                D['772277 private: family or juvenile court file'] += 1
                continue
            if any(tk in url.lower() for tk in party_tokens(title, '')):
                D['772277 private: court address carries a party name'] += 1
                continue
        # The collections: WWASP, WWASP/New, WWASP/New/Turn About Ranch, and one named after a
        # person, which is never written. Only Turn About Ranch names a record.
        progs, where = [], ['WWASP Survivors group']
        for c in d.get('collections', []):
            pth = cols2.get(c, {}).get('path', [])
            if pth[-1:] == ['Turn About Ranch'] and tar:
                progs = [tar]
                where = ['WWASP Survivors group', 'Turn About Ranch']
        emit(772277, it['key'], d, title, url, cat, [where], progs, sc.item_date(d))

    items = list(out.items.values())
    # The same article saved twice (a Wayback and an archive.today copy): one row per headline and year.
    by_head = {}
    final = []
    dup_head = 0
    for it in items:
        if it['category'] == 'news':
            hk = (title_key(re.sub(r'\s*\([^()]*\)\s*$', '', it['label'])), year_of(it['seen'][0].get('date', '')))
            if len(hk[0]) >= 20 and hk in by_head:
                have = by_head[hk]
                if not have['facility'] and it['facility']:
                    have['facility'], have['facility_how'] = it['facility'], it['facility_how']
                for s in it['seen']:
                    if s['doc'] not in {x['doc'] for x in have['seen']} and len(have['seen']) < 12:
                        have['seen'].append(s)
                dup_head += 1
                continue
            by_head[hk] = it
        final.append(it)
    # A filing scanned a page at a time (a complaint in 13 links) is one court record: the first
    # page's link stands for it, and the entry says how many pages SCIAD NET holds.
    pages = collections.OrderedDict()
    rest = []
    for it in final:
        m = re.match(r'^(.*?), (page|part) (\d+)( - .*)?$', it['label']) if it['category'] == 'court' else None
        if not m:
            rest.append(it)
            continue
        tie = it['facility']['id'] if it['facility'] else ('o%s' % it['operator']['id'] if it['operator'] else it['seen'][0]['doc'])
        pages.setdefault((m.group(1), m.group(4) or '', tie), []).append((int(m.group(3)), m.group(2), it))
    paged = 0
    for (head, tail, _tie), group in pages.items():
        group.sort(key=lambda g: g[0])
        if len({g[0] for g in group}) != len(group):
            rest.extend(g[2] for g in group)  # Two "page 1"s: several documents of one kind, not one filing.
            continue
        n, word, it = group[0]
        if len(group) > 1:
            paged += len(group) - 1
            it['label'] = '%s, %d %ss%s' % (head, len(group), word, tail)
            nums = ', '.join(str(g[0]) for g in group)
            for s in it['seen']:
                s['text'] += ' This link is %s %d; SCIAD NET holds %ss %s, each its own scan.' % (word, n, word, nums)
        rest.append(it)
    final = rest
    items = sorted(final, key=lambda i: (i['facility']['name'] if i['facility'] else '~', i['category'], i['url']))
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, 'w', encoding='utf-8') as fh:
        json.dump(items, fh, ensure_ascii=False, indent=0)

    kinds = collections.Counter(i['category'] for i in items)
    tie = collections.Counter('facility' if i['facility'] else ('operator only' if i['operator'] else
                              ('ambiguous name' if i['also_named'] else 'none')) for i in items)
    by_kind_tie = collections.Counter((i['category'], 'facility' if i['facility'] else ('operator' if i['operator'] else 'none')) for i in items)
    D.pop('offered', None)
    rep = ['# SCIAD NET links for Drive Docs', '',
           '%d rows in %s (%d entries merged by address, %d news rows merged by headline and year, '
           '%d court pages folded into their filing).' % (
               len(items), os.path.relpath(args.out, ROOT), out.merged, dup_head, paged), '',
           '## By kind', ''] + ['- %s: %d (facility %d, operator %d, none %d)' % (
               k, v, by_kind_tie[(k, 'facility')], by_kind_tie[(k, 'operator')], by_kind_tie[(k, 'none')]) for k, v in kinds.most_common()]
    rep += ['', '## By tie', ''] + ['- %s: %d' % kv for kv in tie.most_common()]
    rep += ['', 'Facilities gaining rows: %d; operators: %d' % (
        len({i['facility']['id'] for i in items if i['facility']}), len({i['operator']['id'] for i in items if i['operator']}))]
    rep += ['', '## Left out', ''] + ['- %s: %d' % kv for kv in sorted(D.items(), key=lambda kv: -kv[1])]
    rep += ['', '## Court titles (sample)', ''] + ['- ' + i['label'] for i in items if i['category'] == 'court'][:40]
    with open(os.path.join(SCIAD, 'sciad-links-report.md'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(rep) + '\n')
    print('\n'.join(rep[:60]))
    print('wrote', args.out)


if __name__ == '__main__':
    sys.exit(main())
