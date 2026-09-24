<?php
/**
 * The glossary build in PHP: glossary.md in, the glossary.json data out.
 *
 * A port of scripts/build-glossary.js, so the admin editor
 * (inc/glossary-editor.php) can rebuild the glossary on the server, where
 * there is no Node. The two must agree: scripts/test-glossary-build.php
 * builds js/data/glossary/glossary.md here and compares the result with the
 * committed glossary.json, which build-glossary.js wrote. Change one, change
 * the other.
 *
 * Entry points:
 *   kop_glossary_build($markdown, $keep_source = false)
 *       array('data' => the glossary.json structure or null, 'errors' => list)
 *       With $keep_source every entry also carries 'source' (its markdown
 *       paragraph) and 'container' (the section/group titles it sits under),
 *       for the editor; they are not part of glossary.json.
 *   kop_glossary_paragraphs($markdown)   the paragraphs the parser reads
 *   kop_glossary_parse_entry($para)      one entry paragraph, or null
 */

if (!defined('ABSPATH')) {
    exit;
}

/* Program names that contain a comma, so the tag list cannot be split on
 * every comma. */
function kop_glossary_comma_names() {
    return array('Straight, Inc.');
}

/* Program names that end in parentheses of their own. */
function kop_glossary_paren_names() {
    return array('Bloom (Adult & Teen Challenge)', 'Kids Helping Kids (KHK)');
}

/** JS String.prototype.trim(): Unicode whitespace and the BOM too. */
function kop_glossary_trim($text) {
    $ws = '[\s\x{00A0}\x{1680}\x{2000}-\x{200A}\x{2028}\x{2029}\x{202F}\x{205F}\x{3000}\x{FEFF}]';
    return preg_replace('/^' . $ws . '+|' . $ws . '+$/u', '', (string) $text);
}

function kop_glossary_lower($text) {
    return function_exists('mb_strtolower') ? mb_strtolower((string) $text, 'UTF-8') : strtolower((string) $text);
}

/** NFD with the combining marks (U+0300-U+036F) dropped: "Élan" -> "Elan". */
function kop_glossary_strip_marks($text) {
    $text = (string) $text;
    if (class_exists('Normalizer')) {
        $nfd = Normalizer::normalize($text, Normalizer::FORM_D);
        if ($nfd !== false) {
            return preg_replace('/[\x{0300}-\x{036F}]/u', '', $nfd);
        }
    }
    /* No intl: the Latin letters the file could plausibly hold. */
    static $map = null;
    if ($map === null) {
        $map = array();
        $groups = array(
            'A' => 'ÀÁÂÃÄÅĀĂĄ', 'a' => 'àáâãäåāăą', 'C' => 'ÇĆĈĊČ', 'c' => 'çćĉċč',
            'D' => 'Ď', 'd' => 'ď', 'E' => 'ÈÉÊËĒĔĖĘĚ', 'e' => 'èéêëēĕėęě',
            'G' => 'ĜĞĠĢ', 'g' => 'ĝğġģ', 'H' => 'Ĥ', 'h' => 'ĥ', 'I' => 'ÌÍÎÏĨĪĬĮİ',
            'i' => 'ìíîïĩīĭį', 'J' => 'Ĵ', 'j' => 'ĵ', 'K' => 'Ķ', 'k' => 'ķ',
            'L' => 'ĹĻĽ', 'l' => 'ĺļľ', 'N' => 'ÑŃŅŇ', 'n' => 'ñńņň', 'O' => 'ÒÓÔÕÖŌŎŐ',
            'o' => 'òóôõöōŏő', 'R' => 'ŔŖŘ', 'r' => 'ŕŗř', 'S' => 'ŚŜŞŠ', 's' => 'śŝşš',
            'T' => 'ŢŤ', 't' => 'ţť', 'U' => 'ÙÚÛÜŨŪŬŮŰŲ', 'u' => 'ùúûüũūŭůűų',
            'W' => 'Ŵ', 'w' => 'ŵ', 'Y' => 'ÝŶŸ', 'y' => 'ýÿŷ', 'Z' => 'ŹŻŽ', 'z' => 'źżž',
        );
        foreach ($groups as $base => $chars) {
            foreach (preg_split('//u', $chars, -1, PREG_SPLIT_NO_EMPTY) as $ch) {
                $map[$ch] = $base;
            }
        }
    }
    return strtr($text, $map);
}

function kop_glossary_slugify($text) {
    $s = kop_glossary_lower(kop_glossary_strip_marks($text));
    $s = str_replace(array('&', '+'), array(' and ', ' plus '), $s);
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s, '-');
}

/** Sort key: case, punctuation and a leading "The" do not count. */
function kop_glossary_sort_key($term) {
    $s = kop_glossary_lower(kop_glossary_strip_marks($term));
    $s = preg_replace('/^the\s+/', '', $s);
    return preg_replace('/[^a-z0-9 ]/', '', $s);
}

/** Split a comma list, ignoring commas inside parentheses. */
function kop_glossary_split_list($text) {
    $names = kop_glossary_comma_names();
    $masked = (string) $text;
    foreach ($names as $i => $name) {
        $masked = str_replace($name, "\0" . $i . "\0", $masked);
    }
    $parts = array();
    $depth = 0;
    $current = '';
    $len = strlen($masked);
    for ($k = 0; $k < $len; $k++) {
        $ch = $masked[$k];
        if ($ch === '(') {
            $depth++;
        }
        if ($ch === ')') {
            $depth--;
        }
        if ($ch === ',' && $depth === 0) {
            $parts[] = $current;
            $current = '';
        } else {
            $current .= $ch;
        }
    }
    $parts[] = $current;
    $out = array();
    foreach ($parts as $p) {
        $p = kop_glossary_trim(preg_replace_callback('/\0(\d+)\0/', function ($m) use ($names) {
            return $names[(int) $m[1]];
        }, $p));
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/**
 * 'Spring Ridge Academy (also as "vicinity visit")' -> program + note. Any
 * trailing parenthetical is a note on how that program used the term.
 */
function kop_glossary_parse_tag($text) {
    $name = kop_glossary_trim($text);
    $rest = '';
    $own = null;
    foreach (kop_glossary_paren_names() as $n) {
        if (strpos($name, $n) === 0) {
            $own = $n;
            break;
        }
    }
    if ($own !== null) {
        $rest = kop_glossary_trim(substr($name, strlen($own)));
        $name = $own;
    } elseif (preg_match('/^(.*?)\s*(\(.*\))$/uD', $name, $m)) {
        $name = $m[1];
        $rest = $m[2];
    }
    $tag = array('program' => kop_glossary_trim($name));
    $note = kop_glossary_trim(preg_replace('/^\(|\)$/D', '', $rest));
    if ($note !== '') {
        $tag['note'] = $note;
    }
    return $tag;
}

/**
 * One entry paragraph: term, note, aka, text, used, reported. null when the
 * paragraph is not an entry. With $raw the parts are also returned as they
 * are written (head, text_raw, tags_raw) for the editor.
 */
function kop_glossary_parse_entry($para, $raw = false) {
    if (!preg_match('/^\*\*(.+?)\*\*(?: \*\(([^)]*(?:\([^)]*\)[^)]*)*)\)\*)?:\s*/u', $para, $head)) {
        return null;
    }
    $term = kop_glossary_trim($head[1]);
    $note = isset($head[2]) ? kop_glossary_trim($head[2]) : '';
    $aka = array();
    if (preg_match('/^aka /i', $note)) {
        $list = substr($note, 4);
        if (preg_match_all('/"[^"]+"/u', $list, $quoted) && $quoted[0]) {
            foreach ($quoted[0] as $q) {
                $aka[] = preg_replace('/,$/D', '', substr($q, 1, -1));
            }
        } else {
            $aka = kop_glossary_split_list($list);
        }
        $note = '';
    }
    $text = substr($para, strlen($head[0]));
    $used = array();
    $reported = array();
    $tags_raw = '';
    if (preg_match('/\s*(?:Used at: \*([^*]+)\*)?(?:;?\s*[Rr]eportedly used at: \*([^*]+)\*)?\s*$/uD', $text, $tags, PREG_OFFSET_CAPTURE)
        && ((isset($tags[1]) && $tags[1][0] !== '') || (isset($tags[2]) && $tags[2][0] !== ''))) {
        if (isset($tags[1]) && $tags[1][0] !== '') {
            $used = array_map('kop_glossary_parse_tag', kop_glossary_split_list($tags[1][0]));
        }
        if (isset($tags[2]) && $tags[2][0] !== '') {
            $reported = array_map('kop_glossary_parse_tag', kop_glossary_split_list($tags[2][0]));
        }
        $tags_raw = substr($text, $tags[0][1]);
        $text = kop_glossary_trim(substr($text, 0, $tags[0][1]));
    }
    $text_raw = kop_glossary_trim($text);
    /* Dictionary-style lower-case starts are capitalised on the page. */
    $text = preg_replace_callback('/^[a-z]/', function ($m) {
        return strtoupper($m[0]);
    }, $text_raw);
    $entry = array(
        'term'     => $term,
        'note'     => $note,
        'aka'      => $aka,
        'text'     => $text,
        'used'     => $used,
        'reported' => $reported,
    );
    if ($raw) {
        $entry['head'] = $head[0];
        $entry['paren'] = isset($head[2]) ? $head[2] : '';
        $entry['text_raw'] = $text_raw;
        $entry['tags_raw'] = $tags_raw;
    }
    return $entry;
}

/** The paragraphs of the source, as the parser splits them. */
function kop_glossary_paragraphs($markdown) {
    $paras = preg_split('/\n{2,}/', str_replace("\r\n", "\n", (string) $markdown));
    $out = array();
    foreach ($paras as $p) {
        $p = kop_glossary_trim($p);
        if ($p !== '') {
            $out[] = $p;
        }
    }
    return $out;
}

/** Heading level (1-4) of a paragraph, or 0. */
function kop_glossary_heading_level($para) {
    if (preg_match('/^(#{1,4}) (.+)$/uD', $para, $m)) {
        return strlen($m[1]);
    }
    return 0;
}

/**
 * The parse: a tree of stdClass nodes, so entries can be updated in place
 * while they are also listed flat. Mirrors parse() in build-glossary.js.
 */
function kop_glossary_parse($markdown, $keep_source) {
    $doc = (object) array('title' => '', 'updated' => '', 'intro' => array(), 'sections' => array());
    $section = null;
    $group = null;
    $target = null;
    $path = array();

    foreach (kop_glossary_paragraphs($markdown) as $para) {
        if (preg_match('/^# (.+)$/uD', $para, $m)) {
            $doc->title = kop_glossary_trim($m[1]);
            continue;
        }
        if (preg_match('/^updated: (\d{4}-\d{2}-\d{2})$/D', $para, $m)) {
            $doc->updated = $m[1];
            continue;
        }
        if (preg_match('/^## (.+)$/uD', $para, $m)) {
            $title = kop_glossary_trim($m[1]);
            $section = (object) array('title' => $title, 'id' => kop_glossary_slugify($m[1]), 'notes' => array(), 'entries' => array(), 'groups' => array());
            $doc->sections[] = $section;
            $group = null;
            $target = $section;
            $path = array($title);
            continue;
        }
        if (preg_match('/^(###|####) (.+)$/uD', $para, $m)) {
            if (!$section) {
                throw new RuntimeException('Group before any section: ' . $para);
            }
            $title = kop_glossary_trim($m[2]);
            $node = (object) array('title' => $title, 'id' => kop_glossary_slugify($m[2]), 'sources' => '', 'notes' => array(), 'entries' => array(), 'groups' => array());
            if ($m[1] === '###') {
                $section->groups[] = $node;
                $group = $node;
                $path = array($section->title, $title);
            } else {
                if (!$group) {
                    throw new RuntimeException('#### without a ### above it: ' . $para);
                }
                $group->groups[] = $node;
                $path = array($section->title, $group->title, $title);
            }
            $target = $node;
            continue;
        }
        if (!$section) {
            $doc->intro[] = $para;
            continue;
        }
        if ($target !== $section && preg_match('/^\*Sources?: (.+)\*$/uD', $para, $m)) {
            $target->sources = kop_glossary_trim($m[1]);
            continue;
        }
        $entry = kop_glossary_parse_entry($para);
        if ($entry) {
            $entry = (object) $entry;
            if ($keep_source) {
                $entry->source = $para;
                $entry->container = $path;
            }
            $target->entries[] = $entry;
        } else {
            $target->notes[] = $para;
        }
    }
    return $doc;
}

/** Every entry in reading order, with the title of the group it sits in. */
function kop_glossary_build_entries($doc) {
    $out = array();
    $walk = function ($node, $group_title) use (&$walk, &$out) {
        foreach ($node->entries as $e) {
            $out[] = array($e, $group_title);
        }
        foreach ($node->groups as $g) {
            $walk($g, $g->title);
        }
    };
    foreach ($doc->sections as $s) {
        $walk($s, '');
    }
    return $out;
}

function kop_glossary_build($markdown, $keep_source = false) {
    try {
        $doc = kop_glossary_parse($markdown, $keep_source);
    } catch (RuntimeException $e) {
        return array('data' => null, 'errors' => array($e->getMessage()));
    }
    $errors = array();
    $entries = kop_glossary_build_entries($doc);

    /* Ids: the term, plus the qualifier when two entries share a term. */
    $counts = array();
    foreach ($entries as $pair) {
        $k = kop_glossary_slugify($pair[0]->term);
        $counts[$k] = (isset($counts[$k]) ? $counts[$k] : 0) + 1;
    }
    $used = array();
    foreach ($entries as $pair) {
        list($entry, $group_title) = $pair;
        $id = kop_glossary_slugify($entry->term);
        if ($counts[$id] > 1) {
            $id = kop_glossary_slugify($entry->term . ' ' . ($entry->note !== '' ? $entry->note : $group_title));
        }
        if ($id === '' || isset($used[$id])) {
            $errors[] = 'Duplicate or empty id "' . $id . '" for "' . $entry->term . '"';
        }
        $used[$id] = true;
        $entry->id = $id;
    }

    /* What a **cross-reference** may be called. */
    $index = array();
    $add = function ($name, $entry) use (&$index) {
        $k = kop_glossary_trim(kop_glossary_lower($name));
        if ($k === '') {
            return;
        }
        if (!isset($index[$k])) {
            $index[$k] = array();
        }
        if (!in_array($entry, $index[$k], true)) {
            $index[$k][] = $entry;
        }
    };
    foreach ($entries as $pair) {
        $entry = $pair[0];
        $bare = preg_replace('/\s*\([^)]*\)\s*$/uD', '', $entry->term);
        $add($entry->term, $entry);
        $add($bare, $entry);
        foreach (preg_split('/\s*\/\s*/u', $entry->term) as $part) {
            $add($part, $entry);
        }
        foreach ($entry->aka as $a) {
            $add($a, $entry);
        }
        if ($entry->note !== '') {
            $add($entry->term . ' (' . $entry->note . ')', $entry);
        }
    }
    $resolve = function ($name) use (&$index) {
        $k = kop_glossary_trim(kop_glossary_lower($name));
        $plain = preg_replace('/s$/D', '', $k);
        $hits = isset($index[$k]) ? $index[$k] : (isset($index[$plain]) ? $index[$plain] : array());
        /* A term's own name beats an entry that only lists it as an aka. */
        $exact = array_values(array_filter($hits, function ($e) use ($k) {
            return kop_glossary_lower(preg_replace('/\s*\([^)]*\)\s*$/uD', '', $e->term)) === $k;
        }));
        return count($exact) === 1 ? $exact : $hits;
    };

    /* Check every reference and record the id it points at. */
    $refs = array();
    $ref_check = function ($text, $where) use (&$refs, &$errors, $resolve) {
        preg_match_all('/\*\*(.+?)\*\*/u', $text, $all, PREG_SET_ORDER);
        foreach ($all as $m) {
            $hits = $resolve($m[1]);
            if (!$hits) {
                $errors[] = $where . ': **' . $m[1] . '** does not name an entry';
                continue;
            }
            if (count($hits) > 1) {
                $errors[] = $where . ': **' . $m[1] . '** is ambiguous (' . implode(', ', array_map(function ($h) {
                    return $h->id;
                }, $hits)) . '); write it as "Term (qualifier)"';
            }
            $refs[kop_glossary_lower($m[1])] = $hits[0]->id;
        }
    };
    foreach ($entries as $pair) {
        $ref_check($pair[0]->text, $pair[0]->term);
    }
    $walk_notes = function ($node) use (&$walk_notes, $ref_check) {
        foreach ($node->notes as $n) {
            $ref_check($n, $node->title);
        }
        foreach ($node->groups as $g) {
            $walk_notes($g);
        }
    };
    foreach ($doc->sections as $s) {
        $walk_notes($s);
    }

    /* Entries sort alphabetically inside every section and group. */
    $sort_node = function ($node) use (&$sort_node) {
        usort($node->entries, function ($a, $b) {
            return strcmp(kop_glossary_sort_key($a->term), kop_glossary_sort_key($b->term)) ?: strcmp($a->id, $b->id);
        });
        foreach ($node->groups as $g) {
            $sort_node($g);
        }
    };
    foreach ($doc->sections as $s) {
        $sort_node($s);
    }

    /* Programs: every name a tag carries, with how many entries name it. */
    $programs = array();
    foreach ($entries as $pair) {
        $entry = $pair[0];
        $tags = array_merge($entry->used, $entry->reported);
        $names = array();
        foreach ($tags as $t) {
            $names[$t['program']] = true;
        }
        foreach (array_keys($names) as $name) {
            $name = (string) $name;
            $slug = kop_glossary_slugify($name);
            if (!isset($programs[$slug])) {
                $programs[$slug] = array('slug' => $slug, 'name' => $name, 'count' => 0);
            }
            $programs[$slug]['count']++;
        }
        foreach ($entry->used as $i => $t) {
            $entry->used[$i]['slug'] = kop_glossary_slugify($t['program']);
        }
        foreach ($entry->reported as $i => $t) {
            $entry->reported[$i]['slug'] = kop_glossary_slugify($t['program']);
        }
    }

    if ($errors) {
        return array('data' => null, 'errors' => $errors);
    }

    $programs = array_values($programs);
    usort($programs, function ($a, $b) {
        return strcmp(kop_glossary_sort_key($a['name']), kop_glossary_sort_key($b['name']));
    });
    $out = array(
        'title'    => $doc->title,
        'updated'  => $doc->updated,
        'intro'    => $doc->intro,
        'count'    => count($entries),
        'programs' => $programs,
        'refs'     => $refs,
        'sections' => $doc->sections,
    );
    /* Objects to plain arrays, as json_decode(..., true) of glossary.json. */
    $data = json_decode(json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), true);
    if (!is_array($data)) {
        return array('data' => null, 'errors' => array('The glossary could not be encoded: ' . json_last_error_msg()));
    }
    if (empty($data['refs'])) {
        $data['refs'] = array();
    }
    return array('data' => $data, 'errors' => array());
}
