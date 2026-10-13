<?php
/**
 * Offline checks for marketing companies (api/lib-marketers.php, inc/marketers.php, the data forms'
 * Marketers tab in templates/data-form-marketer.php + js/data-form/marketer-form.js).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-marketers.php [--db=tmp/prod.sqlite] [--out=tmp/marketers]
 *
 * marketers_master is made in an in-memory database; nothing is written to the mirror (which only feeds
 * the facility and company links). Writes the rendered page and the form markup to --out.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/marketers');
if (!file_exists($db_path)) {
    fwrite(STDERR, "Need $db_path (scripts/sync-prod-sqlite.py).\n");
    exit(2);
}

require __DIR__ . '/kop-test-harness.php';
if (!function_exists('wpautop')) {
    function wpautop($s) { return '<p>' . str_replace("\n\n", '</p><p>', trim((string) $s)) . '</p>'; }
}
foreach (array('kop_icon' => 'icons', 'kop_url_label' => 'url-labels', 'kop_facility_link_resolve' => 'facility-suggest', 'kop_operator_page_url_for_name' => 'operator-pages') as $fn => $file) {
    if (!function_exists($fn)) {
        require_once dirname(__DIR__) . '/inc/' . $file . '.php';
    }
}

$mk = new PDO('sqlite::memory:');
$mk->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['kop_test_mk_pdo']; }
}
$GLOBALS['kop_test_mk_pdo'] = $mk;
require_once dirname(__DIR__) . '/inc/marketers.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Record shape (api/lib-marketers.php) --\n";
$form = array(
    'operator' => array('name' => ''), 'facilities' => array(array('identification' => array('name' => ''))),
    'referrerAgency' => array('name' => ''), 'transporterCompany' => array('name' => ''),
    'marketerCompany' => array(
        'name' => ' Acme Leads ', 'website' => 'acme.example', 'otherNames' => array('', 'Acme', 'Acme'),
        'services' => array('seo' => true, 'bogus' => true), 'sitesRun' => "a.example\nb.example",
    ),
);
$kept = kop_marketer_keep_data($form);
$check('only the company block and the tag are kept', array_keys($kept) === array('marketerCompany', 'category') && $kept['category'] === 'marketers');
$c = $kept['marketerCompany'];
$check('text is trimmed', $c['name'] === 'Acme Leads');
$check('lists drop blanks and repeats', $c['otherNames'] === array('Acme'));
$check('a lone website fills the list', $c['websites'] === array('acme.example'));
$check('a list typed as lines is split', $c['sitesRun'] === array('a.example', 'b.example'));
$check('services: only known keys, all present', $c['services']['seo'] === true && !isset($c['services']['bogus'])
    && count($c['services']) === count(kop_marketer_service_labels()));
$check('a wrapped project is unwrapped', kop_marketer_keep_data(array('name' => 'x', 'data' => $form))['marketerCompany']['name'] === 'Acme Leads');
$check('has data: by tag or by name', kop_marketer_has_data(array('category' => 'marketers')) && kop_marketer_has_data($form)
    && !kop_marketer_has_data(array('marketerCompany' => array('name' => ' '))) && !kop_marketer_has_data(array('operator' => array('name' => 'X'))));

$merged = kop_marketer_merge_company(
    array('name' => 'Acme Leads', 'city' => 'Tampa', 'clientFacilities' => array('A'), 'services' => array('seo' => true)),
    array('name' => 'Acme Leads', 'city' => '', 'state' => 'FL', 'clientFacilities' => array('B', 'A'), 'services' => array('directories' => true))
);
$check('merge: filled text wins, empty text keeps the old', $merged['city'] === 'Tampa' && $merged['state'] === 'FL');
$check('merge: lists join without repeats', $merged['clientFacilities'] === array('A', 'B'));
$check('merge: ticked services stay ticked', $merged['services']['seo'] && $merged['services']['directories']);

echo "-- Seed (seeds/marketers.json) --\n";
$seed = json_decode(file_get_contents(dirname(__DIR__) . '/seeds/marketers.json'), true);
$added = kop_marketers_apply_seed($mk);
$check('every seed record is added', count($added) === count($seed['records']), implode(', ', $added));
$check('each seed record cites sources', count(array_filter($seed['records'], function ($r) { return empty($r['sourceUrls']); })) === 0);
$mk->exec("UPDATE marketers_master SET json_data = REPLACE(json_data, '\"status\":\"Active\"', '\"status\":\"Edited\"') WHERE unique_name = 'All Kinds of Therapy'");
$check('a second run adds nothing', kop_marketers_apply_seed($mk) === array());
$row = kop_marketer_company_from_json($mk->query("SELECT json_data FROM marketers_master WHERE unique_name = 'All Kinds of Therapy'")->fetchColumn());
$check('a record on file is never changed by the seed', $row['status'] === 'Edited', $row['status']);

echo "-- The page (/marketing-companies/) --\n";
$all = kop_marketers_all();
$check('listed A to Z', array_map(function ($m) { return $m['company']['name']; }, $all)
    === array('All Kinds of Therapy', 'Best Choice Admissions Services', 'Exceed Marketing Solutions', 'The Envoy Group'));
ob_start();
kop_marketers_render_public();
$html = ob_get_clean();
$check('every company has its own anchor', substr_count($html, 'class="kop-mkt-company" id="kop-mkt-') === count($all));
$check('services print as words', strpos($html, 'Instructions for AI chatbots') !== false);
$check('a directory links its archived copy', strpos($html, 'https://web.archive.org/web/https://besttherapeuticboardingschools.com') !== false);
$check('a live industry site goes only through /go/', strpos($html, '/go/?u=https%3A%2F%2Fmilitaryschoolusa.com') !== false
    && !preg_match('#href="https?://(www\.)?militaryschoolusa\.com#', $html));
$check('sources read as words, not addresses', strpos($html, '>https://kidsoverprofits.org/free-help') === false);
preg_match_all('#<a href="(https://example\.test/facility/[^"]+)">([^<]+)</a>#', $html, $m);
echo '     programs linked to their pages: ' . (count($m[2]) ? implode(', ', $m[2]) : 'none') . "\n";
$check('nothing escapes unencoded', strpos($html, '<script') === false);

echo "-- Other tools: Woodbury, wiki links, finder, people --\n";
require_once dirname(__DIR__) . '/inc/woodbury-create.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';
require_once dirname(__DIR__) . '/api/lib-wiki-link-label.php';
require_once dirname(__DIR__) . '/inc/people.php';
$exceed = $mk->query("SELECT id, unique_name, json_data FROM marketers_master WHERE unique_name = 'Exceed Marketing Solutions'")->fetch(PDO::FETCH_ASSOC);
$payload = json_decode($exceed['json_data'], true);
$check('Woodbury can create a marketing company', isset(kop_wbc_kinds()['marketer']) && function_exists('kop_wbc_create_marketer')
    && isset(kop_wbf_create_kinds()['marketer']));
$check('Woodbury files under an existing one', (kop_wbc_record_tables()['marketer']['table'] ?? '') === 'marketers_master');
$check('a marketer token parses', kop_wbc_parse_record_token('marketer:' . $exceed['id']) === array('marketer', (int) $exceed['id']));
$names = kop_wbc_record_names('marketer', $exceed['unique_name'], $payload);
$check('found by its other names', isset($names['Exceed Marketing Solutions, LLC']), implode('; ', array_keys($names)));
$check('its place', kop_wbc_record_place('marketer', $payload) === 'FL', kop_wbc_record_place('marketer', $payload));
$check('notes go in the company block', kop_wbc_note_paths('marketer', $payload) === array(array('data', 'marketerCompany', 'notes')));
$check('wiki entries can link to one', isset(kop_wiki_upd_other_kinds()['marketer']));
$token = 'marketer:' . $exceed['id'];
$check('a wiki link shows its name', kop_wiki_link_labels($mk, array($token))[$token] === 'Exceed Marketing Solutions');
$check('the People screens name the kind', (kop_people_kinds()['marketer'] ?? '') === 'marketing company');

echo "-- The form (templates/data-form-marketer.php == js/data-form/marketer-form.js) --\n";
$render = function ($part, $public) {
    $kop_marketer_part = $part;
    $kop_marketer_public = $public;
    ob_start();
    include dirname(__DIR__) . '/templates/data-form-marketer.php';
    return ob_get_clean();
};
$editor = $render('editor', true);
$panel = $render('panel', false);
$js = file_get_contents(dirname(__DIR__) . '/js/data-form/marketer-form.js');
$js_list = function ($const) use ($js) {
    preg_match('/const ' . $const . ' = \[(.*?)\];/s', $js, $m);
    preg_match_all("/'([^']+)'/", $m[1] ?? '', $k);
    return $k[1];
};
$check('service keys: JS == PHP', $js_list('MARKETER_SERVICE_KEYS') === array_keys(kop_marketer_service_labels()));
$check('text fields: JS == PHP', $js_list('MARKETER_TEXT_FIELDS') === kop_marketer_text_fields());
$check('list fields: JS == PHP', $js_list('MARKETER_LIST_FIELDS') === kop_marketer_list_fields());
foreach (kop_marketer_text_fields() as $field) {
    $id = 'marketer-company-' . strtolower(preg_replace('/([A-Z])/', '-$1', $field));
    $check("the form has #$id", strpos($editor, 'id="' . $id . '"') !== false);
}
foreach (kop_marketer_list_fields() as $field) {
    $check("the form has the $field list", strpos($editor, 'data-path="marketerCompany.' . $field . '"') !== false);
}
foreach (array_keys(kop_marketer_service_labels()) as $key) {
    $check("the form has the $key box", strpos($editor, 'data-service="' . $key . '"') !== false);
}
foreach (array('marketer-search-input', 'marketer-saved-projects-list', 'new-project-btn-marketer', 'export-all-btn-marketer', 'generate-report-btn-marketer') as $id) {
    $check("the panel has #$id", strpos($panel, 'id="' . $id . '"') !== false);
}
foreach (array('marketer-project-name', 'save-marketer-project-btn', 'marketer-company-section', 'marketer-services-section', 'marketer-clients-section', 'marketer-submission-section') as $id) {
    $check("the editor has #$id", strpos($editor, 'id="' . $id . '"') !== false);
}
$check('the public editor says Submit for Review', strpos($editor, 'Submit for Review') !== false && strpos($render('editor', false), 'Save Marketer Project') !== false);
foreach (array('templates/data-form-admin.php', 'templates/data-form-public.php') as $tpl) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $tpl);
    $check("$tpl has the tab, the panel and the editor", strpos($src, 'data-category="marketers"') !== false
        && strpos($src, "\$kop_marketer_part = 'panel'") !== false && strpos($src, "\$kop_marketer_part = 'editor'") !== false);
}

if (!is_dir($out_dir)) {
    mkdir($out_dir, 0777, true);
}
$css = file_get_contents(dirname(__DIR__) . '/css/marketer-index.css');
file_put_contents($out_dir . '/marketing-companies.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . file_get_contents(dirname(__DIR__) . '/css/colors.css') . $css . '</style><div class="kop-mkt-page"><h1 class="kop-mkt-title">Marketing Companies</h1>' . $html . '</div>');
file_put_contents($out_dir . '/form-editor.html', $editor);
echo "Wrote $out_dir/marketing-companies.html\n";

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
