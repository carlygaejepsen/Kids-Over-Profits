<?php
/**
 * Propose facility tags for the Research & Reports library.
 *
 * A document in the library (inc/research-library.php) can be tagged with the
 * facilities it is about, which puts it on those facility pages and puts chips
 * on its card. Tagging the existing library by hand means reading a hundred
 * PDFs, so this reads them instead: every facility name it finds in a
 * document's title, description or extracted text becomes a proposal.
 *
 * Nothing is saved on a proposal run. The proposals are written to a review
 * file in the uploads directory; delete the rows that are wrong, then run again
 * with ?apply=1, which saves exactly what the file holds and nothing else.
 *
 * GET                       - propose: scan, write the review file, report
 * GET ?apply=1              - save the tags in the review file
 * GET ?limit=40             - documents per proposal run (1 to 200, default 40)
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

if (!defined('KOP_RESEARCH_FACILITY_META')) {
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
    $ids = [];
    foreach (kop_research_library_folders() as $folder_id => $kind) {
        foreach ((array) kop_get_folder_attachments((int) $folder_id) as $attachment) {
            $ids[(int) $attachment->ID] = true;
        }
    }
    return array_keys($ids);
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

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : 40;
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

        if ($attachment_id <= 0 || get_post_type($attachment_id) !== 'attachment' || !$ids) {
            $skipped[] = ['attachment' => $attachment_id, 'why' => 'no such document, or nothing left to save'];
            continue;
        }

        // Added to what is already there: an editor's own tags stay.
        $existing = kop_research_facility_ids('att:' . $attachment_id);
        $merged = array_values(array_unique(array_merge($existing, $ids)));
        if ($merged === $existing) {
            $skipped[] = ['attachment' => $attachment_id, 'why' => 'already tagged with all of them'];
            continue;
        }

        delete_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META);
        foreach ($merged as $facility_id) {
            add_post_meta($attachment_id, KOP_RESEARCH_FACILITY_META, $facility_id);
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
$attachments = kop_prft_library_attachments();

$proposals = [];
$scanned = 0;
$no_text = [];

foreach ($attachments as $attachment_id) {
    if ($scanned >= $limit) {
        break;
    }
    $scanned++;

    $post = get_post($attachment_id);
    if (!$post) {
        continue;
    }

    $where = [];
    $hits = [];
    foreach (['title' => $post->post_title, 'description' => $post->post_content, 'byline' => $post->post_excerpt] as $field => $value) {
        foreach (kop_prft_matches($value, $index) as $facility_id => $name_key) {
            $hits[$facility_id] = $name_key;
            $where[$facility_id] = $field;
        }
    }

    if ($read_text) {
        $path = get_attached_file($attachment_id);
        $text = ($path && file_exists($path)) ? kop_extract_pdf_text($path) : '';
        if (trim($text) === '') {
            $no_text[] = ['attachment' => (int) $attachment_id, 'title' => $post->post_title];
        } else {
            foreach (kop_prft_matches($text, $index) as $facility_id => $name_key) {
                if (!isset($hits[$facility_id])) {
                    $hits[$facility_id] = $name_key;
                    $where[$facility_id] = 'text';
                }
            }
        }
    }

    $existing = kop_research_facility_ids('att:' . $attachment_id);
    foreach ($existing as $facility_id) {
        unset($hits[$facility_id]);
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
    'library_documents' => count($attachments),
    'scanned'        => $scanned,
    'remaining'      => max(0, count($attachments) - $scanned),
    'documents_with_proposals' => count($proposals),
    'facilities_proposed' => array_sum(array_map(static function ($row) { return count($row['facilities']); }, $proposals)),
    'no_text_extracted' => $no_text,
    'review_file'    => $written ? $review_path : '',
    'review_error'   => $written ? '' : 'Could not write the review file.',
    'sample'         => array_slice($proposals, 0, 10),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
