"""
Which of Unsilenced's archive documents KOP has no copy of, per facility.

Unsilenced keeps its program archive in public Google Drive folders, one per
state (js/data/unsilenced/roots.json). The facility pages list the documents
from a program's Unsilenced folder that KOP does not hold itself, linked to
Unsilenced's Drive. A document counts as held when

  - its Drive md5 equals the md5 of a file in KOP's media library
    (mdd_hash / _kop_import_md5), wherever in the library it is filed, or
  - a media library file has the same distinctive name (KOP's copies of
    Unsilenced documents are often re-saved, so the bytes differ; names that
    recur, like "DocumentInquiry" or "Parent Manual", never count), or
  - it is a state inspection report KOP's scrapers already hold for that
    facility (Arizona INSP- numbers, North Carolina report numbers, California
    printouts of a visit date KOP has).

Unsilenced's program folders are tied to a facility only on an exact name
match (current, past or other name) in the same state, so adult care and
anything unmatched stays out.

Inputs
  tmp/unsilenced/files.jsonl   every archive file with its md5, from the
                               server: php api/list-unsilenced-files.php
                               (copy ~/kop-import/unsilenced/files.jsonl here)
  tmp/prod.sqlite              scripts/sync-prod-sqlite.py
  tmp/unsilenced-titles/titles.json
                               optional {file id: title} from the content
                               (scripts/unsilenced-backup.py titles); files
                               without one keep Unsilenced's name

Writes
  js/data/unsilenced/index.json          {facility id: documents listed}
  js/data/unsilenced/f/<facility id>.json one per facility (read by
                                          inc/unsilenced-archive.php)
  tmp/unsilenced/build-report.md          what was matched and left out

Usage
  python scripts/build-unsilenced-links.py [--files tmp/unsilenced/files.jsonl]
"""
import argparse
import collections
import datetime
import json
import os
import re
import sqlite3
import sys
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'js', 'data', 'unsilenced')

STATES = {
    'alabama': 'AL', 'alaska': 'AK', 'arizona': 'AZ', 'arkansas': 'AR', 'california': 'CA', 'colorado': 'CO',
    'connecticut': 'CT', 'delaware': 'DE', 'district-of-columbia': 'DC', 'florida': 'FL', 'georgia': 'GA',
    'hawaii': 'HI', 'idaho': 'ID', 'illinois': 'IL', 'indiana': 'IN', 'iowa': 'IA', 'kansas': 'KS',
    'kentucky': 'KY', 'louisiana': 'LA', 'maine': 'ME', 'maryland': 'MD', 'massachusetts': 'MA',
    'michigan': 'MI', 'minnesota': 'MN', 'mississippi': 'MS', 'missouri': 'MO', 'montana': 'MT',
    'nebraska': 'NE', 'nevada': 'NV', 'new-hampshire': 'NH', 'new-jersey': 'NJ', 'new-mexico': 'NM',
    'new-york': 'NY', 'north-carolina': 'NC', 'north-dakota': 'ND', 'ohio': 'OH', 'oklahoma': 'OK',
    'oregon': 'OR', 'pennsylvania': 'PA', 'rhode-island': 'RI', 'south-carolina': 'SC', 'south-dakota': 'SD',
    'tennessee': 'TN', 'texas': 'TX', 'utah': 'UT', 'vermont': 'VT', 'virginia': 'VA', 'washington': 'WA',
    'west-virginia': 'WV', 'wisconsin': 'WI', 'wyoming': 'WY', 'non-us-program': '',
}

# Words that say nothing about which program a name means.
GENERIC = set('academy school center centre home homes house ranch residential treatment youth services behavioral '
              'health hospital group program programs boys girls children childrens kids teen teens family families '
              'care foundation lodge camp institute rtc prtf facility for'.split())
STOP = re.compile(r'\b(the|llc|l l c|inc|incorporated|corp|corporation|co|company|pllc|pc|ltd|of|and|dba|d b a)\b')

# Adult congregate care, left out whatever it matches: Arizona's habilitation
# and supported-living homes, New York's adult residences, memory care.
# Young-adult transition programs are part of the industry and stay in.
ADULT = re.compile(r"\b((?<!young )adults?|seniors?|elder(ly)?|assisted living|nursing|memory care|geriatric|habilitation|"
                   r"supported living|supportive living|recovery residence|sober living|halfway house|icf|ira|"
                   r"opwdd|veterans?)\b", re.I)

# Files that are not documents.
JUNK = re.compile(r'(^desktop\.ini$|^thumbs\.db$|\.(ini|json|db|ds_store|lnk|tmp)$)', re.I)


def norm(s):
    s = unicodedata.normalize('NFKD', s or '').encode('ascii', 'ignore').decode().lower()
    s = s.replace('&', ' and ').replace('_', ' ')
    s = re.sub(r'[^a-z0-9 ]', ' ', s)
    s = STOP.sub(' ', s)
    return re.sub(r'\s+', ' ', s).strip()


def name_variants(folder):
    """A folder name and the parts of it that could each be a program name:
    "Utah Boys Ranch_West Ridge Academy", "Hyde School (1)",
    "WINSHAPE HOMES [CCI001060]", "ROSEMARY COLE SFH - 337900249"."""
    f = re.sub(r'\[[^\]]*\]|\s-\s*\d{6,}$|\s*\(\d+\)$', '', folder)
    f = re.sub(r'\b(HQ|Corporate)\b', '', f, flags=re.I)
    parts = {f}
    for sep in (' - ', '_', '/', ' dba ', ' DBA ', ' aka '):
        for p in list(parts):
            parts |= set(p.split(sep))
    for p in list(parts):
        parts |= set(re.findall(r'\(([^)]+)\)', p))
        parts.add(re.sub(r'\([^)]*\)', '', p))
    whole = {norm(f), norm(re.sub(r'\([^)]*\)', '', f))}
    out = set()
    for n in (norm(p) for p in parts):
        words = distinctive(n)
        # A piece of a split name has to say enough on its own: Arizona's
        # "PROVIDER _ OASIS" group homes must not land on any "Oasis".
        if len(n) >= 3 and words and (n in whole or len(words) >= 2):
            out.add(n)
    return out


def distinctive(key):
    """The words of a normalised name that are not program-type words."""
    return frozenset(t for t in key.split() if t not in GENERIC and not t.isdigit())


def iso_date(text):
    text = (text or '').strip().split(' - ')[0]
    for fmt in ('%m/%d/%Y', '%Y-%m-%d', '%B %d, %Y'):
        try:
            return datetime.datetime.strptime(text, fmt).date().isoformat()
        except ValueError:
            pass
    return ''


def load_kop(db):
    """KOP's side: facility and operator names, media md5s, inspection coverage."""
    facilities = collections.defaultdict(set)  # (state, norm name) -> {facility id}
    for fid, name, state, js in db.execute("SELECT id, name, COALESCE(state, ''), json_data FROM facilities_v2"):
        try:
            ident = (json.loads(js or '{}') or {}).get('identification') or {}
        except ValueError:
            ident = {}
        names = [name, ident.get('name'), ident.get('currentName')]
        for key in ('pastNames', 'otherNames'):
            for n in ident.get(key) or []:
                names.append(n if isinstance(n, str) else (n or {}).get('name'))
        for n in names:
            k = norm(n)
            if k:
                facilities[((state or '').upper(), k)].add(int(fid))
    # (state, distinctive words) -> {facility id}, for names that differ only
    # in type words ("Spring Creek Lodge" / "Spring Creek Lodge Academy").
    by_words = collections.defaultdict(set)
    for (state, k), ids in facilities.items():
        words = distinctive(k)
        if words and len(' '.join(sorted(words))) >= 5:
            by_words[(state, words)] |= ids

    operators = collections.defaultdict(set)  # norm name -> {operator id}
    for oid, uname, name, js in db.execute('SELECT id, unique_name, name, json_data FROM wpdl_kop_operators'):
        try:
            op = (json.loads(js or '{}') or {}).get('operator') or {}
        except ValueError:
            op = {}
        for n in [uname, name, op.get('currentName')] + list(op.get('otherNames') or []):
            if isinstance(n, str) and n.strip():
                for v in name_variants(n):
                    operators[v].add(int(oid))
                    operators[re.sub(r's$', '', v)].add(int(oid))

    # File names and titles in the media library, for copies whose bytes
    # differ from Unsilenced's.
    names = collections.Counter()
    for title, path in db.execute(
            "SELECT p.post_title, m.meta_value FROM wpdl_posts p JOIN wpdl_postmeta m "
            "ON m.post_id = p.ID AND m.meta_key = '_wp_attached_file' WHERE p.post_type = 'attachment'"):
        for k in {file_key(os.path.basename(path or '')), file_key(title or '')}:
            if k:
                names[k] += 1

    md5s = set()
    for (h,) in db.execute("SELECT meta_value FROM wpdl_postmeta WHERE meta_key IN ('mdd_hash', '_kop_import_md5')"):
        if h and re.fullmatch(r'[0-9a-fA-F]{32}', h.strip()):
            md5s.add(h.strip().lower())

    report_ids = set()
    report_dates = collections.defaultdict(set)  # (state, norm facility name) -> {iso date}
    for rid, rdate, state, fname in db.execute(
            'SELECT r.report_id, r.report_date, f.state, f.facility_name FROM inspection_reports r '
            'JOIN inspection_facilities f ON f.id = r.facility_id'):
        report_ids.add((rid or '').lower().replace('.pdf', ''))
        d = iso_date(rdate)
        if d:
            report_dates[((state or '').upper(), norm(fname))].add(d)
    return facilities, by_words, operators, md5s, names, report_ids, report_dates


def file_key(name):
    """A file name reduced for comparison: no extension, no copy markers
    ("(1)", "Copy of", WordPress's "-pdf.jpg" preview suffix), letters and
    digits only. A trailing number stays: DocumentInquiry-3 is not -26."""
    n = unicodedata.normalize('NFKD', name or '').encode('ascii', 'ignore').decode().lower().strip()
    n = re.sub(r'-pdf\.jpe?g$', '', n)
    n = re.sub(r'\.(pdf|jpe?g|png|gif|webp|docx?|tiff?|rtf|txt|xlsx?|mp4|html?)$', '', n)
    n = re.sub(r'\s*\(\d+\)$', '', n)
    n = re.sub(r'^copy of ', '', n)
    return re.sub(r'[^a-z0-9]', '', n)


def match_program(folder, state, facilities, by_words, operators):
    """('f', ids) for facility records, ('o', ids) for an operator, or None.
    Exact names first; then the same distinctive words, when only one
    facility in the state has them; then an operator by name."""
    if ADULT.search(folder):
        return None
    variants = name_variants(folder)
    # A folder named after an operator ("Teen Challenge", "Devereux
    # Foundation") holds the operator's papers, not one program's.
    whole = norm(re.sub(r'\([^)]*\)|\[[^\]]*\]|\s-\s*\d{6,}$|\b(HQ|Corporate)\b', '', folder))
    ops = operators.get(whole, set()) | operators.get(re.sub(r's$', '', whole), set())
    if len(ops) == 1:
        return 'o', ops, 'operator'
    ids = set()
    for v in variants:
        ids |= facilities.get((state, v), set())
    if ids:
        return 'f', ids, 'exact'
    for v in variants:
        hit = by_words.get((state, distinctive(v)), set())
        if len(hit) == 1:
            ids |= hit
    if ids:
        return 'f', ids, 'same words'
    for v in variants:
        ids |= operators.get(v, set()) | operators.get(re.sub(r's$', '', v), set())
    if len(ids) == 1:
        return 'o', ids, 'operator'
    return None


def held_as_inspection(name, state, program_keys, report_ids, report_dates):
    """True when the file is a state report KOP's scrapers already hold."""
    low = name.lower()
    m = re.search(r'insp-\d+', low)
    if m and m.group(0) in report_ids:
        return True
    m = re.search(r'\d{8}-\d{6}', name)
    if m and m.group(0) in report_ids:
        return True
    m = re.match(r'(\d{4}-\d{2}-\d{2}) (inspection checklist|all visit date|complaint visit detail|report)', low)
    if m and state == 'CA':
        return any(m.group(1) in report_dates.get(('CA', k), ()) for k in program_keys)
    return False


def main():
    ap = argparse.ArgumentParser(description=__doc__.split('\n\n')[0])
    ap.add_argument('--files', default=os.path.join(ROOT, 'tmp', 'unsilenced', 'files.jsonl'))
    ap.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    ap.add_argument('--titles', default=os.path.join(ROOT, 'tmp', 'unsilenced-titles', 'titles.json'))
    args = ap.parse_args()
    for p in (args.files, args.db):
        if not os.path.exists(p):
            sys.exit('Missing ' + p + ' (see the usage at the top of this script).')

    rows, seen = [], set()
    for line in open(args.files, encoding='utf8'):
        line = line.strip()
        if not line:
            continue
        r = json.loads(line)
        if r['id'] in seen:  # a resumed server run can repeat a folder
            continue
        seen.add(r['id'])
        rows.append(r)
    no_md5 = sum(1 for r in rows if not r.get('md5'))

    titles = {}
    if os.path.exists(args.titles):
        with open(args.titles, encoding='utf8') as fh:
            titles = {k: v.strip() for k, v in json.load(fh).items() if isinstance(v, str) and v.strip()}

    db = sqlite3.connect(args.db)
    facilities, by_words, operators, md5s, kop_names, report_ids, report_dates = load_kop(db)
    # A name only identifies a document when it is long enough and neither
    # side uses it for several different files.
    un_names = collections.Counter(file_key(r['name']) for r in rows)
    def same_name_held(name):
        k = file_key(name)
        return len(k) >= 12 and kop_names.get(k, 0) >= 1 and un_names[k] <= 2

    # Program folder (state slug, folder name) -> ('f' or 'o', ids, how).
    programs = {}
    for r in rows:
        if len(r['path']) >= 2:
            key = (r['path'][0], r['path'][1])
            if key not in programs:
                programs[key] = match_program(key[1], STATES.get(key[0], ''), facilities, by_words, operators)

    lists = {'f': collections.defaultdict(dict), 'o': collections.defaultdict(dict)}  # id -> {md5 or id: entry}
    stats = collections.Counter()
    unmatched = collections.Counter()
    for r in rows:
        if len(r['path']) < 2:
            stats['loose file at state level'] += 1
            continue
        key = (r['path'][0], r['path'][1])
        match = programs.get(key)
        if JUNK.search(r['name']):
            stats['not a document'] += 1
            continue
        if not match:
            unmatched[key] += 1
            stats['program not matched to KOP'] += 1
            continue
        md5 = (r.get('md5') or '').lower()
        if md5 and md5 in md5s:
            stats['KOP has the same file'] += 1
            continue
        if same_name_held(r['name']):
            stats['KOP has a copy under the same name'] += 1
            continue
        if held_as_inspection(r['name'], STATES.get(key[0], ''), name_variants(key[1]), report_ids, report_dates):
            stats['KOP has the inspection report'] += 1
            continue
        stats['listed (%s)' % ('facility' if match[0] == 'f' else 'operator')] += 1
        entry = [r['id'], r['name'], ' / '.join(r['path'][2:])]
        for i in match[1]:
            # Unsilenced sometimes files the same document twice.
            lists[match[0]][i].setdefault(md5 or r['id'], entry)

    # Output: one shard per facility (f/) and operator (o/), and the index
    # the page builds read.
    folder_ids = {}
    for r in rows:
        if len(r['path']) == 2 and r.get('parent'):
            folder_ids[(r['path'][0], r['path'][1])] = r['parent']
    index = {'f': {}, 'o': {}}
    titled = 0
    for kind in ('f', 'o'):
        shard_dir = os.path.join(OUT, kind)
        os.makedirs(shard_dir, exist_ok=True)
        for old in os.listdir(shard_dir):
            if old.endswith('.json'):
                os.remove(os.path.join(shard_dir, old))
        for i, entries in sorted(lists[kind].items()):
            files = sorted(entries.values(), key=lambda e: (e[2].lower(), e[1].lower()))
            folders = sorted({(k[1], folder_ids.get(k, '')) for k, m in programs.items()
                              if m and m[0] == kind and i in m[1]})
            shard = {
                'folders': [{'name': n, 'id': fid} for n, fid in folders],
                # title: what the document is, read from its content, when
                # Unsilenced's name says little; the page shows the name too.
                'files': [dict({'id': e[0], 'name': e[1], 'folder': e[2]},
                               **({'title': titles[e[0]]} if titles.get(e[0], e[1]) != e[1] else {}))
                          for e in files],
            }
            with open(os.path.join(shard_dir, '%d.json' % i), 'w', encoding='utf8', newline='\n') as fh:
                json.dump(shard, fh, ensure_ascii=False, separators=(',', ':'))
            index[kind][str(i)] = len(files)
            titled += sum(1 for f in shard['files'] if 'title' in f)
    with open(os.path.join(OUT, 'index.json'), 'w', encoding='utf8', newline='\n') as fh:
        # Google Docs have no md5; everything else must have one.
        # titled moves the pages' cache key when only the titles change.
        json.dump({'built': datetime.date.today().isoformat(), 'md5_checked': no_md5 <= len(rows) // 1000,
                   'titled': titled, 'facilities': index['f'], 'operators': index['o']}, fh, separators=(',', ':'))

    how = collections.Counter(m[2] for m in programs.values() if m)
    report = [
        '# Unsilenced archive build', '',
        'Built %s from %d archive files (%d without an md5).' % (datetime.date.today().isoformat(), len(rows), no_md5), '',
        '| Outcome | Files |', '|---|---:|',
    ] + ['| %s | %d |' % (k, v) for k, v in stats.most_common()] + [
        '', 'Program folders matched: %s; not matched: %d.' % (dict(how), sum(1 for m in programs.values() if not m)),
        '%d facilities and %d operators get a list (%d documents in all).' % (
            len(index['f']), len(index['o']), sum(index['f'].values()) + sum(index['o'].values())), '',
        '## Matched on the same distinctive words (check these)', '',
    ] + ['- %s (%s)' % (k[1], STATES.get(k[0], k[0]) or 'non-US') for k, m in sorted(programs.items())
         if m and m[2] == 'same words'] + [
        '', '## Largest program folders not matched', '',
        'Adult care, and programs KOP has no record of or names differently. Add a past',
        'or other name to the facility record to tie one in; the next build picks it up.', '',
    ] + ['- %s (%s): %d files' % (k[1], STATES.get(k[0], k[0]) or 'non-US', n) for k, n in unmatched.most_common(200)]
    os.makedirs(os.path.join(ROOT, 'tmp', 'unsilenced'), exist_ok=True)
    with open(os.path.join(ROOT, 'tmp', 'unsilenced', 'build-report.md'), 'w', encoding='utf8') as fh:
        fh.write('\n'.join(report) + '\n')

    for k, v in stats.most_common():
        print('%8d  %s' % (v, k))
    print('%d facilities, %d operators -> js/data/unsilenced/ (report: tmp/unsilenced/build-report.md)' % (
        len(index['f']), len(index['o'])))
    if titles:
        print('%d of the listed files titled from their content (%s)' % (
            len({e[0] for kind in lists.values() for d in kind.values() for e in d.values() if e[0] in titles}),
            os.path.relpath(args.titles, ROOT)))
    if no_md5:
        print('WARNING: %d files have no md5, so a copy KOP holds under another name is not recognised.' % no_md5)


if __name__ == '__main__':
    main()
