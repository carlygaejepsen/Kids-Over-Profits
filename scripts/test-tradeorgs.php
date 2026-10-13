<?php
/**
 * Offline checks for trade organizations (api/lib-tradeorgs.php, inc/tradeorgs.php, the data forms'
 * Trade Organizations tab in templates/data-form-tradeorg.php + js/data-form/tradeorg-form.js).
 *
 *   php -d extension=pdo_sqlite -d extension=mbstring scripts/test-tradeorgs.php [--db=tmp/prod.sqlite] [--out=tmp/tradeorgs]
 *
 * tradeorgs_master is made in an in-memory database; the members each organization lists come from the
 * facility records in the mirror. Writes the rendered page to --out.
 */

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

$args = getopt('', array('db::', 'out::'));
$db_path = $args['db'] ?? (dirname(__DIR__) . '/tmp/prod.sqlite');
$out_dir = $args['out'] ?? (dirname(__DIR__) . '/tmp/tradeorgs');
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

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
if (!function_exists('kop_seed_pdo')) {
    function kop_seed_pdo() { return $GLOBALS['kop_test_tro_pdo']; }
}
$GLOBALS['kop_test_tro_pdo'] = $db;
if (!function_exists('kop_transporters_table')) {
    require_once dirname(__DIR__) . '/inc/utilities.php';
}
require_once dirname(__DIR__) . '/inc/marketers.php';
require_once dirname(__DIR__) . '/inc/tradeorgs.php';

$failures = 0;
$check = function ($label, $ok, $detail = '') use (&$failures) {
    if (!$ok) $failures++;
    echo ($ok ? 'PASS ' : 'FAIL ') . $label . ($detail !== '' ? "  ($detail)" : '') . "\n";
};

echo "-- Record shape (api/lib-tradeorgs.php) --\n";
$form = array('operator' => array('name' => ''), 'facilities' => array(array()), 'marketerCompany' => array('name' => ''),
    'tradeOrg' => array('name' => ' Example Alliance ', 'acronym' => 'EXA', 'otherNames' => array('', 'Old Name'),
        'activities' => array('lobbying' => true, 'bogus' => true), 'publications' => "Journal\nNewsletter"));
$kept = kop_tradeorg_keep_data($form);
$check('only the tradeOrg block and the tag are kept', array_keys($kept) === array('tradeOrg', 'category') && $kept['category'] === 'tradeorgs');
$check('text trimmed, lists cleaned and split', $kept['tradeOrg']['name'] === 'Example Alliance' && $kept['tradeOrg']['otherNames'] === array('Old Name')
    && $kept['tradeOrg']['publications'] === array('Journal', 'Newsletter'));
$check('activities: only known keys, all present', $kept['tradeOrg']['activities']['lobbying'] === true && !isset($kept['tradeOrg']['activities']['bogus'])
    && count($kept['tradeOrg']['activities']) === count(kop_tradeorg_activity_labels()));
$check('has data: by tag or by name', kop_tradeorg_has_data(array('category' => 'tradeorgs')) && kop_tradeorg_has_data($form)
    && !kop_tradeorg_has_data(array('marketerCompany' => array('name' => 'X'))));
$merged = kop_tradeorg_merge(array('name' => 'A', 'founded' => '1999', 'lobbying' => array('x')), array('name' => 'A', 'founded' => '', 'lobbying' => array('y', 'x')));
$check('merge keeps filled text and joins lists', $merged['founded'] === '1999' && $merged['lobbying'] === array('x', 'y'));

echo "-- Matching memberships on facility records --\n";
$natsap = array('name' => 'National Association of Therapeutic Schools and Programs', 'acronym' => 'NATSAP', 'otherNames' => array());
$aee = array('name' => 'Association for Experiential Education', 'acronym' => 'AEE', 'otherNames' => array());
$cases = array(
    array($natsap, 'NATSAP (former member; member from 1999, last listed 2011)', 'former'),
    array($natsap, 'NATSAP (member since 2005; still listed on natsap.org in Sep 2026)', 'current'),
    array($natsap, 'National Association of Therapeutic Schools and Programs', 'current'),
    array($natsap, 'NATSAPS', ''),
    array($aee, 'Association of Experiential Education', ''),
    array($aee, 'aee', ''),
    array($aee, 'AEE (past member)', 'former'),
);
foreach ($cases as $c) {
    $got = kop_tradeorg_membership_match($c[1], $c[0]);
    $check('"' . $c[1] . '" -> ' . ($c[2] === '' ? 'no match' : $c[2]), $got === $c[2], $got);
}

echo "-- Seed (seeds/tradeorgs.json) --\n";
$seed = json_decode(file_get_contents(dirname(__DIR__) . '/seeds/tradeorgs.json'), true);
$added = kop_tradeorgs_apply_seed($db);
$check('every seed record is added', count($added) === count($seed['records']), implode(', ', $added));
$check('each seed record cites sources', count(array_filter($seed['records'], function ($r) { return empty($r['sourceUrls']); })) === 0);
$check('a second run adds nothing', kop_tradeorgs_apply_seed($db) === array());

echo "-- The page (/trade-organizations/) --\n";
$all = kop_tradeorgs_all();
$names = array_map(function ($o) { return $o['org']['name']; }, $all);
$sorted = $names;
usort($sorted, 'strcasecmp');
$check('listed A to Z', $names === $sorted, $names[0]);
foreach ($all as $o) {
    $m = kop_tradeorgs_members($o['org']);
    echo '     ' . str_pad($o['org']['acronym'] ?: $o['org']['name'], 12) . count($m['current']) . ' current, ' . count($m['former']) . " former member records\n";
    if ($o['org']['acronym'] === 'NATSAP') {
        $check('NATSAP: its members come from the records, current and former', count($m['current']) > 20 && count($m['former']) > 20);
        $sample = $m['former'][0] ?? null;
        $check('a member links to its facility page', $sample && strpos($sample['url'], '/facility/') !== false, $sample['url'] ?? '');
    }
}
foreach ($all as $o) {
    if ($o['org']['acronym'] === 'AMATS') {
        $t = kop_tradeorgs_transport_members($o['org']);
        echo '     AMATS: ' . count($t) . " transport company records list it
";
        $check('AMATS: transport companies come from their records', count($t) >= 5, implode('; ', array_column($t, 'name')));
    }
}
ob_start();
kop_tradeorgs_render_public();
$html = ob_get_clean();
$check('every organization has its own anchor', substr_count($html, 'class="kop-tro-org" id="kop-tro-') === count($all));
$check('the acronym prints beside the name', strpos($html, '(NATSAP)</span>') !== false);
$check('members are listed under a summary', strpos($html, 'Programs on file that list it as a former membership') !== false);
$check('activities print as words', strpos($html, 'Funds or publishes research') !== false);
$check('sources read as words, not addresses', strpos($html, '>https://kidsoverprofits.org/') === false);

echo "-- Other tools: Woodbury, wiki links, finder, people --\n";
require_once dirname(__DIR__) . '/inc/woodbury-create.php';
require_once dirname(__DIR__) . '/inc/woodbury-facts.php';
require_once dirname(__DIR__) . '/inc/wiki-updates.php';
require_once dirname(__DIR__) . '/api/lib-wiki-link-label.php';
require_once dirname(__DIR__) . '/inc/people.php';
$row = $db->query("SELECT id, unique_name, json_data FROM tradeorgs_master WHERE unique_name = 'Independent Small Programs Alliance'")->fetch(PDO::FETCH_ASSOC);
$payload = json_decode($row['json_data'], true);
$check('Woodbury can create and file under one', isset(kop_wbc_kinds()['tradeorg']) && function_exists('kop_wbc_create_tradeorg')
    && isset(kop_wbf_create_kinds()['tradeorg']) && (kop_wbc_record_tables()['tradeorg']['table'] ?? '') === 'tradeorgs_master');
$check('a tradeorg token parses', kop_wbc_parse_record_token('tradeorg:' . $row['id']) === array('tradeorg', (int) $row['id']));
$check('found by its acronym', isset(kop_wbc_record_names('tradeorg', $row['unique_name'], $payload)['ISPA']));
$check('its place', kop_wbc_record_place('tradeorg', $payload) === 'Clearwater, FL', kop_wbc_record_place('tradeorg', $payload));
$check('notes go in the tradeOrg block', kop_wbc_note_paths('tradeorg', $payload) === array(array('data', 'tradeOrg', 'notes')));
$token = 'tradeorg:' . $row['id'];
$check('a wiki link shows its name', isset(kop_wiki_upd_other_kinds()['tradeorg']) && kop_wiki_link_labels($db, array($token))[$token] === 'Independent Small Programs Alliance');
$check('the People screens name the kind', (kop_people_kinds()['tradeorg'] ?? '') === 'trade organization');

echo "-- The form (templates/data-form-tradeorg.php == js/data-form/tradeorg-form.js) --\n";
$render = function ($part, $public) {
    $kop_tradeorg_part = $part;
    $kop_tradeorg_public = $public;
    ob_start();
    include dirname(__DIR__) . '/templates/data-form-tradeorg.php';
    return ob_get_clean();
};
$editor = $render('editor', true);
$panel = $render('panel', false);
$js = file_get_contents(dirname(__DIR__) . '/js/data-form/tradeorg-form.js');
$js_list = function ($const) use ($js) {
    preg_match('/const ' . $const . ' = \[(.*?)\];/s', $js, $m);
    preg_match_all("/'([^']+)'/", $m[1] ?? '', $k);
    return $k[1];
};
$check('activity keys: JS == PHP', $js_list('TRADEORG_ACTIVITY_KEYS') === array_keys(kop_tradeorg_activity_labels()));
$check('text fields: JS == PHP', $js_list('TRADEORG_TEXT_FIELDS') === kop_tradeorg_text_fields());
$check('list fields: JS == PHP', $js_list('TRADEORG_LIST_FIELDS') === kop_tradeorg_list_fields());
$missing = array();
foreach (kop_tradeorg_text_fields() as $field) {
    $id = 'tradeorg-' . strtolower(preg_replace('/([A-Z])/', '-$1', $field));
    if (strpos($editor, 'id="' . $id . '"') === false) $missing[] = '#' . $id;
}
foreach (kop_tradeorg_list_fields() as $field) {
    if (strpos($editor, 'data-path="tradeOrg.' . $field . '"') === false) $missing[] = $field;
}
foreach (array_keys(kop_tradeorg_activity_labels()) as $key) {
    if (strpos($editor, 'data-activity="' . $key . '"') === false) $missing[] = $key;
}
foreach (array('tradeorg-project-name', 'save-tradeorg-project-btn', 'tradeorg-org-section', 'tradeorg-activities-section', 'tradeorg-members-section', 'tradeorg-submission-section') as $id) {
    if (strpos($editor, 'id="' . $id . '"') === false) $missing[] = '#' . $id;
}
foreach (array('tradeorg-search-input', 'tradeorg-saved-projects-list', 'new-project-btn-tradeorg', 'export-all-btn-tradeorg', 'generate-report-btn-tradeorg') as $id) {
    if (strpos($panel, 'id="' . $id . '"') === false) $missing[] = '#' . $id;
}
$check('every field, box, list and button the JS uses is in the markup', !$missing, implode(', ', $missing));
foreach (array('templates/data-form-admin.php', 'templates/data-form-public.php') as $tpl) {
    $src = file_get_contents(dirname(__DIR__) . '/' . $tpl);
    $check("$tpl has the tab, the panel and the editor", strpos($src, 'data-category="tradeorgs"') !== false
        && strpos($src, "\$kop_tradeorg_part = 'panel'") !== false && strpos($src, "\$kop_tradeorg_part = 'editor'") !== false);
}

if (!is_dir($out_dir)) {
    mkdir($out_dir, 0777, true);
}
file_put_contents($out_dir . '/trade-organizations.html', '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><style>'
    . file_get_contents(dirname(__DIR__) . '/css/colors.css') . file_get_contents(dirname(__DIR__) . '/css/tradeorg-index.css')
    . '</style><div class="kop-tro-page"><h1 class="kop-tro-title">Trade Organizations</h1>' . $html . '</div>');
echo "Wrote $out_dir/trade-organizations.html\n";

echo $failures ? "\n$failures FAILED\n" : "\nAll passed\n";
exit($failures ? 1 : 0);
