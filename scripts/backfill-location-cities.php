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
 *   # Review-and-approve workflow for low-confidence guesses:
 *   php scripts/backfill-location-cities.php --export-review=/tmp/review.json
 *   # edit /tmp/review.json: fix bad street/city values, set "approve":false to skip
 *   php scripts/backfill-location-cities.php --apply-review=/tmp/review.json
 *
 * By default only "auto" matches (those backed by a street-suffix split or
 * a PO Box pattern) are written. Records where the parser had to guess
 * with no suffix anchor are listed under "needs-review" so you can audit
 * them before applying with --aggressive, or export them with
 * --export-review for hand-editing.
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
  --state=<name>           Limit to one state (e.g. Oklahoma). Repeatable.
                           Default: scan every locations_master row.
  --apply                  Commit changes. Default is dry-run.
  --aggressive             Also write low-confidence guesses (no street suffix).
                           Default: only "auto" matches are written.
  --backup-dir=<dir>       Write a JSON backup of the rows that change.
                           Default: tmp/city-backfill-backups/<timestamp>/
  --json                   Emit machine-readable JSON output.
  --export-review=<file>   Write all "needs review" + unparseable rows to a
                           JSON file you can hand-edit. Includes every row
                           (no 200-row cap) with an "approve":true flag per
                           record. Implies dry-run.
  --apply-review=<file>    Read an edited review file and commit only rows
                           where "approve" is true, using whatever
                           street/city/state/zip values are in the file.
                           Skips other modes; cannot be combined with --apply.
  --help                   Show this message.

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
        'exportReview' => '',
        'applyReview' => '',
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') { $options['help'] = true; continue; }
        if ($arg === '--apply') { $options['apply'] = true; continue; }
        if ($arg === '--aggressive') { $options['aggressive'] = true; continue; }
        if ($arg === '--json') { $options['json'] = true; continue; }
        if (preg_match('/^--state=(.+)$/', $arg, $m)) { $options['states'][] = trim($m[1]); continue; }
        if (preg_match('/^--backup-dir=(.+)$/', $arg, $m)) { $options['backupDir'] = trim($m[1]); continue; }
        if (preg_match('/^--export-review=(.+)$/', $arg, $m)) { $options['exportReview'] = trim($m[1]); continue; }
        if (preg_match('/^--apply-review=(.+)$/', $arg, $m)) { $options['applyReview'] = trim($m[1]); continue; }
        throw new InvalidArgumentException("Unknown argument: {$arg}");
    }
    if ($options['applyReview'] !== '' && ($options['apply'] || $options['exportReview'] !== '')) {
        throw new InvalidArgumentException('--apply-review cannot be combined with --apply or --export-review.');
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

function backfill_guess_multiword_city(array $tokens): ?array {
    $count = count($tokens);
    if ($count < 3) {
        return null;
    }

    // Prefer multi-word city endings when the last token strongly suggests
    // a compound city name (e.g. "Oklahoma City", "Colorado Springs").
    $compoundEndings = [
        'city', 'springs', 'heights', 'hills', 'beach', 'falls', 'valley',
        'village', 'town', 'junction', 'harbor', 'grove', 'park', 'ridge',
        'creek', 'point', 'mesa', 'fork', 'plains', 'lakes', 'island',
    ];
    $last = strtolower($tokens[$count - 1]);
    if (in_array($last, $compoundEndings, true)) {
        return [
            'street' => implode(' ', array_slice($tokens, 0, -2)),
            'city' => implode(' ', array_slice($tokens, -2)),
            'confidence' => 'auto',
        ];
    }

    // Prefixes that commonly start multi-word city names
    // (e.g. "Fort Worth", "New York", "St George").
    $compoundPrefixes = ['st', 'st.', 'saint', 'ft', 'ft.', 'fort', 'mt', 'mt.', 'mount', 'new', 'north', 'south', 'east', 'west'];
    $penultimate = strtolower($tokens[$count - 2]);
    if (in_array($penultimate, $compoundPrefixes, true)) {
        return [
            'street' => implode(' ', array_slice($tokens, 0, -2)),
            'city' => implode(' ', array_slice($tokens, -2)),
            'confidence' => 'guess',
        ];
    }

    return null;
}

function backfill_split_street_city($beforeState) {
    $beforeState = (string)$beforeState;
    if ($beforeState === '') {
        return ['street' => '', 'city' => '', 'confidence' => 'none'];
    }

    // Strategy 1: last street-suffix token. Everything up to and including the
    // suffix (plus a following cardinal direction if any) is street; the rest
    // is city.
    if (preg_match_all('/\b' . STREET_SUFFIX_RE . '\b\.?/i', $beforeState, $matches, PREG_OFFSET_CAPTURE)) {
        $last = end($matches[0]);
        $splitIdx = $last[1] + strlen($last[0]);
        $rest = substr($beforeState, $splitIdx);
        if (preg_match('/^\s+' . CARDINAL_AFTER_RE . '\b\.?/i', $rest, $cardMatch)) {
            $splitIdx += strlen($cardMatch[0]);
        }
        $street = backfill_normalize_ws(substr($beforeState, 0, $splitIdx));
        $city = backfill_normalize_ws(substr($beforeState, $splitIdx));
        if ($city !== '' && $street !== '') {
            return ['street' => $street, 'city' => $city, 'confidence' => 'auto'];
        }
    }

    // Strategy 2: PO Box.
    if (preg_match('/^(p\.?\s*o\.?\s*box\s+\d+)\s+(.+)$/i', $beforeState, $m)) {
        return [
            'street' => backfill_normalize_ws($m[1]),
            'city' => backfill_normalize_ws($m[2]),
            'confidence' => 'auto',
        ];
    }

    // Strategy 3: token fallback. First try a multi-word city heuristic, then
    // fall back to "last token is city". This is still low confidence.
    $tokens = preg_split('/\s+/', trim($beforeState));
    if (count($tokens) >= 2) {
        $multiWordGuess = backfill_guess_multiword_city($tokens);
        if ($multiWordGuess && $multiWordGuess['street'] !== '' && $multiWordGuess['city'] !== '') {
            return [
                'street' => $multiWordGuess['street'],
                'city' => $multiWordGuess['city'],
                'confidence' => $multiWordGuess['confidence'] ?? 'guess',
            ];
        }

        return [
            'street' => implode(' ', array_slice($tokens, 0, -1)),
            'city' => $tokens[count($tokens) - 1],
            'confidence' => 'guess',
        ];
    }

    return ['street' => $beforeState, 'city' => '', 'confidence' => 'none'];
}

function backfill_extract($rawAddress) {
    $cleaned = backfill_normalize_ws($rawAddress);
    $cleaned = preg_replace('/\s*\([^()]*\)\s*$/', '', $cleaned);
    $cleaned = preg_replace('/[;]+$/', '', $cleaned);
    $cleaned = backfill_normalize_ws($cleaned);
    if ($cleaned === '') return null;

    // Pull off the trailing ZIP and state abbreviation first; whatever's left
    // is the street+city prefix we need to split.
    $zip = '';
    if (preg_match('/^(.+?)(?:[,]?\s+(\d{5}(?:-\d{4})?))?$/u', $cleaned, $zm)) {
        $cleaned = trim($zm[1]);
        $zip = $zm[2] ?? '';
    }
    $cleaned = preg_replace('/[,]+$/', '', $cleaned);

    // Comma-based: "<street>, <city>, XX" or "<city>, XX"
    if (preg_match('/^(.*),\s*([A-Za-z.\'\x{2019}\- ]+),\s*([A-Z]{2})$/u', $cleaned, $m)) {
        return [
            'street' => backfill_normalize_ws($m[1]),
            'city' => backfill_normalize_ws($m[2]),
            'state' => strtoupper($m[3]),
            'zip' => $zip,
            'confidence' => 'auto',
        ];
    }
    if (preg_match('/^([A-Za-z.\'\x{2019}\- ]+),\s*([A-Z]{2})$/u', $cleaned, $m)) {
        return [
            'street' => '',
            'city' => backfill_normalize_ws($m[1]),
            'state' => strtoupper($m[2]),
            'zip' => $zip,
            'confidence' => 'auto',
        ];
    }

    // Comma-less or single-comma-after-street: optional street, then city + state.
    if (preg_match('/^(.*?)\s+([A-Z]{2})$/u', $cleaned, $m)) {
        $beforeState = trim(preg_replace('/[,]+$/', '', $m[1]));
        $abbrev = strtoupper($m[2]);
        $split = backfill_split_street_city($beforeState);
        if ($split['city'] !== '') {
            return [
                'street' => $split['street'],
                'city' => $split['city'],
                'state' => $abbrev,
                'zip' => $zip,
                'confidence' => $split['confidence'],
            ];
        }
    }

    return null;
}

/* ------------------------------------------------------------------ *
 * --apply-review: read an edited review file and commit only the rows
 * marked approve:true, using the user's edited values (not the parser's).
 * ------------------------------------------------------------------ */

function backfill_run_apply_review(PDO $pdo, array $options): void {
    $reviewPath = $options['applyReview'];
    if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $reviewPath)) {
        $reviewPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $reviewPath), '/');
    }
    if (!file_exists($reviewPath)) {
        throw new RuntimeException("Review file not found: {$reviewPath}");
    }
    $reviewData = json_decode(file_get_contents($reviewPath), true);
    if (!is_array($reviewData) || !isset($reviewData['rows']) || !is_array($reviewData['rows'])) {
        throw new RuntimeException("Invalid review file format (expected JSON with 'rows' array): {$reviewPath}");
    }

    // Group approved rows by state_row so we touch each DB row exactly once.
    $approvedByState = [];
    $skippedNotApproved = 0;
    $skippedNoCity = 0;
    $skippedMissingId = 0;
    foreach ($reviewData['rows'] as $r) {
        if (!is_array($r)) continue;
        if (empty($r['approve'])) { $skippedNotApproved++; continue; }
        if (empty($r['state_row']) || !isset($r['facility_idx'])) { $skippedMissingId++; continue; }
        if (trim((string)($r['city'] ?? '')) === '') { $skippedNoCity++; continue; }
        $approvedByState[(string)$r['state_row']][] = $r;
    }

    $changedRows = [];      // unique_name => updated JSON string
    $originalRows = [];     // unique_name => original DB row (for backup)
    $appliedCount = 0;
    $nameMismatches = [];
    $missingStateRows = [];
    $missingIndices = [];

    $stmt = $pdo->prepare("SELECT unique_name, json_data FROM locations_master WHERE unique_name = :name");

    foreach ($approvedByState as $stateRow => $rowsForState) {
        $stmt->execute([':name' => $stateRow]);
        $dbRow = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$dbRow) {
            $missingStateRows[] = $stateRow;
            continue;
        }
        $originalRows[$stateRow] = $dbRow;

        $data = json_decode($dbRow['json_data'], true);
        if (!is_array($data) || empty($data['data']['facilities']) || !is_array($data['data']['facilities'])) {
            continue;
        }

        $rowChanged = false;
        foreach ($rowsForState as $r) {
            $idx = (int)$r['facility_idx'];
            if (!isset($data['data']['facilities'][$idx]) || !is_array($data['data']['facilities'][$idx])) {
                $missingIndices[] = "{$stateRow}[{$idx}]";
                continue;
            }
            $facility = &$data['data']['facilities'][$idx];

            // Safety: verify the facility name still matches what was exported.
            $expectedName = backfill_normalize_ws($r['facility_name'] ?? '');
            $actualName = backfill_normalize_ws($facility['identification']['name'] ?? '');
            if ($expectedName !== '' && $expectedName !== $actualName) {
                $nameMismatches[] = "{$stateRow}[{$idx}]: expected \"{$expectedName}\" but found \"{$actualName}\"";
                unset($facility);
                continue;
            }

            $editedStreet = backfill_normalize_ws($r['street'] ?? '');
            $editedCity   = backfill_normalize_ws($r['city']);
            $editedState  = strtoupper(backfill_normalize_ws($r['state'] ?? ''));
            $editedZip    = backfill_normalize_ws($r['zip'] ?? '');

            if (!isset($facility['locationDetails']) || !is_array($facility['locationDetails'])) {
                $facility['locationDetails'] = ['city' => '', 'state' => '', 'country' => 'United States', 'additionalLocations' => []];
            }
            $facility['locationDetails']['city'] = $editedCity;
            if ($editedState !== '') {
                $facility['locationDetails']['state'] = $editedState;
            }
            $stateForLocation = $facility['locationDetails']['state'] ?: $editedState;
            $facility['location'] = $editedCity . ($stateForLocation !== '' ? (', ' . $stateForLocation) : '');

            $facility['addressParts'] = [
                'street' => $editedStreet,
                'city'   => $editedCity,
                'state'  => $editedState,
                'zip'    => $editedZip,
            ];

            unset($facility);
            $rowChanged = true;
            $appliedCount++;
        }

        if ($rowChanged) {
            $changedRows[$stateRow] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
    }

    // Write a backup before mutating anything.
    $backupFile = null;
    if ($changedRows) {
        $backupDir = $options['backupDir'] !== ''
            ? $options['backupDir']
            : dirname(__DIR__) . '/tmp/city-backfill-backups/' . date('Ymd-His') . '-review';
        if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $backupDir)) {
            $backupDir = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $backupDir), '/');
        }
        if (!is_dir($backupDir) && !mkdir($backupDir, 0777, true) && !is_dir($backupDir)) {
            throw new RuntimeException("Failed to create backup directory: {$backupDir}");
        }
        $backupFile = rtrim($backupDir, '/\\') . '/city-backfill-review-backup.json';
        $backupRows = [];
        foreach ($changedRows as $name => $_) {
            if (isset($originalRows[$name])) $backupRows[] = $originalRows[$name];
        }
        file_put_contents($backupFile, json_encode([
            'createdAt' => date('c'),
            'sourceReviewFile' => $reviewPath,
            'rows' => $backupRows,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $pdo->beginTransaction();
        try {
            $upd = $pdo->prepare("UPDATE locations_master SET json_data = :json_data, updated_at = NOW() WHERE unique_name = :unique_name");
            foreach ($changedRows as $name => $jsonData) {
                $upd->execute([':json_data' => $jsonData, ':unique_name' => $name]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    fwrite(STDOUT, "Apply-review summary:" . PHP_EOL);
    fwrite(STDOUT, "  Facilities updated:     {$appliedCount}" . PHP_EOL);
    fwrite(STDOUT, "  State rows updated:     " . count($changedRows) . PHP_EOL);
    fwrite(STDOUT, "  Skipped (approve=false): {$skippedNotApproved}" . PHP_EOL);
    fwrite(STDOUT, "  Skipped (empty city):    {$skippedNoCity}" . PHP_EOL);
    fwrite(STDOUT, "  Skipped (missing id):    {$skippedMissingId}" . PHP_EOL);
    if ($missingStateRows) {
        fwrite(STDOUT, PHP_EOL . "Unknown state_row values in review file:" . PHP_EOL);
        foreach ($missingStateRows as $name) fwrite(STDOUT, "  - {$name}" . PHP_EOL);
    }
    if ($missingIndices) {
        fwrite(STDOUT, PHP_EOL . "facility_idx out of range:" . PHP_EOL);
        foreach ($missingIndices as $msg) fwrite(STDOUT, "  - {$msg}" . PHP_EOL);
    }
    if ($nameMismatches) {
        fwrite(STDOUT, PHP_EOL . "Name mismatches (skipped — facility may have been re-ordered):" . PHP_EOL);
        foreach ($nameMismatches as $msg) fwrite(STDOUT, "  - {$msg}" . PHP_EOL);
    }
    if ($backupFile) fwrite(STDOUT, PHP_EOL . "Backup: {$backupFile}" . PHP_EOL);
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

    if ($options['applyReview'] !== '') {
        backfill_run_apply_review($pdo, $options);
        exit(0);
    }

    $sql = "SELECT unique_name, json_data FROM locations_master";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    $perState = [];        // state => [scanned, alreadyHasCity, autoFixed, needsReview, noStateMatch]
    $reviewRows = [];      // sample rows for review (capped at 200, dry-run output only)
    $rowChanges = [];      // [unique_name => updatedJsonString] for --apply
    $exportRows = [];      // full, uncapped review rows for --export-review

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

        foreach ($facilities as $facilityIdx => &$facility) {
            if (!is_array($facility)) continue;
            $stats['scanned']++;

            // Decide whether this record already has the structured pieces filled in.
            $existingCity = '';
            if (isset($facility['locationDetails']) && is_array($facility['locationDetails'])) {
                $existingCity = backfill_normalize_ws($facility['locationDetails']['city'] ?? '');
            }
            $hasStructuredAddressParts = isset($facility['addressParts'])
                && is_array($facility['addressParts'])
                && !empty($facility['addressParts']['city']);
            if ($existingCity !== '' && $hasStructuredAddressParts) {
                $stats['alreadyHasCity']++;
                continue;
            }

            $rawAddress = '';
            if (isset($facility['address']) && is_string($facility['address'])) {
                $rawAddress = backfill_normalize_ws($facility['address']);
            }
            if ($rawAddress === '') { continue; }

            $parsed = backfill_extract($rawAddress);
            if (!$parsed) {
                $stats['noStateMatch']++;
                if ($options['exportReview'] !== '') {
                    $exportRows[] = [
                        'state_row' => $uname,
                        'facility_idx' => $facilityIdx,
                        'facility_name' => $facility['identification']['name'] ?? '',
                        'raw_address' => $rawAddress,
                        'parse_confidence' => 'none',
                        'street' => '',
                        'city' => '',
                        'state' => '',
                        'zip' => '',
                        'approve' => true,
                    ];
                }
                continue;
            }

            $isAuto = $parsed['confidence'] === 'auto';
            if (!$isAuto && !$options['aggressive']) {
                $stats['needsReview']++;
                if (count($reviewRows) < 200) {
                    $reviewRows[] = [
                        'state_row' => $uname,
                        'facility' => $facility['identification']['name'] ?? '',
                        'raw_address' => $rawAddress,
                        'guess_street' => $parsed['street'],
                        'guess_city' => $parsed['city'],
                        'guess_state' => $parsed['state'],
                        'guess_zip' => $parsed['zip'],
                    ];
                }
                if ($options['exportReview'] !== '') {
                    $exportRows[] = [
                        'state_row' => $uname,
                        'facility_idx' => $facilityIdx,
                        'facility_name' => $facility['identification']['name'] ?? '',
                        'raw_address' => $rawAddress,
                        'parse_confidence' => $parsed['confidence'],
                        'street' => $parsed['street'],
                        'city' => $parsed['city'],
                        'state' => $parsed['state'],
                        'zip' => $parsed['zip'],
                        'approve' => true,
                    ];
                }
                continue;
            }

            // Fill in locationDetails (legacy structured fields).
            if (!isset($facility['locationDetails']) || !is_array($facility['locationDetails'])) {
                $facility['locationDetails'] = ['city' => '', 'state' => '', 'country' => 'United States', 'additionalLocations' => []];
            }
            if (empty($facility['locationDetails']['city'])) {
                $facility['locationDetails']['city'] = $parsed['city'];
            }
            if (empty($facility['locationDetails']['state'])) {
                $facility['locationDetails']['state'] = $parsed['state'];
            }
            $stateForLocation = $facility['locationDetails']['state'] ?: $parsed['state'];
            if (empty($facility['location'])) {
                $facility['location'] = $parsed['city'] . ', ' . $stateForLocation;
            }

            // Write the new structured addressParts sibling field.
            // Keep facility.address (the raw line) untouched so existing consumers don't break.
            $facility['addressParts'] = [
                'street' => $parsed['street'],
                'city'   => $parsed['city'],
                'state'  => $parsed['state'],
                'zip'    => $parsed['zip'],
            ];

            $stats[$isAuto ? 'autoFixed' : 'needsReview']++;
            $rowChanged = true;
        }
        unset($facility);

        $perState[$uname] = $stats;
        if ($rowChanged) {
            $rowChanges[$uname] = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
    }

    // --export-review short-circuits before any apply logic.
    if ($options['exportReview'] !== '') {
        $exportPath = $options['exportReview'];
        if (!preg_match('~^(?:[A-Za-z]:[\\/]|/)~', $exportPath)) {
            $exportPath = dirname(__DIR__) . '/' . ltrim(str_replace('\\', '/', $exportPath), '/');
        }
        $exportDir = dirname($exportPath);
        if ($exportDir !== '' && !is_dir($exportDir) && !mkdir($exportDir, 0777, true) && !is_dir($exportDir)) {
            throw new RuntimeException("Failed to create export directory: {$exportDir}");
        }
        $payload = [
            'createdAt' => date('c'),
            'instructions' => 'Edit street/city/state/zip for any row whose values look wrong. Set "approve":false to skip a row. Re-run with --apply-review=<this file> to commit. The state_row + facility_idx fields identify the record and should not be changed.',
            'totals' => [
                'rows' => count($exportRows),
                'guess' => count(array_filter($exportRows, fn($r) => ($r['parse_confidence'] ?? '') === 'guess')),
                'none' => count(array_filter($exportRows, fn($r) => ($r['parse_confidence'] ?? '') === 'none')),
            ],
            'rows' => $exportRows,
        ];
        file_put_contents($exportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        fwrite(STDOUT, "Exported " . count($exportRows) . " rows to: {$exportPath}" . PHP_EOL);
        fwrite(STDOUT, "  guess-confidence: {$payload['totals']['guess']}" . PHP_EOL);
        fwrite(STDOUT, "  unparseable:      {$payload['totals']['none']}" . PHP_EOL);
        fwrite(STDOUT, PHP_EOL . "Edit that file, then run:" . PHP_EOL);
        fwrite(STDOUT, "  php scripts/backfill-location-cities.php --apply-review={$exportPath}" . PHP_EOL);
        exit(0);
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
            fwrite(STDOUT, sprintf(
                "  [%s] %s\n    addr: %s\n    guess: street=\"%s\" city=\"%s\" state=\"%s\" zip=\"%s\"%s",
                $r['state_row'], $r['facility'], $r['raw_address'],
                $r['guess_street'], $r['guess_city'], $r['guess_state'], $r['guess_zip'], PHP_EOL
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
