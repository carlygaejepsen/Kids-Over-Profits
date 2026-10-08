"""
Wiki update drafts written by script (docs/PLAN.md 3.12): every gap that has a fixed form becomes its added line here,
so models only write what needs judgement. Reads tmp/wiki-updates/drafts/<id>/{entry.md,gaps.json}
(scripts/wiki-pilot-prep.py --all-kinds) and writes, next to them:

  ops.json         the script's ops (by "script"), in the shape scripts/wiki-drafts.py assembles;
  model-gaps.json  the gaps left for the models: lawsuits, deaths, and news newer than the entry that reports an
                   event (a suit, a closure, an arrest, an investigation), each with its gid;
  excerpt.md       the entry's headings and the sections a model writes into (history, abuse/lawsuits), for a short read.

By script: staff ("**Name** was the Role of Program", their other industry roles linked to their r/troubledteens wiki
pages), news (a Related Media line in the entry's own "[Title](url) (Outlet, M/D/YYYY)" form), other and later names,
operators, a closure (header years and one sentence), approved serious findings ("In a report dated ..., <State>
inspectors found that ..." with the state's own words). A fact whose only source is KOP's record carries no link
and "kop_record": true; one from a Woodbury issue or a court filing cites it.

    python scripts/wiki-script-drafts.py <ids...>
"""
import csv
import json
import os
import re
import sys
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRAFTS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
sys.path.insert(0, os.path.join(ROOT, 'scripts'))
import importlib.util  # noqa: E402

_spec = importlib.util.spec_from_file_location('wiki_drafts', os.path.join(ROOT, 'scripts', 'wiki-drafts.py'))
wd = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(wd)

TITLES = json.load(open(os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'page-urls.json'), encoding='utf-8'))['titles']
TITLE_KEYS = {re.sub(r'[^a-z0-9]+', ' ', t.lower()).strip(): u for t, u in TITLES.items()}
# Pages whose wiki title is not the company's name.
TITLE_KEYS.update({
    'wwasp': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
    'wwasps': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
    'world wide association of specialty programs and schools': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
})
MODEL_NEWS = {'lawsuit', 'closure', 'arrest', 'expose', 'event'}
STATES = {'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas', 'CA': 'California', 'CO': 'Colorado',
          'CT': 'Connecticut', 'DE': 'Delaware', 'FL': 'Florida', 'GA': 'Georgia', 'HI': 'Hawaii', 'ID': 'Idaho',
          'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa', 'KS': 'Kansas', 'KY': 'Kentucky', 'LA': 'Louisiana',
          'ME': 'Maine', 'MD': 'Maryland', 'MA': 'Massachusetts', 'MI': 'Michigan', 'MN': 'Minnesota',
          'MS': 'Mississippi', 'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada',
          'NH': 'New Hampshire', 'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York', 'NC': 'North Carolina',
          'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma', 'OR': 'Oregon', 'PA': 'Pennsylvania',
          'RI': 'Rhode Island', 'SC': 'South Carolina', 'SD': 'South Dakota', 'TN': 'Tennessee', 'TX': 'Texas',
          'UT': 'Utah', 'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington', 'WV': 'West Virginia',
          'WI': 'Wisconsin', 'WY': 'Wyoming'}


def clean_name(name):
    """The entry's name without what the Reddit conversion left on it ("The Bethesda Home for Girls**()")."""
    return re.sub(r'\s+', ' ', re.sub(r'\(\s*\)|\*+', '', name or '')).strip()


def wiki_link(name, own=''):
    """[Name](its wiki page) when the wiki has a page of that title (not the entry's own), else the name."""
    k = re.sub(r'[^a-z0-9]+', ' ', name.lower()).strip()
    short = re.sub(r'[^a-z0-9]+', ' ', re.sub(r'\s*\([^)]*\)\s*$', '', name).lower()).strip()
    abbr = re.search(r'\(([^)]+)\)\s*$', name)
    url = TITLE_KEYS.get(k) or TITLE_KEYS.get(re.sub(r'^the ', '', k)) or TITLE_KEYS.get(short) \
        or (TITLE_KEYS.get(re.sub(r'[^a-z0-9]+', ' ', abbr.group(1).lower()).strip()) if abbr else None)
    if not url or k == re.sub(r'[^a-z0-9]+', ' ', own.lower()).strip():
        return name
    return f'[{name}]({url})'


def fix_url(u):
    """Sources stored as site-relative paths came out under kidsoverprofits.org: put them back where they live. A HEAL
    copy from 2023 on (the squatter's domain) goes to HEAL's own capture (js/data/reddit-wiki/heal-archive-urls.json)."""
    u = re.sub(r'^https?://(www\.)?kidsoverprofits\.org/(r|u|user)/', r'https://www.reddit.com/\2/', u)
    u = re.sub(r'^https?://(www\.)?kidsoverprofits\.org/web/', 'https://web.archive.org/web/', u)
    m = re.match(r'https://web\.archive\.org/web/(\d+)\w*/(https?://(www\.)?heal-online\.org\S*)', u)
    if m and m.group(1) >= '2023':
        u = wd.heal_archive().get(wd.heal_key(m.group(2)), u)
    return u


def cite(g):
    """An outside citation for a gap, '' for KOP's own record."""
    if g.get('kop_source'):
        return ''
    if g.get('source_url'):
        url = fix_url(g['source_url'])
        label = g.get('source_label') or 'source'
        if url != g['source_url'] and 'heal-online' in url:
            label = re.sub(r'\s*\(archived [^)]*\)', '', label)   # the capture date it named is not the one linked now
        return f' ([{label}]({url}))'
    ct = (g.get('detail') or {}).get('cite_text')
    link = cite_link(ct) if ct else ''
    if link:
        return f' ([source]({link}))'
    return f' ({ct})' if ct else ''


MONTHS = ['january', 'february', 'march', 'april', 'may', 'june', 'july', 'august', 'september', 'october', 'november', 'december']
_LIB = None


def cite_link(ct):
    """The link for a citation the record holds only as words: a Woodbury Reports issue page -> our copy of the issue
    in the media library (#page=N); a HEAL page -> HEAL's archived copy (tmp/heal/issues.json, through fix_url); an
    r/troubledteens wiki page title -> that page. '' when none is known."""
    global _LIB
    if _LIB is None:
        _LIB = {'wb': {}, 'heal': {}}
        try:
            import sqlite3
            con = sqlite3.connect(os.path.join(ROOT, 'tmp', 'prod.sqlite'))
            for (g,) in con.execute("SELECT guid FROM wpdl_posts WHERE post_type='attachment' AND guid LIKE '%woodbury-%.pdf'"):
                _LIB['wb'][os.path.basename(g).lower()] = re.sub(r'kidsoverprofits\.org/staging/', 'kidsoverprofits.org/', g)
        except Exception:
            pass
        p = os.path.join(ROOT, 'tmp', 'heal', 'issues.json')
        if os.path.exists(p):
            for it in json.load(open(p, encoding='utf-8')):
                if isinstance(it, dict) and it.get('label') and it.get('url'):
                    _LIB['heal'][it['label'].lower()] = it['url']
    m = re.match(r'Woodbury Reports, (\w+) (\d{4})[^,]*, p\. ?(\d+)', ct)
    if m and m.group(1).lower() in MONTHS:
        u = _LIB['wb'].get(f'woodbury-{MONTHS.index(m.group(1).lower()) + 1:02d}{m.group(2)[2:]}.pdf')
        return f'{u}#page={m.group(3)}' if u else ''
    m = re.match(r'HEAL, (.+?)\s*(?:\(archived [^)]*\))?$', ct)
    if m:
        u = _LIB['heal'].get(m.group(1).strip().lower())
        return fix_url(u) if u else ''
    m = re.search(r'troubledteens wiki, page "([^"]+)"', ct)
    if m:
        return TITLES.get(m.group(1), '')
    return ''


def mdy(d):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', d or '')
    return f'{int(m.group(2))}/{int(m.group(3))}/{m.group(1)}' if m else (d or '')


def long_date(d):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', d or '')
    if not m:
        return d
    dt = date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
    return f'{dt.strftime("%B")} {dt.day}, {dt.year}'


def section(lines, *names):
    for n in names:
        s = wd.find(lines, n)
        if s:
            return lines[s[0]].strip()
    return ''


def heading_text(h):
    return re.sub(r'[#*]+', '', h).strip()


def staff_places():
    """name (lower case) -> the programs the network map's staff list puts the person at."""
    out = {}
    path = os.path.join(ROOT, 'js', 'data', 'network', 'staff-list.csv')
    if os.path.exists(path):
        for row in csv.reader(open(path, encoding='utf-8')):
            if len(row) > 1:
                out.setdefault(row[0].strip().lower(), []).append(row[1].strip())
    return out


STAFF_PLACES = staff_places()


def record_names(record_id):
    """The record's past and other names (tmp/prod.sqlite), without their years."""
    try:
        import sqlite3
        con = sqlite3.connect(os.path.join(ROOT, 'tmp', 'prod.sqlite'))
        row = con.execute('SELECT json_data FROM facilities_v2 WHERE id = ?', (record_id,)).fetchone()
        ident = json.loads(row[0]).get('identification') or {}
    except Exception:
        return []
    names = (ident.get('pastNames') or []) + (ident.get('otherNames') or [])
    return [re.sub(r'\s*\(\d{4}.*$', '', n).strip() for n in names if isinstance(n, str) and n.strip()]


def clean_role(role):
    """'Admissions Director, Admissions' -> 'Admissions Director'. A part is dropped when another part already holds its
    words, is spelled out by it ('CEO' beside 'Chief Executive Officer') or differs from it by a letter or two ('Principle'
    beside 'Principal'); notes in brackets, 'Former' and NATSAP board seats go."""
    role = re.sub(r'\bPrinciple\b', 'Principal', re.sub(r'\s*\([^)]*\)', '', role))
    parts = [re.sub(r'(?i)^former\s+', '', p.strip()) for p in role.split(',')]
    parts = [p for p in parts if p and 'natsap' not in p.lower()]
    words = lambda p: set(re.findall(r'[a-z]+', p.lower().replace('admission ', 'admissions '))) - {'of', 'and', 'the'}
    key = lambda p: re.sub(r'[^a-z]', '', p.lower())
    initials = lambda p: ''.join(w[0] for w in re.findall(r'[A-Za-z]+', p) if w.lower() not in ('of', 'and', 'the')).lower()

    def near(a, b):
        a, b = a.lower(), b.lower()
        if abs(len(a) - len(b)) > 2 or min(len(a), len(b)) < 6:
            return False
        import difflib
        return difflib.SequenceMatcher(None, a, b).ratio() >= 0.85

    keep = []
    for i, p in enumerate(parts):
        drop = False
        for j, q in enumerate(parts):
            if i == j:
                continue
            same = words(p) == words(q) or near(p, q)
            if (same and j < i) or (not same and (words(p) < words(q) or key(p) in key(q))) or (len(p) <= 5 and p.lower() == initials(q)):
                drop = True
                break
        if not drop:
            keep.append(p)
    return ', '.join(keep) or role


def staff_line(g, program, closed, earlier_names=()):
    """One staff line. Always "was": KOP's lists do not say whether someone still holds the job. A record that holds an
    earlier name keeps that name's staff too, so when the network map's staff list puts the person at one of the record's
    earlier names and not at the entry's program (Dan Dekker at Integrity House, on the Havenwood entry), the line names
    the earlier program."""
    d = g.get('detail') or {}
    name = re.sub(r'\s*\(.*\)$', '', g['text']).strip()
    places = STAFF_PLACES.get(name.lower(), [])
    if places and program.lower() not in [p.lower() for p in places]:
        earlier = [p for p in places if p.lower() in [n.lower() for n in earlier_names]]
        if earlier:
            program = earlier[0]
    role = re.sub(r'\s*\((\d{4}).*$', '', (d.get('role') or '').strip())
    role = clean_role(role) if role else ''
    years = re.search(r'\((\d{4}(?:-\d{4})?)', d.get('role') or '')
    verb = 'was'
    art = '' if re.match(r'(?i)(the|a|an)\b', role) else wd.role_article(role) + ' '
    if not role:
        # Named on the record's staff list with no role: that they worked there is the fact.
        s = f'**{name}** worked at {program}' + (f' in {years.group(1)}' if years else '') + cite(g) + '.'
    elif re.match(r'(?i)(helped found|co-?founded|founded)$', role):
        s = f'**{name}** {role[0].lower() + role[1:]} {program}' + (f' in {years.group(1)}' if years else '') + cite(g) + '.'
    else:
        s = f'**{name}** {verb} {art}{role} of {program}' + (f' in {years.group(1)}' if years else '') + cite(g) + '.'
    s = s.replace(').', ').').replace(' .', '.').replace('..', '.')
    others = []
    for r in d.get('other_roles') or []:
        role2, _, place = r.rpartition(', ')
        if not role2:
            continue
        place = re.sub(r'\s*\(\d{4}.*$', '', place).strip()
        others.append(f'{role2} of {wiki_link(place, program)}')
    if others:
        s += f' {name.split()[-1]} was also ' + (', '.join(others[:-1]) + ' and ' + others[-1] if len(others) > 1 else others[0]) + '.'
    return s


def target_sections(lines):
    """The entry's own History, Staff, Abuse and Related Media headings, recognised as the wiki editor's template
    recognises them (wd.TEMPLATE); a section the entry lacks gets the template's name, which templated() adds before
    any addition to it. Nothing is dropped for want of a section."""
    secs = wd.sections(lines)

    def pick(canon):
        test = next(t for c, t, _ in wd.TEMPLATE if c == canon)
        s = next((s for s in secs if test(s[2])), None)
        return lines[s[0]].strip() if s else f'## **{canon}**'
    return (pick('History and Background Information'), pick('Founders and Notable Staff'),
            pick('Abuse/Neglect Allegations and Lawsuits'), pick('Related Media'))


def sentence(text):
    t = re.sub(r'\s+', ' ', text or '').strip()
    return t if not t or re.search(r'[.!?]["”)]?$', t) else t + '.'


def with_cite(text, g):
    """The sentence with its citation before the final stop; a fact from KOP's own record has none."""
    t = sentence(text)
    c = cite(g)
    return (t[:-1] + c + t[-1]) if c and t else t


def incident_line(g):
    """One of the record's incidents as its own paragraph: the record's words, dated when they are not."""
    d = g.get('detail') or {}
    what = (d.get('what') or g['text'].split(': ', 1)[-1]).strip()
    when = (d.get('when') or '').strip()
    year = re.search(r'\d{4}', when)
    if when and not (year and year.group(0) in what) and when.lower() not in what.lower():
        lead = re.sub(r'^Reported ', 'Reported in ', when) if when.lower().startswith('reported') \
            else ('On ' if re.search(r'\b\d{1,2},? \d{4}$', when) else 'In ') + when
        first = what.split(' ', 1)[0]
        # Lower-case only a common opening word ("A survivor", "Staff"), never a name ("Louis", "Mother Jones").
        if first.lower() in ('a', 'an', 'the', 'survivors', 'survivor', 'staff', 'residents', 'students', 'boys', 'girls',
                             'parents', 'former', 'two', 'three', 'several', 'one', 'another', 'his', 'her', 'their'):
            what = what[:1].lower() + what[1:]
        what = f'{lead}, {what}'
    return with_cite(what, g)


def lawsuit_line(g):
    """A case on the record, as the wiki editor writes one with no structured parties (buildLawsuitSentence()): its
    summary as the sentence, the case named after it."""
    d = g.get('detail') or {}
    case = (g.get('text') or '').strip()
    court = (d.get('court') or '').strip()
    num = (d.get('case_number') or '').strip()
    named = f'*{case}*' + (f' ({", ".join(x for x in (court, ("No. " + num) if num else "") if x)})' if court or num else '')
    summary = sentence(d.get('summary') or '')
    year = (g.get('date') or '').strip()
    if summary:
        text = f'{summary} {named}.'
    else:
        text = f'In {year}, a lawsuit was filed: {named}.' if year else f'A lawsuit was filed: {named}.'
    outcome = (d.get('outcome') or '').strip()
    if outcome and outcome.lower() not in text.lower():
        text += f' Outcome: {sentence(outcome)}'
    return with_cite(text, g)


def death_line(g):
    return with_cite(g['text'], g)


def add_new(ids):
    """Every gap the entry's draft does not hold yet (tmp/wiki-updates/gaps/<id>.json from scripts/wiki-gaps.php,
    through wiki-pilot-prep.trimmed()) is added to its gaps.json and written by script into ops.json as ops "n<k>",
    after the ops already there, which are left as they are. Owner, 2026-10-08: every fact on KOP's record goes in,
    cited or not; lawsuits, deaths and incidents are written here too, in the editor's sentence forms."""
    import importlib.util as iu
    spec = iu.spec_from_file_location('prep', os.path.join(ROOT, 'scripts', 'wiki-pilot-prep.py'))
    prep = iu.module_from_spec(spec)
    spec.loader.exec_module(prep)

    def key(g):
        k = {'staff_other': 'staff', 'news_mention': 'news'}.get(g['kind'], g['kind'])
        return (k, g.get('source_url') if k == 'news' else re.sub(r'\W+', ' ', g['text'].lower()).strip())

    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        gpath = os.path.join(ROOT, 'tmp', 'wiki-updates', 'gaps', f'{i}.json')
        if not os.path.exists(gpath):
            print(f'{i}: no gaps file')
            continue
        gaps_obj = json.load(open(os.path.join(folder, 'gaps.json'), encoding='utf-8'))
        ops_obj = json.load(open(os.path.join(folder, 'ops.json'), encoding='utf-8'))
        have = {key(g) for g in gaps_obj['gaps']}
        current = prep.trimmed(json.load(open(gpath, encoding='utf-8'))['gaps'])
        fresh = [g for g in current if key(g) not in have and not g.get('conflict')]
        # A script incident whose gap the record no longer gives (read from this entry's own wiki page: the entry's own
        # content) is taken out; one whose source changed (another wiki page, now linked) is written again.
        now = {key(g): g for g in current}
        by_gid = {g['gid']: g for g in gaps_obj['gaps']}
        changed = False
        for o in ops_obj['ops']:
            gs = [by_gid.get(x) for x in o.get('gids', [])]
            if o.get('by') != 'script' or o.get('verdict') == 'dropped' or not gs or not all(g and g['kind'] == 'incident' for g in gs):
                continue
            if all(key(g) not in now for g in gs):
                o['verdict'] = 'dropped'
                o['note'] = "Read from this entry's own wiki page: already the entry's content, not added back."
                changed = True
            elif len(gs) == 1 and now[key(gs[0])].get('source_url') != gs[0].get('source_url'):
                g = dict(gs[0], source_url=now[key(gs[0])]['source_url'], source_label=now[key(gs[0])]['source_label'],
                         kop_source=now[key(gs[0])].get('kop_source'), detail=now[key(gs[0])].get('detail'))
                by_gid[g['gid']].update(g)
                o['text'] = incident_line(g)
                o.pop('kop_record', None)
                o.pop('text_cited', None)
                if not cite(g):
                    o['kop_record'] = True
                elif not g.get('source_url'):
                    o['text_cited'] = True   # a source named in words, no link (HEAL, Mother Jones, 2007)
                changed = True
        # A gap already in the draft whose ops were all dropped, or that no op ever wrote, is written now too, unless it
        # was dropped as already on the page, the same person twice, or a case/death KOP's own records place at another
        # program; a placeholder operator ("RELOCATED") is not a company.
        live = {x for o in ops_obj['ops'] if o.get('verdict') != 'dropped' for x in o.get('gids', [])}
        draft_path = os.path.join(folder, 'draft.md')
        drafted = open(draft_path, encoding='utf-8').read() if os.path.exists(draft_path) else ''
        keep_out = re.compile(r'already (on the page|describes)|excerpt already|same person|different program|not olympus|'
                              r'over the wwasp|this entry is the|doubtful kop data', re.I)
        for g in gaps_obj['gaps']:
            if g.get('conflict') or g['gid'] in live or key(g) not in now:   # only what the record still gives
                continue
            if g['kind'] == 'news' and g.get('source_url') and g['source_url'] in drafted:
                continue
            if g['kind'] == 'operator' and re.fullmatch(r'(?i)\W*(unknown|relocated|closed|n/?a|none|tbd|\?)\W*', (g.get('detail') or {}).get('operator', '')):
                continue
            notes = ' '.join(o.get('note') or '' for o in ops_obj['ops'] if g['gid'] in o.get('gids', []))
            if g['kind'] != 'news' and keep_out.search(notes):   # an article on the record is always listed
                continue
            fresh.append(dict(g, _orphan=True))
        if not fresh:
            if changed:
                json.dump(gaps_obj, open(os.path.join(folder, 'gaps.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
                json.dump(ops_obj, open(os.path.join(folder, 'ops.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
                print(f'{i}: incidents from its own wiki page taken out / re-cited')
            else:
                print(f'{i}: nothing new')
            continue
        top = max([int(g['gid'][1:]) for g in gaps_obj['gaps'] if re.fullmatch(r'g\d+', g.get('gid', ''))] + [0])
        k = 0
        for g in fresh:
            if not g.get('_orphan'):
                k += 1
                g['gid'] = f'g{top + k}'
        lines = open(os.path.join(folder, 'entry.md'), encoding='utf-8').read().replace('\r\n', '\n').split('\n')
        program = clean_name(gaps_obj['entry']['program_name'])
        rec = gaps_obj.get('record', {})
        earlier_names = record_names(rec.get('id'))
        closed = rec.get('status') == 'Closed' or not re.search(r'present', gaps_obj['entry'].get('years') or '', re.I)
        hist, staff_sec, abuse_sec, media_sec = target_sections(lines)
        taken = {o['id'] for o in ops_obj['ops']}
        n = 0
        new_ops = []

        def op(sec, text, gs, **kw):
            nonlocal n
            n += 1
            while f'n{n}' in taken:
                n += 1
            o = {'id': f'n{n}', 'by': 'script', 'op': 'append_to_section', 'section': heading_text(sec), 'text': text,
                 'gids': [g['gid'] for g in gs], 'verdict': 'ok',
                 'note': "From KOP's record (added 2026-10-08: every fact on the record goes in)."}
            # No outside source to cite (KOP's page, or nothing at all, as a memorial entry marked "Unconfirmed"): the fact
            # stands on KOP's own record.
            if all(g.get('kop_source') or not cite(g) for g in gs):
                o['kop_record'] = True
            elif all(not g.get('source_url') for g in gs):
                o['text_cited'] = True   # a source named in words, no link
            o.update(kw)
            new_ops.append(o)

        for g in fresh:
            d = g.get('detail') or {}
            kind = g['kind']
            if kind == 'staff':
                op(staff_sec, staff_line(g, program, closed, earlier_names), [g])
            elif kind == 'incident':
                op(abuse_sec, incident_line(g), [g])
            elif kind == 'lawsuit':
                op(abuse_sec, lawsuit_line(g), [g])
            elif kind == 'death':
                op(abuse_sec, death_line(g), [g])
            elif kind == 'finding':
                st = STATES.get((re.search(r', ([A-Z]{2})\b', gaps_obj['entry'].get('place') or '') or [None, ''])[1], '')
                ex = re.sub(r'\s+', ' ', d.get('excerpt') or g['text'].split(': ', 1)[-1]).strip().rstrip('.').replace('"', "'")
                op(abuse_sec, f'In a report dated {long_date(g.get("date"))}, {st + " inspectors" if st else "state inspectors"} found: "{ex}."{cite(g)}', [g])
            elif kind == 'name':
                nm = d.get('name', '')
                op(hist, with_cite(f'{program} later operated as {wiki_link(nm, program)}' if d.get('how') == 'later'
                                   else f'{program} has also been known as {nm}' if d.get('how') in ('also', 'aka')
                                   else f'{program} was formerly called {nm}', g), [g])
            elif kind == 'operator':
                op(hist, with_cite(f'{program} {"was" if closed else "is"} operated by {wiki_link(d.get("operator", ""), program)}', g), [g])
            elif kind == 'closure':
                end = str(d.get('end_year') or '')
                op(hist, with_cite(f'{program} closed in {end}' if re.fullmatch(r'\d{4}', end) else f'{program} has closed', g), [g])
            elif kind == 'news':
                op(media_sec, f'[{g["text"]}]({fix_url(g["source_url"])}) ({g.get("source_label") or "news"}, {mdy(g.get("date"))})', [g])
        gaps_obj['gaps'] += [g for g in fresh if not g.get('_orphan')]
        ops_obj['ops'] += new_ops
        json.dump(gaps_obj, open(os.path.join(folder, 'gaps.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
        json.dump(ops_obj, open(os.path.join(folder, 'ops.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
        kinds = {}
        for g in fresh:
            kinds[g['kind']] = kinds.get(g['kind'], 0) + 1
        print(f'{i} {program}: {len(new_ops)} ops added {kinds}')


def main(ids):
    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        entry_md = open(os.path.join(folder, 'entry.md'), encoding='utf-8').read().replace('\r\n', '\n')
        lines = entry_md.split('\n')
        gaps = json.load(open(os.path.join(folder, 'gaps.json'), encoding='utf-8'))
        program = clean_name(gaps['entry']['program_name'])
        rec = gaps.get('record', {})
        earlier_names = record_names(rec.get('id'))
        closed = rec.get('status') == 'Closed' or not re.search(r'present', gaps['entry'].get('years') or '', re.I) \
            or any(g['kind'] == 'closure' and not g['conflict'] for g in gaps['gaps'])
        hist, staff_sec, abuse_sec, media_sec = target_sections(lines)
        ops, model, n = [], [], 0

        def op(sec, text, gids, kop=False, **kw):
            nonlocal n
            n += 1
            o = {'id': f'c{n}', 'by': 'script', 'op': 'append_to_section', 'section': heading_text(sec), 'text': text,
                 'gids': gids, 'verdict': 'ok', 'note': 'Written by script from the gap.'}
            if kop:
                o['kop_record'] = True
            o.update(kw)
            ops.append(o)

        live = [g for g in gaps['gaps'] if not g.get('conflict') and not g.get('needs_source')]
        # History: names, operator, closure.
        hist_lines, hist_gids, hist_kop = [], [], True
        for g in live:
            d = g.get('detail') or {}
            if g['kind'] == 'name':
                names = d.get('name', '')
                hist_lines.append(f'{program} later operated as {wiki_link(names, program)}.' if d.get('how') == 'later'
                                  else f'{program} has also been known as {names}.' if d.get('how') == 'also'
                                  else f'{program} was formerly called {names}.')
            elif g['kind'] == 'operator':
                if not re.search(r'[a-z]', d.get('operator', '')) or re.fullmatch(r'(?i)\W*(unknown|relocated|closed|n/?a|none|tbd|\?)\W*', d.get('operator', '')):
                    continue  # a placeholder in the record ("RELOCATED", "Unknown"), not a company
                hist_lines.append(f'{program} {"was" if closed else "is"} operated by {wiki_link(d.get("operator", ""), program)}.')
            elif g['kind'] == 'closure':
                end = str(d.get('end_year') or '')
                if not re.fullmatch(r'\d{4}', end):
                    # The record says Closed with no year: say so, and leave the header's years alone.
                    hist_lines.append(f'{program} has closed{cite(g)}.')
                    hist_gids.append(g['gid'])
                    hist_kop = hist_kop and bool(g.get('kop_source'))
                    continue
                hist_lines.append(f'{program} closed in {end}{cite(g)}.')
                start = (gaps['entry'].get('years') or '').split('-')[0]
                if start:
                    ops.append({'id': 'c0', 'by': 'script', 'op': 'set_header_years', 'years': f'{start}-{d.get("end_year")}',
                                'gids': [g['gid']], 'verdict': 'ok', 'note': 'Closing year from the record.'})
            else:
                continue
            hist_gids.append(g['gid'])
            hist_kop = hist_kop and bool(g.get('kop_source'))
        if hist_lines and hist:
            # The program's name once, then "It".
            hist_lines = [hist_lines[0]] + [re.sub('^' + re.escape(program) + r'\b', 'It', l) for l in hist_lines[1:]]
            op(hist, ' '.join(hist_lines), hist_gids, kop=hist_kop)
        # Staff.
        for g in [g for g in live if g['kind'] in ('staff', 'staff_other')]:
            op(staff_sec, staff_line(g, program, closed, earlier_names), [g['gid']], kop=bool(g.get('kop_source')))
        # The record's incidents, each its own paragraph.
        for g in sorted([g for g in live if g['kind'] == 'incident'], key=lambda g: g.get('date') or ''):
            op(abuse_sec, incident_line(g), [g['gid']], kop=bool(g.get('kop_source')))
        # Findings, under one subsection.
        finds = sorted([g for g in live if g['kind'] == 'finding'], key=lambda g: g.get('date') or '')
        if finds:
            st = STATES.get((re.search(r', ([A-Z]{2})\b', gaps['entry'].get('place') or '') or [None, ''])[1], '')
            body = []
            for g in finds:
                d = g.get('detail') or {}
                ex = re.sub(r'\s+', ' ', d.get('excerpt') or g['text'].split(': ', 1)[-1]).strip().rstrip('.').replace('"', "'")
                who = f'{st} inspectors' if st else 'state inspectors'
                body.append(f'In a report dated {long_date(g.get("date"))}, {who} found: "{ex}."{cite(g)}')
            op(abuse_sec, '### **State Inspection Findings**\n\n' + '\n\n'.join(body), [g['gid'] for g in finds])
        # News: every article in Related Media; event news also goes to the models.
        news = sorted([g for g in live if g['kind'] == 'news'], key=lambda g: g.get('date') or '')
        if news:
            op(media_sec, '\n\n'.join(f'[{g["text"]}]({fix_url(g["source_url"])}) ({g.get("source_label") or "news"}, {mdy(g.get("date"))})'
                                      for g in news), [g['gid'] for g in news])
        for g in live:
            d = g.get('detail') or {}
            if g['kind'] in ('lawsuit', 'death') or (g['kind'] == 'news' and d.get('newer_than_entry') and d.get('type') in MODEL_NEWS):
                model.append(g)
        json.dump({'entry_id': i, 'ops': ops}, open(os.path.join(folder, 'ops.json'), 'w', encoding='utf-8', newline='\n'),
                  ensure_ascii=False, indent=1)
        json.dump({'entry_id': i, 'program': program, 'closed': closed, 'gaps': model},
                  open(os.path.join(folder, 'model-gaps.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
        # The excerpt models read instead of the whole entry: every heading, and the sections they write into.
        keep = set()
        for name in (hist, abuse_sec):
            s = wd.find(lines, heading_text(name)) if name else None
            if s:
                keep.update(range(s[0], s[1]))
        ex = [l for k, l in enumerate(lines) if k in keep or re.match(r'\s*#{1,6}\s', l)]
        open(os.path.join(folder, 'excerpt.md'), 'w', encoding='utf-8', newline='\n').write('\n'.join(ex) + '\n')
        print(f'{i} {program}: {len(ops)} script ops, {len(model)} gaps for the models, closed={closed}')


if __name__ == '__main__':
    if sys.argv[1:2] == ['add-new']:
        add_new([int(x) for x in sys.argv[2:]] or sorted(int(d) for d in os.listdir(DRAFTS) if d.isdigit()))
    else:
        main([int(x) for x in sys.argv[1:]])
