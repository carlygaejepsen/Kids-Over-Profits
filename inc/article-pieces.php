<?php
/**
 * The four pieces the long-form pages are assembled from: an era header, a
 * collapsible section, a sources list and a "Why this matters" block.
 *
 * Item 19 of docs/FIX-PLAN-2026-09.md. The brief that asked for these asked
 * for blocks; the theme has no bundler and should not gain one, so each piece
 * is a function a template calls and a shortcode an editor can place mid-
 * article. The two share one builder, so what an editor places and what a
 * template prints cannot drift apart.
 *
 * Every builder returns markup rather than echoing it, because a shortcode
 * has to return and because a returned string is something a test can read.
 * Templates echo the return value.
 *
 * Two rules carried over from the items these serve:
 *
 * - A collapsible section is open (15B). A closed <details> is invisible to
 *   the browser's find-in-page and swallows an anchor jump from the contents
 *   list, and these pages are 40,000 characters of somebody's research with a
 *   contents list at the top. Closing one by default needs a hashchange
 *   handler to open the target first, which nothing here has, so the piece
 *   does not offer a closed state at all.
 * - An era is never colour alone (WCAG 1.4.1). The tag prints its era's name,
 *   and the colour is a rule and a chip outline over the top of it - the
 *   palette reserves the bright accents for borders and highlights, not for
 *   blocks of background.
 *
 * The era colours themselves live in css/article-pieces.css as one class per
 * era, over the tokens in css/colors.css; this file knows only the names.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Era slug => the name printed on its tag. Filter 'kop_article_eras' to add
 * one; a new era also needs its three lines of CSS, and without them it still
 * reads correctly, just in the neutral ink.
 */
function kop_article_eras() {
    return apply_filters('kop_article_eras', array(
        'nonprofit'         => 'Nonprofit',
        'for-profit'        => 'For-profit',
        'private-equity'    => 'Private equity',
        'survivor-advocacy' => 'Survivor advocacy',
    ));
}

/**
 * A stable, unique id for one piece.
 *
 * Ids have to be unique across everything on the page, because the contents
 * list and the timeline band both point at them, so the ones handed out here
 * are remembered for the length of the request the way kop_article_sections()
 * remembers its own. A piece given an explicit id keeps it; a piece given a
 * title is named after it; a piece with neither falls back to its prefix.
 */
function kop_article_piece_id($given, $text, $prefix) {
    static $used = array();

    $id = trim((string) $given);
    if ($id === '') {
        $id = sanitize_title(wp_strip_all_tags((string) $text));
    }
    if ($id === '') {
        $id = $prefix;
    }

    $base = $id;
    $n    = 2;
    while (isset($used[$id])) {
        $id = $base . '-' . $n++;
    }
    $used[$id] = true;
    return $id;
}

/**
 * The tag that says which era a section belongs to: a named chip, coloured by
 * a class rather than by anything printed here.
 *
 * An era the map does not know is not dropped - an unknown slug is written out
 * as words and given the neutral class, because a tag that says nothing is
 * worse than a tag in the wrong colour.
 */
function kop_article_era_tag($era, $icon = '') {
    $era = sanitize_title((string) $era);
    if ($era === '') {
        return '';
    }

    $eras  = kop_article_eras();
    $known = isset($eras[$era]);
    $label = $known ? $eras[$era] : ucfirst(str_replace('-', ' ', $era));

    $mark = '';
    if (trim((string) $icon) !== '') {
        // A class hook, never text: the icon is decoration over the name and
        // must not be the thing carrying the meaning.
        $mark = '<span class="kop-article-era__icon ' . esc_attr(trim((string) $icon)) . '" aria-hidden="true"></span>';
    }

    return '<span class="kop-article-era__tag' . ($known ? ' is-' . esc_attr($era) : '') . '">'
        . $mark . '<span class="kop-article-era__name">' . esc_html($label) . '</span></span>';
}

/**
 * Piece 1: an era header. Title, era tag, one-line summary, optional icon,
 * anchor id.
 *
 * The title is a real heading, so it reaches the contents list the article
 * template builds and a reader can link to the era the same way they link to
 * anything else.
 */
function kop_article_era_header($args = array()) {
    $args = array_merge(array(
        'title'   => '',
        'era'     => '',
        'summary' => '',
        'icon'    => '',
        'id'      => '',
        'level'   => 2,
    ), (array) $args);

    $title = trim(wp_strip_all_tags((string) $args['title']));
    $tag   = kop_article_era_tag($args['era'], $args['icon']);
    if ($title === '' && $tag === '') {
        return '';
    }

    $level = ((int) $args['level'] === 3) ? 'h3' : 'h2';
    $id    = kop_article_piece_id($args['id'], $title !== '' ? $title : $args['era'], 'era');
    $era   = sanitize_title((string) $args['era']);

    $out = '<header class="kop-article-era' . ($era !== '' ? ' kop-article-era--' . esc_attr($era) : '') . '"'
        . ' id="' . esc_attr($id) . '">';
    if ($tag !== '') {
        $out .= '<p class="kop-article-era__tagline">' . $tag . '</p>';
    }
    if ($title !== '') {
        $out .= '<' . $level . ' class="kop-article-era__title">' . esc_html($title) . '</' . $level . '>';
    }
    if (trim((string) $args['summary']) !== '') {
        $out .= '<p class="kop-article-era__summary">' . wp_kses_post($args['summary']) . '</p>';
    }
    return $out . '</header>';
}

/**
 * Piece 2: a collapsible section. Title, one-line summary, body, optional
 * sources.
 *
 * Open, always, for the reasons at the top of this file. The heading goes
 * inside the <summary>, which is where the contents list finds it and where a
 * reader's eye expects it; the disclosure triangle is the browser's own.
 */
function kop_article_collapsible($args = array()) {
    $args = array_merge(array(
        'title'   => '',
        'summary' => '',
        'body'    => '',
        'sources' => '',
        'id'      => '',
        'level'   => 2,
        'era'     => '',
    ), (array) $args);

    $title = trim(wp_strip_all_tags((string) $args['title']));
    $body  = (string) $args['body'];
    if ($title === '' && trim($body) === '') {
        return '';
    }

    $level = ((int) $args['level'] === 3) ? 'h3' : 'h2';
    $id    = kop_article_piece_id($args['id'], $title, 'section');
    $tag   = kop_article_era_tag($args['era']);

    $out = '<details class="kop-article-section" id="' . esc_attr($id) . '" open>';
    $out .= '<summary class="kop-article-section__summary">';
    if ($title !== '') {
        $out .= '<' . $level . ' class="kop-article-section__title">' . esc_html($title) . '</' . $level . '>';
    }
    if ($tag !== '') {
        $out .= $tag;
    }
    if (trim((string) $args['summary']) !== '') {
        $out .= '<span class="kop-article-section__hint">' . wp_kses_post($args['summary']) . '</span>';
    }
    $out .= '</summary>';
    $out .= '<div class="kop-article-section__body">' . $body . '</div>';

    $sources = (string) $args['sources'];
    if (trim($sources) !== '') {
        $out .= (strpos($sources, 'kop-article-sources') !== false)
            ? $sources
            : kop_article_sources($sources, array('level' => 3));
    }
    return $out . '</details>';
}

/**
 * One citation, in the one format every page uses: the title, linked where
 * there is somewhere to link to, then the publisher, then the date, then
 * whatever note the editor added.
 *
 * A citation with no title but a URL is printed as its host, so a bare link
 * still reads as a source rather than as forty characters of query string.
 */
function kop_article_source($item, $number, $prefix) {
    $item = array_merge(array(
        'title'     => '',
        'publisher' => '',
        'date'      => '',
        'url'       => '',
        'note'      => '',
    ), (array) $item);

    $url   = trim((string) $item['url']);
    $title = trim(wp_strip_all_tags((string) $item['title']));
    if ($title === '' && $url !== '') {
        $host  = parse_url($url, PHP_URL_HOST);
        $title = $host ? preg_replace('/^www\./', '', $host) : $url;
    }
    if ($title === '' && trim((string) $item['note']) === '') {
        return '';
    }

    $cite = esc_html($title);
    if ($url !== '') {
        $cite = '<a href="' . esc_url($url) . '" rel="nofollow noopener">' . $cite . '</a>';
    }

    $out = '<li class="kop-article-sources__item" id="' . esc_attr($prefix . '-' . $number) . '">';
    $out .= '<cite class="kop-article-sources__cite">' . $cite . '</cite>';
    if (trim((string) $item['publisher']) !== '') {
        $out .= '<span class="kop-article-sources__publisher">, ' . esc_html(trim((string) $item['publisher'])) . '</span>';
    }
    if (trim((string) $item['date']) !== '') {
        $out .= '<span class="kop-article-sources__date">, ' . esc_html(trim((string) $item['date'])) . '</span>';
    }
    $out .= '<span class="kop-article-sources__stop">.</span>';
    if (trim((string) $item['note']) !== '') {
        $out .= '<span class="kop-article-sources__note"> ' . wp_kses_post($item['note']) . '</span>';
    }
    return $out . '</li>';
}

/**
 * Whatever an editor typed, read as a list of citations.
 *
 * Either a list they built in the editor or one citation per line, and within
 * a line the fields separated by pipes: title | publisher | date | url. A
 * line with no pipes is a title, and a URL anywhere in it is lifted out and
 * used as the link, so pasting a link and a name works without the editor
 * having to learn the field order.
 */
function kop_article_source_items($text) {
    $text = (string) $text;
    $rows = array();

    if (preg_match_all('#<li[^>]*>(.*?)</li>#is', $text, $found)) {
        $rows = $found[1];
    } else {
        $text = preg_replace('#<br\s*/?>#i', "\n", $text);
        $text = preg_replace('#</p>#i', "\n", $text);
        $text = wp_strip_all_tags($text);
        $rows = preg_split('/\r\n|\r|\n/', $text);
    }

    $items = array();
    foreach ($rows as $row) {
        $row = trim(wp_strip_all_tags((string) $row));
        if ($row === '') {
            continue;
        }

        $url = '';
        if (preg_match('#https?://[^\s<>"\']+#i', $row, $link)) {
            $url = rtrim($link[0], '.,;');
            $row = trim(str_replace($link[0], '', $row));
        }

        $fields = array_map('trim', explode('|', $row));
        $item   = array(
            'title'     => isset($fields[0]) ? $fields[0] : '',
            'publisher' => isset($fields[1]) ? $fields[1] : '',
            'date'      => isset($fields[2]) ? $fields[2] : '',
            'url'       => $url,
            'note'      => '',
        );
        // A fourth field is the URL when the editor gave one that way; a
        // fifth, or a fourth alongside a pasted link, is a note.
        if (isset($fields[3]) && $fields[3] !== '') {
            if ($item['url'] === '' && preg_match('#^https?://#i', $fields[3])) {
                $item['url'] = $fields[3];
            } else {
                $item['note'] = $fields[3];
            }
        }
        if (isset($fields[4]) && $fields[4] !== '' && $item['note'] === '') {
            $item['note'] = $fields[4];
        }

        $items[] = $item;
    }
    return $items;
}

/**
 * Piece 3: the sources list. One format, auto-numbered, the same markup on
 * every page, so a reader learns it once.
 *
 * Takes either a list of citation arrays or the text an editor typed. The
 * numbering is the list's own: an <ol> counts for itself, and each item
 * carries an id so a line of the article can point at it.
 */
function kop_article_sources($items, $args = array()) {
    $args = array_merge(array(
        'title'  => 'Sources',
        'id'     => '',
        'level'  => 2,
        'prefix' => 'source',
    ), (array) $args);

    if (!is_array($items)) {
        $items = kop_article_source_items($items);
    }
    if (!$items) {
        return '';
    }

    $level  = ((int) $args['level'] === 3) ? 'h3' : 'h2';
    $id     = kop_article_piece_id($args['id'], $args['title'], 'sources');
    $prefix = sanitize_title((string) $args['prefix']);
    if ($prefix === '') {
        $prefix = 'source';
    }

    $rows   = '';
    $number = 0;
    foreach ($items as $item) {
        $row = kop_article_source($item, $number + 1, $prefix);
        if ($row !== '') {
            $number++;
            $rows .= $row;
        }
    }
    if ($rows === '') {
        return '';
    }

    $heading = '';
    if (trim((string) $args['title']) !== '') {
        $heading = '<' . $level . ' class="kop-article-sources__title" id="' . esc_attr($id) . '">'
            . esc_html($args['title']) . '</' . $level . '>';
    }

    return '<section class="kop-article-sources"' . ($heading !== '' ? ' aria-labelledby="' . esc_attr($id) . '"' : '') . '>'
        . $heading . '<ol class="kop-article-sources__list">' . $rows . '</ol></section>';
}

/**
 * Piece 4: "Why this matters". Written for journalists, policymakers and
 * parents, and printed in the same place on every page it appears, so nobody
 * has to hunt for it.
 *
 * Callable on a hub page too, which is why it takes its text as an argument
 * rather than reading the post itself.
 */
function kop_article_why_this_matters($args = array()) {
    if (is_string($args)) {
        $args = array('body' => $args);
    }
    $args = array_merge(array(
        'title' => 'Why this matters',
        'body'  => '',
        'id'    => '',
        'level' => 2,
    ), (array) $args);

    $body = trim((string) $args['body']);
    if ($body === '') {
        return '';
    }
    if (strpos($body, '<') === false) {
        $body = '<p>' . $body . '</p>';
    }

    $level = ((int) $args['level'] === 3) ? 'h3' : 'h2';
    $id    = kop_article_piece_id($args['id'], $args['title'], 'why-this-matters');

    $out = '<aside class="kop-article-why" aria-labelledby="' . esc_attr($id) . '">';
    $out .= '<' . $level . ' class="kop-article-why__title" id="' . esc_attr($id) . '">'
        . esc_html($args['title']) . '</' . $level . '>';
    $out .= '<div class="kop-article-why__body">' . wp_kses_post($body) . '</div>';
    return $out . '</aside>';
}

/**
 * 15C's micro-summary: the one sentence under a section heading.
 *
 * A section wrapped in the collapsible piece carries its summary as an
 * attribute and needs none of this. Everywhere else - which is most of these
 * articles, bold marker paragraphs from end to end - the sentence is simply a
 * paragraph the editor marked, either by giving the block the class
 * `kop-article-summary` in the editor or by typing [kop_summary].
 *
 * It has to be marked. A rule on "the paragraph after a heading" would catch
 * the first line of the prose on every section that has no summary, which is
 * all of them today, and quietly restyle 29 articles' worth of writing.
 */
function kop_article_summary($text) {
    $text = trim((string) $text);
    if ($text === '') {
        return '';
    }
    // The editor's own paragraph markup, if it came wrapped, is not wanted
    // here: this is one sentence and it gets one paragraph.
    $text = trim(preg_replace('#^\s*<p[^>]*>(.*)</p>\s*$#is', '$1', $text));
    if ($text === '') {
        return '';
    }
    return '<p class="kop-article-summary">' . wp_kses_post($text) . '</p>';
}

/* -- The same five, for an editor placing one mid-article ----------------- */

if (function_exists('add_shortcode')) {
    add_shortcode('kop_era', 'kop_article_era_shortcode');
    add_shortcode('kop_section', 'kop_article_section_shortcode');
    add_shortcode('kop_sources', 'kop_article_sources_shortcode');
    add_shortcode('kop_why', 'kop_article_why_shortcode');
    add_shortcode('kop_summary', 'kop_article_summary_shortcode');
}

/** [kop_summary]one sentence[/kop_summary] */
function kop_article_summary_shortcode($atts, $content = null) {
    return kop_article_summary(do_shortcode((string) $content));
}

/** [kop_era era="for-profit" title="..." summary="..." icon="" id=""] */
function kop_article_era_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'era'     => '',
        'title'   => '',
        'summary' => '',
        'icon'    => '',
        'id'      => '',
        'level'   => 2,
    ), (array) $atts, 'kop_era');

    // The enclosed form reads as the summary, so an editor can write a
    // sentence with a link in it rather than squeezing one into an attribute.
    if ($atts['summary'] === '' && trim((string) $content) !== '') {
        $atts['summary'] = do_shortcode((string) $content);
    }
    return kop_article_era_header($atts);
}

/** [kop_section title="..." summary="..." era=""]body[/kop_section] */
function kop_article_section_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'title'   => '',
        'summary' => '',
        'era'     => '',
        'id'      => '',
        'level'   => 2,
        'sources' => '',
    ), (array) $atts, 'kop_section');

    $atts['body'] = do_shortcode((string) $content);
    return kop_article_collapsible($atts);
}

/** [kop_sources title="Sources"]one citation per line[/kop_sources] */
function kop_article_sources_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'title'  => 'Sources',
        'id'     => '',
        'level'  => 2,
        'prefix' => 'source',
    ), (array) $atts, 'kop_sources');

    return kop_article_sources((string) $content, $atts);
}

/** [kop_why]text[/kop_why] */
function kop_article_why_shortcode($atts, $content = null) {
    $atts = shortcode_atts(array(
        'title' => 'Why this matters',
        'id'    => '',
        'level' => 2,
    ), (array) $atts, 'kop_why');

    $atts['body'] = do_shortcode((string) $content);
    return kop_article_why_this_matters($atts);
}
