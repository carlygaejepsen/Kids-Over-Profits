<?php
/**
 * Page text: pages whose words live in a small JSON file in the repo
 * (js/data/pages/<slug>.json) instead of in their template, so they can be
 * edited from wp-admin (KOP Data Tools > Page Text, inc/page-text-editor.php)
 * without touching code.
 *
 * A page is a list of sections. Each has a key, a label for the editor, a
 * style (how the template wraps it), an optional heading and a body written
 * in a very small format:
 *
 *   a blank line between paragraphs
 *   ### Subheading
 *   - list item
 *   > a pull quote, set large (inc/pull-quotes.php); "> -- Name" credits it
 *   **bold**, *italic*, [link text](https://...)
 *   1-800-555-0100 (any 1-xxx-xxx-xxxx number) becomes a tap-to-call link
 *
 * In a "cards" section, a list item that starts with a link or a **bold
 * name** followed by a colon becomes an organization card.
 *
 * Citations follow the site's articles: [^3] in the text prints a small
 * superscript 3 linking to the third list item of the page's "sources"
 * section ([^3][^4] prints "3, 4").
 *
 * The body never carries HTML: every character is escaped and only the
 * syntax above becomes markup, so an edit cannot inject anything, and the
 * host firewall (which rejects form posts containing HTML) never sees any.
 * Links may only be http(s), mailto, tel, #anchor or /site-path.
 *
 * Saved edits sit in the kop_page_text_edits option, one per section, over
 * the deployed JSON, and are live at once. An edit that matches the repo
 * copy (after the downloaded file is committed) clears itself.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Pages this system knows, by page slug. */
function kop_page_text_pages() {
    $pages = array(
        'indian-boarding-schools' => array(
            'title'  => 'Indian Boarding Schools and Residential Schools',
            'file'   => 'js/data/pages/indian-boarding-schools.json',
            'prefix' => 'kop-ibs',
            'css'    => 'css/indian-boarding-schools.css',
        ),
        'faq' => array(
            'title'  => 'Frequently Asked Questions',
            'file'   => 'js/data/pages/faq.json',
            'prefix' => 'kop-faq',
            'css'    => 'css/faq.css',
        ),
    );
    // Industries outside the TTI connected to it, all on templates/page-related-industry.php.
    $related = array(
        'related-industries'     => 'Related Industries',
        'foster-care'            => 'Foster Care',
        'disability-group-homes' => 'Group Homes for Adults with Developmental Disabilities',
        'nursing-homes'          => 'Nursing Homes',
        'psychiatric-hospitals'  => 'Psychiatric Hospitals and Psych Wards',
    );
    foreach ($related as $slug => $title) {
        $pages[$slug] = array(
            'title'  => $title,
            'file'   => 'js/data/pages/' . $slug . '.json',
            'prefix' => 'kop-ri',
            'css'    => 'css/related-industries.css',
        );
    }
    return $pages;
}

function kop_page_text_page($slug) {
    $pages = kop_page_text_pages();
    return isset($pages[$slug]) ? $pages[$slug] : null;
}

/** The deployed sections of a page (from the repo JSON), or array(). */
function kop_page_text_defaults($slug) {
    static $cache = array();
    if (isset($cache[$slug])) {
        return $cache[$slug];
    }
    $page = kop_page_text_page($slug);
    $sections = array();
    if ($page) {
        $path = get_stylesheet_directory() . '/' . $page['file'];
        $data = is_readable($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (is_array($data) && isset($data['sections']) && is_array($data['sections'])) {
            foreach ($data['sections'] as $s) {
                if (!is_array($s) || empty($s['key'])) {
                    continue;
                }
                $sections[] = array(
                    'key'     => preg_replace('/[^a-z0-9-]/', '', strtolower((string) $s['key'])),
                    'label'   => isset($s['label']) ? (string) $s['label'] : (string) $s['key'],
                    'style'   => isset($s['style']) ? (string) $s['style'] : 'plain',
                    'heading' => isset($s['heading']) ? (string) $s['heading'] : '',
                    'body'    => kop_page_text_clean(isset($s['body']) ? (string) $s['body'] : ''),
                );
            }
        }
    }
    $cache[$slug] = $sections;
    return $sections;
}

/** Line endings to \n, trailing space and control characters off. */
function kop_page_text_clean($text) {
    $text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
    $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $text);
    $text = preg_replace('/[ \t]+$/m', '', $text);
    return trim($text, "\n");
}

/** Saved edits: array(slug => array(key => array(heading, body, user, time))). */
function kop_page_text_edits() {
    $edits = get_option('kop_page_text_edits');
    return is_array($edits) ? $edits : array();
}

function kop_page_text_save_edits($edits) {
    update_option('kop_page_text_edits', $edits, false);
}

/**
 * Saved edits a later repo version was written from, by md5 of the edit's
 * body: slug => key => md5s. Such an edit no longer covers the repo text;
 * an edit made after it (another body) still does.
 */
function kop_page_text_superseded() {
    return array(
        // The owner's 2026-10-11 wording ("not typically part of", "often connected"),
        // carried into the rewrite that leaves room for RTCs on hospital campuses.
        'psychiatric-hospitals' => array('outside' => array('f2851c1c5f32e98d9f298e60d43daece')),
    );
}

/** The page's sections with saved edits applied; each carries 'edit' (or null). */
function kop_page_text_sections($slug) {
    $edits = kop_page_text_edits();
    $mine  = isset($edits[$slug]) && is_array($edits[$slug]) ? $edits[$slug] : array();
    $old   = kop_page_text_superseded();
    $out = array();
    foreach (kop_page_text_defaults($slug) as $s) {
        $edit = isset($mine[$s['key']]) && is_array($mine[$s['key']]) ? $mine[$s['key']] : null;
        if ($edit && isset($old[$slug][$s['key']], $edit['body'])
            && in_array(md5(kop_page_text_clean($edit['body'])), $old[$slug][$s['key']], true)) {
            $edit = null;
        }
        if ($edit) {
            $s['heading'] = isset($edit['heading']) ? (string) $edit['heading'] : $s['heading'];
            $s['body']    = isset($edit['body']) ? kop_page_text_clean($edit['body']) : $s['body'];
        }
        $s['edit'] = $edit;
        $out[] = $s;
    }
    return $out;
}

/* ---- Rendering ---------------------------------------------------------- */

function kop_page_text_esc($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** A link target this format allows, made absolute, or null. */
function kop_page_text_url($url) {
    $url = trim((string) $url);
    if (preg_match('#^https?://[^\s<>"\']+$#i', $url)
        || preg_match('#^mailto:[^\s<>"\']+$#i', $url)
        || preg_match('#^tel:\+?[0-9-]+$#i', $url)
        || preg_match('#^\#[A-Za-z][\w-]*$#', $url)) {
        return $url;
    }
    if (preg_match('#^/(?!/)[^\s<>"\']*$#', $url)) {
        return function_exists('home_url') ? home_url($url) : $url;
    }
    return null;
}

/** **bold** and *italic* on text that is already escaped. */
function kop_page_text_emphasis($html) {
    $html = preg_replace('/\*\*(?=\S)(.+?)(?<=\S)\*\*/u', '<strong>$1</strong>', $html);
    return preg_replace('/(?<![\*\w])\*(?=\S)(.+?)(?<=\S)\*(?![\*\w])/u', '<em>$1</em>', $html);
}

/** One line of the format as HTML. */
function kop_page_text_inline($text, $prefix) {
    $tokens = array();
    $keep = function ($html) use (&$tokens) {
        $tokens[] = $html;
        return "\x01" . (count($tokens) - 1) . "\x02";
    };
    $text = str_replace(array("\x01", "\x02"), '', (string) $text);

    // [^3] (or a run like [^3][^4]) cites source 3 of the page's "sources" section,
    // printed like the site's articles: a small superscript number linking to it.
    $text = preg_replace_callback('/(?:\[\^\d+\])+/', function ($m) use ($keep, $prefix) {
        preg_match_all('/\d+/', $m[0], $n);
        $links = array_map(function ($i) use ($prefix) {
            return '<a href="#' . $prefix . '-src-' . (int) $i . '">' . (int) $i . '</a>';
        }, $n[0]);
        return $keep('<sup class="kop-cite">' . implode(', ', $links) . '</sup>');
    }, $text);

    $text = preg_replace_callback('/\[([^\]\n]+)\]\(([^)\s]+)\)/u', function ($m) use ($keep, $prefix) {
        $inner = kop_page_text_emphasis(kop_page_text_esc($m[1]));
        $url = kop_page_text_url($m[2]);
        if ($url === null) {
            return $keep($inner);
        }
        $class = stripos($url, 'tel:') === 0 ? ' class="' . $prefix . '-phone"' : '';
        return $keep('<a' . $class . ' href="' . kop_page_text_esc($url) . '">' . $inner . '</a>');
    }, $text);

    $text = preg_replace_callback('/(?<![\d-])1-(\d{3})-(\d{3})-(\d{4})(?![\d-])/', function ($m) use ($keep, $prefix) {
        return $keep('<a class="' . $prefix . '-phone" href="tel:+1' . $m[1] . $m[2] . $m[3] . '">' . $m[0] . '</a>');
    }, $text);

    $html = kop_page_text_emphasis(kop_page_text_esc($text));
    return preg_replace_callback("/\x01(\d+)\x02/", function ($m) use ($tokens) {
        return $tokens[(int) $m[1]];
    }, $html);
}

/** A card list item, or null when the item is not "name: description". */
function kop_page_text_card($item, $prefix) {
    if (!preg_match('/^(?:\[([^\]\n]+)\]\(([^)\s]+)\)|\*\*([^*\n]+)\*\*)\s*:\s*(.*)$/su', $item, $m)) {
        return null;
    }
    $url = $m[1] !== '' ? kop_page_text_url($m[2]) : null;
    $name = $m[1] !== '' ? $m[1] : $m[3];
    $name_html = $url !== null
        ? '<a class="' . $prefix . '-org-name" href="' . kop_page_text_esc($url) . '">' . kop_page_text_esc($name) . '</a>'
        : '<span class="' . $prefix . '-org-name">' . kop_page_text_esc($name) . '</span>';
    $desc = trim($m[4]);
    return '<li class="' . $prefix . '-org">' . $name_html . ($desc !== '' ? '<p>' . kop_page_text_inline($desc, $prefix) . '</p>' : '') . '</li>';
}

/** A section body as HTML. */
function kop_page_text_body_html($body, $style, $prefix) {
    $blocks = array();
    $para = array();
    $list = null;
    $quote = null;
    $flush = function () use (&$blocks, &$para, &$list, &$quote) {
        if ($para) {
            $blocks[] = array('p', implode(' ', $para));
            $para = array();
        }
        if ($list !== null) {
            $blocks[] = array('ul', $list);
            $list = null;
        }
        if ($quote !== null) {
            $blocks[] = array('quote', $quote);
            $quote = null;
        }
    };
    foreach (explode("\n", kop_page_text_clean($body)) as $line) {
        $t = trim($line);
        $is_quote = $t === '>' || strpos($t, '> ') === 0;
        if ($quote !== null && !$is_quote) {
            // The quote ends at the first line without "> ".
            $flush();
        }
        if ($is_quote) {
            // A pull quote (inc/pull-quotes.php): "> " lines, "> -- Name" credits it.
            if ($quote === null) {
                $flush();
                $quote = array('lines' => array(''), 'credit' => '');
            }
            $q = trim(substr($t, 1));
            if (preg_match('/^(?:--|\x{2014})\s*(.+)$/u', $q, $c)) {
                $quote['credit'] = $c[1];
            } elseif ($q === '') {
                $quote['lines'][] = '';
            } else {
                $last = count($quote['lines']) - 1;
                $quote['lines'][$last] = trim($quote['lines'][$last] . ' ' . $q);
            }
        } elseif ($t === '') {
            $flush();
        } elseif (strpos($t, '### ') === 0) {
            $flush();
            $blocks[] = array('h3', trim(substr($t, 4)));
        } elseif (strpos($t, '- ') === 0) {
            if ($para) {
                $flush();
            }
            if ($list === null) {
                $list = array();
            }
            $list[] = trim(substr($t, 2));
        } elseif ($list !== null) {
            $list[count($list) - 1] .= ' ' . $t;
        } else {
            $para[] = $t;
        }
    }
    $flush();

    $html = '';
    foreach ($blocks as $b) {
        if ($b[0] === 'h3') {
            $html .= '<h3>' . kop_page_text_inline($b[1], $prefix) . "</h3>\n";
        } elseif ($b[0] === 'p') {
            $html .= '<p>' . kop_page_text_inline($b[1], $prefix) . "</p>\n";
        } elseif ($b[0] === 'quote') {
            $lines = array_filter($b[1]['lines'], 'strlen');
            if ($lines && function_exists('kop_pull_quote')) {
                $html .= kop_pull_quote(implode("\n\n", array_map(function ($l) use ($prefix) {
                    return kop_page_text_inline($l, $prefix);
                }, $lines)), array('credit' => kop_page_text_inline($b[1]['credit'], $prefix))) . "\n";
            }
        } elseif ($style === 'cards') {
            $html .= '<ul class="' . $prefix . '-orgs">';
            foreach ($b[1] as $item) {
                $card = kop_page_text_card($item, $prefix);
                $html .= $card !== null ? $card : '<li class="' . $prefix . '-org"><p>' . kop_page_text_inline($item, $prefix) . '</p></li>';
            }
            $html .= "</ul>\n";
        } elseif ($style === 'sources') {
            // Numbered in order; [^N] in the text links to the Nth item.
            $html .= '<ol class="' . $prefix . '-source-list">';
            foreach ($b[1] as $i => $item) {
                $html .= '<li id="' . $prefix . '-src-' . ($i + 1) . '">' . kop_page_text_inline($item, $prefix) . '</li>';
            }
            $html .= "</ol>\n";
        } else {
            $html .= '<ul>';
            foreach ($b[1] as $item) {
                $html .= '<li>' . kop_page_text_inline($item, $prefix) . '</li>';
            }
            $html .= "</ul>\n";
        }
    }
    return $html;
}

/** One section, wrapped for its style. */
function kop_page_text_section_html($section, $prefix, $slug = '') {
    $id = $prefix . '-' . $section['key'];
    // An admin's pencil (inc/inline-edit.php); empty for everyone else.
    $edit = $slug !== '' && function_exists('kop_ie_attr') ? kop_ie_attr('pt:' . $slug . ':' . $section['key'], $section['label']) : '';
    $heading = trim((string) $section['heading']);
    $h2 = $heading !== '' ? '<h2 id="' . $id . '-title">' . kop_page_text_inline($heading, $prefix) . "</h2>\n" : '';
    $labelled = $heading !== '' ? ' aria-labelledby="' . $id . '-title"' : '';
    $body = kop_page_text_body_html($section['body'], $section['style'], $prefix);
    switch ($section['style']) {
        case 'note':
            return '<div class="' . $prefix . '-note" id="' . $id . '"' . $edit . ' role="note">' . $h2 . $body . "</div>\n";
        case 'updated':
            return '<div class="' . $prefix . '-updated" id="' . $id . '"' . $edit . '>' . $h2 . $body . "</div>\n";
        case 'statement':
        case 'support':
        case 'sources':
            return '<section class="' . $prefix . '-' . $section['style'] . '" id="' . $id . '"' . $edit . $labelled . ">\n" . $h2 . $body . "</section>\n";
        default:
            return '<section id="' . $id . '"' . $edit . $labelled . ">\n" . $h2 . $body . "</section>\n";
    }
}

/**
 * Print a page's sections. $opts: 'only' => keys, 'skip' => keys, so a
 * template can put its own content between two runs; 'after' => array(key
 * => callable) prints the callable's output right after that section.
 */
function kop_page_text_render($slug, $opts = array()) {
    $page = kop_page_text_page($slug);
    if (!$page) {
        return;
    }
    foreach (kop_page_text_sections($slug) as $s) {
        if (isset($opts['only']) && !in_array($s['key'], $opts['only'], true)) {
            continue;
        }
        if (isset($opts['skip']) && in_array($s['key'], $opts['skip'], true)) {
            continue;
        }
        echo kop_page_text_section_html($s, $page['prefix'], $slug);
        if (isset($opts['after'][$s['key']]) && is_callable($opts['after'][$s['key']])) {
            call_user_func($opts['after'][$s['key']]);
        }
    }
}
