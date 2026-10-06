"""
The outside sources the data audit read (docs/PLAN.md 3.13), offered at KOP
Tools > Drive Docs so the ones KOP does not have yet become news, court
records, bills or resource links on the facility page.

    python scripts/data-audit-links.py [--out tmp/data-audit/audit-links.json]

Reads every source of every proposal: js/data/data-audit/proposals.json and
the research batches in tmp/data-audit/proposals*.json (a source a reviewer
never saw is still a source the audit used). Each link is classified the way
scripts/gdocs-extract.py does and tied to the proposal's facility (its
facility_id while that record exists, else the program or place named in the
proposal; a memorial entry by its program). Links KOP already holds are left
out: news, lawsuits, legislation, Websites Sent In, facility records, the
memorial's own source, a published post that links it, a news headline
already on file, or a link the Google Docs, HEAL, wiki or SCIAD NET files
already offer on the same screen.

Copy the output to ~/kop-import/gdocs/audit-links.json on the server; the
Drive Docs screen reads it beside the others (inc/drive-docs.php, source
'audit'). Nothing reaches a record or a queue until accepted there.
"""

import argparse
import collections
import glob
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


gx = load('gdocs_extract', 'gdocs-extract.py')
sc = load('sciad_links', 'sciad-links.py')
ws = gx.ws

DOC = 'KOP data audit'
DATE = re.compile(r'^(\d{4})(?:-(\d{2}))?(?:-(\d{2}))?$')
# The researchers' own notes in a title ("(lead)", "(search summary)") are not the page's title.
# Outlets and case-law sites the shared classifier does not know (it calls them 'other').
NEWS = {'lpm.org', 'altoonamirror.com', 'parkrecord.com', 'tampabay28.com', 'ky3.com', 'alreporter.com', 'oxygen.com',
        'theelectricgf.com', 'radioiowa.com', 'live5news.com', 'wfmz.com', 'kgw.com', 'openminds.com'}
COURT = {'case-law.vlex.com', 'caselaw.findlaw.com', 'law.justia.com'}
TITLE_NOTE = re.compile(r'\s*\((?:lead|search summary|via [^)]*|page returned \d+[^)]*)\)\s*$', re.I)


def proposals():
    files = [os.path.join(ROOT, 'js', 'data', 'data-audit', 'proposals.json')]
    files += sorted(glob.glob(os.path.join(ROOT, 'tmp', 'data-audit', 'proposals*.json')))
    for f in files:
        if not os.path.exists(f):
            continue
        for p in json.load(open(f, encoding='utf-8')):
            if isinstance(p, dict):
                yield p


def load_queued():
    """Addresses the other Drive Docs files already offer (sciad-links.py's list plus SCIAD NET's own)."""
    out = sc.load_queued()
    p = os.path.join(ROOT, 'tmp', 'sciad', 'sciad-links.json')
    if os.path.exists(p):
        for it in json.load(open(p, encoding='utf-8')):
            for u in (it.get('url'), it.get('original')):
                n = gx.normalize_url(u) if u else None
                if n:
                    out.add(n)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--out', default=os.path.join(ROOT, 'tmp', 'data-audit', 'audit-links.json'))
    ap.add_argument('--db', default=os.path.join(ROOT, 'tmp', 'prod.sqlite'))
    args = ap.parse_args()

    con = sqlite3.connect(args.db)
    on_file = gx.load_on_file(con)
    for rid, name, url in con.execute('SELECT id, name, source_url FROM memorial_victims'):
        n = gx.normalize_url(url or '')
        if n:
            on_file[n].append(('memorial', rid, name or ''))
    news_domains = gx.load_news_domains(con)
    program_domains = gx.load_program_domains(con, news_domains)
    news_titles = sc.load_news_titles(con)
    post_urls = sc.load_post_urls(con)
    queued = load_queued()
    memorials = {rid: prog for rid, prog in con.execute('SELECT id, program FROM memorial_victims')}
    m = gx.Matcher(con)
    facs = {fid: {'id': fid, 'name': name, 'state': (st or '').upper()}
            for fid, name, st in con.execute('SELECT id, name, state FROM facilities_v2')}

    stats = collections.Counter()
    items = collections.OrderedDict()
    for p in proposals():
        fac, how = None, ''
        fid = p.get('facility_id')
        if fid and fid in facs:
            fac, how = facs[fid], 'audit record'
        if not fac:
            mids = [op.get('memorial_id') for op in p.get('ops') or [] if op.get('type') == 'memorial']
            names = [memorials.get(i) or '' for i in mids if i] + [p.get('place') or '', p.get('title') or '']
            for nm in names:
                f, _ = m.title(nm.split(',')[0])
                if not f:
                    found = m.in_text(nm)
                    f = found[0][0] if len({x[0]['id'] for x in found}) == 1 else None
                if f:
                    fac, how = f, 'program in audit entry'
                    break
        for s in p.get('sources') or []:
            url = (s.get('url') or '').strip().rstrip('.,;')
            if not re.match(r'^https?://', url, re.I):
                continue
            stats['sources'] += 1
            orig = gx.wayback_original(url)
            dom = gx.domain_of(orig or url)
            norm = gx.normalize_url(url)
            if not norm:
                continue
            cat = gx.classify(orig or url, dom, news_domains, program_domains)
            if cat == 'other' and gx.base_domain(dom) in NEWS:
                cat = 'news'
            elif cat in ('other', 'news') and dom.replace('www.', '') in COURT:
                cat = 'court'
            if cat == 'internal':
                stats['internal'] += 1
                continue
            title = TITLE_NOTE.sub('', (s.get('title') or '').strip())
            norms = {norm} | ({gx.normalize_url(orig)} if orig else set())
            hit = [h for n in norms for h in on_file.get(n, [])]
            held = ''
            if not hit:
                if norms & post_urls:
                    held = 'a published KOP post links it'
                elif norms & queued:
                    held = 'already offered from the Google Docs, HEAL, wiki or SCIAD NET'
                elif cat == 'news' and sc.news_on_file(title, news_titles):
                    held = 'headline already in news_submissions'
            if held:
                stats['held: ' + held] += 1
                continue
            it = items.get(norm)
            if not it:
                it = items[norm] = {
                    'key': norm, 'url': url, 'original': orig, 'domain': dom, 'category': cat,
                    'category_note': ('archive of ' + gx.CATEGORY_LABEL.get(cat, cat).lower()) if orig else '',
                    'label': gx.link_label(title, '', orig or url),
                    'facility': None, 'facility_how': '', 'also_named': [], 'operator': None, 'on_file': [], 'seen': [],
                    'source': 'audit',
                }
            quote = re.sub(r'\s+', ' ', s.get('quote') or '').strip()
            if len(it['seen']) < 12:
                place = {'doc': DOC, 'path': '', 'doc_id': 'audit:' + str(p.get('key') or ''), 'tab': '',
                         'heading': ('%s (%s)' % (p.get('title') or '', p.get('place') or '')).strip()[:200],
                         'anchor': title[:300], 'text': (quote or p.get('finding') or '')[:700]}
                d = DATE.match((s.get('date') or '').strip())
                if d and d.group(3):
                    place['published'] = d.group(0)
                it['seen'].append(place)
            if fac and not it['facility']:
                it['facility'] = {'id': fac['id'], 'name': fac['name'], 'state': fac['state']}
                it['facility_how'] = how
            elif fac and it['facility']['id'] != fac['id'] and all(a['id'] != fac['id'] for a in it['also_named']):
                it['also_named'].append({'id': fac['id'], 'name': fac['name'], 'state': fac['state']})
            for kind, rid, t in hit:
                if all(not (x['type'] == kind and x['id'] == rid) for x in it['on_file']):
                    it['on_file'].append({'type': kind, 'id': rid, 'title': t})

    out = sorted(items.values(), key=lambda i: (i['facility']['name'] if i['facility'] else '~', i['category'], i['url']))
    for it in out:
        stats['on_file' if it['on_file'] else ('facility' if it['facility'] else 'none')] += 1
        if not it['on_file']:
            stats['offer_' + it['category']] += 1
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    with open(args.out, 'w', encoding='utf-8') as fh:
        json.dump(out, fh, indent=0, ensure_ascii=False)
    print('%d sources on %d addresses; %d offered (not on file)' % (stats['sources'], len(out), len(out) - stats['on_file']))
    for k, v in sorted(stats.items()):
        print('  %-60s %d' % (k, v))
    print('wrote', args.out)


if __name__ == '__main__':
    main()
