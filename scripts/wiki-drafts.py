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

    python scripts/wiki-drafts.py assemble [ids...]   # ops.json -> draft.md; refuses a draft that loses or changes a line
    python scripts/wiki-drafts.py check [ids...]       # the same checks, prints per entry
"""
import json
import os
import re
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRAFTS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
SEPARATOR = re.compile(r'^\s*(-{3,}|\*{3,}|_{3,})\s*$')
HEADING = re.compile(r'^\s*#{1,6}\s')


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
        if '](' not in t:
            problems.append(f'added line without a link: {t[:80]!r}')
    if re.search(r'[\U0001F300-\U0001FAFF☀-➿]', '\n'.join(added)):
        problems.append('an emoji in the added text')
    return problems, added


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
        draft, applied, errors = apply(md, ops)
        header = any(o.get('op') == 'set_header_years' and o.get('verdict') != 'dropped' for o in ops)
        problems, added = check(md, draft, header)
        problems = errors + problems
        status = 'OK' if not problems else 'REFUSED'
        print(f'{i}: {status}, {len(applied)} ops applied, {sum(1 for a in added if a.strip())} lines added'
              + ''.join(f'\n    {p}' for p in problems))
        if problems:
            ok = False
        if write:
            with open(os.path.join(folder, 'draft.md'), 'w', encoding='utf-8', newline='\n') as f:
                f.write(draft)
            with open(os.path.join(folder, 'check.json'), 'w', encoding='utf-8', newline='\n') as f:
                json.dump({'status': status, 'problems': problems, 'applied': applied}, f, indent=1)
    return ok


def main():
    if len(sys.argv) < 2 or sys.argv[1] not in ('assemble', 'check'):
        print(__doc__)
        sys.exit(2)
    ids = [int(x) for x in sys.argv[2:]] or sorted(int(d) for d in os.listdir(DRAFTS) if d.isdigit())
    sys.exit(0 if run(ids, sys.argv[1] == 'assemble') else 1)


if __name__ == '__main__':
    main()
