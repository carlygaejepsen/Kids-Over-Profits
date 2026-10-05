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
 * Reddit: markdown_output/ holds the Reddit wiki export, one file per page
 * slug; js/data/reddit-wiki/programs-*.json ties each program name to its
 * slug. kop_wiki_reddit_compare() compares the text we hold for an entry with
 * that page, ignoring the contact link, the "Last revised by" footer, spacing
 * and markdown punctuation, so only a change in the words counts.
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

if (!function_exists('kop_wiki_theme_dir')) {
    function kop_wiki_theme_dir() {
        return dirname(__DIR__);
    }
}

if (!function_exists('kop_wiki_reddit_slugs')) {
    /** lowercase program name => Reddit wiki slug, from js/data/reddit-wiki/programs-*.json. */
    function kop_wiki_reddit_slugs() {
        static $map = null;
        if ($map !== null) return $map;
        $map = array();
        foreach ((array) glob(kop_wiki_theme_dir() . '/js/data/reddit-wiki/programs-*.json') as $file) {
            $data = json_decode((string) file_get_contents($file), true);
            foreach ((array) ($data['programs'] ?? array()) as $p) {
                if (!preg_match('~/wiki/(?:index/)?([^/?#]+)~', (string) ($p['url'] ?? ''), $m)) continue;
                foreach (array($p['name'] ?? '', $p['normalizedName'] ?? '') as $n) {
                    $key = strtolower(trim((string) $n));
                    if ($key !== '' && !isset($map[$key])) $map[$key] = strtolower($m[1]);
                }
            }
        }
        return $map;
    }
}

if (!function_exists('kop_wiki_reddit_markdown')) {
    /** The Reddit export of one page, or null when markdown_output/ has none. */
    function kop_wiki_reddit_markdown($slug) {
        $slug = strtolower(trim((string) $slug));
        if ($slug === '' || !preg_match('~^[a-z0-9_\-]+$~', $slug)) return null;
        $dir = kop_wiki_theme_dir() . '/markdown_output/';
        foreach (array($slug . '.md', 'index_' . $slug . '.md', 'index_' . $slug . '_.md') as $f) {
            if (is_file($dir . $f)) return (string) file_get_contents($dir . $f);
        }
        return null;
    }
}

if (!function_exists('kop_wiki_reddit_norm')) {
    /** The words of a page: no contact link, footer, link URLs' punctuation, emphasis or spacing differences. */
    function kop_wiki_reddit_norm($md) {
        $md = str_replace("\r", '', (string) $md);
        $md = preg_replace('~\n?\s*Last revised by.*$~is', '', $md);
        $md = kop_wiki_contact_normalize($md);
        $md = str_replace(kop_wiki_contact_link(), ' ', $md);
        $md = preg_replace('~\]\(([^)]*)\)~', ' $1 ', $md);
        $md = preg_replace('~[\\\\*_`>#|\[\]\-:]+~', ' ', $md);
        return trim(preg_replace('~\s+~u', ' ', mb_strtolower($md, 'UTF-8')));
    }
}

if (!function_exists('kop_wiki_is_import_row')) {
    /** A row from the bulk import of the Reddit export, not a page someone edited here (as api/wiki-stubs.php reads it). */
    function kop_wiki_is_import_row(array $row) {
        $by = strtolower(trim((string) ($row['submitted_by'] ?? '')));
        $notes = trim((string) ($row['submission_notes'] ?? ''));
        if (in_array($by, array('bulk-upload', 'batch-import-script', 'reimport-regenerated', 'import', 'system'), true)) return true;
        if (stripos($notes, 'batch imported') === 0 || stripos($notes, 'bulk') === 0) return true;
        return $by === '' && $notes === '';
    }
}

if (!function_exists('kop_wiki_our_markdown')) {
    /**
     * The text we hold for an entry: an imported row's original (the Reddit
     * text it came from, what /wiki-feed/ prints); an edited row's generated
     * markdown, which is the edit.
     */
    function kop_wiki_our_markdown(array $row) {
        $json = json_decode((string) ($row['json_data'] ?? ''), true);
        $json = is_array($json) ? $json : array();
        $original = trim((string) ($row['original_markdown'] ?? ''));
        if ($original === '') $original = trim((string) ($json['originalMarkdown'] ?? $json['original_markdown'] ?? ''));
        $generated = trim((string) ($row['generated_markdown'] ?? ''));
        if ($generated === '') $generated = trim((string) ($json['generatedMarkdown'] ?? $json['generated_markdown'] ?? ''));
        if (kop_wiki_is_import_row($row)) return $original !== '' ? $original : $generated;
        return $generated !== '' ? $generated : $original;
    }
}

if (!function_exists('kop_wiki_reddit_compare')) {
    /**
     * Our entry against its Reddit page: null when the program has no page in
     * the export, else ['slug', 'reddit_url', 'differs' => bool, 'edited' => bool].
     * 'edited' = someone saved changes in the wiki editor; otherwise a
     * difference means the text we imported no longer matches the export.
     * $ours: the text to compare when a page prints something other than
     * kop_wiki_our_markdown() (/wiki-feed/ prints the original).
     */
    function kop_wiki_reddit_compare(array $row, $ours = null) {
        $slugs = kop_wiki_reddit_slugs();
        $json = json_decode((string) ($row['json_data'] ?? ''), true);
        $slug = is_array($json) && !empty($json['sourceSlug']) ? strtolower(trim((string) $json['sourceSlug'])) : '';
        if ($slug === '') $slug = $slugs[strtolower(trim((string) ($row['program_name'] ?? '')))] ?? '';
        if ($slug === '') return null;
        $reddit = kop_wiki_reddit_markdown($slug);
        if ($reddit === null) return null;
        if ($ours === null) $ours = kop_wiki_our_markdown($row);
        $ours = (string) $ours;
        return array(
            'slug'       => $slug,
            'reddit_url' => 'https://www.reddit.com/r/troubledteens/wiki/index/' . $slug . '/',
            'differs'    => $ours !== '' && kop_wiki_reddit_norm($ours) !== kop_wiki_reddit_norm($reddit),
            'edited'     => !kop_wiki_is_import_row($row),
        );
    }
}

if (!function_exists('kop_wiki_reddit_compare_all')) {
    /**
     * Every program with a Reddit page, newest live row per name:
     * lowercase program name => kop_wiki_reddit_compare() result.
     */
    function kop_wiki_reddit_compare_all(PDO $pdo) {
        $rows = $pdo->query(
            "SELECT id, program_name, json_data, generated_markdown, original_markdown, submitted_by, submission_notes
               FROM wiki_submissions
              WHERE status NOT IN ('deleted', 'rejected') AND program_name IS NOT NULL AND program_name != ''
              ORDER BY updated_at DESC, id DESC"
        );
        $out = array();
        while ($rows && ($row = $rows->fetch(PDO::FETCH_ASSOC))) {
            $key = strtolower(trim((string) $row['program_name']));
            if (isset($out[$key])) continue;
            $cmp = kop_wiki_reddit_compare($row);
            if ($cmp !== null) $out[$key] = $cmp;
        }
        return $out;
    }
}
