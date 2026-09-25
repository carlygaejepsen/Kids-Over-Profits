"""
Plan subfolders for the largest flat FileBird folders.

    python scripts/build-media-subfolders.py

Reads tmp/prod.sqlite (scripts/sync-prod-sqlite.py) and writes
seeds/media-subfolders.json, which kop_apply_media_subfolders() in
inc/admin.php applies on the next deploy: each entry names a parent folder, a
subfolder (reused when one of that name already exists under the parent,
created otherwise) and the attachments to move into it. Only documents sitting
directly in the parent move; anything already filed deeper is left alone, and
a document no rule places stays where it is.

The rules below are per folder, because each one is a different kind of pile:
a monthly newsletter wants years, a court docket wants filing types, an
operator wants its programs. A new subfolder needs MIN_NEW documents; an
existing one takes any number.

Also writes tmp/media-subfolders-report.md: every folder's plan and what stays.
"""

import html
import json
import os
import re
import sqlite3
import sys
from collections import OrderedDict, defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DB = os.path.join(ROOT, 'tmp', 'prod.sqlite')
OUT = os.path.join(ROOT, 'seeds', 'media-subfolders.json')
REPORT = os.path.join(ROOT, 'tmp', 'media-subfolders-report.md')
MIN_NEW = 3

# ---- shared rules -----------------------------------------------------------
# (subfolder name, regex over "title | filename", lowercased). First match wins.

NATSAP_PROFILE = ('NATSAP Profiles', r'natsap')
WOODBURY_MENTION = ('Woodbury Reports Mentions', r'woodbury')
LAWSUITS = ('Lawsuits', r'\bcomplaint\b|\blawsuit|\bsettlement\b|\bv\.? [a-z]|\bvs\.? |court filing|case-\d|\banswer\b|\bmotion to\b')
INSPECTIONS = ('Licensing & Inspections', r'inspection|violation|licen[cs]e|enforcement agreement|sexualmisconduct|\bawol\b|admission ban|\bf\d\d-\d{4,}|\bprea\b|\bcdss\b|shutdown|ytc registration')
BUSINESS = ('Business Records', r'\bfiling \d{4}|annual report|articles of (incorporation|organization)|statement of change|good standing|dissolution|delinquency notice|propublica|sec info|trademark|cc search|statutory agent|corporate network|\bgrowth\b')
ENROLLMENT = ('Enrollment Materials', r'admission|application|intake|enrollment|clothing|placement agreement|\bpoa\b|photo.release|physician|resident.profile|shopping|\bfaq\b|referral sheet|\bcosts?\b|transcript request|travel info|authorization to release|packing')
HANDBOOKS = ('Handbooks & Policies', r'handbook|manual|guidelines|glossary|dictionary|point sheet|polic(y|ies)|procedures|typical.day|resident guide')

RULES = OrderedDict()

# CEDU: its six programs first, then the kinds of record.
RULES[22] = [
    # Filed here but about other programs: left at the top to be refiled.
    (None, r'kids of greater|aspen achievement|mel blount|smokey point|king george'),
    ('Ascent Wilderness Therapy', r'\bascent\b'),
    ('Northwest Academy', r'northwest academy'),
    ('Boulder Creek Academy', r'boulder creek'),
    ('Rocky Mountain Academy', r'rocky mountain academy'),
    ('CEDU Middle School', r'middle school'),
    ('CEDU High School', r'high school|cedu ?hs\b'),
    ('State Licensing Records (CDSS)', r'cdss|public records act'),
    WOODBURY_MENTION,
    ('News Coverage', r'\bnews\b|\bsun\b|digitized|life mag|typepad|medium\.com|reddit|screencapture|experiment|closing|criminal'),
]

# Woodbury Reports: the monthly issues by year ("woodbury 0106" is January 2006).
def woodbury_year(text):
    m = re.search(r'woodbury (?:[a-z]+ )?(0[1-9]|1[0-2])(0[5-9]|1[0-4])\b', text)
    if m:
        return '20' + m.group(2)
    m = re.search(r'woodbury (?:january|february|march|april|may|june|july|august|september|october|november|december) (20\d\d)', text)
    if m:
        return m.group(1)
    return None

# NATSAP: its existing subfolders, then four new ones.
RULES[5] = [
    ('Directories', r'member list|program list|directory|attendee list'),
    ('Newsletters', r'newsletter|nwsltr|natsap news|natsap-news|we are natsap|\dq0\d|\dqnews'),
    ('News Coverage', r'woodbury|youthtoday|strugglingteens'),
    ('Strategy', r'strategic plan|stratplan|white ?paper|public.relations|toolkit|misleading study'),
    ('Conferences', r'conference|conf-brochure|sponsor'),
    ('Standards & Definitions', r'definition|ethic|principles|self-report|good practice'),
    ('Lobbying & Legislation', r'lobby|bill \d|bill-\d|hr 1738|congress|hearing|oversight|advocacy day|interstate compact|cwla'),
    ('Press Releases', r'press release'),
    ('Membership', r'application for membership|membership application|program-application|dues|invoice'),
    ('Member Program Profiles', r'natsap (19|20)\d\d|natsap profile|natsap-profile|spring ridge academy'),
]

# Doe v. Hyde at Woodstock: the docket by filing type.
RULES[7803] = [
    ('Pleadings', r'complaint|answer|summons|revise|revision|nonsuit'),
    ('Discovery & Depositions', r'deposition|interrogator|discovery|subpoena|exam |expert'),
    ('Evidence', r'evidence|med records|causation'),
    ('Appearances & Counsel', r'appearance|withdrawal|counsel'),
    ('Scheduling & Continuances', r'scheduling|continuance|caseflow|extension|status conf|hearing order'),
    ('Orders & Rulings', r'granted|overruled|order|decision|certificate'),
    ('Motions & Requests', r'motion|request|objection|permission|pseudonym|service|compliance|reply'),
]

# Youth Services International: the 1997-98 program websites, cases, press.
RULES[252] = [
    ('Program Websites 1997-1998', r'ysi website|genesis treatment|developmental behavioral'),
    ('Charles Britt Academy', r'britt'),
    ('Lawsuits', r'\bv\.? ysi\b|v ysi|whis+leblower|amended complaint'),
    ('News Coverage', r'herald|palm ?beach ?post|palmbeachpost|tampa bay times|prisonlegalnews|press release|news releases'),
    ('Investigations & Reports', r'hickey|flawed from the inception|investigation|10 year plan|what_if'),
]

# Handbooks: by who the handbook is written for.
RULES[64] = [
    ('Staff Manuals', r'staff|employee|training'),
    ('Parent & Family Handbooks', r'parent|family|introduction packet'),
    ('Student & Resident Handbooks', r'student|resident|youth handbook|patient'),
]

# Hyde / News Clippings: by decade, from the date in the title.
def clipping_decade(text):
    m = re.search(r'\b(19[5-9]\d|20[0-2]\d)\b', text)
    year = int(m.group(1)) if m else None
    if year is None:
        m = re.search(r'\b\d{1,2}-\d{1,2}-(\d{2})\b', text)
        if m:
            yy = int(m.group(1))
            year = 1900 + yy if yy > 30 else 2000 + yy
    if year is None or not re.search(r'hyde|gauld', text):
        return None
    return '%ds' % (year // 10 * 10)

RULES[7829] = [LAWSUITS, INSPECTIONS, BUSINESS]                      # Mingus Mountain Academy
RULES[4] = [WOODBURY_MENTION, NATSAP_PROFILE, LAWSUITS, INSPECTIONS,  # Discovery Ranch
            ('Reviews & Police Calls', r'yelp|police calls|elopement'),
            ('Program Materials', r'course-descriptions|outcomes|experiential|healing|expansion|info\b|employment_application|costs|fy23')]
RULES[19] = [                                                          # Hyde (existing subfolders)
    ('Fuller vs. Hyde School', r'fuller|class action|pph hyde lawsuit'),
    ('Newsletters', r'newsletter|alumni bulletin'),
    ('News Clippings', r'maine times|telegram|new school 12|open house|national tour'),
    ('Enrollment Materials', r'application|transcript request|travel info|regional contact'),
    ('Marketing', r'one pager|overview|campus map|appropriate hyde family|hydebooks|character first'),
    ('Property Records', r'property|deed'),
    BUSINESS,
]
RULES[14] = [WOODBURY_MENTION, NATSAP_PROFILE,                         # Vista
             ('Business Records', r'vistareg|registration|businesses'),
             ('Staff', r'staff'),
             HANDBOOKS]
RULES[1916] = [                                                        # Vivant
    ('Staff Profiles', r'linkedin|controller|specialist|payroll|\bhr\b|\bed\b'),
    LAWSUITS,
    ('Websites', r'website'),
    BUSINESS,
]
RULES[263] = [                                                         # Altior / The Ridge Maine
    ('Newsletters', r'treatment[ _]?times'),
    NATSAP_PROFILE,
    ENROLLMENT,
]
RULES[142] = [NATSAP_PROFILE, LAWSUITS, INSPECTIONS, ENROLLMENT,      # Diamond Ranch Academy
              ('Press', r'press release')]

# Folders whose plan is a function of the text rather than a rule list.
FUNCTIONS = {8: woodbury_year, 61: clipping_decade}


def main():
    if not os.path.exists(DB):
        sys.exit('tmp/prod.sqlite not found; run scripts/sync-prod-sqlite.py first.')
    c = sqlite3.connect(DB)
    folders = {r[0]: (html.unescape(r[1]).strip(), r[2]) for r in c.execute('SELECT id, name, parent FROM wpdl_fbv WHERE type = 0')}

    def path(i):
        out = []
        while i in folders and len(out) < 8:
            out.append(folders[i][0])
            i = folders[i][1]
        return ' / '.join(reversed(out))

    rows = c.execute(
        """SELECT af.folder_id, p.ID, p.post_title,
                  (SELECT meta_value FROM wpdl_postmeta m WHERE m.post_id = p.ID AND m.meta_key = '_wp_attached_file')
           FROM wpdl_fbv_attachment_folder af JOIN wpdl_posts p ON p.ID = af.attachment_id
           WHERE p.post_type = 'attachment'"""
    ).fetchall()
    by_folder = defaultdict(list)
    for fid, aid, title, f in rows:
        by_folder[fid].append((aid, html.unescape(title or ''), (f or '').split('/')[-1]))

    plan = []
    report = ['# Media subfolder plan', '', 'Generated by scripts/build-media-subfolders.py from tmp/prod.sqlite.', '']
    for parent in list(RULES.keys()) + list(FUNCTIONS.keys()):
        if parent not in folders:
            continue
        existing = {folders[i][0].lower(): folders[i][0] for i in folders if folders[i][1] == parent}
        docs = by_folder.get(parent, [])
        # A PDF's hidden preview image ("<name>-pdf.jpg") travels with it.
        previews = {}
        for aid, title, f in docs:
            m = re.match(r'(.*)-pdf(-\d+x\d+)?\.(jpg|jpeg|png)$', f, re.I)
            if m:
                previews.setdefault((m.group(1) + '.pdf').lower(), []).append(aid)
        preview_ids = {a for ids in previews.values() for a in ids}

        groups = OrderedDict()
        stays = []
        for aid, title, f in docs:
            if aid in preview_ids:
                continue
            text = (title + ' | ' + f).lower().replace('_', ' ')
            name = None
            if parent in FUNCTIONS:
                name = FUNCTIONS[parent](text)
            else:
                for sub, rx in RULES[parent]:
                    if re.search(rx, text):
                        name = sub   # None: a rule that keeps the document where it is
                        break
            if name is None:
                stays.append(title)
                continue
            name = existing.get(name.lower(), name)
            groups.setdefault(name, []).append((aid, title, f))

        report += ['## %s (folder %d, %d documents at its top level)' % (path(parent), parent, len(docs) - len(preview_ids)), '']
        for name, items in groups.items():
            is_new = name.lower() not in existing
            if is_new and len(items) < MIN_NEW:
                stays += [t for _, t, _ in items]
                continue
            ids = []
            for aid, title, f in items:
                ids.append(aid)
                ids += previews.get(f.lower(), [])
            plan.append({'parent': parent, 'name': name, 'attachments': sorted(set(ids))})
            report.append('- **%s**%s: %d  (%s)' % (name, ' (new)' if is_new else '', len(items),
                                                   '; '.join(sorted({t[:40] for _, t, _ in items}))[:300]))
        report.append('- stays at the top: %d  (%s)' % (len(stays), '; '.join(sorted(set(t[:40] for t in stays)))[:400]))
        report.append('')

    with open(OUT, 'w', encoding='utf-8', newline='\n') as fh:
        json.dump({'_comment': 'Generated by scripts/build-media-subfolders.py; applied by kop_apply_media_subfolders() in inc/admin.php.',
                   'folders': plan}, fh, indent=1, ensure_ascii=False)
        fh.write('\n')
    os.makedirs(os.path.dirname(REPORT), exist_ok=True)
    with open(REPORT, 'w', encoding='utf-8', newline='\n') as fh:
        fh.write('\n'.join(report) + '\n')
    moved = sum(len(e['attachments']) for e in plan)
    print('%d subfolders, %d attachments to move; report: tmp/media-subfolders-report.md' % (len(plan), moved))


if __name__ == '__main__':
    main()
