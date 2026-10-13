<?php
/**
 * Proper names: one sitewide list of words and names that are always
 * capitalised as written (NATSAP, WWASP, MacLaren, LLC...).
 *
 * Every place the site changes the case of text (title case, sentence case,
 * lower-casing a facility type) passes its result through
 * kop_proper_names_apply() as a last step, so a listed name comes out the way
 * it is written here whatever the step before did to it. js/proper-names.js
 * (kopProperNames()) does the same in the browser with the same list, sent
 * with the page. Text people typed is never rewritten: only text the site
 * recases goes through it.
 *
 * The list lives in the kop_proper_names option, edited at KOP Tools >
 * Proper Names (one name per line, Undo for the last save). Until that screen
 * is first saved, js/data/proper-names.json is the list.
 *
 * A name matches whole words only, in any capitals ("natsap", "Natsap"),
 * possessives included ("NATSAP's"); a name of several words matches with
 * any spacing between them.
 *
 * scripts/test-proper-names.php checks PHP and JS give the same text.
 */

if (!defined('KOP_PROPER_NAMES_OPTION')) {
    define('KOP_PROPER_NAMES_OPTION', 'kop_proper_names');
}

if (!function_exists('kop_proper_names_default')) {
    /** The starting list, from js/data/proper-names.json. */
    function kop_proper_names_default() {
        $path = dirname(__DIR__) . '/js/data/proper-names.json';
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        return kop_proper_names_clean(is_array($data) ? (array) ($data['names'] ?? array()) : array());
    }
}

if (!function_exists('kop_proper_names_clean')) {
    /**
     * Trimmed, single-spaced, one per name ignoring case (the first spelling
     * given wins), sorted A to Z ignoring case.
     */
    function kop_proper_names_clean($names) {
        $out = array();
        foreach ((array) $names as $name) {
            $name = trim(preg_replace('/\s+/u', ' ', (string) $name) ?? '');
            if ($name === '' || mb_strlen($name) > 80) continue;
            $key = kop_proper_names_key($name);
            if ($key === '' || isset($out[$key])) continue;
            $out[$key] = $name;
        }
        uksort($out, 'strcmp');
        return array_values($out);
    }
}

if (!function_exists('kop_proper_names_key')) {
    /** "O’Brien" / "o'brien" / "O'BRIEN" -> "o'brien". */
    function kop_proper_names_key($text) {
        $text = str_replace("\u{2019}", "'", (string) $text);
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? $text;
        return mb_strtolower($text, 'UTF-8');
    }
}

if (!function_exists('kop_proper_names')) {
    /** The list in force. $reset after a save in the same request. */
    function kop_proper_names($reset = false) {
        static $memo = null;
        if ($reset) $memo = null;
        if ($memo !== null) return $memo;
        $saved = function_exists('get_option') ? get_option(KOP_PROPER_NAMES_OPTION, null) : null;
        return $memo = is_array($saved) ? kop_proper_names_clean($saved) : kop_proper_names_default();
    }
}

if (!function_exists('kop_proper_names_pattern')) {
    /**
     * The regex for a list, and its key => spelling map. Longest names first,
     * so "MacLaren Hall" wins over "MacLaren". js/proper-names.js builds the
     * same pattern.
     */
    function kop_proper_names_pattern($names) {
        $map = array();
        foreach ($names as $name) $map[kop_proper_names_key($name)] = $name;
        if (!$map) return array('', array());
        $alts = array_keys($map);
        usort($alts, function ($a, $b) {
            return (mb_strlen($b) <=> mb_strlen($a)) ?: strcmp($a, $b);
        });
        $parts = array();
        foreach ($alts as $key) {
            $q = preg_quote($key, '/');
            $q = str_replace("'", '[\'\x{2019}]', $q);
            $q = str_replace(' ', '\s+', $q);
            $parts[] = $q;
        }
        return array('/(?<![\p{L}\p{N}])(?:' . implode('|', $parts) . ')(?![\p{L}\p{N}])/iu', $map);
    }
}

if (!function_exists('kop_proper_names_apply')) {
    /**
     * $text with every listed name spelled as listed. $names = another list
     * (the editor's preview); default the list in force.
     */
    function kop_proper_names_apply($text, $names = null) {
        if (!is_string($text) || $text === '') return $text;
        static $cache = array();
        $list = $names === null ? kop_proper_names() : kop_proper_names_clean($names);
        $sig = md5(implode("\n", $list));
        if (!isset($cache[$sig])) {
            if (count($cache) > 4) $cache = array();
            $cache[$sig] = kop_proper_names_pattern($list);
        }
        list($re, $map) = $cache[$sig];
        if ($re === '') return $text;
        $out = preg_replace_callback($re, function ($m) use ($map) {
            return $map[kop_proper_names_key($m[0])] ?? $m[0];
        }, $text);
        return $out === null ? $text : $out;
    }
}

if (!function_exists('kop_proper_names_common_words')) {
    /**
     * Listed names that are also ordinary English words, so every "it" or
     * "us" the site recases would change too. The editor warns about them.
     */
    function kop_proper_names_common_words($names) {
        static $words = null;
        if ($words === null) {
            $words = array_flip(array(
                'a', 'ad', 'am', 'an', 'and', 'are', 'as', 'at', 'be', 'but', 'by', 'can', 'co', 'did', 'do', 'don',
                'for', 'go', 'had', 'has', 'he', 'her', 'hi', 'him', 'his', 'i', 'id', 'if', 'in', 'is', 'it', 'its',
                'me', 'my', 'no', 'not', 'of', 'oh', 'ok', 'on', 'or', 'our', 'out', 'pa', 'ma', 'see', 'she', 'so',
                'the', 'to', 'up', 'us', 'was', 'we', 'who', 'will', 'with', 'you', 'inc', 'corp', 'co', 'ltd',
                'care', 'home', 'camp', 'hope', 'new', 'one', 'all', 'way', 'path', 'life', 'ice', 'aid', 'ace',
            ));
        }
        $out = array();
        foreach ((array) $names as $name) {
            if (isset($words[kop_proper_names_key($name)])) $out[] = $name;
        }
        return $out;
    }
}

// ---------------------------------------------------------------------------
// The browser copy: js/proper-names.js on every page, with the list.

if (function_exists('add_action')) {
    // Priority 1, like kop-url-labels: in place before the scripts that recase.
    add_action('wp_enqueue_scripts', 'kop_proper_names_enqueue', 1);
    add_action('admin_enqueue_scripts', 'kop_proper_names_enqueue', 1);
}

if (!function_exists('kop_proper_names_enqueue')) {
    function kop_proper_names_enqueue() {
        $path = get_stylesheet_directory() . '/js/proper-names.js';
        if (!file_exists($path)) return;
        wp_enqueue_script('kop-proper-names', get_stylesheet_directory_uri() . '/js/proper-names.js', array(), filemtime($path), false);
        wp_add_inline_script('kop-proper-names', 'window.KOP_PROPER_NAMES = ' . wp_json_encode(kop_proper_names()) . ';', 'before');
    }
}

// ---------------------------------------------------------------------------
// KOP Tools > Proper Names.

if (!defined('KOP_PROPER_NAMES_PAGE')) {
    define('KOP_PROPER_NAMES_PAGE', 'kop-proper-names');
}

if (function_exists('add_action')) {
    add_action('admin_menu', function () {
        if (!function_exists('kop_tools_parent_slug')) return;
        add_submenu_page(kop_tools_parent_slug(), 'Proper Names', 'Proper Names', 'manage_options',
            KOP_PROPER_NAMES_PAGE, 'kop_proper_names_page');
    }, 21);
}

if (!function_exists('kop_proper_names_save')) {
    /** Saves a list, keeping the one before it for Undo. Returns the saved list. */
    function kop_proper_names_save($names) {
        $before = kop_proper_names();
        $after = kop_proper_names_clean($names);
        update_option('kop_proper_names_previous', $before, false);
        update_option(KOP_PROPER_NAMES_OPTION, $after, false);
        kop_proper_names(true);
        return $after;
    }
}

if (!function_exists('kop_proper_names_page')) {
    function kop_proper_names_page() {
        if (!current_user_can('manage_options')) wp_die('Not allowed.', 403);
        $notice = '';
        $try = '';
        $draft = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('kop_proper_names');
            $do = sanitize_key($_POST['kop_do'] ?? '');
            $lines = preg_split('/\r\n|\r|\n/', wp_unslash((string) ($_POST['names'] ?? '')));
            if ($do === 'save') {
                $before = kop_proper_names();
                $after = kop_proper_names_save($lines);
                $added = array_diff($after, $before);
                $removed = array_diff($before, $after);
                $notice = 'Saved. ' . count($after) . ' names'
                    . ($added ? '; added ' . implode(', ', $added) : '')
                    . ($removed ? '; removed ' . implode(', ', $removed) : '') . '.';
            } elseif ($do === 'undo') {
                $prev = get_option('kop_proper_names_previous', null);
                if (is_array($prev)) {
                    kop_proper_names_save($prev);
                    $notice = 'The list is back as it was before the last save.';
                }
            } elseif ($do === 'try') {
                // Try a phrase against the list in the box, saved or not.
                $draft = kop_proper_names_clean($lines);
                $try = wp_unslash((string) ($_POST['try'] ?? ''));
            }
        }
        $names = $draft !== null ? $draft : kop_proper_names();
        $common = kop_proper_names_common_words($names);
        $has_prev = is_array(get_option('kop_proper_names_previous', null));

        echo '<div class="wrap kop-proper-names">';
        echo '<h1>Proper Names</h1>';
        echo '<p style="max-width:760px">Names and acronyms that are always capitalised exactly as written here, on every page. '
            . 'Wherever the site changes the capitals of a name or title (facility names on the state report pages, '
            . 'document titles, news tags, facility types, the directory), a word on this list is put back the way it is '
            . 'written below. Whole words only, in any capitals: <code>natsap</code> and <code>Natsap</code> both become '
            . '<code>NATSAP</code>. Text someone typed into a record or a post is left as it was typed.</p>';
        if ($notice !== '') echo '<div class="notice notice-success"><p>' . esc_html($notice) . '</p></div>';
        if ($common) {
            echo '<div class="notice notice-warning"><p>' . esc_html(implode(', ', $common))
                . (count($common) === 1 ? ' is' : ' are') . ' also an ordinary word, so every one in a recased title or name will be written that way too.</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('kop_proper_names');
        echo '<p><label for="kop-pn-names"><strong>One name per line</strong> (' . count($names) . ')</label><br>';
        echo '<textarea id="kop-pn-names" name="names" rows="24" style="width:100%;max-width:420px;font-family:monospace">'
            . esc_textarea(implode("\n", $names)) . '</textarea></p>';
        echo '<p><label for="kop-pn-try"><strong>Try a phrase</strong> against the list in the box (saved or not)</label><br>';
        echo '<input type="text" id="kop-pn-try" name="try" value="' . esc_attr($try) . '" style="width:100%;max-width:420px" placeholder="e.g. natsap member redcliff ascent llc"></p>';
        if ($try !== '') {
            $plain = function_exists('kop_title_case') ? kop_title_case(mb_strtolower($try)) : $try;
            echo '<p>Title case: <strong>' . esc_html(kop_proper_names_apply($plain, $names)) . '</strong><br>'
                . 'As typed: <strong>' . esc_html(kop_proper_names_apply($try, $names)) . '</strong></p>';
        }
        echo '<p><button type="submit" name="kop_do" value="save" class="button button-primary">Save list</button> ';
        echo '<button type="submit" name="kop_do" value="try" class="button">Try it</button> ';
        if ($has_prev) echo '<button type="submit" name="kop_do" value="undo" class="button">Undo last save</button>';
        echo '</p></form></div>';
    }
}
