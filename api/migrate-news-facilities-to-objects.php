<?php
/**
 * Migrate news_submissions.facilities_mentioned from a string array to an
 * object array, and populate news_facility_links in the same pass.
 *
 * Before: ["Mountain View Academy", "Provo Canyon"]
 * After:  [{"name":"Mountain View Academy","facility_id":17}, {"name":"Provo Canyon","facility_id":null}]
 *
 * For each name, attempts an exact (case-insensitive) match against
 * facilities_master.unique_name. Matched names get facility_id stamped AND a
 * row inserted into news_facility_links. Unmatched names keep facility_id=null
 * and are reported back so they can be fixed up via the admin UI later.
 *
 * Matching uses the shared alias index (api/facility-aliases.php), so beyond
 * unique_name it also resolves operating-company names and the curated
 * "Other Names / DBAs / Former Names" admins maintain in the facility editor.
 * That means renamed facilities and operators (e.g. an article saying
 * "Acadia Healthcare") link even when the mention doesn't equal the unique_name.
 * An alias that resolves to two different facilities is dropped as ambiguous.
 *
 * Optional fuzzy pass (?fuzzy=1 or CLI "fuzzy"): for names the exact pass could
 * not match, link on a *distinctive token* - a word that (a) belongs to exactly
 * one facility's unique_name, (b) isn't a generic facility/place word, and
 * (c) is >= 4 chars. This catches "Anasazi" -> "Anasazi Foundation" or
 * "Sununu Youth Services" -> "John H. Sununu Youth Services Center" without the
 * false positives a full-string fuzzy match would produce on words like
 * "academy" or "ranch". A name only links if its distinctive tokens all point
 * at a single facility; ambiguous names are left unmatched. Always dry-run and
 * review the `fuzzy_matches` report before committing.
 *
 * Safe to re-run: rows already in object shape are left intact; INSERT IGNORE
 * prevents duplicate link rows.
 *
 * Safety: must be invoked with ?run=1 from a logged-in admin, or from CLI.
 * Pass ?dry=1 to see what WOULD change without writing.
 *
 *   Browser:  /api/migrate-news-facilities-to-objects.php?run=1
 *   Dry run:  /api/migrate-news-facilities-to-objects.php?run=1&dry=1
 *   Fuzzy:    /api/migrate-news-facilities-to-objects.php?run=1&fuzzy=1&dry=1
 *   CLI:      php api/migrate-news-facilities-to-objects.php
 *   CLI dry:  php api/migrate-news-facilities-to-objects.php dry
 *   CLI fuzzy:php api/migrate-news-facilities-to-objects.php fuzzy dry
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/news-mentions.php';
require_once __DIR__ . '/facility-aliases.php';

$is_cli = php_sapi_name() === 'cli';
$run_param = $_GET['run'] ?? null;
$dry_run = $is_cli
    ? (in_array('dry', $argv ?? [], true))
    : !empty($_GET['dry']);
$fuzzy = $is_cli
    ? (in_array('fuzzy', $argv ?? [], true))
    : !empty($_GET['fuzzy']);

if (!$is_cli) {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) {
                require_once $current . '/wp-load.php';
                break;
            }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');

    if ($run_param !== '1' || !$is_admin) {
        http_response_code(403);
        echo json_encode([
            'success' => false,
            'error'   => 'Migration requires ?run=1 and an admin session, or CLI execution.',
        ]);
        exit;
    }
}

try {
    // Build the name -> facility_id index from facilities_master. This covers
    // unique_name, operator names, and the curated "Other Names / Former Names"
    // aliases - the same index the save endpoint uses, so backfill and live saves
    // match identically. See api/facility-aliases.php.
    $index = kop_build_facility_alias_index($pdo);
    $facilityMap   = $index['exact'];  // lower(name) => id  (uniques + non-ambiguous aliases)
    $facilityNames = $index['names'];  // id => unique_name (for the audit log)

    $news = $pdo->query("SELECT id, facilities_mentioned FROM news_submissions")->fetchAll();

    $stats = [
        'news_scanned'         => 0,
        'news_rewritten'       => 0,
        'mentions_upgraded'    => 0, // string -> object conversions
        'mentions_id_stamped'  => 0, // entries that gained a facility_id (exact)
        'mentions_fuzzy_matched' => 0, // entries linked via distinctive token
        'mentions_unmatched'   => 0,
        'links_inserted'       => 0,
        'unmatched_names'      => [],
        'fuzzy_matches'        => [], // audit: [{mention, facility_id, matched_name, via_token}]
    ];
    $unmatchedSeen = [];

    $updateStmt = $pdo->prepare(
        "UPDATE news_submissions SET facilities_mentioned = ? WHERE id = ?"
    );
    $insertLinkStmt = $pdo->prepare(
        "INSERT IGNORE INTO news_facility_links (news_id, facility_id, link_type, created_by)
         VALUES (?, ?, 'mentioned', 'migration')"
    );

    foreach ($news as $n) {
        $stats['news_scanned']++;

        $raw = $n['facilities_mentioned'];
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) $decoded = [];

        $normalized = kop_normalize_facility_mentions($decoded);
        if (empty($normalized) && empty($decoded)) {
            continue; // truly empty - nothing to do
        }

        $rowChanged = false;

        // Count the upgrades: anything that was a string in the original input.
        foreach ($decoded as $orig) {
            if (is_string($orig) && trim($orig) !== '') {
                $stats['mentions_upgraded']++;
                $rowChanged = true;
            }
        }

        // Try to stamp facility_id on any entry that doesn't have one.
        foreach ($normalized as $i => $m) {
            if (!empty($m['facility_id'])) continue;
            $key = strtolower($m['name']);
            if (isset($facilityMap[$key])) {
                $normalized[$i]['facility_id'] = $facilityMap[$key];
                $stats['mentions_id_stamped']++;
                $rowChanged = true;
                continue;
            }

            $fz = $fuzzy ? kop_fuzzy_resolve_name($m['name'], $index) : null;
            if ($fz) {
                $normalized[$i]['facility_id'] = $fz['facility_id'];
                $stats['mentions_fuzzy_matched']++;
                $rowChanged = true;
                if (count($stats['fuzzy_matches']) < 500) {
                    $stats['fuzzy_matches'][] = [
                        'mention'      => $m['name'],
                        'facility_id'  => $fz['facility_id'],
                        'matched_name' => $facilityNames[$fz['facility_id']] ?? null,
                        'via_token'    => $fz['token'],
                    ];
                }
                continue;
            }

            $stats['mentions_unmatched']++;
            if (!isset($unmatchedSeen[$key]) && count($stats['unmatched_names']) < 500) {
                $unmatchedSeen[$key] = true;
                $stats['unmatched_names'][] = $m['name'];
            }
        }

        if ($rowChanged && !$dry_run) {
            $updateStmt->execute([
                json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $n['id'],
            ]);
        }
        if ($rowChanged) {
            $stats['news_rewritten']++;
        }

        // Populate news_facility_links for any matched ids in this article.
        if (!$dry_run) {
            foreach ($normalized as $m) {
                if (empty($m['facility_id'])) continue;
                $insertLinkStmt->execute([(int)$n['id'], (int)$m['facility_id']]);
                if ($insertLinkStmt->rowCount() > 0) $stats['links_inserted']++;
            }
        } else {
            // Dry-run: still count what we WOULD insert.
            foreach ($normalized as $m) {
                if (!empty($m['facility_id'])) $stats['links_inserted']++;
            }
        }
    }

    echo json_encode([
        'success' => true,
        'dry_run' => (bool)$dry_run,
        'fuzzy'   => (bool)$fuzzy,
        'stats'   => $stats,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
