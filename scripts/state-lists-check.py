#!/usr/bin/env python3
"""Check our facility records against the facility lists a few states publish.

These states publish no inspection reports, only lists of who is licensed or
registered. This is a record check, not a report tracker. Nothing is written
to any database; the output is tmp/state-lists/report.md and report.json.

Sources (each has its own small parser, see SOURCES):
  MO  license-exempt residential care facility notification listing (PDF, monthly)
  KY  child caring facilities by status (XLSX)
  AK  residential child care facilities, RCCF rows only (XLSX)
  LA  DCFS licensed facilities, Residential Home rows only (HTML table)
  IN  active residential licenses, child caring institutions + group homes (2 PDFs)
  KS  PRTF facilities (PDF; the host answers 403 to python-requests, so
      curl_cffi with a Chrome fingerprint is the fallback, as for the NC scraper)
  MS  Medicaid PRTF providers (PDF, dated 02/22/19 on its face)

Usage:
  python scripts/state-lists-check.py                  # every state, from the newest snapshot
  python scripts/state-lists-check.py --state MO --state KY
  python scripts/state-lists-check.py --refresh        # refetch (one request a second)
  python scripts/state-lists-check.py --selftest

Files: tmp/state-lists/<ST>/<YYYY-MM-DD>/ holds the fetched files, meta.json
(URLs, fetch date, list date) and rows.json. Every fetch makes a snapshot for
its date; the added/gone diff compares a state's newest snapshot with the one
before it.

Matching mirrors scripts/match-inspection-names.php and
kop_facility_pages_inspections(): the same name key (kop_normalize_facility_name_rules
without the curated alias map), a match when the keys are equal or the
RECORD's key extends the listed key (both 12+ characters), against a record's
name, current name, other names and past names, inside the list's state.
More than one record reaching one listed name is reported as ambiguous and
never picked. "Near misses" (shared words) are hints only.
"""
import argparse
import datetime
import json
import os
import re
import sqlite3
import sys
import time
import html as htmllib

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = os.path.join(ROOT, 'tmp', 'state-lists')
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
UA = ('Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
      '(KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36')

# ---------------------------------------------------------------- name keys

_SUFFIX = re.compile(r'\s+(?:l\s*l\s*c|llc|inc|incorporated|ltd|limited|co|corp|corporation)$')
_TITLES = re.compile(
    r'\s+(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|operator|'
    r'date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|licensee|licensed\s+capacity).*$')


def name_key(name):
    """kop_facility_pages_name_key(): trailing (...) off, then the rules of
    kop_normalize_facility_name_rules() (no curated alias map)."""
    name = (name or '').strip()
    if not name:
        return ''
    name = re.sub(r'\s*\([^)]*\)\s*$', '', name)
    s = name.lower().strip()
    if not s:
        return ''
    cp = s.find(':')
    if cp != -1:
        before, after = s[:cp].strip(), s[cp + 1:].strip()
        label = re.search(r'(?:board\s+chairperson|chairperson|administrator|executive\s+director|director|owner|'
                          r'operator|date\s+of\s+site\s+visit|site\s+visit|visit\s+date|inspection\s+date|'
                          r'licensee|licensed\s+capacity)$', before)
        if re.match(r'\d{1,4}[/\-.]\d{1,2}(?:[/\-.]\d{2,4})?\b', after) or label:
            s = before
    s = _TITLES.sub('', s)
    s = re.sub(r'\s+d\s*/?\s*b\s*/?\s*a\s+', ' ', s)
    s = re.sub(r'\s*[\-–—]\s*', ' ', s)
    s = re.sub(r'\s*&\s*', ' and ', s)
    s = re.sub(r'[^\w\s]', '', s)
    s = re.sub(r'\s+', ' ', s).strip()
    s = re.sub(r'^the\s+', '', s)
    s = _SUFFIX.sub('', s)
    return s.strip()


def key_matches(listed_key, record_key):
    """kop_state_related_key_matches(): equal, or the record's key extends the listed one (12+ chars each)."""
    if not listed_key or not record_key:
        return False
    if listed_key == record_key:
        return True
    if len(listed_key) < 12 or len(record_key) < 12:
        return False
    return record_key.startswith(listed_key)


_STOP = {'the', 'of', 'and', 'for', 'inc', 'llc', 'center', 'home', 'homes', 'services', 'youth', 'county',
         'children', 'childrens', 'family', 'academy', 'school', 'program', 'programs', 'house', 'group'}


def words(key):
    return {w for w in key.split() if w and w not in _STOP}


def match_rows(rows, records):
    """records: [{id, name, status, city, keys:[...]}] of ONE state. Returns
    (results, hit_ids): one result per listed row with status match | ambiguous | none."""
    results = []
    hit = set()
    for row in rows:
        lk = name_key(row['name'])
        found = []
        for rec in records:
            if any(key_matches(lk, rk) for rk in rec['keys']):
                found.append(rec)
        res = {'row': row, 'key': lk}
        if len(found) == 1:
            res['status'] = 'match'
            res['records'] = [_rec_brief(found[0])]
            hit.add(found[0]['id'])
        elif len(found) > 1:
            res['status'] = 'ambiguous'
            res['records'] = [_rec_brief(r) for r in found]
            for r in found:
                hit.add(r['id'])
        else:
            res['status'] = 'none'
            res['records'] = []
            lw = words(lk)
            scored = []
            if lw:
                for rec in records:
                    best = max((len(lw & words(rk)) for rk in rec['keys']), default=0)
                    if best >= 2 or (best >= 1 and len(lw) == 1):
                        scored.append((best, rec))
            scored.sort(key=lambda x: -x[0])
            res['near'] = [_rec_brief(r) for _, r in scored[:3]]
        results.append(res)
    return results, hit


def _rec_brief(r):
    return {'id': r['id'], 'name': r['name'], 'city': r.get('city', ''), 'status': r.get('status', '')}


# ---------------------------------------------------------------- records

def load_records(states):
    """facilities_v2 records per state: name, current/other/past names as keys."""
    out = {s: [] for s in states}
    if not os.path.exists(DB):
        raise SystemExit('No SQLite mirror at %s (python scripts/sync-prod-sqlite.py)' % DB)
    con = sqlite3.connect(DB)
    q = ','.join('?' * len(states))
    for rid, uname, name, city, state, status, doc in con.execute(
            'SELECT id, unique_name, name, city, state, status, json_data FROM facilities_v2 WHERE state IN (%s)' % q,
            list(states)):
        try:
            d = json.loads(doc) or {}
        except ValueError:
            d = {}
        ident = d.get('identification') or {}
        names = [ident.get('name'), ident.get('currentName'), uname]
        for k in ('otherNames', 'pastNames'):
            v = ident.get(k)
            if isinstance(v, str):
                v = re.split(r'[;\n]', v)
            for n in (v or []):
                names.append(n.get('name') if isinstance(n, dict) else n)
        keys = []
        for n in names:
            k = name_key(n if isinstance(n, str) else '')
            if k and k not in keys:
                keys.append(k)
        out[state].append({'id': rid, 'name': name or uname, 'city': city or '', 'status': status or '', 'keys': keys})
    con.close()
    return out


# ---------------------------------------------------------------- fetching

_last = [0.0]
_session = [None]


def _requests():
    if _session[0] is None:
        import requests
        s = requests.Session()
        s.headers['User-Agent'] = UA
        s.headers['Accept'] = '*/*'
        _session[0] = s
    return _session[0]


def get(url):
    """One request at a time, 1 s apart. Returns (bytes, content_type, last_modified, final_url).
    python-requests first; a 403 falls back to curl_cffi (Chrome fingerprint), never verify=False."""
    wait = 1.05 - (time.time() - _last[0])
    if wait > 0:
        time.sleep(wait)
    r = _requests().get(url, timeout=60, allow_redirects=True)
    _last[0] = time.time()
    if r.status_code == 403:
        try:
            from curl_cffi import requests as cr
        except ImportError:
            cr = None
        if cr is not None:
            time.sleep(1.05)
            r = cr.get(url, impersonate='chrome', timeout=60)
            _last[0] = time.time()
    if r.status_code != 200:
        raise RuntimeError('HTTP %s for %s' % (r.status_code, url))
    h = r.headers
    return r.content, h.get('content-type', ''), h.get('last-modified', ''), str(r.url)


def page_links(url):
    """(href, text) pairs of a page, hrefs made absolute."""
    from urllib.parse import urljoin
    body, _, _, final = get(url)
    text = body.decode('utf-8', 'replace')
    out = []
    for m in re.finditer(r'<a\b[^>]*?href="([^"]+)"[^>]*>(.*?)</a>', text, re.S | re.I):
        out.append((urljoin(final, htmllib.unescape(m.group(1))), re.sub(r'<[^>]+>', '', htmllib.unescape(m.group(2))).strip()))
    return out, text, final


# ---------------------------------------------------------------- parsers
# Each takes already-extracted structures, so --selftest can feed fixtures.

def _clean(s):
    return re.sub(r'\s+', ' ', (s or '').replace('�', '-')).strip()


def _zip(s):
    m = re.findall(r'\b(\d{5})(?:-\d{4})?\b', s or '')
    return m[-1] if m else ''


def _row(name, source_url, **kw):
    r = {'name': _clean(name), 'city': '', 'state': '', 'zip': '', 'address': '', 'license_id': '', 'type': '',
         'capacity': None, 'source_url': source_url, 'extra': {}}
    r.update(kw)
    return r


def parse_mo(tables, url):
    """Missouri: Agency Name | Physical Address | Mailing Address | Director | Fire | Health. Director not kept."""
    rows = []
    for t in tables:
        for c in t:
            c = [_clean(x) for x in c]
            if len(c) < 2 or not c[0] or c[0].lower().startswith('agency name'):
                continue
            addr = c[1]
            city = ''
            m = re.search(r',\s*([^,]+?),?\s*MO\b', addr)
            if m:
                city = m.group(1).strip()
            else:
                # "13201 Clayton Rd. St. Louis MO 63131" / "287 County Road 275 Myrtle, MO 65778": city = the
                # words before MO, back to the street's last number or street word
                pre = re.split(r',?\s+MO\b', addr)[0].split()
                stop = {'rd', 'rd.', 'road', 'ave', 'ave.', 'avenue', 'dr', 'dr.', 'drive', 'ln', 'ln.', 'lane', 'blvd',
                        'blvd.', 'hwy', 'hwy.', 'street', 'way', 'pkwy', 'ct', 'ct.', 'court', 'circle', 'trail'}
                got = []
                for tok in reversed(pre):
                    if tok.lower().strip(',') in stop or re.fullmatch(r'[\d-]+,?', tok):
                        break
                    got.append(tok.strip(','))
                city = ' '.join(reversed(got))
            rows.append(_row(c[0], url, address=addr, city=city, state='MO', zip=_zip(addr),
                             type='License-exempt residential care facility',
                             extra={'fire_inspection': c[4] if len(c) > 4 else '', 'health_inspection': c[5] if len(c) > 5 else ''}))
    return rows


def parse_ky(table, url):
    """Kentucky: header row then one row per facility (Agency Facility Status, LicenseNumber, FacilityName, ...)."""
    head = [str(h or '') for h in table[0]]
    ix = {h: i for i, h in enumerate(head)}
    rows = []
    for r in table[1:]:
        if not r or not r[ix['FacilityName']]:
            continue
        kinds = [lab for col, lab in (('GroupHome', 'Group home'), ('Institution', 'Institution'),
                                      ('EmergencyShelter', 'Emergency shelter')) if str(r[ix[col]] or '').upper() == 'Y']
        cap = r[ix['MaxCapacity']]
        rows.append(_row(r[ix['FacilityName']], url, address=_clean(str(r[ix['FacilityAddress']] or '')),
                         city=_clean(str(r[ix['FacilityCity']] or '')), state='KY',
                         zip=str(r[ix['MailingZip']] or '') if str(r[ix['MailingCity']] or '') == str(r[ix['FacilityCity']] or '') else '',
                         license_id=str(r[ix['LicenseNumber']] or ''), type=', '.join(kinds) or 'Child caring facility',
                         capacity=int(cap) if isinstance(cap, (int, float)) else None,
                         extra={'status': _clean(str(r[ix['Agency Facility Status']] or '')),
                                'age_served': _clean(str(r[ix['AgeServed']] or '')),
                                'owner_corporation': _clean(str(r[ix['OwnerCorporation']] or ''))}))
    return rows


def parse_ak(table, url):
    """Alaska: RCCF rows only (the sheet also lists adult mental health residences, 'AMHR')."""
    head = [str(h or '').strip() for h in table[0]]
    ix = {}
    for i, h in enumerate(head):
        ix[h] = i  # duplicate 'P City': the later column wins, both hold the city
    rows = []
    for r in table[1:]:
        typ = str(r[ix['Type Res']] or '').strip()
        if not r[ix['Name']] or not typ.upper().startswith('RCCF'):
            continue
        cap = r[ix['# Res']]
        lt = r[ix['Licensed To']]
        rows.append(_row(r[ix['Name']], url, address=_clean(str(r[ix['Physical Address']] or '')),
                         city=_clean(str(r[ix['P City']] or '')), state='AK', zip=str(r[ix['P ZIP Code']] or ''),
                         type=typ, capacity=int(cap) if isinstance(cap, (int, float)) else None,
                         extra={'licence_type': str(r[ix['Lic Type']] or ''),
                                'licensed_to': lt.strftime('%Y-%m-%d') if hasattr(lt, 'strftime') else str(lt or ''),
                                'owner_agency': _clean(str(r[ix['Owner/Agency']] or ''))}))
    return rows


def parse_la(html_text, url):
    """Louisiana: the licensed facilities table; Residential Home rows only (child placing agencies and maternity homes dropped)."""
    from bs4 import BeautifulSoup
    soup = BeautifulSoup(html_text, 'lxml')
    rows = []
    for tb in soup.find_all('table'):
        head = [th.get_text(' ', strip=True) for th in tb.find_all('th')]
        if 'Program Type' not in head or 'Name' not in head:
            continue
        ix = {h: i for i, h in enumerate(head)}
        for tr in tb.find_all('tr'):
            td = [td.get_text(' ', strip=True) for td in tr.find_all('td')]
            if len(td) < len(head) or td[ix['Program Type']].lower() != 'residential home':
                continue
            nm = td[ix['Name']]
            if nm.strip().upper() == 'TEST':
                continue
            rows.append(_row(nm, url, address=td[ix['Street']], city=td[ix['City']], state='LA', zip=td[ix['Zip Code']],
                             license_id=td[ix['License Number']], type='Residential Home, ' + td[ix['Subprogram']],
                             extra={'parish': td[ix['Parish']]}))
    return rows


def parse_in(tables, url, kind):
    """Indiana DCS active residential licenses (county, type, name, ...). Name often ends with the licence number.
    No city is published, only the county."""
    rows = []
    for t in tables:
        for c in t:
            c = [_clean(x) if x is not None else '' for x in c]
            idx = next((i for i, x in enumerate(c) if re.fullmatch(r'\d{9,12}', x)), None)
            if idx is None or not c[0] or not c[2]:
                continue
            name = c[2]
            if 'do not use' in name.lower():
                continue
            lic = ''
            m = re.search(r'[\s,]+(\d{5})(?!\d).*$', name)  # "NAME 33578 AL 5/25/23": licence number, then clerical notes
            if m:
                lic = m.group(1)
                name = name[:m.start()].strip(' ,')
            capi = 6 if idx == 3 else 9
            cap = c[capi] if capi < len(c) else ''
            rows.append(_row(name, url, state='IN', license_id=lic, type=kind,
                             capacity=int(cap) if cap.isdigit() else None,
                             extra={'county': c[0], 'resource_id': c[idx], 'effective_end': c[idx + 2] if idx + 2 < len(c) else ''}))
    return rows


def parse_ks(words_, url):
    """Kansas PRTF sheet: the table has no ruling, so words are placed by x position. Each address
    (starts with a number, address column) is a row anchor; name words (left column) and city words
    attach to the nearest anchor by vertical position."""
    hdr = {w['text']: w for w in words_ if w['text'] in ('Facility', 'Address', 'City', 'Phone') and w['top'] < 120}
    if not all(k in hdr for k in ('Facility', 'Address', 'City', 'Phone')):
        return []
    xa, xc, xp = hdr['Address']['x0'] - 4, hdr['City']['x0'] - 4, hdr['Phone']['x0'] - 4
    body = [w for w in words_ if w['top'] > hdr['Address']['bottom']]
    anchors = []
    for w in sorted(body, key=lambda w: (w['top'], w['x0'])):
        if xa <= w['x0'] < xc and re.fullmatch(r'\d{2,5}', w['text']):
            if not any(abs(a['top'] - w['top']) < 4 for a in anchors):
                anchors.append(w)
    if not anchors:
        return []
    groups = [{'a': a, 'name': [], 'addr': [], 'city': []} for a in anchors]
    for w in body:
        if w['x0'] >= xp:
            continue
        # a line level with an address belongs to it; a line off every address is the start of the name that
        # wraps onto the next address line, so it goes to the nearest address BELOW within 30 pt, else the nearest
        level = [g for g in groups if abs(g['a']['top'] - w['top']) < 6]
        if level:
            g = level[0]
        else:
            below = [g for g in groups if 0 < g['a']['top'] - w['top'] <= 30]
            g = min(below, key=lambda g: g['a']['top']) if below else min(groups, key=lambda g: abs(g['a']['top'] - w['top']))
        if w['x0'] < xa:
            g['name'].append(w)
        elif w['x0'] < xc:
            g['addr'].append(w)
        else:
            g['city'].append(w)
    rows = []
    for g in groups:
        key = lambda w: (round(w['top'] / 5), w['x0'])
        name = ' '.join(w['text'] for w in sorted(g['name'], key=key))
        addr = ' '.join(w['text'] for w in sorted(g['addr'], key=key))
        city = ' '.join(w['text'] for w in sorted(g['city'], key=key))
        city = re.sub(r',?\s*KS$', '', city.strip())
        if name:
            rows.append(_row(re.sub(r'\s+[-–—]\s*\(', ' (', name), url, address=_clean(addr), city=_clean(city),
                             state='KS', type='Psychiatric residential treatment facility (PRTF)'))
    return rows


def parse_ms(tables, url):
    """Mississippi Medicaid PRTF providers: name, address (city, ST zip), phone. Out-of-state providers are
    kept with their own state; '*' marks need State Level Case Review."""
    rows = []
    for t in tables:
        for c in t:
            c = [(x or '') for x in c]
            if len(c) < 2 or not _clean(c[0]) or _clean(c[0]).lower().startswith('facility name'):
                continue
            addr = c[1].replace('\n', ', ')
            m = re.search(r'([^,]+),\s*([A-Z]{2})\s+(\d{5})', addr)
            nm = _clean(c[0]).rstrip('*').strip()
            rows.append(_row(nm, url, address=_clean(addr), city=m.group(1).strip() if m else '',
                             state=m.group(2) if m else 'MS', zip=m.group(3) if m else '',
                             type='Psychiatric residential treatment facility (PRTF)',
                             extra={'state_level_case_review': '*' in c[0]}))
    return rows


# ---------------------------------------------------------------- sources
# discover(): the live file URLs (found from the agency page, since names carry versions/dates)
# parse(paths, urls): rows + (date, basis)

def _pdf_tables(path):
    import pdfplumber
    out = []
    with pdfplumber.open(path) as pdf:
        for pg in pdf.pages:
            out += pg.extract_tables()
        meta = pdf.metadata or {}
        text = '\n'.join((pg.extract_text() or '') for pg in pdf.pages[:1])
    return out, meta, text


def _pdf_moddate(meta):
    m = re.match(r'D:(\d{4})(\d{2})(\d{2})', str(meta.get('ModDate') or meta.get('CreationDate') or ''))
    return '%s-%s-%s' % m.groups() if m else ''


def _http_date(s):
    try:
        from email.utils import parsedate_to_datetime
        return parsedate_to_datetime(s).strftime('%Y-%m-%d')
    except Exception:
        return ''


def _find(links, pat, what):
    for href, text in links:
        if re.search(pat, href + ' ' + text, re.I):
            return href
    raise RuntimeError('no link matching %s on the agency page (%s)' % (pat, what))


def discover_mo():
    page = 'https://mydss.mo.gov/provider-services/children/residential-program/license-exempt'
    links, _, _ = page_links(page)
    wrapper = _find(links, r'license-exempt-agencies', 'notification compliance listing')
    text = get(wrapper)[0].decode('utf-8', 'replace')
    m = re.search(r'data-src="([^"]+\.pdf)"', text)
    if not m:
        raise RuntimeError('the listing page no longer embeds a PDF')
    return [m.group(1)], page


def parse_mo_files(paths, urls, lm):
    tables, meta, _ = _pdf_tables(paths[0])
    return parse_mo(tables, urls[0]), _pdf_moddate(meta) or _http_date(lm[0]), 'PDF modification date'


def discover_ky():
    page = 'https://www.chfs.ky.gov/agencies/os/oig/drcc/Pages/cccpb.aspx'
    links, _, _ = page_links(page)
    return [_find(links, r'ChildCaringByStatus.*\.xlsx', 'child caring list')], page


def parse_ky_files(paths, urls, lm):
    import openpyxl
    ws = openpyxl.load_workbook(paths[0], data_only=True).active
    return parse_ky([list(r) for r in ws.iter_rows(values_only=True)], urls[0]), _http_date(lm[0]), 'file Last-Modified header'


def discover_ak():
    page = 'https://health.alaska.gov/en/services/residential-child-care-facilities-licensing/'
    links, _, _ = page_links(page)
    return [_find(links, r'rccf.*\.xlsx', 'RCCF list')], page


def parse_ak_files(paths, urls, lm):
    import openpyxl
    ws = openpyxl.load_workbook(paths[0], data_only=True).active
    d = _http_date(lm[0])
    m = re.search(r'(january|february|march|april|may|june|july|august|september|october|november|december)-(\d{4})', urls[0], re.I)
    basis = 'file Last-Modified header'
    if m:
        basis += '; file name says %s %s' % (m.group(1).capitalize(), m.group(2))
    return parse_ak([list(r) for r in ws.iter_rows(values_only=True)], urls[0]), d, basis


def discover_la():
    return ['https://dcfs.louisiana.gov/page/licensed-facilities'], 'https://dcfs.louisiana.gov/page/licensed-facilities'


def parse_la_files(paths, urls, lm):
    with open(paths[0], encoding='utf-8') as f:
        text = f.read()
    return parse_la(text, urls[0]), _http_date(lm[0]), 'page Last-Modified header (the page prints no date)'


def discover_in():
    return ['https://www.in.gov/dcs/files/Active_Residential_Licenses-Child-Caring-Institution.pdf',
            'https://www.in.gov/dcs/files/Active_Residential_Licenses-Group-Home.pdf'], 'https://www.in.gov/dcs/placement'


def parse_in_files(paths, urls, lm):
    rows, dates = [], []
    for p, u in zip(paths, urls):
        tables, _, text = _pdf_tables(p)
        kind = 'Group home' if 'Group-Home' in u else 'Child caring institution'
        rows += parse_in(tables, u, kind)
        m = re.search(r'As of\s+(\d{1,2})/(\d{1,2})/(\d{4})', text)
        if m:
            dates.append('%s-%02d-%02d' % (m.group(3), int(m.group(1)), int(m.group(2))))
    return rows, ' / '.join(dates), 'printed "As of" dates (child caring institutions / group homes)'


def discover_ks():
    page = 'https://www.kdads.ks.gov/services-programs/behavioral-health/psychiatric-residential-treatment-facilities'
    links, _, _ = page_links(page)
    for href, text in links:
        if text.strip().lower() == 'kansas prtf facilities':
            return [href], page
    raise RuntimeError('"Kansas PRTF Facilities" link not on the KDADS page')


def parse_ks_files(paths, urls, lm):
    import pdfplumber
    with pdfplumber.open(paths[0]) as pdf:
        rows = parse_ks(pdf.pages[0].extract_words(), urls[0])
        meta = pdf.metadata or {}
    return rows, _pdf_moddate(meta) or _http_date(lm[0]), 'PDF modification date'


def discover_ms():
    page = 'https://medicaid.ms.gov/programs/mental-health/mental-health-services/'
    links, _, _ = page_links(page)
    return [_find(links, r'PRTF', 'PRTF providers')], page


def parse_ms_files(paths, urls, lm):
    tables, meta, text = _pdf_tables(paths[0])
    import pdfplumber
    with pdfplumber.open(paths[0]) as pdf:
        last = pdf.pages[-1].extract_text() or ''
    m = re.search(r'(?m)^(\d{2})/(\d{2})/(\d{2})\s*$', last)
    d = '20%s-%s-%s' % (m.group(3), m.group(1), m.group(2)) if m else (_pdf_moddate(meta) or _http_date(lm[0]))
    return parse_ms(tables, urls[0]), d, 'date printed on the PDF' if m else 'PDF modification date'


SOURCES = {
    'MO': dict(label='Missouri license-exempt residential care facility notification listing',
               covers='License-exempt residential care facilities only (religious institutions, state/county-run, educational '
                      'overnight programs, sleep-away camps). Licensed Missouri facilities are not on it.',
               absent_meaningful=False, discover=discover_mo, parse=parse_mo_files, ext=['.pdf']),
    'KY': dict(label='Kentucky child caring facilities by status',
               covers='Licensed child caring facilities (group homes, institutions, emergency shelters, treatment). '
                      'Child placing agencies are a separate file and not used.',
               absent_meaningful=True, discover=discover_ky, parse=parse_ky_files, ext=['.xlsx']),
    'AK': dict(label='Alaska residential child care facilities (RCCF)',
               covers='Licensed residential child care facilities; adult mental health residences (AMHR) on the same sheet are dropped.',
               absent_meaningful=True, discover=discover_ak, parse=parse_ak_files, ext=['.xlsx']),
    'LA': dict(label='Louisiana DCFS licensed facilities, Residential Home rows',
               covers='Licensed residential homes (Type IV, Type B/I). Child placing agencies and maternity homes dropped. '
                      'The page lists a short set; the Class B (Type I) list is a separate PDF not used here.',
               absent_meaningful=True, discover=discover_la, parse=parse_la_files, ext=['.html']),
    'IN': dict(label='Indiana DCS active residential licenses (child caring institutions and group homes)',
               covers='Licensed child caring institutions and group homes. No city is published, only the county. '
                      'Private secure facilities and child placing agencies are not in these two files.',
               absent_meaningful=True, discover=discover_in, parse=parse_in_files, ext=['.pdf', '.pdf']),
    'KS': dict(label='Kansas PRTF facilities (KDADS)',
               covers='Psychiatric residential treatment facilities only. Most Kansas TTI records are other licence types.',
               absent_meaningful=False, discover=discover_ks, parse=parse_ks_files, ext=['.pdf']),
    'MS': dict(label='Mississippi Medicaid PRTF providers',
               covers='Medicaid-enrolled psychiatric residential treatment facilities only (includes a few out-of-state providers). '
                      'The PDF is dated 02/22/19.',
               absent_meaningful=False, discover=discover_ms, parse=parse_ms_files, ext=['.pdf']),
}


# ---------------------------------------------------------------- snapshots

def snapshots(st):
    d = os.path.join(OUT, st)
    if not os.path.isdir(d):
        return []
    return sorted(x for x in os.listdir(d) if re.fullmatch(r'\d{4}-\d{2}-\d{2}', x) and
                  os.path.exists(os.path.join(d, x, 'rows.json')))


def fetch_snapshot(st, today):
    src = SOURCES[st]
    urls, page = src['discover']()
    sdir = os.path.join(OUT, st, today)
    os.makedirs(sdir, exist_ok=True)
    paths, final_urls, lms = [], [], []
    for i, u in enumerate(urls):
        body, ctype, lm, final = get(u)
        p = os.path.join(sdir, 'source%d%s' % (i + 1, src['ext'][i]))
        with open(p, 'wb') as f:
            f.write(body)
        paths.append(p)
        final_urls.append(final)
        lms.append(lm)
    rows, list_date, basis = src['parse'](paths, final_urls, lms)
    meta = {'state': st, 'fetched_on': today, 'agency_page': page, 'urls': final_urls, 'last_modified': lms,
            'list_date': list_date, 'list_date_basis': basis, 'rows': len(rows)}
    with open(os.path.join(sdir, 'meta.json'), 'w', encoding='utf-8') as f:
        json.dump(meta, f, indent=1)
    with open(os.path.join(sdir, 'rows.json'), 'w', encoding='utf-8') as f:
        json.dump(rows, f, indent=1)
    return today


def load_snapshot(st, date):
    sdir = os.path.join(OUT, st, date)
    with open(os.path.join(sdir, 'meta.json'), encoding='utf-8') as f:
        meta = json.load(f)
    with open(os.path.join(sdir, 'rows.json'), encoding='utf-8') as f:
        rows = json.load(f)
    return meta, rows


def row_identity(r):
    """Same facility between snapshots: licence id when the list has one, else name key + zip/city."""
    k = name_key(r['name'])
    if r.get('license_id'):
        return 'id:' + r['license_id'] + '|' + k
    return 'nm:' + k + '|' + (r.get('zip') or r.get('city', '').lower() or (r.get('extra') or {}).get('county', '').lower())


def diff_rows(prev, cur):
    """Added / gone between two row lists (multiset by identity)."""
    def bag(rows):
        b = {}
        for r in rows:
            b.setdefault(row_identity(r), []).append(r)
        return b
    pb, cb = bag(prev), bag(cur)
    added, gone = [], []
    for k, rs in cb.items():
        extra = len(rs) - len(pb.get(k, []))
        added += rs[:extra] if extra > 0 else []
    for k, rs in pb.items():
        extra = len(rs) - len(cb.get(k, []))
        gone += rs[:extra] if extra > 0 else []
    return added, gone


# ---------------------------------------------------------------- run

def check_state(st, records, date, meta, rows, prev):
    src = SOURCES[st]
    out = {'state': st, 'label': src['label'], 'covers': src['covers'], 'absent_meaningful': src['absent_meaningful'],
           'snapshot': date, 'urls': meta['urls'], 'fetched_on': meta['fetched_on'], 'list_date': meta.get('list_date', ''),
           'list_date_basis': meta.get('list_date_basis', ''), 'rows': len(rows)}
    by_state = {}
    for r in rows:
        by_state.setdefault(r.get('state') or st, []).append(r)
    results, hit = [], set()
    for s, rs in by_state.items():
        recs = records.get(s)
        if recs is None:
            recs = load_records([s])[s]
            records[s] = recs
        res, h = match_rows(rs, recs)
        results += res
        if s == st:
            hit = h
    out['matched'] = [r for r in results if r['status'] == 'match']
    out['ambiguous'] = [r for r in results if r['status'] == 'ambiguous']
    out['no_record'] = [r for r in results if r['status'] == 'none']
    open_not_listed = [_rec_brief(r) for r in records[st] if r['status'] == 'Open' and r['id'] not in hit]
    out['open_records'] = sum(1 for r in records[st] if r['status'] == 'Open')
    out['open_not_on_list'] = open_not_listed
    if prev is None:
        out['diff'] = {'previous': None, 'added': [], 'gone': []}
    else:
        pm, prows = prev
        a, g = diff_rows(prows, rows)
        out['diff'] = {'previous': pm['fetched_on'], 'previous_list_date': pm.get('list_date', ''),
                       'added': a, 'gone': g}
    return out


def _fmt_row(r):
    bits = [r['name']]
    place = ', '.join(x for x in (r.get('city'), r.get('state')) if x)
    if r.get('extra', {}).get('county') and not r.get('city'):
        place = r['extra']['county'] + ' County, IN'
    if place:
        bits.append(place)
    if r.get('license_id'):
        bits.append('lic ' + r['license_id'])
    return ' | '.join(bits)


def render_state(o, no_record_limit=None):
    L = []
    L.append('## %s - %s' % (o['state'], o['label']))
    L.append('')
    L.append('- Source: ' + '; '.join(o['urls']))
    L.append('- List date: %s (%s). Fetched %s, snapshot %s.' % (o['list_date'] or 'unknown', o['list_date_basis'], o['fetched_on'], o['snapshot']))
    L.append('- Covers: ' + o['covers'])
    L.append('- Rows parsed: %d. Matched a record: %d. Ambiguous: %d. No record: %d.' %
             (o['rows'], len(o['matched']), len(o['ambiguous']), len(o['no_record'])))
    L.append('')
    if o['matched']:
        L.append('### Matched')
        for r in o['matched']:
            rec = r['records'][0]
            L.append('- %s -> %s [#%d, %s]' % (r['row']['name'], rec['name'], rec['id'], rec['status']))
        L.append('')
    if o['ambiguous']:
        L.append('### Ambiguous (more than one record reaches the name; none chosen)')
        for r in o['ambiguous']:
            L.append('- %s -> %s' % (r['row']['name'], '; '.join('%s [#%d, %s]' % (x['name'], x['id'], x['status']) for x in r['records'])))
        L.append('')
    L.append('### Listed, no record (%d)' % len(o['no_record']))
    nr = o['no_record'] if no_record_limit is None else o['no_record'][:no_record_limit]
    for r in nr:
        near = ''
        if r.get('near'):
            near = '  ~ near: ' + '; '.join('%s [#%d]' % (x['name'], x['id']) for x in r['near'])
        L.append('- %s%s' % (_fmt_row(r['row']), near))
    if len(nr) < len(o['no_record']):
        L.append('- ... %d more in report.json' % (len(o['no_record']) - len(nr)))
    L.append('')
    if o['absent_meaningful']:
        L.append('### Our Open records in %s the list does not have (%d of %d)' % (o['state'], len(o['open_not_on_list']), o['open_records']))
        for r in o['open_not_on_list']:
            L.append('- %s [#%d] %s' % (r['name'], r['id'], r['city']))
    else:
        L.append('### Our Open records the list does not have: not meaningful here')
        L.append('%d of %d Open %s records are not on this list, but the list covers only one facility type (see Covers); '
                 'names are in report.json.' % (len(o['open_not_on_list']), o['open_records'], o['state']))
    L.append('')
    d = o['diff']
    L.append('### Change since the previous snapshot')
    if d['previous'] is None:
        L.append('No earlier snapshot: this is the baseline. The next --refresh run diffs against it.')
    else:
        L.append('Previous snapshot %s (list date %s). Added: %d. Gone: %d.' % (d['previous'], d.get('previous_list_date') or 'unknown', len(d['added']), len(d['gone'])))
        for r in d['added']:
            L.append('- ADDED %s' % _fmt_row(r))
        for r in d['gone']:
            L.append('- GONE %s' % _fmt_row(r))
    L.append('')
    return '\n'.join(L)


def main():
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    ap.add_argument('--state', action='append', help='two-letter state, repeatable (default: all)')
    ap.add_argument('--refresh', action='store_true', help='refetch the lists (one request a second) and make today\'s snapshot')
    ap.add_argument('--selftest', action='store_true')
    ap.add_argument('--full', action='store_true', help='print every no-record name (default: first 40 per state; the files always have all)')
    a = ap.parse_args()
    if a.selftest:
        return selftest()
    states = [s.upper() for s in (a.state or SOURCES.keys())]
    for s in states:
        if s not in SOURCES:
            raise SystemExit('No source for %s (have %s)' % (s, ', '.join(SOURCES)))
    today = datetime.date.today().isoformat()
    records = load_records(states)
    report, failed, md = [], {}, ['# State facility lists vs our records', '', 'Generated %s from tmp/prod.sqlite (facilities_v2).' % today, '']
    for st in states:
        try:
            if a.refresh or not snapshots(st):
                fetch_snapshot(st, today)
            snaps = snapshots(st)
            date = snaps[-1]
            meta, rows = load_snapshot(st, date)
            prev = load_snapshot(st, snaps[-2]) if len(snaps) > 1 else None
            o = check_state(st, records, date, meta, rows, prev)
            report.append(o)
            md.append(render_state(o))
            print(render_state(o, None if a.full else 40))
        except Exception as e:  # one dead source does not stop the others
            failed[st] = '%s: %s' % (type(e).__name__, e)
            msg = '## %s - FAILED\n\n%s\n' % (st, failed[st])
            md.append(msg)
            print(msg, file=sys.stderr)
    os.makedirs(OUT, exist_ok=True)
    with open(os.path.join(OUT, 'report.md'), 'w', encoding='utf-8') as f:
        f.write('\n'.join(md))
    with open(os.path.join(OUT, 'report.json'), 'w', encoding='utf-8') as f:
        json.dump({'generated': today, 'states': report, 'failed': failed}, f, indent=1, ensure_ascii=False)
    print('Wrote tmp/state-lists/report.md and report.json' + ('; failed: ' + ', '.join(failed) if failed else ''))
    return 1 if failed else 0


# ---------------------------------------------------------------- selftest

def selftest():
    n = [0]

    def ok(cond, label):
        n[0] += 1
        if not cond:
            raise AssertionError('FAILED: ' + label)

    # name keys (same rules as kop_normalize_facility_name_rules)
    ok(name_key('The Academy at Sisters, Inc.') == 'academy at sisters', 'the + inc')
    ok(name_key('CERTS dba Kolob Canyon RTC') == 'certs kolob canyon rtc', 'dba')
    ok(name_key("Children’s Home & Ranch (Annex)") == 'childrens home and ranch', 'apostrophe, ampersand, trailing parens')
    ok(name_key('Boys Town Louisiana – City Park Family Home') == 'boys town louisiana city park family home', 'en dash')
    ok(key_matches('discovery ranch', 'discovery ranch south'), 'record extends listed (14 chars)')
    ok(not key_matches('discovery ranch south', 'discovery ranch'), 'reverse is not a match')
    ok(not key_matches('smc', 'smc knob noster unit'), 'short key never prefix-matches')

    # matcher: match, ambiguous, none, past name, near miss
    recs = [
        {'id': 1, 'name': 'Whetstone Boys Ranch', 'city': 'Mountain View', 'status': 'Open', 'keys': ['whetstone boys ranch']},
        {'id': 2, 'name': 'Shiloh Ranch', 'city': 'X', 'status': 'Open', 'keys': ['shiloh ranch', 'shiloh christian childrens ranch']},
        {'id': 3, 'name': 'Link Academy', 'city': 'Branson', 'status': 'Open', 'keys': ['link academy kanakuk']},
        {'id': 4, 'name': 'Link Academy Campus', 'city': 'Branson', 'status': 'Open', 'keys': ['link academy kanakuk campus']},
        {'id': 5, 'name': 'Heartland Girls Home', 'city': 'KC', 'status': 'Closed', 'keys': ['heartland girls home']},
        {'id': 6, 'name': 'Unlisted Place', 'city': 'Y', 'status': 'Open', 'keys': ['unlisted place']},
    ]
    rows = [_row(x, 'u') for x in ('Whetstone Boys Ranch', "Shiloh Christian Children's Ranch", 'Link Academy-Kanakuk',
                                   'Heartland Girls Academy', 'Totally Unknown Camp')]
    res, hit = match_rows(rows, recs)
    st = [r['status'] for r in res]
    ok(st == ['match', 'match', 'ambiguous', 'none', 'none'], 'statuses %s' % st)
    ok(res[1]['records'][0]['id'] == 2, 'past name reached the record')
    ok({x['id'] for x in res[2]['records']} == {3, 4}, 'ambiguous lists both, none picked')
    ok(res[3]['near'] and res[3]['near'][0]['id'] == 5, 'near miss is a hint only')
    ok(hit == {1, 2, 3, 4}, 'hit ids')

    # MO
    t = [[['Agency Name', 'Physical Address', 'Mailing Address (if different)', 'Director', 'Fire Inspection', 'Health\nInspection'],
          ['Whetstone Boys Ranch', '6850 CR 2660, Mountain View, MO 65548', '', 'Made Up', 'N/A', 'Yes'],
          ['Principia- Aron House', '13201 Clayton Rd. St. Louis MO 63131', '', 'Made Up', 'Yes', 'Yes']]]
    r = parse_mo(t, 'u')
    ok(len(r) == 2 and r[0]['city'] == 'Mountain View' and r[0]['zip'] == '65548' and r[1]['zip'] == '63131' and r[1]['city'] == 'St. Louis', 'MO rows')
    ok('Made Up' not in json.dumps(r), 'MO director not kept')

    # KY
    head = ['Agency Facility Status', 'LicenseNumber', 'FacilityName', 'FacilityAddress', 'FacilityCity', 'FacilityState',
            'MailingAddress', 'MailingCity', 'MailingState', 'MailingZip', 'TelephoneNumber', 'ExecutiveDirector',
            'TreatmentDirector', 'OwnerCorporation', 'GroupHome', 'Institution', 'EmergencyShelter', 'LicenceComponent',
            'MaxCapacity', 'AgeServed', 'SexOfChildrenServed']
    kr = ['Licensed', 500039, "Appalachian Children's Home", '1909 KY 3439', 'Barbourville', 'KY', 'PO Box 550', 'Barbourville', '17',
          40906, '', 'A', 'B', "Appalachian Children's Home", 'N', 'Y', 'Y', 'CC', 58, '11 - 18', 'Male,Female']
    r = parse_ky([head, kr], 'u')
    ok(r[0]['license_id'] == '500039' and r[0]['capacity'] == 58 and r[0]['type'] == 'Institution, Emergency shelter', 'KY row')
    ok('A' not in [r[0]['extra'].get('director')], 'KY staff not kept')

    # AK
    ah = ['P City', 'Name', 'Licensed To', 'Licensed From', 'Lic Type', 'Type Res', 'Notes', '# Res', 'Owner/Agency', 'Adm First Name',
          'Adm Last Name', 'Website Phone #', 'Facility Phone #', 'Administrator Cell #', 'email address', 'Physical Address', 'P City', 'P ZIP Code']
    a1 = ['Anchorage', 'Arc - Lionheart [AMHR]', datetime.datetime(2027, 7, 31), None, 'Biennial', 'DD/MH [AMHR 1115]', '', 5, 'The Arc', 'J', 'T', '', '', '', 'x@y', '5256 Lionheart Dr', 'Anchorage', '99508']
    a2 = ['Eagle River', 'ARCH Volunteers of America', datetime.datetime(2026, 11, 30), None, 'Biennial', 'RCCF - SU', '', 24, 'VOA', 'J', 'E', '', '', '', 'x@y', '8012 Stewart Mountain Drive', 'Eagle River', '99577']
    r = parse_ak([ah, a1, a2], 'u')
    ok(len(r) == 1 and r[0]['name'] == 'ARCH Volunteers of America' and r[0]['capacity'] == 24 and r[0]['zip'] == '99577', 'AK RCCF only')
    ok('x@y' not in json.dumps(r), 'AK emails not kept')

    # LA
    h = ('<table><thead><tr><th>Program Type</th><th>Subprogram</th><th>Name</th><th>License Number</th><th>Street</th><th>City</th>'
         '<th>Zip Code</th><th>Office Phone</th><th>Parish</th><th>Director</th></tr></thead><tbody>'
         '<tr><td>Residential Home</td><td>Type IV</td><td>Raintree House</td><td>2154</td><td>1219 Eighth St</td><td>New Orleans</td><td>70115</td><td>1</td><td>Orleans</td><td>D</td></tr>'
         '<tr><td>Child Placing Agency</td><td>Foster</td><td>Some Agency</td><td>2309</td><td>S</td><td>Monroe</td><td>71203</td><td>1</td><td>Ouachita</td><td>D</td></tr>'
         '<tr><td>Maternity Home</td><td>N/A</td><td>TEST</td><td>16403</td><td>S</td><td>BR</td><td>70802</td><td></td><td>x</td><td></td></tr>'
         '</tbody></table>')
    r = parse_la(h, 'u')
    ok(len(r) == 1 and r[0]['license_id'] == '2154' and r[0]['city'] == 'New Orleans', 'LA residential only')

    # IN: two layouts (group home 16 cols, institution 19 cols)
    gh = ['Allen', 'Group\nHome', "CATHERINE'S PLACE\nGROUP HOME 43898", '10000064538', '5/10/2021', '5/9/2025', '5', '13', '20', 'Female', '0', '', '', '', 'W,\nJ', 'S,\nR']
    dn = ['Adams', 'Group\nHome', 'Do Not Use 1.16.25', '10000129282', '1/17/2025', '1/16/2029', '9', '6', '15', 'Both', '8', '7', '22', 'Both', 'W', 'S']
    cci = ['Allen', 'Child\nCaring\nInstitution', "CROSSROAD CHILD &\nFAMILY SERVICES, INC. 30017", '', '', '10000000242', '4/1/2025', '3/31/2029', '1', '15', '6', '20', 'Both', '15', '6', '21', 'Both', 'W', 'S']
    r = parse_in([[gh, dn]], 'u', 'Group home') + parse_in([[cci]], 'u', 'Child caring institution')
    ok(len(r) == 2, 'IN skips Do Not Use')
    ok(r[0]['name'] == "CATHERINE'S PLACE GROUP HOME" and r[0]['license_id'] == '43898' and r[0]['capacity'] == 5, 'IN group home row')
    ok(r[1]['name'].startswith('CROSSROAD CHILD & FAMILY SERVICES, INC') and r[1]['capacity'] == 15 and r[1]['extra']['county'] == 'Allen', 'IN CCI row')

    # KS: words placed by column; a name wraps above and below the address line
    def W(text, x0, top):
        return {'text': text, 'x0': x0, 'top': top, 'bottom': top + 8}
    ws = [W('Facility', 44, 102), W('Address', 169, 102), W('City', 292, 102), W('Phone', 380, 102),
          W('Camber', 44, 116), W('Wheatland', 78, 116), W('(KVC', 120, 116), W('3000', 169, 130), W('New', 192, 130),
          W('Way', 212, 130), W('Hays', 292, 130), W('(913)890-7468', 380, 130), W('Hays)', 44, 129),
          W('EmberHope,', 44, 202), W('Inc.', 97, 202), W('900', 169, 203), W('W', 187, 203), W('Broadway', 198, 203), W('Newton', 292, 202)]
    r = parse_ks(ws, 'u')
    ok([x['name'] for x in r] == ['Camber Wheatland (KVC Hays)', 'EmberHope, Inc.'], 'KS names %s' % [x['name'] for x in r])
    ok(r[0]['city'] == 'Hays' and r[0]['address'] == '3000 New Way' and r[1]['city'] == 'Newton', 'KS city/address')

    # MS (one out-of-state provider keeps its own state)
    t = [[['Facility Name', 'Address', 'Phone Number', 'Fax Number'],
          ['CARES Center', '402 Wesley Avenue\nJackson, MS 39202', '1', '2'],
          ['Timber Ridge\nRanch*', '15000 Hwy 298\nBenton, AR 72019', '1', '2']]]
    r = parse_ms(t, 'u')
    ok(r[0]['state'] == 'MS' and r[1]['state'] == 'AR' and r[1]['name'] == 'Timber Ridge Ranch' and r[1]['extra']['state_level_case_review'], 'MS rows')

    # diff
    prev = [_row('Alpha House', 'u', zip='65001'), _row('Beta House', 'u', zip='65002'), _row('Gamma', 'u', license_id='7')]
    cur = [_row('Alpha House', 'u', zip='65001'), _row('Delta Ranch', 'u', zip='65003'), _row('GAMMA', 'u', license_id='7')]
    added, gone = diff_rows(prev, cur)
    ok([x['name'] for x in added] == ['Delta Ranch'] and [x['name'] for x in gone] == ['Beta House'], 'diff added/gone')
    dup = [_row('St. Nicholas Academy', 'u', zip='65109'), _row('St. Nicholas Academy', 'u', zip='65109')]
    ok(diff_rows(dup, dup[:1])[1][0]['name'] == 'St. Nicholas Academy', 'diff counts a repeated row')

    # snapshots: previous = the one before the newest
    global OUT
    import tempfile
    old = OUT
    OUT = tempfile.mkdtemp()
    try:
        for d in ('2026-09-01', '2026-10-01'):
            p = os.path.join(OUT, 'MO', d)
            os.makedirs(p)
            open(os.path.join(p, 'rows.json'), 'w').write('[]')
        os.makedirs(os.path.join(OUT, 'MO', 'scratch'))
        ok(snapshots('MO') == ['2026-09-01', '2026-10-01'], 'snapshot listing')
        o = check_state('MO', {'MO': recs}, '2026-10-01', {'urls': ['u'], 'fetched_on': '2026-10-01'}, [_row('Whetstone Boys Ranch', 'u', state='MO')],
                        ({'fetched_on': '2026-09-01'}, [_row('Gone Ranch', 'u', state='MO')]))
        ok(len(o['matched']) == 1 and len(o['diff']['gone']) == 1 and len(o['diff']['added']) == 1, 'check_state with previous')
        ok('GONE Gone Ranch' in render_state(o), 'render shows gone')
    finally:
        OUT = old
    print('selftest ok (%d checks)' % n[0])
    return 0


if __name__ == '__main__':
    sys.exit(main())
