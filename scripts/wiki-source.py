"""
The r/troubledteens wiki as a source for Woodbury Facts, like HEAL's archived
site: every wiki page in markdown_output/ (the export, Dec 2025 with later
edits) plus the wiki editor's copies of pages that export lacks
(wiki_submissions.original_markdown in tmp/prod.sqlite), written in the
Woodbury reading format so scripts/woodbury-facts.py --also tmp/wiki reads
them like an issue. Each proposal cites the wiki page; none is ever added
without review.

    python scripts/wiki-source.py text              # -> tmp/wiki/text/, issues.json, INSTRUCTIONS.md
    python scripts/wiki-source.py batches [--chars=220000] [--prefix=b]
                                                    # pages not read yet -> tmp/wiki/batches/<prefix>NN.txt

Then readers follow tmp/wiki/INSTRUCTIONS.md (text/ -> facts/) and
    python scripts/woodbury-facts.py --also tmp/heal --also tmp/wiki
"""

import argparse
import hashlib
import json
import os
import re
import sqlite3
import subprocess

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
MD = os.path.join(ROOT, 'markdown_output')
OUT = os.path.join(ROOT, 'tmp', 'wiki')
TEXT = os.path.join(OUT, 'text')
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
PUB = 'r/troubledteens wiki'
WIKI = 'https://www.reddit.com/r/troubledteens/wiki/'

# Pages with nothing about a program or the people who ran it.
OFF_TOPIC = {'info', 'teens', 'standards', 'redflags', 'inherentabuse', 'wiki', 'index', 'mentalhealthsubs',
             'tti', 'whatisthetti', 'troubledteen', 'rapeweekend'}


def plain(md):
    """Wiki markdown -> the words a reader sees: link text kept, URLs, emphasis and heading marks dropped."""
    t = md.replace('\r\n', '\n')
    t = re.sub(r'!\[[^\]]*\]\([^)]*\)', '', t)
    t = re.sub(r'\[([^\]]*)\]\((?:[^()]|\([^)]*\))*\)', r'\1', t)
    t = re.sub(r'(?m)^\s{0,3}#{1,6}\s*', '', t)
    t = t.replace('**', '').replace('__', '')
    t = re.sub(r'(?<![\w*])\*(?!\s)([^*\n]+?)\*(?!\w)', r'\1', t)
    t = re.sub(r'(?m)^\s*\*\s*$\n?', '', t)          # a bare list bullet on its own line
    t = re.sub(r'(?m)^\s*[*-]\s+', '- ', t)
    t = re.sub(r'(?m)^\s*-{3,}\s*$', '', t)           # horizontal rules
    t = re.sub(r'(?m)^\|?\s*:?-{2,}:?\s*(\|\s*:?-{2,}:?\s*)*\|?\s*$\n?', '', t)  # table rules
    t = re.sub(r'(?m)^Last revised by .*$', '', t)
    t = re.sub(r'[ \t]+', ' ', t)
    t = re.sub(r' +\n', '\n', t)
    t = re.sub(r'\n{3,}', '\n\n', t)
    # "Elan School(1970-2011) Poland Spring, ME": the header's years set apart from the name.
    return re.sub(r'^(.*?\w)\((\d{4}|\?|\))', r'\1 (\2', t.strip(), count=1)


def content_chars(body):
    """What there is to read: lines of words, not section titles or "No information is known."."""
    return sum(len(ln) for ln in body.splitlines()
               if len(ln) > 40 and not re.match(r'(No information is known|No known)', ln, re.I))


def title_of(md):
    m = re.search(r'#\s*\*\*\s*(.+?)\s*\*\*', md[:600]) or re.search(r'(?m)^#+\s*(.+)$', md[:600])
    return re.sub(r'[*#\[\]]', '', m.group(1)).strip() if m else ''


def slug_url(fn):
    """index_elan.md -> .../wiki/index/elan; active-programs_active-programs-utah.md -> .../wiki/active-programs/active-programs-utah."""
    base = fn[:-3].rstrip('_')
    if '_' in base:
        return WIKI + base.replace('_', '/')
    return WIKI + 'index/' + base


def key_of(fn):
    """One page, however the export named it: index_x.md, index_x_.md and x.md are the same page."""
    base = fn[:-3].rstrip('_')
    return base[len('index_'):] if base.startswith('index_') else base


def git_dates():
    """Each export file's last commit date: the copy's 'as of' date."""
    out = subprocess.run(['git', 'log', '--format=@%ad', '--date=short', '--name-only', '--', 'markdown_output'],
                         cwd=ROOT, capture_output=True, text=True, encoding='utf-8').stdout
    dates, cur = {}, ''
    for ln in out.splitlines():
        if ln.startswith('@'):
            cur = ln[1:]
        elif ln.startswith('markdown_output/'):
            dates.setdefault(ln[len('markdown_output/'):], cur)
    return dates


def norm_title(t):
    return re.sub(r'[^a-z0-9]+', ' ', re.sub(r'\(.*', '', t.lower())).strip()


INSTRUCTIONS = r'''# r/troubledteens wiki: fact extraction

The r/troubledteens wiki (reddit.com/r/troubledteens/wiki) is written by
survivors of the troubled teen industry (TTI): residential treatment centers,
therapeutic boarding schools, wilderness programs, boot camps, religious
boys'/girls' homes, psychiatric hospitals that take teens, transport companies,
and the companies that own them. Each program page has a header
("Program Name (1998-present) City, ST", then the program type) and sections:
history and background, founders and notable staff, program structure,
allegations, lawsuits, news, survivor stories. State pages ("Active Programs
in Utah") list programs with the year they opened. Kids Over Profits documents
these programs. We want every fact the wiki records about programs and the
people who ran them, so it can be added to our facility records after review.

Pages with nothing about a program or its people (general advice, essays on
the word "troubled teen", subreddit lists, glossary-style pages): write
`{"issue": "<key>", "items": []}`.

## Input

`C:/Users/daniu/source/repos/Kids-Over-Profits/tmp/wiki/text/<name>.txt`. First
line: `WIKI <url> title='...'`. `=== PAGE 1 ===` is the page; the line after
it says the copy's date. Links were reduced to their words; table rows are
`| cell | cell |`. Read the WHOLE file (Read with offset/limit in chunks of
~800 lines until the end).

Skip: survivors, students and parents (never record a survivor's, child's or
parent's name, username, email or contact details, even when a survivor
story names them), the wiki's own editors, "contact us" requests, and
anything the page only links to without saying (a link titled "Lawsuit" with
no words about it is not a fact). Facts about OTHER programs that the page
states in passing ("he previously worked at Cross Creek") count: the person's
past job goes under that other program. A former resident who went on to
found or run a program is recorded as that program's founder or staff (the
adult's later job, not their time as a resident). Staff named inside a
survivor story count when the story says what their job was; people who only
referred a child (psychiatrists, therapists, consultants outside the program)
do not.

## Output

Write `C:/Users/daniu/source/repos/Kids-Over-Profits/tmp/wiki/facts/wiki-<name>.json`
(`<name>` = the text file's name without `.txt`; valid JSON, UTF-8):

```json
{
  "issue": "wiki-<name>",
  "items": [ ... ]
}
```

Every item has:
- `kind`: `"person"`, `"incident"` or `"program"`.
- `program`: the program/school/company name as the page spells it.
- `place`: "City, ST" or state/country as the page gives it for that program, else "".
- `page`: 1.
- `quote`: text copied VERBATIM from the file, one contiguous passage, up to
  ~350 characters, line breaks replaced by single spaces. It must support the
  item on its own. Never paraphrase inside `quote`, and keep the page's own
  spelling mistakes (every quote is checked against the file mechanically; one
  that is not there is thrown out).
- `date`: the date the fact happened if the page gives one ("2007",
  "2007-03"), else "". "Currently"/"as of now" is not a date: leave it "".

`kind: "person"` (one item per person per program per event: someone who
founded a program and later left it gets a `founded` item and a `left` item):
- `person`: full name as written, credentials removed; "aka" names go in `note`.
- `credentials`: "PhD, LCSW" or "".
- `role`: job title at `program` ("Clinical Director"), or "". A relation
  ("sister of X", "married to Y") is not a role: put it in `note`.
- `event`: `holds_role` (the page says they work there now, or lists them
  with no tense), `joined`, `left` (the page says they left, were fired, or
  "worked"/"was" in the past tense), `promoted`, `founded`, `owns`, `retired`,
  `died`, `previously` (a past job named as background: then
  `program`/`role` are the PAST job and `note` says where they are now).
- `from_program`, `from_role`: where they came from when the page says, else "".
- `note`: anything else short and factual (years there, licence status,
  "arrested 2009 for ...", "also works at X").
A person whose bio names past jobs gets one item for the job the section is
about and one `previously` item per past program.

`kind: "incident"`:
- `category`: `death`, `abuse`, `sexual_abuse`, `restraint`, `injury`,
  `runaway`, `arrest`, `criminal_charge`, `lawsuit`, `investigation`,
  `license_action`, `closure_order`, `legislation`, `other`.
- `summary`: one plain factual sentence (who/what/when), no children's or
  survivors' names. Allegations stay allegations ("Survivors report that...",
  "A 2019 lawsuit alleges...").

`kind: "program"`:
- `field`: `opened`, `closed`, `renamed`, `acquired` (bought by someone),
  `merged`, `moved` (relocated), `new_campus`, `owner`, `operator` (company that
  runs it / parent company), `founder`, `capacity`, `ages`, `gender`,
  `program_type` (wilderness, TBS, RTC, emotional growth school, boot camp,
  psychiatric hospital, transport, etc.), `accreditation`, `membership` (NATSAP,
  IECA, etc.), `license`, `cost`, `length_of_stay`, `address`, `other`.
- `value`: the fact in short plain words: "Aspen Education Group" /
  "renamed from Cedar Ridge Ranch" / "moved from Montana to Idaho" / "35 students"
  / "boys 13-17" / "Joint Commission" / "opened 2012".
- `note`: optional short context.

The header line gives years and place: "Elan School (1970-2011) Poland
Spring, ME" is `opened` 1970 and `closed` 2011 (quote the header line), and
the type line under it is `program_type`. On a state list, each row is the
program's `opened` year and place; a row with only a name gives nothing. In a
table of closed programs, a year range is `opened` and `closed`; a lone year
is `closed` with that year only when the column says it is the closing year,
else `other` with value "listed as closed, year given: 1977".

Be exhaustive: every named staff member with their program, every
opened/closed/sold/renamed/moved program, every incident the page describes
in words. Do not invent or infer facts the text does not state. Programs
outside the US count too.

When done, print one line per file: `<name>: N items`.
'''


def wiki_pages():
    """Every wiki page once: {key, file, title, url, date, body (plain text), md}. scripts/wiki-links.py reads the same pages."""
    dates = git_dates()
    pages, seen_hash, seen_key, titles = [], set(), {}, set()
    files = sorted(f for f in os.listdir(MD) if f.endswith('.md') and not f.startswith('empty'))
    # index_x.md before x.md and index_x_.md: the index copy is the page the wiki links to.
    files.sort(key=lambda f: (key_of(f), not f.startswith('index_'), f.endswith('_.md')))
    for fn in files:
        md = open(os.path.join(MD, fn), encoding='utf-8').read()
        body = plain(md)
        h = hashlib.md5(body.encode('utf-8')).hexdigest()
        k = key_of(fn)
        if h in seen_hash or k in OFF_TOPIC:
            continue
        if k in seen_key and len(body) <= len(seen_key[k]['body']):
            continue
        seen_hash.add(h)
        t = title_of(md) or k
        p = {'key': k, 'file': fn, 'title': t, 'url': slug_url(fn), 'date': dates.get(fn, '2025-12-16'), 'body': body, 'md': md}
        if k in seen_key:
            pages[pages.index(seen_key[k])] = p
        else:
            pages.append(p)
        seen_key[k] = p
        titles.add(norm_title(t))
    # The wiki editor kept copies of pages the export lacks (company and person pages).
    if os.path.exists(DB):
        con = sqlite3.connect(DB)
        rows = con.execute("SELECT id, program_name, original_markdown, COALESCE(reviewed_at, updated_at) FROM wiki_submissions "
                           "WHERE status != 'rejected' AND LENGTH(original_markdown) > 500 ORDER BY id DESC").fetchall()
        for sid, name, md, when in rows:
            t = title_of(md) or name
            nt = norm_title(t)
            body = plain(md)
            h = hashlib.md5(body.encode('utf-8')).hexdigest()
            if not nt or nt in titles or h in seen_hash:
                continue
            seen_hash.add(h)
            titles.add(nt)
            k = 'sub-%d' % sid
            pages.append({'key': k, 'file': '', 'title': t, 'url': WIKI + 'index', 'date': (when or '')[:10] or '2025-12-16',
                          'body': body, 'md': md})
    return pages


def cmd_text():
    os.makedirs(TEXT, exist_ok=True)
    pages = wiki_pages()
    issues = []
    for p in pages:
        name = re.sub(r'[^a-z0-9-]+', '-', p['key'].lower()).strip('-')
        with open(os.path.join(TEXT, name + '.txt'), 'w', encoding='utf-8') as f:
            f.write('WIKI %s title=%r\n=== PAGE 1 ===\n[Wiki page as of %s]\n%s\n' % (p['url'], p['title'], p['date'], p['body']))
        issues.append({'date': 'wiki-' + name, 'file': name + '.txt', 'pub': PUB,
                       'label': 'page "%s"' % p['title'][:90], 'number': '', 'id': 0, 'url': p['url'],
                       'page_urls': {'1': p['url']}, 'page_dates': {'1': p['date']}, 'date_word': 'as of',
                       'page_years': {'1': int(p['date'][:4])}, 'title': p['title'], 'chars': content_chars(p['body'])})
    with open(os.path.join(OUT, 'issues.json'), 'w', encoding='utf-8') as f:
        json.dump(issues, f, indent=1, ensure_ascii=False)
    with open(os.path.join(OUT, 'INSTRUCTIONS.md'), 'w', encoding='utf-8') as f:
        f.write(INSTRUCTIONS)
    os.makedirs(os.path.join(OUT, 'facts'), exist_ok=True)
    print('%d pages -> %s (%d chars)' % (len(issues), TEXT, sum(i['chars'] for i in issues)))


def cmd_batches(a):
    """Pages not read yet, grouped so each reader gets about the same amount of text; stubs left out."""
    issues = json.load(open(os.path.join(OUT, 'issues.json'), encoding='utf-8'))
    fdir = os.path.join(OUT, 'facts')
    done = set(f[:-5] for f in os.listdir(fdir)) if os.path.isdir(fdir) else set()
    bdir = os.path.join(OUT, 'batches')
    os.makedirs(bdir, exist_ok=True)
    queued = set()
    for fn in os.listdir(bdir):
        queued.update(x.strip() for x in open(os.path.join(bdir, fn), encoding='utf-8') if x.strip())
    todo, stubs = [], 0
    for i in issues:
        if i['date'] in done or i['file'] in queued:
            continue
        # A header and empty section titles: nothing to read, unless the header has the years and place.
        head = open(os.path.join(TEXT, i['file']), encoding='utf-8').read(600).split('\n')[3:4]
        if i['chars'] < a.min_chars and not (head and re.search(r'\((\d{4}|\?)\s*[-–]', head[0])):
            stubs += 1
            continue
        todo.append((i['file'], i['chars']))
    batches, cur, size = [], [], 0
    for f, n in sorted(todo, key=lambda x: -x[1]):
        if cur and size + n > a.chars:
            batches.append(cur)
            cur, size = [], 0
        cur.append(f)
        size += n
    if cur:
        batches.append(cur)
    start = len([f for f in os.listdir(bdir) if f.startswith(a.prefix)])
    for k, b in enumerate(batches, start):
        with open(os.path.join(bdir, '%s%02d.txt' % (a.prefix, k)), 'w', encoding='utf-8') as fh:
            fh.write('\n'.join(b) + '\n')
    print('%d pages in %d batches (%d chars); %d stubs left out' % (len(todo), len(batches), sum(n for _, n in todo), stubs))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('cmd', choices=['text', 'batches'])
    ap.add_argument('--chars', type=int, default=220000)
    ap.add_argument('--min-chars', type=int, default=600)
    ap.add_argument('--prefix', default='b')
    a = ap.parse_args()
    if a.cmd == 'text':
        cmd_text()
    else:
        cmd_batches(a)


if __name__ == '__main__':
    main()
