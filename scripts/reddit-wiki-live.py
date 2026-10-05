"""
The live r/troubledteens wiki against our program wiki entries (wiki_submissions).

    python scripts/reddit-wiki-live.py fetch [--pages index/a active-programs/b] [--refresh] [--delay 8]
    python scripts/sync-prod-sqlite.py            # so the entries are current
    python scripts/reddit-wiki-live.py compare [--list]

fetch reads every wiki page in a real Chrome window (Reddit refuses scripts and its
.json API, and serves no markdown source, so this is the page as a reader sees it)
into tmp/reddit-wiki-live/pages/<page, / as __>.json: its words, whether it exists, and the
date Reddit last revised it. Resumable; pages already read are skipped unless
--refresh. When Reddit shows its "Prove your humanity" check, the script waits for
you to solve it in the window; the profile in tmp/reddit-wiki-live/profile keeps
the cookie for the next run.

compare pairs each entry with the page it came from (json_data.sourceSlug, or the
"Bulk uploaded from file: <page>.md" note the import left; never by name, so two
programs called "Northwest Academy" are not mixed up) and compares the words,
ignoring the contact line, link addresses and formatting. Writes
js/data/reddit-wiki/live-compare.json, read by api/lib-wiki-contact.php (the wiki
editor's index badge and the /wiki-feed/ note). An entry saved after the check
shows as changed since then, not as matching.
"""

import argparse
import datetime
import difflib
import glob
import html
import json
import os
import re
import sqlite3
import sys
import time

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tmp', 'reddit-wiki-live')
PAGES = os.path.join(OUT, 'pages')
PROFILE = os.path.join(OUT, 'profile')
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
RESULT = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'live-compare.json')
WIKI = 'https://www.reddit.com/r/troubledteens/wiki/'
SUB_CREATED = '2011-03-26'   # the subreddit's own date, printed on every page
IMPORT_SUBMITTERS = {'bulk-upload', 'batch-import-script', 'reimport-regenerated', 'import', 'system'}
DIFF_WORDS = 3               # more changed words than this = differs


def file_page(name):
    """The import's file name -> the wiki page it was read from: 'index_carlbrook.md' -> index/carlbrook,
    'active-programs_cedu.md' -> active-programs/cedu, 'index_wwasp_#wiki_level_system.md' -> index/wwasp
    (an anchor on that page). The export dropped the index_ prefix from most files: 'achreekranch.md'
    is index/achreekranch and 'active-programs_cedu.md' is index/active-programs/cedu, while a few
    ('standards') are top-level pages. So a name without index/ stays as it is here, and
    resolve_page() picks index/<name> when that page exists, else <name>."""
    s = re.sub(r'\.md$', '', os.path.basename(name.strip()), flags=re.I)
    s = s.split('#', 1)[0].rstrip('_')
    return s.replace('_', '/').lower()


def page_file(page):
    return os.path.join(PAGES, page.replace('/', '__') + '.json')


def all_pages():
    pages = set()
    for f in glob.glob(os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'programs-*.json')):
        for p in json.load(open(f, encoding='utf-8')).get('programs', []):
            m = re.search(r'/wiki/(.+?)/?$', p.get('url') or '')
            if m:
                pages.add(m.group(1).lower())
    if os.path.exists(DB):
        con = sqlite3.connect(DB)
        for (notes,) in con.execute("SELECT submission_notes FROM wiki_submissions WHERE submission_notes LIKE 'Bulk uploaded from file:%'"):
            page = file_page(notes.split(':', 1)[1])
            pages.add(page)
            if not page.startswith('index/'):
                pages.add('index/' + page)
                pages.add('index/' + page.rsplit('/', 1)[-1])
                pages.add(page.rsplit('/', 1)[-1])
    return sorted(s for s in pages if re.fullmatch(r'[a-z0-9_\-]+(?:/[a-z0-9_\-]+)*', s))


def migrate_page_files():
    """The first runs stored index pages as <slug>.json: rename them to index__<slug>.json."""
    for f in glob.glob(os.path.join(PAGES, '*.json')):
        if '__' in os.path.basename(f):
            continue
        rec = json.load(open(f, encoding='utf-8'))
        if 'page' not in rec:
            rec['page'] = 'index/' + rec.pop('slug')
            json.dump(rec, open(page_file(rec['page']), 'w', encoding='utf-8'), ensure_ascii=False)
            os.remove(f)


# --- fetch ---------------------------------------------------------------------

READ_PAGE = """() => {
    const text = (e) => (e ? e.innerText || '' : '');
    const missing = /does not exist/.test(text(document.body).slice(0, 400));
    let el = null, how = '';
    for (const sel of ['[data-testid="wiki-page-content"]', '.md.wiki', 'div.wiki-page-content', 'main']) {
        const e = document.querySelector(sel);
        if (e && text(e).trim().length > 20) { el = e; how = sel; break; }
    }
    if (!el) { el = document.body; how = 'body'; }
    const dates = [...document.querySelectorAll('time, faceplate-timeago')]
        .map(x => x.getAttribute('datetime') || x.getAttribute('ts') || '').filter(Boolean);
    return { missing, how, text: text(el), dates };
}"""


def is_challenge(page):
    try:
        return 'prove your humanity' in (page.title() or '').lower()
    except Exception:
        return False


def wait_for_human(page):
    print('\n  Reddit is asking for its human check. Solve it in the Chrome window; waiting up to 10 minutes...', flush=True)
    try:
        page.bring_to_front()
    except Exception:
        pass
    for _ in range(120):
        time.sleep(5)
        if not is_challenge(page):
            print('  thanks, carrying on', flush=True)
            return True
    return False


def fetch(args):
    from playwright.sync_api import sync_playwright
    os.makedirs(PAGES, exist_ok=True)
    migrate_page_files()
    pages = [s.lower().strip('/') for s in args.pages] if args.pages else all_pages()
    todo = [s for s in pages if args.refresh or not os.path.exists(page_file(s))]
    print(f'{len(pages)} pages, {len(todo)} to read', flush=True)
    with sync_playwright() as p:
        def open_browser():
            c = p.chromium.launch_persistent_context(PROFILE, channel='chrome', headless=False, viewport={'width': 1100, 'height': 800})
            return c, (c.pages[0] if c.pages else c.new_page())

        def close_browser(c):
            try:
                c.close()
            except Exception:
                pass

        ctx, page = open_browser()
        failed_in_a_row = 0
        for i, slug in enumerate(todo, 1):   # slug = the page, e.g. index/carlbrook
            # A Chrome window left open for hundreds of pages runs out of memory:
            # start a fresh one (same profile, so the human check's cookie stays).
            # Three pages in a row refused is Reddit's rate limit (or a dead
            # window): close it, wait 10, then 20, then 30 minutes, and go on.
            if failed_in_a_row and failed_in_a_row % 3 == 0:
                if failed_in_a_row >= 12:
                    print('  Reddit still refuses after three long waits; stopping (run again later to resume)')
                    close_browser(ctx)
                    return 1
                wait = 10 * failed_in_a_row // 3
                close_browser(ctx)
                print(f'  {failed_in_a_row} pages in a row refused; waiting {wait} minutes', flush=True)
                time.sleep(wait * 60)
                ctx, page = open_browser()
                failed_in_a_row += 1   # wait once per three
            elif i > 1 and i % 150 == 1:
                close_browser(ctx)
                ctx, page = open_browser()
            for attempt in range(3):
                try:
                    page.goto(WIKI + slug + '/', wait_until='domcontentloaded', timeout=45000)
                    page.wait_for_timeout(2500)
                    if is_challenge(page):
                        if not wait_for_human(page):
                            print('  no one solved the check; stopping (run again to resume)')
                            ctx.close()
                            return 1
                        continue
                    got = page.evaluate(READ_PAGE)
                    break
                except Exception as e:
                    print(f'  {slug}: {e.__class__.__name__}: {str(e).splitlines()[0][:120] if str(e) else ""}, retrying', flush=True)
                    time.sleep(5)   # the page itself may be gone
            else:
                print(f'  {slug}: gave up')
                failed_in_a_row += 1
                continue
            failed_in_a_row = 0
            revised = next((d[:10] for d in got['dates'] if not d.startswith(SUB_CREATED)), '')
            rec = {
                'page': slug,
                'exists': not got['missing'],
                'revised': revised if not got['missing'] else '',
                'fetched': datetime.datetime.now().strftime('%Y-%m-%d'),
                'how': got['how'],
                'text': '' if got['missing'] else got['text'],
            }
            json.dump(rec, open(page_file(slug), 'w', encoding='utf-8'), ensure_ascii=False)
            print(f'[{i}/{len(todo)}] {slug}: ' + ('missing' if got['missing'] else f"{len(rec['text'])} chars, revised {revised or '?'}, via {got['how']}"), flush=True)
            time.sleep(args.delay)
        ctx.close()
    return 0


# --- compare -------------------------------------------------------------------

HANDLES = r'(?:Miss_Nobody89|Signal-Strain\d+)'


def words_md(md):
    """Our markdown -> the words a reader sees."""
    md = (md or '').replace('\r', '')
    md = re.sub(r'(?is)\n?\s*Last revised by.*$', '', md)
    md = re.sub(r'!\[[^\]]*\]\([^)]*\)', ' ', md)                 # images
    md = re.sub(r'\[([^\]]*)\]\([^)]*\)', r'\1', md)                # links -> their text
    md = re.sub(r'\^\(([^)]*)\)', r'\1', md).replace('^', '')       # superscript
    md = re.sub(r'(?m)^\s*\d+[.)]\s+', '', md)                      # list numbers (not in the page's text)
    md = re.sub(r'[*~]+', '', md)                                    # emphasis, also inside a word: group**s**
    md = re.sub(r'\\(.)', r'\1', md)                                 # markdown escapes: CEDU\'s
    return words_text(html.unescape(md))


def words_text(t):
    """Page text -> lowercase words, without contact handles, addresses or apostrophes."""
    t = re.sub(r'https?://\S+', ' ', t or '')
    t = re.sub(r'(?i)\b(?:www\.)?[\w-]+\.(?:com|org|net|gov|edu|us)/\S*', ' ', t)   # addresses without https://
    # The contact line, whoever it names: Reddit's pages now say u/shroomskillet, ours the modmail
    t = re.sub(r'(?i)\bcontact\s+(?:the\s+)?(?:/?u/[\w-]+|r/troubledteens modmail)', 'contact', t)
    t = re.sub(r'(?i)/?u(?:ser)?/' + HANDLES + r'/?|' + HANDLES, ' ', t)
    t = re.sub(r'(?i)r/troubledteens modmail', ' ', t)
    t = re.sub(r"['’]", '', t.lower())
    return re.findall(r'[a-z0-9]+', t)


def row_slugs(rows):
    """wiki_submissions id -> the page it came from (or None)."""
    own = {}
    for r in rows:
        slug = ''
        try:
            slug = (json.loads(r['json_data'] or '{}') or {}).get('sourceSlug') or ''
            if slug:
                slug = 'index/' + slug.strip('/')
        except ValueError:
            pass
        notes = r['submission_notes'] or ''
        if not slug and notes.lower().startswith('bulk uploaded from file:'):
            slug = file_page(notes.split(':', 1)[1])
        own[r['id']] = slug.lower()
    # An entry edited in the editor is a new row without the note: it takes the
    # page of the imported rows of the same program, when they all name one page.
    by_key = {}
    for r in rows:
        if own[r['id']]:
            for key in ('f:' + (r['facility_unique_name'] or '').strip().lower(), 'n:' + (r['program_name'] or '').strip().lower()):
                if key not in ('f:', 'n:'):
                    by_key.setdefault(key, set()).add(own[r['id']])
    out = {}
    for r in rows:
        slug = own[r['id']]
        if not slug:
            fac = (r['facility_unique_name'] or '').strip().lower()
            cands = by_key.get('f:' + fac) if fac else None
            if not cands:
                cands = by_key.get('n:' + (r['program_name'] or '').strip().lower())
            if cands and len(cands) == 1:
                slug = next(iter(cands))
        out[r['id']] = slug or None
    return out


def is_import(r):
    by = (r['submitted_by'] or '').strip().lower()
    notes = (r['submission_notes'] or '').strip()
    if by in IMPORT_SUBMITTERS or notes.lower().startswith(('batch imported', 'bulk')):
        return True
    return by == '' and notes == ''


def our_markdown(r):
    """An imported row's original (the Reddit text it came from); an edited row's generated markdown (the edit)."""
    try:
        j = json.loads(r['json_data'] or '{}') or {}
    except ValueError:
        j = {}
    original = (r['original_markdown'] or j.get('originalMarkdown') or '').strip()
    generated = (r['generated_markdown'] or j.get('generatedMarkdown') or '').strip()
    if is_import(r):
        return original or generated
    return generated or original


def snip(ws):
    s = ' '.join(ws)
    return s if len(s) <= 90 else s[:87] + '...'


def resolve_page(page, pages):
    """A name without index/ -> the first of index/<name>, <name>, index/<last part> and <last part>
    that exists (the export filed the organization pages, index/cedu, and some top-level ones,
    history, as active-programs_<name>.md)."""
    if not page or page.startswith('index/'):
        return page
    last = page.rsplit('/', 1)[-1]
    for cand in ('index/' + page, page, 'index/' + last, last):
        rec = pages.get(cand) if cand else None
        if rec and rec['exists']:
            return cand
    return page


def compare(args):
    if not os.path.exists(DB):
        sys.exit('tmp/prod.sqlite is missing: python scripts/sync-prod-sqlite.py')
    pages = {}
    migrate_page_files()
    for f in glob.glob(os.path.join(PAGES, '*.json')):
        rec = json.load(open(f, encoding='utf-8'))
        pages[rec['page']] = rec
    if not pages:
        sys.exit('no pages read yet: python scripts/reddit-wiki-live.py fetch')
    con = sqlite3.connect(DB)
    con.row_factory = sqlite3.Row
    rows = con.execute("SELECT id, program_name, facility_unique_name, json_data, generated_markdown, original_markdown, "
                       "submitted_by, submission_notes, updated_at FROM wiki_submissions "
                       "WHERE status NOT IN ('deleted', 'rejected')").fetchall()
    slugs = row_slugs(rows)
    out_rows, counts = {}, {'same': 0, 'differs': 0, 'missing': 0, 'unread': 0, 'unpaired': 0}
    for r in rows:
        slug = resolve_page(slugs[r['id']], pages)
        if not slug:
            counts['unpaired'] += 1
            continue
        page = pages.get(slug)
        if not page:
            counts['unread'] += 1
            continue
        rec = {'page': slug, 'updated_at': r['updated_at'] or '', 'edited': not is_import(r)}
        if not page['exists']:
            rec.update({'differs': True, 'changed': None, 'samples': []})
            counts['missing'] += 1
        else:
            a, b = words_text(page['text']), words_md(our_markdown(r))
            # A stretch with the same letters on both sides differs only in spacing
            # ("The*minimum*length" vs "the minimum length", "group**s**" vs "groups")
            # and a lone list number one side has ("Phase 1. During": Reddit reads "1." as a list marker)
            def counted(o):
                ra, rb = a[o[1]:o[2]], b[o[3]:o[4]]
                if ''.join(ra) == ''.join(rb):
                    return False
                lone = ra + rb
                return not (len(lone) == 1 and (not ra or not rb) and re.fullmatch(r'\d{1,2}', lone[0]))
            ops = [o for o in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes()
                   if o[0] != 'equal' and counted(o)]
            changed = sum(max(i2 - i1, j2 - j1) for _, i1, i2, j1, j2 in ops)
            differs = changed > DIFF_WORDS
            rec.update({'differs': differs, 'changed': changed,
                        'samples': [{'reddit': snip(a[i1:i2]), 'ours': snip(b[j1:j2])} for _, i1, i2, j1, j2 in ops[:3]] if differs else []})
            counts['differs' if differs else 'same'] += 1
        out_rows[str(r['id'])] = rec
    result = {
        'about': 'Program wiki entries against the live r/troubledteens wiki, from scripts/reddit-wiki-live.py',
        'checked': max(p['fetched'] for p in pages.values()),
        'pages': {s: {'exists': p['exists'], 'revised': p['revised'], 'fetched': p['fetched']} for s, p in sorted(pages.items())},
        'rows': dict(sorted(out_rows.items(), key=lambda kv: int(kv[0]))),
    }
    with open(RESULT, 'w', encoding='utf-8', newline='\n') as fh:
        json.dump(result, fh, ensure_ascii=False, indent=1)
        fh.write('\n')
    print(f"{len(pages)} pages read; entries: {counts}")
    print('wrote ' + os.path.relpath(RESULT, ROOT))
    if args.list:
        names = {str(r['id']): r['program_name'] for r in rows}
        for rid, rec in out_rows.items():
            if rec['differs']:
                what = 'not on Reddit' if rec['changed'] is None else f"{rec['changed']} words"
                print(f"  #{rid} {names[rid]} ({rec['page']}): {what}" + (' [edited here]' if rec['edited'] else ''))
                for s in rec['samples']:
                    print(f"      reddit: {s['reddit']}\n      ours:   {s['ours']}")
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    f = sub.add_parser('fetch')
    f.add_argument('--pages', nargs='*')
    f.add_argument('--refresh', action='store_true')
    f.add_argument('--delay', type=float, default=8)
    c = sub.add_parser('compare')
    c.add_argument('--list', action='store_true')
    args = ap.parse_args()
    return fetch(args) if args.cmd == 'fetch' else compare(args)


if __name__ == '__main__':
    sys.exit(main())
