<?php
/**
 * The r/troubledteens program wiki entries (wiki_submissions): who readers
 * contact, and whether our copy of a page differs from Reddit's.
 *
 * Contact: every "please contact ..." in an entry names the subreddit's
 * modmail, never one person's account. kop_wiki_contact_normalize() is the PHP
 * twin of normalizeContactTag() in js/wiki-generation.js (same handles, same
 * link); kop_wiki_contact_migrate() rewrites the saved rows once
 * (inc/wiki-contact.php).
 *
 * Reddit: scripts/reddit-wiki-live.py reads the live wiki pages, pairs each
 * entry with the page it was imported from (never by name) and compares the
 * words; its result, js/data/reddit-wiki/live-compare.json, is what
 * kop_wiki_reddit_compare() reports. An entry saved after that check shows as
 * changed since then; one the check never reached gets no mark at all.
 */

if (!defined('KOP_WIKI_CONTACT_URL')) {
    define('KOP_WIKI_CONTACT_URL', 'https://www.reddit.com/message/compose?to=/r/troubledteens');
}

if (!function_exists('kop_wiki_contact_link')) {
    function kop_wiki_contact_link() {
        return '[r/troubledteens modmail](' . KOP_WIKI_CONTACT_URL . ')';
    }
}

if (!function_exists('kop_wiki_contact_normalize')) {
    /** Every mention of an old contact handle (link, u/ path or bare name) -> the modmail link. */
    function kop_wiki_contact_normalize($md) {
        $names = 'Miss_Nobody89|Signal-Strain9810|Signal-Strain8910';
        $re = '~\[[^\]\n]*(?:' . $names . ')[^\]\n]*\]\([^)\n]*\)'
            . '|\[[^\]\n]*\]\([^)\n]*(?:' . $names . ')[^)\n]*\)'
            . '|/?u(?:ser)?/(?:' . $names . ')/?'
            . '|(?:' . $names . ')~';
        $link = kop_wiki_contact_link();
        return preg_replace_callback($re, function () use ($link) { return $link; }, (string) $md);
    }
}

if (!function_exists('kop_wiki_contact_walk')) {
    /** kop_wiki_contact_normalize() over every string in a decoded json_data value. */
    function kop_wiki_contact_walk($value) {
        if (is_string($value)) return kop_wiki_contact_normalize($value);
        if (is_array($value)) {
            foreach ($value as $k => $v) $value[$k] = kop_wiki_contact_walk($v);
        }
        return $value;
    }
}

if (!function_exists('kop_wiki_contact_migrate')) {
    /**
     * Rewrite the contact in every wiki_submissions row (and wiki_master, where
     * that table exists). Idempotent. $apply = false only counts.
     * Returns ['rows' => n, 'fields' => n] (+ 'error').
     */
    function kop_wiki_contact_migrate(PDO $pdo, $apply = true) {
        $out = array('rows' => 0, 'fields' => 0);
        $tables = array('wiki_submissions' => array('generated_markdown', 'original_markdown', 'json_data'),
                        'wiki_master'      => array('markdown', 'json_data'));
        $pattern = '~Miss_Nobody89|Signal-Strain(?:9810|8910)~';
        foreach ($tables as $table => $cols) {
            try {
                $rows = $pdo->query('SELECT id, ' . implode(', ', $cols) . " FROM `$table`");
            } catch (Throwable $e) {
                continue; // wiki_master is not on every install
            }
            if (!$rows) continue;
            $todo = array();
            while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
                $set = array();
                foreach ($cols as $col) {
                    $old = (string) ($row[$col] ?? '');
                    if ($old === '' || !preg_match($pattern, $old)) continue;
                    if ($col === 'json_data') {
                        $decoded = json_decode($old, true);
                        if (!is_array($decoded)) continue;
                        $new = json_encode(kop_wiki_contact_walk($decoded), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        if ($new === false) continue;
                    } else {
                        $new = kop_wiki_contact_normalize($old);
                    }
                    if ($new !== $old) $set[$col] = $new;
                }
                if ($set) $todo[(int) $row['id']] = $set;
            }
            foreach ($todo as $id => $set) {
                $out['rows']++;
                $out['fields'] += count($set);
                if (!$apply) continue;
                $sql = "UPDATE `$table` SET " . implode(', ', array_map(function ($c) { return "`$c` = ?"; }, array_keys($set))) . ' WHERE id = ?';
                // updated_at is left alone where MySQL's ON UPDATE would bump it:
                // a wording change is not an edit to the entry.
                if ($table === 'wiki_submissions') $sql = str_replace(' WHERE id = ?', ', updated_at = updated_at WHERE id = ?', $sql);
                try {
                    $pdo->prepare($sql)->execute(array_merge(array_values($set), array($id)));
                } catch (Throwable $e) {
                    $out['error'] = $e->getMessage();
                    return $out;
                }
            }
        }
        return $out;
    }
}

// --- Reddit comparison -------------------------------------------------------

if (!function_exists('kop_wiki_reddit_live')) {
    /** js/data/reddit-wiki/live-compare.json, or null before the first check. */
    function kop_wiki_reddit_live() {
        static $data = false;
        if ($data !== false) return $data;
        $file = defined('KOP_WIKI_REDDIT_LIVE_FILE') ? KOP_WIKI_REDDIT_LIVE_FILE   // tests
            : dirname(__DIR__) . '/js/data/reddit-wiki/live-compare.json';
        $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $data = (is_array($decoded) && isset($decoded['rows'], $decoded['pages'])) ? $decoded : null;
        return $data;
    }
}

if (!function_exists('kop_wiki_reddit_compare')) {
    /**
     * One wiki_submissions row against its live Reddit page: null when the
     * check has no result for it, else
     *   slug, reddit_url, checked (date the page was read), revised (Reddit's
     *   last edit, '' when unknown), state: 'same' | 'differs' | 'missing'
     *   (no such page on Reddit) | 'changed' (saved here after the check),
     *   edited (saved in the wiki editor, not the import), samples.
     */
    function kop_wiki_reddit_compare(array $row) {
        $live = kop_wiki_reddit_live();
        $rec = $live['rows'][(string) (int) ($row['id'] ?? 0)] ?? null;
        if (!$live || !is_array($rec) || empty($rec['slug'])) return null;
        $page = $live['pages'][$rec['slug']] ?? array();
        if (empty($page['exists'])) {
            $state = 'missing';
        } elseif (isset($row['updated_at']) && (string) $row['updated_at'] !== (string) $rec['updated_at']) {
            $state = 'changed';
        } else {
            $state = !empty($rec['differs']) ? 'differs' : 'same';
        }
        return array(
            'slug'       => $rec['slug'],
            'reddit_url' => 'https://www.reddit.com/r/troubledteens/wiki/index/' . ($state === 'missing' ? '' : rawurlencode($rec['slug']) . '/'),
            'checked'    => (string) ($page['fetched'] ?? ''),
            'revised'    => (string) ($page['revised'] ?? ''),
            'state'      => $state,
            'edited'     => !empty($rec['edited']),
            'samples'    => $state === 'differs' ? array_values((array) ($rec['samples'] ?? array())) : array(),
        );
    }
}

if (!function_exists('kop_wiki_reddit_compare_all')) {
    /**
     * Reddit page slug => kop_wiki_reddit_compare() of the newest live entry
     * paired with that page (the one the wiki editor opens).
     */
    function kop_wiki_reddit_compare_all(PDO $pdo) {
        if (!kop_wiki_reddit_live()) return array();
        $rows = $pdo->query(
            "SELECT id, updated_at FROM wiki_submissions
              WHERE status NOT IN ('deleted', 'rejected')
              ORDER BY updated_at DESC, id DESC"
        );
        $out = array();
        while ($rows && ($row = $rows->fetch(PDO::FETCH_ASSOC))) {
            $cmp = kop_wiki_reddit_compare($row);
            if ($cmp !== null && !isset($out[$cmp['slug']])) $out[$cmp['slug']] = $cmp;
        }
        return $out;
    }
}
