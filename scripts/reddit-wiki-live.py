"""
The live r/troubledteens wiki against our program wiki entries (wiki_submissions).

    python scripts/reddit-wiki-live.py fetch [--slugs a b] [--refresh] [--delay 4]
    python scripts/sync-prod-sqlite.py            # so the entries are current
    python scripts/reddit-wiki-live.py compare [--list]

fetch reads every wiki page in a real Chrome window (Reddit refuses scripts and its
.json API, and serves no markdown source, so this is the page as a reader sees it)
into tmp/reddit-wiki-live/pages/<slug>.json: its words, whether it exists, and the
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
WIKI = 'https://www.reddit.com/r/troubledteens/wiki/index/'
SUB_CREATED = '2011-03-26'   # the subreddit's own date, printed on every page
IMPORT_SUBMITTERS = {'bulk-upload', 'batch-import-script', 'reimport-regenerated', 'import', 'system'}
DIFF_WORDS = 3               # more changed words than this = differs


def file_slug(name):
    """'index_carlbrook.md' / 'index_x_.md' / 'BSflorida.md' -> the page slug."""
    s = re.sub(r'\.md$', '', os.path.basename(name.strip()), flags=re.I)
    s = re.sub(r'^index_', '', s, flags=re.I).rstrip('_')
    return s.lower()


def all_slugs():
    slugs = set()
    for f in glob.glob(os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'programs-*.json')):
        for p in json.load(open(f, encoding='utf-8')).get('programs', []):
            m = re.search(r'/wiki/(?:index/)?([^/?#]+)', p.get('url') or '')
            if m:
                slugs.add(m.group(1).lower())
    if os.path.exists(DB):
        con = sqlite3.connect(DB)
        for (notes,) in con.execute("SELECT submission_notes FROM wiki_submissions WHERE submission_notes LIKE 'Bulk uploaded from file:%'"):
            slugs.add(file_slug(notes.split(':', 1)[1]))
    return sorted(s for s in slugs if re.fullmatch(r'[a-z0-9_\-]+', s))


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
    slugs = [s.lower() for s in args.slugs] if args.slugs else all_slugs()
    todo = [s for s in slugs if args.refresh or not os.path.exists(os.path.join(PAGES, s + '.json'))]
    print(f'{len(slugs)} pages, {len(todo)} to read', flush=True)
    with sync_playwright() as p:
        ctx = p.chromium.launch_persistent_context(PROFILE, channel='chrome', headless=False, viewport={'width': 1100, 'height': 800})
        page = ctx.pages[0] if ctx.pages else ctx.new_page()
        for i, slug in enumerate(todo, 1):
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
                    print(f'  {slug}: {e.__class__.__name__}, retrying', flush=True)
                    page.wait_for_timeout(5000)
            else:
                print(f'  {slug}: gave up')
                continue
            revised = next((d[:10] for d in got['dates'] if not d.startswith(SUB_CREATED)), '')
            rec = {
                'slug': slug,
                'exists': not got['missing'],
                'revised': revised if not got['missing'] else '',
                'fetched': datetime.datetime.now().strftime('%Y-%m-%d'),
                'how': got['how'],
                'text': '' if got['missing'] else got['text'],
            }
            json.dump(rec, open(os.path.join(PAGES, slug + '.json'), 'w', encoding='utf-8'), ensure_ascii=False)
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
    return words_text(html.unescape(md))


def words_text(t):
    """Page text -> lowercase words, without contact handles, addresses or apostrophes."""
    t = re.sub(r'https?://\S+', ' ', t or '')
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
        except ValueError:
            pass
        notes = r['submission_notes'] or ''
        if not slug and notes.lower().startswith('bulk uploaded from file:'):
            slug = file_slug(notes.split(':', 1)[1])
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


def compare(args):
    if not os.path.exists(DB):
        sys.exit('tmp/prod.sqlite is missing: python scripts/sync-prod-sqlite.py')
    pages = {}
    for f in glob.glob(os.path.join(PAGES, '*.json')):
        rec = json.load(open(f, encoding='utf-8'))
        pages[rec['slug']] = rec
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
        slug = slugs[r['id']]
        if not slug:
            counts['unpaired'] += 1
            continue
        page = pages.get(slug)
        if not page:
            counts['unread'] += 1
            continue
        rec = {'slug': slug, 'updated_at': r['updated_at'] or '', 'edited': not is_import(r)}
        if not page['exists']:
            rec.update({'differs': True, 'changed': None, 'samples': []})
            counts['missing'] += 1
        else:
            a, b = words_text(page['text']), words_md(our_markdown(r))
            ops = [o for o in difflib.SequenceMatcher(None, a, b, autojunk=False).get_opcodes() if o[0] != 'equal']
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
                print(f"  #{rid} {names[rid]} ({rec['slug']}): {what}" + (' [edited here]' if rec['edited'] else ''))
                for s in rec['samples']:
                    print(f"      reddit: {s['reddit']}\n      ours:   {s['ours']}")
    return 0


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest='cmd', required=True)
    f = sub.add_parser('fetch')
    f.add_argument('--slugs', nargs='*')
    f.add_argument('--refresh', action='store_true')
    f.add_argument('--delay', type=float, default=4)
    c = sub.add_parser('compare')
    c.add_argument('--list', action='store_true')
    args = ap.parse_args()
    return fetch(args) if args.cmd == 'fetch' else compare(args)


if __name__ == '__main__':
    sys.exit(main())
