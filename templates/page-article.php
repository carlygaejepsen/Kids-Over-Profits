<?php
/**
 * Template Name: Article
 * Description: Long-form reading layout for the research summaries, timelines
 * and case analyses. The editor content is printed unchanged; the template
 * adds a reading-time line, a table of contents built from the article's
 * own headings (or its bold-only marker paragraphs, which many of these
 * pages use instead of headings), a readable measure, and an updated date.
 *
 * Section ids are added to headings at render time only, so nothing is
 * written back to post_content.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_trail_css_path = get_stylesheet_directory() . '/css/trail.css';
if (file_exists($kop_trail_css_path)) {
    wp_enqueue_style(
        'kop-trail',
        get_stylesheet_directory_uri() . '/css/trail.css',
        array(),
        filemtime($kop_trail_css_path)
    );
}

$kop_art_js_path = get_stylesheet_directory() . '/js/article-toc.js';
if (file_exists($kop_art_js_path)) {
    wp_enqueue_script(
        'kop-article-toc',
        get_stylesheet_directory_uri() . '/js/article-toc.js',
        array(),
        filemtime($kop_art_js_path),
        true
    );
}

$kop_art_css_path = get_stylesheet_directory() . '/css/article.css';
if (file_exists($kop_art_css_path)) {
    wp_enqueue_style(
        'kop-article',
        get_stylesheet_directory_uri() . '/css/article.css',
        array(),
        filemtime($kop_art_css_path)
    );
}

$kop_art_pieces_css_path = get_stylesheet_directory() . '/css/article-pieces.css';
if (file_exists($kop_art_pieces_css_path)) {
    wp_enqueue_style(
        'kop-article-pieces',
        get_stylesheet_directory_uri() . '/css/article-pieces.css',
        array(),
        filemtime($kop_art_pieces_css_path)
    );
}

if (!function_exists('kop_article_anchor')) {
    /**
     * The link mark that lets a reader copy the address of one section.
     *
     * Hidden until the heading is hovered or the link itself is focused, so
     * it is there for the person who wants to cite a section of a 46,000
     * character timeline and invisible to everybody else. It carries the
     * section's own name, because "Link to this section" thirty times over
     * is no use to somebody listening to the page.
     */
    function kop_article_anchor($id, $text) {
        return ' <a class="kop-article-anchor" href="#' . esc_attr($id) . '"'
            . ' aria-label="' . esc_attr('Link to this section: ' . $text) . '">#</a>';
    }
}

if (!function_exists('kop_article_sections')) {
    /**
     * Find section markers in rendered content and make sure each has an id.
     *
     * Markers are h2/h3 elements, and (only when the article has fewer than
     * three of those) short paragraphs whose entire text is bold. Returns
     * array('html' => content with ids, 'toc' => [ [id, text, level], ... ]).
     */
    function kop_article_sections($html) {
        $toc  = array();
        $used = array();

        $make_id = static function ($text, $existing) use (&$used) {
            $id = $existing !== '' ? $existing : sanitize_title(wp_strip_all_tags($text));
            if ($id === '') {
                $id = 'section';
            }
            $base = $id;
            $n    = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $n++;
            }
            $used[$id] = true;
            return $id;
        };

        // Real headings first.
        $html = preg_replace_callback(
            '#<(h[23])([^>]*)>(.*?)</\1>#is',
            static function ($m) use (&$toc, $make_id) {
                $text = trim(wp_strip_all_tags($m[3]));
                if ($text === '') {
                    return $m[0];
                }
                $attrs    = $m[2];
                $existing = '';
                if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs, $idm)) {
                    $existing = $idm[1];
                    $attrs    = preg_replace('/\sid=["\'][^"\']+["\']/i', '', $attrs);
                }
                $id    = $make_id($text, $existing);
                $toc[] = array($id, $text, $m[1] === 'h2' ? 2 : 3);
                return '<' . $m[1] . $attrs . ' id="' . esc_attr($id) . '">' . $m[3]
                    . kop_article_anchor($id, $text) . '</' . $m[1] . '>';
            },
            $html
        );

        if (count($toc) < 3) {
            // Bold-only marker paragraphs: <p ...><strong>Title</strong></p>
            $markers = array();
            $html = preg_replace_callback(
                '#<p([^>]*)>\s*(?:<em>)?<strong>(.*?)</strong>(?:</em>)?\s*(?:<br\s*/?>)?\s*</p>#is',
                static function ($m) use (&$markers, $make_id) {
                    $text = trim(wp_strip_all_tags($m[2]));
                    if ($text === '' || mb_strlen($text) > 90 || preg_match('/[.!?]\s*$/u', $text)) {
                        return $m[0];
                    }
                    $attrs    = $m[1];
                    $existing = '';
                    if (preg_match('/\sid=["\']([^"\']+)["\']/i', $attrs, $idm)) {
                        $existing = $idm[1];
                        $attrs    = preg_replace('/\sid=["\'][^"\']+["\']/i', '', $attrs);
                    }
                    $id        = $make_id($text, $existing);
                    $markers[] = array($id, $text, 2);
                    return '<p' . $attrs . ' id="' . esc_attr($id) . '" class="kop-article-marker"><strong>'
                        . $m[2] . '</strong>' . kop_article_anchor($id, $text) . '</p>';
                },
                $html
            );
            if (count($markers) >= 3) {
                $toc = array_merge($toc, $markers);
            } else {
                // Not enough markers to be worth a contents box; leave the
                // paragraphs as they were, anchor marks included - without
                // the contents list there is nothing for them to point back
                // to, and a stray "#" on three paragraphs reads as a typo.
                $html = preg_replace('/ class="kop-article-marker"/', '', $html);
                $html = preg_replace('~\s*<a class="kop-article-anchor"[^>]*>#</a>~', '', $html);
            }
        }

        if (count($toc) < 3) {
            $toc = array();
        }
        return array('html' => $html, 'toc' => $toc);
    }
}

get_header();

while (have_posts()) :
    the_post();

    $kop_art_raw     = get_the_content();
    $kop_art_words   = str_word_count(wp_strip_all_tags(strip_shortcodes($kop_art_raw)));
    $kop_art_minutes = max(1, (int) round($kop_art_words / 230));

    $kop_art_html = apply_filters('the_content', $kop_art_raw);
    $kop_art_html = str_replace(']]>', ']]&gt;', $kop_art_html);
    $kop_art      = kop_article_sections($kop_art_html);
    /* Dated list entries get ids here so the timeline band can point at them;
     * the band itself is printed below the contents box. */
    $kop_art_dated = function_exists('kop_article_timeline_entries')
        ? kop_article_timeline_entries($kop_art['html'])
        : array();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-article'); ?>>
    <div class="entry-content-wrap">

        <?php
        // Where this page sits. The articles are flat in the database, so
        // without this a reader who landed on a timeline from a search
        // engine has no way of knowing it is one of five under Birth of the
        // TTI, itself one of four under History. inc/article-parts.php.
        if (function_exists('kop_article_breadcrumbs')) {
            kop_article_breadcrumbs(get_post_field('post_name', get_the_ID()), get_the_title());
        }
        ?>

        <header class="entry-header page-title title-align-center kop-article-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php if (has_excerpt()) : ?>
                <p class="kop-article-standfirst"><?php echo esc_html(get_the_excerpt()); ?></p>
            <?php endif; ?>
            <p class="kop-article-meta">
                <span><?php echo esc_html($kop_art_minutes); ?> min read</span>
                <span aria-hidden="true">&middot;</span>
                <span>Updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time></span>
            </p>
        </header>

        <?php if (has_post_thumbnail()) : ?>
            <div class="post-thumbnail kop-article-thumbnail">
                <?php the_post_thumbnail('full'); ?>
            </div>
        <?php endif; ?>

        <?php if ($kop_art['toc']) : ?>
            <details class="kop-article-toc" open>
                <summary>Contents</summary>
                <ol>
                    <?php foreach ($kop_art['toc'] as $item) : ?>
                        <li class="kop-toc-l<?php echo (int) $item[2]; ?>"><a href="#<?php echo esc_attr($item[0]); ?>"><?php echo esc_html($item[1]); ?></a></li>
                    <?php endforeach; ?>
                </ol>
            </details>
        <?php endif; ?>

        <?php
        // Nine of these articles are dated lists spanning centuries. The band
        // says so, and lets a reader jump to a period. inc/article-parts.php.
        if (function_exists('kop_article_timeline')) {
            kop_article_timeline($kop_art_dated);
        }
        ?>

        <div class="entry-content single-content">
            <?php
            echo $kop_art['html']; // Rendered post content, already through the_content filters.
            wp_link_pages(array(
                'before' => '<div class="page-links">',
                'after'  => '</div>',
            ));
            ?>
        </div>

        <?php
        // What to read next, in the order the hub lists it. A timeline is
        // one of a set, and the set is what makes sense of it.
        if (function_exists('kop_article_continue')) {
            kop_article_continue(get_post_field('post_name', get_the_ID()));
        }
        ?>

        <footer class="kop-article-footer">
            <span>Updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time>.</span>
            <a href="<?php echo esc_url(home_url('/contact/')); ?>">Corrections</a>
            <?php edit_post_link('Edit this article', '<span class="kop-article-edit">', '</span>'); ?>
        </footer>

    </div>
</article>
    <?php
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
endwhile;

get_footer();
