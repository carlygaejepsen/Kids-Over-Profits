"""
The links on the r/troubledteens wiki's pages (news articles, court records,
bills, inspection reports, survivor posts, program sites), each tied to the
facility it is about and offered at KOP Tools > Drive Docs beside the Google
Docs links and HEAL's documents.

    python scripts/wiki-links.py [--out tmp/wiki/wiki-links.json]

Reads the same pages as scripts/wiki-source.py (markdown_output/ plus the wiki
editor's copies in tmp/prod.sqlite) and classifies every link the way
scripts/gdocs-extract.py does: its kind, the facility named in its paragraph
(else the page's own program), and whether it is already on file (news,
lawsuits, legislation, Websites Sent In, a facility record). Links to other
wiki pages and to maps are left out, and so are links the Google Docs pass
already offers (tmp/gdocs/links.json), so each link is one item.

Copy the output to ~/kop-import/gdocs/wiki-links.json on the server; the
Drive Docs screen reads it beside links.json (inc/drive-docs.php). Nothing
reaches a record or a queue until accepted there.
"""

import argparse
import collections
import importlib.util
import json
import os
import re
import sqlite3


ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))


def load(name, file):
    spec = importlib.util.spec_from_file_location(name, os.path.join(ROOT, 'scripts', file))
    mod = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


wsrc = load('wiki_source', 'wiki-source.py')
gx = load('gdocs_extract', 'gdocs-extract.py')
ws = gx.ws

LINK = re.compile(r'\[([^\]]*)\]\(\s*(https?://(?:[^()\s]|\([^()\s]*\))+)\s*\)', re.I)
HEADING = re.compile(r'^\s{0,3}#{1,6}\s*(.+?)\s*$')
SKIP = re.compile(r'reddit\.com/r/troubledteens/wiki|google\.[a-z.]+/maps|maps\.google\.|goo\.gl/maps|maps\.app\.goo\.gl|'
                  r'reddit\.com/r/troubledteens/?$|reddit\.com/message/', re.I)
# "Ashcreek Ranch Academy (2012-present) Toquerville, UT": the header's state.
HEADER_PLACE = re.compile(r'\)\s*[^,\n]*,\s*([A-Z]{2})\b')


def blocks(md):
    """(heading, paragraph markdown) for every paragraph, the nearest heading kept."""
    heading, cur = '', []
    for line in md.replace('\r\n', '\n').split('\n') + ['']:
        h = HEADING.match(line)
        if h or not line.strip() or re.match(r'^\s*-{3,}\s*$', line):
            if cur:
                yield heading, '\n'.join(cur)
                cur = []
            if h:
                heading = wsrc.plain(h.group(1)).strip(' *')
            continue
        cur.append(line)


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=os.path.join(ROOT, 'tmp', 'wiki', 'wiki-links.json'))
    ap.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    ap.add_argument('--gdocs', default=os.path.join(ROOT, 'tmp', 'gdocs', 'links.json'))
    args = ap.parse_args()

    con = sqlite3.connect(args.db)
    on_file = gx.load_on_file(con)
    news_domains = gx.load_news_domains(con)
    program_domains = gx.load_program_domains(con, news_domains)
    m = gx.Matcher(con)
    offered = set()
    if os.path.exists(args.gdocs):
        for it in json.load(open(args.gdocs, encoding='utf-8')):
            for u in (it.get('url'), it.get('original')):
                n = gx.normalize_url(u or '')
                if n:
                    offered.add(n)

    stats = collections.Counter()
    items = {}
    for p in wsrc.wiki_pages():
        md = p['md']
        state = ''
        hp = HEADER_PLACE.search(wsrc.plain(md[:600]).split('\n')[0])
        if hp and hp.group(1) in ws.STATES:
            state = hp.group(1)
        page_fac, page_how = None, ''
        f, kind = m.title(p['title'], state)
        if f:
            page_fac, page_how = f, 'wiki page' + (' (close name)' if kind == 'close name' else '')
        page_op = None if page_fac else m.operator(p['title'])
        # A list page (a state's programs, a company's programs) hands no program to its links.
        named_on_page = {x[0]['id'] for x in m.in_text(wsrc.plain(md))}
        if page_fac and len(named_on_page) > 12 and p['key'].startswith('active-programs'):
            page_fac, page_how = None, ''
        doc = 'r/troubledteens wiki: page "%s" (as of %s)' % (p['title'][:120], p['date'])

        for heading, block in blocks(md):
            text = wsrc.plain(block)
            for anchor_md, url in LINK.findall(block):
                url = url.strip().rstrip('.,;')
                stats['links'] += 1
                if SKIP.search(url):
                    stats['wiki_or_map'] += 1
                    continue
                orig = gx.wayback_original(url)
                dom = gx.domain_of(orig or url)
                norm = gx.normalize_url(url)
                if not norm:
                    continue
                if norm in offered or (orig and gx.normalize_url(orig) in offered):
                    stats['in_gdocs'] += 1
                    continue
                cat = gx.classify(orig or url, dom, news_domains, program_domains)
                if cat == 'internal':
                    stats['internal'] += 1
                    continue
                anchor = wsrc.plain(anchor_md)
                context = text if len(text) <= 1500 else (anchor or text[:1500])
                named = m.in_text(anchor + ' ' + context)
                ids = list(dict.fromkeys(x[0]['id'] for x in named))
                fac, how = None, ''
                if len(ids) == 1:
                    fac, how = named[0][0], 'past name in text' if named[0][2] else 'name in text'
                elif page_fac and (not ids or page_fac['id'] in ids):
                    fac, how = page_fac, page_how
                if not fac and cat == 'program_site':
                    cands = program_domains.get(gx.base_domain(dom)) or set()
                    if len(cands) == 1:
                        fac, how = m.by_id.get(next(iter(cands))), 'website on record'
                others = [x[0] for x in named if not fac or x[0]['id'] != fac['id']]
                hit = on_file.get(norm, []) + (on_file.get(gx.normalize_url(orig), []) if orig else [])
                it = items.get(norm)
                if not it:
                    it = items[norm] = {
                        'key': norm, 'url': url, 'original': orig, 'domain': dom, 'category': cat,
                        'category_note': ('archive of ' + gx.CATEGORY_LABEL.get(cat, cat).lower()) if orig else '',
                        'label': gx.link_label(anchor, '', orig or url),
                        'facility': None, 'facility_how': '', 'also_named': [], 'operator': None, 'on_file': [], 'seen': [],
                    }
                if len(it['seen']) < 12:
                    it['seen'].append({'doc': doc, 'path': p['url'], 'doc_id': 'wiki:' + p['key'], 'tab': '',
                                       'heading': heading[:200], 'anchor': anchor[:300], 'text': context[:700]})
                if fac and not it['facility']:
                    it['facility'] = {'id': fac['id'], 'name': fac['name'], 'state': fac['state']}
                    it['facility_how'] = how
                for o in others:
                    if all(a['id'] != o['id'] for a in it['also_named']) and (not it['facility'] or it['facility']['id'] != o['id']):
                        it['also_named'].append({'id': o['id'], 'name': o['name'], 'state': o['state']})
                if page_op and not it['operator'] and not it['facility']:
                    it['operator'] = page_op
                for kind, rid, title in hit:
                    if all(not (x['type'] == kind and x['id'] == rid) for x in it['on_file']):
                        it['on_file'].append({'type': kind, 'id': rid, 'title': title})

    out = sorted(items.values(), key=lambda i: (i['facility']['name'] if i['facility'] else '~', i['category'], i['url']))
    for it in out:
        stats['on_file' if it['on_file'] else ('facility' if it['facility'] else ('company' if it['operator'] else 'none'))] += 1
        if not it['on_file']:
            stats['offer_' + it['category']] += 1
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, 'w', encoding='utf-8') as fh:
        json.dump(out, fh, indent=0, ensure_ascii=False)
    print('%d links on %d unique addresses; %d offered (not on file)' % (stats['links'], len(out), len(out) - stats['on_file']))
    print(dict(stats))
    print('wrote', args.out)


if __name__ == '__main__':
    main()
