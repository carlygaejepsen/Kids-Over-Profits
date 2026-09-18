<?php
/**
 * Regenerate first-page preview images for PDFs that have none.
 *
 * The May 2026 media restore registered about 150 PDFs with metadata holding
 * only a filesize, so WordPress never rendered their first page, and a handful
 * of older PDFs point at preview files that are no longer on disk. Every
 * document list on the site (state and country hubs, location index, program
 * index, research library) reads the preview through
 * kop_get_attachment_preview_url(), which has nothing to return for them, so
 * they show a file-type badge instead of a cover.
 *
 * A PDF qualifies when its metadata has no sizes, or when none of the size
 * files it names exists. PDFs with a working preview are never touched.
 * Generation is wp_generate_attachment_metadata(), the same call an upload
 * makes; it writes new "<name>-pdf*.jpg" files beside the PDF and never
 * changes the PDF itself.
 *
 * GET                     - dry run: count and list what qualifies
 * GET ?apply=1            - regenerate one batch (default 10), report the rest
 * GET ?apply=1&limit=25   - batch size, 1 to 50
 *
 * Re-run ?apply=1 until "remaining" reaches 0. A PDF Imagick cannot read is
 * marked kop_pdf_preview_failed so later batches skip it; ?retry=1 clears
 * those marks.
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

const KOP_PDF_PREVIEW_FAILED_META = 'kop_pdf_preview_failed';

/** True when the PDF has at least one preview file on disk. */
function kop_rpp_has_preview($attachment_id, $pdf_path) {
    $meta = wp_get_attachment_metadata($attachment_id, true);
    if (!is_array($meta) || empty($meta['sizes']) || !is_array($meta['sizes'])) {
        return false;
    }
    $dir = trailingslashit(dirname($pdf_path));
    foreach ($meta['sizes'] as $info) {
        if (!empty($info['file']) && file_exists($dir . $info['file'])) {
            return true;
        }
    }
    return false;
}

$apply = isset($_GET['apply']) && $_GET['apply'] === '1';
$limit = isset($_GET['limit']) ? max(1, min(50, (int) $_GET['limit'])) : 10;

if (isset($_GET['retry']) && $_GET['retry'] === '1') {
    delete_post_meta_by_key(KOP_PDF_PREVIEW_FAILED_META);
}

$pdf_ids = get_posts([
    'post_type'      => 'attachment',
    'post_status'    => 'inherit',
    'post_mime_type' => 'application/pdf',
    'posts_per_page' => -1,
    'fields'         => 'ids',
    'orderby'        => 'ID',
    'order'          => 'ASC',
]);

$queue = [];
$missing_file = [];
$failed_before = [];
foreach ($pdf_ids as $id) {
    $path = get_attached_file($id);
    if (!$path || !file_exists($path)) {
        $missing_file[] = ['id' => (int) $id, 'file' => (string) get_post_meta($id, '_wp_attached_file', true)];
        continue;
    }
    if (kop_rpp_has_preview($id, $path)) {
        continue;
    }
    if (get_post_meta($id, KOP_PDF_PREVIEW_FAILED_META, true)) {
        $failed_before[] = (int) $id;
        continue;
    }
    $queue[] = ['id' => (int) $id, 'path' => $path];
}

$report = [
    'success'        => true,
    'mode'           => $apply ? 'apply' : 'dry-run',
    'pdfs'           => count($pdf_ids),
    'need_preview'   => count($queue),
    'pdf_missing'    => $missing_file,
    'failed_earlier' => $failed_before,
];

if (!$apply) {
    $report['sample'] = array_map(function ($row) {
        return ['id' => $row['id'], 'file' => basename($row['path'])];
    }, array_slice($queue, 0, 25));
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit;
}

require_once ABSPATH . 'wp-admin/includes/image.php';
@set_time_limit(300);

$done = [];
$failed = [];
foreach (array_slice($queue, 0, $limit) as $row) {
    $meta = wp_generate_attachment_metadata($row['id'], $row['path']);
    if (is_array($meta) && !empty($meta['sizes'])) {
        wp_update_attachment_metadata($row['id'], $meta);
        $done[] = ['id' => $row['id'], 'file' => basename($row['path']), 'sizes' => array_keys($meta['sizes'])];
    } else {
        // Encrypted or damaged PDFs: remember them so the queue can drain.
        update_post_meta($row['id'], KOP_PDF_PREVIEW_FAILED_META, gmdate('c'));
        $failed[] = ['id' => $row['id'], 'file' => basename($row['path'])];
    }
}

$report['regenerated'] = $done;
$report['failed']      = $failed;
$report['remaining']   = max(0, count($queue) - count($done) - count($failed));

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
