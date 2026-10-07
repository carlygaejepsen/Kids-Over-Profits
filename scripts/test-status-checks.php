<?php
/**
 * The nightly bill and lawsuit status checks (inc/status-checks.php), offline:
 * bill and docket number parsing, govinfo BILLSTATUS reading, CourtListener
 * matching, what counts as a change, and whole runs over an in-memory copy of
 * the legislation and lawsuits tables in tmp/prod.sqlite with made-up pages
 * and a made-up AI (no network, no AI calls; the mirror is never written).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-status-checks.php [--db=tmp/prod.sqlite]
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}
$args = getopt('', array('db::'));
$mirror = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');

define('ABSPATH', __DIR__);
define('MINUTE_IN_SECONDS', 60);
define('HOUR_IN_SECONDS', 3600);
define('DAY_IN_SECONDS', 86400);
$GLOBALS['opts'] = array();
function get_option($k, $d = false) { return $GLOBALS['opts'][$k] ?? $d; }
function update_option($k, $v) { $GLOBALS['opts'][$k] = $v; return true; }
function get_transient($k) { return false; }
function set_transient() { return true; }
function delete_transient() { return true; }
function add_action() {}
function wp_next_scheduled() { return true; }
function wp_schedule_event() {}
function wp_schedule_single_event() {}
function get_stylesheet_directory() { return dirname(__DIR__); }
function kop_legislation_statuses() { return array('proposed', 'introduced', 'in_committee', 'passed_house', 'passed_senate', 'signed', 'vetoed', 'dead', 'enacted', 'unknown'); }
require dirname(__DIR__) . '/inc/status-checks.php';

$failures = 0;
function check($label, $ok, $detail = '') {
    global $failures;
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
}

/* ---- Parsing --------------------------------------------------------- */

$ref = function ($num, $session, $title = '') { return kop_sc_federal_bill_ref(array('bill_number' => $num, 'session_year' => $session, 'bill_title' => $title)); };
check('S. 1351 in 2023-2024 is the 118th Congress', $ref('S. 1351', '2023-2024') === array(118, 's', 1351));
check('H.R. 911 in 2009-2010 is the 111th', $ref('H.R. 911', '2009-2010') === array(111, 'hr', 911));
check('"118th Congress" in the session wins', $ref('HR 9076', '118th Congress') === array(118, 'hr', 9076));
check('a draft with no bill number has no reference', $ref('', '2025-2026') === null);
check('a state bill number is not federal', $ref('SB 1190', '2025-2026') === null);

check('docket 2:18-cv-35-TC-DAO', kop_sc_docket_parts('2:18-cv-35-TC-DAO') === array('2', '18-cv-00035'));
check('docket 20-CV-00215-SWS (no office)', kop_sc_docket_parts('20-CV-00215-SWS') === array('', '20-cv-00215'));
check('docket 3:17-cv-01411-SRU', kop_sc_docket_parts('3:17-cv-01411-SRU') === array('3', '17-cv-01411'));
check('a state case number is not a federal docket', kop_sc_docket_parts('220900123') === null);
check('first party: Sherman, et al. v. ...', kop_sc_first_party_word('Sherman, et al. v. Trinity Teen Solutions, et al.') === 'Sherman');
check('first party: John R. v. ... has none to search by', kop_sc_first_party_word('John R. v. United Behavioral Health') === '');
check('court state: Western District of North Carolina', kop_sc_court_state('U.S. District Court for the Western District of North Carolina') === 'North Carolina');
check('court id: District of Utah', kop_sc_court_id('U.S. District Court for the District of Utah, Central Division') === 'utd');
check('court id: Western District of North Carolina', kop_sc_court_id('U.S. District Court for the Western District of North Carolina') === 'ncwd');
check('court id: a state judicial district is not a federal court', kop_sc_court_id('Fifth Judicial District Court, Washington County, Utah') === '');
check('court state: West Virginia is not Virginia', kop_sc_court_state('Southern District of West Virginia') === 'West Virginia');
check('California bills are read on CalMatters', kop_sc_bill_source_url(array('official_url' => 'https://leginfo.legislature.ca.gov/faces/billNavClient.xhtml?bill_id=202520260SB1190', 'full_text_url' => ''))
    === 'https://calmatters.digitaldemocracy.org/bills/ca_202520260sb1190');
check('New York Senate bills are read on the Assembly site', kop_sc_bill_source_url(array('official_url' => 'https://www.nysenate.gov/legislation/bills/2025/S937', 'full_text_url' => ''))
    === 'https://nyassembly.gov/leg/?default_fld=&leg_video=&bn=S00937&term=2025&Summary=Y&Actions=Y');
check('Ohio bills are read from the legislature data service', kop_sc_bill_source_url(array('official_url' => 'https://www.legislature.ohio.gov/legislation/136/hb811', 'full_text_url' => ''))
    === 'https://search-prod.lis.state.oh.us/api/v2/general_assembly_136/legislation/hb811/actions/');
$GLOBALS['kop_sc_fetch_stub'] = function ($url) {
    return substr($url, -9) === '/actions/'
        ? '[{"occurred":"2026-04-08T10:00:00-04:00","chamber":"House","action":"Introduced","description":"Introduced"},{"occurred":"2026-05-13T19:43:00-04:00","chamber":"House","action":"Refer to Committee","description":"Refer to Committee","cmte_lpid":"cmte_h_children_human_services_1"}]'
        : '[{"name":"H. B. No. 811","short_title":"Regards residential facilities","version":"As Introduced","governor_signed_date":null,"effective_date":null}]';
};
$oh = kop_sc_ohio_text(kop_sc_fetch('https://search-prod.lis.state.oh.us/api/v2/general_assembly_136/legislation/hb811/actions/'), 'https://search-prod.lis.state.oh.us/api/v2/general_assembly_136/legislation/hb811/actions/');
check('Ohio actions read newest first with the committee', strpos($oh, "Actions, newest first:
2026-05-13 House: Refer to Committee (committee children_human_services)") !== false && strpos($oh, 'Governor signed: no') !== false);
unset($GLOBALS['kop_sc_fetch_stub']);
check('quote found despite curly quotes and spacing', kop_sc_quote_found("Approved by Governor  \u{2019}10/5/26\u{2019}", "x approved by governor '10/5/26' y"));
check('a quote that is not there is not found', !kop_sc_quote_found('Vetoed by the Governor', 'Approved by the Governor'));
check('AI JSON inside a code fence is read', kop_sc_parse_json("```json\n{\"same_bill\": true, \"status\": \"enacted\"}\n```")['status'] === 'enacted');

/* ---- govinfo BILLSTATUS ---------------------------------------------- */

function billstatus_xml($origin, array $actions, array $laws = array(), $latest = null) {
    $a = '';
    foreach ($actions as $x) $a .= "<item><actionDate>{$x[0]}</actionDate><text>{$x[1]}</text><type>{$x[2]}</type></item>";
    $l = '';
    foreach ($laws as $x) $l .= "<item><type>Public Law</type><number>$x</number></item>";
    $latest = $latest ?: $actions[0];
    return "<?xml version=\"1.0\"?><billStatus><bill><originChamber>$origin</originChamber><actions>$a</actions>"
        . ($l ? "<laws>$l</laws>" : '') . "<latestAction><actionDate>{$latest[0]}</actionDate><text>{$latest[1]}</text></latestAction></bill></billStatus>";
}
$cur = kop_sc_current_congress();
$f = kop_sc_billstatus_facts(billstatus_xml('Senate', array(array('2024-12-23', 'Became Public Law No: 118-194.', 'President'), array('2024-12-18', 'Passed/agreed to in House: On passage Passed by the Yeas and Nays: 373 - 33.', 'Floor')), array('118-194')), 118);
check('a public law is enacted', $f['status'] === 'enacted' && $f['last_action_date'] === '2024-12-23' && strpos($f['last_action_text'], '118-194') !== false, json_encode($f['status']));
$f = kop_sc_billstatus_facts(billstatus_xml('House', array(array('2009-03-23', 'Committee on HELP discharged.', 'Committee'), array('2009-02-23', 'Passed/agreed to in House: On passage Passed by the Yeas and Nays: 295 - 102.', 'Floor'))), 111);
check('a bill whose Congress ended without becoming law is dead', $f['status'] === 'dead' && strpos($f['last_action_text'], '111th Congress ended') !== false, $f['last_action_text']);
$f = kop_sc_billstatus_facts(billstatus_xml('House', array(array('2026-09-01', 'Passed/agreed to in House: On passage Passed by voice vote.', 'Floor'), array('2026-03-26', 'Referred to the House Committee on Energy and Commerce.', 'IntroReferral'))), $cur);
check('a current bill the House passed is passed_house', $f['status'] === 'passed_house' && $f['last_action_date'] === '2026-09-01');
$f = kop_sc_billstatus_facts(billstatus_xml('House', array(array('2026-03-26', 'Referred to the House Committee on Energy and Commerce.', 'IntroReferral'))), $cur);
check('a current bill in committee is in_committee', $f['status'] === 'in_committee');
$f = kop_sc_billstatus_facts(billstatus_xml('House', array(array('2026-06-01', 'Vetoed by President.', 'President'), array('2026-05-01', 'Passed/agreed to in Senate: Passed Senate without amendment by Unanimous Consent.', 'Floor'), array('2026-03-01', 'Passed/agreed to in House: On passage Passed.', 'Floor'))), $cur);
check('a vetoed bill is vetoed', $f['status'] === 'vetoed');
check('an unreadable BILLSTATUS file is null', kop_sc_billstatus_facts('<html>blocked</html>', 118) === null && kop_sc_billstatus_facts('', 118) === null);

/* ---- What counts as a change ----------------------------------------- */

$bill = array('status' => 'in_committee', 'last_action_date' => '2026-05-13', 'last_action_text' => 'Referred to committee.');
$d = kop_sc_diff('bill', $bill, array('status' => 'enacted', 'last_action_date' => '2026-10-05', 'last_action_text' => 'Signed.'));
check('a newer action and a new status are a change', isset($d['status'], $d['last_action_date'], $d['last_action_text']) && $d['status'] === array('in_committee', 'enacted'));
$d = kop_sc_diff('bill', $bill, array('status' => 'in_committee', 'last_action_date' => '2026-05-13', 'last_action_text' => 'Referred to the Committee on Children.'));
check('the same action reworded is no change', $d === array());
$d = kop_sc_diff('bill', $bill, array('status' => 'introduced', 'last_action_date' => '2026-02-01', 'last_action_text' => 'Introduced.'));
check('an older action never moves the record back', $d === array());
$d = kop_sc_diff('bill', array('status' => 'unknown', 'last_action_date' => null, 'last_action_text' => ''), array('status' => 'dead', 'last_action_date' => '', 'last_action_text' => ''));
check('a status with no date fills a record that had none', $d === array('status' => array('unknown', 'dead')));
$suit = array('status' => 'filed', 'outcome' => '', 'settlement_amount' => '');
$d = kop_sc_diff('lawsuit', $suit, array('status' => 'settled', 'outcome' => 'The parties settled.', 'settlement_amount' => ''));
check('a lawsuit that settled is a change with its outcome', $d === array('status' => array('filed', 'settled'), 'outcome' => array('', 'The parties settled.')));
$d = kop_sc_diff('lawsuit', array('status' => 'ruling', 'outcome' => 'Motion to dismiss denied in part.', 'settlement_amount' => ''), array('status' => 'ruling', 'outcome' => 'The court denied part of the motion to dismiss.', 'settlement_amount' => ''));
check('a lawsuit outcome reworded with no new status is no change', $d === array());
$d = kop_sc_diff('lawsuit', array('status' => 'unknown', 'outcome' => '', 'settlement_amount' => ''), array('status' => 'unknown', 'outcome' => 'Discovery is under way.', 'settlement_amount' => ''));
check('an empty outcome is filled even without a new status', $d === array('outcome' => array('', 'Discovery is under way.')));

/* ---- CourtListener matching ------------------------------------------ */

$GLOBALS['kop_sc_fetch_stub'] = function ($url) {
    return json_encode(array('results' => array(
        array('docket_id' => 1, 'docketNumber' => '2:20-cv-00215', 'court' => 'District Court, D. Wyoming', 'caseName' => 'Sherman v. Trinity Teen Solutions Inc', 'docket_absolute_url' => '/docket/1/sherman/', 'dateTerminated' => '2025-10-06'),
        array('docket_id' => 2, 'docketNumber' => '1:20-cv-00215', 'court' => 'District Court, D. Wyoming', 'caseName' => 'Scavuzzo v. Triangle Cross Ranch LLC', 'docket_absolute_url' => '/docket/2/scavuzzo/'),
        array('docket_id' => 3, 'docketNumber' => '2:20-cv-00215', 'court' => 'District Court, N.D. Georgia', 'caseName' => 'Sherman v. Someone', 'docket_absolute_url' => '/docket/3/x/'),
    )));
};
$dk = kop_sc_courtlistener_docket(array('case_number' => '2:20-cv-00215-SWS', 'case_name' => 'Sherman v. Trinity', 'court' => 'U.S. District Court for the District of Wyoming'));
check('the docket in the right state and office is picked', $dk && $dk['docket_id'] === 1 && $dk['terminated'] === '2025-10-06');
$dk = kop_sc_courtlistener_docket(array('case_number' => '20-cv-00215', 'case_name' => 'Sherman v. Trinity', 'court' => 'U.S. District Court for the District of Wyoming'));
check('two dockets that fit (no office to tell them apart) are never guessed', $dk === null);
$dk = kop_sc_courtlistener_docket(array('case_number' => '2:20-cv-00215', 'case_name' => 'Sherman v. Trinity', 'court' => 'U.S. District Court for the District of Utah'));
check('a docket in another state is not this case', $dk === null);

/* ---- Whole runs over a copy of the tables ---------------------------- */

if (!file_exists($mirror)) {
    echo "SKIP runs: no mirror at $mirror (run scripts/sync-prod-sqlite.py)\n";
} else {
    $src = new PDO('sqlite:' . $mirror);
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    foreach (array('legislation', 'lawsuits') as $t) {
        $cols = $src->query("PRAGMA table_info($t)")->fetchAll(PDO::FETCH_COLUMN, 1);
        $pdo->exec("CREATE TABLE $t (" . implode(', ', array_map(function ($c) { return $c === 'id' ? 'id INTEGER PRIMARY KEY' : "`$c`"; }, $cols)) . ')');
        $ins = $pdo->prepare("INSERT INTO $t VALUES (" . implode(',', array_fill(0, count($cols), '?')) . ')');
        foreach ($src->query("SELECT * FROM $t", PDO::FETCH_NUM) as $r) $ins->execute($r);
    }
    $src = null;
    $pdo->exec("CREATE TABLE record_status_sources (kind TEXT NOT NULL, record_id INTEGER NOT NULL, source_url TEXT, source_ref TEXT,
        last_checked TEXT, last_outcome TEXT, last_detail TEXT, last_hash TEXT, PRIMARY KEY (kind, record_id))");
    $pdo->exec("CREATE TABLE record_status_proposals (id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, record_id INTEGER NOT NULL,
        changes TEXT NOT NULL, change_hash TEXT NOT NULL, quote TEXT, quote_found INTEGER NOT NULL DEFAULT 0, source_url TEXT, source_label TEXT,
        status TEXT NOT NULL DEFAULT 'pending', previous TEXT, reviewed_by TEXT, reviewed_at TEXT, created_at TEXT NOT NULL, UNIQUE (kind, record_id, change_hash))");
    $GLOBALS['opts']['kop_status_checks_db'] = KOP_STATUS_CHECKS_DB_VERSION;

    // Two made-up records with known sources, so the run does not depend on what the mirror holds.
    $pdo->exec("INSERT INTO legislation (id, bill_number, bill_title, jurisdiction, chamber, session_year, bill_type, status, last_action_date, last_action_text,
        official_url, publication_status, reviewer_notes) VALUES
        (9001, 'H.R. 8095', 'Test federal bill', 'Federal', 'federal_house', '2025-2026', 'HR', 'in_committee', '2026-03-26', 'Referred.', '', 'published', 'old notes'),
        (9002, 'SB 77', 'Test state bill', 'Testland', 'senate', '2025-2026', 'SB', 'passed_senate', '2026-04-01', 'Passed the Senate.', 'https://legis.example.test/sb77', 'published', '')");
    $pdo->exec("INSERT INTO lawsuits (id, case_name, case_number, court, jurisdiction, status, outcome, settlement_amount, source_urls, document_urls, publication_status)
        VALUES (9003, 'Sherman, et al. v. Trinity Teen Solutions', '2:20-cv-00215-SWS', 'U.S. District Court for the District of Wyoming', 'Federal', 'filed', '', '', '[]', '[]', 'published')");
    $only = array('bill' => array(9001, 9002), 'lawsuit' => array(9003));

    $state_page = '<html><body><h1>SB 77</h1><table><tr><td>10/01/2026</td><td>Signed by the Governor. Chapter 412.</td></tr>'
        . '<tr><td>04/01/2026</td><td>Passed the Senate.</td></tr></table>' . str_repeat('<p>Bill history and text of SB 77.</p>', 10) . '</body></html>';
    $GLOBALS['kop_sc_fetch_stub'] = function ($url) use ($state_page) {
        if (strpos($url, 'govinfo.gov') !== false) {
            return billstatus_xml('House', array(array('2026-09-15', 'Passed/agreed to in House: On passage Passed by recorded vote: 300 - 100.', 'Floor'), array('2026-03-26', 'Referred to the House Committee on Energy and Commerce.', 'IntroReferral')));
        }
        if (strpos($url, 'legis.example.test') !== false) return $state_page;
        if (strpos($url, '/api/rest/v4/search/') !== false) {
            return json_encode(array('results' => array(array('docket_id' => 77, 'docketNumber' => '2:20-cv-00215', 'court' => 'District Court, D. Wyoming',
                'caseName' => 'Sherman v. Trinity Teen Solutions Inc', 'docket_absolute_url' => '/docket/77/sherman/', 'dateTerminated' => '2025-10-06'))));
        }
        if (strpos($url, '/docket/77/feed/') !== false) {
            return '<feed><entry><title>ORDER of dismissal with prejudice after settlement. Signed by Judge Skavdahl on 10/6/2025.</title></entry>'
                . str_repeat('<entry><title>Docket text of an earlier entry in the case.</title></entry>', 6) . '</feed>';
        }
        return '';
    };
    $ai_calls = 0;
    $GLOBALS['kop_sc_ai_stub'] = function ($prompt) use (&$ai_calls) {
        $ai_calls++;
        if (strpos($prompt, 'SB 77') !== false) {
            return '{"same_bill": true, "status": "enacted", "last_action_date": "2026-10-01", "last_action_text": "Signed by the Governor; Chapter 412.", "quote": "Signed by the Governor. Chapter 412."}';
        }
        check('the lawsuit prompt passes on the date the court closed the case', strpos($prompt, 'terminated on 2025-10-06') !== false);
        return '{"same_case": true, "status": "settled", "event_date": "2025-10-06", "outcome": "The court dismissed the case with prejudice after the parties settled.", "settlement_amount": "", "quote": "ORDER of dismissal with prejudice after settlement."}';
    };

    $r1 = kop_sc_run($pdo, 10, 60, true, array('bill'), $only['bill']);
    $r2 = kop_sc_run($pdo, 10, 60, true, array('lawsuit'), $only['lawsuit']);
    check('a run checks each record and finds the changes', $r1['counts']['changed'] === 2 && $r2['counts']['changed'] === 1, json_encode(array($r1['counts'], $r2['counts'])));
    $props = $pdo->query('SELECT * FROM record_status_proposals ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $byRec = array();
    foreach ($props as $p) $byRec[$p['record_id']] = $p;
    $fed = json_decode($byRec[9001]['changes'], true);
    check('federal bill: passed the House, newer action, from govinfo with no AI', $fed['status'][1] === 'passed_house' && $fed['last_action_date'][1] === '2026-09-15'
        && $byRec[9001]['source_label'] === 'govinfo (Congress)' && $byRec[9001]['quote_found'] == 1);
    $st = json_decode($byRec[9002]['changes'], true);
    check('state bill: enacted, quote found on the page', $st['status'][1] === 'enacted' && $byRec[9002]['quote_found'] == 1);
    $ls = json_decode($byRec[9003]['changes'], true);
    check('lawsuit: settled from the CourtListener docket feed', $ls['status'][1] === 'settled' && $byRec[9003]['source_url'] === 'https://www.courtlistener.com/docket/77/sherman/' && $byRec[9003]['quote_found'] == 1);
    check('the AI read only the state bill and the lawsuit', $ai_calls === 2, (string) $ai_calls);
    check('nothing changed a record yet', $pdo->query('SELECT status FROM legislation WHERE id = 9002')->fetchColumn() === 'passed_senate');

    $before_n = count($props);
    kop_sc_run($pdo, 10, 60, true, array('bill'), $only['bill']);
    check('the same finding the next night makes no second update', (int) $pdo->query('SELECT COUNT(*) FROM record_status_proposals')->fetchColumn() === $before_n);
    check('an unchanged page is not sent to the AI again', $ai_calls === 2, (string) $ai_calls);
    $due = array_column(kop_sc_due($pdo, 'bill', 500), 'id');
    check('a record checked in the last 20 hours is not due', !in_array('9001', array_map('strval', $due), true) && !in_array('9002', array_map('strval', $due), true));

    // The page moves on before anyone reviewed: the waiting update is replaced.
    $state_page = str_replace('10/01/2026</td><td>Signed by the Governor. Chapter 412.', '10/03/2026</td><td>Chaptered by the Secretary of State. Chapter 412.', $state_page);
    $GLOBALS['kop_sc_ai_stub'] = function () {
        return '{"same_bill": true, "status": "enacted", "last_action_date": "2026-10-03", "last_action_text": "Chaptered by the Secretary of State; Chapter 412.", "quote": "Chaptered by the Secretary of State. Chapter 412."}';
    };
    $GLOBALS['kop_sc_fetch_stub'] = function ($url) use ($state_page) { return strpos($url, 'legis.example.test') !== false ? $state_page : ''; };
    kop_sc_run($pdo, 10, 60, true, array('bill'), array(9002));
    $rows = $pdo->query('SELECT status FROM record_status_proposals WHERE record_id = 9002 ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    check('a newer finding replaces the waiting one', $rows === array('stale', 'pending'), implode(',', $rows));

    // Apply, then Undo: the record comes back exactly, notes included.
    $pid = (int) $pdo->query("SELECT id FROM record_status_proposals WHERE record_id = 9001")->fetchColumn();
    $orig = $pdo->query('SELECT * FROM legislation WHERE id = 9001')->fetch(PDO::FETCH_ASSOC);
    kop_sc_apply($pdo, $pid, 'tester');
    $now = $pdo->query('SELECT * FROM legislation WHERE id = 9001')->fetch(PDO::FETCH_ASSOC);
    check('apply writes the update', $now['status'] === 'passed_house' && $now['last_action_date'] === '2026-09-15' && strpos($now['reviewer_notes'], 'approved by tester') !== false);
    try {
        kop_sc_apply($pdo, $pid, 'tester');
        check('an update cannot be applied twice', false);
    } catch (RuntimeException $e) {
        check('an update cannot be applied twice', true);
    }
    kop_sc_undo($pdo, $pid, 'tester');
    $back = $pdo->query('SELECT * FROM legislation WHERE id = 9001')->fetch(PDO::FETCH_ASSOC);
    check('Undo restores the record exactly', $back === $orig);

    // Records with nothing to check say so and cost nothing.
    $GLOBALS['kop_sc_fetch_stub'] = function () { return ''; };
    $pdo->exec("INSERT INTO lawsuits (id, case_name, case_number, court, jurisdiction, status, source_urls, document_urls, publication_status)
        VALUES (9004, 'Doe v. Some Academy', '', 'Fifth Judicial District Court, Washington County, Utah', 'Utah', 'filed', '[]', '[]', 'published')");
    $r = kop_sc_run($pdo, 10, 60, true, array('lawsuit'), array(9004));
    check('a state court case with no docket link is "no source"', $r['counts']['no_source'] === 1);
    $pdo->exec("INSERT INTO legislation (id, bill_number, bill_title, jurisdiction, session_year, status, publication_status) VALUES (9005, '', 'Draft act', 'Federal', '2025-2026', 'proposed', 'published')");
    $r = kop_sc_run($pdo, 10, 60, true, array('bill'), array(9005));
    check('a federal draft with no number is "no source"', $r['counts']['no_source'] === 1);

    // What a real night would look at in the mirror.
    $GLOBALS['opts']['kop_status_checks_db'] = KOP_STATUS_CHECKS_DB_VERSION;
    $pdo->exec('DELETE FROM record_status_sources');
    $bills = kop_sc_due($pdo, 'bill', 500);
    $suits = kop_sc_due($pdo, 'lawsuit', 500);
    $federal = count(array_filter($suits, 'kop_sc_is_federal_case'));
    echo sprintf("INFO a night checks %d bills and %d lawsuits (%d federal cases CourtListener may have)\n", count($bills), count($suits), $federal);
    check('finished bills are not checked', !array_filter($bills, function ($b) { return in_array($b['status'], array('enacted', 'dead'), true); }));
    check('settled and closed lawsuits are not checked', !array_filter($suits, function ($s) { return in_array($s['status'], array('settled', 'closed'), true); }));
}

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
