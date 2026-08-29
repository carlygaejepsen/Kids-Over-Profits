<?php
/**
 * Search facilities_master for the facility picker.
 *
 * GET /api/facility-search.php?q=mount[&limit=20]
 *
 * Returns up to `limit` matches, each shaped:
 *   { id, unique_name, state, city, status, matched_name }
 *
 * Matches against unique_name AND every current/alternate/past name stored in
 * json_data (operator otherNames, facility identification pastNames, etc.), so
 * a program renamed years ago is still found by its old name. `matched_name`
 * is set when the hit was on a name other than unique_name. Public read.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/config.php';

/**
 * Collect every name a program is known by from its decoded json_data (same
 * helper as facility-picker.php / link-wiki-facility.php; function_exists-
 * guarded so any load order works).
 */
if (!function_exists('kop_picker_collect_known_names')) {
    function kop_picker_collect_known_names($node, array &$out, int $depth = 0): void {
        if ($depth > 6 || !is_array($node)) {
            return;
        }
        static $scalarKeys = ['name', 'currentname'];
        static $listKeys = ['othernames', 'other_names', 'pastnames', 'past_names',
                            'formernames', 'former_names', 'aliases', 'alternatenames'];

        foreach ($node as $key => $value) {
            $lkey = is_string($key) ? strtolower($key) : '';
            if (in_array($lkey, $scalarKeys, true) && is_string($value) && trim($value) !== '') {
                $out[] = trim($value);
            } elseif (in_array($lkey, $listKeys, true) && is_array($value)) {
                foreach ($value as $alias) {
                    if (is_string($alias) && trim($alias) !== '') {
                        $out[] = trim($alias);
                    }
                }
            } elseif (is_array($value)) {
                kop_picker_collect_known_names($value, $out, $depth + 1);
            }
        }
    }
}

try {
    $q = trim((string)($_GET['q'] ?? ''));
    $limit = (int)($_GET['limit'] ?? 20);
    if ($limit < 1) $limit = 20;
    if ($limit > 50) $limit = 50;

    if ($q === '') {
        echo json_encode(['success' => true, 'data' => []]);
        exit;
    }

    // Over-fetch: json_data LIKE also hits records that merely MENTION the
    // query somewhere (wiki prose, addresses); the PHP pass below keeps only
    // hits on actual name fields, so we need slack in the pool.
    $pool = min(150, $limit * 5);
    $like = '%' . $q . '%';
    $stmt = $pdo->prepare(
        "SELECT id, unique_name, json_data
         FROM facilities_master
         WHERE unique_name LIKE :like1 OR json_data LIKE :like2
         ORDER BY
           CASE WHEN unique_name LIKE :starts THEN 0
                WHEN unique_name LIKE :like3 THEN 1
                ELSE 2 END,
           CHAR_LENGTH(unique_name) ASC,
           unique_name ASC
         LIMIT :lim"
    );
    $stmt->bindValue(':like1', $like);
    $stmt->bindValue(':like2', $like);
    $stmt->bindValue(':starts', $q . '%');
    $stmt->bindValue(':like3', $like);
    $stmt->bindValue(':lim', $pool, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll();

    // Keep unique_name hits as-is; keep json-only hits ONLY when the query
    // matches one of the record's known names (current/alternate/past) —
    // otherwise any record whose wiki text mentions the query would surface.
    $qLower = mb_strtolower($q);
    $filtered = [];
    foreach ($rows as $row) {
        $matchedName = null;
        if (mb_stripos($row['unique_name'], $q) === false) {
            $names = [];
            $decoded = json_decode($row['json_data'] ?? '', true);
            if (is_array($decoded)) {
                kop_picker_collect_known_names($decoded, $names);
            }
            foreach (array_unique($names) as $known) {
                if ($known !== $row['unique_name'] && mb_stripos($known, $q) !== false) {
                    $matchedName = $known;
                    break;
                }
            }
            if ($matchedName === null) {
                continue;
            }
        }
        $row['matched_name'] = $matchedName;
        // Alt-name hits sort after direct-name hits (query relevance), with
        // starts-with beating contains within the group.
        $row['sort_group'] = $matchedName === null
            ? (mb_stripos($row['unique_name'], $q) === 0 ? 0 : 1)
            : (mb_stripos($matchedName, $q) === 0 ? 2 : 3);
        $filtered[] = $row;
        if (count($filtered) >= $limit * 2) {
            break;
        }
    }

    usort($filtered, static function ($a, $b) {
        if ($a['sort_group'] !== $b['sort_group']) {
            return $a['sort_group'] - $b['sort_group'];
        }
        $la = strlen($a['unique_name']);
        $lb = strlen($b['unique_name']);
        return $la !== $lb ? $la - $lb : strcmp($a['unique_name'], $b['unique_name']);
    });
    $rows = array_slice($filtered, 0, $limit);

    // Pull state/city/status out of the JSON for disambiguation.
    $out = array_map(static function ($row) {
        $project = json_decode($row['json_data'] ?? '', true);
        $state = null;
        $city  = null;
        $status = null;

        if (is_array($project)) {
            // Top-level fields the project may carry.
            $operator = isset($project['data']['operator']) && is_array($project['data']['operator'])
                ? $project['data']['operator']
                : null;
            $identification = isset($project['data']['identification']) && is_array($project['data']['identification'])
                ? $project['data']['identification']
                : null;

            // facilities_master projects actually store identification per
            // facility (data.facilities[i].identification / .locationDetails) —
            // the top-level paths above exist only in legacy payloads. Without
            // these sources, state/city/status came back null for most rows.
            $facilityIdentification = null;
            $facilityLocation = null;
            if (isset($project['data']['facilities']) && is_array($project['data']['facilities'])) {
                foreach ($project['data']['facilities'] as $fac) {
                    if (!is_array($fac)) continue;
                    if (!$facilityIdentification && isset($fac['identification']) && is_array($fac['identification'])) {
                        $facilityIdentification = $fac['identification'];
                    }
                    if (!$facilityLocation && isset($fac['locationDetails']) && is_array($fac['locationDetails'])) {
                        $facilityLocation = $fac['locationDetails'];
                    }
                    if ($facilityIdentification && $facilityLocation) break;
                }
            }

            $sources = [$facilityLocation, $facilityIdentification, $identification, $operator];

            foreach ($sources as $src) {
                if (!is_array($src)) continue;
                if (!$state && !empty($src['state'])) $state = $src['state'];
                if (!$state && !empty($src['locationState'])) $state = $src['locationState'];
                if (!$city && !empty($src['city'])) $city = $src['city'];
                if (!$city && !empty($src['locationCity'])) $city = $src['locationCity'];
                if (!$status && !empty($src['status'])) $status = $src['status'];
            }

            // Some projects only have free-form 'location' / 'address' strings; try to
            // pull a "City, ST" out of those when nothing structured was found.
            if (!$state || !$city) {
                foreach ($sources as $src) {
                    if (!is_array($src)) continue;
                    foreach (['location', 'address', 'cityState', 'city_state', 'headquarters'] as $k) {
                        if (!empty($src[$k]) && is_string($src[$k])) {
                            if (preg_match('/([^,]+),\s*([A-Z]{2}|[A-Za-z .]+)\s*$/', trim($src[$k]), $m)) {
                                if (!$city)  $city  = trim($m[1]);
                                if (!$state) $state = trim($m[2]);
                            }
                            break 2;
                        }
                    }
                }
            }
        }

        return [
            'id'           => (int)$row['id'],
            'unique_name'  => $row['unique_name'],
            'state'        => $state,
            'city'         => $city,
            'status'       => $status,
            // The past/alternate name the query matched, when it wasn't the
            // unique_name itself — lets the picker explain the hit.
            'matched_name' => $row['matched_name'] ?? null,
        ];
    }, $rows);

    echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_SLASHES);
} catch (PDOException $e) {
    error_log('facility-search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'A database error occurred.']);
} catch (Exception $e) {
    error_log('facility-search error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'An unexpected error occurred.']);
}
