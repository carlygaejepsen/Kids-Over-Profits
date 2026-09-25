<?php
/**
 * Propose facility links for documents filed anywhere in the FileBird library.
 *
 * A document can be linked to the facilities it discusses. This tool scans
 * every attachment filed in FileBird and proposes exact name matches from its
 * title, description, byline and extracted text. PDF text is kept page by page
 * so a proposal can include PDF page numbers for an editor to verify.
 *
 * Nothing is saved on a proposal run. The proposals are written to a review
 * file in the uploads directory; delete the rows that are wrong, then run again
 * with ?apply=1, which saves exactly what the file holds and nothing else.
 *
 * GET                       - propose: scan, write the review file, report
 * GET ?apply=1              - save the tags in the review file
 * GET ?limit=40             - documents per proposal run (1 to 200, default 40)
 * GET ?offset=0             - starting position in the FileBird document list
 * GET ?text=0               - titles and descriptions only, no PDF text
 *
 * Matching is deliberately timid. A facility name counts only when it has two
 * or more words and ten or more characters, when it is not a phrase that names
 * many programs ("boys ranch", "youth services"), and when it belongs to one
 * facility: a name two facilities share is dropped rather than guessed, the
 * same rule api/facility-aliases.php uses for news. A document already tagged
 * with a facility is never proposed for it again, and nothing here ever removes
 * a tag somebody set by hand.
 *
 * Requires manage_options.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/config.php';

if (!function_exists('current_user_can')) {
    $kop_wp = __DIR__;
    for ($i = 0; $i < 6; $i++) {
        $kop_wp = dirname($kop_wp);
        if (file_exists($kop_wp . '/wp-load.php')) {
            require_once $kop_wp . '/wp-load.php';
            break;
        }
    }
}
if (!function_exists('current_user_can') || !current_user_can('manage_options')) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin access required.']);
    exit;
}

require_once __DIR__ . '/facility-aliases.php';          // kop_normalize_name_key()
require_once __DIR__ . '/lawsuit-extraction-lib.php';    // kop_extract_pdf_text()

if (!defined('KOP_RESEARCH_FACILITY_META') || !defined('KOP_DOCUMENT_FACILITY_REVIEWED_META')) {
    echo json_encode(['success' => false, 'error' => 'The research library module did not load.']);
    exit;
}

/** Where the proposals wait for a human. */
function kop_prft_review_path() {
    $uploads = wp_get_upload_dir();
    return trailingslashit($uploads['basedir']) . 'kop-research-tag-proposals.json';
}

/**
 * Phrases that name a kind of program rather than one program. A facility whose
 * whole name is one of these is never matched on, because the words turn up in
 * every report about the industry.
 */
function kop_prft_generic_names() {
    return [
        'boys ranch', 'girls ranch', 'boys home', 'girls home', 'group home',
        'youth services', 'youth home', 'youth center', 'youth centre',
        'juvenile hall', 'juvenile detention', 'detention center', 'detention centre',
        'residential treatment', 'residential treatment center', 'treatment center',
        'behavioral health', 'behavioural health', 'wilderness therapy',
        'therapeutic boarding school', 'boarding school', 'boot camp',
        'day treatment', 'family services', 'childrens home', 'children home',
        'mental health', 'health services', 'human services', 'social services',
        'academy', 'the academy', 'ranch', 'the ranch',
    ];
}

/**
 * name key => facility id for every facilities_v2 row, plus its other and past
 * names. A key that two facilities answer to is dropped.
 */
function kop_prft_name_index() {
    global $wpdb;

    $generic = array_flip(array_map('kop_normalize_name_key', kop_prft_generic_names()));
    $index   = [];
    $clashes = [];

    $add = static function ($name, $id) use (&$index, &$clashes, $generic) {
        $key = kop_normalize_name_key((string) $name);
        if ($key === '' || isset($generic[$key])) {
            return;
        }
        // Two words and ten characters: shorter names match too much prose.
        if (strlen($key) < 10 || substr_count($key, ' ') < 1) {
            return;
        }
        if (isset($index[$key]) && $index[$key] !== $id) {
            $clashes[$key] = true;
            return;
        }
        $index[$key] = $id;
    };

    $rows = $wpdb->get_results("SELECT id, name, json_data FROM facilities_v2", ARRAY_A);
    foreach ((array) $rows as $row) {
        $id = (int) $row['id'];
        $add($row['name'], $id);

        $doc = json_decode((string) $row['json_data'], true);
        $ident = [];
        if (is_array($doc)) {
            if (isset($doc['identification']) && is_array($doc['identification'])) {
                $ident = $doc['identification'];
            } elseif (isset($doc['facility']['identification']) && is_array($doc['facility']['identification'])) {
                $ident = $doc['facility']['identification'];
            }
        }
        foreach (['otherNames', 'pastNames', 'formerNames'] as $key) {
            if (empty($ident[$key]) || !is_array($ident[$key])) {
                continue;
            }
            foreach ($ident[$key] as $alias) {
                if (is_string($alias)) {
                    $add($alias, $id);
                }
            }
        }
    }

    foreach (array_keys($clashes) as $key) {
        unset($index[$key]);
    }

    return $index;
}

/** The library's own documents, as attachment ids. */
function kop_prft_library_attachments() {
    global $wpdb;
    $source = function_exists('kop_document_library_relation_table')
        ? kop_document_library_relation_table()
        : '';
    if ($source === '') {
        return [];
    }
    $hidden = function_exists('kop_get_hidden_preview_ids') ? kop_get_hidden_preview_ids() : [];
    $not_hidden = $hidden ? ' AND p.ID NOT IN (' . implode(',', array_map('intval', $hidden)) . ')' : '';
    return array_map('intval', (array) $wpdb->get_col(
        "SELECT DISTINCT af.attachment_id
         FROM $source af
         INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id
         WHERE p.post_type = 'attachment' AND p.post_status NOT IN ('trash','auto-draft'){$not_hidden}
         ORDER BY af.attachment_id ASC"
    ));
}

/** Facility ids whose name appears in the text, with the name that matched. */
function kop_prft_matches($text, array $index) {
    $key = kop_normalize_name_key((string) $text);
    if ($key === '') {
        return [];
    }
    // Pad so a name at either end still sits between word boundaries.
    $haystack = ' ' . $key . ' ';
    $hits = [];
    foreach ($index as $name_key => $facility_id) {
        if (strpos($haystack, ' ' . $name_key . ' ') !== false) {
            $hits[$facility_id] = $name_key;
        }
    }

    // "Discovery Ranch for Girls" contains "Discovery Ranch", a different
    // facility, so the shorter name matches too. Keep the longest name only:
    // the sibling program can be added by hand if the document names it.
    $by_length = $hits;
    uasort($by_length, static function ($a, $b) { return strlen($b) - strlen($a); });
    $kept = [];
    foreach ($by_length as $facility_id => $name_key) {
        foreach ($kept as $longer) {
            if (strpos(' ' . $longer . ' ', ' ' . $name_key . ' ') !== false) {
                continue 2;
            }
        }
        $kept[$facility_id] = $name_key;
    }

    return $kept;
}

/** First readable text line that contains a proposed facility name. */
function kop_prft_excerpt($text, $facility_id, array $index) {
    foreach (preg_split('/\R+/', (string) $text) as $line) {
        $line = trim(preg_replace('/\s+/', ' ', $line));
        if ($line === '') continue;
        if (isset(kop_prft_matches($line, $index)[(int) $facility_id])) {
            if (strlen($line) > 280) $line = substr($line, 0, 277) . '...';
            return $line;
        }
    }
    return '';
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 40;
$offset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$read_text = !isset($_GET['text']) || $_GET['text'] !== '0';
$review_path = kop_prft_review_path();

@set_time_limit(300);

// --- Apply: save what the review file holds --------------------------------

if ($apply) {
    if (!file_exists($review_path)) {
        echo json_encode(['success' => false, 'error' => 'No review file. Run without ?apply=1 first.']);
        exit;
    }
    $review = json_decode((string) file_get_contents($review_path), true);
    if (!is_array($review) || empty($review['proposals'])) {
        echo json_encode(['success' => false, 'error' => 'The review file holds no proposals.']);
        exit;
    }

    $saved = [];
    $skipped = [];
    foreach ($review['proposals'] as $row) {
        $attachment_id = isset($row['attachment']) ? (int) $row['attachment'] : 0;
        $ids = isset($row['facilities']) && is_array($row['facilities'])
            ? array_map(static function ($f) { return isset($f['id']) ? (int) $f['id'] : (int) $f; }, $row['facilities'])
            : [];
        $ids = kop_research_valid_facility_ids($ids);

        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment') {
            $skipped[] = ['attachment' => $attachment_id, 'why' => 'no such document'];
            continue;
        }

        // Added to what is already there: an editor's own tags stay.
        $existing = kop_research_facility_ids('att:' . $attachment_id);
        $merged = array_values(array_unique(array_merge($existing, $ids)));
        $old_contexts = function_exists('kop_document_facility_contexts')
            ? kop_document_facility_contexts($attachment_id)
            : [];
        $proposed_pages = [];
        foreach ((array) ($row['facilities'] ?? []) as $facility) {
            if (is_array($facility) && !empty($facility['id']) && !empty($facility['pages'])) {
                $proposed_pages[(int) $facility['id']] = (string) $facility['pages'];
            }
        }
        $associations = [];
        foreach ($merged as $facility_id) {
            $context = $old_contexts[$facility_id] ?? [];
            $associations[] = [
                'facility_id' => $facility_id,
                'note' => (string) ($context['note'] ?? ''),
                'pages' => (string) (($context['pages'] ?? '') !== '' ? $context['pages'] : ($proposed_pages[$facility_id] ?? '')),
            ];
        }
        if (function_exists('kop_document_save_facility_associations')) {
            kop_document_save_facility_associations($attachment_id, $associations, true);
        } else {
            delete_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META);
            foreach ($merged as $facility_id) {
                add_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META, $facility_id);
            }
        }
        $saved[] = [
            'attachment' => $attachment_id,
            'title'      => get_the_title($attachment_id),
            'added'      => array_values(array_diff($merged, $existing)),
            'total'      => count($merged),
        ];
    }

    echo json_encode([
        'success'     => true,
        'mode'        => 'apply',
        'review_file' => $review_path,
        'saved'       => $saved,
        'skipped'     => $skipped,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

// --- Propose ----------------------------------------------------------------

$index = kop_prft_name_index();
$all_attachments = kop_prft_library_attachments();
$attachments = array_slice($all_attachments, $offset, $limit);

$proposals = [];
$scanned = 0;
$no_text = [];

foreach ($attachments as $attachment_id) {
    $scanned++;

    // A checked document stays out of the proposal queue until an editor
    // explicitly revisits it in the Document Facility Links screen.
    if (get_post_meta($attachment_id, KOP_DOCUMENT_FACILITY_REVIEWED_META, true) !== '') {
        continue;
    }

    $post = get_post($attachment_id);
    if (!$post) {
        continue;
    }

    $where = [];
    $hits = [];
    $page_hits = [];
    $excerpts = [];
    foreach (['title' => $post->post_title, 'description' => $post->post_content, 'byline' => $post->post_excerpt] as $field => $value) {
        foreach (kop_prft_matches($value, $index) as $facility_id => $name_key) {
            $hits[$facility_id] = $name_key;
            $where[$facility_id] = $field;
        }
    }

    if ($read_text) {
        $path = get_attached_file($attachment_id);
        $mime = (string) $post->post_mime_type;
        if ($path && file_exists($path) && stripos($mime, 'pdf') !== false) {
            $text = kop_extract_pdf_text($path, true);
        } elseif ($path && file_exists($path) && function_exists('kop_extract_document_text')) {
            $text = kop_extract_document_text($path, $mime);
        } else {
            $text = '';
        }
        if (trim($text) === '') {
            $no_text[] = ['attachment' => (int) $attachment_id, 'title' => $post->post_title];
        } else {
            if (stripos($mime, 'pdf') !== false) {
                $pages = preg_split('/\f/', $text);
                foreach ($pages as $page_index => $page_text) {
                    foreach (kop_prft_matches($page_text, $index) as $facility_id => $name_key) {
                        if (!isset($hits[$facility_id])) {
                            $hits[$facility_id] = $name_key;
                            $where[$facility_id] = 'text';
                        }
                        $page_hits[$facility_id][] = $page_index + 1;
                        if (empty($excerpts[$facility_id])) {
                            $excerpts[$facility_id] = kop_prft_excerpt($page_text, $facility_id, $index);
                        }
                    }
                }
            } else {
                foreach (kop_prft_matches($text, $index) as $facility_id => $name_key) {
                    if (!isset($hits[$facility_id])) {
                        $hits[$facility_id] = $name_key;
                        $where[$facility_id] = 'text';
                    }
                }
            }
        }
    }

    $existing = kop_research_facility_ids('att:' . $attachment_id);
    foreach ($existing as $facility_id) {
        unset($hits[$facility_id]);
        unset($page_hits[$facility_id]);
        unset($excerpts[$facility_id]);
    }
    if (!$hits) {
        continue;
    }

    $chips = kop_research_facility_chips(array_keys($hits));
    $facilities = [];
    foreach ($chips as $chip) {
        $facilities[] = [
            'id'      => $chip['id'],
            'name'    => $chip['name'],
            'place'   => $chip['place'],
            'matched' => $hits[$chip['id']],
            'found_in' => $where[$chip['id']],
            'pages' => !empty($page_hits[$chip['id']])
                ? 'PDF ' . (count(array_unique($page_hits[$chip['id']])) === 1 ? 'p. ' : 'pp. ')
                    . implode(', ', array_unique($page_hits[$chip['id']]))
                : '',
            'excerpt' => (string) ($excerpts[$chip['id']] ?? ''),
        ];
    }

    $proposals[] = [
        'attachment'    => (int) $attachment_id,
        'title'         => $post->post_title,
        'already_tagged' => $existing,
        'facilities'    => $facilities,
    ];
}

$review = [
    'generated'  => gmdate('c'),
    'note'       => 'Delete any facility or whole proposal that is wrong, then load this script again with ?apply=1. Nothing is saved until then.',
    'proposals'  => $proposals,
];
$written = (bool) @file_put_contents($review_path, json_encode($review, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo json_encode([
    'success'        => true,
    'mode'           => 'propose',
    'names_indexed'  => count($index),
    'scanned'        => $scanned,
    'offset'         => $offset,
    'next_offset'    => $offset + count($attachments),
    'remaining'      => max(0, count($all_attachments) - ($offset + count($attachments))),
    'library_documents' => count($all_attachments),
    'documents_with_proposals' => count($proposals),
    'facilities_proposed' => array_sum(array_map(static function ($row) { return count($row['facilities']); }, $proposals)),
    'no_text_extracted' => $no_text,
    'review_file'    => $written ? $review_path : '',
    'review_error'   => $written ? '' : 'Could not write the review file.',
    'sample'         => array_slice($proposals, 0, 10),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
