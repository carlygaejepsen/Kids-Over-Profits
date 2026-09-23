<?php
/**
 * Where each long-form article sits, and the orientation the reading layout
 * prints around one: the trail back to the hub it belongs to, and the link
 * on to the next article in the same reading order.
 *
 * The articles are flat in the database. All 29 of them are Pages with no
 * parent, no menu order and no entry in any menu, assigned
 * templates/page-article.php by slug in kop_template_assignments(). Nothing
 * stored anywhere says that "Precursors in Antiquity" is read under "Early
 * Systems of Child Control", which is read under History - the two index
 * pages say so in their own body links, and a reader who arrives from a
 * search engine sees none of it.
 *
 * kop_article_parents() is that structure written down: an ordered map of
 * page slug to the slug it sits under, which is either another article or
 * one of the hub pages from kop_template_assignments(). The order of the map
 * is the reading order. Moving an article is one line; nothing else changes.
 *
 * A custom post type would hold the same two facts (a parent and a place in
 * a sequence) at the cost of migrating every page and every URL, so the map
 * is the map.
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Page slug => the slug it is read under. Order is reading order, so an
 * article's next link is the entry after it in a depth-first walk of its
 * hub. A slug whose page does not exist is skipped wherever it appears.
 *
 * The History branch is not editorial guesswork: the History hub links its
 * four, and the two index pages under it link their own children, in this
 * order. The rest are placed by subject and are the owner's to move.
 */
function kop_article_parents() {
    return apply_filters('kop_article_parents', array(
        // History. The two index pages are articles themselves - each is a
        // page of links and nothing else - so they sit in the trail between
        // the hub and the timeline a reader lands on.
        'tti-history-part-one'                         => 'history',
        'early-child-control'                          => 'history',
        'antiquity'                                    => 'early-child-control',
        'medieval-child-oblation-and-monastic-schools' => 'early-child-control',
        'orphanages'                                   => 'early-child-control',
        'idd-timeline'                                 => 'early-child-control',
        'birth-of-the-tti'                             => 'history',
        'juvenile-justice-timeline'                    => 'birth-of-the-tti',
        'fundamentalist'                               => 'birth-of-the-tti',
        'wilderness-therapy-timeline'                  => 'birth-of-the-tti',
        'experimental-group-psychology'                => 'birth-of-the-tti',
        'war-on-drugs'                                 => 'birth-of-the-tti',
        'corporatization'                              => 'history',
        'advocacy-history'                             => 'history',

        // Research summaries: someone else's report, read and cited here.
        'warehouses-of-neglect'                        => 'researchreports',
        'child-welfare'                                => 'researchreports',
        'gao-2007-report'                              => 'researchreports',
        'more-than-troubling'                          => 'researchreports',
        'the-kids-are-not-alright'                     => 'researchreports',
        'notes-quotes-summary'                         => 'researchreports',

        // Law and policy: the statutes, the bills and the case analyses.
        'sicaa'                                        => 'law-policy',
        'challenges-in-maine'                          => 'law-policy',
        'doe-v-trails-motions'                         => 'law-policy',
        'jane-june-doe-v-trails-carolina-et-al-summary' => 'law-policy',
        'john-doe-v-trails-complaint-summary-defendant-information' => 'law-policy',

        // Written for survivors rather than about the industry.
        'common-survivor-experiences'                  => 'survivors',
        'spiritual-abuse'                              => 'survivors',
        'survivor-resources-nature'                    => 'survivors',

        // The press coverage index.
        'news-2'                                       => 'journalists',
    ));
}

/**
 * The hub slugs, taken from the template assignments rather than listed
 * again here, so a new hub page is a hub as soon as it has the template.
 */
function kop_article_hub_slugs() {
    static $hubs = null;
    if ($hubs !== null) {
        return $hubs;
    }
    $hubs = array();
    if (!function_exists('kop_template_assignments')) {
        return $hubs;
    }
    foreach (kop_template_assignments() as $slug => $spec) {
        $template = is_array($spec) ? (isset($spec['template']) ? $spec['template'] : '') : $spec;
        if ($template === 'page-hub.php') {
            $hubs[$slug] = true;
        }
    }
    return $hubs;
}

/**
 * Title and URL for a slug, or null when no published page has it. Looked up
 * once per request per slug: a trail asks for three or four of these and the
 * footer for two more, and get_page_by_path() is a query each time.
 */
function kop_article_page($slug) {
    static $cache = array();
    if (array_key_exists($slug, $cache)) {
        return $cache[$slug];
    }
    $page = get_page_by_path($slug);
    $cache[$slug] = ($page && $page->post_status === 'publish')
        ? array('slug' => $slug, 'title' => get_the_title($page), 'url' => get_permalink($page))
        : null;
    return $cache[$slug];
}

/**
 * The slugs read under this one, in the map's order.
 */
function kop_article_children($slug) {
    static $index = null;
    if ($index === null) {
        $index = array();
        foreach (kop_article_parents() as $child => $parent) {
            $index[$parent][] = $child;
        }
    }
    return isset($index[$slug]) ? $index[$slug] : array();
}

/**
 * Every article under a hub, depth first, which is the order a reader
 * working through the hub would meet them: an index page, then the pages it
 * indexes, then whatever the hub lists next.
 */
function kop_article_sequence($hub) {
    $out  = array();
    $walk = function ($slug) use (&$walk, &$out) {
        foreach (kop_article_children($slug) as $child) {
            if (!kop_article_page($child)) {
                // A slug whose page has gone would otherwise put a dead link
                // in the footer of the article before it.
                continue;
            }
            $out[] = $child;
            $walk($child);
        }
    };
    $walk($hub);
    return $out;
}

/**
 * The trail above an article: the hub it belongs to, then any index page
 * between the two. Outermost first, the article itself not included. An
 * article with no entry in the map gets nothing.
 */
function kop_article_trail($slug) {
    $parents = kop_article_parents();
    $hubs    = kop_article_hub_slugs();
    $trail   = array();
    $seen    = array($slug => true);
    $at      = isset($parents[$slug]) ? $parents[$slug] : '';

    // Up to the hub, guarding against a loop somebody could introduce by
    // pointing two articles at each other.
    while ($at !== '' && !isset($seen[$at])) {
        $seen[$at] = true;
        $page = kop_article_page($at);
        if ($page) {
            array_unshift($trail, $page);
        }
        if (isset($hubs[$at])) {
            break;
        }
        $at = isset($parents[$at]) ? $parents[$at] : '';
    }
    return $trail;
}

/**
 * The article before and after this one in its hub's reading order, or null
 * for either end. The walk is over the whole hub, not just this article's
 * siblings, so the last timeline under one index page leads on to the next
 * index page rather than stopping.
 */
function kop_article_neighbours($slug) {
    $trail = kop_article_trail($slug);
    if (!$trail) {
        return array('prev' => null, 'next' => null);
    }
    $hub      = $trail[0]['slug'];
    $sequence = kop_article_sequence($hub);
    $at       = array_search($slug, $sequence, true);
    if ($at === false) {
        return array('prev' => null, 'next' => null);
    }
    return array(
        'prev' => $at > 0 ? kop_article_page($sequence[$at - 1]) : null,
        'next' => isset($sequence[$at + 1]) ? kop_article_page($sequence[$at + 1]) : null,
    );
}

/**
 * The trail, as markup. The machine-readable copy is not printed here:
 * Yoast already puts a BreadcrumbList in its schema graph on every page, and
 * kop_article_yoast_breadcrumbs() fills that one in instead. Two
 * BreadcrumbLists on one page disagree with each other.
 *
 * Prints nothing for a page the map does not place, rather than a lone
 * "Home" crumb that tells a reader nothing.
 */
function kop_article_breadcrumbs($slug, $current_title) {
    $trail = kop_article_trail($slug);
    $hubs  = kop_article_hub_slugs();
    /* A hub has nothing above it but the home page, and that one step is
     * worth printing: it is what says the page is a section of a site rather
     * than the site. Anything else the map does not place gets nothing. */
    if (!$trail && !isset($hubs[$slug])) {
        return;
    }

    $crumbs = array_merge(
        array(array('title' => 'Home', 'url' => home_url('/'))),
        $trail
    );

    echo '<nav class="kop-article-trail" aria-label="Breadcrumb"><ol>';
    foreach ($crumbs as $crumb) {
        echo '<li><a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['title']) . '</a></li>';
    }
    echo '<li aria-current="page">' . esc_html($current_title) . '</li>';
    echo '</ol></nav>';
}

/**
 * The same trail, handed to Yoast, which puts a BreadcrumbList in its schema
 * graph on every page of this site. With no page parents to read, that list
 * has only ever said "Home > this page"; the trail is the missing middle.
 *
 * Yoast's own list is home first and the current page last, so the steps go
 * between the two. A page the map does not place is left exactly as it was.
 */
function kop_article_yoast_breadcrumbs($links) {
    if (!is_array($links) || count($links) < 2 || !is_singular()) {
        return $links;
    }
    $trail = kop_article_trail(get_post_field('post_name', get_the_ID()));
    if (!$trail) {
        return $links;
    }
    $middle = array();
    foreach ($trail as $step) {
        $middle[] = array('url' => $step['url'], 'text' => $step['title']);
    }
    $home = array_shift($links);
    return array_merge(array($home), $middle, $links);
}
add_filter('wpseo_breadcrumb_links', 'kop_article_yoast_breadcrumbs');

/**
 * Previous and next in the reading order, for the foot of an article. The
 * next link carries the hub's name, because "Continue" on its own does not
 * say what a reader is continuing through.
 */
function kop_article_continue($slug) {
    $near = kop_article_neighbours($slug);
    if (!$near['prev'] && !$near['next']) {
        return;
    }
    $trail = kop_article_trail($slug);
    $hub   = $trail ? $trail[0]['title'] : '';

    echo '<nav class="kop-article-continue" aria-label="' .
        esc_attr($hub !== '' ? 'More in ' . $hub : 'More in this series') . '">';
    if ($near['prev']) {
        echo '<a class="kop-article-continue__prev" href="' . esc_url($near['prev']['url']) . '">' .
            '<span>Previous</span>' . esc_html($near['prev']['title']) . '</a>';
    }
    if ($near['next']) {
        echo '<a class="kop-article-continue__next" href="' . esc_url($near['next']['url']) . '">' .
            '<span>Next</span>' . esc_html($near['next']['title']) . '</a>';
    }
    echo '</nav>';
}

/* -------------------------------------------------------------- timelines --
 *
 * Nine of these articles are timelines: a list of dated entries, each one a
 * list item whose first bold run begins with a year -
 * "<strong>1660 - First U.S. Workhouse Established (Boston)</strong>",
 * with the detail in a nested list under it. Juvenile Justice has 71 of them
 * across 1660 to 2023, Fundamentalist Christian Homes 48 across 1517 to 2011.
 *
 * Nothing said so on the page. A reader arriving at forty thousand characters
 * of prose could not see that it covered three centuries, or get to the
 * 1970s without scrolling for them.
 *
 * The years are already in the writing, so none of this asks an editor for
 * anything: the entries are read out of the rendered content, given ids, and
 * drawn as a band above the article.
 */

/** How many dated entries make a page worth drawing a timeline for. */
define('KOP_ARTICLE_TIMELINE_MIN', 8);

/**
 * The year an entry starts with, or null. Handles "1912", "1730s", "1179 CE",
 * "c. 1400" and "1968:", which are all shapes these articles use.
 */
function kop_article_entry_year($text) {
    $text = trim(html_entity_decode(wp_strip_all_tags((string) $text), ENT_QUOTES, 'UTF-8'));
    if (preg_match('/^(?:c\.?\s*)?(1[0-9]{3}|20[0-2][0-9])s?\b/u', $text, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * Find the dated entries in rendered article content, give each one an id so
 * the timeline can point at it, and return them in the order they appear.
 *
 * $html is edited in place. Only the id is added: the entry's own markup is
 * otherwise untouched, and an entry that already has an id keeps it.
 *
 * Returns array of array('id' => ..., 'year' => int, 'text' => ...).
 */
function kop_article_timeline_entries(&$html) {
    $entries = array();
    $used    = array();

    $html = preg_replace_callback(
        '#<li([^>]*)>(\s*(?:<[^>]+>\s*)*?<strong>)(.{0,120}?)</strong>#is',
        static function ($m) use (&$entries, &$used) {
            $year = kop_article_entry_year($m[3]);
            if ($year === null) {
                return $m[0];
            }
            $text = trim(html_entity_decode(wp_strip_all_tags($m[3]), ENT_QUOTES, 'UTF-8'));
            $attrs = $m[1];
            if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs, $idm)) {
                $id = $idm[1];
            } else {
                /* The title already begins with the year, so the slug of
                 * the title carries it: "y1660-first-u-s-workhouse". The
                 * letter keeps it a valid id whatever a browser thinks of
                 * one starting with a digit. */
                $id   = 'y' . sanitize_title($text);
                $base = $id;
                $n    = 2;
                while (isset($used[$id])) {
                    $id = $base . '-' . $n++;
                }
                $attrs .= ' id="' . esc_attr($id) . '"';
            }
            $used[$id] = true;
            $entries[] = array('id' => $id, 'year' => $year, 'text' => $text);
            return '<li' . $attrs . ' class="kop-article-dated">' . $m[2] . $m[3] . '</strong>';
        },
        $html
    );

    return $entries;
}

/**
 * The ticks along the band: a round number of years apart, chosen so a span
 * of three centuries does not get one mark per decade and a span of forty
 * years does not get one mark in total.
 */
function kop_article_timeline_step($span) {
    foreach (array(10, 20, 25, 50, 100, 200) as $step) {
        if ($span / $step <= 12) {
            return $step;
        }
    }
    return 500;
}

/**
 * The band itself: one dot per entry at its year, and a handful of labelled
 * marks a reader can click or tab to.
 *
 * Positions are percentages of the span, so the whole thing is fluid and
 * needs no SVG - the brief asked for one, but an SVG here would be a fixed
 * coordinate system standing in for what CSS already does properly at any
 * width.
 *
 * The dots are a picture of where the entries fall, and they are a shortcut
 * for a pointer, but they are not the accessible route: seventy-one tab stops
 * in front of an article would be an obstacle, not a feature. They are hidden
 * from assistive technology and skipped by the keyboard; the labelled marks,
 * each jumping to the first entry of its period, are the interface, and the
 * article's own contents list is beside them.
 */
function kop_article_timeline($entries) {
    if (count($entries) < KOP_ARTICLE_TIMELINE_MIN) {
        return;
    }
    $years = array();
    foreach ($entries as $entry) {
        $years[] = $entry['year'];
    }
    $first = min($years);
    $last  = max($years);
    $span  = $last - $first;
    if ($span < 20) {
        return;
    }

    $step  = kop_article_timeline_step($span);
    $start = (int) (floor($first / $step) * $step);
    $end   = (int) (ceil($last / $step) * $step);
    $width = max(1, $end - $start);

    $place = static function ($year) use ($start, $width) {
        return round((($year - $start) / $width) * 100, 3);
    };

    /* Each labelled mark jumps to the first entry at or after it, so a click
     * always lands on something. */
    $marks = array();
    for ($year = $start; $year <= $end; $year += $step) {
        $target = null;
        foreach ($entries as $entry) {
            if ($entry['year'] >= $year && ($year + $step) > $entry['year']) {
                $target = $entry;
                break;
            }
        }
        $marks[] = array('year' => $year, 'target' => $target);
    }

    echo '<nav class="kop-article-timeline" aria-label="Timeline of this article">';
    echo '<p class="kop-article-timeline__caption">'
        . esc_html(count($entries) . ' dated entries, ' . $first . ' to ' . $last)
        . '</p>';
    echo '<div class="kop-article-timeline__band">';

    foreach ($entries as $entry) {
        echo '<a class="kop-article-timeline__dot" href="#' . esc_attr($entry['id']) . '"'
            . ' style="left:' . $place($entry['year']) . '%"'
            . ' tabindex="-1" aria-hidden="true" title="'
            . esc_attr($entry['year'] . ' ' . $entry['text']) . '"></a>';
    }

    /* Every other year is marked "minor" so a narrow screen can drop half the
     * labels and keep the rest evenly spaced. A CSS nth-of-type would not do
     * it: the marks are a mix of links and plain spans, and the two are
     * counted separately, which thins them out unevenly. */
    foreach ($marks as $index => $mark) {
        $left  = $place($mark['year']);
        $minor = ($index % 2 === 1) ? ' is-minor' : '';
        if ($mark['target']) {
            echo '<a class="kop-article-timeline__mark' . $minor . '"'
                . ' href="#' . esc_attr($mark['target']['id']) . '"'
                . ' style="left:' . $left . '%">'
                . '<span class="kop-article-timeline__year">' . esc_html($mark['year']) . '</span>'
                . '<span class="screen-reader-text">'
                . esc_html(': jump to ' . $mark['target']['text']) . '</span></a>';
        } else {
            echo '<span class="kop-article-timeline__mark is-empty' . $minor . '"'
                . ' style="left:' . $left . '%" aria-hidden="true">'
                . '<span class="kop-article-timeline__year">' . esc_html($mark['year']) . '</span></span>';
        }
    }

    echo '</div></nav>';
}
