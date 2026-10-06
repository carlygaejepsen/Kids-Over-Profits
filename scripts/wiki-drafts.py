"""Wiki update drafts (docs/PLAN.md 3.12 step 3): apply the drafters' additions to an entry, never anything else.

Each tmp/wiki-updates/drafts/<id>/ holds entry.md and gaps.json (scripts/wiki-pilot-prep.py) and the
drafters' ops files: ops-haiku.json, ops-sonnet.json, ops.json (Opus's final list, every op with a
verdict). An op adds text only:

  {"id": "o1", "op": "append_to_section", "section": "Related Media", "text": "...", "gids": ["g3"]}
  {"id": "o2", "op": "add_section", "after_section": "History and Background Information",
   "heading": "## **Closure**", "text": "...", "gids": ["g1"]}
  {"id": "o3", "op": "set_header_years", "years": "2008-2024", "gids": ["g1"]}

plus "by" (haiku/sonnet/opus), "verdict" (ok, fixed, dropped) and "note". Sections are found by their
heading text without #, * and spaces, case ignored. ops with verdict "dropped" are not applied.

A closed program's entry (or one about an earlier name) also gets ops-tense.json, the one op that
rewrites existing lines: {"id": "t1", "op": "past_tense", "lines": [{"old": "<exact line>", "new": "..."}]}.
A line is taken only when the sole changes are verbs put in the past tense ("is" -> "was", "uses" ->
"used", "runs" -> "ran") and dropped "current"/"currently"/"now"/"still"; anything else refuses the draft.

    python scripts/wiki-drafts.py assemble [ids...]   # ops-tense.json + ops.json -> draft.md; refuses a draft that loses or changes a line
    python scripts/wiki-drafts.py check [ids...]       # the same checks, prints per entry
    python scripts/wiki-drafts.py selftest             # the tense checker on fixed cases
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRAFTS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
SEPARATOR = re.compile(r'^\s*(-{3,}|\*{3,}|_{3,})\s*$')
HEADING = re.compile(r'^\s*#{1,6}\s')
# A source with no page of its own, cited by name: an inspection report (state and date) or a case (name v. name, number).
TEXT_CITE = re.compile(r'\([^()]*(inspection report|\sv\.\s|No\.\s?\d)[^()]*\)')
# KOP pages that gather other people's reporting; KOP's own articles and media-library documents are fine.
KOP_PAGE = re.compile(r'^https?://(www\.)?kidsoverprofits\.org/(facility|operator|network-map|lawsuits|severe-reports|memorial|wiki-feed|'
                      r'tti-program-index|location-index|[a-z]{2}-reports|news|search|open-data|glossary|phpbb)(/|#|\?|$)', re.I)


def norm(heading):
    return re.sub(r'[#*\s:]+', ' ', heading).strip().lower()


FOOTER = re.compile(r'^\s*(last revised by\b|#{1,6}\s*page title\s*$)', re.I)


def footer_start(lines):
    """Reddit's own footer ("Last revised by", "## Page title"), copied in with the page: nothing goes after it."""
    for i, l in enumerate(lines):
        if FOOTER.match(l):
            return i
    return len(lines)


def sections(lines):
    """[(start, end, key)] per heading above the footer: lines start..end-1 are the heading and its body."""
    stop = footer_start(lines)
    heads = [i for i, l in enumerate(lines[:stop]) if HEADING.match(l)]
    out = []
    for n, i in enumerate(heads):
        end = heads[n + 1] if n + 1 < len(heads) else stop
        out.append((i, end, norm(lines[i])))
    return out


def body_end(lines, start, end):
    """Where to add at the end of a section: before its trailing blank lines and separator."""
    j = end
    while j > start + 1 and (not lines[j - 1].strip() or SEPARATOR.match(lines[j - 1])):
        j -= 1
    return j


def find(lines, name):
    want = norm(name)
    for s in sections(lines):
        if s[2] == want:
            return s
    for s in sections(lines):
        if want and (want in s[2] or s[2] in want) and len(s[2]) > 3:
            return s
    return None


def apply(md, ops):
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    applied, errors = [], []
    for op in ops:
        if op.get('verdict') == 'dropped':
            continue
        kind = op.get('op')
        text = (op.get('text') or '').strip('\n')
        new = text.split('\n') if text else []
        if kind == 'set_header_years':
            first = lines[0]
            changed = re.sub(r'(\*\*\s*)\(([^)]*)\)', lambda m: m.group(1) + '(' + op['years'] + ')', first, count=1)
            if changed == first and '(' not in first:
                changed = re.sub(r'^(#+\s*\*\*.*?\*\*)', lambda m: m.group(1) + '(' + op['years'] + ')', first, count=1)
            if changed == first:
                errors.append(f"{op.get('id')}: header has no years to set")
                continue
            lines[0] = changed
        elif kind == 'append_to_section':
            s = find(lines, op.get('section', ''))
            if not s:
                errors.append(f"{op.get('id')}: no section '{op.get('section')}'")
                continue
            at = body_end(lines, s[0], s[1])
            lines[at:at] = [''] + new
        elif kind == 'add_section':
            s = find(lines, op.get('after_section', ''))
            at = body_end(lines, s[0], s[1]) if s else body_end(lines, 0, footer_start(lines))
            lines[at:at] = ['', '---', '', op['heading'].strip(), ''] + new
        else:
            errors.append(f"{op.get('id')}: unknown op '{kind}'")
            continue
        applied.append(op.get('id'))
    return '\n'.join(lines) + '\n', applied, errors


def check(original, draft, header_changed):
    """Every original line is still there, in order (the header may only change its years); every added line cites a link."""
    o = original.replace('\r\n', '\n').rstrip('\n').split('\n')
    d = draft.rstrip('\n').split('\n')
    problems = []
    if header_changed:
        strip = lambda l: re.sub(r'\([^)]*\)', '()', l, count=1)
        if strip(o[0]) != strip(d[0]):
            problems.append('the header changed beyond its years')
        o, d = o[1:], d[1:]
    j = 0
    added = []
    for line in d:
        if j < len(o) and line == o[j]:
            j += 1
        else:
            added.append(line)
    if j < len(o):
        problems.append(f'original line {j + 1} is missing or changed: {o[j][:80]!r}')
    for line in added:
        t = line.strip()
        if not t or HEADING.match(t) or SEPARATOR.match(t):
            continue
        if '](' not in t and not TEXT_CITE.search(t):
            problems.append(f'added line without a source: {t[:80]!r}')
        for url in re.findall(r'\]\((https?://[^)\s]+)\)', t):
            if KOP_PAGE.match(url):
                problems.append(f'cites a KOP page that gathers others\' reporting: {url}')
    if re.search(r'[\U0001F300-\U0001FAFF☀-➿]', '\n'.join(added)):
        problems.append('an emoji in the added text')
    return problems, added


IRREGULAR = {
    'is': 'was', 'are': 'were', 'has': 'had', 'have': 'had', 'does': 'did', 'do': 'did', 'can': 'could', 'will': 'would',
    "isn't": "wasn't", "aren't": "weren't", "doesn't": "didn't", "don't": "didn't", "hasn't": "hadn't", "haven't": "hadn't",
    "can't": "couldn't", "won't": "wouldn't", 'am': 'was', 'may': 'might', 'shall': 'should', 'must': 'hadto', 'hasto': 'hadto', 'haveto': 'hadto',
}
STRONG = {
    'run': 'ran', 'keep': 'kept', 'send': 'sent', 'take': 'took', 'make': 'made', 'hold': 'held', 'give': 'gave', 'get': 'got',
    'say': 'said', 'teach': 'taught', 'bring': 'brought', 'lead': 'led', 'pay': 'paid', 'spend': 'spent', 'cost': 'cost',
    'put': 'put', 'see': 'saw', 'go': 'went', 'come': 'came', 'become': 'became', 'tell': 'told', 'feel': 'felt', 'meet': 'met',
    'find': 'found', 'think': 'thought', 'buy': 'bought', 'sell': 'sold', 'let': 'let', 'set': 'set', 'write': 'wrote',
    'speak': 'spoke', 'wear': 'wore', 'sleep': 'slept', 'eat': 'ate', 'forbid': 'forbade', 'beat': 'beat', 'hire': 'hired',
    'stand': 'stood', 'understand': 'understood', 'leave': 'left', 'sit': 'sat', 'lose': 'lost', 'build': 'built', 'win': 'won',
    'break': 'broke', 'choose': 'chose', 'wake': 'woke', 'drive': 'drove', 'fall': 'fell', 'hide': 'hid', 'fight': 'fought',
    'catch': 'caught', 'seek': 'sought', 'ride': 'rode', 'rise': 'rose', 'shake': 'shook', 'throw': 'threw', 'grow': 'grew',
    'know': 'knew', 'draw': 'drew', 'begin': 'began', 'sing': 'sang', 'drink': 'drank', 'forget': 'forgot', 'hear': 'heard',
    'mean': 'meant', 'read': 'read', 'deal': 'dealt', 'shut': 'shut', 'hurt': 'hurt', 'hit': 'hit', 'cut': 'cut', 'quit': 'quit',
    'spread': 'spread', 'strike': 'struck', 'stick': 'stuck', 'swear': 'swore', 'tear': 'tore', 'steal': 'stole', 'bite': 'bit',
    'feed': 'fed', 'flee': 'fled', 'bleed': 'bled', 'lay': 'laid', 'forgive': 'forgave', 'withhold': 'withheld', 'undergo': 'underwent',
    'overcome': 'overcame', 'oversee': 'oversaw', 'shoot': 'shot', 'sweep': 'swept', 'weep': 'wept', 'kneel': 'knelt',
}
# Words a past-tense rewrite may drop: they only say "as of now".
DROPPABLE = {'current', 'currently', 'now', 'still', 'presently', 'today'}


def _stems(word):
    """Base forms a present-tense verb may come from ("uses" -> use, "carries" -> carry, "pushes" -> push)."""
    out = {word}
    if word.endswith('ies'):
        out.add(word[:-3] + 'y')
    if word.endswith('es'):
        out.add(word[:-2])
    if word.endswith('s'):
        out.add(word[:-1])
    return out


def tense_pair(old, new):
    """True when new is old put in the past tense."""
    o, n = old.lower(), new.lower()
    if IRREGULAR.get(o) == n:
        return True
    for stem in _stems(o):
        if STRONG.get(stem) == n:
            return True
        if n in (stem + 'd', stem + 'ed', stem[:-1] + 'ied' if stem.endswith('y') else '', stem + stem[-1:] + 'ed'):
            return True
    return False


TOKEN = re.compile(r"[A-Za-z]+(?:'[A-Za-z]+)?|\s+|[^\sA-Za-z]")


def tense_only(old, new):
    """'' when new differs from old only by verbs put in the past tense and dropped "current"/"still"/...; else why not."""
    import difflib
    # Curly apostrophes read as straight ones; "must" -> "had to" is one change.
    old, new = old.replace('\u2019', "'"), new.replace('\u2019', "'")
    # "has to"/"have to"/"had to" become one word on both sides, so "has to" -> "had to" and "must" -> "had to" both pair.
    joined = lambda t: re.sub(r'\b([Hh])(as|ave|ad) to\b', lambda m: m.group(1) + m.group(2) + 'to', t)
    old, new = joined(old), joined(new)
    a, b = TOKEN.findall(old), TOKEN.findall(new)
    if ''.join(a) != old or ''.join(b) != new:
        return 'could not read the line'
    for tag, i1, i2, j1, j2 in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes():
        if tag == 'equal':
            continue
        olds = [t for t in a[i1:i2] if t.strip()]
        news = [t for t in b[j1:j2] if t.strip()]
        if tag == 'delete' or (tag == 'replace' and not news):
            if all(t.lower() in DROPPABLE or t == ',' for t in olds):
                continue
            return 'removes ' + ' '.join(olds)
        if tag == 'insert':
            return 'adds ' + ' '.join(news)
        # A replaced run: pair the words up after dropping droppable ones.
        olds = [t for t in olds if t.lower() not in DROPPABLE]
        if len(olds) != len(news) or not all(x == y or tense_pair(x, y) for x, y in zip(olds, news)):
            return f"changes '{' '.join(a[i1:i2]).strip()}' to '{' '.join(b[j1:j2]).strip()}'"
    return ''


def apply_tense(md, ops):
    """Past-tense ops: each line replaced must exist once and change only its verbs' tense. -> (md, lines changed, errors)."""
    lines = md.replace('\r\n', '\n').rstrip('\n').split('\n')
    changed, errors = 0, []
    for op in ops:
        if op.get('op') != 'past_tense' or op.get('verdict') == 'dropped':
            continue
        for k, pair in enumerate(op.get('lines', [])):
            old, new = pair.get('old', '').rstrip('\r'), pair.get('new', '').rstrip('\r')
            hits = [n for n, line in enumerate(lines) if line == old]
            if len(hits) != 1:
                errors.append(f"{op.get('id')}.{k + 1}: line {'not found' if not hits else 'found more than once'}: {old[:70]!r}")
                continue
            why = tense_only(old, new)
            if why:
                errors.append(f"{op.get('id')}.{k + 1}: not only a tense change ({why}): {old[:70]!r}")
                continue
            lines[hits[0]] = new
            changed += 1
    return '\n'.join(lines) + '\n', changed, errors


def run(ids, write):
    ok = True
    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        path = os.path.join(folder, 'ops.json')
        if not os.path.exists(path):
            print(f'{i}: no ops.json')
            ok = False
            continue
        with open(os.path.join(folder, 'entry.md'), encoding='utf-8') as f:
            md = f.read()
        with open(path, encoding='utf-8') as f:
            ops = json.load(f).get('ops', [])
        tense_path = os.path.join(folder, 'ops-tense.json')
        tense_ops = json.load(open(tense_path, encoding='utf-8')).get('ops', []) if os.path.exists(tense_path) else []
        # The tense pass rewrites lines in place; the additions then go on top, and the check
        # compares with the tensed text, so every other original line must still be there.
        base, tensed, tense_errors = apply_tense(md, tense_ops)
        draft, applied, errors = apply(base, ops)
        header = any(o.get('op') == 'set_header_years' and o.get('verdict') != 'dropped' for o in ops)
        problems, added = check(base, draft, header)
        problems = tense_errors + errors + problems
        status = 'OK' if not problems else 'REFUSED'
        print(f'{i}: {status}, {len(applied)} ops applied, {sum(1 for a in added if a.strip())} lines added, {tensed} put in the past tense'
              + ''.join(f'\n    {p}' for p in problems))
        if problems:
            ok = False
        if write:
            with open(os.path.join(folder, 'draft.md'), 'w', encoding='utf-8', newline='\n') as f:
                f.write(draft)
            with open(os.path.join(folder, 'check.json'), 'w', encoding='utf-8', newline='\n') as f:
                json.dump({'status': status, 'problems': problems, 'applied': applied, 'tensed': tensed}, f, indent=1)
    return ok


def selftest():
    cases = [
        ('Provo Canyon School is a residential treatment center that serves teens.', 'Provo Canyon School was a residential treatment center that served teens.', True),
        ('**Jane Doe** is the current Executive Director.', '**Jane Doe** was the Executive Director.', True),
        ('The program still uses level systems and carries out restraints.', 'The program used level systems and carried out restraints.', True),
        ('Staff run the groups and admit students year round.', 'Staff ran the groups and admitted students year round.', True),
        ('"I have nightmares," she said.', '"I had nightmares," she said.', True),   # allowed by the checker; the drafter must leave quotes alone
        ('The school is in Provo.', 'The school was in Orem.', False),
        ('The school is abusive.', 'The school was reportedly abusive.', False),
        ('It has 106 beds.', 'It had 100 beds.', False),
        ('It is open.', 'It was closed.', False),
        ('If a resident breaks a rule, the teens must sit and won\u2019t speak.', 'If a resident broke a rule, the teens had to sit and wouldn\u2019t speak.', True),
        ('Staff may restrain them.', 'Staff might restrain them.', True),
        ('The teen has to accept it and they have to stay; others had to wait.', 'The teen had to accept it and they had to stay; others had to wait.', True),
        ('The teen has to accept it.', 'The teen has to accept it.', True),
        ('Staff must restrain them.', 'Staff had to hold them.', False),
    ]
    bad = 0
    for old, new, want in cases:
        got = tense_only(old, new) == ''
        if got != want:
            bad += 1
            print(f'FAIL {old!r} -> {new!r}: {tense_only(old, new) or "accepted"}')
    md = 'line one is here\nline two is here\n'
    out, n, err = apply_tense(md, [{'id': 't1', 'op': 'past_tense', 'lines': [{'old': 'line two is here', 'new': 'line two was here'}]}])
    if n != 1 or err or out != 'line one is here\nline two was here\n':
        bad += 1
        print('FAIL apply_tense', n, err)
    out, n, err = apply_tense(md, [{'id': 't1', 'op': 'past_tense', 'lines': [{'old': 'line three', 'new': 'x'}]}])
    if n or not err:
        bad += 1
        print('FAIL a line that is not there is refused')
    print('selftest:', 'all passed' if not bad else f'{bad} failed')
    return not bad


def main():
    if len(sys.argv) >= 2 and sys.argv[1] == 'selftest':
        sys.exit(0 if selftest() else 1)
    if len(sys.argv) < 2 or sys.argv[1] not in ('assemble', 'check'):
        print(__doc__)
        sys.exit(2)
    ids = [int(x) for x in sys.argv[2:]] or sorted(int(d) for d in os.listdir(DRAFTS) if d.isdigit())
    sys.exit(0 if run(ids, sys.argv[1] == 'assemble') else 1)


if __name__ == '__main__':
    main()
