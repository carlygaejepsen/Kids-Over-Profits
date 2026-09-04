-- Backfill legislation records researched 2026-09-03 (Claude).
-- Verified against LegiScan / OLIS / state legislature sites; positions left untouched.
-- Run in phpMyAdmin against the production database. Review, then COMMIT.
START TRANSACTION;

-- id 1: SB 1190 (California, 2025-2026) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1190',
  bill_title = 'Child welfare: transport escort services (Safe Passage for Youth Act)',
  jurisdiction = 'California',
  chamber = 'senate',
  session_year = '2025-2026',
  bill_type = 'SB',
  sponsors = '["Shannon Grove (primary)","Krell (coauthor, Assembly)","Lee (coauthor, Assembly)"]',
  status = 'passed_senate',
  introduced_date = '2026-02-19',
  last_action_date = '2026-08-30',
  last_action_text = 'Senate concurred in Assembly amendments (39-0); enrolled 8/30/26 and sent to the Governor.',
  subject_tags = '["youth transport","transport escort services","child welfare","out-of-state placement","licensing","restraint"]',
  summary = 'The Safe Passage for Youth Act expands California''s regulation of transport escort services that accompany or transport minors to residential, behavioral, or treatment programs, whether the destination is in-state or out-of-state. It requires trustline registration and safety training for transporters, written parental consent, restricts use of restraint to imminent-harm situations, and gives the Attorney General enforcement authority against repeated or egregious violators.',
  full_text_url = 'https://leginfo.legislature.ca.gov/faces/billTextClient.xhtml?bill_id=202520260SB1190',
  official_url = 'https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202520260SB1190',
  facilities_affected = '["Youth Transport Services","Residential Treatment Facilities","Behavioral Health Programs"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202520260SB1190'
WHERE id = 1;

-- id 2: HB 4042 (Oregon, 2026) [high confidence]
UPDATE legislation SET
  bill_number = 'HB 4042',
  bill_title = 'Relating to the welfare of young people.',
  jurisdiction = 'Oregon',
  chamber = 'house',
  session_year = '2026',
  bill_type = 'HB',
  sponsors = '["Jason Kropf (at request of House Interim Committee on Judiciary)"]',
  status = 'dead',
  introduced_date = NULL,
  last_action_date = NULL,
  last_action_text = 'Passed the House (B-Engrossed); at the Senate President''s desk upon sine die adjournment of the 2026 session and did not pass.',
  subject_tags = '["child-caring agency licensing","restraint provisions","placement limitations","DHS enforcement","children in care"]',
  summary = 'HB 4042 would have expanded the types of adverse licensing actions the Department of Human Services may take against child-caring agencies following certain findings, while also modifying restraint provisions for children in care and creating exceptions to placement limitations. It passed the House during Oregon''s 2026 short session but died at the Senate President''s desk when the session adjourned.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Downloads/MeasureDocument/HB4042/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/HB4042',
  facilities_affected = '["Child-caring agencies","Congregate care residential settings"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/HB4042'
WHERE id = 2;

-- id 3: SB 710 (Oregon, 2021) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 710',
  bill_title = 'Relating to children in care; creating new provisions; amending ORS 418.205, 418.257 and 418.259; and declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2021',
  bill_type = 'SB',
  sponsors = '["Sara Gelser (primary)","James Manning Jr.","Lew Frederick"]',
  status = 'enacted',
  introduced_date = NULL,
  last_action_date = '2021-09-01',
  last_action_text = 'Enacted as Chapter 672, Oregon Laws 2021; effective September 1, 2021.',
  subject_tags = '["restraint","involuntary seclusion","children in care","reporting requirements","child-caring agencies"]',
  summary = 'Defines and restricts prohibited restraint and seclusion practices (chemical, mechanical, prone, supine, and others) used on children in Oregon''s care in foster homes, child-caring agencies, and developmental disabilities residential facilities. Requires programs to establish incident procedures whenever restraint or seclusion is used, and mandates reportable-injury and quarterly reporting to DHS.',
  full_text_url = 'https://www.oregonlegislature.gov/bills_laws/lawsstatutes/2021orlaw0672.pdf',
  official_url = 'https://olis.oregonlegislature.gov/liz/2021R1/Measures/Overview/SB710',
  facilities_affected = '["Child-Caring Agencies","Foster Homes","Developmental Disabilities Residential Facilities"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2021R1/Measures/Overview/SB710'
WHERE id = 3;

-- id 4: SB 707 (Oregon, 2021) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 707',
  bill_title = 'Relating to notices to children placed in out-of-state residential facilities; and declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2021',
  bill_type = 'SB',
  sponsors = '["Sara Gelser (primary)"]',
  status = 'enacted',
  introduced_date = NULL,
  last_action_date = '2021-07-14',
  last_action_text = 'Approved by the Governor; Chapter 492, Oregon Laws 2021; effective on passage, July 14, 2021.',
  subject_tags = '["out-of-state placement","notice requirements","records retention","restraint records","child welfare"]',
  summary = 'Required Oregon DHS to notify, by October 1, 2021, every child or ward it had placed in an out-of-state residential facility between January 1, 2016 and June 30, 2020 of their right to seek civil remedies, along with facility and parent-company identifying information. DHS was also required to proactively obtain and retain for 20 years each child''s facility records, including incident, injury, abuse-allegation, and restraint/seclusion records, and report progress to the Legislature.',
  full_text_url = 'https://www.oregonlegislature.gov/bills_laws/lawsstatutes/2021orlaw0492.pdf',
  official_url = 'https://olis.oregonlegislature.gov/liz/2021R1/Measures/Overview/SB707',
  facilities_affected = '["Out-of-State Residential Facilities"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2021R1/Measures/Overview/SB707'
WHERE id = 4;

-- id 5: AB 643 (Wisconsin, 2019-2020) [medium confidence]
UPDATE legislation SET
  bill_number = 'AB 643',
  bill_title = 'Relating to: qualified residential treatment programs, providing an exemption from rule-making procedures, and granting rule-making authority.',
  jurisdiction = 'Wisconsin',
  chamber = 'assembly',
  session_year = '2019-2020',
  bill_type = 'AB',
  sponsors = '["Joan Ballweg (primary)","Jill Billings","David Bowen","Deb Considine"]',
  status = 'dead',
  introduced_date = '2019-12-02',
  last_action_date = '2019-12-02',
  last_action_text = 'Referred to Assembly Committee on Children and Families; received no further action before the session ended.',
  subject_tags = '["qualified residential treatment program","QRTP","residential care center","group home","shelter care","child welfare licensing"]',
  summary = 'Would have authorized Wisconsin''s Department of Children and Families to certify residential care centers, group homes, or shelter care facilities as Qualified Residential Treatment Programs (QRTPs) eligible for federal Title IV-E funding, and directed DCF to promulgate implementing rules under an expedited process. The bill never passed; comparable authority was granted two years later via 2021 Wisconsin Act 42.',
  full_text_url = 'https://docs.legis.wisconsin.gov/2019/related/proposals/ab643',
  official_url = 'https://docs.legis.wisconsin.gov/2019/proposals/ab643',
  facilities_affected = '["Residential Care Centers","Group Homes","Shelter Care Facilities","Qualified Residential Treatment Programs"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://docs.legis.wisconsin.gov/2019/proposals/ab643'
WHERE id = 5;

-- id 7: SB 1534 (Oregon, 2026) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1534',
  bill_title = 'Relating to the welfare of young people; declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2026',
  bill_type = 'SB',
  sponsors = '["Senate Interim Committee on Human Services (by request)"]',
  status = 'dead',
  introduced_date = '2026-02-02',
  last_action_date = '2026-03-06',
  last_action_text = 'In Joint Committee on Ways and Means upon sine die adjournment of the 2026 short session.',
  subject_tags = '["child welfare","abuse in care","child-caring agencies","out-of-state placements","congregate care","youth residential treatment"]',
  summary = 'Would have substantially rewritten Oregon''s definition of abuse of a child in care to explicitly cover child-caring agencies, developmental disabilities residential facilities, proctor, certified, and adjudicated youth foster homes, and their staff, contractors, and volunteers, including restraint and seclusion violations, financial exploitation, and sexual abuse. Also would have reformed child-caring-agency licensing and out-of-state placement rules. Never reached a floor vote before the short session ended.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Downloads/MeasureDocument/SB1534/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/SB1534',
  facilities_affected = '["Child-caring agencies","Developmental disabilities residential facilities","Proctor foster homes","Certified foster homes","Adjudicated youth foster homes"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/SB1534'
WHERE id = 7;

-- id 8: SB 1532 (Oregon, 2026) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1532',
  bill_title = 'Relating to services for vulnerable persons; and declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2026',
  bill_type = 'SB',
  sponsors = '["Senate Interim Committee on Human Services (by request)"]',
  status = 'signed',
  introduced_date = '2026-02-02',
  last_action_date = '2026-03-10',
  last_action_text = 'Signed by Governor Kotek; Chapter 28, 2026 Oregon Laws, effective on passage.',
  subject_tags = '["vulnerable persons","residential care facilities","children in care","restraint and seclusion","developmental disabilities services","out-of-state placement"]',
  summary = 'Omnibus human-services bill: tightens DHS authority to impose license conditions on residential and long-term care facilities after immediate-jeopardy findings, requires a model consent form for in-room electronic monitoring, creates a differentiated payment rate for developmental-disability direct support professionals who live with clients, adds narrow exceptions to out-of-state child-placement limits (with a required DHS escort), and bars finding abuse of a child in care based solely on a lapsed restraint/seclusion training certification.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Downloads/MeasureDocument/SB1532/A-Engrossed',
  official_url = 'https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/SB1532',
  facilities_affected = '["Residential care and long-term care facilities","Developmental disabilities residential facilities","Child-caring agencies","Out-of-state residential placements"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2026R1/Measures/Overview/SB1532'
WHERE id = 8;

-- id 9: SB 129 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 129',
  bill_title = 'Relating to individuals with intellectual disabilities; declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Senate Interim Committee on Human Services (by request)"]',
  status = 'dead',
  introduced_date = NULL,
  last_action_date = '2025-06-27',
  last_action_text = 'In Senate Committee on Human Services upon sine die adjournment of the 2025 regular session.',
  subject_tags = '["intellectual disabilities","involuntary civil commitment","institutionalization","mental health access","disability rights"]',
  summary = 'Would have repealed Oregon statutes that allowed a court to order a person into a facility against their will solely on the basis of having an intellectual or developmental disability, along with related habilitation-detention provisions. Also would have prohibited any public body from denying mental illness services to a person on the grounds that the person also has an intellectual disability. Died in committee without a vote.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB129/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB129',
  facilities_affected = '["Residential and institutional facilities for people with intellectual or developmental disabilities","Mental health service providers"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB129'
WHERE id = 9;

-- id 10: SB 136 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 136',
  bill_title = 'Relating to developmental disabilities services providers.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Senate Interim Committee on Human Services (by request)"]',
  status = 'enacted',
  introduced_date = NULL,
  last_action_date = NULL,
  last_action_text = 'Enacted as Chapter 621, 2025 Oregon Laws.',
  subject_tags = '["developmental disabilities","service providers","licensing","IDD","provider accountability","Oregon DHS"]',
  summary = 'Authorizes Oregon DHS to revoke, suspend, or impose conditions on the license, certificate, or endorsement of an agency providing community-based services to individuals with intellectual or developmental disabilities if the agency is deemed unqualified. Specifies disqualifying conditions such as staff on the federal excluded-provider list, denying inspectors access to records or clients, tampering with records, repeated serious health and safety violations, financial fraud, or leadership with a history of license revocation or Medicaid fraud.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB136/Enrolled',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB136',
  facilities_affected = '["Community-based IDD service provider agencies","Developmental disability residential providers"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB136'
WHERE id = 10;

-- id 11: SB 989 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 989',
  bill_title = 'Relating to treatment of minor children.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Dick Anderson (primary)"]',
  status = 'dead',
  introduced_date = '2025-02-06',
  last_action_date = '2025-06-27',
  last_action_text = 'In committee upon adjournment (Senate Committee on Rules); died when the 2025 session adjourned sine die.',
  subject_tags = '["parental admission","inpatient treatment","minor consent","behavioral health","substance use disorder","DHS/OHA licensed facilities"]',
  summary = 'SB 989 would have let a parent or guardian admit a minor child, with or without the child''s consent, to an inpatient treatment facility licensed by the Oregon Health Authority or DHS for a mental, emotional, behavioral health, or substance use condition. It required an initial clinical assessment and periodic reviews to justify continued admission, barred facilities from refusing admission solely because a child withheld consent, and gave children age 14 and older a process to request review of the admission decision.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB989/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB989',
  facilities_affected = '["Inpatient mental health treatment facilities","Inpatient substance use disorder treatment programs","OHA/DHS-licensed behavioral health facilities"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB989'
WHERE id = 11;

-- id 12: SB 1069 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1069',
  bill_title = 'Relating to the regulation of human services providers; prescribing an effective date.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Sara Gelser Blouin (primary)"]',
  status = 'dead',
  introduced_date = '2025-02-25',
  last_action_date = '2025-06-27',
  last_action_text = 'Remained in Senate Committee on Human Services; died without a vote when the 2025 session adjourned sine die on June 27, 2025.',
  subject_tags = '["provider licensing","DHS applications","child-caring agencies","IDD providers","older adult care providers","regulatory oversight"]',
  summary = 'SB 1069 would have created new application requirements for any provider seeking a license, certificate, endorsement, or authorization from DHS to serve children, older adults, or individuals with intellectual/developmental disabilities, including child-caring agencies. It required DHS to notify applicants of incomplete or noncompliant applications, barred reapplication for one year after a revocation or denial, and required DHS to report to the legislature on application processing times and costs.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB1069/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1069',
  facilities_affected = '["Child-caring agencies","Residential/behavioral health providers licensed by DHS","IDD service providers","Older adult care providers"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1069'
WHERE id = 12;

-- id 13: SB 1112 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1112',
  bill_title = 'Relating to Department of Human Services admissions of children for treatment; creating new provisions; amending ORS 418.257; and declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Sara Gelser Blouin (primary)"]',
  status = 'dead',
  introduced_date = '2025-02-26',
  last_action_date = '2025-06-27',
  last_action_text = 'In Senate Committee on Human Services upon adjournment; not enacted (per LPRO 2025 Human Services Legislative Summary Report).',
  subject_tags = '["DHS child admissions","inpatient/residential treatment","medical necessity review","out-of-state placement","foster care continuity","Medicaid authorization"]',
  summary = 'SB 1112 would have restricted when DHS can admit a child in its own care or custody to inpatient or residential treatment: only after an in-person medical-necessity evaluation by a licensed health professional, only at an OHA-licensed/certified facility, only with Medicaid authorization, and generally only when the child has an ongoing foster placement to return to. It set additional conditions for out-of-state hospital admissions and clarified that such admissions do not count as a placement or change the child''s foster-care status.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB1112/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1112',
  facilities_affected = '["DHS-custody inpatient/residential treatment facilities","Out-of-state hospitals","Congregate care placements"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1112'
WHERE id = 13;

-- id 14: SB 1113 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1113',
  bill_title = 'Relating to the welfare of young people.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Sara Gelser Blouin (primary)"]',
  status = 'dead',
  introduced_date = '2025-02-27',
  last_action_date = '2025-06-27',
  last_action_text = 'In committee upon adjournment (Senate Committee on Human Services); not enacted (per LPRO 2025 Human Services Legislative Summary Report).',
  subject_tags = '["restraint and seclusion","abuse investigations","child-caring agency licensing","secure transportation","out-of-state placements","DHS enforcement"]',
  summary = 'SB 1113 would have tightened restrictions on the use of restraint and involuntary seclusion on children in care (including in public education programs and secure transportation), defined abusive restraint/seclusion, modified how DHS investigates and substantiates abuse and neglect, expanded DHS''s authority to impose civil penalties and other regulatory actions against child-caring entities, and set new limits on out-of-state placements of children in care.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Downloads/MeasureDocument/SB1113/Introduced',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1113',
  facilities_affected = '["Child-caring agencies","Secure transportation providers","Public education programs serving children in care","Out-of-state placement facilities"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/SB1113'
WHERE id = 14;

-- id 15: HB 3835 (Oregon, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'HB 3835',
  bill_title = 'Relating to the welfare of young people; declaring an emergency.',
  jurisdiction = 'Oregon',
  chamber = 'house',
  session_year = '2025',
  bill_type = 'HB',
  sponsors = '["Ed Diehl (primary)","Rob Nosse","Tom Andersen","Darin Harbick","Cyrus Javadi","Bobby Levy","Hai Pham"]',
  status = 'dead',
  introduced_date = '2025-02-27',
  last_action_date = '2025-06-27',
  last_action_text = 'In committee upon adjournment (Joint Committee on Ways and Means, after crossing over as B-Engrossed); not enacted (per LPRO 2025 Human Services Legislative Summary Report).',
  subject_tags = '["restraint and seclusion","abuse investigations","DHS regulatory authority","out-of-state placement","secure medical transport","System of Care Advisory Council"]',
  summary = 'HB 3835, requested by DHS and the System of Care Advisory Council, would have redefined what counts as abusive restraint/seclusion of a child in care, changed how DHS investigates and responds to abuse reports, modified DHS''s regulatory and enforcement authority over child-caring agencies, allowed DHS to place children with out-of-state agencies under certain circumstances, and exempted secure medical transport from some licensing rules. Disability-rights advocates opposed it as weakening child-abuse protections and oversight.',
  full_text_url = 'https://olis.oregonlegislature.gov/liz/2025r1/Downloads/MeasureDocument/HB3835/B-Engrossed',
  official_url = 'https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/HB3835',
  facilities_affected = '["Child-caring agencies","Congregate care residential settings","Secure medical transport providers","Out-of-state placement agencies"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2025R1/Measures/Overview/HB3835'
WHERE id = 15;

-- id 18: SB 1043 (California, 2023-2024) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 1043',
  bill_title = 'Accountability in Children''s Treatment Act (short-term residential therapeutic programs: restraints and seclusion)',
  jurisdiction = 'California',
  chamber = 'senate',
  session_year = '2023-2024',
  bill_type = 'SB',
  sponsors = '["Shannon Grove (primary)"]',
  status = 'enacted',
  introduced_date = '2024-02-07',
  last_action_date = '2024-09-27',
  last_action_text = 'Signed by Governor Newsom; chaptered as Chapter 628, Statutes of 2024',
  subject_tags = '["restraint and seclusion","STRTP","foster care","transparency","public dashboard"]',
  summary = 'Known as the Accountability in Children''s Treatment Act, this bipartisan bill (sponsored by Paris Hilton''s 11:11 Media Impact) requires California''s short-term residential therapeutic programs (STRTPs) to notify a child''s parent, guardian, or tribal representative whenever restraint or seclusion is used on them, and to provide written incident descriptions within seven days. It also directs the Department of Social Services to publish a public dashboard of restraint/seclusion incidents, investigations, and licensing actions starting January 1, 2026.',
  full_text_url = 'https://leginfo.legislature.ca.gov/faces/billTextClient.xhtml?bill_id=202320240SB1043',
  official_url = 'https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202320240SB1043',
  facilities_affected = '["Short-term residential therapeutic programs (STRTPs)"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202320240SB1043'
WHERE id = 18;

-- id 19: HB 5514 (Michigan, 2025-2026) [high confidence]
UPDATE legislation SET
  bill_number = 'HB 5514',
  bill_title = 'Children: other; prohibition of use of certain restraints; require certain requirements for transportation of a minor to a qualified residential treatment program.',
  jurisdiction = 'Michigan',
  chamber = 'house',
  session_year = '2025-2026',
  bill_type = 'HB',
  sponsors = '["Cam Cavitt (primary)","Kathy Schmaltz"]',
  status = 'in_committee',
  introduced_date = '2026-02-05',
  last_action_date = NULL,
  last_action_text = 'Advanced by House Families and Veterans Committee (referred for further committee consideration)',
  subject_tags = '["restraint prohibition","youth transport","qualified residential treatment program","troubled teen industry","overnight pickup ban"]',
  summary = 'Bars private transportation companies from using handcuffs, chains, blindfolds, hoods, or other physical restraints while picking up and transporting minors to qualified residential treatment programs (QRTPs), except in narrow emergency circumstances involving imminent serious physical harm with no less-restrictive alternative available. Also prohibits nighttime pickups between 9 p.m. and 6 a.m., with limited safety exceptions. Part of a Michigan legislative package targeting abusive transport practices in the troubled teen industry, publicly backed by Paris Hilton.',
  full_text_url = 'https://legiscan.com/MI/bill/HB5514/2025',
  official_url = 'https://legislature.mi.gov/Bills/Bill?ObjectName=2026-HB-5514',
  facilities_affected = '["Qualified residential treatment programs (QRTPs)","Private youth transport/escort companies"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://legislature.mi.gov/Bills/Bill?ObjectName=2026-HB-5514'
WHERE id = 19;

-- id 20: AB 1688 (California, 2025-2026) [high confidence]
UPDATE legislation SET
  bill_number = 'AB 1688',
  bill_title = 'Child abuse or neglect: reporting.',
  jurisdiction = 'California',
  chamber = 'assembly',
  session_year = '2025-2026',
  bill_type = 'AB',
  sponsors = '["Juan Carrillo (primary)","Mike Gipson"]',
  status = 'passed_senate',
  introduced_date = '2026-02-03',
  last_action_date = '2026-08-26',
  last_action_text = 'Passed Senate; enrolled and sent to Governor',
  subject_tags = '["child abuse reporting","congregate care","foster care","dependency court","attorney notification"]',
  summary = 'Requires a county welfare agency, when it substantiates a report of abuse or neglect occurring in foster care, congregate care, or other out-of-home placement, to notify the attorneys for both the child and the parents in the dependency case within a set time frame. The notice must exclude the identity of the reporting party and other confidential details while ensuring attorneys are informed of substantiated incidents affecting their clients.',
  full_text_url = 'https://leginfo.legislature.ca.gov/faces/billTextClient.xhtml?bill_id=202520260AB1688',
  official_url = 'https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202520260AB1688',
  facilities_affected = '["Foster care placements","Congregate care/group homes","Short-term residential therapeutic programs"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202520260AB1688'
WHERE id = 20;

-- id 21: HB 811 (Ohio, 2025-2026) [medium confidence]
UPDATE legislation SET
  bill_number = 'HB 811',
  bill_title = 'Regards residential facilities licensed by the Department of Behavioral Health',
  jurisdiction = 'Ohio',
  chamber = 'house',
  session_year = '2025-2026',
  bill_type = 'HB',
  sponsors = '["Crystal Lett (primary)"]',
  status = 'introduced',
  introduced_date = '2026-04-22',
  last_action_date = NULL,
  last_action_text = 'Introduced in the 136th General Assembly.',
  subject_tags = '["behavioral health","residential facility licensing","youth residential treatment","license revocation","troubled teen industry"]',
  summary = 'Would remove the discretionary authority of Ohio''s behavioral health licensing agency over youth residential treatment facilities and instead mandate state intervention, requiring the agency to suspend admissions, deny license renewals, or shut down a facility once serious violations are documented. Introduced by Rep. Crystal Lett following a Marshall Project investigation into escalating violence and staff injuries at Mohican Young Star Academy (Empowering to Elevate Academy) in Perrysville, Ohio.',
  full_text_url = '',
  official_url = 'https://www.legislature.ohio.gov/legislation/136/hb811',
  facilities_affected = '["Licensed behavioral health residential facilities","Youth residential treatment centers"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://www.legislature.ohio.gov/legislation/136/hb811'
WHERE id = 21;

-- id 23: SB 297 (Utah, 2025) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 297',
  bill_title = 'Congregate Care Amendments',
  jurisdiction = 'Utah',
  chamber = 'senate',
  session_year = '2025',
  bill_type = 'SB',
  sponsors = '["Michael K. McKell (primary)","Casey Snider (House sponsor)"]',
  status = 'enacted',
  introduced_date = NULL,
  last_action_date = '2025-07-01',
  last_action_text = 'Passed both chambers in the 2025 Utah General Session; effective July 1, 2025.',
  subject_tags = '["congregate care","troubled teen industry","youth residential treatment","ombudsman","facility licensing"]',
  summary = 'Establishes a Congregate Care Advisory Committee, creates a congregate care ombudsman, and creates the Licensed Provider Civil Money Penalty Fund. Imposes new requirements on congregate care programs, including adopting admissions criteria, maintaining a list of authorized contacts for children in crisis, notifying authorized contacts and parents when a child is in crisis, posting notice of the ombudsman, and providing a dedicated phone line to reach the ombudsman at any time.',
  full_text_url = 'https://le.utah.gov/Session/2025/bills/enrolled/SB0297.pdf',
  official_url = 'https://le.utah.gov/~2025/bills/static/SB0297.html',
  facilities_affected = '["Residential treatment centers","Group homes","Wilderness therapy programs","Congregate care youth programs"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://le.utah.gov/~2025/bills/static/SB0297.html'
WHERE id = 23;

-- id 25: SB 846 (Oregon, 2017) [high confidence]
UPDATE legislation SET
  bill_number = 'SB 846',
  bill_title = 'Relating to restraint of youth in custodial circumstances.',
  jurisdiction = 'Oregon',
  chamber = 'senate',
  session_year = '2017',
  bill_type = 'SB',
  sponsors = '[]',
  status = 'enacted',
  introduced_date = NULL,
  last_action_date = '2017-06-06',
  last_action_text = 'Approved by the Governor June 6, 2017; Chapter 257, Oregon Laws 2017; effective January 1, 2018.',
  subject_tags = '["restraint","juvenile court","youth transport","children in custody","DHS/OHA transport"]',
  summary = 'Enacted as Chapter 257, Oregon Laws 2017 (creating ORS 419A.240 and 419A.245). Prohibits instruments of physical restraint (handcuffs, chains, straitjackets, and similar) on youth during juvenile court proceedings unless the court makes written findings of an immediate and serious risk with no less restrictive alternative, and bars restraining a youth to a stationary object or another person. Also restricts restraints during DHS/OHA transportation of wards and children, requiring a documented transportation safety plan, trained staff, and prohibiting restraint use as punishment, for convenience, or as a substitute for supervision.',
  full_text_url = 'https://www.oregonlegislature.gov/bills_laws/lawsstatutes/2017orlaw0257.pdf',
  official_url = 'https://olis.oregonlegislature.gov/liz/2017R1/Measures/Overview/SB846',
  facilities_affected = '["Juvenile courts","Detention facilities","Youth correction facilities","Child-caring agencies","Foster homes","Treatment and residential facilities"]',
  reviewer_notes = '[claude 2026-09-03] Backfilled from https://olis.oregonlegislature.gov/liz/2017R1/Measures/Overview/SB846'
WHERE id = 25;

-- Unresolved records: flagged for manual review, data left untouched.
UPDATE legislation SET reviewer_notes = '[claude 2026-09-03] Could not verify this bill against any state or federal legislature; the stored bill number and title may be truncated or mis-keyed. Needs manual identification.' WHERE id = 6;
UPDATE legislation SET reviewer_notes = '[claude 2026-09-03] Could not verify this bill against any state or federal legislature; the stored bill number and title may be truncated or mis-keyed. Needs manual identification.' WHERE id = 16;
UPDATE legislation SET reviewer_notes = '[claude 2026-09-03] Could not verify this bill against any state or federal legislature; the stored bill number and title may be truncated or mis-keyed. Needs manual identification.' WHERE id = 17;
UPDATE legislation SET reviewer_notes = '[claude 2026-09-03] Could not verify this bill against any state or federal legislature; the stored bill number and title may be truncated or mis-keyed. Needs manual identification.' WHERE id = 24;
UPDATE legislation SET reviewer_notes = '[claude 2026-09-03] Could not verify this bill against any state or federal legislature; the stored bill number and title may be truncated or mis-keyed. Needs manual identification.' WHERE id = 28;

COMMIT;
