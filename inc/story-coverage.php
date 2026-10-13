<?php
/**
 * "Also covered by" on the news feed, corrected by hand.
 *
 * The feed folds articles that tell one story into one card
 * (news_submissions.story_group_id, grouped automatically by
 * api/news-story-groups.php). The guess is sometimes wrong. An admin's pencil
 * on a card's "Also covered by" list ('storycov:<news id>') lists the articles
 * grouped with it: untick one to take it off, paste an article's link (or #id)
 * to add it. Every row the save touches is marked story_group_manual = 1, so
 * the automatic grouping never undoes the correction. Undo
 * ('storycovundo:<token>') puts every touched row back exactly, unless one has
 * changed since.
 *
 * The core functions take a PDO and no WordPress, so
 * scripts/test-story-coverage.php runs them on an in-memory SQLite copy.
 */

require_once dirname(__DIR__) . '/api/news-story-groups.php';
require_once dirname(__DIR__) . '/api/url-dedupe.php';
require_once dirname(__DIR__) . '/api/lib-news-archive.php';

if (!function_exists('kop_story_cov_pdo')) {
    function kop_story_cov_pdo() {
        if (function_exists('kop_seed_pdo')) {
            $pdo = kop_seed_pdo();
            if ($pdo instanceof PDO) return $pdo;
        }
        return isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null;
    }
}

if (!function_exists('kop_story_cov_attr')) {
    /** The admin's pencil on a card's "Also covered by" list ('' for everyone else). */
    function kop_story_cov_attr($news_id) {
        return function_exists('kop_ie_attr') && (int) $news_id > 0
            ? kop_ie_attr('storycov:' . (int) $news_id, '"Also covered by": take a wrong article off, or add one')
            : '';
    }
}

if (!function_exists('kop_story_cov_article')) {
    /** One live article: id, title, outlet, date, story_group_id, story_group_manual; null when gone. */
    function kop_story_cov_article(PDO $pdo, $id) {
        kop_news_story_manual_ensure($pdo);
        $st = $pdo->prepare("SELECT id, article_title, alternate_title, publication_name, publication_date, article_url, status,
                                    story_group_id, story_group_manual
                             FROM news_submissions WHERE id = ? AND status NOT IN ('rejected', 'deleted')");
        $st->execute(array((int) $id));
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('kop_story_cov_label')) {
    /** "Outlet, Mar 3, 2024: Headline" (plus a note when it is not on the feed yet). */
    function kop_story_cov_label(array $row) {
        $title = trim((string) ($row['alternate_title'] ?: $row['article_title']));
        $outlet = trim((string) $row['publication_name']);
        if ($outlet === '' && !empty($row['article_url'])) {
            $outlet = (string) preg_replace('/^www\./', '', (string) parse_url($row['article_url'], PHP_URL_HOST));
        }
        $date = !empty($row['publication_date']) && strtotime($row['publication_date']) ? date('M j, Y', strtotime($row['publication_date'])) : '';
        $head = trim($outlet . ($date !== '' ? ', ' . $date : ''), ', ');
        $label = ($head !== '' ? $head . ': ' : '') . ($title !== '' ? $title : 'Article #' . (int) $row['id']);
        if (!in_array($row['status'] ?? '', array('approved', 'published'), true)) {
            $label .= ' (not on the feed yet)';
        }
        return $label;
    }
}

if (!function_exists('kop_story_cov_members')) {
    /** The other live articles in $id's story group, newest first. */
    function kop_story_cov_members(PDO $pdo, $id) {
        $a = kop_story_cov_article($pdo, $id);
        if (!$a || empty($a['story_group_id'])) return array();
        $st = $pdo->prepare("SELECT id, article_title, alternate_title, publication_name, publication_date, article_url, status,
                                    story_group_id, story_group_manual
                             FROM news_submissions
                             WHERE story_group_id = ? AND id <> ? AND status NOT IN ('rejected', 'deleted')
                             ORDER BY publication_date DESC, id DESC");
        $st->execute(array((int) $a['story_group_id'], (int) $id));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('kop_story_cov_resolve')) {
    /**
     * Article ids for what was pasted, one a line: a link to the article or its
     * archived copy, a KOP article id ("123" or "#123"). Returns
     * ['ids' => [..], 'unknown' => [lines that name no article]].
     */
    function kop_story_cov_resolve(PDO $pdo, $text) {
        $ids = array();
        $unknown = array();
        foreach (preg_split('/[\r\n]+/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^#?(\d+)$/', $line, $m)) {
                if (kop_story_cov_article($pdo, (int) $m[1])) {
                    $ids[(int) $m[1]] = true;
                } else {
                    $unknown[] = $line;
                }
                continue;
            }
            $needles = array();
            foreach (array($line, kop_news_unwrap_archive_url($line)) as $u) {
                $n = $u !== '' ? kop_normalize_url($u) : null;
                if ($n !== null) $needles[$n] = true;
            }
            $found = $needles ? kop_find_url_duplicates(
                $pdo, 'news_submissions', array('article_url', 'archive_url'),
                array('article_url' => array_keys($needles), 'archive_url' => array_keys($needles)),
                'status', array('rejected', 'deleted'), 'article_title'
            ) : array();
            if (!$found) {
                $unknown[] = $line;
                continue;
            }
            foreach ($found as $f) $ids[(int) $f['id']] = true;
        }
        return array('ids' => array_keys($ids), 'unknown' => $unknown);
    }
}

if (!function_exists('kop_story_cov_state')) {
    /** [id => [story_group_id, story_group_manual]] for the given rows, as stored now. */
    function kop_story_cov_state(PDO $pdo, array $ids) {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (!$ids) return array();
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT id, story_group_id, story_group_manual FROM news_submissions WHERE id IN ($ph)");
        $st->execute($ids);
        $out = array();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['id']] = array(
                $r['story_group_id'] === null || $r['story_group_id'] === '' ? null : (int) $r['story_group_id'],
                (int) $r['story_group_manual'],
            );
        }
        ksort($out);
        return $out;
    }
}

if (!function_exists('kop_story_cov_write_state')) {
    function kop_story_cov_write_state(PDO $pdo, array $state) {
        $st = $pdo->prepare('UPDATE news_submissions SET story_group_id = ?, story_group_manual = ? WHERE id = ?');
        foreach ($state as $id => $s) {
            $st->execute(array($s[0], (int) $s[1], (int) $id));
        }
    }
}

if (!function_exists('kop_story_cov_apply')) {
    /**
     * $id's story becomes $id + $keep (ids now grouped with it) + $add (other
     * articles, each moved out of its own group). Articles grouped with it and
     * not kept stand alone. Every row touched is marked manual. An article
     * left alone in a group it was moved out of stands alone too.
     * Returns ['before' => state, 'after' => state, 'removed' => n, 'added' => n]
     * (state as kop_story_cov_state()); before === after when nothing changed.
     */
    function kop_story_cov_apply(PDO $pdo, $id, array $keep, array $add) {
        $id = (int) $id;
        $a = kop_story_cov_article($pdo, $id);
        if (!$a) throw new RuntimeException('That article is gone.');
        $members = array();
        foreach (kop_story_cov_members($pdo, $id) as $m) $members[(int) $m['id']] = $m;

        $keep = array_values(array_intersect(array_map('intval', $keep), array_keys($members)));
        $removed = array_values(array_diff(array_keys($members), $keep));
        $added = array();
        foreach (array_map('intval', $add) as $x) {
            if ($x !== $id && !isset($members[$x]) && kop_story_cov_article($pdo, $x)) $added[$x] = true;
        }
        $added = array_keys($added);

        // Groups the added articles leave: what remains there.
        $left = array();
        foreach ($added as $x) {
            $xa = kop_story_cov_article($pdo, $x);
            if (!empty($xa['story_group_id'])) {
                foreach (kop_story_cov_members($pdo, $x) as $m) {
                    if ((int) $m['id'] !== $id && !in_array((int) $m['id'], $added, true)) $left[(int) $xa['story_group_id']][(int) $m['id']] = true;
                }
            }
        }

        $touched = array_merge(array($id), array_keys($members), $added);
        foreach ($left as $rest) $touched = array_merge($touched, array_keys($rest));
        $before = kop_story_cov_state($pdo, $touched);

        $after = $before;
        $group = array_merge(array($id), $keep, $added);
        $gid = count($group) > 1 ? min($group) : null;
        foreach ($group as $x) $after[$x] = array($gid, 1);
        foreach ($removed as $x) $after[$x] = array(null, 1);
        // A group an added article left: one article left over stands alone; two or more
        // take the lowest remaining id as their group id (the old one may be the moved article's).
        foreach ($left as $rest) {
            $rest_ids = array_keys($rest);
            $rest_gid = count($rest_ids) > 1 ? min($rest_ids) : null;
            foreach ($rest_ids as $x) $after[$x] = array($rest_gid, $before[$x][1]);
        }
        // Only a real change marks anything manual.
        $changed = false;
        foreach ($after as $x => $s) {
            if ($s[0] !== $before[$x][0]) { $changed = true; break; }
        }
        if (!$changed) {
            return array('before' => $before, 'after' => $before, 'removed' => 0, 'added' => 0);
        }
        kop_story_cov_write_state($pdo, $after);
        return array('before' => $before, 'after' => kop_story_cov_state($pdo, array_keys($after)), 'removed' => count($removed), 'added' => count($added));
    }
}

if (!function_exists('kop_story_cov_undo')) {
    /** Puts the rows back as $before, unless any has changed since $after. */
    function kop_story_cov_undo(PDO $pdo, array $before, array $after) {
        $before = array_combine(array_map('intval', array_keys($before)), array_values($before));
        $after = array_combine(array_map('intval', array_keys($after)), array_values($after));
        ksort($after);
        if (kop_story_cov_state($pdo, array_keys($after)) != $after) {
            throw new RuntimeException('These articles have been regrouped since. Use the pencil on "Also covered by" to change them back.');
        }
        kop_story_cov_write_state($pdo, $before);
    }
}

// ---------------------------------------------------------------------------
// The pencil: 'storycov:<news id>', Undo 'storycovundo:<token>'
// ---------------------------------------------------------------------------

if (!function_exists('kop_story_cov_ie_load')) {
    function kop_story_cov_ie_load(array $p) {
        $pdo = kop_story_cov_pdo();
        if (!$pdo) throw new RuntimeException('No database connection.');
        $id = (int) ($p[0] ?? 0);
        $a = kop_story_cov_article($pdo, $id);
        if (!$a) throw new RuntimeException('That article is gone.');
        $options = array();
        $ids = array();
        foreach (kop_story_cov_members($pdo, $id) as $m) {
            $options[] = array('value' => (string) $m['id'], 'label' => kop_story_cov_label($m));
            $ids[] = (string) $m['id'];
        }
        $fields = array();
        if ($options) {
            $fields[] = kop_ie_field('keep', 'The same story as this article (untick one that is not)', 'checks', $ids, array('options' => $options, 'wide' => true));
        } else {
            $fields[] = kop_ie_field('none', 'Also covered by', 'note', 'No other article is grouped with this one.');
        }
        $fields[] = kop_ie_field('add', 'Add articles about the same story', 'lines', '', array(
            'rows' => 3,
            'help' => 'One a line: the article\'s link (or its archived copy\'s), or its KOP id like #1234. It must already be in the news list. An article grouped with another story moves here on its own.',
        ));
        return array(
            'title'  => 'Also covered by: ' . trim((string) ($a['alternate_title'] ?: $a['article_title'])),
            'help'   => 'These articles show as one card on the news feed. Your change stays: the automatic grouping leaves articles you have grouped by hand alone.',
            'fields' => $fields,
        );
    }
}

if (!function_exists('kop_story_cov_ie_save')) {
    function kop_story_cov_ie_save(array $p, array $v) {
        $pdo = kop_story_cov_pdo();
        if (!$pdo) throw new RuntimeException('No database connection.');
        $id = (int) ($p[0] ?? 0);
        // No list shown = nothing grouped with it to keep or drop.
        $keep = isset($v['keep']) && is_array($v['keep']) ? $v['keep'] : array();
        $found = kop_story_cov_resolve($pdo, (string) ($v['add'] ?? ''));
        if ($found['unknown']) {
            throw new RuntimeException('Not in the news list: ' . implode(', ', $found['unknown']) . '. Add the article through the news processor first, or check the link.');
        }
        $r = kop_story_cov_apply($pdo, $id, $keep, $found['ids']);
        if ($r['before'] === $r['after']) {
            return array('message' => 'No change.');
        }
        $parts = array();
        if ($r['removed']) $parts[] = $r['removed'] . ' taken off';
        if ($r['added']) $parts[] = $r['added'] . ' added';
        $message = 'Also covered by: ' . implode(', ', $parts) . '.';
        if (!function_exists('set_transient') || !function_exists('wp_generate_password')) {
            return array('message' => $message);
        }
        $token = wp_generate_password(20, false);
        set_transient('kop_storycov_undo_' . $token, array('before' => $r['before'], 'after' => $r['after']), DAY_IN_SECONDS);
        return array('message' => $message, 'undo' => 'storycovundo:' . $token);
    }
}

if (!function_exists('kop_story_cov_ie_undo')) {
    function kop_story_cov_ie_undo(array $p, array $v) {
        $key = 'kop_storycov_undo_' . preg_replace('/[^A-Za-z0-9]/', '', (string) ($p[0] ?? ''));
        $saved = get_transient($key);
        if (!is_array($saved)) {
            throw new RuntimeException('That change can no longer be undone here. Use the pencil on "Also covered by" to change it back.');
        }
        $pdo = kop_story_cov_pdo();
        if (!$pdo) throw new RuntimeException('No database connection.');
        kop_story_cov_undo($pdo, $saved['before'], $saved['after']);
        delete_transient($key);
        return array('message' => 'Undone.');
    }
}

if (function_exists('add_filter')) {
    add_filter('kop_inline_edit_sources', static function ($sources) {
        $sources['storycov'] = array('load' => 'kop_story_cov_ie_load', 'save' => 'kop_story_cov_ie_save');
        $sources['storycovundo'] = array('load' => function () { throw new RuntimeException('Nothing to show.'); }, 'save' => 'kop_story_cov_ie_undo');
        return $sources;
    });
}
