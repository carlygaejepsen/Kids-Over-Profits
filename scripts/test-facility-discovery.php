<?php
/**
 * Offline checks for facilities found in the news (inc/facility-discovery.php)
 * against the production mirror in tmp/prod.sqlite.
 *
 *   php scripts/test-facility-discovery.php [--db=tmp/prod.sqlite]
 *
 * No Groq calls: the model's reply is faked. The new record is built and
 * validated but not saved (the writes need MySQL locks).
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
if (!file_exists($db_path)) {
    fwrite(STDERR, "No mirror at $db_path (run scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
require_once dirname(__DIR__) . '/inc/closure-reports.php';
require_once dirname(__DIR__) . '/inc/facility-discovery.php';
require_once dirname(__DIR__) . '/inc/indigenous-schools.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

// ---------------------------------------------------------------------------
echo "-- Names the scan would ask about --\n";
$index = kop_facdisc_alias_index($pdo);
$articles = 0;
$names = array();
foreach ($pdo->query("SELECT id, facilities_mentioned FROM news_submissions WHERE status NOT IN ('rejected','deleted','promotional')") as $r) {
    $r['known'] = array();
    $u = kop_facdisc_unresolved($pdo, $r, $index);
    if ($u) $articles++;
    foreach ($u as $n) $names[kop_normalize_name_key($n)] = $n;
}
printf("  %d articles carry %d distinct names with no record\n", $articles, count($names));
$check('there are names to ask about', count($names) > 0);

$news = array('facilities_mentioned' => json_encode(array(
    array('name' => 'Provo Canyon School', 'facility_id' => null),
    array('name' => 'Silver Oak Academy', 'facility_id' => null),
    array('name' => 'silver oak academy', 'facility_id' => null),
    array('name' => 'Linked Already', 'facility_id' => 12),
)), 'closure_programs' => array('Three Points Ranch'), 'known' => array());
$u = kop_facdisc_unresolved($pdo, $news, $index);
$check('known names, linked names and repeats are left out; closure programs are added',
    $u === array('Silver Oak Academy', 'Three Points Ranch'), implode(', ', $u));
$news['known'] = array(kop_normalize_name_key('Silver Oak Academy') => array('decision' => 'created', 'facility_id' => 5));
$decided = array();
$u = kop_facdisc_unresolved($pdo, $news, $index, $decided);
$check('a name decided before is not asked again, and its decision comes back', $u === array('Three Points Ranch') && count($decided) === 1);

$look = kop_facdisc_lookalikes($pdo, 'Ashville Academy for Girls', $index);
$look_names = array_column($look, 'unique_name');
echo '  look-alikes for "Ashville Academy for Girls": ' . implode(' | ', $look_names) . "\n";
$check('a misspelled name finds the right record among its look-alikes',
    (bool) array_filter($look_names, static function ($n) { return stripos($n, 'Asheville Academy') !== false; }));
$check('a near-identical name in the same state is caught before a create',
    kop_facdisc_near_duplicate($pdo, 'Ashville Academy for Girls', 'NC') === 11656);
$check('and the same name in another state is not', kop_facdisc_near_duplicate($pdo, 'Ashville Academy for Girls', 'MT') === null);
$check('a genuinely new name is not a near duplicate', kop_facdisc_near_duplicate($pdo, 'Silver Oak Academy', 'MD', 'Keymar') === null);
$check('a program whose campuses are on record is held ("Maple Lake Academy")',
    in_array(kop_facdisc_near_duplicate($pdo, 'Maple Lake Academy', 'UT', 'Spanish Fork'), array(10475, 10476), true));
$check('a same-city name sharing a distinctive word is held ("Three Points Ranch", Hurricane)',
    kop_facdisc_near_duplicate($pdo, 'Three Points Ranch', 'UT', 'Hurricane') === 10545);
$check('a campus named differently in the same town is held ("Maple Lake Academy - Girls Program")',
    in_array(kop_facdisc_near_duplicate($pdo, 'Maple Lake Academy – Girls Program', 'UT', 'Spanish Fork'), array(10475, 10476), true));
$check('an acronym of a same-state record is held ("Camp SAYLA")', kop_facdisc_near_duplicate($pdo, 'Camp SAYLA', 'AL', '') === 13694);
$check('the same acronym in another state is not', kop_facdisc_near_duplicate($pdo, 'Camp SAYLA', 'GA', '') === null);
$check('generic words alone do not make a duplicate', kop_facdisc_near_duplicate($pdo, 'Spanish Fork Girls Academy Treatment Program', 'UT', 'Provo') === null);
$check('the same words in another city are not', kop_facdisc_near_duplicate($pdo, 'Three Points Ranch', 'UT', 'Provo') === null);
$check('new programs from the dry run stay new', kop_facdisc_near_duplicate($pdo, 'Zion Hills Academy', 'UT', '') === null
    && kop_facdisc_near_duplicate($pdo, "Walker's Point Youth and Family Center", 'WI', 'Milwaukee') === null);
$check('a name of stopwords only has no look-alikes', kop_facdisc_lookalikes($pdo, 'Youth Academy Center', $index) === array());

$check('agencies, police, courts, colleges and jails are decided without Groq',
    kop_facdisc_obvious_kind('Oregon State Police') === 'organization' && kop_facdisc_obvious_kind('Pinellas Technical College') === 'organization'
    && kop_facdisc_obvious_kind('Tillamook County Jail') === 'organization' && kop_facdisc_obvious_kind("Tillamook County Sheriff's Office") === 'organization'
    && kop_facdisc_obvious_kind('Sheppard Pratt Hospital') === 'provider');
$check('programs still go to the model', kop_facdisc_obvious_kind('Silver Oak Academy') === null
    && kop_facdisc_obvious_kind('Knox County Juvenile Detention Center') === null && kop_facdisc_obvious_kind('Hillsborough Residential Hospital for Youth') === null);
$news['known'] = array(kop_normalize_name_key('Silver Oak Academy') => array('decision' => 'needs_place', 'facility_id' => null));
$check('a name held for want of a place is asked again', in_array('Silver Oak Academy', kop_facdisc_unresolved($pdo, $news, $index), true));

// ---------------------------------------------------------------------------
echo "-- Reading the model's reply --\n";
$asked = array('Silver Oak Academy', 'Hope unit', 'Ashville Academy for Girls', 'Maryland Department of Juvenile Services', 'Sheppard Pratt');
$lookalikes = array('Ashville Academy for Girls' => array(array('id' => 11656, 'unique_name' => 'Asheville Academy for Girls', 'city' => 'Black Mountain', 'state' => 'NC', 'country' => 'United States', 'status' => 'Closed')));
$reply = json_encode(array('names' => array(
    array('name' => 'Silver Oak Academy', 'kind' => 'facility', 'sameAs' => null, 'officialName' => 'Silver Oak Academy', 'otherNames' => array('SOA'),
        'city' => 'Keymar', 'state' => 'md', 'country' => 'United States', 'type' => 'Juvenile Justice RTC', 'status' => 'closed',
        'startYear' => 2009, 'endYear' => '2026', 'operator' => 'Rite of Passage', 'gender' => 'Male', 'evidence' => 'Silver Oak Academy in Keymar...'),
    array('name' => 'Hope unit', 'kind' => 'vague'),
    array('name' => 'Ashville Academy for Girls', 'kind' => 'facility', 'sameAs' => 11656),
    array('name' => 'Maryland Department of Juvenile Services', 'kind' => 'organization', 'sameAs' => 11656),
    array('name' => 'Sheppard Pratt', 'kind' => 'provider'),
    array('name' => 'Not Asked', 'kind' => 'facility'),
)));
$e = kop_facdisc_parse_reply('```json' . $reply . '```', $asked, $lookalikes);
$check('every name asked about comes back, and nothing else', array_keys($e) === $asked, implode(', ', array_keys($e)));
$s = $e['Silver Oak Academy'];
$check('fields cleaned: state upper-cased, status and years typed, type from the list',
    $s['state'] === 'MD' && $s['status'] === 'Closed' && $s['endYear'] === 2026 && $s['type'] === 'Juvenile Justice RTC' && $s['gender'] === 'Male');
$check('a same-as id from the name\'s own list is kept', $e['Ashville Academy for Girls']['sameAs'] === 11656);
$check('a same-as id from another name\'s list is dropped', $e['Maryland Department of Juvenile Services']['sameAs'] === null);
$e3 = kop_facdisc_parse_reply(json_encode(array('names' => array(array('name' => 'Walker Point', 'kind' => 'facility', 'status' => 'suspended')))), array('Walker Point'), array());
$check('a temporary closure is kept as Suspended', $e3['Walker Point']['status'] === 'Suspended');
$e4 = kop_facdisc_parse_reply(json_encode(array('names' => array(
    array('name' => 'Bethel Boys Academy', 'kind' => 'facility', 'sameAs' => null, 'renameOf' => 13927),
    array('name' => 'Eagle Point Academy', 'kind' => 'facility', 'sameAs' => 13927, 'renameOf' => 13927),
    array('name' => 'Gulf Coast', 'kind' => 'facility', 'renameOf' => 99)))),
    array('Bethel Boys Academy', 'Eagle Point Academy', 'Gulf Coast'),
    array('Bethel Boys Academy' => array(array('id' => 13927)), 'Eagle Point Academy' => array(array('id' => 13927)), 'Gulf Coast' => array(array('id' => 13927))));
$check('an earlier name is kept as another era of its record, not the same record',
    $e4['Bethel Boys Academy']['renameOf'] === 13927 && $e4['Bethel Boys Academy']['sameAs'] === null);
$check('same-as wins over rename; a rename id not offered is dropped',
    $e4['Eagle Point Academy']['renameOf'] === null && $e4['Gulf Coast']['renameOf'] === null);
$check('kinds kept', $e['Hope unit']['kind'] === 'vague' && $e['Sheppard Pratt']['kind'] === 'provider');
$check('no names array is unreadable', kop_facdisc_parse_reply('{"closures":[]}', $asked, array()) === null);
$e2 = kop_facdisc_parse_reply(json_encode(array('names' => array(array('name' => 'SILVER OAK ACADEMY (Maryland)', 'kind' => 'facility', 'type' => 'Prison')))), array('Silver Oak Academy'), array());
$check('a rewritten name falls back to the list order; an unknown type is dropped',
    isset($e2['Silver Oak Academy']) && $e2['Silver Oak Academy']['type'] === '');

// ---------------------------------------------------------------------------
echo "-- The new record --\n";
$article = array('id' => 502, 'article_title' => 'Maryland won\'t renew Silver Oak\'s license', 'publication_name' => 'The Baltimore Banner',
    'publication_date' => '2026-05-01', 'article_url' => 'https://example.org/silver-oak');
$doc = kop_facdisc_build_doc(array_merge($s, array('name' => 'Silver Oak')), $article);
$errors = array_filter(kop_facility_validate($doc), static function ($v) { return $v['severity'] === 'error'; });
$check('the built record passes the facility validator', !$errors, $errors ? json_encode(array_values($errors)[0]) : '');
$check('name, place, status, years, type and operator carried over',
    $doc['identification']['name'] === 'Silver Oak Academy' && $doc['location']['state'] === 'MD' && $doc['location']['city'] === 'Keymar'
    && $doc['operatingPeriod']['status'] === 'Closed' && $doc['operatingPeriod']['startYear'] === 2009 && $doc['operatingPeriod']['endYear'] === 2026
    && $doc['facilityDetails']['type'] === 'Juvenile Justice RTC' && ($doc['identification']['currentOperator'] ?? '') === 'Rite of Passage');
$check('the article\'s own spelling is kept as another name', in_array('Silver Oak', $doc['identification']['otherNames'], true)
    && in_array('SOA', $doc['identification']['otherNames'], true));
$check('the article is cited in the notes', (bool) array_filter($doc['notes'], static function ($n) { return strpos($n, 'https://example.org/silver-oak') !== false; }));
$check('provenance says where it came from', $doc['provenance']['source'] === 'news-discovery' && $doc['facility_id'] === null);
$check('the stored copy still matches after a JSON round trip (so Remove can tell it is untouched)',
    kop_facility_same_document(json_decode(wp_json_encode($doc), true), $doc));
$edited = $doc;
$edited['location']['city'] = 'Taneytown';
$check('and an edited record does not match', !kop_facility_same_document($doc, $edited));
$check('no record already has its name key in its state (the identity rule would match it)',
    !$pdo->query("SELECT 1 FROM facilities_v2 WHERE name_key = " . $pdo->quote(kop_facility_name_key('Silver Oak Academy')) . " AND state = 'MD'")->fetchColumn());
$foreign = kop_facdisc_build_doc(array_merge($s, array('name' => 'Brisbane Youth Detention Centre', 'officialName' => 'Brisbane Youth Detention Centre',
    'state' => '', 'country' => 'Australia', 'city' => 'Wacol', 'type' => 'Juvenile Detention Facility', 'otherNames' => array())), $article);
$check('a facility abroad keeps its country and no state', $foreign['location']['country'] === 'Australia' && $foreign['location']['state'] === null,
    json_encode(array($foreign['location']['country'], $foreign['location']['state'])));

// ---------------------------------------------------------------------------
echo "-- Only what the article says --\n";
$head = kop_facdisc_norm_text('Mingus Mountain Academy The requested web page could not be retrieved.');
$check('a name in the title is in the source', kop_facdisc_in_source('Mingus Mountain Academy', $head));
$check('a town the page only listed is not', !kop_facdisc_in_source('Kissimmee', $head));
$check('whole words only ("Madison" is not in "Madisonville")', !kop_facdisc_in_source('Madison', kop_facdisc_norm_text('Madisonville Academy')));
$check('punctuation and case do not matter', kop_facdisc_in_source('Sequel TSI - Kissimmee', kop_facdisc_norm_text('the SEQUEL TSI Kissimmee campus')));
$unq = kop_facdisc_apply_entry($pdo, array('name' => 'Osceola Youth Ranch', 'kind' => 'facility', 'sameAs' => 11129, 'renameOf' => null, 'officialName' => 'Osceola Youth Ranch',
    'state' => 'FL', 'country' => '', 'city' => 'Kissimmee', 'quoted' => false), array('id' => 628), false);
$check('a name not in the article is held, not linked', $unq === array('unquoted', 11129), json_encode($unq));

echo "-- Town names are never a match --\n";
// Sequel's site list on article #628: each town was offered as the record that shares its name.
$check('"Kissimmee" is a town, not Kissimmee Youth Academy', kop_facdisc_near_duplicate($pdo, 'Kissimmee', 'FL', 'Kissimmee') === null);
$check('"Courtland" is a town, not Brighter Path Courtland', kop_facdisc_near_duplicate($pdo, 'Courtland', 'AL', 'Courtland') === null);
$check('"Tuskegee Union" is not Sequel TSI of Tuskegee', kop_facdisc_near_duplicate($pdo, 'Tuskegee Union', 'AL', 'Tuskegee') === null);
$check('a town alone is a place name', kop_facdisc_is_place_name($pdo, 'Kissimmee', 'FL', '') && kop_facdisc_is_place_name($pdo, 'Madison', 'AL', 'Madison'));
$check('a program named after its town is not', !kop_facdisc_is_place_name($pdo, 'Kissimmee Youth Academy', 'FL', 'Kissimmee'));
$town = kop_facdisc_apply_entry($pdo, array('name' => 'Courtland', 'kind' => 'facility', 'sameAs' => null, 'renameOf' => null, 'officialName' => 'Courtland',
    'state' => 'AL', 'country' => '', 'city' => 'Courtland', 'quoted' => true), array('id' => 628), false);
$check('a town alone is skipped, never offered as a record', $town === array('not_facility', null), json_encode($town));

// ---------------------------------------------------------------------------
echo "-- The prompt --\n";
$prompt = kop_facdisc_build_prompt($article + array('summary' => 'S'), $asked, $lookalikes, 'body');
$check('lists each name with its look-alikes', strpos($prompt, '11656 | Asheville Academy for Girls | Black Mountain, NC | Closed') !== false
    && strpos($prompt, '5. "Sheppard Pratt"') !== false);
$check('names the type vocabulary and the kinds', strpos($prompt, 'Juvenile Justice RTC') !== false && strpos($prompt, '- vague:') !== false);

// ---------------------------------------------------------------------------
echo "-- Indigenous residential schools (filed at Indigenous Schools, never as facilities) --\n";
$check('the prompt names the kind', strpos($prompt, '- indigenous_school:') !== false && strpos($prompt, 'facility|indigenous_school|provider') !== false);
$check('a school name is never pre-sorted as an organization', kop_facdisc_obvious_kind('Chilocco Indian Agricultural School') === null
    && kop_facdisc_obvious_kind('Indian Agency Boarding School') === null && kop_facdisc_obvious_kind('Bureau of Indian Affairs') === 'organization');
$check('the name test reads Indian boarding and mission schools, not TTI programs or Indiana',
    kop_facdisc_looks_indigenous_school("St. Paul's Indian Mission School") && kop_facdisc_looks_indigenous_school('Shawnee Indian Manual Labor School')
    && !kop_facdisc_looks_indigenous_school('New Beginnings Residential School') && !kop_facdisc_looks_indigenous_school('Indiana Boarding School for Boys'));
$es = kop_facdisc_parse_reply(json_encode(array('names' => array(array('name' => 'Shawnee Indian Mission', 'kind' => 'indigenous_school',
    'officialName' => 'Shawnee Indian Manual Labor School', 'state' => 'KS', 'city' => 'Fairway', 'startYear' => 1839, 'endYear' => 1862)))), array('Shawnee Indian Mission'), array());
$check('the reply keeps the kind and years before 1900', ($es['Shawnee Indian Mission']['kind'] ?? '') === 'indigenous_school'
    && $es['Shawnee Indian Mission']['startYear'] === 1839 && $es['Shawnee Indian Mission']['endYear'] === 1862);
$ef = kop_facdisc_parse_reply(json_encode(array('names' => array(array('name' => 'Old Ranch', 'kind' => 'facility', 'startYear' => 1839)))), array('Old Ranch'), array());
$check('a facility still gets no year before 1900', $ef['Old Ranch']['startYear'] === null);
$check('the dry run files it as a school, not a new facility',
    kop_facdisc_apply_entry($pdo, $es['Shawnee Indian Mission'], array('id' => 519), false) === array('indigenous_school', null));

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
