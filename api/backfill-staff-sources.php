<?php
/**
 * Give staff added before entries kept their source (Woodbury Facts and
 * Fornits staff applied before 2026-10-01) the citation and link of the item
 * that added them, so the facility page names where each came from
 * (kop_wbf_backfill_staff_sources() in inc/woodbury-facts.php). Undo of those
 * items still takes the entry back exactly.
 * CLI only:
 *   php api/backfill-staff-sources.php          dry run: count what it would change
 *   php api/backfill-staff-sources.php apply    write the sources
 * Running it again changes nothing: entries that have a source are left alone.
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('CLI only.');
}
$apply = in_array('apply', $argv, true);
set_time_limit(0);

$current = __DIR__;
for ($i = 0; $i < 6; $i++) {
    $current = dirname($current);
    if (file_exists($current . '/wp-load.php')) {
        require_once $current . '/wp-load.php';
        break;
    }
}
if (!defined('ABSPATH') || !function_exists('kop_wbf_backfill_staff_sources')) {
    fwrite(STDERR, "WordPress or inc/woodbury-facts.php did not load.\n");
    exit(1);
}

$s = kop_wbf_backfill_staff_sources($apply);
printf("%s: %d applied staff items on record; %d entries %s on %d facilities, %d already had one, %d no longer on the record\n",
    $apply ? 'Applied' : 'Dry run', $s['rows'], $s['sourced'], $apply ? 'given their source' : 'would get their source',
    $s['facilities'], $s['already'], $s['missing']);
