"""
The documents HEAL saved on heal-online.org (news articles, court filings,
inspection reports, legislation), each tied to the facility it is about and
offered at KOP Tools > Drive Docs beside the Google Docs links.

    python scripts/heal-docs.py [--out tmp/heal/heal-links.json]

Inputs (tmp/heal/, gitignored; scripts/heal-archive.py builds them):
  pdfs.json        every PDF: name, archive URL, capture dates
  docs/*.json      what a reader made of each PDF (DOCS-INSTRUCTIONS.md):
                   kind, title, outlet, date, programs, companies, summary

Programs are matched to facilities_v2 in tmp/prod.sqlite the way
scripts/woodbury-scan.py matches article headers. An article whose title is
already in news_submissions is marked on file and left out. Each link is the
Wayback copy of HEAL's PDF, so it keeps working although the site is gone.

Copy the output to ~/kop-import/gdocs/heal-links.json on the server; the
Drive Docs screen reads it beside links.json (inc/drive-docs.php). Nothing
reaches a record or a queue until accepted there. The output quotes HEAL's
documents, so it is never committed.
"""

import argparse
import collections
import importlib.util
import json
import os
import re
import sqlite3
import sys

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
HEAL = os.path.join(ROOT, 'tmp', 'heal')
spec = importlib.util.spec_from_file_location('woodbury_scan', os.path.join(ROOT, 'scripts', 'woodbury-scan.py'))
ws = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ws)

# Reader kinds -> the Drive Docs kinds (kop_gdl_kinds()).
CATEGORY = {
    'news': 'news', 'court': 'court', 'legislation': 'legislation', 'inspection': 'inspection',
    'government': 'government', 'program': 'reference', 'heal': 'social', 'other': 'other',
}


def title_key(s):
    s = re.sub(r'[^a-z0-9 ]', ' ', (s or '').lower())
    return ' '.join(w for w in s.split() if w not in ('the', 'a', 'an'))


def load_news(con):
    """Title keys of the articles on file, with their ids."""
    out = {}
    try:
        rows = con.execute("SELECT id, article_title, alternate_title FROM news_submissions WHERE status <> 'deleted'").fetchall()
    except sqlite3.Error:
        return out
    for nid, t1, t2 in rows:
        for t in (t1, t2):
            k = title_key(t)
            if len(k) >= 15:
                out.setdefault(k, (nid, t))
    return out


def on_file(title, news):
    k = title_key(title)
    if len(k) < 15:
        return None
    if k in news:
        return news[k]
    # A headline saved with a subtitle, or cut short.
    for nk, v in news.items():
        if len(nk) >= 25 and (nk in k or k in nk):
            return v
    return None


def label_of(d):
    title = re.sub(r'\s+', ' ', d.get('title') or '').strip() or d['name']
    bits = [b for b in (d.get('outlet'), d.get('date')) if b]
    return title + (' (' + ', '.join(bits) + ')' if bits else '')


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=os.path.join(HEAL, 'heal-links.json'))
    args = ap.parse_args()

    pdfs = {p['name']: p for p in json.load(open(os.path.join(HEAL, 'pdfs.json'), encoding='utf-8'))}
    docs = {}
    ddir = os.path.join(HEAL, 'docs')
    for fn in sorted(os.listdir(ddir)) if os.path.isdir(ddir) else []:
        if fn.endswith('.json'):
            try:
                for d in json.load(open(os.path.join(ddir, fn), encoding='utf-8')).get('docs') or []:
                    if isinstance(d, dict) and d.get('name') in pdfs:
                        docs[d['name']] = d
            except Exception as e:  # noqa: BLE001
                print('bad json', fn, e, file=sys.stderr)

    con = sqlite3.connect(ws.DB)
    names, past = ws.load_facilities(con)
    news = load_news(con)

    stats = collections.Counter()
    items = []
    for name, d in sorted(docs.items()):
        kind = (d.get('kind') or 'other').lower()
        if kind == 'not_tti':
            stats['not_tti'] += 1
            continue
        p = pdfs[name]
        cat = CATEGORY.get(kind, 'other')
        matched, how = [], ''
        for prog in d.get('programs') or []:
            if not isinstance(prog, dict) or not (prog.get('name') or '').strip():
                continue
            state = ws.place_state(prog.get('place') or '') if prog.get('place') else ''
            if ws.key(prog['name']) in ws.GENERIC_NAMES or len(ws.key(prog['name'])) < 5:
                continue
            f, _alts, mk, _note = ws.match_header(prog['name'].strip(), state, names, past)
            if f and all(m['id'] != f['id'] for m, _h in matched):
                matched.append((f, 'exact name' if mk in ('section', 'exact') else 'close name (%s)' % mk))
        companies = [c.strip() for c in d.get('companies') or [] if isinstance(c, str) and c.strip()]
        hit = on_file(d.get('title') or '', news) if cat == 'news' else None
        item = {
            'key': 'heal:' + name,
            'url': p['url'],
            'original': 'http://www.heal-online.org/' + name,
            'domain': 'heal-online.org (archived)',
            'category': cat,
            'label': label_of(d),
            'facility': None, 'facility_how': '', 'also_named': [],
            'operator': {'name': companies[0]} if companies and not matched else None,
            'on_file': [{'type': 'news', 'id': hit[0], 'title': hit[1]}] if hit else [],
            'seen': [{'doc': 'HEAL archive: heal-online.org/%s, saved %s' % (name, p.get('first_capture') or p['date']),
                      'text': re.sub(r'\s+', ' ', d.get('summary') or '').strip()[:700]}],
            'title': d.get('title') or '', 'outlet': d.get('outlet') or '', 'date': d.get('date') or '',
            'author': d.get('author') or '',
        }
        if matched:
            f, h = matched[0]
            item['facility'] = {'id': f['id'], 'name': f['name'], 'state': f['state']}
            item['facility_how'] = h
            item['also_named'] = [{'id': f2['id'], 'name': f2['name'], 'state': f2['state']} for f2, _h in matched[1:]]
        stats['on_file' if hit else ('facility' if matched else ('company' if item['operator'] else 'none'))] += 1
        stats['kind_' + cat] += 1
        items.append(item)

    missing = sorted(set(pdfs) - set(docs))
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    json.dump(items, open(args.out, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)
    print('%d documents read of %d PDFs (%d not read yet); %d offered' % (len(docs), len(pdfs), len(missing), len(items)))
    print(dict(stats))
    print('wrote', args.out)


if __name__ == '__main__':
    main()
