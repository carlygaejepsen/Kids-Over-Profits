<?php
/**
 * Offline check of the inspection highlights parser (inc/inspection-highlights.php,
 * docs/FIX-PLAN-2026-09.md item 14).
 *
 *   php.exe -n -d extension_dir=<php>/ext -d extension=mbstring -d extension=pdo_sqlite -d memory_limit=2048M \
 *       scripts/test-inspection-highlights.php [--db=tmp/prod.sqlite] [--report=<file.md>] [--samples=8]
 *
 * Part 1 runs fixed cases through the rules and needs no database. Part 2
 * (when the SQLite mirror from scripts/sync-prod-sqlite.py is present) runs
 * the scanner over every supported report as a dry run, then into a scratch
 * copy of the two highlight tables to check that a second run changes nothing
 * and that a reviewed row survives. The mirror itself is opened read-only.
 * --report writes the distribution and the top candidates to a file for a
 * person to read.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

$args = getopt('', array('db::', 'report::', 'samples::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$report_path = $args['report'] ?? '';
$samples = (int) ($args['samples'] ?? 8);

$failures = 0;
$checks = 0;
function check($ok, $label) {
    global $failures, $checks;
    $checks++;
    if (!$ok) { $failures++; echo "FAIL  $label\n"; }
}

// ---------------------------------------------------------------------------
// Part 1: the rules
// ---------------------------------------------------------------------------

$sentence_cases = array(
    // sentence, categories expected (exactly)
    array('A child in care died after being restrained by two staff.', array('death')),
    array('No deaths or serious injuries were reported during the review period.', array()),
    array('The child was not hospitalized and no injuries were found.', array()),
    array('Staff punched a resident in the face during an argument.', array('physical_abuse')),
    array('Staff restrained the child in a prone hold, resulting in a fractured wrist.', array('restraint_injury')),
    array('Staff restrained the child for ten minutes.', array()),
    array('The child absconded from the facility and police were called.', array('missing', 'police')),
    array('Failure to supervise could result in serious injury or death.', array()),
    array('The operation must report a death within 24 hours.', array()),
    array('Staff engaged in a sexual relationship with a 16-year-old resident.', array('sexual_abuse')),
    array('The resident denied any sexual contact with staff.', array()),
    array('The child was transported to the hospital by ambulance after swallowing batteries.', array('self_harm', 'hospitalization')),
    array('Staff failed to seek medical treatment for a child with a broken arm for three days.', array('medical_neglect')),
    array('The deadline for the deadbolt repair was extended.', array()),
    array('Dr. Smith reviewed the file. The child ran away on 3/4/2024.', array('missing')),
    // Noise the first dry run against the mirror turned up.
    array('LPA met with Paige Woodard, Deputy Director, and discussed the above allegation.', array()),
    array('LPA observed sufficient food supply, including fresh fruits, can goods, and staples.', array()),
    array('Missing from policy', array()),
    array('LPA reviewed the Stockton Police Department report and facility records.', array()),
    array('Law enforcement was called to the facility and arrested S1.', array('police')),
    array('S1 failed to provide supervision, protection, and care of the clients.', array()),
    array('C1 told S1 that he hoped she and her grandson died.', array()),
    array('Two children in care were missing from the operation for 15 minutes.', array('missing')),
    array('The caregiver signature was missing on two medication logs.', array()),
    array('A child in care was reported missing at 3 AM.', array('missing')),
    // Child on child is not queued as abuse, except a sexual assault (owner rule, 2026-09-28); what an adult did is.
    array('Due to staff not being aware, one child was put in a choke hold by another child.', array()),
    array('A child inappropriately touched another child by grabbing them in the private area.', array()),
    array('C1 engaged in sexual intercourse with C2 in the facility bathroom.', array()),
    array('Inappropriate sexual contact occurred between two residents.', array()),
    array('Staff failed to stop a peer-on-peer assault in the day room.', array()),
    array('Two children were able to engage in consensual inappropriate sexual contact with each other.', array()),
    array('CCL received an allegation that Client #1 (C1) was sexually assaulted by Client #2 while in care.', array('sexual_abuse')),
    array('Child 1 (C1) and Child (C2) engaged in inappropriate sexual behaviors while overnight staff slept.', array()),
    array('Staff did not follow the plan, resulting in Client #2 (C2) and Client #3 (C3) physically assaulting (C1).', array()),
    array('Staff failed to intervene when C1 hit C2.', array()),
    array('Residents and staff reported multiple incidents where the child hit younger peers.', array()),
    array('S1 physically assaulted C1 and C2.', array('physical_abuse')),
    array('CCL received an allegation that Client #1 (C1) (see LIC811, dated 12/16/2021) was sexually assaulted by Client #2 while in care.', array('sexual_abuse')),
    array('Staff failed to separate two children after a child was hit twice by another child.', array()),
    array('The operation failed to report sexual abuse against a child in care by another resident.', array('sexual_abuse')),
    array('All residents interviewed stated they have either been hit by the staff or witnessed the staff hit another resident.', array('physical_abuse')),
    // Utah and Arizona wording.
    array('The information gathered substantiated that an incident of sexual activity between three clients was reported late.', array()),
    array('During this time, two clients engaged in sexual activity.', array()),
    array('R1 reported to a parent that another resident sexually assaulted R1.', array('sexual_abuse')),
    array('Video evidence showed clients overwhelming personnel, engaging in physical assaults against staff, and seizing staff keys.', array()),
    array('A staff member bit a client during a physical restraint incident; the client incurred an injury as a result.', array('physical_abuse', 'restraint_injury')),
    array('A review of facility documentation revealed an "Incident, Accident or Death Report" was created on March 14, 2024.', array()),
    array('This deficient practice can lead to patient death or harm in an emergency.', array()),
    array('A review of an incident report revealed E1 punched R1 in the face.', array('physical_abuse')),
    array('The Compliance Officer observed bird feathers and one deceased bird on the ground in the back yard.', array()),
    array('Failure to have anti-ligature curtain rods presents a health and safety risk for patients including possible death.', array()),
    array('The assessment listed Homicide risk: low and suicidal ideations: passive.', array()),
    // Owner rule, 2026-10-05: a notice sent late is paperwork, whatever it was about.
    array('The administrator failed to provide written notification to the Department of a resident\'s death within one working day.', array()),
    array('The complaint alleged a resident had an unauthorized absence and later passed away.', array('death', 'missing')),
    // Training and screenings are not deaths; self-harm is not a suicide attempt (owner, 2026-09-30).
    array('One out of three child records reviewed did not have a completed suicide screening completed within 90 days of the last completed suicide screening.', array()),
    array('Documetnation of a completed suicide screening at admission could not be found in the child\'s file.', array()),
    array('A child in care died by suicide in the facility bathroom.', array('death')),
    array('The youth committed suicide while on the unit.', array('death')),
    array('A child in care cut herself with a razor.', array('self_harm')),
    array('Client scratched herself while trying to attack staff.', array()),
    array('A child was able to self-harm with razors.', array('self_harm')),
    array('Subsequently, a child in care used the ribbon to attempt to hang themselves.', array('suicide_attempt')),
    array('A child who attempted suicide was not closely supervised before a suicide assessment was completed.', array('suicide_attempt')),
    array('Specific actions required in the event that there is a suicide attempt are not documented on the suicide policy.', array()),
    array('The child swallowed a zipper and was taken to the emergency room for X-rays.', array('self_harm', 'hospitalization')),
);
// Whole sentences the scorer sets aside: quoted policies, instructions, training lists.
$noise_cases = array(
    'The policy stated "Therapeutic holds are only to be used where a resident has physically engaged himself or others in an attempt to cause bodily harm."',
    'In the event of an unauthorized absence staff are to contact: Administrator on duty; Local enforcement and/or 911; Case manager.',
    'a) Submit a detailed plan to ensure the RN checks MARs to identify medication errors and missed medications.',
    'The following required annual trainings expired in May 2025: Medical Consent, Recognizing and Reporting Child Sexual Abuse, Runaway Prevention.',
    'When youth are discharged and subsequently admitted to the Hospital, the name of the person responsible should be documented.',
    'This deficient practice poses the potential condition in which patients could physically harm themselves, causing physical injury or death.',
    'Mom reported that member was sexually assaulted in their previous group home.',
    'The document stated "...History of physical aggression, suicidal ideation, self harm, running away..."',
    'A.R.S. § 13-3620(A) states any person who reasonably believes that a minor has been the victim of physical injury shall report.',
    'A.R.S. § 13-3620 Any person who reasonably believes that a minor is or has been the victim of physical injury, abuse, child abuse, or death shall immediately report.',
);
$noise_cases = array_merge($noise_cases, array(
    'Diagnoses of Conduct Disorder, ADHD, Child Physical Abuse, PTSD.',
    'These crimes include Article 6, Homicide; Article 7A, Rape and Other Sex Offenses; Article 8, Assaults.',
    'One staff was missing Human Trafficking, Prison Rape Elimination Act (PREA), and Sexual Harassment training.',
    'The program conducted thirty mock mental health drills in response to a suicide attempt.',
    '“Medication error” means: a. The failure to administer an ordered medication.',
    // Training and plans the scanner once read as a death or a suicide attempt (2026-09-30).
    'All reviewed staff records documented staff completed suicide awareness and prevention training, with the exception of one staff who was missing two hours of suicide training.',
    'All seven reviewed pre-service staff records documented staff completed suicide awareness and prevention training.',
    'The center has a Suicide Prevention Plan which includes an established review process for incidents of serious suicide attempts or self-inflicted injuries.',
    'Twelve out of twelve staff interviewed state that they do get continuous training\'s to help clients who engage in self injurious behaviors.',
    'Staff reviewed the self-harm training materials during orientation.',
    'The Penal Code Section 11165.6 defines child abuse or neglect as a physical injury or death inflicted by other than accidental means upon a child by another person.',
    'The rule for incident reporting to DCWL for AWOL\'s, youth involvement with law enforcement, youth hospitalizations, etc. also states immediate reporting, but not more than 24 hours.',
    'Abuse means any act or failure to act by an employee or other person responsible for the care of an individual in a facility or program operated, licensed, or funded by the department.',
    'This action demonstrates use of excessive force, was performed knowingly and intentionally, and might have caused physical or psychological harm, injury, or death to the individual.',
    'This includes, but is not limited to, frequent unprovoked physical assaults, weapon use, or severe property destruction. Active Suicide Risk: Individuals with a current, imminent plan for suicide.',
    '"As a reminder, Serious Occurrences are any event that result in Restraint or Seclusion, Resident\'s Death, Any Serious Injury to a Resident, and a Resident\'s Suicide Attempt.',
));
foreach ($noise_cases as $s) check((bool) preg_match('/' . kop_ih_noise_pattern() . '/iu', $s), 'noise: "' . $s . '" is set aside');
foreach (array('The incident report stated E1 punched R1 in the face.', 'Staff failed to follow the treatment plan and the child was hospitalized.', 'The child was found unresponsive when the door was opened.') as $s) {
    check(!preg_match('/' . kop_ih_noise_pattern() . '/iu', $s), 'noise: "' . $s . '" is kept');
}
$sentence_cases = array_merge($sentence_cases, array(
    // Owner rule, 2026-09-28: minors' sexual activity is severe only with an adult taking part or an assault.
    array('Two children in care (15 and 17 years of age) were involved in inappropriate sexualized behavior, and the caregiver was unaware of the children\'s activity.', array()),
    array('A caregiver failed to supervise children resulting in children engaging in inappropriate sexual behavior.', array()),
    array('Based on the information obtained, minors had engaged in inappropriate sexual activities while in care.', array()),
    array('Three of four staff members interviewed stated that a former client did inappropriately touch another client in care.', array()),
    array('Staff (S1) was oblivious towards the inappropriate touching between C1 and C2 throughout the yoga session.', array()),
    array('A staff member was found having inappropriate sexual contact and relationship with a 17-year-old child in care.', array('sexual_abuse')),
    array('Administrator was aware of on-going inappropriate relationship between a staff member at the operation and a child in care.', array('sexual_abuse')),
    array('The complaint alleged staff did not provide adequate supervision resulting in minor engaging in sexual behavior with an adult.', array('sexual_abuse')),
    array('An operational volunteer did not use proper judgement and did not demonstrate self-control in the presence of the children by engaging in inappropriate sexualized contact with two children in care.', array('sexual_abuse')),
    array('Allegations involving inappropriate sexual comments made by a staff member were not reported timely to Licensing.', array('sexual_abuse')),
    array('Victim Child alleged that residents had nonconsensual sex inside the facility.', array('sexual_abuse')),
    array('The screening for sexually abusive behavior was not completed within six days of admission.', array()),
    // Owner rule, 2026-09-28: a single medication error is not queued; a pattern of them is.
    array('Medication error occurred.', array()),
    array('A child in care received the wrong dose of medication.', array()),
    array('A child in care was not given their medication as prescribed.', array()),
    array('Some of the children\'s medication records were missing the medication counts.', array()),
    array('The child missed their dose of Concerta 36mg, on 5/4, 5/5, 5/21, 5/22, and 5/23.', array('medical_neglect')),
    array('Six children in care missed dosages of prescribed medications on multiple occasions for consecutive days.', array('medical_neglect')),
    array('A child was given the wrong dosage of medication for a month, after a change in medication.', array('medical_neglect')),
    array('LPA observed that this is the second medication error in the facility within thirty days.', array('medical_neglect')),
    array('A child sustained a hand fracture and was not provided medical care until 5 days later.', array('medical_neglect')),
    // North Carolina's record reviews.
    array('FC #4 was in the custody of DSS since November 2017 after FC #4\'s grandmother passed away.', array()),
    array('On 5/6/25, client #1 told him she wanted to kill herself and be with a friend who committed suicide.', array()),
    array('Staff member S1 was seen on video hitting C1 in the day room.', array('physical_abuse')),
    array('Staff sexually abused a child in care.', array('sexual_abuse')),
    array('On video, a staff member was seen shoving a child to prevent them from going into the room of a peer.', array('physical_abuse')),
    array('A child in care was subjected to physical abuse by a operation staff member.', array('physical_abuse')),
));
$sentence_cases = array_merge($sentence_cases, array(
    // Owner rule, 2026-10-05: paperwork or training only does not count.
    array('The child\'s hospitalization on 4/2/24 was not reported to Licensing within 24 hours.', array()),
    array('The facility did not document the restraint in which the youth sustained a bruise.', array()),
    array('The operation failed to complete an incident report after a child in care was taken to the emergency room.', array()),
    array('A youth ran away on 5/1 and the runaway was documented late.', array()),
    array('Staff who restrained the child had expired restraint certification.', array()),
    array('The facility failed to notify the parent within 24 hours that the youth was transported to the hospital.', array()),
    array('A youth was taken to the emergency room after swallowing a battery.', array('self_harm', 'hospitalization')),
    // ... but what staff did to a child counts however it was cited.
    array('The facility failed to report to Licensing within 24 hours that S1 slapped C1.', array('physical_abuse')),
    array('Staff restrained the child in a prone hold, causing a broken wrist, and did not document the restraint.', array('restraint_injury')),
    // Montana's surveys (2026-10-05).
    array('1. Youth #4 reports that he witnessed Staff #2 pick Youth #1 up by his shirt and drag him to the unit from the dining hall and place him in a restraint.', array('physical_abuse')),
    array('There are consistent reports from both youth and staff of verbal abuse, use of physical force, profanity and degradation of youth by staff as evidenced by:', array('physical_abuse')),
    array('Youth #4 heard Youth #1 scream during the restraint that he was being hurt.', array('restraint_injury')),
    array('Participant #1 and #2 had stacked several bean bags up and made a little wall in front of themselves to hide from staff’s view and engaged in a sexual activity.', array()),
    array('On 08/19/2024, Youth #1 reported to staff that Youth #2 and Youth #3 engaged in sexual activity.', array()),
    array('P#1 suffered injuries of multiple bruises, bite marks, and scratches that were done by participant #2 during this incident.', array()),
    array('Staff did not report an incident of child abuse or neglect that occurred on 9/6/2013 to the state child abuse hotline within 24 hours of the incident.', array()),
    // Michigan's special investigations (labels "Youth A", "Staff 1"), from the 2026-09-30 mirror.
    array('Although Staff 2 followed, Youth A was separated from staff long enough to kick the door several times before he exited the building.', array()),
    array('Being alone in the lounge provided Youth A the opportunity to seriously physically assault Youth B.', array()),
    array('Staff 1 denied punching Youth A and reported she put up her arms as Youth A went to swing on her again.', array()),
    array('Staff 1 denied being physically assaulted by a Wayne County Juvenile Detention youth.', array()),
    array('The facility is found in noncompliance as staff reported they did not feel safe intervening to attempt to take the cell phones away from the youth as staff felt Youth A and Youth B would assault them.', array()),
    array('Administrator 1 reported observing Staff 1 and Staff 3 engage in inappropriate touching/pushing of Youth A and Youth B when trying to keep them from fighting.', array()),
    array('The facility is found in noncompliance as per interviews with youth and staff, Youth A and Youth C engaged in sexual activity while at the facility as Youth C snuck into Youth A\'s bedroom for a few minutes while Staff 4 was working.', array()),
    array('This was unwarranted as this was not a response to a life-threatening injury or serious bodily harm when other interventions were ineffective and does not align with seclusion variance granted.', array()),
    array('Staff 1 kicked and pushed Youth A during the incident that occurred on 10/20/2025.', array('physical_abuse')),
    array('The facility is found in noncompliance as per interviews and video review Staff 1 grabbed Youth A by the collar area of his shirt and pushed Youth A against the wall.', array('physical_abuse')),
    array('Both staff were aware of concerns of Youth A being physically hit by Staff 2 and there was no report made to Child Protective Services.', array('physical_abuse')),
    array('It is found that Former Staff 1 hit Youth A with a walkie talkie more than once and pulled out strands of Youth A\'s hair.', array('physical_abuse')),
    array('Youth C reported having sexual intercourse with Staff 1 while he was at the facility.', array('sexual_abuse')),
    // The states added 2026-10-05, from their real reports (live copy of 2026-10-05).
    array('Staff E confirmed observing the hole punch on each fire extinguisher for the month of June 2022.', array()),
    array('Two staff were transporting Consumer #13 when Staff H hit the brakes to avoid a car accident and Consumer #13 fell forward hitting his head.', array()),
    array('Review of Consumer #1\'s incident reports revealed a 4/1/03 incident in which he accused staff of physical abuse (dragging him down the hallway).', array()),
    array('The staff were scheduled to work with children that had known aggressive/assaultive behaviors.', array()),
    array('On 1/31/2025, Child 2 told staff the water hitting her skin made her feel uncomfortable.', array()),
    array('The facility failed to provide medical records upon request for 1 of 3 sample patients reviewed.', array()),
    array('The parent of patient #2 stated they never received the medical records they requested.', array()),
    array('Once the resident has swallowed the medications, the resident must open his/her mouth, to allow staff to check that medications were swallowed.', array()),
    array('On 2/14/16 an unwanted sexual advance by resident #4 towards resident #8 occurred in the basement of a church.', array()),
    array('Patient #2 became nude, sexually abusing self and stocking objects into her vagina.', array()),
    array('Child A and B\'s assessment did not include assessment related to known or suspected suicide or self-injury attempts or gestures.', array()),
    array('Staff member B kicked Child 4 in the leg causing the child to fall to the ground.', array('physical_abuse')),
    array('Employee #1 used excessive force by grabbing individual\'s arm and flipped Individual #1 over Employee #1\'s head resulting in Individual #1 landing hard on the floor.', array('physical_abuse')),
    array('The video footage showed Staff A dragging Resident A from their bedroom into the bathroom by grasping the back of the resident\'s shirt.', array('physical_abuse')),
    array('The complainant reported that Resident A had been forced to perform oral sex by Resident B.', array('sexual_abuse')),
    // Owner rule, 2026-10-05: a resident assaulting staff does not count.
    array('C1 punched a staff member in the face.', array()),
    array('The resident assaulted staff and police were called.', array()),
    array('Staff was struck by a resident and taken to the emergency room.', array()),
    array('A staff member was taken to the hospital after a youth attacked her.', array()),
    array('Youth 2 kicked and bit staff during the restraint, and S1 sustained a bruise.', array()),
    array('S1 was hit by C1 during the incident.', array()),
    array('S1 restrained C1 after being hit by C1.', array()),
    array('The youth threw a chair at staff and was arrested.', array()),
    array('A resident physically assaulted a staff member.', array()),
    // ... but staff hurting the child in the same sentence still counts.
    array('C1 hit S2, and S2 then punched C1 in the face.', array('physical_abuse')),
    array('After the youth assaulted staff, staff restrained the youth in a prone hold, resulting in a fractured arm.', array('restraint_injury')),
    array('Staff punched a resident who was taken to the emergency room.', array('physical_abuse', 'hospitalization')),
    array('A youth had a panic attack and staff slapped him.', array('physical_abuse')),
));
foreach ($sentence_cases as $case) {
    $got = array();
    foreach (kop_ih_split_sentences($case[0]) as $s) $got = array_merge($got, array_keys(kop_ih_match_sentence($s)));
    sort($got);
    $want = $case[1];
    sort($want);
    check($got === $want, 'sentence: "' . $case[0] . '" expected [' . implode(',', $want) . '] got [' . implode(',', $got) . ']');
}

check(count(kop_ih_split_sentences('Dr. Smith met Mr. Jones at 9 a.m. on Monday. They left.')) === 2, 'abbreviations do not end a sentence');
check(count(kop_ih_split_sentences('Review of an incident report revealed: -On 1/31/26 Client #1 walked off. -Staff #2 called law enforcement - 17 year old male')) === 4, 'a bullet is a sentence of its own');

// Texas
$tx = array('id' => 1, 'facility_id' => 1, 'categories_json' => json_encode(array(
    'Standard Number / Description' => '748.685(a)(4) - Caregiver responsibility',
    'Standard Risk Level' => 'High', 'Corrected at Inspection' => 'No',
    'Deficiency Narrative' => 'A child in care absconded and was later hospitalized after an overdose.',
)));
$c = kop_ih_candidates('TX', $tx);
check(count($c) === 1 && $c[0]['category'] === 'self_harm', 'TX: worst category wins');
check($c && $c[0]['score'] === 75, 'TX: High risk keeps the full score (65 + 2 extra categories)');
check($c && $c[0]['state_label'] === 'Risk level: High' && $c[0]['corrected_on_site'] === false, 'TX: label and corrected flag');
check($c && $c[0]['excerpt'] === 'A child in care absconded and was later hospitalized after an overdose.', 'TX: excerpt is verbatim');
$tx_low = $tx;
$tx_low['categories_json'] = str_replace('"High"', '"Low"', $tx['categories_json']);
$c_low = kop_ih_candidates('TX', $tx_low);
check($c_low && $c_low[0]['score'] === 30, 'TX: Low risk scales the score down');
$tx_none = $tx;
$tx_none['categories_json'] = json_encode(array('Standard Risk Level' => 'High', 'Deficiency Narrative' => 'Two smoke detectors had no batteries.'));
check(kop_ih_candidates('TX', $tx_none) === array(), 'TX: a High citation with no harm is not queued');

// A runaway on its own is not queued; one that ends in a death or a serious injury is.
$tx_run = static function ($narrative) use ($tx) {
    $row = $tx;
    $row['categories_json'] = json_encode(array('Standard Risk Level' => 'High', 'Deficiency Narrative' => $narrative));
    return kop_ih_candidates('TX', $row);
};
check($tx_run('The child ran away from the operation on 01/03/22 at 11:30pm.') === array(), 'TX: a runaway alone is not queued');
check($tx_run('The child absconded from the facility and police were called to search for him.') === array(), 'TX: a runaway with the police searching is still a runaway alone');
check($tx_run('The child ran away and returned the next day with no injuries.') === array(), 'TX: a runaway who came back unhurt is not queued');
$c = $tx_run('The child ran away and was hit by a car, suffering a fractured leg.');
check(count($c) === 1 && $c[0]['category'] === 'missing', 'TX: a runaway with a serious injury is queued');
$c = $tx_run('The child ran away from the operation. The child was found deceased two days later.');
check(count($c) === 1 && $c[0]['category'] === 'death', 'TX: a runaway who died is queued as a death');
$c = $tx_run('The child ran away from the operation and was later taken to the hospital by ambulance.');
check(count($c) === 1 && $c[0]['category'] === 'hospitalization', 'TX: a runaway who ended up in hospital is queued');

// Owner rule, 2026-10-05: a citation for paperwork or training only is not queued.
$tx_cite = static function ($standard, $narrative) use ($tx) {
    $row = $tx;
    $row['categories_json'] = json_encode(array('Standard Number / Description' => $standard, 'Standard Risk Level' => 'High', 'Deficiency Narrative' => $narrative));
    return kop_ih_candidates('TX', $row);
};
check($tx_cite('748.303(a) - Serious incident reporting', 'On 3/4/24 a child in care self-harmed and was taken to the emergency room. Licensing was notified on 3/9/24.') === array(),
    'TX: a reporting citation about an ER visit is paperwork only');
check($tx_cite('748.501 - Personnel records', 'A child in care died on 2/2/24. The employee file of the caregiver on shift held no background check.') === array(),
    'TX: a personnel records citation is paperwork only, even with a death in it');
check($tx_cite('748.931 - Pre-service training', 'An employee who had not completed emergency behavior intervention training restrained a child, and the child was hospitalized.') === array(),
    'TX: a training citation is training only');
$c = $tx_cite('748.303(a) - Serious incident reporting', 'The operation did not report within 24 hours that a caregiver slapped a child in care.');
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse', 'TX: a reporting citation that records staff abuse is kept');
$c = $tx_cite('748.685 - Caregiver responsibilities', 'The caregiver failed to supervise the child, who self-harmed and was taken to the emergency room. The incident was documented late.');
check(count($c) === 1 && $c[0]['category'] === 'self_harm', 'TX: a supervision failure with a late record is not paperwork only');
$c = $tx_cite('', 'A child in care ran away and was struck by a car. The operation failed to notify the parent within 24 hours.');
check($c === array(), 'TX: with no rule named, a citation whose only fault is a late notice is paperwork only');

// California
$ca_text = '13On 9-27-24 LPA conducted an unannounced inspection. Staff physically assaulted client in care. '
    . 'The preponderance of the evidence has been met. Therefore, these allegations are Substantiated. '
    . 'Facility is being cited for violation of Section 87072(c)(1) and 80075(b). '
    . 'SubstantiatedEstimated Days of Completion: SUPERVISORS NAME: A Person LICENSING EVALUATOR NAME: B Person';
$ca = array('id' => 2, 'facility_id' => 2, 'categories_json' => json_encode(array(
    'report_type' => 'Complaint Investigation', 'complaint_status' => 'unsubstantiated', 'investigation_findings' => $ca_text,
)));
$c = kop_ih_candidates('CA', $ca);
check(count($c) === 1 && $c[0]['state_label'] === 'Substantiated', 'CA: outcome read from the text, not the scraped status');
check($c && $c[0]['category'] === 'physical_abuse' && $c[0]['score'] === 80, 'CA: substantiated physical abuse scores 80');
check($c && $c[0]['excerpt'] === 'Staff physically assaulted client in care.', 'CA: excerpt is the matching sentence only');
check($c && strpos($c[0]['standard'], '87072(c)(1)') !== false && strpos($c[0]['standard'], '80075(b)') !== false, 'CA: sections cited are collected');
check($c && strpos($c[0]['excerpt'], 'SUPERVISORS') === false, 'CA: form boilerplate is stripped');

$ca_unsub = $ca;
$ca_unsub['categories_json'] = json_encode(array('complaint_status' => 'unsubstantiated',
    'investigation_findings' => 'The complaint alleged that staff hit a minor. There is not a preponderance of evidence. Therefore, the allegations are UNSUBSTANTIATED.'));
check(kop_ih_candidates('CA', $ca_unsub) === array(), 'CA: an unsubstantiated complaint is not queued');

// A report that substantiates one allegation and not another: only the substantiated one counts.
$ca_mixed = static function ($findings) use ($ca) {
    $row = $ca;
    $row['categories_json'] = json_encode(array('complaint_status' => 'unsubstantiated', 'investigation_findings' => $findings));
    return kop_ih_candidates('CA', $row);
};
$c = $ca_mixed('Staff slapped a client in care. This allegation is Substantiated. The allegation that staff withheld food is Unsubstantiated.');
check(count($c) === 1 && $c[0]['state_label'] === 'Substantiated (one of several allegations)' && $c[0]['score'] === 80, 'CA: a mixed report is queued at full score for the substantiated allegation');
check($c && $c[0]['excerpt'] === 'Staff slapped a client in care.', 'CA: a mixed report quotes the harm, not the verdict');
check($ca_mixed('Staff slapped a client in care. This allegation is Unsubstantiated. Staff withheld food from clients. This allegation is Substantiated.') === array(),
    'CA: a mixed report whose harm was the unsubstantiated allegation is not queued');
check($ca_mixed('The allegation that staff physically abused a client in care is Substantiated. The allegation that staff withheld food is Unsubstantiated.') !== array(),
    'CA: the verdict may sit in the same sentence as the harm');
check($ca_mixed('Staff slapped a client in care. LPA reviewed the file. LPA interviewed C1. LPA interviewed S1. This allegation is Substantiated. The food allegation is Unsubstantiated.') === array(),
    'CA: a verdict more than three sentences after the harm does not cover it');
check($ca_mixed('The allegation that staff sexually abused a minor in care cannot be substantiated because the staff did not work that shift.') === array(),
    'CA: "cannot be substantiated" is unsubstantiated');
check($ca_mixed('Interviews did not substantiate the allegation that staff hit a youth in care.') === array(),
    'CA: "did not substantiate" is unsubstantiated');
foreach (array('The facility is found in compliance as Staff 3 reported being present and Staff 1 did not slap Youth A.',
    'It does not appear as though Staff Person 1 and Resident A met outside of the facility or engaged in any form of sexual contact.',
    'Upon completing interviews with staff and youth, there was no indications of an inappropriate relationship.') as $s) {
    check((bool) preg_match('/' . kop_ih_unsubstantiated_pattern() . '/iu', $s), 'cleared: "' . $s . '"');
}
check($ca_mixed('Staff physically abused a client in care. Based on the interviews the allegation is inconclusive.') === array(),
    'CA: an inconclusive complaint is not queued');
$ca_inc = $ca;
$ca_inc['categories_json'] = json_encode(array('complaint_status' => 'inconclusive',
    'investigation_findings' => 'Staff physically abused a client in care. The evidence gathered did not settle the matter.'));
check(kop_ih_candidates('CA', $ca_inc) === array(), 'CA: a complaint the state filed as inconclusive is not queued');

$ca_eval = array('id' => 3, 'facility_id' => 3, 'categories_json' => json_encode(array(
    'report_type' => 'Facility Evaluation', 'narrative' => 'LPA toured the facility. All bedrooms were clean. No deficiencies were cited.')));
check(kop_ih_candidates('CA', $ca_eval) === array(), 'CA: an evaluation with nothing cited is not queued');

// Utah: one line per rule cited in the report text.
$ut_raw = "R380-80-5(4): Provider shall protect clients from abuse, prevent abuse \xE2\x80\x94 The provider was out of compliance with R380-80-5(4) by not protecting clients from harm. During the investigation inspection, the evidence substantiated that staff members harmed a client by forcibly removing a client from the top bunk of a bed. During the unnecessary physical restraint, the client incurred a concussion.\n"
    . "R501-1-8(1)(a)-(i): Facility and safety requirements \xE2\x80\x94 The licensee was out of compliance by having a prescription cream out in the med room.\n"
    . "This was a repeat noncompliance as noted on 03/28/2024.\n"
    . "Checklist 642294: Census: 27; Capacity: 45; Contact: Scott Jones; Licensor: Heather Holbrook";
$ut = array('id' => 31, 'facility_id' => 7, 'report_date' => '11/17/2025', 'raw_content' => $ut_raw,
    'categories_json' => json_encode(array('Inspection Date' => '11/17/2025', 'Inspection Type' => 'Investigation Inspection', 'Findings Count' => '2')));
$f = kop_ih_extract('UT', $ut);
check(count($f) === 2, 'UT: one finding per rule line, checklist line skipped; got ' . count($f));
check($f && $f[0]['standard'] === 'R380-80-5(4) Provider shall protect clients from abuse, prevent abuse', 'UT: the rule and its title are the standard');
check($f && strpos($f[0]['text'], 'The provider was out of compliance') === 0, 'UT: the text starts after the dash');
check($f && substr($f[1]['text'], -11) === '03/28/2024.', 'UT: a repeat note joins the finding before it');
check(count(kop_ih_split_sentences('1. A review of the file revealed E1 punched R1. 2. R1 was seen by a nurse. Two children were missing for 15 minutes. Then staff called.')) === 4, 'sentences: list numbers stay with their sentence, a number ending one does not');
check($f && $f[0]['state_label'] === 'Out of compliance, investigation inspection' && $f[0]['factor'] === 1.0, 'UT: an investigation is trusted fully');
$c = kop_ih_candidates('UT', $ut);
check(count($c) === 1 && $c[0]['category'] === 'restraint_injury' && $c[0]['score'] === 75, 'UT: the concussion during a forced removal is a restraint injury at 75');
$ut_annual = $ut;
$ut_annual['categories_json'] = json_encode(array('Inspection Type' => 'Announced, Annual Inspection'));
$c = kop_ih_candidates('UT', $ut_annual);
check($c && $c[0]['score'] === 64, 'UT: an annual inspection is scaled to 0.85');
check(kop_ih_candidates('UT', array('id' => 32, 'facility_id' => 7, 'raw_content' => "Checklist 1: Census: 5", 'categories_json' => '{}')) === array(), 'UT: a checklist-only report has no findings');

// Arizona: evidence plus numbered findings per deficiency.
$az = array('id' => 41, 'facility_id' => 8, 'report_date' => '8/23/2024', 'categories_json' => json_encode(array(
    'inspection_type' => 'Complaint', 'inspection_number' => 'INSP-1',
    'deficiencies' => array(
        array('rule' => 'R9-10-706. Treatment Plan. A. An administrator shall ensure that a treatment plan is developed for each resident.',
            'evidence' => 'Based on record review and interview, the administrator failed to ensure the health and safety of a resident.',
            'findings' => "1. A review of an incident report revealed E1 punched R1 in the face. 2. R1 was transported to the emergency room. 3. (A.R.S.) \\'a7 36-425.03(E) was not on file."),
        array('rule' => 'R9-10-703. Personnel.', 'evidence' => 'The administrator failed to ensure personnel records were complete.', 'findings' => '1. E2 had no CPR card.'),
    ),
)));
$f = kop_ih_extract('AZ', $az);
check(count($f) === 2 && $f[0]['standard'] === 'R9-10-706. Treatment Plan. A. An administrator shall ensure that a treatment plan is developed for each resident.', 'AZ: one finding per deficiency with its rule');
check($f && strpos($f[0]['text'], '§ 36-425.03') !== false, 'AZ: the RTF section sign is decoded');
check($f && $f[0]['state_label'] === 'Deficiency cited, complaint' && $f[0]['factor'] === 1.0, 'AZ: a complaint inspection is trusted fully');
$c = kop_ih_candidates('AZ', $az);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && in_array('hospitalization', $c[0]['categories'], true), 'AZ: E1 punching R1 is staff assault, with the ER visit');
check($c && $c[0]['excerpt'] === '1. A review of an incident report revealed E1 punched R1 in the face. 2. R1 was transported to the emergency room.', 'AZ: excerpt is the two matching findings');
$az_long = $az;
$d = json_decode($az['categories_json'], true);
$d['inspection_type'] = 'Compliance (Annual)';
$d['deficiencies'][0]['rule'] = str_repeat('36-425.03. Children\'s behavioral health programs; personnel; ', 6);
$az_long['categories_json'] = json_encode($d);
$f = kop_ih_extract('AZ', $az_long);
check($f && mb_strlen($f[0]['standard']) <= 164 && substr($f[0]['standard'], -4) === ' ...' && $f[0]['factor'] === 0.85, 'AZ: a long rule is cut at a word; an annual inspection is 0.85');

// Connecticut: the non-compliance section of a field visit, split per regulation.
$ct_raw = "Field Visit Reporting Form\n\nList of Areas / Topics covered during visit:\n\xE2\x80\xA2\tCensus is 9.\n\xE2\x80\xA2\tA resident was taken to the hospital by ambulance after a fall; discussed with the PM.\n\n"
    . "Corrective Actions implemented as a result of previous visit:\nNot applicable\n\n"
    . "Areas of regulatory non-compliance identified during this visit:\n\nSection 17a-145-63.  Chief administrative officer.\nEvidence:  Staff slapped a resident during a restraint and the program did not report it to the Careline.\n"
    . "17a-145-64 Personnel policies and procedures.\no Evidence of CPR certification was not found in one file (DS).\n\n"
    . "Please submit a plan of correction to address the above referenced areas of non-compliance within 30 days.\n\nJames Funaro\nRegulatory Consultant";
$ct = array('id' => 51, 'facility_id' => 9, 'report_date' => '11/06/2024', 'raw_content' => $ct_raw, 'categories_json' => json_encode(array('visit_details' => array())));
$f = kop_ih_extract('CT', $ct);
check(count($f) === 2, 'CT: one finding per regulation in the non-compliance section; got ' . count($f));
check($f && $f[0]['standard'] === '17a-145-63. Chief administrative officer' && $f[1]['standard'] === '17a-145-64 Personnel policies and procedures', 'CT: the regulation heads each finding');
check($f && strpos($f[0]['text'], 'Staff slapped a resident') !== false && strpos($f[0]['text'], 'Please submit') === false, 'CT: the section ends before the plan-of-correction request');
$c = kop_ih_candidates('CT', $ct);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && $c[0]['score'] === 68, 'CT: the slap is queued at 0.85; the hospital trip in the topics list is not a finding');
$ct_none = $ct;
$ct_none['raw_content'] = "Areas of regulatory non-compliance identified during this visit:\n\n\xE2\x80\xA2\tNot applicable\n\nPlease submit a plan";
check(kop_ih_candidates('CT', $ct_none) === array(), 'CT: "Not applicable" is no finding');
$ct_letter = $ct;
$ct_letter['raw_content'] = "Dear Ms. Goduti,\n\nOn September 16th, 2024, a biennial licensing inspection was conducted at your facility. The areas of non-compliance are as follows:\n\n17a-145-73 Sleeping accommodations.\nEvidence: A child was found unresponsive in a bedroom and staff did not call 911 for twenty minutes.\n\nDCF licensing has determined that your agency has met the requirements for a regular license.\n\nSincerely,\nPatrick Hughes";
$c = kop_ih_candidates('CT', $ct_letter);
check(count($c) === 1 && $c[0]['category'] === 'death' && $c[0]['standard'] === '17a-145-73 Sleeping accommodations', 'CT: a licensing letter is read the same way');

// North Carolina: a statement of deficiencies, page headers in the middle, harm tags only.
$nc_raw = "Division of Health Service Regulation\nPRINTED: 10/04/2019\nFORM APPROVED\nV 000 INITIAL COMMENTS V 000\nA complaint survey was completed. The complaint was substantiated.\n"
    . "V 512 27D .0304 Client Rights - Harm, Abuse, Neglect V 512\n(a) Each client shall be free from harm, abuse, neglect and exploitation.\nThis Rule is not met as evidenced by:\n"
    . "Based on record review and interview, staff failed to protect clients from harm. The findings are:\nReview on 9/12/19 of the incident report revealed:\n-Staff #1 punched Client #2 in the face\n"
    . "Division of Health Service Regulation\nSTATEMENT OF DEFICIENCIES (X1) PROVIDER/SUPPLIER/CLIA\nALEXANDER YOUTH NETWORK - CHARLOTTE DAY 1\nV 512 Continued From page 2 V 512\n"
    . "during a restraint on 9/1/19.\n-Diagnoses of Conduct Disorder, Child Physical Abuse, PTSD.\nThis deficiency constitutes a Type A1 rule violation for serious abuse.\n"
    . "V 112 27G .0205 (C-D) Treatment Plan V 112\nThis Rule is not met as evidenced by:\nBased on record review the facility failed to develop goals. History of self harm, suicide attempts and running away was documented.\n";
$nc = array('id' => 61, 'facility_id' => 11, 'report_date' => '10/2/2019', 'raw_content' => $nc_raw, 'categories_json' => json_encode(array('inspection_type' => 'MHLCS Complaint')));
$f = kop_ih_extract('NC', $nc);
check(count($f) === 1 && strpos($f[0]['standard'], 'V 512') === 0, 'NC: one finding, from the harm tag; the treatment plan tag is left out');
check($f && strpos($f[0]['text'], 'PRINTED') === false && strpos($f[0]['text'], 'ALEXANDER') === false && strpos($f[0]['text'], 'Continued From') === false && strpos($f[0]['text'], 'shall be free') === false, 'NC: headers, the rule text and the continuation line are dropped');
check($f && $f[0]['factor'] === 1.0 && strpos($f[0]['state_label'], 'Type A1 violation') !== false, 'NC: a Type A1 violation carries the full score');
$c = kop_ih_candidates('NC', $nc);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && strpos($c[0]['excerpt'], 'Diagnoses') === false, 'NC: the punch is queued, the diagnoses list is not');

// Georgia: one tag per rule, the severity letter scales the score.
$ga_raw = "STATEMENT OF DEFICIENCIES\nTAG\nSUMMARY OF STATEMENT OF DEFICIENCIES PLAN OF CORRECTION\nNUMBER\n0000 Severity : 0 Survey Type(s) : 2 Completed Date : ____\nOpen Comment\nThe purpose of this survey was to investigate an incident.\n"
    . "1511 Severity : D Survey Type(s) : 2 Completed Date : ____\nResident Rights\nThis Requirement is not met as evidenced by:\nBased on record review and interview, the facility failed to protect a resident.\nFindings include:\nResident #1 informed Staff A that Staff D hit him/her twice in the chest.\n9/1/2026 1:22:36 PM 1\n"
    . "1602 Severity : G Survey Type(s) : 2 Completed Date : ____\nSupervision\nThis Requirement is not met as evidenced by:\nResident #2 was found unresponsive in the bathroom and later died at the hospital.\n";
$ga = array('id' => 62, 'facility_id' => 12, 'report_date' => '08/18/2025', 'raw_content' => $ga_raw, 'categories_json' => json_encode(array('survey_type' => 'Incident')));
$f = kop_ih_extract('GA', $ga);
check(count($f) === 2 && $f[0]['factor'] === 0.85 && $f[1]['factor'] === 1.0 && $f[0]['state_label'] === 'Deficiency cited, severity D, incident survey', 'GA: severity D is 0.85, G is 1.0; the opening comment is no finding');
check($f && strpos($f[0]['text'], '1:22:36') === false, 'GA: the page footer is dropped');

// Minnesota: a maltreatment memo counts only when maltreatment was determined; a correction order lists violations.
$mn_memo = "MALTREATMENT INVESTIGATION MEMORANDUM\nDisposition: Maltreatment determined as to physical abuse of an alleged victim by a staff person.\nSuspected Maltreatment Reported:\nIt was reported that a staff person hit a child.\n"
    . "Summary of Findings:\nThe AV said the SP slapped him.\nConclusion:\nA. Maltreatment:\nThe SP slapped the AV in the face. It was determined that physical abuse occurred.\nB. Responsibility pursuant to Minnesota Statutes:\nThe SP was responsible.";
$mn = array('id' => 63, 'facility_id' => 13, 'report_date' => 'April 28, 2023', 'raw_content' => $mn_memo, 'categories_json' => json_encode(array('doc_type' => 'Maltreatment Finding')));
$f = kop_ih_extract('MN', $mn);
check(count($f) === 1 && strpos($f[0]['text'], 'SP slapped the AV') !== false && strpos($f[0]['text'], 'responsible') === false && $f[0]['factor'] === 1.0, 'MN: a determined memo gives its conclusion');
$mn_not = $mn;
$mn_not['raw_content'] = str_replace('Maltreatment determined as to physical abuse', 'Maltreatment not determined', $mn_memo);
check(kop_ih_extract('MN', $mn_not) === array(), 'MN: a memo that did not determine maltreatment is no finding');
$mn_order = $mn;
$mn_order['categories_json'] = json_encode(array('doc_type' => 'Correction Order'));
$mn_order['raw_content'] = "CORRECTION ORDER\nA licensing review and licensing investigation was conducted.\n1. Violation: The license holder failed to protect a resident; staff restrained the resident face down and the resident suffered a broken wrist.\nRule Violated: Minnesota Rules, part 2960.0710.\nCorrective Action Required: Immediately.\n2. Violation: Postings were outdated.\nStatute Violated: 245A.65.";
$f = kop_ih_extract('MN', $mn_order);
check(count($f) === 1 && strpos($f[0]['text'], 'broken wrist') !== false && $f[0]['factor'] === 1.0, 'MN: each violation paragraph is a finding (a short one is not); an investigation is 1.0');

// Arkansas: only the federal surveys; the facility's own notices and police logs are not findings.
$ar = array('id' => 64, 'facility_id' => 14, 'report_date' => '6/12/2025', 'raw_content' => "N 123 Restraint\nThis STANDARD is not met as evidenced by:\nVia video, two male staff members were seen dragging Client #1 from the seclusion room.\n", 'categories_json' => json_encode(array('doc_type' => 'Complaint Survey')));
check(count(kop_ih_extract('AR', $ar)) === 1, 'AR: a complaint survey is read');
$ar['categories_json'] = json_encode(array('doc_type' => 'Notice of Incident'));
check(kop_ih_extract('AR', $ar) === array(), 'AR: a facility notice of incident is not');

// Florida: DJJ compliance reviews, indicators rated Failed or Limited only.
$fl_raw = "2.08 Youth Needs Assessment Summary (YNAS) Satisfactory Compliance\nThe program shall ensure a YNAS is completed.\nEach of the five youth had a YNAS completed on time.\n"
    . "Florida Department of Juvenile Justice Residential Annual Compliance Report\nOffice of Accountability and Program Support Page 21 of 65 (Revised August 2025)\n"
    . "5.05 Use of Force\nFailed Compliance\n(Critical)\nThe program shall ensure staff use force only as a last resort.\nVideo showed a staff member slammed a youth to the floor during a restraint, causing a laceration to the youth's chin.\n";
$fl = array('id' => 65, 'facility_id' => 15, 'report_date' => '06/30/2026', 'raw_content' => $fl_raw, 'categories_json' => json_encode(array('source' => 'DJJ', 'report_type' => 'QI Residential')));
$f = kop_ih_extract('FL', $fl);
check(count($f) === 1 && $f[0]['standard'] === 'Indicator 5.05 Use of Force' && $f[0]['factor'] === 1.0 && strpos($f[0]['text'], 'Page 21') === false, 'FL: the failed indicator is a finding, the satisfactory one is not');
$c = kop_ih_candidates('FL', $fl);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && strpos($c[0]['excerpt'], 'shall') === false, 'FL: the standard\'s own "shall" sentence is not quoted');
$fl['categories_json'] = json_encode(array('source' => 'DJJ', 'report_type' => 'SPEP'));
check(kop_ih_extract('FL', $fl) === array(), 'FL: a research evaluation (SPEP) is not read');

// Store: idempotent, and a reviewed row is never touched.
$mem = new PDO('sqlite::memory:');
$mem->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
kop_ih_ensure_tables($mem);
$cands = kop_ih_candidates('TX', $tx);
$first = kop_ih_store($mem, 'TX', $tx, $cands);
$second = kop_ih_store($mem, 'TX', $tx, $cands);
check($first['added'] === 1 && $second['added'] === 0 && $second['refreshed'] === 1, 'store: a second run adds nothing');
$tx_twin = array('id' => 9, 'facility_id' => 1) + $tx;
$twin = kop_ih_store($mem, 'TX', $tx_twin, kop_ih_candidates('TX', $tx_twin));
check($twin['duplicate'] === 1 && $twin['added'] === 0, 'store: the same finding under a second report id of one facility is not queued twice');
$mem->exec("UPDATE inspection_highlights SET status = 'rejected', reviewed_by = 'tester', score = 1");
$third = kop_ih_store($mem, 'TX', $tx, $cands);
$row = $mem->query('SELECT status, score, reviewed_by FROM inspection_highlights')->fetch(PDO::FETCH_ASSOC);
check($third['kept'] === 1 && $row['status'] === 'rejected' && (int) $row['score'] === 1, 'store: a rejected row is left as the reviewer left it');
$fourth = kop_ih_store($mem, 'TX', $tx, array());
check($fourth['kept'] === 1 && (int) $mem->query('SELECT COUNT(*) FROM inspection_highlights')->fetchColumn() === 1, 'store: a reviewed row survives the rules no longer producing it');
$mem->exec("UPDATE inspection_highlights SET status = 'pending'");
$fifth = kop_ih_store($mem, 'TX', $tx, array());
check($fifth['dropped'] === 1, 'store: a pending row the rules no longer produce is removed');

// Dates: the reports table holds them as text, in several forms.
$date_cases = array(
    '10/02/2023' => '2023-10-02', '3/23/25' => '2025-03-23', 'April 25, 2025' => '2025-04-25',
    '9/13/2023 - 9/14/2023' => '2023-09-13', '2024-07-01 00:00:00' => '2024-07-01', 'Sept. 3, 2021' => '2021-09-03',
    '' => null, 'unknown' => null, '13/45/2023' => null, '01/01/1900' => null, '01/01/2999' => null,
);
foreach ($date_cases as $text => $want) {
    check(kop_ih_parse_date($text) === $want, 'date: "' . $text . '" expected ' . var_export($want, true) . ' got ' . var_export(kop_ih_parse_date($text), true));
}
kop_ih_store($mem, 'TX', $tx, $cands);
check($mem->query('SELECT finding_date FROM inspection_highlights WHERE report_id = 1')->fetchColumn() === null, 'store: a report with no date leaves finding_date empty');
$tx_dated = array('id' => 21, 'facility_id' => 5, 'report_date' => '10/02/2023') + $tx;
kop_ih_store($mem, 'TX', $tx_dated, kop_ih_candidates('TX', $tx_dated));
check($mem->query('SELECT finding_date FROM inspection_highlights WHERE report_id = 21')->fetchColumn() === '2023-10-02', 'store: the report date is saved in a form that sorts');

// What the site shows: approved and severe only, most recent first.
$site = new PDO('sqlite::memory:');
$site->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
kop_ih_ensure_tables($site);
$site->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT)');
$site->exec('CREATE TABLE inspection_reports (id INTEGER PRIMARY KEY, facility_id INTEGER, report_id TEXT, report_date TEXT, report_url TEXT)');
$site->exec("INSERT INTO inspection_facilities VALUES (1, 'TX', 'Example Ranch')");
$site_rows = array(
    // id, report date, narrative, risk level, status
    array(11, '01/15/2020', 'A child in care died after being restrained by two staff.', 'High', 'approved'),
    array(12, '06/01/2026', 'Staff punched a resident in the face during an argument.', 'High', 'approved'),
    array(13, 'March 3, 2025', 'Staff engaged in a sexual relationship with a 16-year-old resident.', 'High', 'approved'),
    array(14, '08/01/2026', 'Staff slapped a resident during an argument.', 'High', 'pending'),
    array(15, '07/01/2026', 'The child was taken to the hospital by ambulance after a fall.', 'High', 'approved'),
    array(16, 'unknown', 'A child in care died in a vehicle accident while staff drove.', 'High', 'approved'),
);
foreach ($site_rows as $sr) {
    $site->prepare('INSERT INTO inspection_reports VALUES (?, 1, ?, ?, ?)')->execute(array($sr[0], 'r' . $sr[0], $sr[1], ''));
    $report = array('id' => $sr[0], 'facility_id' => 1, 'report_date' => $sr[1],
        'categories_json' => json_encode(array('Standard Risk Level' => $sr[3], 'Deficiency Narrative' => $sr[2])));
    kop_ih_store($site, 'TX', $report, kop_ih_candidates('TX', $report));
    $site->prepare('UPDATE inspection_highlights SET status = ? WHERE report_id = ?')->execute(array($sr[4], $sr[0]));
}
$shown = $site->query(kop_ih_recent_severe_sql(10))->fetchAll(PDO::FETCH_ASSOC);
$order = array_map(static function ($r) { return (int) $r['report_id']; }, $shown);
check($order === array(12, 13, 11, 16), 'site: approved severe findings, most recent first, undated last; got [' . implode(',', $order) . ']');
check(!in_array(14, $order, true), 'site: a pending finding is never shown, however recent');
check(!in_array(15, $order, true), 'site: an approved finding below the severe score is not highlighted');
check(count($site->query(kop_ih_recent_severe_sql(2))->fetchAll()) === 2, 'site: the limit holds');
// The Severe Reports page and the tracker flags: all of them, not the newest few.
$run = static function (array $q) use ($site) {
    $stmt = $site->prepare($q[0]);
    $stmt->execute($q[1]);
    return array_map(static function ($r) { return (int) $r['report_id']; }, $stmt->fetchAll(PDO::FETCH_ASSOC));
};
check($run(kop_ih_severe_query()) === array(12, 13, 11, 16), 'severe page: every approved severe finding, most recent first');
check($run(kop_ih_severe_query('tx')) === array(12, 13, 11, 16) && $run(kop_ih_severe_query('CA')) === array(), 'severe page: the state filter');
check($run(kop_ih_severe_query('', 'death')) === array(11, 16), 'severe page: the category filter');
check($run(kop_ih_severe_query('', 'physical_abuse')) === array(12), 'severe page: a category matches a whole entry of the list');
check($run(kop_ih_severe_query('', 'not-a-category')) === array(12, 13, 11, 16), 'severe page: an unknown category filters nothing');
check($run(kop_ih_severe_query('', '', 2, 1)) === array(13, 11), 'severe page: limit and offset page through the list');
$tally = kop_ih_severe_tally($site->query(kop_ih_severe_tally_sql())->fetchAll(PDO::FETCH_ASSOC));
check($tally['all']['all'] === 4 && $tally['TX']['all'] === 4, 'severe page tiles: every approved severe finding counted, per state and overall');
check(($tally['TX']['death'] ?? 0) === 2 && ($tally['TX']['physical_abuse'] ?? 0) === 1, 'severe page tiles: counted per kind of harm');
$tally = kop_ih_severe_tally(array(array('state' => 'ca', 'categories' => 'death,police,death,bogus')));
check($tally['CA'] === array('all' => 1, 'death' => 1, 'police' => 1), 'severe page tiles: a kind counts once per finding, unknown kinds are skipped');
check(kop_ih_flag_needle("Staff  punched a Resident.\nIn the face. [...] Later text.") === 'staffpunchedaresident.intheface.', 'flag needle: the first run of the excerpt, lower-cased, spaces removed');
check(mb_strlen(kop_ih_flag_needle(str_repeat('abcdefghij ', 40))) === 160, 'flag needle: capped at 160 characters');

check(kop_ih_document_url('', json_encode(array('pdf_url' => 'https://info.ncdhhs.gov/a.pdf?ver=1'))) === 'https://info.ncdhhs.gov/a.pdf?ver=1'
    && kop_ih_document_url('', json_encode(array('sod_url' => 'https://rcctrails.dhs.ga.gov/x?EID=1'))) === 'https://rcctrails.dhs.ga.gov/x?EID=1'
    && kop_ih_document_url('https://www.dhs.state.mn.us/doc', '{}') === 'https://www.dhs.state.mn.us/doc'
    && kop_ih_document_url('', json_encode(array('Deficiency Narrative' => 'x'))) === '', 'flags: the document url comes from pdf_url, sod_url or the report link, else none');
check(kop_ih_card_excerpt(str_repeat('word ', 100), 50) === 'word word word word word word word word word word [...]', 'site: a long excerpt is cut at a word and the cut is marked');

// One entry per facility per day: same text goes, different text is combined.
check(kop_ih_combine_excerpts(array('Staff hit a child.', 'Staff  hit a child')) === 'Staff hit a child.', 'merge: the same words, spaced or punctuated differently, are one');
check(kop_ih_combine_excerpts(array('Staff hit a child.', 'A child was choked.')) === "Staff hit a child.\n\nA child was choked.", 'merge: different findings become paragraphs');
check(kop_ih_combine_excerpts(array('The report said E2 origi', 'The report said E2 originally denied it. [...] Staff hit a child.'))
    === "The report said E2 originally denied it. [...] Staff hit a child.", 'merge: a longer cut of a sentence replaces the shorter one');
check(kop_ih_combine_excerpts(array('Staff hit a child. [...] He was bruised.', 'He was bruised.')) === 'Staff hit a child. [...] He was bruised.', 'merge: a run already quoted is not repeated');
check(kop_ih_excerpt_html("A <b>.\n\nB & C") === '<p>A &lt;b&gt;.</p><p>B &amp; C</p>', 'merge: each merged finding is its own escaped paragraph');

$day = new PDO('sqlite::memory:');
$day->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
kop_ih_ensure_tables($day);
$day->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT, program_name TEXT)');
$day->exec("INSERT INTO inspection_facilities VALUES (1, 'TX', 'Example Ranch', ''), (2, 'TX', 'Other Ranch', ''),
    (3, 'CA', 'NEW BEGINNINGS - RAJA', '336403968'), (4, 'CA', '336403968', '336403968'), (5, 'CA', 'SUNRISE HOME', '111111111'), (6, 'CA', 'SUNRISE HOME', '222222222')");
$ins = $day->prepare("INSERT INTO inspection_highlights (id, report_id, facility_id, finding_key, text_hash, state, category, categories, score, finding_date, excerpt, standard, state_label, status, scanner_version)
    VALUES (?, ?, ?, ?, ?, 'TX', ?, ?, ?, ?, ?, ?, ?, ?, 4)");
foreach (array(
    // id, report, facility, category, categories, score, date, excerpt, standard, label, status
    array(1, 10, 1, 'physical_abuse', 'physical_abuse', 80, '2024-01-05', 'Staff punched a child.', '748.1101', 'High', 'approved'),
    array(2, 11, 1, 'self_harm', 'self_harm', 72, '2024-01-05', 'A child in care self-harmed with staff aware.', '748.685', 'High', 'approved'),
    array(3, 12, 1, 'physical_abuse', 'physical_abuse', 80, '2024-01-05', 'Staff punched a child', '748.1101', 'High', 'approved'),
    array(4, 13, 1, 'death', 'death', 95, '2024-01-06', 'A child died.', '', '', 'approved'),
    array(5, 14, 2, 'death', 'death', 95, '2024-01-05', 'Another facility.', '', '', 'approved'),
    array(6, 15, 1, 'death', 'death', 95, '2024-01-05', 'A pending finding that day.', '', '', 'pending'),
) as $r) {
    $ins->execute(array($r[0], $r[1], $r[2], 'k' . $r[0], 'h' . $r[0], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[10]));
}
$dry = kop_ih_merge_same_day($day, false);
check(count($dry) === 1 && (int) $day->query('SELECT COUNT(*) FROM inspection_highlights')->fetchColumn() === 6, 'merge: a dry run writes nothing');
$done = kop_ih_merge_same_day($day, true);
$kept = $day->query('SELECT * FROM inspection_highlights WHERE id = 1')->fetch(PDO::FETCH_ASSOC);
check(count($done) === 1 && $done[0]['keep'] === 1 && $done[0]['identical'] === array(3) && $done[0]['merged'] === array(2), 'merge: the worst, oldest finding keeps its id; the identical one goes, the different one is folded in');
check($kept['excerpt'] === "Staff punched a child.\n\nA child in care self-harmed with staff aware." && $kept['categories'] === 'physical_abuse,self_harm'
    && $kept['standard'] === '748.1101; 748.685' && $kept['state_label'] === 'High', 'merge: text, kinds of harm and citations combined');
check(array_map('intval', $day->query('SELECT id FROM inspection_highlights ORDER BY id')->fetchAll(PDO::FETCH_COLUMN)) === array(1, 4, 5, 6), 'merge: other days, other facilities and pending findings are left alone');
check(kop_ih_merge_same_day($day, true) === array(), 'merge: a second run finds nothing to do');
foreach (array(
    array(7, 20, 4, 'death', 'death', 77, '2023-12-18', 'C1 was found unconscious from an overdose and later passed away.', '', '', 'approved'),
    array(8, 21, 3, 'death', 'death', 77, '2023-12-18', 'C1 was found unconscious from an overdose and later passed away.', '', '', 'approved'),
    array(9, 22, 5, 'death', 'death', 90, '2024-03-01', 'A child died at one home.', '', '', 'approved'),
    array(10, 23, 6, 'death', 'death', 90, '2024-03-01', 'A child died at another home.', '', '', 'approved'),
) as $r) {
    $ins->execute(array($r[0], $r[1], $r[2], 'k' . $r[0], 'h' . $r[0], $r[3], $r[4], $r[5], $r[6], $r[7], $r[8], $r[9], $r[10]));
}
check(kop_ih_facility_twins($day, 4) === array(3, 4) && kop_ih_facility_twins($day, 1) === array(1), 'merge: records sharing a license number are one facility');
$lic = kop_ih_merge_same_day($day, true);
check(count($lic) === 1 && $lic[0]['keep'] === 8 && $lic[0]['identical'] === array(7), 'merge: the same finding under a second, number-named record goes; the named record keeps it');
check((int) $day->query('SELECT COUNT(*) FROM inspection_highlights WHERE id IN (9, 10)')->fetchColumn() === 2, 'merge: two homes that only share a name are not merged');
check(kop_ih_same_day_covers($day, 4, '2023-12-18', 'C1 was found unconscious from an overdose and later passed away.'), 'merge: a rescan under the other record does not queue it again');
check(kop_ih_same_day_covers($day, 1, '2024-01-05', 'A child in care self-harmed with staff aware.')
    && !kop_ih_same_day_covers($day, 1, '2024-01-05', 'Something new happened.')
    && !kop_ih_same_day_covers($day, 1, null, 'Staff punched a child.'), 'merge: a rescan does not queue a finding already folded in');

// Oklahoma (ok_scraper.py): structured items, no document text.
$ok_row = function (array $cats) { return array('categories_json' => json_encode($cats), 'raw_content' => '', 'report_date' => '2025-06-09'); };
$c = kop_ih_candidates('OK', $ok_row(array('kind' => 'complaint', 'visit_type' => '', 'purpose' => '', 'items' => array(
    array('requirement' => '340:110-3-154.2(b)(1)', 'description' => 'behaviors that could cause physical pain, such as shaking, striking ...', 'observed' => 'Behavior Management: Staff member punched a resident in the nose, causing injury.', 'plan' => '', 'finding' => 'Substantiated'),
    array('requirement' => '340:110-3-153.1(b)(2)', 'description' => 'Program director.', 'observed' => 'Additional Non-Compliance Found During Investigation: Personnel: Program director failed to file a report.', 'plan' => '', 'finding' => 'Determined During Course of Investigation'),
))));
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && $c[0]['score'] === 80 && $c[0]['kind'] === 'complaint', 'OK: a substantiated complaint keeps the full score');
check($c && $c[0]['state_label'] === 'Substantiated complaint' && strpos($c[0]['standard'], '340:110-3-154.2(b)(1)') === 0, 'OK: label and requirement');
$visit = array('kind' => 'visit', 'visit_type' => 'Full', 'purpose' => 'Periodic', 'items' => array(
    array('requirement' => '340:110-3-153(a)', 'description' => 'Supervision.', 'observed' => 'Program failed to supervise a resident who was taken to the emergency room on 3-16-24.', 'nrs' => false),
));
$c = kop_ih_candidates('OK', $ok_row($visit));
check(count($c) === 1 && $c[0]['score'] === 47 && $c[0]['state_label'] === 'Non-compliance cited at a monitoring visit', 'OK: an ordinary visit item scores as a citation');
$visit['items'][0]['nrs'] = true;
$c = kop_ih_candidates('OK', $ok_row($visit));
check(count($c) === 1 && $c[0]['score'] === 55 && strpos($c[0]['state_label'], 'numerous, repeated or serious') !== false, 'OK: an NRS item keeps the full score');
check(kop_ih_candidates('OK', $ok_row(array('kind' => 'visit', 'items' => array()))) === array(), 'OK: a visit with nothing found has no candidate');
// Owner rule, 2026-10-05: a citation for paperwork only is not queued, whatever it mentions.
$late = array('kind' => 'visit', 'visit_type' => 'Full', 'purpose' => 'Periodic', 'items' => array(
    array('requirement' => '340:110-3-152(f)', 'description' => 'Notifications.', 'observed' => 'Program failed to notify licensing of a resident taken to the emergency room on 3-16-24.', 'nrs' => true),
));
check(kop_ih_candidates('OK', $ok_row($late)) === array(), 'OK: a late notification of an ER visit is paperwork only');
check(kop_ih_document_url('http://residentialchildplacingview.okdhs.org/ResidentialView/ResidentialView.aspx?CaseNumber=K850052676', '{}') === ''
    && kop_ih_document_url('https://example.org/report.pdf', '{}') === 'https://example.org/report.pdf', 'a facility page is not a report\'s own document');

// New Hampshire (nh_scraper.py): items are the rules not met; only the coordinator's observations are read.
$nh = array('report_date' => '2025-03-04', 'raw_content' => '', 'categories_json' => json_encode(array(
    'visit_type' => 'Licensed Complaint Visit', 'is_complaint' => true, 'items' => array(
        array('rule' => 'He-C 4001.15(ag)', 'rule_text' => 'Staff shall not use physical punishment.', 'result' => 'Non-Compliant', 'high_risk' => true,
            'observations' => 'Staff A slapped Resident A across the face during an argument in the dining room.', 'directed_cap' => 'Staff A shall be retrained.', 'corrective_action_plan' => 'We retrained staff.'),
        array('rule' => 'He-C 4001.07(c)', 'rule_text' => 'Records.', 'result' => 'Founded, Problem Resolved', 'high_risk' => false,
            'observations' => 'The program failed to notify the department within 24 hours that Resident B was taken to the emergency room.'),
        array('rule' => 'He-C 4001.09', 'result' => 'Non-Compliant', 'observations' => ''),
    ))));
$f = kop_ih_extract('NH', $nh);
check(count($f) === 2 && strpos($f[0]['text'], 'Staff A slapped') === 0 && $f[0]['factor'] === 1.0, 'NH: observations only, a high-risk rule at full weight');
check($f && $f[1]['kind'] === 'complaint' && $f[1]['corrected_on_site'] === true, 'NH: founded and resolved is a complaint put right');
$c = kop_ih_candidates('NH', $nh);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && strpos($c[0]['state_label'], 'high-risk rule') !== false, 'NH: the slap is queued, the late notice is not');

// Wyoming (wy_scraper.py): a DFS notice counts only when the evidence supports it; a WDH survey tag's evidence.
$wy = static function (array $cats) { return array('report_date' => '2024-05-01', 'raw_content' => '', 'categories_json' => json_encode($cats)); };
$c = kop_ih_candidates('WY', $wy(array('source' => 'DFS', 'kind' => 'notice', 'non_compliance' => true,
    'allegation' => 'It was reported that a staff member choked a youth during a restraint, leaving bruises on his neck.',
    'rules' => array(array('chapter' => '6', 'section' => '12', 'title' => 'Discipline')))));
check(count($c) === 1 && $c[0]['kind'] === 'complaint' && $c[0]['score'] === 85, 'WY: a supported notice is the confirmed allegation');
check(kop_ih_candidates('WY', $wy(array('source' => 'DFS', 'kind' => 'notice', 'non_compliance' => false,
    'allegation' => 'It was reported that a staff member choked a youth.'))) === array(), 'WY: a notice the evidence did not support is not queued');
check(kop_ih_extract('WY', $wy(array('source' => 'DFS', 'kind' => 'visit'))) === array(), 'WY: handwritten visits have nothing to read');
$f = kop_ih_extract('WY', $wy(array('source' => 'WDH', 'kind' => 'survey', 'survey_type' => 'Survey', 'complaint_intakes' => array('WY00123456'), 'tags' => array(
    array('tag' => 'N 137', 'title' => 'Restraint and seclusion', 'regulation' => '', 'evidence' => '483.356 Each facility must ... This STANDARD is not met as evidenced by: Based on record review, staff placed Resident 1 in a prone restraint and the resident sustained a fractured wrist.'),
))));
check(count($f) === 1 && strpos($f[0]['text'], 'Based on record review') === 0 && $f[0]['factor'] === 1.0 && strpos($f[0]['state_label'], 'complaint survey') !== false,
    'WY: an unsplit survey tag is cut at its evidence; a complaint intake makes it an investigation');

// Idaho (id_scraper.py): the surveyor's finding, never the plan.
$id = array('report_date' => '4/10/2024', 'raw_content' => '', 'categories_json' => json_encode(array('kind' => 'deficiencies', 'risk_assessment' => 'Bronze', 'license_granted' => '1-Year',
    'deficiencies' => array(array('rule' => '16.04.18.411.02.b', 'rule_text' => 'Supervision.', 'finding' => 'This is a repeat deficiency. Staff failed to supervise Resident 3, who ran away and was struck by a car, suffering a broken leg.', 'plan' => 'We will retrain staff.', 'repeat' => true)))));
$f = kop_ih_extract('ID', $id);
check(count($f) === 1 && strpos($f[0]['text'], 'Staff failed') === 0 && $f[0]['factor'] === 1.0 && strpos($f[0]['state_label'], 'repeat deficiency') !== false, 'ID: repeat note dropped from the text, full weight');
check(count(kop_ih_candidates('ID', $id)) === 1, 'ID: a runaway with a broken leg is queued');
check(kop_ih_extract('ID', array('categories_json' => json_encode(array('kind' => 'no_deficiencies')))) === array(), 'ID: a no-deficiency letter has nothing');

// Maine (me_scraper.py): adult programs and rule wording are left out.
$me = array('report_date' => '2025-01-10', 'raw_content' => '', 'categories_json' => json_encode(array('inspection_type' => 'Full Agency Survey', 'is_complaint' => true, 'deficiencies' => array(
    array('section' => 'SECTION 7. CLIENT RIGHTS', 'finding' => 'Finding: The agency must protect clients. Based on interview, a staff member pushed a youth into a wall, injuring the youth.'),
    array('section' => 'SECTION 9', 'finding' => 'Based on record review, two adults in the outpatient program were not seen by a prescriber.'),
))));
$f = kop_ih_extract('ME', $me);
check(count($f) === 1 && strpos($f[0]['text'], 'Based on interview') === 0 && $f[0]['factor'] === 1.0, 'ME: rule wording and an adults-only finding are dropped');
check(kop_ih_extract('ME', array('categories_json' => json_encode(array('adult_program' => true, 'deficiencies' => array(array('finding' => str_repeat('Staff hit a client. ', 5))))))) === array(), 'ME: an adult program is skipped');

// Ohio (oh_scraper.py): residential findings, one per specialist's comment.
$oh = array('report_date' => '2025-08-01', 'raw_content' => '', 'categories_json' => json_encode(array('review_type' => 'Full', 'findings' => array(
    array('residential' => true, 'question' => '14. Did staff use only approved restraint techniques', 'rule' => '5180:2-9-04(C)', 'cap_needed' => true,
        'comments' => array(array('record' => 'Record 2', 'comment' => 'Staff used a prone restraint and the youth sustained a bloody nose.'), array('record' => 'Record 3', 'comment' => 'Staff used a prone restraint and the youth sustained a bloody nose.'))),
    array('residential' => false, 'question' => '3. Foster home study', 'comments' => array(array('comment' => 'The foster parent hit the child with a belt, causing bruises.'))),
    array('residential' => true, 'question' => '32. If due during the review period, is there documentation of the incident report', 'rule' => '5180:2-9-42(B)(9)', 'cap_needed' => true,
        'comments' => array(array('comment' => 'The incident report for the youth taken to the emergency room was not completed.'))),
))));
$f = kop_ih_extract('OH', $oh);
check(count($f) === 2 && $f[0]['factor'] === 0.85, 'OH: residential only, one finding per distinct comment');
$c = kop_ih_candidates('OH', $oh);
check(count($c) === 1 && $c[0]['category'] === 'restraint_injury', 'OH: the restraint injury is queued, the missing incident report is not');

// West Virginia (wv_scraper.py): the full finding from detail, corrected tags skipped, the federal severity letter.
$wv = array('report_date' => '2024-11-12', 'raw_content' => '', 'categories_json' => json_encode(array('survey_type' => 'Complaint Survey', 'is_complaint' => true,
    'tags' => array(
        array('tag' => 'F 0156', 'regulation' => 'Abuse', 'scope' => 'SS=G', 'finding' => 'short', 'corrected' => false),
        array('tag' => 'N 0127', 'regulation' => 'Supervision', 'scope' => '', 'finding' => 'short', 'corrected' => true),
    ),
    'detail' => array('tags' => array(
        array('tag' => 'F 0156', 'regulation' => 'Abuse 483.13 The facility must protect residents.', 'finding' => 'Based on interview and video review, a mental health technician punched Resident #4 in the face.'),
        array('tag' => 'N 0127', 'regulation' => 'Supervision', 'finding' => 'Based on record review, a resident went missing.'),
    )))));
$f = kop_ih_extract('WV', $wv);
check(count($f) === 1 && $f[0]['factor'] === 1.0 && strpos($f[0]['state_label'], 'severity G') !== false, 'WV: a corrected tag is skipped; severity G is full weight');

// Iowa (ia_scraper.py): the full finding from detail; E tags and the rule wording left out.
$ia = array('report_date' => '2024-02-02', 'raw_content' => '', 'categories_json' => json_encode(array('visit_type' => 'Recertification, Complaint', 'complaint_numbers' => array('130840-C'),
    'tags' => array(
        array('tag' => 'N 145', 'regulation' => '483.358(f) ORDERS FOR USE OF RESTRAINT OR SECLUSION', 'finding' => 'cut'),
        array('tag' => 'E 0001', 'regulation' => '483.475 EMERGENCY PREPAREDNESS', 'finding' => 'Based on record review, the facility had no emergency plan for residents.'),
    ),
    'detail' => array('tags' => array(
        array('requirement' => 'Each order must ...', 'finding' => 'Based on record review and staff interview, staff held Resident 2 in a prone restraint and Resident 2 sustained a fractured collarbone.'),
        array('requirement' => '', 'finding' => 'Based on record review, the facility had no emergency plan for residents.'),
    )))));
$f = kop_ih_extract('IA', $ia);
check(count($f) === 1 && $f[0]['factor'] === 1.0 && strpos($f[0]['standard'], 'N 145') === 0, 'IA: one N tag, complaint numbers make it an investigation');
$c = kop_ih_candidates('IA', $ia);
check(count($c) === 1 && $c[0]['category'] === 'restraint_injury', 'IA: the restraint injury is queued');

// Maryland (md_scraper.py): one-line comments, weighted by the state's own safety rating.
$md = array('report_date' => '2023-06-01', 'raw_content' => '', 'categories_json' => json_encode(array('inspection_type' => 'Quarterly',
    'safety_citations' => array(array('site' => 'Main', 'citation' => '[07.05.01.10C(2)', 'comment' => 'Staff member slapped a youth during a verbal altercation.', 'status' => 'CAP')),
    'other_citations' => array(array('site' => 'Main', 'citation' => '07.05.01.15', 'comment' => 'Two smoke detectors lacked batteries.', 'status' => 'Resolved')),
)));
$f = kop_ih_extract('MD', $md);
check(count($f) === 2 && $f[0]['factor'] === 1.0 && $f[1]['factor'] === 0.7 && strpos($f[0]['standard'], 'COMAR 07.05') === 0, 'MD: both blocks, the safety block at full weight');
check(count(kop_ih_candidates('MD', $md)) === 1, 'MD: only the slap is queued');

// South Dakota (sd_scraper.py): only corrective action plans, never compliance (fire, health) plans.
$sd = static function (array $cats) { return array('report_date' => '2025-01-01', 'raw_content' => '', 'categories_json' => json_encode($cats)); };
$items = array(array('rule' => '67:42:07:15', 'finding' => 'A staff member kicked a resident in the leg while escorting him to his room.', 'corrective_action' => 'Staff terminated.'));
check(count(kop_ih_extract('SD', $sd(array('kind' => 'corrective_action_plan', 'plan_type' => 'corrective_action', 'items' => $items)))) === 1, 'SD: a corrective action plan finding is read');
check(kop_ih_extract('SD', $sd(array('kind' => 'corrective_action_plan', 'plan_type' => 'compliance', 'items' => $items))) === array(), 'SD: a fire or health compliance plan is not');
check(kop_ih_extract('SD', $sd(array('kind' => 'licensing_study', 'items' => $items))) === array(), 'SD: a licensing study is not');

// Virginia (va_scraper.py): VDSS violations; DBHDS rows rated N or NS, never C or ND or a "No Violation" plan.
$va = static function (array $cats) { return array('report_date' => '2024-09-09', 'raw_content' => '', 'categories_json' => json_encode($cats)); };
$f = kop_ih_extract('VA', $va(array('source' => 'vdss', 'complaint_related' => true, 'comments' => 'Complaint alleged staff hit a child.',
    'violations' => array(array('standard' => '22VAC40-151-680', 'description' => 'The facility did not protect a resident from harm.', 'findings' => 'Staff #1 pushed Resident #3 to the floor, causing a cut above the eye.', 'plan' => 'Retrain.')))));
check(count($f) === 1 && $f[0]['factor'] === 1.0 && strpos($f[0]['text'], 'Retrain') === false && strpos($f[0]['text'], 'Complaint alleged') === false, 'VA: VDSS reads description and findings, not plan or comments');
$dbhds = array('source' => 'dbhds', 'kind' => 'inspection', 'purpose' => 'Unannounced',
    'citations' => array(array('standard' => '12VAC35-46-1000', 'comp' => 'NS N'), array('standard' => '12VAC35-46-320', 'comp' => 'C'), array('standard' => '12VAC35-46-330', 'comp' => 'ND')),
    'detail' => array('citations' => array(
        array('noncompliance' => 'Unit 2 This regulation was NOT MET as evidenced by: Staff used a prone restraint on a resident who then sustained a bloody nose.'),
        array('noncompliance' => 'Staff hit a resident.'), array('noncompliance' => 'Staff hit a resident.'))));
$f = kop_ih_extract('VA', $va($dbhds));
check(count($f) === 1 && $f[0]['factor'] === 1.0 && strpos($f[0]['text'], 'Staff used') === 0 && strpos($f[0]['state_label'], 'systemic') !== false, 'VA: DBHDS NS row only, cut after NOT MET');
$dbhds['no_violation'] = true;
check(kop_ih_extract('VA', $va($dbhds)) === array(), 'VA: a "No Violation" plan has no findings');

// Michigan (mi_scraper.py): an analysis counts only under an established conclusion.
$mi_raw = "ALLEGATION: Staff 1 hit Youth A.\nINVESTIGATION: Youth A said Staff 1 hit him.\nANALYSIS: Video review showed Staff 1 struck Youth A in the face with a closed fist during an argument.\nCONCLUSION: VIOLATION ESTABLISHED\n"
    . "ALLEGATION: Staff 2 restrained Youth B.\nANALYSIS: There was no evidence that Staff 2 restrained Youth B, who was taken to the hospital for an unrelated illness.\nCONCLUSION: VIOLATION NOT ESTABLISHED\n";
$mi = array('report_date' => '2024-03-03', 'raw_content' => $mi_raw, 'categories_json' => json_encode(array('doc_type' => 'special_investigation',
    'allegations' => array(array('rule' => 'CCI Rule 400.4159', 'rule_title' => 'Resident restraint', 'conclusion' => 'Violation Established'), array('rule' => 'CCI Rule 400.4159', 'conclusion' => 'Violation Not Established')))));
$f = kop_ih_extract('MI', $mi);
check(count($f) === 1 && strpos($f[0]['text'], 'Video review') === 0 && $f[0]['kind'] === 'complaint' && strpos($f[0]['standard'], 'CCI Rule 400.4159') === 0, 'MI: only the established analysis, with its rule');
$c = kop_ih_candidates('MI', $mi);
check(count($c) === 1 && $c[0]['category'] === 'physical_abuse' && $c[0]['score'] === 80, 'MI: the established assault is queued at full weight');
check(kop_ih_extract('MI', array('raw_content' => $mi_raw, 'categories_json' => json_encode(array('doc_type' => 'renewal')))) === array(), 'MI: inspections are not read');

// Pennsylvania (pa_scraper.py): the full violation from detail; only documents that count; no table-form scans.
$pa = array('report_date' => '2023-05-05', 'raw_content' => '', 'categories_json' => json_encode(array('kind' => 'citation', 'counts_as_violation' => true, 'inspection_type' => 'Complaint',
    'citations' => array(array('regulation' => '3800.32.d', 'title' => 'Protection from abuse', 'violation' => 'short…', 'repeat' => false)),
    'detail' => array('citations' => array(array('violation' => 'On a date redacted, staff person A dragged child #1 down the hallway by the arm, leaving bruises on the child\'s forearm.'))))));
$f = kop_ih_extract('PA', $pa);
check(count($f) === 1 && strpos($f[0]['text'], 'dragged') !== false && $f[0]['factor'] === 1.0 && strpos($f[0]['standard'], '55 Pa. Code § 3800.32.d') === 0, 'PA: the full violation from detail, a complaint inspection at full weight');
$d = json_decode($pa['categories_json'], true);
$d['form'] = 'table';
check(kop_ih_extract('PA', array('categories_json' => json_encode($d))) === array(), 'PA: a table-form scan is left out');
unset($d['form'], $d['counts_as_violation']);
check(kop_ih_extract('PA', array('categories_json' => json_encode($d))) === array(), 'PA: a document the page does not count is left out');
check(!in_array('NV', kop_ih_supported_states(), true) && !in_array('OR', kop_ih_supported_states(), true) && count(kop_ih_supported_states()) === 24, 'states: 24 supported, Nevada and Oregon left out');

// Montana (js/data/mt_reports.json, copied in by api/lib-mt-reports.php): findings passages, never the plan.
$mt = static function (array $survey) { return array('report_date' => '09/14/2018', 'raw_content' => '', 'categories_json' => json_encode($survey)); };
$survey = array('source_file' => 'x.txt', 'Header' => array('Facility' => 'Test Ranch', 'Survey Type' => 'Complaint Inspection', 'Survey Date' => '09/14/2018',
    'Description' => 'THE INTENT OF THIS RULE HAS NOT BEEN MET, AS EVIDENCED BY: The surveyor’s review of records. Findings: 1) Staff #1 did not provide appropriate supervision to residents #1 and #2. Resident #2 was discovered by a person in the community unconscious on the sidewalk.'),
    'Issues' => array(
        array('Rule' => '37.97.154-1 YOUTH CARE FACILITY (YCF): CARE AND GUID', 'Findings' => '1) Staff #1 did not provide appropriate supervision to residents #1 and #2. Resident #2 was discovered by a person in the community unconscious on the sidewalk.', 'Repeat Deficiency' => false),
        array('Rule' => 'x', 'Findings' => 'Findings: The facility did not provide a copy of the written report as described in MCA 53-21-107(7)(a) through (7)(e) within 5 working days of the completion of the investigation to the departments Licensure Bureau regarding Resident #3’s selfharm/suicide attempt on 7/15/2018.'),
        array('Rule' => 'y', 'Findings' => '1) Participant #4 missed medication on 5/1/20 through 5/15/20 with no documentation of the error PROVIDERS PLAN OF CORRECTION: 1) Amend current MAR to show each dose. Findings: 2) Staff #2 restrained Youth #1 in a prone hold, causing a bloody nose.'),
    ));
$f = kop_ih_extract('MT', $mt($survey));
check(count($f) === 5 && $f[0]['factor'] === 1.0 && $f[0]['standard'] === '', 'MT: each distinct passage once (the Description repeat read once), a complaint at full weight; got ' . count($f));
check(!array_filter($f, static function ($x) { return stripos($x['text'], 'Amend current MAR') !== false; }), 'MT: the program\'s plan of correction is cut out');
$cats = array_map(static function ($c) { return $c['category']; }, kop_ih_candidates('MT', $mt($survey)));
sort($cats);
check($cats === array('medical_neglect', 'restraint_injury'), 'MT: the restraint and the missed doses are queued, the late written report is not; got ' . implode(',', $cats));

// The importer: Montana's surveys into the database, idempotent.
require_once dirname(__DIR__) . '/api/lib-mt-reports.php';
$mtdb = new PDO('sqlite::memory:');
$mtdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$mtdb->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT, full_address TEXT, phone TEXT, program_category TEXT, program_name TEXT, executive_director TEXT, scraped_timestamp TEXT)');
$mtdb->exec('CREATE TABLE inspection_reports (id INTEGER PRIMARY KEY, facility_id INT, report_id TEXT, report_date TEXT, report_url TEXT, raw_content TEXT, content_length INT, is_structured INT, summary TEXT, categories_json TEXT)');
$dry = kop_mt_reports_import($mtdb, false);
check($dry['added'] > 250 && (int) $mtdb->query('SELECT COUNT(*) FROM inspection_reports')->fetchColumn() === 0, 'MT import: a dry run counts and writes nothing');
$first = kop_mt_reports_import($mtdb, true);
$again = kop_mt_reports_import($mtdb, true);
check($first['added'] === $dry['added'] && $again['added'] === 0 && $again['updated'] === 0 && $again['unchanged'] === $first['added'], 'MT import: a second run changes nothing');
check((int) $mtdb->query("SELECT COUNT(*) FROM inspection_facilities WHERE state = 'MT'")->fetchColumn() === $first['facilities'], 'MT import: one facility per program name');
$mt_scan = kop_ih_scan($mtdb, 1000, false, array('MT'));
check($mt_scan['scanned'] === $first['added'] && count($mt_scan['candidates']) >= 5, 'MT: the copied surveys are scanned; ' . count($mt_scan['candidates']) . ' candidates');

// Versions per state: a report is rescanned only when its own state's rules moved.
check(kop_ih_scanner_version('TX') === kop_ih_scanner_version() + ((kop_ih_state_rule_versions()['TX'] ?? 0)), 'versions: a state without a bump scans with the base version');
$vdb = new PDO('sqlite::memory:');
$vdb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$vdb->exec('CREATE TABLE inspection_facilities (id INTEGER PRIMARY KEY, state TEXT, facility_name TEXT)');
$vdb->exec('CREATE TABLE inspection_reports (id INTEGER PRIMARY KEY, facility_id INT, report_id TEXT, report_date TEXT, categories_json TEXT, raw_content TEXT)');
$vdb->exec("INSERT INTO inspection_facilities VALUES (1, 'TX', 'A'), (2, 'PA', 'B')");
$vrow = $vdb->prepare('INSERT INTO inspection_reports (facility_id, report_id, report_date, categories_json, raw_content) VALUES (?, ?, ?, ?, ?)');
$vrow->execute(array(1, 't1', '2024-01-01', json_encode(array('Standard Risk Level' => 'High', 'Deficiency Narrative' => 'Staff punched a resident in the face.')), ''));
$vrow->execute(array(2, 'p1', '2024-01-01', json_encode(array('counts_as_violation' => true, 'citations' => array(array('regulation' => '3800.32', 'violation' => 'Staff person A slapped child #1 across the face.')))), ''));
$first = kop_ih_scan($vdb, 100, true, array('TX', 'PA'));
$again = kop_ih_scan($vdb, 100, true, array('TX', 'PA'));
check($first['scanned'] === 2 && $again['scanned'] === 0, 'versions: a second run reads nothing');
// As if Pennsylvania's rules had been bumped: its report carries an older version than PA now scans with.
$vdb->exec('UPDATE inspection_highlight_scans SET scanner_version = scanner_version - 1 WHERE report_id = 2');
$bumped = kop_ih_scan($vdb, 100, true, array('TX', 'PA'));
check($bumped['scanned'] === 1 && $bumped['remaining'] === 0, 'versions: only the report of the state whose rules moved is read again');
check((int) $vdb->query('SELECT scanner_version FROM inspection_highlight_scans WHERE report_id = 2')->fetchColumn() === kop_ih_scanner_version('PA'), 'versions: it is marked with its state\'s version');

echo "Rules: $checks checks, $failures failed.\n";

// ---------------------------------------------------------------------------
// Part 2: the mirror
// ---------------------------------------------------------------------------

if (!file_exists($db_path)) {
    echo "No mirror at $db_path (run scripts/sync-prod-sqlite.py); skipping the dry run.\n";
    exit($failures ? 1 : 0);
}

$pdo = new PDO('sqlite:file:' . str_replace('\\', '/', realpath($db_path)) . '?mode=ro', null, null, array(PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY));
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$started = microtime(true);
$dry = kop_ih_scan($pdo, 1000000, false, array(), true);
$seconds = round(microtime(true) - $started, 1);
$cands = $dry['candidates'];
usort($cands, static function ($a, $b) { return $b['score'] <=> $a['score'] ?: $b['report_row'] <=> $a['report_row']; });

$by_state = array(); $by_cat = array(); $by_band = array('90-100' => 0, '70-89' => 0, '50-69' => 0, '30-49' => 0);
$labels = array();
foreach ($cands as $c) {
    $by_state[$c['state']] = ($by_state[$c['state']] ?? 0) + 1;
    $by_cat[$c['category']] = ($by_cat[$c['category']] ?? 0) + 1;
    $labels[$c['state'] . ' / ' . $c['state_label']] = ($labels[$c['state'] . ' / ' . $c['state_label']] ?? 0) + 1;
    $band = $c['score'] >= 90 ? '90-100' : ($c['score'] >= 70 ? '70-89' : ($c['score'] >= 50 ? '50-69' : '30-49'));
    $by_band[$band]++;
    check(trim($c['excerpt']) !== '' && strlen($c['excerpt']) <= 4000, 'mirror: excerpt present and bounded (report ' . $c['report_row'] . ')');
    check(!preg_match('/SUPERVISORS NAME|Estimated Days of Completion/', $c['excerpt']), 'mirror: no form boilerplate in excerpt (report ' . $c['report_row'] . ')');
    // SQLite ignores column widths; MySQL refuses the row (state_label is varchar(120)).
    check(mb_strlen($c['state_label']) <= 120, 'mirror: state label fits varchar(120) (report ' . $c['report_row'] . ', ' . mb_strlen($c['state_label']) . ' chars)');
}
arsort($by_cat); arsort($labels);

$lines = array();
$lines[] = "Scanned {$dry['scanned']} reports in {$seconds}s; " . count($cands) . ' candidates at score ' . kop_ih_min_score() . ' or more.';
$lines[] = 'By state: ' . json_encode($by_state);
$lines[] = 'By score: ' . json_encode($by_band);
$lines[] = 'By worst category: ' . json_encode($by_cat);
$lines[] = 'By state signal: ' . json_encode($labels);
echo implode("\n", $lines) . "\n";

if ($report_path !== '') {
    $cats = kop_ih_categories();
    $md = "# Inspection highlights: dry run against the mirror\n\n" . implode("\n\n", $lines) . "\n";
    $md .= "\n## Top $samples per category\n";
    foreach (array_keys($cats) as $key) {
        $md .= "\n### {$cats[$key]['label']}\n\n";
        $n = 0;
        foreach ($cands as $c) {
            if ($c['category'] !== $key) continue;
            $md .= "- **{$c['score']}** {$c['state']} | {$c['facility_name']} | {$c['report_date']} | {$c['state_label']} | report row {$c['report_row']}"
                . ($c['corrected_on_site'] ? ' | corrected on site' : '') . "\n  > " . $c['excerpt'] . "\n";
            if (++$n >= $samples) break;
        }
    }
    // A random slice as well, so the weak end of the queue is visible too.
    mt_srand(14);
    $md .= "\n## Random $samples from the whole queue\n\n";
    $keys = $cands ? (array) array_rand($cands, min($samples * 3, count($cands))) : array();
    foreach ($keys as $k) {
        $c = $cands[$k];
        $md .= "- **{$c['score']}** {$c['category']} | {$c['state']} | {$c['facility_name']} | {$c['state_label']} | report row {$c['report_row']}\n  > " . $c['excerpt'] . "\n";
    }
    file_put_contents($report_path, $md);
    echo "Wrote $report_path\n";
}

echo "Total: $checks checks, $failures failed.\n";
exit($failures ? 1 : 0);
