"""Wiki update drafts, step 3 input (docs/PLAN.md 3.12): per entry, its markdown and the gaps to draft.

Reads tmp/wiki-updates/gaps/<id>.json (scripts/wiki-gaps.php) and the entry's text from tmp/prod.sqlite,
writes tmp/wiki-updates/drafts/<id>/entry.md + gaps.json (gaps numbered g1, g2, ...; news_mention and
staff_other are left out, news newest first, at most 15 news and 12 staff). With no ids, picks a pilot:
entries covering every gap kind, most gaps first, one earlier-name entry and one conflict.

    python scripts/wiki-pilot-prep.py [--ids 721 657 ...] [--n 10]
"""
import argparse
import json
import os
import sqlite3

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
GAPS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'gaps')
OUT = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
DRAFT_KINDS = ('closure', 'name', 'operator', 'news', 'lawsuit', 'death', 'finding', 'incident', 'staff')


def load(i):
    with open(os.path.join(GAPS, f'{i}.json'), encoding='utf-8') as f:
        return json.load(f)


def trimmed(gaps):
    # A gap whose only source is a KOP page that gathers others' reporting is not drafted.
    keep = [g for g in gaps if g['kind'] in DRAFT_KINDS and not g.get('needs_source')]
    news = sorted([g for g in keep if g['kind'] == 'news'], key=lambda g: g['date'], reverse=True)[:15]
    staff = [g for g in keep if g['kind'] == 'staff'][:12]
    rest = [g for g in keep if g['kind'] not in ('news', 'staff')]
    out = rest + news + staff
    for n, g in enumerate(out, 1):
        g['gid'] = f'g{n}'
    return out


def pick(n):
    files = {}
    for name in os.listdir(GAPS):
        d = load(name[:-5])
        g = trimmed(d['gaps'])
        if g:
            files[d['entry']['id']] = (d, g)
    chosen = []
    # One entry about an earlier name, one with a conflict.
    for test in (lambda d, g: any(x['kind'] == 'name' and x['detail'].get('how') == 'later' for x in g),
                 lambda d, g: any(x['conflict'] for x in g)):
        for i, (d, g) in sorted(files.items(), key=lambda kv: -len(kv[1][1])):
            if i not in chosen and test(d, g):
                chosen.append(i)
                break
    # Then, kind by kind, the entry with the most gaps of that kind.
    for kind in ('closure', 'death', 'lawsuit', 'finding', 'name', 'operator', 'news', 'staff'):
        best = sorted((i for i in files if i not in chosen),
                      key=lambda i: (-sum(1 for x in files[i][1] if x['kind'] == kind), -len(files[i][1])))
        if best and any(x['kind'] == kind for x in files[best[0]][1]):
            chosen.append(best[0])
        if len(chosen) >= n:
            break
    return chosen[:n]


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--ids', nargs='*', type=int)
    ap.add_argument('--n', type=int, default=10)
    a = ap.parse_args()
    ids = a.ids or pick(a.n)
    db = sqlite3.connect(f"file:{os.path.join(ROOT, 'tmp', 'prod.sqlite')}?mode=ro", uri=True)
    db.row_factory = sqlite3.Row
    for i in ids:
        d = load(i)
        r = db.execute('SELECT original_markdown, generated_markdown FROM wiki_submissions WHERE id = ?', (i,)).fetchone()
        md = (r[d['entry']['markdown_field']] or '').replace('\r\n', '\n').strip()
        folder = os.path.join(OUT, str(i))
        os.makedirs(folder, exist_ok=True)
        with open(os.path.join(folder, 'entry.md'), 'w', encoding='utf-8', newline='\n') as f:
            f.write(md + '\n')
        g = trimmed(d['gaps'])
        with open(os.path.join(folder, 'gaps.json'), 'w', encoding='utf-8', newline='\n') as f:
            json.dump({'entry': d['entry'], 'record': d['record'], 'gaps': g}, f, indent=1, ensure_ascii=False)
        kinds = {}
        for x in g:
            kinds[x['kind']] = kinds.get(x['kind'], 0) + 1
        print(i, d['entry']['program_name'], '->', d['record']['name'], kinds)
    print(' '.join(map(str, ids)))


if __name__ == '__main__':
    main()
