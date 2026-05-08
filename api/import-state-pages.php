<?php
/**
 * Import facilities listed on existing state pages (e.g. /washington/, /utah/) into facilities_master.
 *
 * The page content is expected to follow the convention:
 *
 *     Facility Name (CLOSED) (aka X) (fka Y) (est. 1956)
 *     Street, City, ST ZIP
 *
 *     Next Facility ...
 *     ...
 *
 * Usage:
 *   ?state=washington          parse just /washington/
 *   ?state=all                 parse every state hub page that exists
 *   ?write=1                   actually insert (otherwise dry-run with preview)
 *   ?overwrite=1               replace existing facilities_master rows by unique_name (default: skip duplicates)
 *
 * Requires manage_options capability (or CLI).
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

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

$is_cli = php_sapi_name() === 'cli';
$is_admin = function_exists('current_user_can') && current_user_can('manage_options');

if (!$is_cli && !$is_admin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin privileges required']);
    exit;
}

$state_param = strtolower(trim($_GET['state'] ?? ''));
$write_mode = !empty($_GET['write']);
$overwrite = !empty($_GET['overwrite']);

if ($state_param === '') {
    echo json_encode([
        'success' => false,
        'error' => 'Missing ?state= parameter. Use a slug like "washington" or "all".'
    ]);
    exit;
}

$us_states = [
    'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia',
    'Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland',
    'Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey',
    'New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina',
    'South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming',
    'District of Columbia',
];

$state_name_lookup = function ($slug) use ($us_states) {
    if (function_exists('kop_state_slug_to_name')) {
        $resolved = kop_state_slug_to_name($slug);
        if ($resolved) return $resolved;
    }
    foreach ($us_states as $name) {
        if (strtolower(str_replace(' ', '-', $name)) === $slug) return $name;
    }
    return null;
};

/**
 * Convert raw post_content into normalized plain text suitable for line-based parsing.
 */
function import_html_to_text($html) {
    if (!is_string($html)) return '';
    // Strip Gutenberg block comments
    $html = preg_replace('/<!--\s*\/?wp:[^>]*-->/', '', $html);
    // Convert breaks and block-level closes to newlines
    $html = preg_replace('/<\s*br\s*\/?\s*>/i', "\n", $html);
    $html = preg_replace('/<\s*\/\s*(p|div|h\d|li|tr)\s*>/i', "\n\n", $html);
    $html = preg_replace('/<\s*\/?\s*(li|ul|ol)\s*[^>]*>/i', "\n", $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace('/\r\n?/', "\n", $html);
    $html = preg_replace('/[ \t]+/', ' ', $html);
    $html = preg_replace('/\n{3,}/', "\n\n", $html);
    return trim($html);
}

/**
 * Parse a single address string into structured parts.
 * Recognizes: "Street, City, ST[,] ZIP" / "City, ST" / "City, ST ZIP".
 */
function import_parse_address($addr, $default_state) {
    $addr = trim((string)$addr);
    if ($addr === '') return null;

    // "Street, City, ST, ZIP" or "Street, City, ST ZIP"
    if (preg_match('/^(.*?),\s*([^,]+),\s*([A-Za-z]{2})[,\s]+(\d{5}(?:-\d{4})?)/', $addr, $m)) {
        return [
            'street' => trim($m[1]),
            'city'   => trim($m[2]),
            'state'  => strtoupper(trim($m[3])),
            'zip'    => trim($m[4]),
        ];
    }
    // "City, ST ZIP" (no street)
    if (preg_match('/^([^,]+),\s*([A-Za-z]{2})\s+(\d{5}(?:-\d{4})?)/', $addr, $m)) {
        return [
            'street' => '',
            'city'   => trim($m[1]),
            'state'  => strtoupper(trim($m[2])),
            'zip'    => trim($m[3]),
        ];
    }
    // "City, ST"
    if (preg_match('/^([^,]+),\s*([A-Za-z]{2})\s*$/', $addr, $m)) {
        return [
            'street' => '',
            'city'   => trim($m[1]),
            'state'  => strtoupper(trim($m[2])),
            'zip'    => '',
        ];
    }
    // Fallback: keep raw street, leave others empty
    return [
        'street' => $addr,
        'city'   => '',
        'state'  => $default_state,
        'zip'    => '',
    ];
}

/**
 * Parse the cleaned text into facility entries.
 *
 * Each entry is a paragraph (blank-line-separated block) where:
 *  - The first line is the name (with optional parentheticals)
 *  - The remaining lines contain one or more addresses, possibly separated by ';'
 *
 * A paragraph is treated as a facility entry only if it carries either a 5-digit zip,
 * a "(CLOSED)" / "(CLIP)" / etc. tag, or a recognizable "City, ST" pattern.
 */
function import_parse_state_page($text, $state_name) {
    $paragraphs = preg_split('/\n\s*\n/', $text);
    $entries = [];

    foreach ($paragraphs as $para) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $para)), static function ($l) {
            return $l !== '';
        }));
        if (empty($lines)) continue;

        $first = $lines[0];
        $rest_text = trim(implode(' ', array_slice($lines, 1)));

        $has_zip       = (bool)preg_match('/\b\d{5}(?:-\d{4})?\b/', $para);
        $has_state_pat = (bool)preg_match('/,\s*[A-Z]{2}\b/', $para);
        $has_paren     = (bool)preg_match('/\((?:CLOSED|CLIP|aka |fka |est\.?|established)\b/i', $first);

        if (!$has_zip && !$has_state_pat && !$has_paren) {
            continue;
        }

        // Skip section headings / breadcrumbs / intro paragraphs
        if (preg_match('/^home\s*\/\s*' . preg_quote($state_name, '/') . '$/i', $first)) continue;
        if (strcasecmp($first, $state_name) === 0) continue;
        if (mb_strlen($first) > 220) continue; // Long paragraphs are likely intro text

        // Name parentheticals: collect, then strip
        $closed = false;
        $clip = false;
        $aka = [];
        $fka = [];
        $est_year = '';

        if (preg_match_all('/\(([^)]+)\)/', $first, $matches)) {
            foreach ($matches[1] as $paren) {
                $p = trim($paren);
                if ($p === '') continue;

                if (stripos($p, 'closed') === 0) {
                    $closed = true;
                } elseif (strcasecmp($p, 'CLIP') === 0) {
                    $clip = true;
                } elseif (preg_match('/^aka\s+(.+)/i', $p, $m)) {
                    $aka[] = trim($m[1]);
                } elseif (preg_match('/^fka\s+(.+)/i', $p, $m)) {
                    $fka[] = trim($m[1]);
                } elseif (preg_match('/^(?:est\.?|established)\s*\.?\s*(\d{4})/i', $p, $m)) {
                    $est_year = $m[1];
                } elseif (preg_match('/(\d{4})\s*[-–]\s*(\d{4})?/', $p, $m)) {
                    // Years range like "(1949-1990)"
                    $est_year = $m[1];
                }
            }
        }

        $name = trim(preg_replace('/\s*\([^)]+\)\s*/', ' ', $first));
        $name = preg_replace('/\s+/', ' ', $name);

        // Some entries have years on a line of their own beneath the name (like "(1949-1990)")
        if (!$est_year && !empty($lines[1]) && preg_match('/^\((\d{4})\s*[-–]\s*(\d{4})?\)/', $lines[1], $m)) {
            $est_year = $m[1];
            $rest_text = trim(implode(' ', array_slice($lines, 2)));
        }

        // Multiple addresses separated by ';'
        $addresses = [];
        $address_blocks = preg_split('/;\s*/', $rest_text);
        foreach ($address_blocks as $block) {
            $parsed = import_parse_address(trim($block), 'XX');
            if ($parsed) $addresses[] = $parsed;
        }

        $entries[] = [
            'name'      => $name,
            'closed'    => $closed,
            'clip'      => $clip,
            'aka'       => $aka,
            'fka'       => $fka,
            'est_year'  => $est_year,
            'addresses' => $addresses,
            'raw'       => $first,
        ];
    }

    return $entries;
}

/**
 * Convert a parsed entry into the facilities_master JSON shape.
 */
function import_entry_to_payload($entry, $state_name) {
    $facilities = [];
    $address_list = !empty($entry['addresses']) ? $entry['addresses'] : [
        ['street' => '', 'city' => '', 'state' => '', 'zip' => '']
    ];

    foreach ($address_list as $addr) {
        if (empty($addr['state'])) $addr['state'] = $state_name;

        $facility = [
            'identification' => [
                'name'          => $entry['name'],
                'currentName'   => $entry['name'],
                'otherNames'    => $entry['aka'],
                'previousNames' => $entry['fka'],
            ],
            'address' => [
                'street' => $addr['street'],
                'city'   => $addr['city'],
                'state'  => $addr['state'],
                'zip'    => $addr['zip'],
            ],
            'facilityDetails' => [
                'type' => $entry['clip'] ? 'CLIP (Children\'s Long-term Inpatient Program)' : '',
            ],
            'operatingPeriod' => [
                'status'           => $entry['closed'] ? 'Closed' : 'Open',
                'yearsOfOperation' => $entry['est_year'] ? $entry['est_year'] . '-' : '',
                'startYear'        => $entry['est_year'] ?: '',
            ],
        ];
        $facilities[] = $facility;
    }

    return [
        'data' => [
            'name'     => $entry['name'],
            'category' => 'companies',
            'operator' => [
                'name' => '',
                'type' => '',
            ],
            'facilities' => $facilities,
            'source' => [
                'imported_from'    => 'state_page',
                'imported_state'   => $state_name,
                'imported_at_iso'  => gmdate('c'),
            ],
        ],
    ];
}

// ----------------------------------------------------------------------------
// Build the list of state slugs to process
// ----------------------------------------------------------------------------
$slugs_to_process = [];
if ($state_param === 'all') {
    foreach ($us_states as $name) {
        $slug = strtolower(str_replace(' ', '-', $name));
        $slugs_to_process[] = $slug;
    }
} else {
    $slugs_to_process[] = $state_param;
}

$results = [];
$grand_totals = ['parsed' => 0, 'inserted' => 0, 'updated' => 0, 'skipped' => 0];

foreach ($slugs_to_process as $slug) {
    $state_name = $state_name_lookup($slug);
    if (!$state_name) {
        $results[] = ['slug' => $slug, 'error' => 'Unknown state slug'];
        continue;
    }

    $page = function_exists('get_page_by_path') ? get_page_by_path($slug) : null;
    if (!$page) {
        $results[] = ['slug' => $slug, 'state' => $state_name, 'error' => 'No WP page found at /' . $slug . '/'];
        continue;
    }

    $raw_content = $page->post_content ?? '';
    $text = import_html_to_text($raw_content);
    $entries = import_parse_state_page($text, $state_name);

    $state_result = [
        'slug'     => $slug,
        'state'    => $state_name,
        'wp_page_id' => $page->ID,
        'parsed'   => count($entries),
        'inserted' => 0,
        'updated'  => 0,
        'skipped'  => 0,
        'samples'  => array_slice($entries, 0, 3),
    ];

    if ($write_mode && !empty($entries)) {
        try {
            foreach ($entries as $entry) {
                $unique_name = $entry['name'];
                if ($unique_name === '') continue;

                $existing = $pdo->prepare("SELECT id FROM facilities_master WHERE unique_name = ?");
                $existing->execute([$unique_name]);
                $row = $existing->fetch();

                $payload = import_entry_to_payload($entry, $state_name);
                $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                if ($row) {
                    if ($overwrite) {
                        $upd = $pdo->prepare("UPDATE facilities_master SET json_data = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                        $upd->execute([$json, $row['id']]);
                        $state_result['updated']++;
                    } else {
                        $state_result['skipped']++;
                    }
                } else {
                    $ins = $pdo->prepare("INSERT INTO facilities_master (unique_name, json_data, created_at, updated_at)
                                          VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");
                    $ins->execute([$unique_name, $json]);
                    $state_result['inserted']++;
                }
            }
        } catch (PDOException $e) {
            $state_result['error'] = 'Database error: ' . $e->getMessage();
        }
    }

    $grand_totals['parsed']  += $state_result['parsed'];
    $grand_totals['inserted'] += $state_result['inserted'];
    $grand_totals['updated'] += $state_result['updated'];
    $grand_totals['skipped'] += $state_result['skipped'];

    $results[] = $state_result;
}

echo json_encode([
    'success'    => true,
    'mode'       => $write_mode ? 'write' : 'dry_run',
    'overwrite'  => $overwrite,
    'totals'     => $grand_totals,
    'results'    => $results,
    'note'       => $write_mode
        ? 'Facilities written to facilities_master. Re-run with ?overwrite=1 to refresh existing rows.'
        : 'Dry-run only — no database changes. Add ?write=1 to commit.',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
