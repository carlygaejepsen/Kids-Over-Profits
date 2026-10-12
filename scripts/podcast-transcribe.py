"""Transcribe a whole podcast series with local Whisper, primed with the names it would otherwise mishear.

Every episode is transcribed with a prompt (Whisper's initial_prompt, carried into every 30-second window) built
from the episode's own title and description plus the names KOP holds that the feed mentions: facilities and their
past names, companies, industry people, map organisations, glossary terms, and the hand lists in
scripts/podcast-vocab/ (common.json + <show>.json). After transcription the corrections in those files fix the
spellings Whisper still gets wrong; the raw text is kept, so 'fix' can re-apply them after the lists grow.

Everything goes to tmp/podcasts/<show>/ (never committed: the transcripts are the show's copyrighted work).
Audio is downloaded one episode at a time and deleted once transcribed (--keep-audio keeps it).

  py -3.13 scripts/podcast-transcribe.py feed      [--show sftf]       # fetch the feed -> episodes.json, the show's own
                                                                        # transcripts -> official/ (their names prime those episodes)
  py -3.13 scripts/podcast-transcribe.py vocab     [--show sftf] [--print 221]   # build prompts -> vocab.json + vocab-report.md
  py -3.13 scripts/podcast-transcribe.py run       [--show sftf] [--model small] [--limit N] [--episodes 221 300] [--oldest-first]
  py -3.13 scripts/podcast-transcribe.py fix       [--show sftf]       # re-apply corrections to every transcript
  py -3.13 scripts/podcast-transcribe.py suspects  [--show sftf]       # names that look misheard -> suspects.md
  py -3.13 scripts/podcast-transcribe.py status    [--show sftf]

Resumable: 'run' skips episodes already transcribed. Stops (exit 3) when C: has less than --min-free-gb left.
"""
import argparse
import ctypes
import html
import json
import os
import re
import shutil
import sqlite3
import sys
import time
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
VOCAB_DIR = ROOT / 'scripts' / 'podcast-vocab'
MIRROR = ROOT / 'tmp' / 'prod.sqlite'
GRAPH = ROOT / 'js' / 'data' / 'network' / 'graph.json'
UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36'
ITUNES = '{http://www.itunes.com/dtds/podcast-1.0.dtd}'

SHOWS = {
    'sftf': {
        'title': 'Stories from the Field',
        'feed': 'https://storiesfromthefield.libsyn.com/rss',
        'host': 'Will White',
        'about': 'wilderness therapy and outdoor behavioral health',
        # the show's own transcripts (Squarespace blog; 114, 115 and 116 as of 2026-10): their names prime those episodes
        'official': 'https://storiesfromthefield.com/blog-and-show-transcripts?format=json',
    },
}

PROMPT_TOKENS = 150  # Whisper keeps 223 prompt tokens; the rest is left for the previous window's text

# Capitalised words that start sentences or are just ordinary words, never names worth priming
COMMON = set('''
a an and are as at be but by can do does for from has have he her his how i if in into is it its join joins
me my not of on or our out she so that the their them then there these they this those to up was we were what
when where which who why will with you your about after again also all any because before being both
during each few further here just more most new now once only other over own same some such than too under
until very while episode podcast today listen listeners discuss discusses discussed talk talks conversation
interview interviews share shares shared story stories field dr phd ph ma lcsw lpc lmft msw ceo founder director
executive clinical therapy therapist therapeutic wilderness adventure outdoor outdoors mental health program
programs school schools treatment youth young adults adult teens teen families family parents parent
january february march april may june july august september october november december
monday tuesday wednesday thursday friday saturday sunday part one two three four five
'''.split())


# ---------------------------------------------------------------- paths and small helpers

def show_dir(show):
    d = ROOT / 'tmp' / 'podcasts' / show
    d.mkdir(parents=True, exist_ok=True)
    return d


def write_json(path, data):
    tmp = path.with_suffix(path.suffix + '.tmp')
    tmp.write_text(json.dumps(data, ensure_ascii=False, indent=1), encoding='utf-8')
    os.replace(tmp, path)


def read_json(path, default=None):
    try:
        return json.loads(Path(path).read_text(encoding='utf-8'))
    except (OSError, ValueError):
        return default


def strip_html(s):
    s = re.sub(r'<br\s*/?>|</p>|</li>', '\n', s or '', flags=re.I)
    s = re.sub(r'<[^>]+>', ' ', s)
    s = html.unescape(s).replace('\xa0', ' ')
    return re.sub(r'[ \t]+', ' ', s).strip()


def slugify(s, n=60):
    s = re.sub(r'[^a-z0-9]+', '-', s.lower()).strip('-')
    return s[:n].rstrip('-') or 'episode'


def fmt_ts(seconds):
    seconds = int(seconds)
    return f'{seconds // 3600}:{seconds % 3600 // 60:02d}:{seconds % 60:02d}'


def fmt_dur(seconds):
    h, m = divmod(int(seconds) // 60, 60)
    return f'{h}h {m:02d}m' if h else f'{m}m'


def low_priority():
    """Below-normal CPU priority so the PC stays usable while Whisper runs for days."""
    if os.name == 'nt':
        try:
            from ctypes import wintypes
            k32 = ctypes.windll.kernel32
            k32.GetCurrentProcess.restype = wintypes.HANDLE  # the default int truncates the 64-bit pseudo-handle
            k32.SetPriorityClass.argtypes = (wintypes.HANDLE, wintypes.DWORD)
            k32.SetPriorityClass(k32.GetCurrentProcess(), 0x4000)  # BELOW_NORMAL_PRIORITY_CLASS
        except Exception:
            pass


# ---------------------------------------------------------------- feed

def parse_duration(s):
    try:
        parts = [int(float(p)) for p in (s or '').split(':')]
    except ValueError:
        return 0
    total = 0
    for p in parts:
        total = total * 60 + p
    return total


def cmd_feed(show, quiet=False):
    import requests
    conf = SHOWS[show]
    d = show_dir(show)
    r = requests.get(conf['feed'], headers={'User-Agent': UA}, timeout=60)
    r.raise_for_status()
    (d / 'feed.xml').write_bytes(r.content)
    root = ET.fromstring(r.content)
    episodes = []
    for item in root.findall('./channel/item'):
        enc = item.find('enclosure')
        if enc is None or not enc.get('url'):
            continue
        title = (item.findtext('title') or '').strip()
        num = (item.findtext(ITUNES + 'episode') or '').strip()
        if not num:
            m = re.match(r'(?:episode\s*)?(\d{1,4})\s*[:.\-]', title, re.I)
            num = m.group(1) if m else ''
        date = item.findtext('pubDate') or ''
        try:
            date_iso = time.strftime('%Y-%m-%d', time.strptime(date[5:16], '%d %b %Y'))
        except ValueError:
            date_iso = ''
        guid = (item.findtext('guid') or enc.get('url')).strip()
        stem = (f'{int(num):03d}' if num.isdigit() else date_iso or 'x') + '-' + slugify(re.sub(r'^(episode\s*)?\d+\s*[:.\-]\s*', '', title, flags=re.I))
        episodes.append({
            'guid': guid,
            'number': int(num) if num.isdigit() else None,
            'title': title,
            'date': date_iso,
            'link': (item.findtext('link') or '').strip(),
            'audio': enc.get('url'),
            'duration': parse_duration(item.findtext(ITUNES + 'duration')),
            'description': strip_html(item.findtext(ITUNES + 'summary') or item.findtext('description') or ''),
            'stem': stem,
        })
    seen = Counter(e['stem'] for e in episodes)
    for e in episodes:  # two episodes with one number and title (re-uploads) keep apart
        if seen[e['stem']] > 1:
            e['stem'] += '-' + slugify(e['date'] or e['guid'][-8:], 12)
    write_json(d / 'episodes.json', episodes)
    got = fetch_official(show, episodes)
    if not quiet:
        hours = sum(e['duration'] for e in episodes) / 3600
        print(f'{len(episodes)} episodes, {hours:.1f} hours -> {d / "episodes.json"}; {got} official transcripts')
    return episodes


def fetch_official(show, episodes):
    """The show's own published transcripts -> official/<stem>.txt, matched to episodes by the number in the post title."""
    import requests
    url = SHOWS[show].get('official')
    if not url:
        return 0
    out = show_dir(show) / 'official'
    by_num = {e['number']: e for e in episodes if e['number'] is not None}
    got = 0
    while url:
        try:
            r = requests.get(url, headers={'User-Agent': UA}, timeout=60)
            r.raise_for_status()
            data = r.json()
        except Exception as ex:  # the feed still works without them
            print(f'  official transcripts not fetched: {ex}', file=sys.stderr)
            break
        for post in data.get('items', []):
            m = re.search(r'episode\s*(\d+)', strip_html(post.get('title', '')), re.I)
            e = by_num.get(int(m.group(1))) if m else None
            text = strip_html(post.get('body') or '')
            if e and len(text) > 2000:
                out.mkdir(exist_ok=True)
                head = f"{strip_html(post['title'])}\nhttps://storiesfromthefield.com{post.get('fullUrl', '')}\n\n"
                (out / f"{e['stem']}.txt").write_text(head + text, encoding='utf-8')
                got += 1
        nxt = (data.get('pagination') or {}).get('nextPageUrl')
        url = f"https://storiesfromthefield.com{nxt}&format=json" if nxt else None
    return got


def official_text(show, e):
    p = show_dir(show) / 'official' / f"{e['stem']}.txt"
    return p.read_text(encoding='utf-8') if p.exists() else ''


def load_episodes(show):
    eps = read_json(show_dir(show) / 'episodes.json')
    return eps if eps else cmd_feed(show, quiet=True)


# ---------------------------------------------------------------- the name list

def hand_lists(show):
    terms, corrections = [], {}
    for name in (f'{show}.json', 'common.json'):
        data = read_json(VOCAB_DIR / name, {}) or {}
        terms += data.get('terms', [])
        corrections = {**data.get('corrections', {}), **corrections}
    return terms, corrections


def clean_name(n):
    n = re.sub(r'\s+', ' ', str(n or '')).strip(' ,.;:')
    return re.sub(r'\s*\((?:until|from|\d{4})[^)]*\)$', '', n)  # "Aspen Education Group (until 2014)"


_KOP = None


def kop_names():
    """Every name KOP holds that a speaker might say: ({name: kind}, {program name lower: [its people]})."""
    global _KOP
    if _KOP is not None:
        return _KOP
    names = {}
    staff = defaultdict(list)  # a program's people: the map's first (the industry's known names), then its staff lists

    def add(n, kind):
        n = clean_name(n)
        if 3 <= len(n) <= 60 and not re.search(r'[:/@]|https?', n) and n not in names:
            names[n] = kind

    def add_staff(program_names, person):
        person = clean_name(person)
        if not (5 <= len(person) <= 40 and ' ' in person):
            return
        for p in program_names:
            lst = staff[clean_name(p).lower()]
            if person not in lst:
                lst.append(person)

    graph = read_json(GRAPH, {}) or {}
    node_name = {n['id']: n.get('name') for n in graph.get('nodes', [])}
    node_kind = {n['id']: n.get('kind') for n in graph.get('nodes', [])}
    for edge in graph.get('edges', []):
        ends = (edge.get('source'), edge.get('target'))
        for a, b in (ends, ends[::-1]):
            if node_kind.get(a) == 'person' and node_kind.get(b) in ('facility', 'parent'):
                add_staff([node_name[b]], node_name[a])

    if MIRROR.exists():
        db = sqlite3.connect(f'file:{MIRROR}?mode=ro', uri=True)
        all_names_of, by_unique = {}, {}
        for fid, unique, name, raw in db.execute('SELECT id, unique_name, name, json_data FROM facilities_v2'):
            add(name, 'program')
            try:
                ident = (json.loads(raw) or {}).get('identification') or {}
            except ValueError:
                ident = {}
            own = [name]
            for key in ('pastNames', 'otherNames'):
                for n in ident.get(key) or []:
                    n = n if isinstance(n, str) else (n or {}).get('name')
                    add(n, 'program')
                    if n:
                        own.append(n)
            all_names_of[fid] = own
            by_unique[unique] = own
            for key in ('currentOperator', 'pastOperators', 'otherOperators'):
                v = ident.get(key)
                for n in (v if isinstance(v, list) else [v]):
                    add(n, 'company')
        wiki_facility = dict(db.execute("SELECT id, facility_unique_name FROM wiki_submissions WHERE facility_unique_name <> ''"))
        for kind, rid, person in db.execute(
                "SELECT r.record_kind, r.record_id, COALESCE(p.name, r.name) FROM wpdl_kop_person_roles r "
                "LEFT JOIN wpdl_kop_people p ON p.id = r.person_id WHERE r.record_kind IN ('facility', 'wiki')"):
            own = all_names_of.get(rid) if kind == 'facility' else by_unique.get(wiki_facility.get(rid))
            if own:
                add_staff(own, person)
        for (name,) in db.execute('SELECT name FROM wpdl_kop_operators'):
            add(name, 'company')
        for (name,) in db.execute("SELECT name FROM wpdl_kop_people WHERE pool = 'industry' AND (merged_into IS NULL OR merged_into = 0)"):
            add(name, 'person')
        for (name,) in db.execute('SELECT name FROM young_adult_programs'):
            add(name, 'program')
        for term, qual in db.execute('SELECT term, qualifier FROM wpdl_kop_glossary_entries'):
            add(re.sub(r'\s*\([^)]*\)', '', term), 'term')
            m = re.search(r'\(([A-Z]{2,})\)', term)  # "Positive Peer Culture (PPC)" -> PPC too
            if m:
                add(m.group(1), 'term')
        db.close()
    else:
        print(f'note: {MIRROR} missing, priming from the network map and hand lists only', file=sys.stderr)
    for node in graph.get('nodes', []):
        kind = {'facility': 'program', 'parent': 'company', 'person': 'person'}.get(node.get('kind'), 'org')
        add(node.get('name'), kind)
    _KOP = (names, dict(staff))
    return _KOP


def name_pattern(name):
    """Multi-word names match in any case; one-word names only as written (so 'Hope' never matches 'hope')."""
    flags = 0 if ' ' not in name else re.I
    return re.compile(r'(?<![\w-])' + re.escape(name) + r'(?![\w-])', flags)


def is_name_word(w, lower_words):
    """One word counts as a name only if the feed never uses it in lower case ("Asheville" yes, "Family" no)."""
    if re.fullmatch(r'[A-Z]{2,6}', w):
        return w.lower() not in COMMON
    return len(w) >= 4 and w.lower() not in COMMON and w.lower() not in lower_words


def proper_nouns(text, lower_words):
    """Names in a description: runs of capitalised words ("Adam Ray", "Foundations Asheville", "Dr. Madolyn Liebing")."""
    out = []
    word = r"(?:[A-Z]\.|[A-Z][\w'’\-]*)"
    for m in re.finditer(rf"{word}(?:[ \t]+(?:of|the|and|for|from|de|van|von|la)?[ \t]*{word})*", text):
        phrase = re.sub(r'^(?:Dr|Mr|Mrs|Ms|The|A|An)\.?[ \t]+', '', m.group(0))
        phrase = re.sub(r"['’]s$", '', phrase).strip(" .'’-")
        words = phrase.split()
        if not words or len(words) > 5:  # longer runs are book and article titles
            continue
        if len(words) == 1:
            if not is_name_word(words[0], lower_words):
                continue
        elif all(w.lower().strip('.') in COMMON for w in words):
            continue
        out.append(phrase)
    return out


FILLER = set('so and but like cause well hey yeah oh um uh okay ok because that thats then now yes no just i'.split())


def trim_spoken(p):
    """A name as said: no filler around it ("So Jessica" -> "Jessica", "Mike Gass and Dr" -> "Mike Gass")."""
    words = p.split()
    while words and (words[0].lower().strip(".'’") in FILLER or re.search(r"['’]", words[0])):
        words.pop(0)
    while words and (words[-1].lower().strip(".'’") in FILLER | {'and', 'dr', 'of', 'the'} or re.search(r"['’]", words[-1])):
        words.pop()
    return ' '.join(words)


def spoken_name_ok(p, lower_words):
    """Said, not written: the first or last word must be a name word ("Mike Gass", "University of New Hampshire"),
    never a phrase of ordinary words that was capitalised ("Staff Training and Pay", "Boot Camp")."""
    words = p.split()
    if not words or any(re.search(r"['’]", w) for w in words):
        return False
    return any(is_name_word(w.strip('.,-'), lower_words) for w in (words[0], words[-1]))


def build_vocab(show, episodes):
    conf = SHOWS[show]
    terms, corrections = hand_lists(show)
    names, staff = kop_names()
    singles = {n: name_pattern(n) for n in names}
    lowered = {n.lower(): n for n in names}

    official = {e['guid']: official_text(show, e) for e in episodes}
    fix_pat, fix_lut = correction_re(corrections)
    corpus = '\n'.join(e['title'] + '\n' + e['description'] + '\n' + official[e['guid']] for e in episodes)
    lower_words = set(re.findall(r"(?<![\w'’])[a-z][a-z'’\-]+", corpus))
    per_episode, series = {}, Counter()
    for e in episodes:
        text = e['title'] + '\n' + e['description']
        low = text.lower()
        said = official[e['guid']]
        hits = []
        for n, pat in singles.items():
            if n.lower() in low and pat.search(text) and (' ' in n or is_name_word(n, lower_words)):
                hits.append(n)
            # in speech only as written (capitalised) and never an everyday phrase ("Boot Camp", "Time Out")
            elif said and n in said and spoken_name_ok(n, lower_words) and pat.search(said):
                hits.append(n)
        text += '\n' + said
        low = text.lower()
        # longest first, then drop names inside a longer hit ("Aspen" inside "Aspen Achievement Academy")
        hits.sort(key=lambda n: (-len(n), n))
        kept = []
        for n in hits:
            if not any(n.lower() in k.lower() for k in kept):
                kept.append(n)
        kept.sort(key=lambda n: low.find(n.lower()))
        propers = []
        for p in proper_nouns(e['title'] + '\n' + e['description'], lower_words):
            pl = p.lower()
            if pl == conf['title'].lower() or pl in conf['host'].lower():
                continue
            if not any(pl in k.lower() for k in kept + propers):
                propers.append(p)
        # names in the show's own transcript: said twice or more, or more than one word, most said first
        # (the show's typos fixed by the corrections first, so Whisper is never primed with "Kurt Hanh")
        spoken = Counter()
        for p in proper_nouns(said, lower_words):
            p = apply_corrections(trim_spoken(p), fix_pat, fix_lut)
            if p and spoken_name_ok(p, lower_words) and p.lower() not in (conf['title'].lower(), conf['host'].lower()):
                spoken[p] += 1
        heard = [p for p, c in spoken.most_common() if (c >= 2 or ' ' in p)
                 and not any(p.lower() in k.lower() for k in kept + propers)]
        # the people KOP has at the programs this episode names, taken in turn from each program, up to 12
        lists = [staff.get(n.lower(), []) for n in kept]
        people, taken = [], {p.lower() for p in propers + kept}
        for row in range(max(map(len, lists), default=0)):
            for lst in lists:
                if row < len(lst) and lst[row].lower() not in taken and len(people) < 12:
                    taken.add(lst[row].lower())
                    people.append(lst[row])
        per_episode[e['guid']] = {'kop': kept, 'propers': propers, 'official': heard, 'staff': people}
        series.update(kept)
        series.update(p for p in propers if p.lower() in lowered or len(p.split()) > 1)

    series_top = [n for n, c in series.most_common() if c >= 3]
    tokenizer = get_tokenizer()
    out = {'show': show, 'built': time.strftime('%Y-%m-%d %H:%M'), 'kop_names': len(names),
           'series': series_top, 'episodes': {}}
    for e in episodes:
        pe = per_episode[e['guid']]
        out['episodes'][e['guid']] = dict(pe, prompt=make_prompt(conf, e, pe, terms, series_top, tokenizer))
    return out, corrections


def make_prompt(conf, e, pe, terms, series_top, tokenizer):
    """A sentence, not a bare list: Whisper copies a prompt's style, and lists invite it to recite them over music."""
    title = re.sub(r'^(?:episode\s*)?\d+\s*[:.\-]\s*', '', e['title'], flags=re.I)
    head = f"{conf['title']}, a podcast about {conf['about']} with {conf['host']}. {title}."
    names, seen = [], {conf['host'].lower()}
    for n in pe['propers'] + pe['kop'] + pe.get('official', []) + pe['staff'] + terms + series_top:
        key = n.lower()
        if key not in seen and key not in title.lower():
            seen.add(key)
            names.append(n)
    prompt = head
    for i in range(len(names), -1, -1):  # as many names as fit the token budget, in order of relevance
        candidate = head + (' Names: ' + ', '.join(names[:i]) + '.' if i else '')
        if count_tokens(tokenizer, candidate) <= PROMPT_TOKENS:
            prompt = candidate
            break
    return prompt


_TOKENIZER = None


def get_tokenizer():
    global _TOKENIZER
    if _TOKENIZER is None:
        try:
            from whisper.tokenizer import get_tokenizer as gt
            _TOKENIZER = gt(multilingual=True)
        except Exception:
            _TOKENIZER = False
    return _TOKENIZER


def count_tokens(tokenizer, text):
    if tokenizer:
        return len(tokenizer.encode(' ' + text))
    return len(text) // 3  # rough fallback, errs short


def cmd_vocab(show, print_ep=None, quiet=False):
    episodes = load_episodes(show)
    vocab, _ = build_vocab(show, episodes)
    d = show_dir(show)
    write_json(d / 'vocab.json', vocab)
    lines = [f"# Prompts for {SHOWS[show]['title']}", '',
             f"{vocab['kop_names']} KOP names checked; {len(vocab['series'])} recur across the feed (3+ episodes):", '',
             ', '.join(vocab['series']) or '(none)', '']
    for e in episodes:
        v = vocab['episodes'][e['guid']]
        lines += [f"## {e['title']} ({e['date']})", '', f"- From KOP: {', '.join(v['kop']) or '-'}",
                  f"- Their people in KOP: {', '.join(v['staff']) or '-'}",
                  f"- From the description: {', '.join(v['propers']) or '-'}"]
        if v['official']:
            lines.append(f"- From the show's own transcript: {', '.join(v['official'])}")
        lines += [f"- Prompt: {v['prompt']}", '']
    (d / 'vocab-report.md').write_text('\n'.join(lines), encoding='utf-8')
    if print_ep is not None:
        for e in episodes:
            if str(e['number']) == str(print_ep) or e['stem'].startswith(str(print_ep)):
                v = vocab['episodes'][e['guid']]
                print(e['title'])
                print('  KOP names:  ', ', '.join(v['kop']) or '-')
                print('  their people:', ', '.join(v['staff']) or '-')
                print('  description:', ', '.join(v['propers']) or '-')
                print('  transcript: ', ', '.join(v['official']) or '-')
                print('  prompt:     ', v['prompt'])
    if not quiet:
        print(f"{len(episodes)} prompts, {len(vocab['series'])} series names -> {d / 'vocab.json'}, {d / 'vocab-report.md'}")
    return vocab


# ---------------------------------------------------------------- corrections and rendering

def correction_re(corrections):
    keys = sorted(corrections, key=len, reverse=True)
    if not keys:
        return None, {}
    lut = {k.lower(): v for k, v in corrections.items()}
    pat = re.compile(r'(?<![\w])(' + '|'.join(re.escape(k) for k in keys) + r')(?![\w])', re.I)
    return pat, lut


def apply_corrections(text, pat, lut):
    return pat.sub(lambda m: lut[m.group(1).lower()], text) if pat else text


def render(meta, segments, pat, lut, pause=0.75):
    """Timestamped paragraphs, a new one after each pause (the layout of timestamped_transcript.py)."""
    out = [f"# {meta['title']}", f"# {SHOWS[meta['show']]['title']}, {meta['date']}",
           f"# Episode page: {meta['link'] or '-'}", f"# Audio: {meta['audio']}",
           f"# Whisper {meta['model']}, transcribed {meta['transcribed']}; machine transcript, check the audio before quoting", '']
    block, last_end = None, None
    for seg in segments:
        text = apply_corrections(seg['text'].strip(), pat, lut)
        if not text:
            continue
        if block is None or seg['start'] - last_end > pause:
            if block:
                out += [block[0], ' '.join(block[1]), '']
            block = (f"[{fmt_ts(seg['start'])}]", [text])
        else:
            block[1].append(text)
        last_end = seg['end']
    if block:
        out += [block[0], ' '.join(block[1]), '']
    return '\n'.join(out)


def cmd_fix(show):
    _, corrections = hand_lists(show)
    pat, lut = correction_re(corrections)
    tdir = show_dir(show) / 'transcripts'
    n = 0
    for jf in sorted(tdir.glob('*.json')):
        data = read_json(jf)
        if not data:
            continue
        jf.with_suffix('.txt').write_text(render(data['meta'], data['segments'], pat, lut), encoding='utf-8')
        n += 1
    print(f'{n} transcripts re-rendered with {len(corrections)} corrections')


# ---------------------------------------------------------------- transcription

def download(url, dest):
    import requests
    part = dest.with_suffix(dest.suffix + '.part')
    have = part.stat().st_size if part.exists() else 0
    headers = {'User-Agent': UA}
    if have:
        headers['Range'] = f'bytes={have}-'
    with requests.get(url, headers=headers, stream=True, timeout=60) as r:
        if r.status_code == 416:
            os.replace(part, dest)
            return
        r.raise_for_status()
        mode = 'ab' if have and r.status_code == 206 else 'wb'
        with open(part, mode) as f:
            for chunk in r.iter_content(1 << 20):
                f.write(chunk)
    os.replace(part, dest)


def free_gb(path):
    return shutil.disk_usage(path).free / 1e9


def cmd_run(show, model_name, limit, only, oldest_first, keep_audio, min_free_gb):
    low_priority()
    episodes = cmd_feed(show, quiet=True)  # fresh feed: new episodes join the queue
    vocab = cmd_vocab(show, quiet=True)
    _, corrections = hand_lists(show)
    pat, lut = correction_re(corrections)
    d = show_dir(show)
    tdir, adir = d / 'transcripts', d / 'audio'
    tdir.mkdir(exist_ok=True)
    adir.mkdir(exist_ok=True)

    queue = [e for e in episodes if not (tdir / f"{e['stem']}.json").exists()]
    if only:
        wanted = {str(x) for x in only}
        queue = [e for e in queue if str(e['number']) in wanted or e['stem'] in wanted]
    queue.sort(key=lambda e: (e['date'], e['number'] or 0), reverse=not oldest_first)
    if limit:
        queue = queue[:limit]
    if not queue:
        print('nothing to transcribe')
        return 0

    import whisper
    print(f"loading Whisper {model_name} ...", flush=True)
    model = whisper.load_model(model_name)
    log = d / 'run.log'
    for i, e in enumerate(queue, 1):
        if free_gb(d) < min_free_gb:
            print(f'stopping: only {free_gb(d):.1f} GB free on the disk (need {min_free_gb}); free some space and run again')
            return 3
        audio = adir / f"{e['stem']}.mp3"
        print(f"[{i}/{len(queue)}] {e['title']} ({fmt_dur(e['duration'])})", flush=True)
        try:
            if not audio.exists():
                download(e['audio'], audio)
            vocab = read_json(tdir.parent / 'vocab.json') or vocab  # picks up a 'vocab' rebuilt while this runs
            prompt = vocab['episodes'][e['guid']]['prompt']
            t0 = time.time()
            result = model.transcribe(str(audio), language='en', fp16=False, verbose=None,
                                      initial_prompt=prompt, carry_initial_prompt=True)
            took = time.time() - t0
        except KeyboardInterrupt:
            print('stopped; run again to carry on')
            return 130
        except Exception as ex:
            print(f'  FAILED: {ex}', flush=True)
            with open(log, 'a', encoding='utf-8') as f:
                f.write(f"{time.strftime('%Y-%m-%d %H:%M')} FAILED {e['stem']}: {ex}\n")
            continue
        segments = [{'start': round(s['start'], 2), 'end': round(s['end'], 2), 'text': s['text']}
                    for s in result.get('segments', [])]
        meta = {k: e[k] for k in ('guid', 'number', 'title', 'date', 'link', 'audio', 'duration', 'description')}
        meta.update(show=show, model=model_name, prompt=prompt, seconds=round(took),
                    transcribed=time.strftime('%Y-%m-%d'))
        write_json(tdir / f"{e['stem']}.json", {'meta': meta, 'segments': segments})
        (tdir / f"{e['stem']}.txt").write_text(render(meta, segments, pat, lut), encoding='utf-8')
        if not keep_audio:
            audio.unlink(missing_ok=True)
        speed = (e['duration'] or 1) / max(took, 1)
        print(f'  done in {fmt_dur(took)} ({speed:.2f}x real time)', flush=True)
        with open(log, 'a', encoding='utf-8') as f:
            f.write(f"{time.strftime('%Y-%m-%d %H:%M')} done {e['stem']} {round(took)}s for {e['duration']}s audio\n")
    return 0


def cmd_status(show):
    episodes = load_episodes(show)
    tdir = show_dir(show) / 'transcripts'
    done, took, audio_done = 0, 0, 0
    for e in episodes:
        data = read_json(tdir / f"{e['stem']}.json")
        if data:
            done += 1
            took += data['meta'].get('seconds', 0)
            audio_done += e['duration']
    left = sum(e['duration'] for e in episodes) - audio_done
    print(f"{done} of {len(episodes)} episodes transcribed, {fmt_dur(left)} of audio left")
    if took:
        rate = audio_done / took
        print(f"speed so far {rate:.2f}x real time; about {fmt_dur(left / rate)} of transcribing to go")
    print(f'disk: {free_gb(show_dir(show)):.1f} GB free')


# ---------------------------------------------------------------- suspects

def cmd_suspects(show, cutoff=86):
    from rapidfuzz import fuzz, process
    terms, corrections = hand_lists(show)
    vocab = read_json(show_dir(show) / 'vocab.json') or cmd_vocab(show, quiet=True)
    pat, lut = correction_re(corrections)
    known = set(kop_names()[0]) | set(terms) | set(vocab['series'])
    for v in vocab['episodes'].values():
        known.update(v['propers'] + v.get('staff', []))
    known = [k for k in known if len(k) >= 5]
    known_low = {k.lower() for k in known}
    choices = [k.lower() for k in known]

    def base(s):  # "The Adventure Recovery's" and "Adventure Recovery" are the same name
        s = re.sub(r"^the\s+|['’]?s$", '', s.lower())
        return re.sub(r'[\s\-]+', '', s)

    found, where = Counter(), defaultdict(list)
    word = r"[A-Z][\w'’\-]*"
    for jf in sorted((show_dir(show) / 'transcripts').glob('*.json')):
        data = read_json(jf)
        if not data:
            continue
        ep = str(data['meta'].get('number') or jf.stem)
        for seg in data['segments']:
            for m in re.finditer(rf"{word}(?:\s+{word}){{0,2}}", apply_corrections(seg['text'], pat, lut)):
                p = m.group(0).strip(" '’-")
                if len(p) < 5 or p.lower() in known_low or all(w.lower() in COMMON for w in p.split()):
                    continue
                found[p] += 1
                if len(where[p]) < 3:
                    where[p].append(f"ep {ep} at {fmt_ts(seg['start'])}")
    rows = []
    for p, n in found.items():
        hit = process.extractOne(p.lower(), choices, scorer=fuzz.ratio, score_cutoff=cutoff)
        if hit and base(p) != base(known[hit[2]]):
            rows.append((n, p, known[hit[2]], round(hit[1])))
    rows.sort(key=lambda r: (-r[0], r[1]))
    out = [f"# Names that may be misheard: {SHOWS[show]['title']}", '',
           'Heard (times) -> probably meant (similarity) | where. A real one goes in scripts/podcast-vocab/'
           f'{show}.json "corrections", then run fix.', '']
    out += [f"- {p} ({n}x) -> {k} ({s}) | {', '.join(where[p])}" for n, p, k, s in rows]
    path = show_dir(show) / 'suspects.md'
    path.write_text('\n'.join(out) + '\n', encoding='utf-8')
    print(f'{len(rows)} possible mishearings -> {path}')


# ---------------------------------------------------------------- main

def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('command', choices=['feed', 'vocab', 'run', 'fix', 'suspects', 'status'])
    ap.add_argument('--show', default='sftf', choices=sorted(SHOWS))
    ap.add_argument('--model', default='small', help='Whisper model (default small)')
    ap.add_argument('--limit', type=int, default=0, help='transcribe at most N episodes this run')
    ap.add_argument('--episodes', nargs='*', help='only these episode numbers (or file stems)')
    ap.add_argument('--oldest-first', action='store_true', help='default is newest first')
    ap.add_argument('--keep-audio', action='store_true')
    ap.add_argument('--min-free-gb', type=float, default=1.0)
    ap.add_argument('--print', dest='print_ep', help='vocab: print one episode\'s names and prompt')
    a = ap.parse_args()
    if hasattr(sys.stdout, 'reconfigure'):
        sys.stdout.reconfigure(encoding='utf-8', errors='replace')
    if a.command == 'feed':
        cmd_feed(a.show)
    elif a.command == 'vocab':
        cmd_vocab(a.show, a.print_ep)
    elif a.command == 'run':
        sys.exit(cmd_run(a.show, a.model, a.limit, a.episodes, a.oldest_first, a.keep_audio, a.min_free_gb))
    elif a.command == 'fix':
        cmd_fix(a.show)
    elif a.command == 'suspects':
        cmd_suspects(a.show)
    elif a.command == 'status':
        cmd_status(a.show)


if __name__ == '__main__':
    main()
