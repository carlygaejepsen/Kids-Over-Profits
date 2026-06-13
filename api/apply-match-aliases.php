<?php
/**
 * Apply curated, hidden match-aliases to facilities_master rows.
 *
 * Reads a mapping of { target unique_name => [alias, ...] } and writes each
 * alias into that row's json_data at data.matchAliases - a match-only list the
 * news linker consults (see api/facility-aliases.php) but no display code
 * renders. Used to make article mentions like "Anasazi" or "Calo" resolve to
 * "Anasazi Foundation" / "CALO Programs" without changing the facility card.
 *
 * Source of the mapping (first that applies):
 *   1. JSON POST body: { "aliases": [ {"id":123,"names":["a","b"]}, ... ] }
 *   2. api/match-aliases-seed.json (committed default)
 *
 * Each entry targets a row by "id" (preferred) or, as a fallback, by "target"
 * matched against unique_name.
 *
 * Idempotent: an alias already present (as a matchAlias, otherName, pastName,
 * the operator name, or the unique_name itself, compared case-insensitively) is
 * skipped. Re-running adds only what's new.
 *
 * Safety: requires ?run=1 from a logged-in admin, or CLI. Pass ?dry=1 (or CLI
 * "dry") to preview without writing.
 *
 *   Preview: /api/apply-match-aliases.php?run=1&dry=1
 *   Apply:   /api/apply-match-aliases.php?run=1
 *   CLI:     php api/apply-match-aliases.php [dry]
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

$is_cli   = php_sapi_name() === 'cli';
$dry_run  = $is_cli ? in_array('dry', $argv ?? [], true) : !empty($_GET['dry']);
$run_ok   = $is_cli || (($_GET['run'] ?? null) === '1');

if (!$is_cli) {
    if (!defined('ABSPATH')) {
        $current = __DIR__;
        for ($i = 0; $i < 6; $i++) {
            $current = dirname($current);
            if (file_exists($current . '/wp-load.php')) { require_once $current . '/wp-load.php'; break; }
        }
    }
    $is_admin = function_exists('current_user_can') && current_user_can('manage_options');
    if (!$run_ok || !$is_admin) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Requires ?run=1 and an admin session, or CLI execution.']);
        exit;
    }
}

/** Case-insensitive presence test using the shared normalization. */
require_once __DIR__ . '/facility-aliases.php';

try {
    // Resolve the mapping source.
    $aliases = null;
    $raw = $is_cli ? '' : file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true);
        if (is_array($body) && isset($body['aliases']) && is_array($body['aliases'])) {
            $aliases = $body['aliases'];
        }
    }
    if ($aliases === null) {
        $seedPath = __DIR__ . '/match-aliases-seed.json';
        if (!file_exists($seedPath)) {
            throw new Exception('No POST body provided and match-aliases-seed.json not found.');
        }
        $seed = json_decode((string)file_get_contents($seedPath), true);
        $aliases = (is_array($seed) && isset($seed['aliases']) && is_array($seed['aliases'])) ? $seed['aliases'] : [];
    }

    $findById   = $pdo->prepare("SELECT id, unique_name, json_data FROM facilities_master WHERE id = ? LIMIT 1");
    $findByName = $pdo->prepare("SELECT id, unique_name, json_data FROM facilities_master WHERE unique_name = ? LIMIT 1");
    $update     = $pdo->prepare("UPDATE facilities_master SET json_data = ? WHERE id = ?");

    $stats = [
        'targets_in_map'    => count($aliases),
        'targets_updated'   => 0,
        'aliases_added'     => 0,
        'aliases_skipped'   => 0, // already present
        'targets_not_found' => [],
        'added'             => [], // [{target, alias}]
    ];

    foreach ($aliases as $entry) {
        if (!is_array($entry)) continue;
        $list   = $entry['names'] ?? null;
        $id     = isset($entry['id']) ? (int)$entry['id'] : 0;
        $target = (string)($entry['target'] ?? ($id ? "id $id" : ''));
        if (!is_array($list)) continue;

        if ($id > 0) {
            $findById->execute([$id]);
            $row = $findById->fetch();
        } else {
            $findByName->execute([$target]);
            $row = $findByName->fetch();
        }
        if (!$row) { $stats['targets_not_found'][] = $target; continue; }

        $decoded = json_decode((string)$row['json_data'], true);
        if (!is_array($decoded)) { $stats['targets_not_found'][] = $target . ' (unparseable json_data)'; continue; }

        // Names this row already answers to, for idempotency. Seed from the row's
        // REAL unique_name - NOT the human "target" label, which may equal an
        // alias we actually need to write (that bug skipped Bethel/Cumberland/
        // Laurel Ridge, whose alias text matched their label).
        $existing = [];
        if (!empty($row['unique_name'])) {
            $existing[kop_normalize_name_key((string)$row['unique_name'])] = true;
        }
        foreach (kop_collect_self_names($decoded) as $known) {
            $existing[kop_normalize_name_key($known)] = true;
        }
        foreach (kop_collect_match_aliases($decoded) as $known) {
            $existing[kop_normalize_name_key($known)] = true;
        }

        if (!isset($decoded['data']) || !is_array($decoded['data'])) $decoded['data'] = [];
        if (!isset($decoded['data']['matchAliases']) || !is_array($decoded['data']['matchAliases'])) {
            $decoded['data']['matchAliases'] = [];
        }

        $changed = false;
        foreach ($list as $alias) {
            $alias = trim((string)$alias);
            if ($alias === '') continue;
            $k = kop_normalize_name_key($alias);
            if ($k === '' || isset($existing[$k])) { $stats['aliases_skipped']++; continue; }
            $decoded['data']['matchAliases'][] = $alias;
            $existing[$k] = true;
            $changed = true;
            $stats['aliases_added']++;
            $stats['added'][] = ['target' => $target, 'alias' => $alias];
        }

        if ($changed) {
            $stats['targets_updated']++;
            if (!$dry_run) {
                $update->execute([
                    json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    (int)$row['id'],
                ]);
            }
        }
    }

    echo json_encode(['success' => true, 'dry_run' => (bool)$dry_run, 'stats' => $stats],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Error: ' . $e->getMessage()]);
}
