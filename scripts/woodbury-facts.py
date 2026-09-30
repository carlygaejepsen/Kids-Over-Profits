"""
Facts from every Woodbury Reports issue, proposed as additions to the facility
records: staff and where each person worked over the years, incidents, and
program history (opening and closing years, renames, owners, moves, size,
ages, memberships).

    python scripts/woodbury-facts.py [--dir tmp/woodbury-extract] [--out C:/tmp/kop-woodbury/pending/facts.json]

Inputs (tmp/woodbury-extract/, gitignored like the rest of tmp/):
  text/<YYYY-MM>.txt   each issue's text, page by page (=== PAGE n ===)
  issues.json          issue label, number, attachment id and URL per file
  facts/<YYYY-MM>.json what a reader pulled out of that issue: person,
                       incident and program items, each with a verbatim quote
                       and its page (the reading instructions are in
                       INSTRUCTIONS.md beside them)

Every quote is checked against the issue text; one that is not there is kept
but flagged and never preselected. Programs are matched to facilities_v2 in
tmp/prod.sqlite the way scripts/woodbury-scan.py matches article headers.
One person's items across all issues become one career line, so each staff
entry carries the other places the newsletter put them. Anything the record
already holds is left out.

The output goes to the server beside the mention candidates
(~/kop-import/woodbury/facts.json). The owner reviews it at KOP Data Tools >
Woodbury Facts (inc/woodbury-facts.php); nothing reaches a record until
accepted there. The repository is public, so the output (it quotes the
newsletter at length) is never committed.
"""

import argparse
import collections
import hashlib
import importlib.util
import json
import os
import re
import sqlite3
import sys
import unicodedata

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
spec = importlib.util.spec_from_file_location('woodbury_scan', os.path.join(ROOT, 'scripts', 'woodbury-scan.py'))
ws = importlib.util.module_from_spec(spec)
spec.loader.exec_module(ws)

LEADERSHIP = re.compile(r'\b(founder|co-?founder|owner|president|ceo|chief executive|executive director|head ?master|'
                        r'head of school|principal|administrator|chairman|managing director|general manager|'
                        r'^director$|program director|campus director|site director)\b', re.I)
INCIDENT_LABELS = {
    'death': 'Death', 'abuse': 'Abuse', 'sexual_abuse': 'Sexual abuse', 'restraint': 'Restraint',
    'injury': 'Injury', 'runaway': 'Runaway', 'arrest': 'Arrest', 'criminal_charge': 'Criminal charge',
    'lawsuit': 'Lawsuit', 'investigation': 'Investigation', 'license_action': 'License action',
    'closure_order': 'Closure order', 'legislation': 'Legislation', 'other': 'Incident',
}
NOTE_LABELS = {
    'accreditation': 'Accreditation', 'membership': 'Membership', 'license': 'License', 'cost': 'Cost',
    'length_of_stay': 'Length of stay', 'address': 'Address', 'founder': 'Founded by', 'merged': 'Merger',
    'new_campus': 'Campus', 'other': 'Note', 'renamed': 'Name change', 'moved': 'Move', 'owner': 'Owner',
    'operator': 'Operator', 'acquired': 'Acquired', 'opened': 'Opened', 'closed': 'Closed',
    'capacity': 'Capacity', 'ages': 'Ages', 'gender': 'Gender', 'program_type': 'Type',
}
COMPANY_WORDS = re.compile(r'\b(inc|llc|l\.l\.c|ltd|group|education|services|health|healthcare|company|corp|'
                           r'corporation|foundation|partners|capital|holdings|enterprises|associates|behavioral|'
                           r'systems|management|ministries|network|family of|schools)\b', re.I)
TYPES = [
    (r'wilderness', 'Wilderness Therapy'),
    (r'residential treatment|\brtc\b', 'Residential Treatment Center'),
    (r'therapeutic boarding|\btbs\b', 'Therapeutic Boarding School'),
    (r'emotional growth', 'Emotional Growth School'),
    (r'young adult|transitional', 'Young Adult Program'),
    (r'transport|escort', 'Transport Service'),
    (r'ranch', 'Ranch Program'),
    (r'boarding school', 'Boarding School'),
    (r'psychiatric hospital', 'Psychiatric Hospital'),
]


HARM = re.compile(r'\b(abus|assault|death|died|dies|killed|suicid|injur|hospital|arrest|charg|convict|guilty|plead|pled|'
                  r'lawsuit|sued|sues|suit\b|settle|investigat|allegation|alleg|runaway|ran away|missing|escap|riot|restrain|'
                  r'neglect|violation|cited|citation|fine[ds]?\b|license|revok|suspend|shut|clos|police|sheriff|molest|rape|'
                  r'sexual|beat|attack|complaint|grand jury|indict|prosecut)', re.I)
CHILD = re.compile(r'\b(student|child|children|boy|girl|teen|youth|client|resident|camper|participant|minor|son|daughter|'
                   r'\d{1,2}-year-old)\b', re.I)


def clean_name(s):
    """A program name out of a rename phrase: "known as the X program" -> "X"."""
    s = re.sub(r'\s+', ' ', s or '').strip(' .,;:"\'()')
    s = re.sub(r'^(?:was\s+)?(?:known|called|named)\s+(?:as\s+)?', '', s, flags=re.I)
    s = re.sub(r'^(?:the)\s+', '', s, flags=re.I)
    s = re.sub(r'\s*\((?:in\s+)?\d{4}\).*$|;.*$|,\s*(?:in|since)\s+\d{4}.*$|\s+in\s+\d{4}.*$', '', s)
    s = re.sub(r',?\s+(?:and\s+)?(?:renamed|rebranded)\b.*$', '', s, flags=re.I)
    s = re.sub(r'\s+program$', '', s, flags=re.I)
    return s.strip(' .,;:"\'')


def clean_company(value):
    """A company name out of "an Aspen Education Group program", "parent company: UHS (Universal Health Services)"."""
    s = re.sub(r'\s+', ' ', value or '').strip(' .')
    s = re.sub(r'^(?:(?:was|is|now|recently|formerly)\s+)?(?:acquired|bought|purchased|owned|operated|run|managed|sold)'
               r'(?:\s+(?:by|to))?\s+', '', s, flags=re.I)
    s = re.sub(r'^(?:parent company|parent|owner|operator|company)\s*:\s*', '', s, flags=re.I)
    s = re.sub(r'^(?:a\s+|an\s+|the\s+)?(?:division|subsidiary|member|affiliate|part|program)\s+of\s+', '', s, flags=re.I)
    s = re.sub(r'^(?:a|an|the)\s+', '', s, flags=re.I)
    s = re.sub(r'\s+(?:program|programs|company|family of programs|family)$', '', s, flags=re.I)
    s = re.sub(r'^(?:parent company|parent)\s+', '', s, flags=re.I)
    s = re.split(r';|\s+/\s+|,\s*(?:which|who|that|part|a division|a subsidiary|a member|member)\b', s, flags=re.I)[0].strip()
    s = re.sub(r'\s+(?:family of\b.*|program|programs)$', '', s, flags=re.I)
    m = re.match(r'^([A-Z]{2,6})\s*\(([^)]{6,})\)$', s)
    if m:
        s = m.group(2)
    s = re.sub(r',?\s+(?:Inc|LLC|L\.L\.C|Ltd|Corp)\.?$', '', s, flags=re.I)
    return s.strip(' .,;:"\'')


def flat(s):
    s = unicodedata.normalize('NFKC', ws.clean(s or ''))
    s = s.replace('\u2013', '-').replace('\u2014', '-').replace('\u00a0', ' ')
    return re.sub(r'\s+', ' ', s).strip().lower()


def unhyphen(s):
    return re.sub(r'(\w)- (\w)', r'\1\2', s)


def load_pages(path):
    raw = open(path, encoding='utf-8').read()
    pages = {}
    for m in re.finditer(r'=== PAGE (\d+) ===\n(.*?)(?==== PAGE \d+ ===|\Z)', raw, re.S):
        f = flat(m.group(2))
        pages[int(m.group(1))] = (f, unhyphen(f))
    return pages


def find_quote(quote, page, pages):
    """(found, page): the page the quote is on, the stated one first."""
    q = flat(quote).strip(' .…"\'')
    q = re.sub(r'\s*(\.\.\.|…)\s*', ' ... ', q)
    parts = [p.strip() for p in q.split(' ... ') if len(p.strip()) >= 12] or [q]
    order = [page] + [p for p in sorted(pages) if p != page]
    for pn in order:
        if pn not in pages:
            continue
        text, text2 = pages[pn]
        if all(p in text or unhyphen(p) in text2 for p in parts):
            return True, pn
    # Across a page break (an article that runs on).
    for pn in order:
        if pn in pages and pn + 1 in pages:
            text = pages[pn][1] + ' ' + pages[pn + 1][1]
            if all(unhyphen(p) in text for p in parts):
                return True, pn
    # The reader tidied a word or two: the start and end both on one page.
    if len(q) >= 60:
        head, tail = unhyphen(q[:40]), unhyphen(q[-40:])
        for pn in order:
            if pn in pages and head in pages[pn][1] and tail in pages[pn][1]:
                return True, pn
    return False, page


def person_key(name):
    s = unicodedata.normalize('NFKD', name or '').encode('ascii', 'ignore').decode().lower()
    s = re.sub(r'\b(dr|mr|mrs|ms|rev|jr|sr|ii|iii|iv|phd|md|lcsw|lpc|ma|ms|med|edd|psyd)\b\.?', ' ', s)
    s = re.sub(r'[^a-z\s-]', ' ', s)
    words = [w for w in s.replace('-', ' ').split() if len(w) > 1]
    if len(words) < 2:
        return ''
    return words[0] + ' ' + words[-1]


def year_of(s):
    m = re.search(r'\b(19[4-9]\d|20[0-2]\d)\b', s or '')
    return int(m.group(1)) if m else None


def years_label(ys):
    ys = sorted(set(y for y in ys if y))
    if not ys:
        return ''
    return str(ys[0]) if ys[0] == ys[-1] else '%d-%d' % (ys[0], ys[-1])


def clean_role(role):
    role = re.sub(r'\s+', ' ', (role or '')).strip(' ,;')
    return role[:1].upper() + role[1:] if role else ''


def ckey(*parts):
    return hashlib.md5('|'.join(str(p) for p in parts).encode('utf-8')).hexdigest()[:16]


def load_records(con):
    recs = {}
    for fid, name, state, city, status, sy, ey, js in con.execute(
            'SELECT id, name, state, city, status, start_year, end_year, json_data FROM facilities_v2'):
        try:
            d = json.loads(js)
        except Exception:  # noqa: BLE001
            d = {}
        recs[fid] = {'id': fid, 'name': name, 'state': (state or '').upper(), 'city': city or '', 'status': status or '',
                     'doc': d, 'blob': flat(js)}
    return recs


def staff_names(doc):
    out = {}
    staff = doc.get('staff') or {}
    for k in ('administrator', 'notableStaff'):
        for s in staff.get(k) or []:
            name = s.get('name') if isinstance(s, dict) else s
            pk = person_key(name or '')
            if pk:
                out[pk] = (k, s)
    return out


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--dir', default=os.path.join(ROOT, 'tmp', 'woodbury-extract'))
    ap.add_argument('--out', default='C:/tmp/kop-woodbury/pending/facts.json')
    args = ap.parse_args()

    issues = {x['date']: x for x in json.load(open(os.path.join(args.dir, 'issues.json'), encoding='utf-8'))}
    con = sqlite3.connect(ws.DB)
    names, past = ws.load_facilities(con)
    recs = load_records(con)

    stats = collections.Counter()
    items = []
    for fn in sorted(os.listdir(os.path.join(args.dir, 'facts'))):
        if not fn.endswith('.json'):
            continue
        date = fn[:7]
        iss = issues.get(date)
        if not iss:
            print('no issue for', fn, file=sys.stderr)
            continue
        try:
            data = json.load(open(os.path.join(args.dir, 'facts', fn), encoding='utf-8'))
        except Exception as e:  # noqa: BLE001
            print('bad json', fn, e, file=sys.stderr)
            continue
        pages = load_pages(os.path.join(args.dir, 'text', iss['file']))
        for it in data.get('items') or []:
            if not isinstance(it, dict) or not (it.get('program') or '').strip():
                stats['no_program'] += 1
                continue
            found, pg = find_quote(it.get('quote') or '', int(it.get('page') or 0), pages)
            stats['quote_found' if found else 'quote_missing'] += 1
            it['found'] = found
            it['page'] = pg
            it['issue'] = date
            it['issue_year'] = int(date[:4])
            items.append(it)
    print('%d items (%s)' % (len(items), dict(stats)))

    # ---- Programs -> records -------------------------------------------
    match_cache = {}
    # Word sets of each name's distinctive part, for names the header matcher
    # misses: "Cottonwood de Tucson" / "Cottonwood Tucson", "San Cristobal"
    # with no place / "San Cristobal Ranch Academy".
    cores = []
    for nk, fs in names.items():
        words = set(ws.core(nk).split())
        if len(words) >= 2:
            cores.append((words, fs))

    def loose(program, state):
        words = set(ws.core(ws.key(program)).split()) - {'de', 'la', 'el'}
        if len(words) < 2:
            return []
        found = []
        for cw, fs in cores:
            cw = cw - {'de', 'la', 'el'}
            if len(cw) >= 2 and (words <= cw or cw <= words):
                found.extend(f for f in fs if f not in found and (not state or f['state'] == state))
        return found if (state and found) or len({f['id'] for f in found}) == 1 else []

    def match(program, place):
        state = ws.place_state(place or '') if place else ''
        k = (ws.key(program), state)
        if k not in match_cache:
            if ws.key(program) in ws.GENERIC_NAMES or len(ws.key(program)) < 5:
                match_cache[k] = (None, [], 'none', '')
                return match_cache[k]
            f, alts, kind, note = ws.match_header(program, state, names, past)
            if f and kind == 'section' and 'past name' in note:
                kind = 'past_name'
            if not f:
                cands = loose(program, state)
                if cands:
                    f, alts, kind = cands[0], cands[1:6], 'fuzzy'
                    note = 'Close name%s: check it is the same program.' % (', same state' if state else '')
            match_cache[k] = (f, alts, {'section': 'exact'}.get(kind, kind), note)
        return match_cache[k]

    for it in items:
        f, alts, kind, note = match(it['program'].strip(), it.get('place', ''))
        it['fid'] = f['id'] if f else 0
        it['match'] = kind if f else 'none'
        it['match_note'] = note
        it['alts'] = [{'id': a['id'], 'name': a['name'], 'state': a['state']} for a in alts[:5]]
        it['pkey'] = ('f%d' % f['id']) if f else ('p' + ws.key(it['program']))

    def evidence(it):
        iss = issues[it['issue']]
        return {'issue': it['issue'], 'label': iss['label'], 'number': iss['number'], 'issue_id': iss['id'],
                'page': it['page'], 'url': iss['url'] + '#page=%d' % it['page'],
                'quote': re.sub(r'\s+', ' ', it.get('quote') or '').strip()[:600], 'found': it['found']}

    def cite(evs):
        evs = sorted(evs, key=lambda e: (e['issue'], e['page']))
        parts = []
        for e in evs[:4]:
            parts.append('%s, p. %d' % (e['label'], e['page']))
        more = ' and %d more' % (len(evs) - 4) if len(evs) > 4 else ''
        return 'Woodbury Reports, ' + '; '.join(parts) + more

    def prog_name(it):
        if it['fid']:
            return recs[it['fid']]['name']
        return ws.title_case(it['program']) if it['program'].isupper() else it['program'].strip()

    proposals = {}

    def propose(it_list, group, op, path, value, label, vkey, conflict='', current=''):
        first = it_list[0]
        key = ckey(first['pkey'], op, path, vkey)
        evs = [evidence(i) for i in it_list]
        p = proposals.get(key)
        if p:
            seen = {(e['issue'], e['page'], e['quote'][:80]) for e in p['evidence']}
            p['evidence'] += [e for e in evs if (e['issue'], e['page'], e['quote'][:80]) not in seen]
            p['found'] = p['found'] or any(i['found'] for i in it_list)
            return p
        p = {
            'key': key, 'facility_id': first['fid'], 'program': prog_name(first), 'program_as_written': first['program'].strip(),
            'place': first.get('place', ''), 'match': first['match'], 'match_note': first['match_note'],
            'alternatives': first['alts'], 'group': group, 'op': op, 'path': path, 'value': value, 'label': label,
            'conflict': conflict, 'current': current, 'evidence': evs, 'found': any(i['found'] for i in it_list),
        }
        proposals[key] = p
        return p

    # ---- People: one career line per person -----------------------------
    people = collections.defaultdict(list)
    for it in items:
        if it.get('kind') == 'person':
            pk = person_key(it.get('person', ''))
            if pk:
                people[pk].append(it)
            else:
                stats['person_no_name'] += 1

    def position_year(it):
        return year_of(it.get('date', '')) or it['issue_year']

    for pk, its in people.items():
        display = max((i.get('person', '').strip() for i in its), key=len)
        # Positions: (program key) -> roles and years.
        positions = collections.OrderedDict()
        for it in sorted(its, key=lambda i: (position_year(i), i['issue'])):
            ev = (it.get('event') or 'holds_role').lower()
            pos = positions.setdefault(it['pkey'], {'program': prog_name(it), 'roles': [], 'years': [], 'before': None,
                                                    'events': [], 'items': []})
            role = clean_role(it.get('role'))
            if role and role.lower() not in [r.lower() for r in pos['roles']]:
                pos['roles'].append(role)
            if ev == 'previously':
                y = it['issue_year']
                pos['before'] = min(pos['before'] or y, y)
                if year_of(it.get('date', '')):
                    pos['years'].append(year_of(it['date']))
            else:
                pos['years'].append(position_year(it))
            if ev in ('joined', 'left', 'promoted', 'founded', 'owns', 'retired', 'died'):
                pos['events'].append('%s %s' % (ev, it.get('date') or it['issue'][:4]))
            pos['items'].append(it)
            fp = (it.get('from_program') or '').strip()
            if fp:
                f2, _a, k2, _n = match(fp, '')
                fkey = ('f%d' % f2['id']) if f2 else ('p' + ws.key(fp))
                prev = positions.setdefault(fkey, {'program': recs[f2['id']]['name'] if f2 else fp, 'roles': [], 'years': [],
                                                   'before': None, 'events': [], 'items': []})
                fr = clean_role(it.get('from_role'))
                if fr and fr.lower() not in [r.lower() for r in prev['roles']]:
                    prev['roles'].append(fr)
                prev['before'] = min(prev['before'] or position_year(it), position_year(it))

        def pos_line(p):
            when = years_label(p['years'])
            if not when and p['before']:
                when = 'before %d' % p['before']
            elif p['before'] and p['years'] and min(p['years']) >= p['before']:
                when = 'before %d' % p['before']
            role = ', '.join(p['roles']) or 'Staff'
            ev = '; '.join(e for e in p['events'] if not e.startswith('died'))
            return '%s - %s%s%s' % (role, p['program'], ' (%s)' % when if when else '', ' [%s]' % ev if ev else '')

        def span(p):
            ys = p['years'] or ([p['before'] - 1] if p['before'] else [])
            return (min(ys), max(ys)) if ys else (0, 0)

        for pkey, pos in positions.items():
            if not pos['items']:
                continue  # known only as someone's "from" program; it shows in their career line
            # The page prints the other jobs as "Previously: ...", so later ones say so.
            here = span(pos)
            others = []
            for k, p in positions.items():
                if k == pkey:
                    continue
                later = bool(pos['years']) and span(p)[0] > here[1]
                others.append(('later ' if later else '') + pos_line(p))
            role = ', '.join(pos['roles']) or 'Staff'
            when = years_label(pos['years'])
            former = bool(pos['before']) and not pos['years']
            if former:
                role = 'Former ' + role + ' (before %d, Woodbury Reports)' % pos['before']
            else:
                role += ' (%sWoodbury Reports)' % (when + ', ' if when else '')
            if any(e.startswith(('left', 'retired')) for e in pos['events']):
                role += ', left'
            died = [e for e in pos['events'] if e.startswith('died')]
            first = pos['items'][0]
            evs = [evidence(i) for i in pos['items']]
            value = {'name': display, 'role': role, 'pastJobs': '; '.join(others)}
            path = 'staff.administrator' if LEADERSHIP.search(', '.join(pos['roles'])) else 'staff.notableStaff'
            current = ''
            if first['fid']:
                have = staff_names(recs[first['fid']]['doc']).get(pk)
                if have:
                    h = have[1] if isinstance(have[1], dict) else {'name': have[1], 'role': '', 'pastJobs': ''}
                    if (h.get('pastJobs') or '').strip() and (h.get('role') or '').strip():
                        stats['person_already'] += 1
                        continue
                    path = 'staff.' + have[0]
                    current = '%s: %s' % (h.get('name', ''), h.get('role', '') or '(no role)')
            label = '%s, %s' % (display, role)
            if died:
                label += ' (died %s)' % died[0].split(' ', 1)[1]
            p = propose(pos['items'], 'staff', 'add_staff', path, value, label, pk, current=current)
            p['person'] = display
            p['career'] = [pos_line(x) for x in positions.values()]

    # ---- Incidents -----------------------------------------------------
    for it in items:
        if it.get('kind') != 'incident':
            continue
        cat = (it.get('category') or 'other').lower()
        summary = re.sub(r'\s+', ' ', (it.get('summary') or '').strip())
        if not summary:
            continue
        # The newsletter files good news too (a hike, a board seat, a staff member's
        # death from illness): only harm, legal and regulatory events are incidents.
        if cat == 'other' and not HARM.search(summary):
            stats['incident_not_harm'] += 1
            continue
        if cat == 'death' and not CHILD.search(summary):
            stats['incident_staff_death'] += 1
            continue
        when = it.get('date') or ''
        line = '%s: %s: %s (%s, p. %d)' % (when or 'Reported %s' % issues[it['issue']]['label'],
                                           INCIDENT_LABELS.get(cat, 'Incident'), summary.rstrip('.') + '.',
                                           'Woodbury Reports, ' + issues[it['issue']]['label'], it['page'])
        vkey = cat + '|' + (when if when else summary.lower()[:80])
        if it['fid'] and flat(summary)[:60] in recs[it['fid']]['blob']:
            stats['incident_already'] += 1
            continue
        propose([it], 'incident', 'add_list', 'criticalIncidents.customIncidents', line,
                INCIDENT_LABELS.get(cat, 'Incident') + ': ' + summary, vkey)

    # ---- Program facts -------------------------------------------------
    def note(it, field, value):
        text = '%s: %s (%s, p. %d)' % (NOTE_LABELS.get(field, 'Note'), value, 'Woodbury Reports, ' + issues[it['issue']]['label'], it['page'])
        vkey = field + '|' + flat(value)
        if it['fid'] and len(flat(value)) >= 4 and flat(value) in recs[it['fid']]['blob']:
            stats['note_already'] += 1
            return
        propose([it], 'history' if field in ('renamed', 'moved', 'merged', 'founder', 'new_campus', 'owner', 'operator', 'acquired', 'opened', 'closed') else 'details',
                'add_list', 'notes', text, NOTE_LABELS.get(field, 'Note') + ': ' + value, vkey)

    for it in items:
        if it.get('kind') != 'program':
            continue
        field = (it.get('field') or 'other').lower()
        value = re.sub(r'\s+', ' ', (it.get('value') or '').strip())
        if not value:
            continue
        rec = recs.get(it['fid'])
        doc = rec['doc'] if rec else {}
        op_ = doc.get('operatingPeriod') or {}
        ident = doc.get('identification') or {}
        det = doc.get('facilityDetails') or {}
        y = year_of(it.get('date', '')) or year_of(value)

        if field == 'opened' and y:
            have = op_.get('startYear')
            if have == y:
                stats['already'] += 1
            elif have:
                propose([it], 'history', 'add_list', 'notes',
                        'Opened: %d per %s, p. %d (the record says %s)' % (y, 'Woodbury Reports, ' + issues[it['issue']]['label'], it['page'], have),
                        'Opened in %d (the record says %s)' % (y, have), 'opened-conflict|%d' % y,
                        conflict='The record has start year %s' % have)
            else:
                propose([it], 'history', 'set_if_empty', 'operatingPeriod.startYear', y, 'Start year: %d' % y, 'start|%d' % y)
            continue
        if field == 'closed':
            if (rec and rec['status'] == 'Closed' and (op_.get('endYear') or not y)):
                stats['already'] += 1
                continue
            propose([it], 'history', 'set_closed', 'operatingPeriod', {'endYear': y},
                    'Closed' + (' in %d' % y if y else '') + ' (sets status Closed' + (', end year %d' % y if y else '') + ')',
                    'closed|%s' % y, current='Status now: %s' % (rec['status'] if rec else '?'))
            continue
        if field == 'renamed':
            # "renamed from X", "X to Y", "formerly known as X", "now called Y".
            old = new = ''
            v = re.sub(r'^(?:was\s+|has\s+been\s+)?(?:renamed|rebranded|changed (?:its|the) name)\s*', '', value, flags=re.I)
            m = re.search(r'\bfrom\s+(.+?)(?:\s+to\s+(.+))?$', v, re.I)
            m2 = re.match(r'^(?:to|as|now called|now known as|became)\s+(.+)$', v, re.I)
            if m2:
                # "renamed to Y": the program as the item names it is the old name.
                old, new = it['program'], m2.group(1)
            elif m:
                old, new = m.group(1), m.group(2) or ''
            else:
                m = re.search(r'^(.+?)\s+to\s+(.+)$', v)
                if m:
                    old, new = m.group(1), m.group(2)
                else:
                    m = re.search(r'\bunder the name\s+(.+)$', value, re.I) or \
                        re.search(r'\b(?:formerly|previously|originally|once)\b\s*(.+)$', value, re.I)
                    old = m.group(1) if m else ''
            old, new = clean_name(old), clean_name(new)
            if old and not old[:1].isupper():
                note(it, field, value)
                continue
            if rec and old and (m2 or ws.key(old) == ws.key(rec['name'])
                                or (ws.core(ws.key(old)) and ws.core(ws.key(old)) == ws.core(ws.key(rec['name'])))):
                # This record is the old name: the new one is another era's record (name-eras rule), so only a note.
                note(it, field, value)
                continue
            if old and rec and ws.key(old) != ws.key(rec['name']):
                if ws.key(old) in [ws.key(n) for n in (ident.get('pastNames') or []) + (ident.get('otherNames') or []) if isinstance(n, str)]:
                    stats['already'] += 1
                    continue
                propose([it], 'history', 'add_list', 'identification.pastNames', old, 'Past name: ' + old, 'past|' + ws.key(old))
                continue
            note(it, field, value)
            continue
        if field in ('acquired', 'owner', 'operator'):
            company = clean_company(value)
            # A name, not a sentence: capitalized, no digits or percent, short.
            if (COMPANY_WORDS.search(company) and len(company) < 60 and rec and company[:1].isupper()
                    and not re.search(r'[\d%]', company) and len(company.split()) <= 7):
                known = [ws.key(x) for x in [ident.get('currentOperator') or ''] + (ident.get('currentOwners') or [])
                         + (ident.get('pastOperators') or []) + (ident.get('otherOperators') or []) if isinstance(x, str)]
                if ws.key(company) in known:
                    stats['already'] += 1
                    continue
                p = propose([it], 'history', 'add_list', 'identification.pastOperators', company,
                            'Operator/owner: ' + company + (' (%s)' % (it.get('date') or it['issue'][:4])), 'op|' + ws.key(company))
                p['note_line'] = '%s: %s (%s, p. %d)' % (NOTE_LABELS.get(field), value, 'Woodbury Reports, ' + issues[it['issue']]['label'], it['page'])
                continue
            note(it, field, value)
            continue
        if field == 'moved':
            m = re.search(r'\bfrom\s+(.+?)(?:\s+to\s+(.+))?$', value, re.I)
            if m and rec:
                raw = m.group(1).strip(' .')
                if flat(raw) in rec['blob']:
                    stats['already'] += 1
                    continue
                loc = {'raw': raw, 'city': '', 'state': ws.place_state(raw) or None, 'country': None,
                       'fromYear': None, 'toYear': y}
                propose([it], 'history', 'add_list', 'location.formerLocations', loc,
                        'Former location: %s%s' % (raw, ' (moved %d)' % y if y else ''), 'moved|' + flat(raw))
                continue
            note(it, field, value)
            continue
        if field == 'capacity':
            m = re.search(r'\b(\d{1,4})\b', value)
            if m and rec and det.get('capacity') is None:
                propose([it], 'details', 'set_if_empty', 'facilityDetails.capacity', int(m.group(1)),
                        'Capacity: %s (as of %s)' % (m.group(1), it['issue'][:4]), 'cap|' + m.group(1))
                continue
            if rec and det.get('capacity') is not None and m and int(m.group(1)) == det.get('capacity'):
                stats['already'] += 1
                continue
            note(it, field, value)
            continue
        if field == 'ages':
            m = re.search(r'\b(\d{1,2})\s*(?:-|to|–|through)\s*(\d{1,2})\b', value)
            ar = det.get('ageRange') or {}
            if m and rec and ar.get('min') is None and ar.get('max') is None:
                propose([it], 'details', 'set_if_empty', 'facilityDetails.ageRange', {'min': int(m.group(1)), 'max': int(m.group(2))},
                        'Ages %s-%s' % (m.group(1), m.group(2)), 'ages|%s-%s' % (m.group(1), m.group(2)))
                continue
            note(it, field, value)
            continue
        if field == 'gender':
            v = value.lower()
            g = 'Co-ed' if re.search(r'co-?ed|both|boys and girls|girls and boys|mixed', v) else \
                'Male' if re.search(r'\b(boys|male|men|young men)\b', v) else \
                'Female' if re.search(r'\b(girls|female|women|young women)\b', v) else ''
            if g and rec and not det.get('gender'):
                propose([it], 'details', 'set_if_empty', 'facilityDetails.gender', g, 'Serves: ' + g, 'gender|' + g)
                continue
            if g and rec and det.get('gender') == g:
                stats['already'] += 1
                continue
            note(it, field, value)
            continue
        if field == 'program_type':
            t = next((name for pat, name in TYPES if re.search(pat, value, re.I)), '')
            if t and rec and not det.get('type'):
                propose([it], 'details', 'set_if_empty', 'facilityDetails.type', t, 'Type: ' + t, 'type|' + t)
                continue
            if rec and det.get('type'):
                stats['already'] += 1
                continue
            note(it, field, value)
            continue
        note(it, field, value)

    # One value per slot: where issues disagree (a start year of 2006 and of
    # 2009), the best-supported one is proposed and the others become notes.
    slots = collections.defaultdict(list)
    for p in proposals.values():
        if p['op'] == 'set_if_empty':
            slots[(p['facility_id'] or p['program'], p['path'])].append(p)
    for group in slots.values():
        if len(group) < 2:
            continue
        group.sort(key=lambda p: (-len(p['evidence']), json.dumps(p['value'])))
        keep = group[0]
        for p in group[1:]:
            p['op'], p['path'] = 'add_list', 'notes'
            p['conflict'] = 'Another issue gives %s; this one is kept as a note' % json.dumps(keep['value'])
            e = p['evidence'][0]
            p['value'] = '%s (Woodbury Reports, %s, p. %d)' % (p['label'], e['label'], e['page'])
    out = sorted(proposals.values(), key=lambda p: (p['facility_id'] == 0, p['program'].lower(), p['group'], p['label']))
    for p in out:
        p['evidence'].sort(key=lambda e: (e['issue'], e['page']))
        p['issue_date'] = p['evidence'][0]['issue']
        p['preselect'] = bool(p['found'] and p['match'] == 'exact' and not p['conflict'])
    os.makedirs(os.path.dirname(args.out), exist_ok=True)
    json.dump({'built': 'woodbury-facts', 'proposals': out}, open(args.out, 'w', encoding='utf-8'), ensure_ascii=False, indent=0)

    by_group = collections.Counter(p['group'] for p in out)
    matched = sum(1 for p in out if p['facility_id'])
    facs = len({p['facility_id'] for p in out if p['facility_id']})
    unmatched_programs = len({p['program'] for p in out if not p['facility_id']})
    print('%d proposals (%s): %d on %d records, %d for %d programs with no record; %d preselected' % (
        len(out), dict(by_group), matched, facs, len(out) - matched, unmatched_programs, sum(p['preselect'] for p in out)))
    print('skipped/flags:', dict(stats))
    print('wrote', args.out)


if __name__ == '__main__':
    main()
