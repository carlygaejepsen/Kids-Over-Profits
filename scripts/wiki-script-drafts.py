"""
Wiki update drafts written by script (docs/PLAN.md 3.12): every gap that has a fixed form becomes its added line here,
so models only write what needs judgement. Reads tmp/wiki-updates/drafts/<id>/{entry.md,gaps.json}
(scripts/wiki-pilot-prep.py --all-kinds) and writes, next to them:

  ops.json         the script's ops (by "script"), in the shape scripts/wiki-drafts.py assembles;
  model-gaps.json  the gaps left for the models: lawsuits, deaths, and news newer than the entry that reports an
                   event (a suit, a closure, an arrest, an investigation), each with its gid;
  excerpt.md       the entry's headings and the sections a model writes into (history, abuse/lawsuits), for a short read.

By script: staff ("**Name** was the Role of Program", their other industry roles linked to their r/troubledteens wiki
pages), news (a Related Media line in the entry's own "[Title](url) (Outlet, M/D/YYYY)" form), other and later names,
operators, a closure (header years and one sentence), approved serious findings ("In a report dated ..., <State>
inspectors found that ..." with the state's own words). A fact whose only source is KOP's record carries no link
and "kop_record": true; one from a Woodbury issue or a court filing cites it.

    python scripts/wiki-script-drafts.py <ids...>
"""
import json
import os
import re
import sys
from datetime import date

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DRAFTS = os.path.join(ROOT, 'tmp', 'wiki-updates', 'drafts')
sys.path.insert(0, os.path.join(ROOT, 'scripts'))
import importlib.util  # noqa: E402

_spec = importlib.util.spec_from_file_location('wiki_drafts', os.path.join(ROOT, 'scripts', 'wiki-drafts.py'))
wd = importlib.util.module_from_spec(_spec)
_spec.loader.exec_module(wd)

TITLES = json.load(open(os.path.join(ROOT, 'js', 'data', 'reddit-wiki', 'page-urls.json'), encoding='utf-8'))['titles']
TITLE_KEYS = {re.sub(r'[^a-z0-9]+', ' ', t.lower()).strip(): u for t, u in TITLES.items()}
# Pages whose wiki title is not the company's name.
TITLE_KEYS.update({
    'wwasp': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
    'wwasps': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
    'world wide association of specialty programs and schools': 'https://www.reddit.com/r/troubledteens/wiki/index/wwasp',
})
MODEL_NEWS = {'lawsuit', 'closure', 'arrest', 'expose', 'event'}
STATES = {'AL': 'Alabama', 'AK': 'Alaska', 'AZ': 'Arizona', 'AR': 'Arkansas', 'CA': 'California', 'CO': 'Colorado',
          'CT': 'Connecticut', 'DE': 'Delaware', 'FL': 'Florida', 'GA': 'Georgia', 'HI': 'Hawaii', 'ID': 'Idaho',
          'IL': 'Illinois', 'IN': 'Indiana', 'IA': 'Iowa', 'KS': 'Kansas', 'KY': 'Kentucky', 'LA': 'Louisiana',
          'ME': 'Maine', 'MD': 'Maryland', 'MA': 'Massachusetts', 'MI': 'Michigan', 'MN': 'Minnesota',
          'MS': 'Mississippi', 'MO': 'Missouri', 'MT': 'Montana', 'NE': 'Nebraska', 'NV': 'Nevada',
          'NH': 'New Hampshire', 'NJ': 'New Jersey', 'NM': 'New Mexico', 'NY': 'New York', 'NC': 'North Carolina',
          'ND': 'North Dakota', 'OH': 'Ohio', 'OK': 'Oklahoma', 'OR': 'Oregon', 'PA': 'Pennsylvania',
          'RI': 'Rhode Island', 'SC': 'South Carolina', 'SD': 'South Dakota', 'TN': 'Tennessee', 'TX': 'Texas',
          'UT': 'Utah', 'VT': 'Vermont', 'VA': 'Virginia', 'WA': 'Washington', 'WV': 'West Virginia',
          'WI': 'Wisconsin', 'WY': 'Wyoming'}


def wiki_link(name, own=''):
    """[Name](its wiki page) when the wiki has a page of that title (not the entry's own), else the name."""
    k = re.sub(r'[^a-z0-9]+', ' ', name.lower()).strip()
    short = re.sub(r'[^a-z0-9]+', ' ', re.sub(r'\s*\([^)]*\)\s*$', '', name).lower()).strip()
    abbr = re.search(r'\(([^)]+)\)\s*$', name)
    url = TITLE_KEYS.get(k) or TITLE_KEYS.get(re.sub(r'^the ', '', k)) or TITLE_KEYS.get(short) \
        or (TITLE_KEYS.get(re.sub(r'[^a-z0-9]+', ' ', abbr.group(1).lower()).strip()) if abbr else None)
    if not url or k == re.sub(r'[^a-z0-9]+', ' ', own.lower()).strip():
        return name
    return f'[{name}]({url})'


def fix_url(u):
    """Sources stored as site-relative paths came out under kidsoverprofits.org: put them back where they live. A HEAL
    copy from 2023 on (the squatter's domain) goes to HEAL's own capture (js/data/reddit-wiki/heal-archive-urls.json)."""
    u = re.sub(r'^https?://(www\.)?kidsoverprofits\.org/(r|u|user)/', r'https://www.reddit.com/\2/', u)
    u = re.sub(r'^https?://(www\.)?kidsoverprofits\.org/web/', 'https://web.archive.org/web/', u)
    m = re.match(r'https://web\.archive\.org/web/(\d+)\w*/(https?://(www\.)?heal-online\.org\S*)', u)
    if m and m.group(1) >= '2023':
        u = wd.heal_archive().get(wd.heal_key(m.group(2)), u)
    return u


def cite(g):
    """An outside citation for a gap, '' for KOP's own record."""
    if g.get('kop_source'):
        return ''
    if g.get('source_url'):
        url = fix_url(g['source_url'])
        label = g.get('source_label') or 'source'
        if url != g['source_url'] and 'heal-online' in url:
            label = re.sub(r'\s*\(archived [^)]*\)', '', label)   # the capture date it named is not the one linked now
        return f' ([{label}]({url}))'
    ct = (g.get('detail') or {}).get('cite_text')
    return f' ({ct})' if ct else ''


def mdy(d):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', d or '')
    return f'{int(m.group(2))}/{int(m.group(3))}/{m.group(1)}' if m else (d or '')


def long_date(d):
    m = re.match(r'(\d{4})-(\d{2})-(\d{2})', d or '')
    if not m:
        return d
    dt = date(int(m.group(1)), int(m.group(2)), int(m.group(3)))
    return f'{dt.strftime("%B")} {dt.day}, {dt.year}'


def section(lines, *names):
    for n in names:
        s = wd.find(lines, n)
        if s:
            return lines[s[0]].strip()
    return ''


def heading_text(h):
    return re.sub(r'[#*]+', '', h).strip()


def staff_line(g, program, closed):
    d = g.get('detail') or {}
    name = re.sub(r'\s*\(.*\)$', '', g['text']).strip()
    role = re.sub(r'\s*\((\d{4}).*$', '', (d.get('role') or '').strip()) or 'staff member'
    years = re.search(r'\((\d{4}(?:-\d{4})?)', d.get('role') or '')
    verb = 'was' if closed or 'left' in (d.get('role') or '') or years else 'has been'
    art = '' if re.match(r'(?i)(the|a|an)\b', role) else ('an ' if role[:1].lower() in 'aeiou' else 'the ')
    s = f'**{name}** {verb} {art}{role} of {program}' + (f' in {years.group(1)}' if years else '') + cite(g) + '.'
    s = s.replace(').', ').').replace(' .', '.')
    others = []
    for r in (d.get('other_roles') or [])[:4]:
        role2, _, place = r.rpartition(', ')
        if not role2:
            continue
        place = re.sub(r'\s*\(\d{4}.*$', '', place).strip()
        others.append(f'{role2} of {wiki_link(place, program)}')
    if others:
        s += f' {name.split()[-1]} was also ' + (', '.join(others[:-1]) + ' and ' + others[-1] if len(others) > 1 else others[0]) + '.'
    return s


def main(ids):
    for i in ids:
        folder = os.path.join(DRAFTS, str(i))
        entry_md = open(os.path.join(folder, 'entry.md'), encoding='utf-8').read().replace('\r\n', '\n')
        lines = entry_md.split('\n')
        gaps = json.load(open(os.path.join(folder, 'gaps.json'), encoding='utf-8'))
        program = gaps['entry']['program_name'].strip()
        rec = gaps.get('record', {})
        closed = rec.get('status') == 'Closed' or not re.search(r'present', gaps['entry'].get('years') or '', re.I) \
            or any(g['kind'] == 'closure' and not g['conflict'] for g in gaps['gaps'])
        hist = section(lines, 'History and Background Information', 'History')
        staff_sec = section(lines, 'Founders and Notable Staff', 'Notable Staff', 'Staff')
        abuse_sec = section(lines, 'Abuse Allegations, Deaths, and Lawsuits', 'Abuse Allegations, Lawsuits, and Death',
                            'Abuse Allegations', 'Lawsuits', 'Deaths')
        media_sec = section(lines, 'Related Media', 'In the Media')
        ops, model, n = [], [], 0

        def op(sec, text, gids, kop=False, **kw):
            nonlocal n
            n += 1
            o = {'id': f'c{n}', 'by': 'script', 'op': 'append_to_section', 'section': heading_text(sec), 'text': text,
                 'gids': gids, 'verdict': 'ok', 'note': 'Written by script from the gap.'}
            if kop:
                o['kop_record'] = True
            o.update(kw)
            ops.append(o)

        live = [g for g in gaps['gaps'] if not g.get('conflict') and not g.get('needs_source')]
        # History: names, operator, closure.
        hist_lines, hist_gids, hist_kop = [], [], True
        for g in live:
            d = g.get('detail') or {}
            if g['kind'] == 'name':
                names = d.get('name', '')
                hist_lines.append(f'{program} later operated as {wiki_link(names, program)}.' if d.get('how') == 'later'
                                  else f'{program} has also been known as {names}.' if d.get('how') == 'also'
                                  else f'{program} was formerly called {names}.')
            elif g['kind'] == 'operator':
                hist_lines.append(f'{program} {"was" if closed else "is"} operated by {wiki_link(d.get("operator", ""), program)}.')
            elif g['kind'] == 'closure':
                hist_lines.append(f'{program} closed in {d.get("end_year")}{cite(g)}.')
                start = (gaps['entry'].get('years') or '').split('-')[0]
                if start:
                    ops.append({'id': 'c0', 'by': 'script', 'op': 'set_header_years', 'years': f'{start}-{d.get("end_year")}',
                                'gids': [g['gid']], 'verdict': 'ok', 'note': 'Closing year from the record.'})
            else:
                continue
            hist_gids.append(g['gid'])
            hist_kop = hist_kop and bool(g.get('kop_source'))
        if hist_lines and hist:
            # The program's name once, then "It".
            hist_lines = [hist_lines[0]] + [re.sub('^' + re.escape(program) + r'\b', 'It', l) for l in hist_lines[1:]]
            op(hist, ' '.join(hist_lines), hist_gids, kop=hist_kop)
        # Staff.
        for g in [g for g in live if g['kind'] in ('staff', 'staff_other')][:25]:
            if staff_sec:
                op(staff_sec, staff_line(g, program, closed), [g['gid']], kop=bool(g.get('kop_source')))
        # Findings, under one subsection.
        finds = sorted([g for g in live if g['kind'] in ('finding', 'incident')], key=lambda g: g.get('date') or '')
        if finds and abuse_sec:
            st = STATES.get((re.search(r', ([A-Z]{2})\b', gaps['entry'].get('place') or '') or [None, ''])[1], '')
            body = []
            for g in finds:
                d = g.get('detail') or {}
                ex = re.sub(r'\s+', ' ', d.get('excerpt') or g['text'].split(': ', 1)[-1]).strip().rstrip('.').replace('"', "'")
                who = f'{st} inspectors' if st else 'state inspectors'
                body.append(f'In a report dated {long_date(g.get("date"))}, {who} found: "{ex}."{cite(g)}')
            op(abuse_sec, '### **State Inspection Findings**\n\n' + '\n\n'.join(body), [g['gid'] for g in finds])
        # News: every article in Related Media; event news also goes to the models.
        news = sorted([g for g in live if g['kind'] == 'news'], key=lambda g: g.get('date') or '')
        if news and media_sec:
            op(media_sec, '\n\n'.join(f'[{g["text"]}]({fix_url(g["source_url"])}) ({g.get("source_label") or "news"}, {mdy(g.get("date"))})'
                                      for g in news), [g['gid'] for g in news])
        for g in live:
            d = g.get('detail') or {}
            if g['kind'] in ('lawsuit', 'death') or (g['kind'] == 'news' and d.get('newer_than_entry') and d.get('type') in MODEL_NEWS):
                model.append(g)
        json.dump({'entry_id': i, 'ops': ops}, open(os.path.join(folder, 'ops.json'), 'w', encoding='utf-8', newline='\n'),
                  ensure_ascii=False, indent=1)
        json.dump({'entry_id': i, 'program': program, 'closed': closed, 'gaps': model},
                  open(os.path.join(folder, 'model-gaps.json'), 'w', encoding='utf-8', newline='\n'), ensure_ascii=False, indent=1)
        # The excerpt models read instead of the whole entry: every heading, and the sections they write into.
        keep = set()
        for name in (hist, abuse_sec):
            s = wd.find(lines, heading_text(name)) if name else None
            if s:
                keep.update(range(s[0], s[1]))
        ex = [l for k, l in enumerate(lines) if k in keep or re.match(r'\s*#{1,6}\s', l)]
        open(os.path.join(folder, 'excerpt.md'), 'w', encoding='utf-8', newline='\n').write('\n'.join(ex) + '\n')
        print(f'{i} {program}: {len(ops)} script ops, {len(model)} gaps for the models, closed={closed}')


if __name__ == '__main__':
    main([int(x) for x in sys.argv[1:]])
