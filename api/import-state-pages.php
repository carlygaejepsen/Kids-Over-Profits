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
$purge_mode = !empty($_GET['purge']);  // Delete previously-imported rows for the chosen state(s)
$debug_mode = !empty($_GET['debug']);  // Include raw post_content snippet + parser text in response
$revision_id_override = isset($_GET['revision_id']) ? (int)$_GET['revision_id'] : 0;  // Force a specific revision
$cleanup_addresses = !empty($_GET['cleanup_addresses']);  // Find/delete rows whose unique_name is an address

// Cleanup mode runs without needing a ?state= param.
if ($cleanup_addresses && $state_param === '') {
    $state_param = 'all';
}

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
 * Decide whether a single line looks like a postal address rather than a facility name.
 * Used to attach orphan address paragraphs to the preceding name paragraph when
 * WordPress wraps each line in its own <p> tag.
 */
function import_line_looks_like_address($line) {
    $line = trim((string)$line);
    if ($line === '') return false;
    // Starts with a street number, or contains a zip, or matches a "City, ST" pattern
    if (preg_match('/^\d{1,6}\s+\S/', $line)) return true;
    if (preg_match('/\b\d{5}(?:-\d{4})?\b/', $line)) return true;
    if (preg_match('/,\s*[A-Z]{2}\b/', $line)) return true;
    return false;
}

/**
 * Decide whether a paragraph looks like a facility name (has a parenthetical tag
 * or a colon-free single-line text that isn't an address).
 */
function import_line_looks_like_name($line) {
    $line = trim((string)$line);
    if ($line === '') return false;
    if (import_line_looks_like_address($line)) return false;
    if (mb_strlen($line) > 220) return false;
    return true;
}

/**
 * Reject non-facility names that scrapers commonly pick up:
 * link/CTA text, instructions, or page headings.
 */
function import_name_is_junk($name) {
    $lower = strtolower(trim((string)$name));
    if ($lower === '') return true;
    if (mb_strlen($lower) < 3) return true;
    if (preg_match('/^(click|see|view|read|visit|browse|review|learn|find|press|tap)\s+/i', $lower)) return true;
    if (preg_match('/\bclick\s+here\b/i', $lower)) return true;
    if (preg_match('/\b(here|now|below|above)\s*(to|for)\s/i', $lower)) return true;
    if (preg_match('/\bavailable\s+(inspections?|reports?|violations?)\b/i', $lower)) return true;
    return false;
}

/**
 * Return true if a line is wrapped in a single pair of parentheses with comma-separated
 * alias names inside — typically rendered as a separate paragraph below a facility name.
 * Example: "(Group Home for Children, The Anoka Ranch, The Nisqually Nest)"
 */
function import_line_is_alias_block($line) {
    $line = trim((string)$line);
    if ($line === '' || $line[0] !== '(' || mb_substr($line, -1) !== ')') return false;
    $inner = mb_substr($line, 1, -1);
    // Reject single-token parentheticals like "(CLOSED)" — those are handled elsewhere.
    if (mb_strpos($inner, ',') === false) return false;
    if (preg_match('/\b(closed|clip|aka|fka|est|established)\b/i', $inner)) return false;
    return true;
}

/**
 * Parse the cleaned text into facility entries.
 *
 * Supports two layouts the WP editor produces:
 *  1. Name and address on consecutive lines inside the same <p> block.
 *  2. Name in one <p>, address in the next <p> (single newlines collapse to blank line
 *     between paragraphs after the HTML→text pass).
 *
 * Strategy: walk paragraphs in order, holding a "pending name" until we see an
 * address-only paragraph that completes it.
 */
function import_parse_state_page($text, $state_name) {
    $paragraphs = preg_split('/\n\s*\n/', $text);
    $entries = [];
    $pending = null;

    $finalize = function ($pending) use (&$entries) {
        if (!$pending || empty($pending['name'])) return;
        if (import_name_is_junk($pending['name'])) return;
        $entries[] = $pending;
    };

    foreach ($paragraphs as $para) {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $para)), static function ($l) {
            return $l !== '';
        }));
        if (empty($lines)) continue;

        $first = $lines[0];
        $rest_text = trim(implode(' ', array_slice($lines, 1)));

        // Skip breadcrumbs / page title
        if (preg_match('/^home\s*\/\s*' . preg_quote($state_name, '/') . '$/i', $first)) continue;
        if (strcasecmp($first, $state_name) === 0) continue;
        if (mb_strlen($first) > 220) {
            // Long intro paragraph — flush any pending name as standalone (no address) and skip.
            if ($pending) { $finalize($pending); $pending = null; }
            continue;
        }

        $has_paren = (bool)preg_match('/\((?:CLOSED|CLIP|aka |fka |est\.?|established|\d{4}\s*[-\x{2013}\x{2014}]\s*\d{4}?)/iu', $first);
        $first_is_address = import_line_looks_like_address($first);
        $first_is_name    = !$first_is_address;

        // Address-only paragraph: attach to pending name, if any.
        if ($first_is_address && empty($rest_text) && !$has_paren) {
            if ($pending) {
                $combined = trim($first . (count($lines) > 1 ? ' ' . trim(implode(' ', array_slice($lines, 1))) : ''));
                $address_blocks = preg_split('/;\s*/', $combined);
                foreach ($address_blocks as $block) {
                    $parsed = import_parse_address(trim($block), 'XX');
                    if ($parsed) $pending['addresses'][] = $parsed;
                }
                $finalize($pending);
                $pending = null;
            }
            // Address with no preceding name — silently drop.
            continue;
        }

        // Alias block paragraph: "(Group Home A, Group Home B, ...)" — attach to pending
        // name as aka entries; do NOT start a new pending.
        if ($pending && import_line_is_alias_block($first) && empty($rest_text)) {
            $inner = mb_substr($first, 1, -1);
            $aliases = array_filter(array_map('trim', explode(',', $inner)));
            foreach ($aliases as $alias) {
                if ($alias !== '') $pending['aka'][] = $alias;
            }
            continue;
        }

        // Otherwise this paragraph starts with a name. Flush any unresolved pending first.
        if ($pending) { $finalize($pending); $pending = null; }

        // Parse the name + parentheticals
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
                } elseif (preg_match('/(\d{4})\s*[-\x{2013}]\s*(\d{4})?/u', $p, $m)) {
                    $est_year = $m[1];
                }
            }
        }

        $name = trim(preg_replace('/\s*\([^)]+\)\s*/', ' ', $first));
        $name = preg_replace('/\s+/', ' ', $name);

        if ($name === '') continue;

        // Same-paragraph address(es), if any
        $addresses = [];
        if (!empty($lines[1]) && preg_match('/^\((\d{4})\s*[-\x{2013}]\s*(\d{4})?\)/u', $lines[1], $m)) {
            // Years-range line, advance
            if (!$est_year) $est_year = $m[1];
            $rest_text = trim(implode(' ', array_slice($lines, 2)));
        }
        if ($rest_text !== '') {
            $address_blocks = preg_split('/;\s*/', $rest_text);
            foreach ($address_blocks as $block) {
                $parsed = import_parse_address(trim($block), 'XX');
                if ($parsed) $addresses[] = $parsed;
            }
        }

        $pending = [
            'name'      => $name,
            'closed'    => $closed,
            'clip'      => $clip,
            'aka'       => $aka,
            'fka'       => $fka,
            'est_year'  => $est_year,
            'addresses' => $addresses,
            'raw'       => $first,
        ];

        // If the paragraph already had an address inline, the entry is complete.
        if (!empty($addresses)) {
            $finalize($pending);
            $pending = null;
        }
    }

    // Flush trailing name (no address)
    if ($pending) $finalize($pending);

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
// Address-as-name cleanup mode — short-circuits the per-state loop.
// Finds facilities_master rows whose unique_name is just a postal address
// (ends with ", ST" or ", ST 99999"). These were created by an earlier
// version of this parser before the stateful name+address pairing fix.
// ----------------------------------------------------------------------------
if ($cleanup_addresses) {
    try {
        // Catch rows whose unique_name is clearly NOT a facility name:
        //   - Ends with ';' (multi-address strings)
        //   - Contains a ZIP code pattern (", ST 99999" or ", ST, 99999")
        //   - Is purely numeric (raw facility_number from a scraper)
        //   - Starts with the literal "Facility (" placeholder
        // This deliberately spares names like "4 Healing Hearts" or "180 Degrees Inc."
        // which start with digits but contain no zip / no semicolon / non-numeric tail.
        $where_pattern = "("
            . " unique_name LIKE 'Facility (%'"
            . " OR unique_name REGEXP '^[0-9]+\$'"
            . " OR unique_name REGEXP ';\$'"
            . " OR unique_name REGEXP ', [A-Z]{2}[ ,]+[0-9]{5}'"
            . ")";

        // Inspect both tagged and untagged matches so the response is informative.
        $count_tagged = $pdo->query(
            "SELECT COUNT(*) FROM facilities_master
             WHERE $where_pattern
               AND json_data LIKE '%\"imported_from\":\"state_page\"%'"
        )->fetchColumn();

        $count_untagged = $pdo->query(
            "SELECT COUNT(*) FROM facilities_master
             WHERE $where_pattern
               AND json_data NOT LIKE '%\"imported_from\":\"state_page\"%'"
        )->fetchColumn();

        $samples = $pdo->query(
            "SELECT id, unique_name FROM facilities_master
             WHERE $where_pattern
             ORDER BY id DESC LIMIT 30"
        )->fetchAll(PDO::FETCH_ASSOC);

        $deleted = 0;
        if ($write_mode) {
            // Default: only delete the tagged rows (safe). Add &include_untagged=1
            // to also remove rows that aren't tagged (riskier — only do this if
            // the untagged samples really are address-only).
            if (!empty($_GET['include_untagged'])) {
                $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE $where_pattern");
            } else {
                $stmt = $pdo->prepare(
                    "DELETE FROM facilities_master
                     WHERE $where_pattern
                       AND json_data LIKE '%\"imported_from\":\"state_page\"%'"
                );
            }
            $stmt->execute();
            $deleted = $stmt->rowCount();
        }

        echo json_encode([
            'success'        => true,
            'mode'           => $write_mode ? 'write' : 'dry_run',
            'cleanup_target' => 'address-as-name rows in facilities_master',
            'counts' => [
                'tagged_imports'   => (int)$count_tagged,
                'untagged_imports' => (int)$count_untagged,
                'total_matches'    => (int)$count_tagged + (int)$count_untagged,
                'deleted'          => (int)$deleted,
            ],
            'samples' => $samples,
            'note' => $write_mode
                ? 'Tagged imports were deleted. Add &include_untagged=1 to also remove untagged matches.'
                : 'Dry-run only. Add &write=1 to delete tagged imports. Add &include_untagged=1 to also remove untagged matches.',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Cleanup failed: ' . $e->getMessage()]);
        exit;
    }
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

    $state_result = [
        'slug'      => $slug,
        'state'     => $state_name,
        'purged'    => 0,
        'parsed'    => 0,
        'inserted'  => 0,
        'updated'   => 0,
        'skipped'   => 0,
    ];

    // Purge mode: delete every facilities_master row tagged as imported from
    // this state's page (json_data.data.source.imported_state matches). This
    // gives a clean slate before re-importing.
    if ($purge_mode) {
        try {
            // facilities_master.json_data is a longtext JSON blob — match the source tag
            // via a LIKE pattern on the rendered JSON to avoid version-specific JSON ops.
            $needle = '%"imported_state":"' . str_replace('"', '\\"', $state_name) . '"%';
            if ($write_mode) {
                $stmt = $pdo->prepare("DELETE FROM facilities_master WHERE json_data LIKE ?");
                $stmt->execute([$needle]);
                $state_result['purged'] = $stmt->rowCount();
            } else {
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM facilities_master WHERE json_data LIKE ?");
                $stmt->execute([$needle]);
                $state_result['purged'] = (int)$stmt->fetchColumn();  // dry-run count only
            }
        } catch (PDOException $e) {
            $state_result['error'] = 'Purge failed: ' . $e->getMessage();
            $results[] = $state_result;
            continue;
        }

        // If purge-only (no state= page parse desired), skip the import step.
        if (empty($_GET['reimport']) && $state_param !== 'all') {
            $results[] = $state_result;
            continue;
        }
    }

    $page = function_exists('get_page_by_path') ? get_page_by_path($slug) : null;
    if (!$page) {
        $state_result['error'] = 'No WP page found at /' . $slug . '/';
        $results[] = $state_result;
        continue;
    }

    $raw_content = $page->post_content ?? '';
    $content_source = 'current';
    $revision_id_used = null;

    // Explicit revision override (debugging older imports)
    if ($revision_id_override > 0) {
        $rev = get_post($revision_id_override);
        if ($rev && (int)$rev->post_parent === (int)$page->ID && $rev->post_type === 'revision') {
            $raw_content = $rev->post_content ?? '';
            $content_source = 'revision_override';
            $revision_id_used = $rev->ID;
        }
    } elseif (mb_strlen($raw_content) < 1500) {
        // Fallback: walk back through revisions for a fuller version.
        $revisions = function_exists('wp_get_post_revisions') ? wp_get_post_revisions($page->ID) : array();
        foreach ($revisions as $rev) {
            $rev_content = $rev->post_content ?? '';
            if (mb_strlen($rev_content) >= 1500) {
                $raw_content = $rev_content;
                $content_source = 'revision';
                $revision_id_used = $rev->ID;
                break;
            }
        }
    }

    $text = import_html_to_text($raw_content);
    $entries = import_parse_state_page($text, $state_name);

    $state_result['wp_page_id']      = $page->ID;
    $state_result['content_source']  = $content_source;
    if ($revision_id_used) $state_result['revision_id'] = $revision_id_used;
    $state_result['parsed']          = count($entries);
    $state_result['samples']         = array_slice($entries, 0, 3);

    if ($debug_mode) {
        $state_result['debug'] = [
            'post_content_length' => mb_strlen($raw_content),
            'post_content_snippet' => mb_substr($raw_content, 0, 2000),
            'cleaned_text_length' => mb_strlen($text),
            'cleaned_text_snippet' => mb_substr($text, 0, 2000),
            'paragraph_count' => count(preg_split('/\n\s*\n/', $text)),
            'first_5_paragraphs' => array_slice(preg_split('/\n\s*\n/', $text), 0, 5),
        ];

        // Also show revision survey so we know what's available even when current passes the 1500-char check.
        if (function_exists('wp_get_post_revisions')) {
            $rev_summary = array();
            foreach (wp_get_post_revisions($page->ID) as $rev) {
                $rev_summary[] = array(
                    'id'             => $rev->ID,
                    'date'           => $rev->post_date,
                    'content_length' => mb_strlen($rev->post_content ?? ''),
                );
                if (count($rev_summary) >= 10) break;
            }
            $state_result['debug']['revisions'] = $rev_summary;
        }
    }

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
    if (!isset($grand_totals['purged'])) $grand_totals['purged'] = 0;
    $grand_totals['purged'] += $state_result['purged'] ?? 0;

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
