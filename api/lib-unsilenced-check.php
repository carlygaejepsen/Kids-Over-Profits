<?php
/**
 * The monthly Unsilenced check (api/list-unsilenced-files.php --check): what
 * Unsilenced added to its archive since the build the pages show, and the
 * mail that says so. Plain PHP apart from the mail, so
 * scripts/test-unsilenced-archive.php runs it offline.
 */

/** Names that are not documents (the build skips them too). */
function kop_unsilenced_check_is_junk($name) {
    return (bool) preg_match('/(^desktop\.ini$|^thumbs\.db$|\.(ini|json|db|ds_store|lnk|tmp)$)/i', (string) $name);
}

/**
 * {complete, built, files, new, new_listed, top: [{folder, new, listed}]}
 * for a complete listing. "New" is a file Drive says was created after the
 * build date in index.json; "listed" means its program folder is one the
 * facility and operator pages already list (the shards' folder ids).
 */
function kop_unsilenced_check_summary($files_file, $data_dir) {
    $index = json_decode((string) @file_get_contents($data_dir . '/index.json'), true);
    $built = is_array($index) ? (string) ($index['built'] ?? '') : '';

    $listed_folders = array();
    foreach (array('f', 'o') as $kind) {
        foreach ((array) glob($data_dir . '/' . $kind . '/*.json') as $shard) {
            $data = json_decode((string) file_get_contents($shard), true);
            foreach ((array) ($data['folders'] ?? array()) as $f) {
                if (!empty($f['id'])) $listed_folders[(string) $f['id']] = true;
            }
        }
    }

    $summary = array('complete' => true, 'built' => $built, 'files' => 0, 'new' => 0, 'new_listed' => 0, 'top' => array());
    $by_folder = array();
    $fh = @fopen($files_file, 'r');
    if (!$fh) return array('complete' => false, 'error' => 'no file list at ' . $files_file);
    while (($line = fgets($fh)) !== false) {
        $r = json_decode($line, true);
        if (!is_array($r) || kop_unsilenced_check_is_junk($r['name'] ?? '')) continue;
        $summary['files']++;
        $created = substr((string) ($r['created'] ?? ''), 0, 10);
        if ($built === '' || $created === '' || strcmp($created, $built) <= 0) continue;
        $summary['new']++;
        $listed = isset($listed_folders[(string) ($r['program'] ?? '')]);
        if ($listed) $summary['new_listed']++;
        $path = (array) ($r['path'] ?? array());
        $label = implode(' / ', array_slice($path, 0, 2));
        if (!isset($by_folder[$label])) $by_folder[$label] = array('folder' => $label, 'new' => 0, 'listed' => $listed);
        $by_folder[$label]['new']++;
    }
    fclose($fh);
    usort($by_folder, function ($a, $b) { return $b['new'] - $a['new'] ?: strcmp($a['folder'], $b['folder']); });
    $summary['top'] = array_slice(array_values($by_folder), 0, 20);
    return $summary;
}

/** Mail when the check could not finish, or when there is enough new to rebuild for. */
function kop_unsilenced_check_should_mail(array $summary, $min_listed = 10) {
    if (empty($summary['complete'])) return true;
    return $summary['new_listed'] >= $min_listed || $summary['new'] >= 100;
}

/** The summary in plain text, for the cron log and the mail. */
function kop_unsilenced_check_text(array $summary) {
    if (empty($summary['complete'])) {
        return 'The check did not finish'
            . (isset($summary['folders_left']) ? ' (' . $summary['folders_left'] . ' folders left to list)' : '')
            . (isset($summary['error']) ? ': ' . $summary['error'] : '') . ".\n";
    }
    if ($summary['built'] === '') {
        return "No build in js/data/unsilenced/index.json to compare with.\n";
    }
    $out = sprintf("%d files in the archive; %d added since the build of %s, %d of them in programs the pages already list.\n",
        $summary['files'], $summary['new'], $summary['built'], $summary['new_listed']);
    foreach ($summary['top'] as $t) {
        $out .= sprintf("  %4d  %s%s\n", $t['new'], $t['folder'], $t['listed'] ? '' : ' (not listed on KOP yet)');
    }
    return $out;
}

/** Send the summary to the site's notification list. */
function kop_unsilenced_check_mail(array $summary, $files_file) {
    if (!function_exists('wp_mail')) return false;
    $to = function_exists('kop_submission_notify_recipients') ? kop_submission_notify_recipients() : array(get_option('admin_email'));
    $to = array_filter((array) $to);
    if (!$to) return false;
    $site = function_exists('kop_submission_site_name') ? kop_submission_site_name() : 'Kids Over Profits';

    if (empty($summary['complete'])) {
        $subject = '[' . $site . '] The monthly Unsilenced check did not finish';
        $body = "The monthly check of Unsilenced's archive stopped before it had listed every folder.\n\n"
            . kop_unsilenced_check_text($summary)
            . "\nIt starts over next month. If this repeats, the Google Drive connection"
            . " (FileBird > Cloud) may need reconnecting.\n";
    } else {
        $subject = sprintf('[%s] Unsilenced added %d documents', $site, $summary['new']);
        $body = "Unsilenced has added documents to its archive since the facility and operator"
            . " pages were last built.\n\n"
            . kop_unsilenced_check_text($summary)
            . "\nTo put them on the pages, ask for a rebuild of the Unsilenced lists."
            . " The new file list is ready on the server:\n" . $files_file . "\n";
    }
    $body .= "\nSent by the monthly Unsilenced check (api/list-unsilenced-files.php --check).";
    return (bool) @wp_mail(array_values($to), $subject, $body);
}
