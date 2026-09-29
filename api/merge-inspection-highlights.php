<?php
/**
 * Merge approved severe findings of one facility on one calendar day into a
 * single entry (kop_ih_merge_same_day in inc/inspection-highlights.php).
 * Approving a finding in api/review-inspection-highlights.php already does
 * this for its facility; this runs it over every facility, for findings
 * approved before the rule existed.
 *
 * CLI (use the CLI binary on the server, /opt/cpanel/ea-php82/root/usr/bin/php):
 *   php api/merge-inspection-highlights.php [apply]
 *
 * Browser, as an administrator: a dry run by default, ?apply=1 to save.
 */

$kop_ih_cli = php_sapi_name() === 'cli';

require_once __DIR__ . '/config.php';
require_once dirname(__DIR__) . '/inc/inspection-highlights.php';

if ($kop_ih_cli) {
    while (ob_get_level() > 0) ob_end_clean();
    $apply = in_array('apply', $argv, true);
} else {
    if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Not authorized. Log in to WordPress as an administrator first.';
        exit;
    }
    header('Content-Type: text/plain; charset=utf-8');
    $apply = !empty($_GET['apply']);
}

if (!$pdo) {
    echo "No database connection.\n";
    exit(1);
}

try {
    $merges = kop_ih_merge_same_day($pdo, $apply);
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . "\n";
    exit(1);
}

$gone = 0;
foreach ($merges as $m) {
    $gone += count($m['merged']) + count($m['identical']);
    echo "#{$m['keep']}  facility {$m['facility_id']}  {$m['date']}";
    if ($m['identical']) echo '  identical, deleted: #' . implode(', #', $m['identical']);
    if ($m['merged']) echo '  combined: #' . implode(', #', $m['merged']);
    echo "\n    " . str_replace("\n\n", "\n    ", $m['excerpt']) . "\n\n";
}
echo ($apply ? 'Saved' : 'Dry run') . ': ' . count($merges) . ' same-day groups, ' . $gone . " entries folded into them.\n";
if (!$apply && $merges) echo ($kop_ih_cli ? "Add apply to save.\n" : "Add ?apply=1 to save.\n");
