"""
Which wiki entries to update first (docs/PLAN.md 3.12), in the owner's order:
  1. closures the entry does not have yet, latest closing year first;
  2. programs still open, with what KOP has that the entry lacks;
  3. everything else (programs closed before the entry was written).
Within 2 and 3 entries are ranked by their gaps (tmp/wiki-updates/gaps/<id>.json from scripts/wiki-gaps.php): a death 8,
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
        s = sum(score(g) for g in d['gaps'] if g['kind'] != 'closure')
        closed = [g['detail']['end_year'] for g in d['gaps'] if g['kind'] == 'closure' and not g.get('conflict')]
        status = ((d.get('record') or {}).get('status') or '').lower()
        if closed:
            tier, key = 1, -int(closed[0])
        elif status == 'open' and s > 0:
            tier, key = 2, -s
        elif s > 0:
            tier, key = 3, -s
        else:
            continue
        label = f'closed {closed[0]}' if closed else status or '?'
        rows.append((tier, key, round(s, 1), int(i), d['entry']['program_name'], kinds, label))
    rows.sort(key=lambda r: (r[0], r[1], -r[2], r[3]))
    with open(os.path.join(ROOT, 'tmp', 'wiki-updates', 'order.json'), 'w', encoding='utf-8') as f:
        json.dump([r[3] for r in rows], f)
    print(f'{len(rows)} entries with new information: ' + ', '.join(
        f'{n} {sum(1 for r in rows if r[0] == t)}' for t, n in ((1, 'closures'), (2, 'open'), (3, 'other'))))
    for t, _, s, i, name, kinds, label in rows[:a.top]:
        print(f'{t} {label[:12]:12} {s:6} {i:5} {name[:40]:40} ' + ', '.join(f'{k} {v}' for k, v in sorted(kinds.items(), key=lambda kv: -WEIGHT.get(kv[0], 1))))


if __name__ == '__main__':
    main()
