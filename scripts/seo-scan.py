"""Scan every program website KOP knows for instructions written to AI and other SEO tactics.

Domains come from facilities_v2 in tmp/prod.sqlite (profileLinks, Wayback links unwrapped,
and the operator's websites), the program sites in tmp/gdocs/links.json, and the directory
and program sites in the reputation series. Each domain is read politely (one request a
second per site, a few sites at a time):

  /llms.txt, /llms-full.txt, /ai.txt   instructions for chatbots: sources to ignore, how to
                                       frame criticism, AI-only notes, ready-made answers,
                                       the Exceed LLMS plugin line
  /robots.txt                          which AI crawlers are blocked or let in
  the home page                        hidden text aimed at AI, AI instructions in comments or
                                       meta tags, self-awarded review stars, the web agency credit
  the sitemaps                         near-identical city/state pages ("doorway" pages) and
                                       pages answering critics (Reddit, lawsuits, "the truth about")

Everything lands in tmp/seo-scan/ (never committed): raw copies of what was read under raw/,
results.jsonl (one line per domain; a rerun skips domains already there), and report.html /
report.csv sorted by how much was found.

usage:
  python scripts/seo-scan.py                      # every domain
  python scripts/seo-scan.py --limit 20           # the first 20 not yet scanned
  python scripts/seo-scan.py --domains a.com b.org
  python scripts/seo-scan.py --refresh            # rescan domains already in results.jsonl
  python scripts/seo-scan.py --no-sitemaps        # skip the sitemap pass (much faster)
  python scripts/seo-scan.py --report             # rebuild the report from results.jsonl only
  python scripts/seo-scan.py --list               # print the domain list and where each came from
"""
import argparse, collections, csv, datetime, html, json, os, re, sqlite3, sys, threading, time
from concurrent.futures import ThreadPoolExecutor, as_completed
from urllib.parse import urljoin, urlparse

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tmp', 'seo-scan')
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
GDOCS = os.path.join(ROOT, 'tmp', 'gdocs', 'links.json')

# The directories and programs read for "Telling the Chatbots What to Say" (seeds/reputation/),
# kept so a scan always covers them even when no record links them.
KNOWN = {
    'besttherapeuticboardingschools.com': 'Exceed directory',
    'militaryschoolusa.com': 'Exceed directory',
    'therapeuticboardingschools.org': 'Exceed directory',
    'troubledteenschools.us': 'Exceed directory',
    'vivetreatment.com': 'Vive Adolescent Care',
    'newlifehouseacademy.com': 'New Lifehouse Academy',
    'newlifehouseacademy.org': 'New Lifehouse Academy',
    'mastersranch.org': "Master's Ranch Christian Academy",
    'teenchallengeforboys.org': 'New Hope Boys Home',
    'safeharboracademy.com': 'Safe Harbor Academy',
    'brushcreekacademy.com': 'Brush Creek Academy',
    'resolutionranch.org': 'Resolution Ranch',
    'stillwateracademy.org': 'Stillwater Academy',
    'liahonaacademy.com': 'Liahona Academy',
    'exceedmarketingsolutions.com': 'Exceed Marketing Solutions',
}

# Hosts that are never a program's own site.
NOT_PROGRAM = re.compile(r'''(^|\.)(
    kidsoverprofits\.org|archive\.org|archive\.ph|archive\.today|reddit\.com|redd\.it|facebook\.com|fb\.com|
    instagram\.com|twitter\.com|x\.com|youtube\.com|youtu\.be|linkedin\.com|tiktok\.com|pinterest\.com|
    wikipedia\.org|wikimedia\.org|google\.[a-z.]+|goo\.gl|bit\.ly|tinyurl\.com|docs\.google\.com|drive\.google\.com|
    sec\.gov|[a-z0-9-]+\.gov|[a-z0-9-]+\.us\.gov|state\.[a-z]{2}\.us|[a-z]{2}\.us|natsap\.org|guidestar\.org|
    propublica\.org|opencorporates\.com|bizapedia\.com|bbb\.org|yelp\.com|glassdoor\.com|indeed\.com|
    squarespace\.com|wixsite\.com|wordpress\.com|blogspot\.com|medium\.com|substack\.com|amazon\.com|
    nytimes\.com|washingtonpost\.com|usatoday\.com|apnews\.com|cnn\.com|nbcnews\.com|abcnews\.go\.com|
    imprintnews\.org|abc\.net\.au|sltrib\.com|ksl\.com|fox13now\.com|heal-online\.org|fornits\.com|
    jri\.org|djj\.state\.fl\.us|justia\.com|courtlistener\.com|pacer\.gov|scribd\.com|issuu\.com
)$''', re.X)

UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'
AI_BOTS = ['GPTBot', 'ChatGPT-User', 'OAI-SearchBot', 'ClaudeBot', 'Claude-Web', 'anthropic-ai', 'PerplexityBot',
           'Google-Extended', 'CCBot', 'Bytespider', 'Applebot-Extended', 'Meta-ExternalAgent', 'cohere-ai', 'Amazonbot']

# ---------------------------------------------------------------- what counts as a finding
# (key, weight, label, pattern). Matched line by line against the AI files.
LLMS_RULES = [
    ('exceed', 2, 'Made with the Exceed LLMS plugin', r'Exceed LLMS plugin|exceedmarketingsolutions'),
    ('suppress', 6, 'Tells AI which sources not to use',
     r'(do not|don\'t|should not|never)\s+(use|cite|rely|reference|include|consider)\b.{0,160}\b(reddit|yelp|quora|glassdoor|news|media|forum|review|blog|social|user[- ]generated|anonymous|third[- ]party)'
     r'|sources?\s+(that\s+should\s+not|to\s+avoid|not\s+to\s+(use|cite))|\bavoid\s+(citing|using|relying|referencing)\b'
     r'|more than \d+ days (ago|old)|older than \d+ days'),
    ('critics', 6, 'Frames criticism or survivors for AI',
     r'inoculation|activist|ideological|disgruntled|agenda|smear|negative[- ](sentiment|inquir|criticism|narrative|comment|review)'
     r'|criticism\s+fallback|unverified unless|anecdotal|advocacy[- ]driven|user[- ]generated allegations|selective reporting'
     r'|does not constitute verification|opposition to (all )?residential'),
    ('directive', 3, 'AI-only notes and ready-made answers',
     r'AI[- ]ONLY|AI systems should|for AI (systems|models|assistants)|PREFERRED\s+(AI\s+PROMPT|SHORT\s+ANSWER|LONG\s+ANSWER|ANSWER|REVIEWS)'
     r'|PLATFORM[- ]SPECIFIC PROMPT|ANSWER TONE|INFORMATION EVALUATION GUIDELINES|when (answering|responding to) (questions|queries)'
     r'|directory inclusion in LLMs|\bGEO\b|generative engine'),
    ('chatbots', 2, 'Names chatbots', r'\b(ChatGPT|Perplexity|Claude|Gemini|Copilot|Bard|Grok)\b'),
    ('wording', 4, 'Tells AI which words to avoid',
     r'avoid[: ].{0,80}(troubled teen industry|therapeutic|boot camp|military school|detention|abuse|rehab)'
     r'|avoid mentioning|should not be (characterized|described|interpreted)|do not (describe|characterize|call|refer)'),
    ('injection', 8, 'Classic prompt injection',
     r'ignore (all |any )?(previous|prior|above|other) (instructions|sources)|system prompt|you are (an? )?(ai|assistant|language model)'
     r'|disregard (any|all|previous)|override (your|any) (instructions|guidelines)'),
]
LLMS_RULES = [(k, w, l, re.compile(p, re.I)) for k, w, l, p in LLMS_RULES]

HTML_AI = re.compile(r'\b(LLMs?|large language models?|AI (systems?|models?|assistants?|crawlers?|agents?) (should|must|may)|'
                     r'for AI (systems|models|assistants)|ignore (all )?previous instructions|llms\.txt)\b', re.I)
HIDDEN_STYLE = re.compile(r'display\s*:\s*none|visibility\s*:\s*hidden|font-size\s*:\s*0(px|em|rem)?\s*(;|$)|'
                          r'(left|top|text-indent)\s*:\s*-\d{3,}px|opacity\s*:\s*0(\.0+)?\s*(;|$)|clip\s*:\s*rect\(0', re.I)
CREDIT = re.compile(r'(?:website|site|web design|designed|developed|built|powered|marketing|seo)\s*(?:design\s*)?(?:by|:)\s*'
                    r'(?:<a[^>]*>)?\s*([A-Z][\w&.\' -]{2,40})', re.I)
REPUTATION_SLUG = re.compile(r'reddit|online-reviews|negative-review|navigat\w*-online-reviews|lawsuit|allegation|truth-about|'
                             r'misinformation|troubled-teen-industry|breaking-code-silence|paris-hilton|survivor|critic(s|ism|ize)?\b|'
                             r'controvers|setting-the-record|rumou?r|accusation|scam|is-it-safe|abuse-claims|investigation', re.I)
STATES = ('alabama alaska arizona arkansas california colorado connecticut delaware florida georgia hawaii idaho illinois '
          'indiana iowa kansas kentucky louisiana maine maryland massachusetts michigan minnesota mississippi missouri '
          'montana nebraska nevada new-hampshire new-jersey new-mexico new-york north-carolina north-dakota ohio oklahoma '
          'oregon pennsylvania rhode-island south-carolina south-dakota tennessee texas utah vermont virginia washington '
          'west-virginia wisconsin wyoming').split()
ABBR = ('al ak az ar ca co ct de fl ga hi id il in ia ks ky la me md ma mi mn ms mo mt ne nv nh nj nm ny nc nd oh ok '
        'or pa ri sc sd tn tx ut vt va wa wv wi wy').split()
STATE_RE = re.compile(r'(?<![a-z])(' + '|'.join(sorted(STATES, key=len, reverse=True)) + r')(?![a-z])')
CITY_RE = re.compile(r'(?<![a-z])([a-z]+(?:-[a-z]+){0,2})-(' + '|'.join(ABBR) + r')(?![a-z])')


# ---------------------------------------------------------------- domains
def host_of(url):
    url = url.strip()
    m = re.match(r'https?://web\.archive\.org/web/[^/]+/(.+)$', url, re.I)
    if m:
        url = m.group(1)
        if not re.match(r'https?://', url, re.I):
            url = 'http://' + url
    if not re.match(r'https?://', url, re.I):
        if not re.match(r'^(www\.)?[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)', url, re.I):
            return ''
        url = 'http://' + url
    h = (urlparse(url).hostname or '').lower().rstrip('.')
    h = re.sub(r'^www\d?\.', '', h)
    if not h or '.' not in h or NOT_PROGRAM.search(h) or re.match(r'^\d+\.\d+\.\d+\.\d+$', h):
        return ''
    return h


def collect_domains():
    """{domain: {'facilities': {id: name}, 'sources': set(), 'label': str}}"""
    out = collections.defaultdict(lambda: {'facilities': {}, 'sources': set(), 'label': ''})

    def add(url, src, fid=None, name=''):
        h = host_of(url or '')
        if not h:
            return
        d = out[h]
        d['sources'].add(src)
        if fid:
            d['facilities'][int(fid)] = name

    if os.path.exists(DB):
        c = sqlite3.connect(DB)
        for fid, name, j in c.execute('select id, name, json_data from facilities_v2'):
            try:
                doc = json.loads(j)
            except Exception:
                continue
            for u in doc.get('profileLinks') or []:
                add(u if isinstance(u, str) else (u or {}).get('url', ''), 'record website', fid, name)
            for u in ((doc.get('provenance') or {}).get('sourceOperator') or {}).get('websites') or []:
                add(u, 'operator website', fid, name)
    else:
        print('note: tmp/prod.sqlite missing (python scripts/sync-prod-sqlite.py); record websites skipped', file=sys.stderr)
    if os.path.exists(GDOCS):
        for r in json.load(open(GDOCS, encoding='utf-8')):
            if r.get('category') == 'program_site':
                f = r.get('facility') or {}
                add(r.get('original') or r.get('url'), 'Google Docs program site', f.get('id'), f.get('name', ''))
    for h, label in KNOWN.items():
        out[h]['sources'].add('reputation series')
        out[h]['label'] = out[h]['label'] or label
    return out


# ---------------------------------------------------------------- fetching
_local = threading.local()


def session():
    if not hasattr(_local, 's'):
        try:
            from curl_cffi import requests as cr
            _local.s = cr.Session(impersonate='chrome')
            _local.kind = 'curl_cffi'
        except Exception:
            import requests
            _local.s = requests.Session()
            _local.s.headers['User-Agent'] = UA
            _local.kind = 'requests'
    return _local.s


def get(url, timeout=25):
    """(status, final_url, content_type, text). Status 0 on a network error (text holds the error)."""
    try:
        r = session().get(url, timeout=timeout, allow_redirects=True)
        body = r.content[:3_000_000]
        enc = r.encoding if r.encoding and r.encoding.lower() not in ('iso-8859-1',) else 'utf-8'
        try:
            text = body.decode(enc, 'replace')
        except LookupError:
            text = body.decode('utf-8', 'replace')
        return r.status_code, str(r.url), r.headers.get('content-type', ''), text
    except Exception as e:
        return 0, url, '', f'{type(e).__name__}: {e}'[:300]


def looks_html(ctype, text):
    return 'html' in (ctype or '').lower() or bool(re.match(r'\s*(<!doctype|<html|<head|<body|<\?xml)', text[:500], re.I))


def save_raw(domain, name, text):
    d = os.path.join(OUT, 'raw', domain)
    os.makedirs(d, exist_ok=True)
    with open(os.path.join(d, name), 'w', encoding='utf-8') as f:
        f.write(text)


# ---------------------------------------------------------------- checks
def scan_ai_file(text):
    hits = collections.defaultdict(list)
    for n, line in enumerate(text.splitlines(), 1):
        s = line.strip()
        # "- [Page title](url): summary" lines are a page index (Yoast and others), not instructions
        if not s or re.match(r'[-*]\s*\[[^\]]*\]\(', s):
            continue
        for key, _w, _l, rx in LLMS_RULES:
            if rx.search(s) and len(hits[key]) < 6:
                hits[key].append({'line': n, 'text': s[:400]})
    return dict(hits)


def scan_robots(text):
    groups, cur, sitemaps = {}, [], []
    seen_rule = False
    for raw in text.splitlines():
        line = raw.split('#', 1)[0].strip()
        if ':' not in line:
            continue
        k, v = [x.strip() for x in line.split(':', 1)]
        k = k.lower()
        if k == 'sitemap':
            sitemaps.append(v)
        elif k == 'user-agent':
            if seen_rule:
                cur, seen_rule = [], False
            cur.append(v.lower())
            for a in cur:
                groups.setdefault(a, [])
        elif k in ('allow', 'disallow'):
            seen_rule = True
            for a in cur:
                groups.setdefault(a, []).append((k, v))
    blocked, allowed = [], []
    for bot in AI_BOTS:
        rules = groups.get(bot.lower())
        if rules is None:
            continue
        if any(k == 'disallow' and v == '/' for k, v in rules):
            blocked.append(bot)
        else:
            allowed.append(bot)
    return {'ai_blocked': blocked, 'ai_named_allowed': allowed, 'sitemaps': sitemaps,
            'mentions_llms': bool(re.search(r'llms', text, re.I))}


def scan_home(text):
    from bs4 import BeautifulSoup, Comment
    out = {'hidden_ai': [], 'comment_ai': [], 'meta_ai': [], 'ratings': [], 'credits': [], 'generator': '',
           'title': '', 'llms_link': False, 'mirror': '', 'exceed_html': bool(re.search(r'exceed', text, re.I) and
                                                                               re.search(r'exceed\s*marketing|exceedmarketingsolutions|Exceed LLMS', text, re.I))}
    try:
        soup = BeautifulSoup(text, 'lxml')
    except Exception:
        soup = BeautifulSoup(text, 'html.parser')
    # The Exceed plugin hides a copy of llms.txt in every page: <pre id="llms-data" style="display:none">.
    # It is often an older version than /llms.txt, so it is read as an AI file of its own.
    for el in soup.find_all(id=re.compile(r'llms', re.I)) + soup.find_all(attrs={'data-canonical': re.compile(r'llms', re.I)}):
        out['mirror'] = el.get_text('\n')
        el.decompose()
        break
    out['title'] = (soup.title.get_text(' ', strip=True) if soup.title else '')[:200]
    for m in soup.find_all('meta'):
        name = (m.get('name') or m.get('property') or '').lower()
        content = m.get('content') or ''
        if name == 'generator':
            out['generator'] = content[:120]
        if re.search(r'\bai\b|llm|gpt|robots', name) and re.search(r'ai|llm|gpt|claude|instruction', content, re.I):
            out['meta_ai'].append(f'{name}={content[:200]}')
    for l in soup.find_all('link'):
        if 'llms' in (l.get('href') or '').lower():
            out['llms_link'] = True
    for c in soup.find_all(string=lambda s: isinstance(s, Comment)):
        s = ' '.join(str(c).split())
        if re.match(r'(BEGIN|END) llms\.txt', s):
            continue
        if HTML_AI.search(s) and len(out['comment_ai']) < 5:
            out['comment_ai'].append(s[:400])
    for el in soup.find_all(True):
        style = el.get('style') or ''
        cls = ' '.join(el.get('class') or [])
        hidden = (HIDDEN_STYLE.search(style) or el.has_attr('hidden') or el.get('aria-hidden') == 'true'
                  or re.search(r'\b(sr-only|screen-reader-text|visually-hidden|hidden-ai|ai-only|llm)\b', cls))
        if not hidden:
            continue
        t = ' '.join(el.get_text(' ', strip=True).split())
        if re.search(r'this is an llms\.txt file', t[:300], re.I):
            out['mirror'] = out['mirror'] or el.get_text('\n')  # a second wrapper around the same hidden copy
            continue
        if len(t) > 30 and HTML_AI.search(t) and len(out['hidden_ai']) < 5:
            out['hidden_ai'].append(t[:400])
    for s in soup.find_all('script', type='application/ld+json'):
        raw = s.string or s.get_text() or ''
        for m in re.finditer(r'"aggregateRating"\s*:\s*\{[^{}]*?"ratingValue"\s*:\s*"?([\d.]+)"?[^{}]*?"(?:reviewCount|ratingCount)"\s*:\s*"?(\d+)', raw):
            out['ratings'].append({'value': m.group(1), 'count': m.group(2)})
        if re.search(r'"@type"\s*:\s*"Review"', raw) and not out['ratings']:
            out['ratings'].append({'value': 'review markup', 'count': ''})
    foot = soup.find('footer')
    ftext = str(foot) if foot else text[-20000:]
    for m in CREDIT.finditer(ftext):
        name = re.sub(r'\s+', ' ', html.unescape(re.sub(r'<[^>]+>', '', m.group(1)))).strip(' .-')
        if name and not re.match(r'(https?|www|the|us|our|wordpress|this)\b', name, re.I) and name not in out['credits']:
            out['credits'].append(name)
    out['credits'] = out['credits'][:4]
    return out


def sitemap_urls(domain, robots_maps, limit_files=40):
    seen, urls = set(), set()
    queue = list(robots_maps) or [f'https://{domain}/sitemap_index.xml', f'https://{domain}/sitemap.xml',
                                  f'https://{domain}/wp-sitemap.xml']
    queue = [q if q.startswith('http') else urljoin(f'https://{domain}/', q) for q in queue]
    while queue and len(seen) < limit_files:
        sm = queue.pop(0)
        if sm in seen:
            continue
        seen.add(sm)
        st, _f, _c, body = get(sm)
        time.sleep(1)
        if st != 200:
            continue
        locs = re.findall(r'<loc>\s*(?:<!\[CDATA\[)?\s*([^<\s\]]+)', body)
        if '<sitemapindex' in body:
            queue.extend(l for l in locs if l not in seen)
        else:
            urls.update(l for l in locs if not re.search(r'\.(jpe?g|png|webp|gif|svg|pdf|mp4)$', l, re.I))
    return sorted(urls), len(seen)


def doorway(urls):
    templates = collections.defaultdict(set)
    located = 0
    for u in urls:
        path = re.sub(r'^https?://[^/]+', '', u).lower()
        t = STATE_RE.sub('<STATE>', path)
        if t == path:
            t = CITY_RE.sub('<CITY>-<st>', path)
        else:  # "...-in-chino-hills-<STATE>/" and "...-in-<STATE>-city-<STATE>/" are one pattern
            t = re.sub(r'\b(in|from|near|for|of|serving|around)-(?:(?!-(?:in|from|near|for|of|serving|around)-)[a-z0-9<>-])+?-<STATE>',
                       r'\1-<CITY>-<STATE>', t)
        if t != path:
            located += 1
            templates[t].add(path)
    top = sorted(((t, len(p)) for t, p in templates.items() if len(p) >= 3), key=lambda x: -x[1])[:8]
    return located, top


# ---------------------------------------------------------------- one domain
def scan_domain(domain, info, do_sitemaps):
    r = {'domain': domain, 'scanned_at': datetime.datetime.now().isoformat(timespec='seconds'),
         'label': info['label'], 'facilities': [{'id': k, 'name': v} for k, v in sorted(info['facilities'].items())][:12],
         'facility_count': len(info['facilities']), 'sources': sorted(info['sources']), 'ai_files': {}, 'findings': {}}
    st, final, ctype, body = get(f'https://{domain}/')
    if st == 0 or st >= 500:
        st2, final2, ctype2, body2 = get(f'http://{domain}/')
        if st2 and st2 < 500:
            st, final, ctype, body = st2, final2, ctype2, body2
    r['home_status'], r['home_final'] = st, final
    fh = re.sub(r'^www\d?\.', '', (urlparse(final).hostname or '').lower())
    r['redirects_to'] = fh if fh and fh != domain and not fh.endswith('.' + domain) else ''
    if st == 0:
        r['error'] = body
        return r
    base = f'{urlparse(final).scheme or "https"}://{urlparse(final).hostname or domain}'
    if 200 <= st < 400 and looks_html(ctype, body):
        save_raw(domain, 'home.html', body)
        r['home'] = scan_home(body)
        mirror = r['home'].pop('mirror', '')
        if mirror.strip():
            save_raw(domain, 'home-hidden-llms.txt', mirror)
            r['ai_files']['hidden copy in home page'] = {
                'url': final, 'bytes': len(mirror.encode('utf-8')), 'hits': scan_ai_file(mirror),
                'first_line': mirror.strip().splitlines()[0][:200]}
    time.sleep(1)

    for path in ('/llms.txt', '/llms-full.txt', '/ai.txt', '/.well-known/llms.txt', '/.well-known/ai.txt'):
        st2, final2, ctype2, text = get(base + path)
        time.sleep(1)
        if st2 != 200 or not text.strip() or looks_html(ctype2, text) or not urlparse(final2).path.rstrip('/').endswith(path.rstrip('/')):
            continue
        name = path.strip('/')
        if any(a.get('text_hash') == hash(text) for a in r['ai_files'].values()):
            continue  # the same file served at a second address
        save_raw(domain, name.replace('/', '_'), text)
        r['ai_files'][name] = {'url': final2, 'bytes': len(text.encode('utf-8')), 'hits': scan_ai_file(text),
                               'first_line': text.strip().splitlines()[0][:200], 'text_hash': hash(text)}
    for a in r['ai_files'].values():
        a.pop('text_hash', None)

    st3, _f3, ctype3, robots = get(base + '/robots.txt')
    time.sleep(1)
    maps = []
    if st3 == 200 and not looks_html(ctype3, robots):
        save_raw(domain, 'robots.txt', robots)
        r['robots'] = scan_robots(robots)
        maps = r['robots']['sitemaps']

    if do_sitemaps and 200 <= st < 400:
        urls, nfiles = sitemap_urls(urlparse(base).hostname or domain, maps)
        located, top = doorway(urls)
        rep = sorted({u for u in urls if REPUTATION_SLUG.search(re.sub(r'^https?://[^/]+', '', u))})
        r['sitemap'] = {'files': nfiles, 'urls': len(urls), 'located': located, 'templates': top, 'reputation_pages': rep[:25]}
    score(r)
    return r


def score(r):
    f = {}
    for name, a in (r.get('ai_files') or {}).items():
        for key, w, label, _rx in LLMS_RULES:
            if a['hits'].get(key):
                f.setdefault(key, {'weight': w, 'label': label, 'where': []})['where'].append(name)
    h = r.get('home') or {}
    if 'hidden copy in home page' in (r.get('ai_files') or {}):
        f['hidden_mirror'] = {'weight': 4, 'label': 'llms.txt hidden inside the home page', 'where': ['home']}
    if h.get('exceed_html') and 'exceed' not in f:
        f['exceed'] = {'weight': 2, 'label': 'Made with the Exceed LLMS plugin', 'where': ['home']}
    if h.get('hidden_ai'):
        f['hidden_ai'] = {'weight': 8, 'label': 'Hidden text on the home page aimed at AI', 'where': ['home']}
    if h.get('comment_ai') or h.get('meta_ai'):
        f['comment_ai'] = {'weight': 4, 'label': 'AI instructions in HTML comments or meta tags', 'where': ['home']}
    if h.get('ratings'):
        f['ratings'] = {'weight': 1, 'label': 'Review stars in its own page markup', 'where': ['home']}
    sm = r.get('sitemap') or {}
    # A program with a page per city it does not operate in; a page per state can be real locations.
    city = sum(n for t, n in sm.get('templates') or [] if '<CITY>' in t)
    if city >= 20:
        f['doorway'] = {'weight': 3 if city >= 100 else 2, 'label': 'Near-identical city pages', 'where': ['sitemap']}
    if sm.get('reputation_pages'):
        f['reputation'] = {'weight': 2, 'label': 'Pages answering critics, reviews or lawsuits', 'where': ['sitemap']}
    if r.get('ai_files') and not any(k in f for k in ('suppress', 'critics', 'directive', 'wording', 'injection')):
        f['llms_plain'] = {'weight': 1, 'label': 'Publishes an AI file (nothing flagged in it)', 'where': list(r['ai_files'])}
    r['findings'] = f
    r['score'] = sum(x['weight'] for x in f.values())


def rescore(r):
    """Apply the current rules to the copies saved under raw/, so a rule change needs no refetch."""
    d = os.path.join(OUT, 'raw', r['domain'])

    def read(p):  # None when missing, or when antivirus has locked a saved page
        try:
            return open(p, encoding='utf-8').read()
        except OSError:
            return None
    for name, a in (r.get('ai_files') or {}).items():
        text = read(os.path.join(d, 'home-hidden-llms.txt' if name == 'hidden copy in home page' else name.replace('/', '_')))
        if text is not None:
            a['hits'] = scan_ai_file(text)
    text = read(os.path.join(d, 'home.html')) if r.get('home') else None
    if text is not None:
        h = scan_home(text)
        h.pop('mirror', None)
        r['home'] = h
    if r.get('sitemap'):
        r['sitemap']['reputation_pages'] = [u for u in r['sitemap'].get('reputation_pages', [])
                                            if REPUTATION_SLUG.search(re.sub(r'^https?://[^/]+', '', u))]
    if not r.get('error'):
        score(r)


# ---------------------------------------------------------------- report
def load_results():
    path = os.path.join(OUT, 'results.jsonl')
    res = {}
    if os.path.exists(path):
        for line in open(path, encoding='utf-8'):
            try:
                d = json.loads(line)
                res[d['domain']] = d
            except Exception:
                pass
    return res


def write_report(res):
    rows = sorted(res.values(), key=lambda r: (-r.get('score', 0), r['domain']))
    with open(os.path.join(OUT, 'report.csv'), 'w', newline='', encoding='utf-8') as fh:
        w = csv.writer(fh)
        w.writerow(['domain', 'score', 'findings', 'ai_files', 'exceed', 'ai_bots_blocked', 'sitemap_urls', 'located_urls',
                    'top_template', 'reputation_pages', 'web_credit', 'redirects_to', 'home_status', 'facilities'])
        for r in rows:
            sm = r.get('sitemap') or {}
            w.writerow([r['domain'], r.get('score', 0), '; '.join(x['label'] for x in r.get('findings', {}).values()),
                        ' '.join(r.get('ai_files', {})), 'yes' if 'exceed' in r.get('findings', {}) else '',
                        ' '.join((r.get('robots') or {}).get('ai_blocked', [])), sm.get('urls', ''), sm.get('located', ''),
                        (f"{sm['templates'][0][0]} x{sm['templates'][0][1]}" if sm.get('templates') else ''),
                        len(sm.get('reputation_pages', [])) or '', ', '.join((r.get('home') or {}).get('credits', [])),
                        r.get('redirects_to', ''), r.get('home_status', ''),
                        '; '.join(f"{f['name']} ({f['id']})" for f in r.get('facilities', []))])

    e = html.escape
    flagged = [r for r in rows if r.get('score', 0) > 0]
    counts = collections.Counter(k for r in rows for k in r.get('findings', {}))
    labels = {}
    for r in rows:
        for k, v in r.get('findings', {}).items():
            labels[k] = v['label']
    parts = [f'''<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Program Website Scan</title><style>
:root{{--ink:#000435;--muted:#4A5568;--line:#d8d4c4;--bg:#F2EEDF;--panel:#fff;--hl:#FFF5CB;--teal:#1d6f78}}
body{{margin:0;background:var(--bg);color:var(--ink);font:15px/1.5 Arial,sans-serif}}
main{{max-width:1100px;margin:0 auto;padding:16px}}h1{{margin:.2em 0}}.muted{{color:var(--muted)}}
.filters{{background:var(--panel);border:1px solid var(--line);border-radius:8px;padding:10px 14px;margin:12px 0;display:flex;flex-wrap:wrap;gap:8px 16px}}
.filters label{{white-space:nowrap}}input[type=search]{{padding:6px 8px;border:1px solid var(--line);border-radius:6px;min-width:220px}}
details{{background:var(--panel);border:1px solid var(--line);border-radius:8px;margin:8px 0;padding:8px 14px}}
summary{{cursor:pointer;font-weight:bold}}.score{{display:inline-block;min-width:2.2em;text-align:center;background:var(--ink);color:#fff;border-radius:4px;margin-right:8px}}
.tag{{display:inline-block;font-size:12px;font-weight:normal;border:1px solid var(--teal);color:var(--teal);border-radius:10px;padding:0 7px;margin:2px 3px}}
pre{{white-space:pre-wrap;background:var(--hl);padding:6px 10px;border-radius:6px;font:13px/1.45 Consolas,monospace;margin:4px 0}}
a{{color:#000080}}h3{{font-size:15px;margin:10px 0 4px}}ul{{margin:4px 0}}
</style></head><body><main>
<h1>Program website scan</h1>
<p class="muted">{len(rows)} websites scanned, {len(flagged)} with something flagged. Built {e(datetime.datetime.now().strftime("%B %d, %Y %H:%M"))} from tmp/seo-scan/results.jsonl.
Raw copies of every file quoted are in tmp/seo-scan/raw/&lt;domain&gt;/.</p>
<div class="filters"><input type="search" id="q" placeholder="Filter by domain or facility">''']
    for k, n in counts.most_common():
        parts.append(f'<label><input type="checkbox" class="f" value="{e(k)}"> {e(labels[k])} ({n})</label>')
    parts.append('<label><input type="checkbox" id="all"> Show sites with nothing flagged</label></div><div id="list">')
    for r in rows:
        fs = r.get('findings', {})
        keys = ' '.join(fs)
        fac = '; '.join(f['name'] for f in r.get('facilities', []))
        parts.append(f'<details data-keys="{e(keys)}" data-text="{e((r["domain"] + " " + fac + " " + r.get("label", "")).lower())}"'
                     f' data-score="{r.get("score", 0)}"><summary><span class="score">{r.get("score", 0)}</span>{e(r["domain"])}'
                     f'{" <span class=muted>(" + e(fac[:120]) + ")</span>" if fac else (" <span class=muted>(" + e(r.get("label", "")) + ")</span>" if r.get("label") else "")} '
                     + ''.join(f'<span class="tag">{e(v["label"])}</span>' for v in fs.values()) + '</summary>')
        parts.append(f'<p class="muted">Home page: {e(str(r.get("home_status")))} <a href="{e(r.get("home_final", ""))}">{e(r.get("home_final", ""))}</a>'
                     + (f' (redirects to {e(r["redirects_to"])})' if r.get('redirects_to') else '')
                     + (f' | error: {e(r["error"])}' if r.get('error') else '') + '</p>')
        for name, a in (r.get('ai_files') or {}).items():
            parts.append(f'<h3><a href="{e(a["url"])}">{e(name)}</a> <span class="muted">({a["bytes"]:,} bytes; first line: {e(a["first_line"])})</span></h3>')
            for key, _w, label, _rx in LLMS_RULES:
                for hit in a['hits'].get(key, []):
                    parts.append(f'<pre><b>{e(label)}</b>, line {hit["line"]}: {e(hit["text"])}</pre>')
        h = r.get('home') or {}
        for t in h.get('hidden_ai', []):
            parts.append(f'<pre><b>Hidden on the home page</b>: {e(t)}</pre>')
        for t in h.get('comment_ai', []) + h.get('meta_ai', []):
            parts.append(f'<pre><b>Comment/meta</b>: {e(t)}</pre>')
        extra = []
        if h.get('ratings'):
            extra.append('Review stars in its own markup: ' + ', '.join(f'{x["value"]} ({x["count"]})' for x in h['ratings'][:3]))
        if h.get('credits'):
            extra.append('Web credit: ' + ', '.join(h['credits']))
        if h.get('generator'):
            extra.append('Generator: ' + h['generator'])
        rb = r.get('robots') or {}
        if rb.get('ai_blocked'):
            extra.append('robots.txt blocks: ' + ', '.join(rb['ai_blocked']))
        if rb.get('ai_named_allowed'):
            extra.append('robots.txt names and allows: ' + ', '.join(rb['ai_named_allowed']))
        sm = r.get('sitemap') or {}
        if sm:
            extra.append(f'Sitemaps: {sm.get("urls", 0):,} pages in {sm.get("files", 0)} files, {sm.get("located", 0):,} name a state or city')
        if extra:
            parts.append('<ul>' + ''.join(f'<li>{e(x)}</li>' for x in extra) + '</ul>')
        if sm.get('templates'):
            parts.append('<p class="muted">Page patterns repeated per place:</p><ul>' +
                         ''.join(f'<li>{e(t)} &times; {n}</li>' for t, n in sm['templates']) + '</ul>')
        if sm.get('reputation_pages'):
            parts.append('<p class="muted">Pages about critics, reviews or lawsuits:</p><ul>' +
                         ''.join(f'<li><a href="{e(u)}">{e(u)}</a></li>' for u in sm['reputation_pages']) + '</ul>')
        if r.get('facilities'):
            parts.append('<p class="muted">Records: ' + ', '.join(
                f'{e(f["name"])} ({f["id"]})' for f in r['facilities'])
                + (f' and {r["facility_count"] - len(r["facilities"])} more' if r['facility_count'] > len(r['facilities']) else '') + '</p>')
        parts.append(f'<p class="muted">Listed from: {e(", ".join(r.get("sources", [])))}. Scanned {e(r.get("scanned_at", ""))}.</p></details>')
    parts.append('''</div></main><script>
const q=document.getElementById('q'),all=document.getElementById('all'),fs=[...document.querySelectorAll('.f')];
function apply(){const t=q.value.trim().toLowerCase(),on=fs.filter(f=>f.checked).map(f=>f.value);
for(const d of document.querySelectorAll('#list details')){const k=d.dataset.keys.split(' ');
let show=(all.checked||+d.dataset.score>0)&&(!t||d.dataset.text.includes(t))&&(!on.length||on.some(x=>k.includes(x)));
d.style.display=show?'':'none';}}
[q,all,...fs].forEach(x=>x.addEventListener('input',apply));apply();
</script></body></html>''')
    with open(os.path.join(OUT, 'report.html'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(parts))


# ---------------------------------------------------------------- main
def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--domains', nargs='+')
    ap.add_argument('--limit', type=int, default=0)
    ap.add_argument('--refresh', action='store_true')
    ap.add_argument('--no-sitemaps', action='store_true')
    ap.add_argument('--workers', type=int, default=6)
    ap.add_argument('--report', action='store_true')
    ap.add_argument('--list', action='store_true')
    a = ap.parse_args()
    os.makedirs(OUT, exist_ok=True)

    if a.report:
        res = load_results()
        for r in res.values():
            rescore(r)
        write_report(res)
        print(os.path.join(OUT, 'report.html'))
        return
    domains = collect_domains()
    if a.domains:
        for d in a.domains:
            h = host_of(d) or d.lower()
            domains[h]['sources'].add('command line')
        domains = {h: domains[h] for h in (host_of(d) or d.lower() for d in a.domains)}
    if a.list:
        for h, d in sorted(domains.items()):
            print(f"{h}\t{len(d['facilities'])} records\t{', '.join(sorted(d['sources']))}")
        print(f'{len(domains)} domains', file=sys.stderr)
        return

    res = load_results()
    todo = [h for h in sorted(domains) if a.refresh or a.domains or h not in res]
    if a.limit:
        todo = todo[:a.limit]
    print(f'{len(domains)} domains, {len(res)} already scanned, scanning {len(todo)}', flush=True)
    lock = threading.Lock()
    path = os.path.join(OUT, 'results.jsonl')
    done = 0
    with ThreadPoolExecutor(max_workers=a.workers) as ex:
        futs = {ex.submit(scan_domain, h, domains[h], not a.no_sitemaps): h for h in todo}
        for fut in as_completed(futs):
            h = futs[fut]
            try:
                r = fut.result()
            except Exception as err:
                r = {'domain': h, 'error': f'{type(err).__name__}: {err}', 'score': 0, 'findings': {},
                     'scanned_at': datetime.datetime.now().isoformat(timespec='seconds')}
            with lock:
                done += 1
                res[h] = r
                with open(path, 'a', encoding='utf-8') as fh:
                    fh.write(json.dumps(r) + '\n')
                tags = ', '.join(r.get('findings', {})) or ('error' if r.get('error') else '-')
                print(f'[{done}/{len(todo)}] {r.get("score", 0):3d} {h}  {tags}', flush=True)
    write_report(res)
    print('report:', os.path.join(OUT, 'report.html'))


if __name__ == '__main__':
    main()
