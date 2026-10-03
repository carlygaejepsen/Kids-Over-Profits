<?php
/**
 * Links read as words, never as long web addresses.
 *
 * kop_url_label() turns an address into a short label: the page's own name
 * from its path, then the site in brackets ("Opinion i was a wilderness
 * therapy success (undark.org)"), a Wayback copy as "(heal-online.org,
 * archived 2023)", a Reddit wiki page as "(r/troubledteens wiki)", a file
 * in KOP's own uploads by its name. js/url-labels.js has the same rules
 * (kopUrlLabel) for lists drawn in the browser; scripts/test-url-labels.php
 * checks the two agree.
 *
 * kop_url_labels_filter_html() runs over post content (the_content, late):
 * a link whose text is an address gets the label, and a bare address in the
 * text becomes a labelled link. The full address stays in the link's title.
 * Code, pre, script, style and textarea are left alone, and so is anything
 * inside an element marked data-kop-keep-url.
 */

if (!defined('ABSPATH')) {
    exit;
}

const KOP_URL_LABEL_MAX = 70;

/** True when the visible text of a link is itself an address (also a "…"-shortened one). */
function kop_url_labels_text_is_url($text) {
    $t = trim(html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($t === '' || preg_match('/\s/u', $t)) return false;
    if (preg_match('#^(https?://|www\.)#i', $t)) return true;
    return (bool) preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+/\S*$#i', $t);
}

/** The site part of an address, without "www.". */
function kop_url_labels_host($url) {
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return preg_replace('/^www\d*\./', '', $host);
}

/** "opinion-i-was-a-wilderness-therapy-success" -> "Opinion i was a wilderness therapy success". */
function kop_url_labels_words($segment) {
    $s = rawurldecode((string) $segment);
    $s = preg_replace('/\.(html?|php|aspx?|jsp|cfm|pdf|docx?|xlsx?|pptx?|jpe?g|png|gif|txt)$/i', '', $s);
    $s = preg_replace('/[-_+.~]+/', ' ', $s);
    $words = array();
    foreach (preg_split('/\s+/', trim($s)) as $w) {
        if ($w === '') continue;
        // Ids, dates run together and hashes say nothing to a reader.
        if (preg_match('/^\d{5,}$/', $w) || (strlen($w) >= 12 && preg_match('/^[0-9a-f]+$/i', $w) && preg_match('/\d/', $w))) continue;
        $words[] = $w;
    }
    $s = implode(' ', $words);
    if (preg_match_all('/[a-z]/i', $s) < 3) return '';
    if (strtoupper($s) === $s || strtolower($s) === $s) $s = strtolower($s);
    return ucfirst($s);
}

/** Shorten to KOP_URL_LABEL_MAX characters on a word boundary. */
function kop_url_labels_trim($s) {
    if (mb_strlen($s) <= KOP_URL_LABEL_MAX) return $s;
    $cut = mb_substr($s, 0, KOP_URL_LABEL_MAX - 1);
    $space = mb_strrpos($cut, ' ');
    if ($space !== false && $space > KOP_URL_LABEL_MAX / 2) $cut = mb_substr($cut, 0, $space);
    return rtrim($cut, " ,.;:-") . '…';
}

/** The words a reader sees for an address. */
function kop_url_label($url) {
    $url = trim((string) $url);
    if ($url === '') return '';
    if (preg_match('#^www\.#i', $url)) $url = 'https://' . $url;
    elseif (!preg_match('#^[a-z][a-z0-9+.-]*:#i', $url) && preg_match('#^[a-z0-9-]+(\.[a-z0-9-]+)+(/|$)#i', $url)) $url = 'https://' . $url;
    if (!preg_match('#^https?://#i', $url)) return $url;

    $host = kop_url_labels_host($url);
    $path = (string) parse_url($url, PHP_URL_PATH);

    // A Wayback Machine copy: the original page, archived in a year.
    if (preg_match('#^(web\.)?archive\.org$#', $host) && preg_match('#^/web/(?:(\d{4})\d*[a-z_]*/)?([a-z]+:/.+|[a-z0-9-]+\.[a-z0-9.-]+.*)$#i', $path . (parse_url($url, PHP_URL_QUERY) ? '?' . parse_url($url, PHP_URL_QUERY) : ''), $m)) {
        $inner = kop_url_label(preg_match('#^[a-z]+:/{1,2}#i', $m[2]) ? preg_replace('#^([a-z]+):/(?!/)#i', '$1://', $m[2]) : 'http://' . $m[2]);
        $when = $m[1] !== '' ? 'archived ' . $m[1] : 'archived';
        if (preg_match('/^(.*) \(([^()]*)\)$/u', $inner, $p)) return $p[1] . ' (' . $p[2] . ', ' . $when . ')';
        return $inner . ' (' . $when . ')';
    }

    $segments = array_values(array_filter(explode('/', $path), 'strlen'));
    $is_pdf = (bool) preg_match('/\.pdf$/i', $path);
    // "#page=18" on a PDF: the page the link opens at.
    $pdf = $is_pdf ? 'PDF' . (preg_match('/(?:^|&)page=(\d+)/', (string) parse_url($url, PHP_URL_FRAGMENT), $pg) ? ', p. ' . $pg[1] : '') : '';

    // Reddit: a wiki page or a thread, named with its subreddit.
    if (preg_match('/(^|\.)reddit\.com$/', $host) && isset($segments[0], $segments[1]) && strtolower($segments[0]) === 'r') {
        $sub = 'r/' . $segments[1];
        if (isset($segments[2]) && strtolower($segments[2]) === 'wiki') {
            $page = kop_url_labels_words(end($segments));
            return kop_url_labels_trim(($page !== '' && strtolower(end($segments)) !== 'wiki' ? $page . ' ' : '') . '(' . $sub . ' wiki)');
        }
        if (isset($segments[2], $segments[4]) && strtolower($segments[2]) === 'comments') {
            $title = kop_url_labels_words($segments[4]);
            if ($title !== '') return kop_url_labels_trim($title . ' (' . $sub . ')');
        }
        return $sub;
    }

    // The page's own name: the last part of the path that reads as words.
    $words = '';
    for ($i = count($segments) - 1; $i >= 0 && $words === ''; $i--) {
        if (preg_match('/^(index|default|home|main)\.(html?|php|aspx?)$/i', $segments[$i])) continue;
        $words = kop_url_labels_words($segments[$i]);
    }

    $own = $host === 'kidsoverprofits.org' || (function_exists('home_url') && $host === kop_url_labels_host(home_url('/')));
    if ($own) {
        if ($words === '') return 'Kids Over Profits';
        return kop_url_labels_trim($words) . ($is_pdf ? ' (' . $pdf . ')' : '');
    }
    $where = $host . ($is_pdf ? ', ' . $pdf : '');
    if ($words === '') return $is_pdf ? $pdf . ' (' . $host . ')' : $host;
    return kop_url_labels_trim($words) . ' (' . $where . ')';
}

/** A bare address found in text, made into a labelled link. */
function kop_url_labels_link($url) {
    $href = preg_match('#^https?://#i', $url) ? $url : 'https://' . $url;
    return '<a href="' . esc_url($href) . '" title="' . esc_attr($href) . '" rel="noopener">' . esc_html(kop_url_label($href)) . '</a>';
}

/** Labels in place of addresses in a piece of HTML (see the file comment). */
function kop_url_labels_filter_html($html) {
    if (!is_string($html) || $html === '') return $html;
    if (!preg_match('#https?://|www\.|\.(com|org|net|gov|edu|us|io|tv|info)/#i', $html)) return $html;

    $parts = preg_split('/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
    $skip = array();     // open code/pre/script/... elements
    $keep_depth = 0;      // inside data-kop-keep-url
    $stack = array();     // tag names, to find where data-kop-keep-url ends
    $link = null;         // the open <a>: [index in $out pieces, href, text so far]
    $pieces = array();

    foreach ($parts as $part) {
        if ($part === '') continue;
        if ($part[0] === '<' && preg_match('#^<(/?)([a-z][a-z0-9-]*)\b#i', $part, $m)) {
            $closing = $m[1] === '/';
            $tag = strtolower($m[2]);
            $void = in_array($tag, array('br', 'img', 'hr', 'input', 'meta', 'link', 'source', 'wbr', 'area', 'col', 'embed', 'track', 'param'), true) || substr($part, -2) === '/>';
            if (in_array($tag, array('code', 'pre', 'script', 'style', 'textarea', 'svg', 'kbd', 'samp'), true)) {
                if ($closing) { if (!empty($skip[$tag])) $skip[$tag]--; }
                else $skip[$tag] = ($skip[$tag] ?? 0) + 1;
            }
            if (!$void) {
                if ($closing) {
                    $top = array_pop($stack);
                    if ($top && $top[1]) $keep_depth--;
                } else {
                    $keeps = stripos($part, 'data-kop-keep-url') !== false;
                    if ($keeps) $keep_depth++;
                    $stack[] = array($tag, $keeps);
                }
            }
            if ($tag === 'a') {
                if (!$closing) {
                    $href = preg_match('/\bhref\s*=\s*(["\'])(.*?)\1/is', $part, $hm) ? html_entity_decode($hm[2], ENT_QUOTES | ENT_HTML5, 'UTF-8') : null;
                    $pieces[] = $part;
                    $link = array('start' => count($pieces), 'href' => $href, 'open' => count($pieces) - 1);
                    continue;
                }
                if ($link !== null) {
                    $inner = implode('', array_slice($pieces, $link['start']));
                    $plain = strip_tags($inner);
                    // A link with no href (a lightbox's copy) is labelled from its own text.
                    if ($link['href'] === null && kop_url_labels_text_is_url($plain)) {
                        $link['href'] = trim(html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                        if (!preg_match('#^https?://#i', $link['href'])) $link['href'] = 'https://' . $link['href'];
                    }
                    if ($keep_depth === 0 && array_sum($skip) === 0 && preg_match('#^https?://#i', $link['href'])
                        && kop_url_labels_text_is_url($plain) && strip_tags($inner, '') === $inner) {
                        $pieces = array_slice($pieces, 0, $link['start']);
                        $pieces[] = esc_html(kop_url_label($link['href']));
                        if (stripos($pieces[$link['open']], ' title=') === false && stripos($pieces[$link['open']], ' href=') !== false) {
                            $pieces[$link['open']] = preg_replace('/^<a\b/i', '<a title="' . esc_attr($link['href']) . '"', $pieces[$link['open']], 1);
                        }
                    }
                    $link = null;
                }
            }
            $pieces[] = $part;
            continue;
        }
        if ($part[0] === '<' || $link !== null || $keep_depth > 0 || array_sum($skip) > 0) {
            $pieces[] = $part;
            continue;
        }
        // Bare addresses in plain text.
        $pieces[] = preg_replace_callback(
            '#(?<![\w@/.-])(?:https?://|www\.)[^\s<>"\'\[\]{}|\\\\^`]+|(?<![\w@/.-])[a-z0-9-]+(?:\.[a-z0-9-]+)*\.(?:com|org|net|gov|edu|us|io|tv|info)/[^\s<>"\'\[\]{}|\\\\^`]+#i',
            function ($m) {
                $url = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $tail = '';
                // Sentence punctuation after an address is not part of it.
                while ($url !== '' && preg_match('/[.,;:!?)\x{2019}\x{201D}]$/u', $url)) {
                    $last = mb_substr($url, -1);
                    if ($last === ')' && substr_count($url, '(') >= substr_count($url, ')')) break;
                    $tail = $last . $tail;
                    $url = mb_substr($url, 0, -1);
                }
                if (strlen($url) < 12) return $m[0];
                return kop_url_labels_link($url) . esc_html($tail);
            },
            $part
        );
    }
    return implode('', $pieces);
}

add_filter('the_content', 'kop_url_labels_filter_html', 99);
add_filter('widget_text_content', 'kop_url_labels_filter_html', 99);

// Priority 1: registered before any script that lists it as a dependency
// (js/shared/program-links.js labels its links with kopUrlLabel()).
add_action('wp_enqueue_scripts', function () {
    $path = get_stylesheet_directory() . '/js/url-labels.js';
    if (file_exists($path)) {
        wp_enqueue_script('kop-url-labels', get_stylesheet_directory_uri() . '/js/url-labels.js', array(), filemtime($path), true);
    }
}, 1);
