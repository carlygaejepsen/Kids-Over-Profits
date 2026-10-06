"""
Which wiki entries to update first (docs/PLAN.md 3.12): the ones KOP has the most new information for.
Reads tmp/wiki-updates/gaps/<id>.json (scripts/wiki-gaps.php) and scores each entry's gaps: a closure 10, a death 8,
a lawsuit 6, a serious finding 5, news newer than the entry 4 (older news 1), a name or operator 3, staff 0.5
(other-program staff 0.25). Entries already drafted (js/data/reddit-wiki/update-drafts.json) are left out.
-> tmp/wiki-updates/order.json (ids, best first) and a table on screen.

    python scripts/wiki-update-order.py [--top 25]
"""
import argparse
import glob
import json
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
WEIGHT = {'closure': 10, 'death': 8, 'lawsuit': 6, 'finding': 5, 'incident': 4, 'name': 3, 'operator': 3,
          'staff': 0.5, 'staff_other': 0.25}


def score(g):
    if g.get('conflict'):
        return 0
    if g['kind'] == 'news':
        return 4 if (g.get('detail') or {}).get('newer_than_entry') else 1
    return WEIGHT.get(g['kind'], 0)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--top', type=int, default=25)
    a = ap.parse_args()
    done = set()
    path = os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'update-drafts.json')
    if os.path.exists(path):
        done = set(json.load(open(path, encoding='utf-8')).get('entries', {}))
    rows = []
    for f in glob.glob(os.path.join(ROOT, 'tmp', 'wiki-updates', 'gaps', '*.json')):
        d = json.load(open(f, encoding='utf-8'))
        i = str(d['entry']['id'])
        if i in done or not d.get('gaps'):
            continue
        kinds = {}
        for g in d['gaps']:
            if not g.get('conflict'):
                kinds[g['kind']] = kinds.get(g['kind'], 0) + 1
        s = sum(score(g) for g in d['gaps'])
        if s > 0:
            rows.append((round(s, 1), int(i), d['entry']['program_name'], kinds))
    rows.sort(key=lambda r: (-r[0], r[1]))
    with open(os.path.join(ROOT, 'tmp', 'wiki-updates', 'order.json'), 'w', encoding='utf-8') as f:
        json.dump([r[1] for r in rows], f)
    print(f'{len(rows)} entries with new information')
    for s, i, name, kinds in rows[:a.top]:
        print(f'{s:6} {i:5} {name[:40]:40} ' + ', '.join(f'{k} {v}' for k, v in sorted(kinds.items(), key=lambda kv: -WEIGHT.get(kv[0], 1))))


if __name__ == '__main__':
    main()
