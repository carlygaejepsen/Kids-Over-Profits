<?php
/**
 * One-time cleanup: rewrite news_submissions.tags to the canonical vocabulary
 * in api/news-tags.php (synonyms merged, casing fixed, duplicates dropped).
 *
 * The news feed already canonicalizes tags when it renders, and new saves are
 * normalized in save-news-submission.php, so this only brings stored rows in
 * line (the state/country REST endpoints match on the raw tags column).
 *
 * GET            - dry run: report every row that would change, write nothing
 * GET ?apply=1   - write the changes
 *
 * Safe to re-run: a second pass finds nothing to change.
 * Requires manage_options.
 */

header('Content-Type: application/json');
set_time_limit(300);

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

require_once __DIR__ . '/news-tags.php';

$apply = !empty($_GET['apply']);

try {
    $rows = $pdo->query("SELECT id, article_title, tags FROM news_submissions WHERE tags IS NOT NULL AND tags <> '' ORDER BY id")
                ->fetchAll(PDO::FETCH_ASSOC);

    $update = $pdo->prepare("UPDATE news_submissions SET tags = ? WHERE id = ?");
    $report = [
        'mode'         => $apply ? 'apply' : 'dry_run',
        'rows_seen'    => count($rows),
        'rows_changed' => 0,
        'renames'      => [],  // "old" => "new", with how many rows used it
        'changes'      => [],
    ];

    foreach ($rows as $row) {
        $old = json_decode($row['tags'], true);
        if (!is_array($old)) {
            continue;
        }
        $new = kop_news_tags_normalize($old);
        if ($new === array_values($old)) {
            continue;
        }

        foreach ($old as $tag) {
            if (!is_scalar($tag)) {
                continue;
            }
            $canonical = kop_news_tag_canonical($tag);
            if ($canonical !== (string) $tag) {
                $key = $tag . ' => ' . $canonical;
                $report['renames'][$key] = ($report['renames'][$key] ?? 0) + 1;
            }
        }

        $report['rows_changed']++;
        $report['changes'][] = [
            'id'    => (int) $row['id'],
            'title' => $row['article_title'],
            'from'  => $old,
            'to'    => $new,
        ];

        if ($apply) {
            $update->execute([json_encode($new, JSON_UNESCAPED_UNICODE), (int) $row['id']]);
        }
    }

    arsort($report['renames']);
    echo json_encode(['success' => true] + $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
