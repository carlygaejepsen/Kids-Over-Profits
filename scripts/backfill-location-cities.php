#!/usr/bin/env php
<?php
/**
 * Backfill missing locationDetails.city / .state on facilities stored in
 * locations_master.<STATE> rows.
 *
 * Many state pages were imported from source HTML where the address line
 * had no commas between street, city, and state (e.g.
 *   "12700 E 76th Street North Owasso OK 74055"
 * ). The original parser stored the raw line in facility.address but left
 * locationDetails.city empty. This script re-parses those raw lines using
 * the same street-suffix heuristic as the updated import-location-pages.js.
 *
 * Usage:
 *   php scripts/backfill-location-cities.php                 # dry-run, all states
 *   php scripts/backfill-location-cities.php --state=Oklahoma
 *   php scripts/backfill-location-cities.php --apply
 *   php scripts/backfill-location-cities.php --apply --backup-dir tmp/city-backfill
 *
 * By default only "auto" matches (those backed by a street-suffix split or
 * a PO Box pattern) are written. Records where the parser had to guess
 * with no suffix anchor are listed under "needs-review" so you can audit
 * them before applying with --aggressive.
 */

if (php_sapi_name() !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

function backfill_print_usage() {
    fwrite(STDOUT, <<<TEXT
Usage:
  php scripts/backfill-location-cities.php [options]

Options:
  --state=<name>      Limit to one state (e.g. Oklahoma). Repeatable.
                      Default: scan every locations_master row.
  --apply             Commit changes. Default is dry-run.
  --aggressive        Also write low-confidence guesses (no street suffix).
                      Default: only "auto" matches are written.
  --backup-dir=<dir>  Write a JSON backup of the rows that change.
                      Default: tmp/city-backfill-backups/<timestamp>/
  --json              Emit machine-readable JSON output.
  --help              Show this message.

TEXT
);
}

function backfill_parse_args($argv) {
    $options = [
        'states' => [],
        'apply' => false,
        'aggressive' => false,
        'backupDir' => '',
        'json' => false,
        'help' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $options['help'] = true; continue; }
        if ($arg === '--apply') { $options['apply'] = true; continue; }
        if ($arg === '--aggressive') { $options['aggressive'] = true; continue; }
        if ($arg === '--json') { $options['json'] = true; continue; }
        if (preg_match('/^--state=(.+)$/', $arg, $m)) { $options['states'][] = trim($m[1]); continue; }
        if (preg_match('/^--backup-dir=(.+)$/', $arg, $m)) { $options['backupDir'] = trim($m[1]); continue; }
        throw new InvalidArgumentException("Unknown argument: {$arg}");
    }
    return $options;
}

/* ------------------------------------------------------------------ *
 * Parser — mirrors guessCityFromCommalessAddress / extractLocationParts
 * in scripts/import-location-pages.js.
 * ------------------------------------------------------------------ */

const STREET_SUFFIX_RE = '(?:st|street|ave|avenue|blvd|boulevard|rd|road|dr|drive|ln|lane|ct|court|cir|circle|pl|place|hwy|highway|pkwy|parkway|ter|terrace|trl|trail|way|sq|square|loop|pike|plaza|plz|run|path|trace|crossing|alley|aly|expy|expressway|broadway|farm)';
const CARDINAL_AFTER_RE = '(?:n|s|e|w|ne|nw|se|sw|north|south|east|west|northeast|northwest|southeast|southwest)';

function backfill_normalize_ws($value) {
    return trim(preg_replace('/\s+/', ' ', (string)$value));
}

function backfill_guess_city_commaless($beforeState) {
    $beforeState = (string)$beforeState;
    if ($beforeState === '') {
        return ['city' => '', 'confidence' => 'none'];
    }

    if (preg_match_all('/\b' . STREET_SUFFIX_RE . '\b\.?/i', $beforeState, $matches, PREG_OFFSET_CAPTURE)) {
        $last = end($matches[0]);
        $splitIdx = $last[1] + strlen($last[0]);
        $rest = substr($beforeState, $splitIdx);
        if (preg_match('/^\s+' . CARDINAL_AFTER_RE . '\b\.?/i', $rest, $cardMatch)) {
            $splitIdx += strlen($cardMatch[0]);
        }
        $city = backfill_normalize_ws(substr($beforeState, $splitIdx));
        if ($city !== '') return ['city' => $city, 'confidence' => 'auto'];
    }

    if (preg_match('/^p\.?\s*o\.?\s*box\s+\d+\s+(.+)$/i', $beforeState, $m)) {
        return ['city' => backfill_normalize_ws($m[1]), 'confidence' => 'auto'];
    }

    $tokens = preg_split('/\s+/', trim($beforeState));
    if (count($tokens) >= 2) {
        return ['city' => $tokens[count($tokens) - 1], 'confidence' => 'guess'];
    }

    return ['city' => '', 'confidence' => 'none'];
}

function backfill_extract($rawAddress) {
    $cleaned = backfill_normalize_ws($rawAddress);
    $cleaned = preg_replace('/\s*\([^()]*\)\s*$/', '', $cleaned);
    $cleaned = preg_replace('/[;.,]+$/', '', $cleaned);
    $cleaned = backfill_normalize_ws($cleaned);
    if ($cleaned === '') return null;

    // Comma-based "(... ,)?City, XX [ZIP]" — the parser path that already works.
    if (preg_match('/(?:,\s*|^)([A-Za-z.\'\x{2019}\- ]+),?\s+([A-Z]{2})(?:\s+\d{5}(?:-\d{4})?)?$/u', $cleaned, $m)) {
        $city = backfill_normalize_ws(preg_replace('/^.*:\s*/', '', $m[1]));
        $state = strtoupper(trim($m[2]));
        // The legacy regex also matches a state-only string at $ (city group becomes empty after the colon strip).
        if ($city !== '' && !preg_match('/^\d/', $city)) {
            return ['city' => $city, 'state' => $state, 'confidence' => 'auto'];
        }
    }

    // Comma-less: "<street> <city> XX [ZIP]"
    if (preg_match('/^(.+?)\s+([A-Z]{2})(?:\s+\d{5}(?:-\d{4})?)?$/u', $cleaned, $m)) {
        $beforeState = trim($m[1]);
        $abbrev = strtoupper($m[2]);
        $guess = backfill_guess_city_commaless($beforeState);
        if ($guess['city'] !== '') {
            return ['city' => $guess['city'], 'state' => $abbrev, 'confidence' => $guess['confidence']];
        }
    }

    return null;
}

/* ------------------------------------------------------------------ */

try {
    $options = backfill_parse_args($argv);
    if ($options['help']) { backfill_print_usage(); exit(0); }

    require_once dirname(__DIR__) . '/api/config.php';

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('Database connection (\$pdo) not available from api/config.php');
    }

    $stateFilter = array_map('strtoupper', $options['states']);

    $sql = "SELECT unique_name, json_data FROM locations_master";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $perState = [];        // state => [scanned, alreadyHasCity, autoFixed, needsReview, noStateMatch]
    $reviewRows = [];      // sample rows for review
    $rowChanges = [];      // [unique_name => updatedJsonString] for --apply

    foreach ($rows as $row) {
        $uname = (string)$row['unique_name'];
        if ($stateFilter && !in_array(strtoupper($uname), $stateFilter, true)) continue;

        $data = json_decode($row['json_data'], true);
        if (!is_array($data) || empty($data['data']['facilities']) || !is_array($data['data']['facilities'])) {
            continue;
        }

        $facilities = &$data['data']['facilities'];
        $rowChanged = false;
        $stats = ['scanned' => 0, 'alreadyHasCity' => 0, 'autoFixed' => 0, 'needsReview' => 0, 'noStateMatch' => 0];

        foreach ($facilities as &$facility) {
            if (!is_array($facility)) continue;
            $stats['scanned']++;

            $existingCity = '';
            if (isset($facility['locationDetails']) && is_array($facility['locationDetails'])) {
                $existingCity = backfill_normalize_ws($facility['locationDetails']['city'] ?? '');
            }
            if ($existingCity !== '') { $stats['alreadyHasCity']++; continue; }

            $rawAddress = '';
            if (isset($facility['address']) && is_string($facility['address'])) {
                $rawAddress = backfill_normalize_ws($facility['address']);
            }
            if ($rawAddress === '') { continue; }

            $parsed = backfill_extract($rawAddress);
            if (!$parsed) { $stats['noStateMatch']++; continue; }

            // The address might belong to a different state than the row's name
            // (e.g. a TX facility filed under an OK aggregate by mistake). Don't
            // rewrite the state silently — only fill in what's missing.
            $isAuto = $parsed['confidence'] === 'auto';
            if (!$isAuto && !$options['aggressive']) {
                $stats['needsReview']++;
                if (count($reviewRows) < 200) {
                    $reviewRows[] = [
                        'state_row' => $uname,
                        'facility' => $facility['identification']['name'] ?? '',
                        'raw_address' => $rawAddress,
                        'guess_city' => $parsed['city'],
                        'guess_state' => $parsed['state'],
                    ];
                }
                continue;
            }

            if (!isset($facility['locationDetails']) || !is_array($facility['locationDetails'])) {
                $facility['locationDetails'] = ['city' => '', 'state' => '', 'country' => 'United States', 'additionalLocations' => []];
            }
            $facility['locationDetails']['city'] = $parsed['city'];
            if (empty($facility['locationDetails']['state'])) {
                $facility['locationDetails']['state'] = $parsed['state'];
            }
            $stateForLocation = $facility['locationDetails']['state'] ?: $parsed['state'];
            $facility['location'] = $parsed['city'] . ', ' . $stateForLocation;

            $stats[$isAuto ? 'autoFixed' : 'needsReview']++;
            $rowChanged = true;
        }
        unset($facility);

        $perState[$uname] = $stats;
        if ($rowChanged) {
            $rowChanges[$uname] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
    }

    // Write backups + apply.
    $backupFile = null;
    if ($options['apply'] && $rowChanges) {
        $backupDir = $options['backupDir'] !== ''
            ? $options['backupDir']
            : dirname(__DIR__) . '/tmp/city-backfill-backups/' . date('Ymd-His');
        if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $backupDir)) {
            $backupDir = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $backupDir), '/');
        }
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Failed to create backup directory: {$backupDir}");
        }
        $backupRows = [];
        foreach ($rowChanges as $uname => $_) {
            foreach ($rows as $r) {
                if ($r['unique_name'] === $uname) {
                    $backupRows[] = $r;
                    break;
                }
            }
        }
        $backupFile = rtrim($backupDir, '/\\') . '/city-backfill-backup.json';
        file_put_contents($backupFile, json_encode([
            'createdAt' => date('c'),
            'rows' => $backupRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("UPDATE locations_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :unique_name");
            foreach ($rowChanges as $uname => $jsonData) {
                $stmt->execute([':json_data' => $jsonData, ':unique_name' => $uname]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    $totals = ['scanned' => 0, 'alreadyHasCity' => 0, 'autoFixed' => 0, 'needsReview' => 0, 'noStateMatch' => 0];
    foreach ($perState as $stats) {
        foreach ($totals as $k => $_) $totals[$k] += $stats[$k];
    }

    if ($options['json']) {
        echo json_encode([
            'mode' => $options['apply'] ? 'apply' : 'dry-run',
            'aggressive' => $options['aggressive'],
            'totals' => $totals,
            'perState' => $perState,
            'reviewSample' => $reviewRows,
            'changedRowCount' => count($rowChanges),
            'backupFile' => $backupFile,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
        exit(0);
    }

    $mode = $options['apply'] ? 'APPLY' : 'DRY-RUN';
    fwrite(STDOUT, "Mode: {$mode}" . ($options['aggressive'] ? ' (aggressive)' : '') . PHP_EOL);
    fwrite(STDOUT, sprintf(
        "Totals: scanned=%d alreadyHasCity=%d autoFixed=%d needsReview=%d noStateMatch=%d%s",
        $totals['scanned'], $totals['alreadyHasCity'], $totals['autoFixed'], $totals['needsReview'], $totals['noStateMatch'], PHP_EOL
    ));
    fwrite(STDOUT, PHP_EOL . "Per-state breakdown (only states with at least one fixable facility):" . PHP_EOL);
    foreach ($perState as $uname => $stats) {
        if (($stats['autoFixed'] + $stats['needsReview']) === 0) continue;
        fwrite(STDOUT, sprintf(
            "  %-25s scanned=%3d already=%3d auto=%3d review=%3d noState=%3d%s",
            $uname, $stats['scanned'], $stats['alreadyHasCity'], $stats['autoFixed'], $stats['needsReview'], $stats['noStateMatch'], PHP_EOL
        ));
    }
    if ($reviewRows) {
        fwrite(STDOUT, PHP_EOL . "Sample needs-review (first " . count($reviewRows) . "):" . PHP_EOL);
        foreach ($reviewRows as $r) {
            fwrite(STDOUT, sprintf("  [%s] %s\n    addr: %s\n    guess: %s, %s%s",
                $r['state_row'], $r['facility'], $r['raw_address'], $r['guess_city'], $r['guess_state'], PHP_EOL
            ));
        }
    }
    if ($options['apply']) {
        fwrite(STDOUT, PHP_EOL . "Rows written: " . count($rowChanges) . PHP_EOL);
        if ($backupFile) fwrite(STDOUT, "Backup: " . $backupFile . PHP_EOL);
    } else {
        fwrite(STDOUT, PHP_EOL . "Run with --apply to commit. Add --aggressive to also write low-confidence guesses." . PHP_EOL);
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Error: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
