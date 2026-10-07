-- Legislation status update researched 2026-10-06 (Claude), from the legislatures' own pages:
--   MI HB 5514: legislature.mi.gov bill page + enrolled text (PA 111 of 2026)
--   ID HB 723:  legislature.idaho.gov bill page, bill text, statement of purpose (Session Law ch. 139)
--   CA SB 1190 / AB 1688: Governor's legislative update 2026-09-30 (both signed), chapters per CalMatters Digital Democracy
-- Positions left untouched on existing records.
START TRANSACTION;

-- id 19: Michigan HB 5514, signed 10/5/26
UPDATE legislation SET
  bill_title = 'Children: other; prohibition of use of certain restraint while transporting youths to certain youth residential treatment programs (Preventing Restraints in Youth Transport Act)',
  sponsors = '["Cam Cavitt (primary)","Julie Brixie","Mai Xiong","Denise Mentzer","John Roth","Kathy Schmaltz"]',
  status = 'enacted',
  last_action_date = '2026-10-05',
  last_action_text = 'Approved by Governor Whitmer 10/5/26 with immediate effect; Public Act 111 of 2026. Passed House 104-1 (4/28/26) and Senate 35-1 (10/1/26).',
  summary = 'The Preventing Restraints in Youth Transport Act bars youth transportation companies (businesses that specialize in taking youth to residential programs) from using blindfolds, hoods, or other visual impairment; handcuffs, chains, irons, straitjackets, or other mechanical restraints; and holds or other physical force on a youth whose final destination is a youth residential treatment program. Physical restraint is allowed only when staff are trained in it, there is a substantial likelihood of imminent serious physical harm to the youth or others, no less restrictive alternative will work, and only for as long as that risk lasts.\n\nThe act also bars these companies from picking up a youth between 9 p.m. and 6 a.m. (transporting during those hours is still allowed).\n\n"Youth residential treatment program" is defined broadly: wilderness and outdoor programs, boot camps, therapeutic and education boarding schools, behavior modification programs, residential treatment centers, qualified residential treatment programs (QRTPs), psychiatric residential treatment facilities, group homes, intermediate care facilities for individuals with intellectual disabilities, and residential alternatives to incarceration, when they serve youth with emotional, behavioral, mental health, substance use, or developmental disabilities. Licensed hospitals and foster family homes are excluded.\n\nViolations carry a civil fine of up to $1,000, collected by the county prosecutor or the Attorney General; an action may be filed up to 10 years after the violation. Part of a Michigan package on abusive youth transport, publicly backed by Paris Hilton.',
  full_text_url = 'https://legislature.mi.gov/documents/2025-2026/billenrolled/House/htm/2026-HNB-5514.htm',
  official_url = 'https://legislature.mi.gov/Bills/Bill?ObjectName=2026-HB-5514',
  facilities_affected = '["Private youth transport/escort companies","Wilderness programs","Therapeutic boarding schools","Residential treatment centers","Qualified residential treatment programs (QRTPs)","Psychiatric residential treatment facilities","Group homes"]',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Status updated to enacted (PA 111 of 2026); summary rewritten from the enrolled text.')
WHERE id = 19 AND bill_number = 'HB 5514';

-- id 1: California SB 1190, signed 9/30/26
UPDATE legislation SET
  status = 'enacted',
  last_action_date = '2026-09-30',
  last_action_text = 'Approved by Governor Newsom 9/30/26; chaptered by Secretary of State, Chapter 1015, Statutes of 2026.',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Status updated to enacted (signed 9/30/26, Ch. 1015).')
WHERE id = 1 AND bill_number = 'SB 1190';

-- id 20: California AB 1688, signed 9/30/26
UPDATE legislation SET
  status = 'enacted',
  last_action_date = '2026-09-30',
  last_action_text = 'Approved by Governor Newsom 9/30/26; chaptered by Secretary of State, Chapter 888, Statutes of 2026.',
  summary = 'When a county welfare agency substantiates a report of abuse or neglect of a child in foster care, congregate care, or another out-of-home placement, or removes a child from such a placement, it must notify the attorney representing the child''s parent or guardian in dependency court (within 36 hours), and the attorneys for other children in the same placement. For an Indian child, the child''s tribe must also be notified. The notice leaves out the identity of the person who made the report and other confidential details. Does not apply to a parent whose parental rights have been terminated.',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Status updated to enacted (signed 9/30/26, Ch. 888); summary updated to the chaptered digest.')
WHERE id = 20 AND bill_number = 'AB 1688';

-- New: Idaho HB 723, signed 3/26/26, effective 7/1/26
INSERT INTO legislation (bill_number, bill_title, jurisdiction, chamber, session_year, bill_type, sponsors,
  status, introduced_date, last_action_date, last_action_text, subject_tags, summary, full_text_url,
  official_url, position, facilities_affected, tags, publication_status, submitted_by, reviewer_notes, published_at)
SELECT 'HB 723',
  'Child care licensing: quality of care oversight, service planning, youth bill of rights, and critical incident reporting in children''s residential care facilities',
  'Idaho', 'house', '2026', 'HB',
  '["House Health and Welfare Committee","Marco Erickson (contact)"]',
  'enacted', '2026-02-18', '2026-03-26',
  'Signed by Governor Little 3/26/26; Session Law Chapter 139, effective July 1, 2026. Passed House 42-25-3 (3/3/26) and Senate 32-3 (3/18/26).',
  '["youth bill of rights","residential treatment facilities","licensing","inspections","critical incident reporting","restraint and seclusion","child abuse hotline"]',
  'Adds four sections to Idaho''s child care licensing law (Title 39, Chapter 12) for licensed children''s residential care facilities.\n\nQuality of care oversight (39-1210A): the Department of Health and Welfare must make an annual unannounced inspection of every licensed residential facility, plus more after substantiated complaints, serious incidents, prior deficiencies or patterns of noncompliance, and must hold confidential in-person interviews with residents and staff chosen by the department alone (facility staff may not pick who is interviewed). Violations found are grounds for corrective plans, sanctions, or referral to the child abuse hotline or police.\n\nService planning (39-1210B): facilities must document a child''s condition and diagnosis at intake, write an individualized service plan within 30 days, update it every 90 days, and write a discharge summary within 7 days of discharge.\n\nYouth bill of rights (39-1225): the department must publish rights covering safety, medical and behavioral health care, contact with family, lawyers and advocates, privacy, education and recreation, freedom from abuse, neglect and unreasonable restraint, and grievances without retaliation. Facilities must post it, give and explain it to each child and parent at admission, and let children reach the child abuse hotline privately and unmonitored. Retaliation is a licensing violation.\n\nCritical incidents (39-1226): facilities must report to the department and to the parent, guardian or placing agency by the next business day any death, suicide attempt or serious self-harm, restraint, seclusion or emergency safety intervention, denied or delayed medical care, hospital trip, abuse allegation, arrest, runaway, or similar event, and keep an incident log for inspectors. The department must review the reports and publish substantiated findings and enforcement actions.',
  'https://legislature.idaho.gov/wp-content/uploads/sessioninfo/2026/legislation/H0723.pdf',
  'https://legislature.idaho.gov/sessioninfo/2026/legislation/H0723/',
  'support',
  '["Children''s residential care facilities","Residential treatment centers","Group homes"]',
  '[]', 'published', 'admin',
  '[claude 2026-10-06] Added from https://legislature.idaho.gov/sessioninfo/2026/legislation/H0723/ (bill text + statement of purpose).',
  NOW()
FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM legislation WHERE jurisdiction = 'Idaho' AND bill_number = 'HB 723');

-- Full-page check 2026-10-06: every other published bill. Past-session outcomes were all correct;
-- these rows were stale, had wrong dates, or lacked the key date.

-- id 42: Missouri HB 557 (2021), signed 7/14/21, emergency clause
UPDATE legislation SET
  status = 'enacted',
  last_action_date = '2021-07-14',
  last_action_text = 'Signed by Governor Parson 7/14/21 (SS HCS HBs 557 & 560); emergency clause, in effect on signing.',
  full_text_url = 'https://documents.house.mo.gov/billtracking/bills211/sumpdf/HB0557T.pdf',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Was "Delivered to Governor"; signed 7/14/21 per truly agreed summary + News Tribune 7/15/21.')
WHERE id = 42 AND bill_number = 'HB 557';

-- id 21: Ohio HB 811, still in committee; introduced date was a news story's date
UPDATE legislation SET
  status = 'in_committee',
  introduced_date = '2026-04-07',
  last_action_date = '2026-05-13',
  last_action_text = 'Referred to House Children and Human Services Committee; no hearings held yet.',
  full_text_url = 'https://search-prod.lis.state.oh.us/api/v2/general_assembly_136/legislation/hb811/00_IN/pdf/',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Introduced 4/7/26 (not 4/22); committee referral 5/13/26.')
WHERE id = 21 AND bill_number = 'HB 811';

-- id 10: Oregon SB 136 (2025), add signing date
UPDATE legislation SET
  last_action_date = '2025-07-31',
  last_action_text = 'Signed by Governor Kotek 7/31/25; Chapter 621, 2025 Oregon Laws; effective January 1, 2026.'
WHERE id = 10 AND bill_number = 'SB 136';

-- id 2: Oregon HB 4042 (2026), add sine die date
UPDATE legislation SET last_action_date = '2026-03-06'
WHERE id = 2 AND bill_number = 'HB 4042' AND last_action_date IS NULL;

-- id 23: Utah SB 297 (2025), the stored date was the effective date
UPDATE legislation SET
  status = 'enacted',
  last_action_date = '2025-03-19',
  last_action_text = 'Signed by the Governor 3/19/25; effective July 1, 2025.'
WHERE id = 23 AND bill_number = 'SB 297';

-- id 22: Utah SB 127 (2021)
UPDATE legislation SET
  last_action_text = 'Signed by the Governor 3/22/21; Laws of Utah 2021, Chapter 400; effective May 5, 2021.'
WHERE id = 22 AND bill_number = 'SB 127';

-- id 28: "a 822 / Relating to physical interventions" = Oregon SB 822 (2023), the only Oregon bill with that clause
UPDATE legislation SET
  bill_number = 'SB 822',
  bill_title = 'Relating to physical interventions of persons under 21 years of age; declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2023',
  bill_type = 'SB',
  sponsors = '["Sara Gelser Blouin (primary)"]',
  status = 'dead',
  introduced_date = '2023-01-31',
  last_action_date = '2023-06-25',
  last_action_text = 'In Senate Committee on Education upon adjournment of the 2023 session; never received a hearing.',
  subject_tags = '["restraint and seclusion","schools","students"]',
  summary = 'Would have banned the seclusion of students in public education programs, changed the definitions of "seclusion" and "involuntary seclusion" for certain children, and clarified the reporting and training rules for restraint and seclusion in public education programs. Referred to the Senate Education Committee, it died there without a hearing when the 2023 session ended.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2023R1/Downloads/MeasureDocument/SB822/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2023R1/Measures/Overview/SB822',
  reviewer_notes = CONCAT(COALESCE(reviewer_notes, ''), '\n[claude 2026-10-06] Identified as Oregon SB 822 (2023): the only Oregon bill 2007-2025 whose relating clause has "physical interventions".')
WHERE id = 28 AND bill_number = 'a 822';

SELECT id, bill_number, jurisdiction, status, last_action_date FROM legislation
WHERE id IN (1, 2, 10, 19, 20, 21, 22, 23, 28, 42) OR (jurisdiction = 'Idaho' AND bill_number = 'HB 723');

COMMIT;
