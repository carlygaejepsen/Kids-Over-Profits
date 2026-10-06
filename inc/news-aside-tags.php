<?php
/**
 * Articles already on file that are about a celebrity or a viral story more
 * than about the program (api/news-tags.php kop_news_aside_tags()). They get
 * the Celebrity or Viral tag once per KOP_NEWS_ASIDE_TAGS_VERSION, so the
 * facility and company pages list them apart, collapsed. New articles get the
 * tag from the AI extractor; an admin adds or removes it like any other tag.
 * A tag removed by hand stays removed until the version is bumped.
 */

if (!defined('ABSPATH')) {
    exit;
}

define('KOP_NEWS_ASIDE_TAGS_VERSION', '1');

/** news_submissions id => tag, read from titles and summaries on 2026-10-06. */
function kop_news_aside_tag_seed() {
    $tags = array();
    // Celebrities' own stories (interviews, memoirs, documentaries about them,
    // their families, their arrests). Closures, testimony and bills that name
    // one are not here.
    $celebrity = array(
        26,                                                         // Nick Reiner
        201, 268, 733, 746, 749, 758, 766, 768, 769,                // Paris Hilton interviews, memoir, "This Is Paris"
        368,                                                        // Earl Spencer
        373, 505,                                                   // Sylvester Stallone
        379,                                                        // Presley Gerber
        856,                                                        // Michael Skakel
        288, 296, 358, 362, 363, 372,                               // Matt Bevin's own court case
        865, 873, 874, 876, 877, 879, 880, 881, 887, 888, 894, 900, 901, 902, // Bhad Bhabie
    );
    // A YouTuber's video, a viral post, a TV show.
    $viral = array(
        74,                                                         // viral wilderness camp story
        281, 297, 298,                                              // Reckless Ben's undercover video
        837,                                                        // "Evil Lives Here" episode
        1005,                                                       // "Camp Kush" sports fan
    );
    foreach ($celebrity as $id) $tags[$id] = 'Celebrity';
    foreach ($viral as $id) $tags[$id] = 'Viral';
    return $tags;
}

add_action('init', 'kop_news_aside_tags_maybe_apply', 30);

function kop_news_aside_tags_maybe_apply() {
    if (get_option('kop_news_aside_tags_version') === KOP_NEWS_ASIDE_TAGS_VERSION) {
        return;
    }
    if (get_transient('kop_news_aside_tags_running')) {
        return;
    }
    set_transient('kop_news_aside_tags_running', 1, 10 * MINUTE_IN_SECONDS);
    $pdo = function_exists('kop_seed_pdo') ? kop_seed_pdo() : null;
    if (!$pdo) {
        delete_transient('kop_news_aside_tags_running');
        return;
    }
    $result = kop_news_aside_tags_apply($pdo, kop_news_aside_tag_seed());
    if (empty($result['error'])) {
        update_option('kop_news_aside_tags_version', KOP_NEWS_ASIDE_TAGS_VERSION, true);
    }
    update_option('kop_news_aside_tags_last_run', $result + array('at' => gmdate('c')), false);
    delete_transient('kop_news_aside_tags_running');
}

/** Adds each tag to its article's tags (kept if already there). Returns {tagged, already, missing} or {error}. */
function kop_news_aside_tags_apply(PDO $pdo, array $seed) {
    require_once get_stylesheet_directory() . '/api/news-tags.php';
    $out = array('tagged' => 0, 'already' => 0, 'missing' => 0);
    if (!$seed) return $out;
    try {
        $ids = implode(',', array_map('intval', array_keys($seed)));
        $rows = $pdo->query("SELECT id, tags FROM news_submissions WHERE id IN ({$ids})")->fetchAll(PDO::FETCH_ASSOC);
        $found = array();
        $update = $pdo->prepare('UPDATE news_submissions SET tags = ? WHERE id = ?');
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $found[$id] = true;
            $tags = kop_news_tags_normalize((string) $r['tags']);
            if (in_array(strtolower($seed[$id]), array_map('strtolower', $tags), true)) {
                $out['already']++;
                continue;
            }
            $tags[] = $seed[$id];
            $update->execute(array(json_encode(array_values($tags), JSON_UNESCAPED_UNICODE), $id));
            $out['tagged']++;
        }
        $out['missing'] = count(array_diff_key($seed, $found));
    } catch (Exception $e) {
        return array('error' => $e->getMessage());
    }
    return $out;
}
