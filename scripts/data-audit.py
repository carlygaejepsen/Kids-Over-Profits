"""Flag facility data that outside sources should check (docs/PLAN.md 3.13).

Offline and read-only: reads tmp/prod.sqlite and js/data/network/graph.json,
writes tmp/data-audit/flags.json and tmp/data-audit/report.md. No model, no
network. Each flag carries what KOP holds and why it looks wrong; research
agents then check it against news, filings and licensing records.

Kinds (patterns found by the 2026-10-06 spot check of the wiki pilot):
  rename_end_year   a renamed program's node ends the year its earlier name
                    ended (Old West Academy took Majestic Ranch's 2007)
  end_before_items  an end year with dated items about the program years
                    later (Oakley School "closed 2007", NATSAP to 2016-17)
  status_years      status and years disagree (Open with an end year,
                    Closed with none, Suspended or Transferred unexplained)
  closure_ordered   a closure confirmed from an order or announcement, not
                    a closing (an appeal or reversal would leave it wrong)
  operator_profile  an operator line taken from the company's own profile
                    that the program's record never names (Acadia on
                    Turn-About Ranch)
  operator_inherited the current operator bought a past one; the program
                    may have been sold off before (Turn-About Ranch left
                    Aspen in 2014, before Acadia bought CRC in 2015)
  person_spelling   two people at one program whose names differ by a
                    letter or two (Kelly Corey / Kelly Cole)
  person_common     one person id on many programs under a common name
                    (Jeff Johnson)
  memorial          every published memorial entry: date and cause
  rename_years      a renamed program kept as two records whose years
                    disagree because of the rename (the later name holds the
                    earlier one's opening year, the earlier one never ends);
                    the inbox card merges them as a rename right there; when one
                    of the two is only the name of the company that runs the
                    other (a bare "Embark Behavioral Health" record in Marion,
                    MT beside Embark at Flathead Valley) the flag says so
                    ('company_name': the side), and the card offers to fold it
                    in as one program (inc/data-audit.php, kop_daudit_rename_brands())

A flag on a record that is one name of a renamed pair carries 'renames':
[{earlier, later, year, why}] (record ids, the rename year as guessed from
the years). Research corrects the year and copies it into the proposal; the
review inbox says the rename is why the years disagree and offers "Merge as
a rename" while both names are records of their own.

    python scripts/data-audit.py [--kind rename_end_year ...] [--list] [--propose-renames]

--propose-renames adds every rename_years flag to js/data/data-audit/proposals.json
as a card of its own (no changes, only the rename: the records are the
evidence), keyed rename_years-<earlier>-<later>; cards already there are kept.
"""
import argparse
import json
import os
import re
import sqlite3
import sys
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
GRAPH = os.path.join(ROOT, 'js', 'data', 'network', 'graph.json')
OUT = os.path.join(ROOT, 'tmp', 'data-audit')

YEAR = re.compile(r'\b(19[5-9]\d|20[0-4]\d)\b')
COMPANY_WORDS = re.compile(r'\b(inc|llc|l\.l\.c|corp|corporation|co|company|group|holdings|healthcare|health|services|'
                           r'systems|ltd|lp|the|of|and|management|education)\b\.?', re.I)


def as_list(v):
    if v is None:
        return []
    if isinstance(v, list):
        out = []
        for x in v:
            if isinstance(x, dict):
                x = x.get('name') or x.get('value') or ''
            out.extend(as_list(x))
        return out
    if isinstance(v, dict):
        return as_list(v.get('name') or v.get('value'))
    s = str(v).strip()
    if not s:
        return []
    return [p.strip() for p in re.split(r'[;\n]|,(?![^()]*\))', s) if p.strip()]


def to_year(v):
    try:
        y = int(str(v).strip()[:4])
        return y if 1900 < y < 2100 else None
    except (TypeError, ValueError):
        return None


def company_key(s):
    s = COMPANY_WORDS.sub(' ', (s or '').lower())
    return re.sub(r'[^a-z0-9]+', ' ', s).strip()


def name_key(s):
    return re.sub(r'[^a-z0-9]+', ' ', (s or '').lower()).strip()


GENERIC_WORDS = {'inc', 'incorporated', 'llc', 'l', 'c', 'corp', 'corporation', 'co', 'company', 'ltd', 'lp', 'llp', 'pllc', 'pc',
                 'the', 'rtc', 'residential', 'treatment', 'center', 'centre', 'campus', 'program', 'programs', 'holdings', 'group'}


def core_key(s):
    """kop_daudit_core_key(): a name key without the words that say nothing about which company or program it is."""
    return ' '.join(w for w in name_key((s or '').replace('&', ' and ')).split() if w not in GENERIC_WORDS)


def company_line_name(s):
    return re.sub(r'\s*[\(\[].*$', '', str(s or '')).strip()


def operator_keys(rec, links):
    """Core keys of the companies that run or ran a record: its operator lines and company links."""
    ident = rec['ident']
    lines = [ident.get('currentOperator') or ''] + as_list(ident.get('pastOperators')) + as_list(ident.get('otherOperators'))
    keys = {core_key(company_line_name(x)) for x in lines if isinstance(x, str)}
    keys |= {core_key(n) for n in links.get(rec['id'], [])}
    return {k for k in keys if k}


def company_reach(recs, links):
    """kop_daudit_company_reach(): company core key => the records it runs or ran."""
    out = defaultdict(set)
    for r in recs.values():
        for k in operator_keys(r, links):
            out[k].add(r['id'])
    return out


def company_named(me, other, links, reach):
    """kop_daudit_rename_brands()' company side: me is only the name of a company that runs the other record and
    other programs too (Embark, Three Springs; not Pathway of Madison County, LLC, named after its one program), and
    has no years of its own outside the other's (Three Points Center in Siler City ran on after Auldern closed)."""
    k = core_key(me['name'])
    if not k or k == core_key(other['name']) or k not in operator_keys(other, links):
        return False
    if len(reach.get(k, set()) - {me['id'], other['id']}) < 2:
        return False
    lo, hi = other['start'], other['end']
    outside = lambda y: bool(y) and ((lo and y < lo) or (hi and y > hi))
    return not (outside(me['start']) or outside(me['end']))


def lev(a, b):
    if a == b:
        return 0
    prev = list(range(len(b) + 1))
    for i, ca in enumerate(a, 1):
        cur = [i]
        for j, cb in enumerate(b, 1):
            cur.append(min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + (ca != cb)))
        prev = cur
    return prev[-1]


def load_records(con):
    recs = {}
    for rid, uname, js, name, state, city, status in con.execute(
            'SELECT id, unique_name, json_data, name, state, city, status FROM facilities_v2'):
        try:
            doc = json.loads(js or '{}')
        except ValueError:
            doc = {}
        ident = doc.get('identification') or {}
        op = doc.get('operatingPeriod') or {}
        recs[rid] = {
            'id': rid, 'name': name or ident.get('name') or uname, 'state': state, 'city': city,
            'status': (op.get('status') or status or '').strip(),
            'start': to_year(op.get('startYear')), 'end': to_year(op.get('endYear')),
            'notes': ' '.join(as_list(op.get('notes'))) if isinstance(op.get('notes'), list) else str(op.get('notes') or ''), 'doc': doc, 'ident': ident,
        }
    return recs


def flag(kind, rec, why, kop, **extra):
    f = {'kind': kind, 'why': why, 'kop': kop}
    if rec:
        f.update({'facility_id': rec['id'], 'name': rec['name'],
                  'place': ', '.join(x for x in (rec['city'], rec['state']) if x)})
    f.update(extra)
    return f


def check_rename_end_year(recs, graph):
    out = []
    by_fid = defaultdict(list)
    for n in graph['nodes']:
        if n.get('facilityId'):
            by_fid[n['facilityId']].append(n)
    for fid, nodes in by_fid.items():
        if len(nodes) < 2:
            continue
        ends = defaultdict(list)
        for n in nodes:
            m = re.search(r'(\d{4})\s*$', str(n.get('years') or ''))
            if m:
                ends[m.group(1)].append(n)
        for year, same in ends.items():
            # tracks and campuses of one program share a record too; a rename line is not required (the board
            # misses many), so research sorts those out
            if len(same) < 2:
                continue
            labels = [f"{n.get('name') or n['id']} ({n.get('years')}, {n.get('status')})" for n in same]
            out.append(flag('rename_end_year', recs.get(fid),
                            f'{len(same)} names on one record all end in {year}; the later name probably ran past it',
                            {'map_nodes': labels, 'record_years': [recs.get(fid, {}).get('start'), recs.get(fid, {}).get('end')]},
                            nodes=[n['id'] for n in same]))
    return out


def find_renames(recs, graph):
    """(earlier id, later id) => why: a record listing another's name as a past name (or naming it as its current
    name), and the map's rename lines between two records' names. Only pairs that are still two records."""
    by_name = defaultdict(list)
    for r in recs.values():
        by_name[name_key(r['name'])].append(r)
    out = {}

    def add(e, l, why):
        if e['id'] != l['id'] and (l['id'], e['id']) not in out and (not e['state'] or not l['state'] or e['state'] == l['state']):
            out.setdefault((e['id'], l['id']), why)
    for l in recs.values():
        for past in as_list(l['ident'].get('pastNames')):
            for e in by_name.get(name_key(past), []):
                add(e, l, f"{l['name']} lists {e['name']} as a past name")
    for e in recs.values():
        for cur in as_list(e['ident'].get('currentName')):
            for l in by_name.get(name_key(cur), []):
                add(e, l, f"{e['name']} says it is now {l['name']}")
    fid = {n['id']: n.get('facilityId') for n in graph['nodes']}
    for ed in graph.get('edges', []):
        if 'rebrand' not in (ed.get('roles') or []):
            continue
        a, b = recs.get(fid.get(ed['source'])), recs.get(fid.get(ed['target']))
        if a and b:
            add(a, b, f"the network map draws {a['name']} renamed {b['name']}")
    return out


def rename_year(e, l):
    """The rename year the records suggest: the earlier name's end, else the later one's start when it is later."""
    if e['end']:
        return e['end']
    if l['start'] and (not e['start'] or l['start'] > e['start']):
        return l['start']
    return None


def check_rename_years(recs, renames, links=None):
    links = links or {}
    reach = company_reach(recs, links)
    out = []
    for (eid, lid), why in sorted(renames.items()):
        e, l = recs[eid], recs[lid]
        side = 'later' if company_named(l, e, links, reach) else ('earlier' if company_named(e, l, links, reach) else '')
        if side:
            me, other = (l, e) if side == 'later' else (e, l)
            out.append(flag('rename_years', l, f"{me['name']} is only the name of the company that runs {other['name']}: one program filed twice, not a rename",
                            {'earlier': [e['name'], eid, e['status'], e['start'], e['end']],
                             'later': [l['name'], lid, l['status'], l['start'], l['end']]},
                            company_name=side, renames=[{'earlier': eid, 'later': lid, 'year': rename_year(e, l), 'why': why}]))
            continue
        off = []
        if not l['start'] or (e['start'] and l['start'] <= e['start']):
            off.append(f"{l['name']} opens {l['start'] or '(no year)'}, the earlier name's opening year or none")
        if not e['end']:
            off.append(f"{e['name']} has no end year")
        elif l['start'] and l['start'] < e['end']:
            off.append(f"{l['name']} starts {l['start']}, before {e['name']} ends {e['end']}")
        if e['status'] in ('Open', 'Suspended') and l['status'] != 'Closed':
            off.append(f"{e['name']} is still {e['status']}")
        if not off:
            continue
        out.append(flag('rename_years', l, f"renamed program kept as two records, years disagree: {'; '.join(off)}",
                        {'earlier': [e['name'], eid, e['status'], e['start'], e['end']],
                         'later': [l['name'], lid, l['status'], l['start'], l['end']]},
                        renames=[{'earlier': eid, 'later': lid, 'year': rename_year(e, l), 'why': why}]))
    return out


SKIP_KEYS = {'provenance', 'sourceUrl', 'url', 'source', 'sources', 'citations', 'documents', 'news', 'label',
             'migratedAt', 'resourceLinks', 'profileLinks', 'archived', 'accessed', 'updatedAt', 'createdAt'}
NOT_OPERATING = re.compile(r'\((?:as of|archived|accessed|added|retrieved)[^)]*\)|(?:as of|archived|added|accessed|retrieved|'
                           r'last checked|migrated)[^,;)"]{0,40}|(?:lawsuit|sued|suit|settle\w*|verdict|convicted|sentenced|'
                           r'indicted|died|death|obituary|documentary|article|book|podcast|later|formerly|now|became|renamed|'
                           r'property|building|campus (?:sold|bought)|sold|bought|demolish\w*)[^.;"]{0,80}', re.I)
WOODBURY = re.compile(r'woodbury-(\d\d)(\d\d)\.pdf')


def doc_strings(v, key=''):
    if key in SKIP_KEYS:
        return
    if isinstance(v, dict):
        for k, x in v.items():
            yield from doc_strings(x, k)
    elif isinstance(v, list):
        for x in v:
            yield from doc_strings(x, key)
    elif isinstance(v, str):
        yield v


def check_end_before_items(con, recs):
    """An end year with evidence of the program operating later: a Woodbury issue naming its staff, or years in
    the record's own text that are not about lawsuits, deaths, renames or sales (those come after a closing)."""
    out = []
    for r in recs.values():
        end = r['end']
        if not end or r['status'] not in ('Closed', 'Rebranded', ''):
            continue
        woodbury = set()
        for m in WOODBURY.finditer(json.dumps(r['doc'])):
            y = 2000 + int(m.group(2)) if int(m.group(2)) < 50 else 1900 + int(m.group(2))
            if y > end + 1:
                woodbury.add('%d-%s' % (y, m.group(1)))
        snips = []
        for t in doc_strings(r['doc']):
            t2 = NOT_OPERATING.sub(' ', t)
            for y in YEAR.findall(t2):
                if int(y) > end + 1:
                    m = re.search(r'.{0,90}%s.{0,50}' % y, t)
                    snips.append((int(y), m.group(0) if m else t[:140]))
        # a name the record does not hold under its later name: a year in the text is weak, Woodbury is strong
        score = 3 * len(woodbury) + len({y for y, _ in snips})
        if not woodbury and len({y for y, _ in snips}) < 3:
            continue
        snips.sort(key=lambda x: -x[0])
        out.append(flag('end_before_items', r, f'record ends {end} but names the program operating later',
                        {'status': r['status'], 'years': [r['start'], end], 'notes': r['notes'][:300]},
                        woodbury_issues=sorted(woodbury)[-6:], later_text=[x[1] for x in snips[:4]], score=score))
    out.sort(key=lambda f: -f['score'])
    return out


def check_status_years(recs):
    out = []
    for r in recs.values():
        s, end = r['status'], r['end']
        why = None
        if s == 'Open' and end:
            why = f'status Open but end year {end}'
        elif s in ('Suspended', 'Transferred') and not r['notes'].strip():
            why = f'status {s} with no note saying why'
        elif s == 'Suspended':
            why = 'status Suspended: check whether it has since closed or reopened'
        if why:
            out.append(flag('status_years', r, why, {'status': s, 'years': [r['start'], end], 'notes': r['notes'][:400]}))
    return out


def check_closure_ordered(con, recs):
    out = []
    for rid, fid, stage, cdate, quote, status in con.execute(
            "SELECT id, facility_id, stage, closure_date, quote, status FROM facility_closure_reports "
            "WHERE status IN ('applied','confirmed','approved') AND stage IN ('ordered_closed','closing','announced')"):
        r = recs.get(fid)
        out.append(flag('closure_ordered', r, f'closure confirmed at stage "{stage}", not a confirmed closing',
                        {'status': r['status'] if r else None, 'years': [r['start'], r['end']] if r else None},
                        report_id=rid, closure_date=cdate, quote=(quote or '')[:300]))
    return out


def check_operator_profile(recs, graph):
    out = []
    labels = {n['id']: (n.get('name') or n.get('label') or n['id']) for n in graph['nodes']}
    fids = {n['id']: n.get('facilityId') for n in graph['nodes']}
    for e in graph.get('edges', []):
        if e.get('provenance') != 'profile' or 'operator' not in (e.get('roles') or []):
            continue
        r = recs.get(fids.get(e['target']))
        if not r:
            continue
        op_name = labels.get(e['source'], e['source'])
        k = company_key(op_name)
        named = []
        for f in ('currentOperator', 'pastOperators', 'otherOperators', 'currentOwners', 'pastOwners', 'parentCompany'):
            named += as_list(r['ident'].get(f))
        named += as_list((r['doc'].get('ownership') or {}).get('history')) if isinstance(r['doc'].get('ownership'), dict) else []
        blob = company_key(' '.join(named) + ' ' + r['notes'])
        if k and k in blob:
            continue
        out.append(flag('operator_profile', r, f'map says {op_name} operated it; the record never names {op_name}',
                        {'record_operators': named[:8], 'status': r['status'], 'years': [r['start'], r['end']]},
                        operator=op_name, edge=e['id']))
    return out


def check_operator_inherited(recs, graph):
    """Current operator is a company that bought a past operator: the program may have been sold off first."""
    out = []
    names = {n['id']: (n.get('name') or n['id']) for n in graph['nodes'] if n.get('kind') == 'parent'}
    linked = set()
    for e in graph.get('edges', []):
        if e.get('source') in names and e.get('target') in names:
            a, b = company_key(names[e['source']]), company_key(names[e['target']])
            linked.add((a, b))
            linked.add((b, a))
    for r in recs.values():
        cur = company_key(r['ident'].get('currentOperator') or '')
        if not cur:
            continue
        for past in as_list(r['ident'].get('pastOperators')):
            pk = company_key(past)
            if pk and pk != cur and (cur, pk) in linked:
                out.append(flag('operator_inherited', r,
                                f"current operator {r['ident'].get('currentOperator')} bought {past}; did this program go with it?",
                                {'current': r['ident'].get('currentOperator'), 'past': as_list(r['ident'].get('pastOperators')),
                                 'status': r['status'], 'years': [r['start'], r['end']]}))
                break
    return out


def check_people(con, recs):
    out = []
    # Industry people only: victims, plaintiffs and journalists keep ids of their own (inc/people.php pools).
    pooled = any(r[1] == 'pool' for r in con.execute("PRAGMA table_info('wpdl_kop_people')"))
    people = {pid: (name, nk) for pid, name, nk, merged in
              con.execute('SELECT id, name, name_key, merged_into FROM wpdl_kop_people'
                          + (" WHERE pool = 'industry'" if pooled else '')) if not merged}
    at = defaultdict(set)
    roles = defaultdict(list)
    for kind, rec_id, pid, role in con.execute(
            "SELECT record_kind, record_id, person_id, role FROM wpdl_kop_person_roles WHERE person_id IS NOT NULL"):
        if pid in people:
            at[(kind, rec_id)].add(pid)
            roles[pid].append((kind, rec_id, role))
    seen = set()
    for (kind, rec_id), pids in at.items():
        pids = sorted(pids)
        for i, a in enumerate(pids):
            for b in pids[i + 1:]:
                na, nb = name_key(people[a][0]).split(), name_key(people[b][0]).split()
                if len(na) < 2 or len(nb) < 2 or (a, b) in seen:
                    continue
                if na[0] == nb[0] and na[-1] != nb[-1] and 0 < lev(na[-1], nb[-1]) <= 2 and min(len(na[-1]), len(nb[-1])) >= 4:
                    seen.add((a, b))
                    r = recs.get(rec_id) if kind in ('facility', 'facilities_v2') else None
                    out.append(flag('person_spelling', r, f'{people[a][0]} and {people[b][0]} at one program: one person misspelled?',
                                    {'a': [people[a][0], a, [x[2] for x in roles[a] if x[1] == rec_id]],
                                     'b': [people[b][0], b, [x[2] for x in roles[b] if x[1] == rec_id]]},
                                    person_ids=[a, b], record=[kind, rec_id]))
    for pid, rl in roles.items():
        progs = {(k, i) for k, i, _ in rl}
        if len(progs) >= 5:
            names = [recs[i]['name'] for k, i in progs if i in recs][:20]
            out.append(flag('person_common', None, f'{people[pid][0]} is named at {len(progs)} records under one id',
                            {'records': names, 'roles': sorted({x[2] for x in rl if x[2]})[:12]},
                            person_id=pid, person=people[pid][0], count=len(progs)))
    return out


def check_memorial(con):
    out = []
    for row in con.execute("SELECT id, name, age, program, date_of_death, date_precision, cause_of_death, cause_category, "
                           "location, source_name, source_url FROM memorial_victims WHERE publication_status = 'published'"):
        mid, name, age, prog, d, prec, cause, cat, loc, sname, surl = row
        out.append(flag('memorial', None, 'check date, age, program and cause against the sources',
                        {'name': name, 'age': age, 'program': prog, 'date_of_death': d, 'precision': prec,
                         'cause': cause, 'category': cat, 'location': loc, 'source': [sname, surl]}, memorial_id=mid))
    return out


def operator_links(con):
    """Record id => names of the companies linked to it (wpdl_kop_operator_facilities), {} without the tables."""
    out = defaultdict(list)
    try:
        for fid, name in con.execute('SELECT f.facility_id, o.name FROM wpdl_kop_operator_facilities f '
                                     'JOIN wpdl_kop_operators o ON o.id = f.operator_id'):
            out[fid].append(name or '')
    except sqlite3.Error:
        pass
    return out


def years_text(r):
    if r['start'] and r['end']:
        return '%d to %d' % (r['start'], r['end'])
    if r['start']:
        return 'from %d' % r['start']
    if r['end']:
        return 'until %d' % r['end']
    return 'no years'


def propose_renames(recs, flags):
    """rename_years flags -> cards in proposals.json: no ops, the rename alone, merged from the card."""
    path = os.path.join(ROOT, 'js', 'data', 'data-audit', 'proposals.json')
    props = json.load(open(path, encoding='utf-8'))
    have = {p['key'] for p in props}
    paired = {(r['earlier'], r['later']) for p in props for r in p.get('renames', [])}
    added = 0
    for f in flags:
        r = f['renames'][0]
        key = 'rename_years-%d-%d' % (r['earlier'], r['later'])
        if key in have or (r['earlier'], r['later']) in paired:
            continue
        e, l = recs[r['earlier']], recs[r['later']]
        y = r['year']
        if f.get('company_name'):
            me, other = (l, e) if f['company_name'] == 'later' else (e, l)
            finding = ('%s is only the name of the company that runs %s (%s): record #%d is most likely %s filed under its company, '
                       'not an earlier or later name of it. Fold it in as one program, unless the program really went by that name.'
                       % (me['name'], other['name'], r['why'], me['id'], other['name']))
        else:
            finding = ('%s and %s are one program under two names (%s), kept as two records, so their years disagree: %s.'
                       % (e['name'], l['name'], r['why'], f['why'].split(': ', 1)[-1])
                       + (' The records suggest the name changed in %d; check it before merging.' % y if y
                          else ' The records do not say when the name changed: give the year when merging.'))
        props.append({
            'key': key, 'kind': 'rename_years', 'title': l['name'], 'place': f.get('place', ''), 'facility_id': l['id'],
            'verdict': 'unsure', 'confidence': 'medium' if y else 'low',
            'finding': finding,
            'kop_now': '%s (#%d): %s, %s. %s (#%d): %s, %s.' % (e['name'], e['id'], e['status'] or 'no status', years_text(e),
                                                              l['name'], l['id'], l['status'] or 'no status', years_text(l)),
            'sources': [], 'ops': [],
            'renames': [{'earlier': r['earlier'], 'later': r['later'], 'year': y, 'why': r['why']} if y else
                        {'earlier': r['earlier'], 'later': r['later'], 'why': r['why']}],
        })
        added += 1
    with open(path, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write(json.dumps(props, indent=1, ensure_ascii=False) + '\n')
    print('proposals.json: %d rename cards added (%d proposals)' % (added, len(props)))


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--kind', nargs='*')
    ap.add_argument('--list', action='store_true')
    ap.add_argument('--propose-renames', action='store_true')
    args = ap.parse_args()
    if not os.path.exists(DB) or os.path.getsize(DB) == 0:
        sys.exit('tmp/prod.sqlite is missing or empty: run python scripts/sync-prod-sqlite.py')
    con = sqlite3.connect('file:%s?mode=ro' % DB.replace('\\', '/'), uri=True)
    graph = json.load(open(GRAPH, encoding='utf-8'))
    recs = load_records(con)
    renames = find_renames(recs, graph)
    checks = {
        'rename_end_year': lambda: check_rename_end_year(recs, graph),
        'end_before_items': lambda: check_end_before_items(con, recs),
        'status_years': lambda: check_status_years(recs),
        'closure_ordered': lambda: check_closure_ordered(con, recs),
        'operator_profile': lambda: check_operator_profile(recs, graph),
        'operator_inherited': lambda: check_operator_inherited(recs, graph),
        'person_spelling': lambda: check_people(con, recs),
        'memorial': lambda: check_memorial(con),
        'rename_years': lambda: check_rename_years(recs, renames, operator_links(con)),
    }
    flags = []
    for kind, fn in checks.items():
        if args.kind and kind not in args.kind and not (kind == 'person_spelling' and 'person_common' in args.kind):
            continue
        flags += fn()
    con.close()
    if args.kind:
        flags = [f for f in flags if f['kind'] in args.kind]
    # Any flag on one name of a renamed pair: the rename may be why its years look wrong.
    pairs_of = defaultdict(list)
    for (eid, lid), why in renames.items():
        pair = {'earlier': eid, 'later': lid, 'year': rename_year(recs[eid], recs[lid]), 'why': why}
        pairs_of[eid].append(pair)
        pairs_of[lid].append(pair)
    for f in flags:
        if 'renames' not in f and pairs_of.get(f.get('facility_id')):
            f['renames'] = pairs_of[f['facility_id']]
    # stable ids, so results written by research agents survive a rerun
    seen = defaultdict(int)
    for f in flags:
        key = f.get('facility_id') or f.get('memorial_id') or f.get('person_id') or f.get('report_id') or             '-'.join(str(x) for x in f.get('person_ids', [])) or name_key(f.get('name') or '')
        base = '%s-%s' % (f['kind'], str(key).replace(' ', '_'))
        seen[base] += 1
        f['flag_id'] = base if seen[base] == 1 else '%s-%d' % (base, seen[base])
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, 'flags.json'), 'w', encoding='utf-8') as fh:
        json.dump(flags, fh, indent=1, ensure_ascii=False)
    counts = defaultdict(int)
    for f in flags:
        counts[f['kind']] += 1
    lines = ['# Data audit flags', '', '%d records, %d flags' % (len(recs), len(flags)), '']
    lines += ['- %s: %d' % (k, v) for k, v in sorted(counts.items())]
    for kind in sorted(counts):
        lines += ['', '## ' + kind, '']
        for f in [x for x in flags if x['kind'] == kind][:200]:
            lines.append('- %s %s: %s' % (f.get('name') or f.get('person') or f['kop'].get('name', ''),
                                          '(%s)' % f['place'] if f.get('place') else '', f['why']))
    with open(os.path.join(OUT, 'report.md'), 'w', encoding='utf-8') as fh:
        fh.write('\n'.join(lines) + '\n')
    print('\n'.join(lines[:12]))
    if args.propose_renames:
        propose_renames(recs, [f for f in flags if f['kind'] == 'rename_years'])
    if args.list:
        for f in flags:
            print(json.dumps(f, ensure_ascii=False)[:400])


if __name__ == '__main__':
    main()
