<?php
/**
 * Template Name: Hub Page
 * Description: The navigation hub pages (History, Survivors, Families, Law &
 * Policy, ...). Orientation (breadcrumb, title, standfirst, actions), the
 * page's own editor content, a live module chosen by page slug
 * (kop_hub_module_for()), the articles filed under the hub, where to
 * contribute, then the footer. What each hub shows is set in
 * kop_hub_config() (inc/hub-shell.php); a hub with no settings gets its
 * editor content, its module if any, and the header and footer.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_hub_trail_css_path = get_stylesheet_directory() . '/css/trail.css';
if (file_exists($kop_hub_trail_css_path)) {
    wp_enqueue_style(
        'kop-trail',
        get_stylesheet_directory_uri() . '/css/trail.css',
        array(),
        filemtime($kop_hub_trail_css_path)
    );
}

$kop_hub_css_path = get_stylesheet_directory() . '/css/hub.css';
if (file_exists($kop_hub_css_path)) {
    wp_enqueue_style(
        'kop-hub',
        get_stylesheet_directory_uri() . '/css/hub.css',
        array(),
        filemtime($kop_hub_css_path)
    );
}

/* Three of the four article pieces are wanted on a hub page too - an era
 * header over a group of links, a sources list, and "Why this matters" at
 * the top of a section index. inc/article-pieces.php. */
$kop_hub_pieces_css_path = get_stylesheet_directory() . '/css/article-pieces.css';
if (file_exists($kop_hub_pieces_css_path)) {
    wp_enqueue_style(
        'kop-article-pieces',
        get_stylesheet_directory_uri() . '/css/article-pieces.css',
        array(),
        filemtime($kop_hub_pieces_css_path)
    );
}

if (!function_exists('kop_hub_module_for')) {
    /**
     * Page slug => module callback name. Filter 'kop_hub_modules' to add one.
     * A callback echoes its own markup and prints nothing when it has no data.
     */
    function kop_hub_module_for($slug) {
        $modules = apply_filters('kop_hub_modules', array(
            'law-policy'         => 'kop_hub_module_law_policy',
            'where-are-the-kids' => 'kop_hub_module_locations',
        ));
        return isset($modules[$slug]) && is_callable($modules[$slug]) ? $modules[$slug] : null;
    }
}

if (!function_exists('kop_hub_pdo')) {
    /** Shared PDO handle from api/config.php, or null. */
    function kop_hub_pdo() {
        static $handle = false;
        if ($handle !== false) {
            return $handle;
        }
        $handle = null;
        $config = get_stylesheet_directory() . '/api/config.php';
        if (file_exists($config)) {
            require_once $config;
            if (!isset($pdo) && isset($GLOBALS['pdo'])) {
                $pdo = $GLOBALS['pdo'];
            }
            if (isset($pdo) && $pdo instanceof PDO) {
                $handle = $pdo;
            }
        }
        return $handle;
    }
}

if (!function_exists('kop_hub_module_law_policy')) {
    /**
     * Law & Policy: one column per directory - what it covers, how many
     * records it holds, the five newest (each linking to its own card), and
     * the way in. The two paragraphs are the page's editor text, which
     * kop_hub_config() leaves out so they are not printed twice.
     */
    function kop_hub_module_law_policy() {
        $lawsuits = array();
        $bills    = array();
        $n_law    = null;
        $n_bill   = null;
        $pdo      = kop_hub_pdo();
        if ($pdo) {
            try {
                $n_law = (int) $pdo->query("SELECT COUNT(*) FROM lawsuits WHERE publication_status IN ('approved','published')")->fetchColumn();
                $stmt  = $pdo->query(
                    "SELECT id, case_name, filing_date, status, jurisdiction FROM lawsuits
                     WHERE publication_status IN ('approved','published')
                     ORDER BY filing_date DESC, id DESC LIMIT 5"
                );
                $lawsuits = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

                $n_bill = (int) $pdo->query("SELECT COUNT(*) FROM legislation WHERE publication_status IN ('approved','published')")->fetchColumn();
                $stmt   = $pdo->query(
                    "SELECT id, bill_number, bill_title, jurisdiction, status, last_action_date, introduced_date FROM legislation
                     WHERE publication_status IN ('approved','published')
                     ORDER BY last_action_date DESC, introduced_date DESC, id DESC LIMIT 5"
                );
                $bills = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
            } catch (Throwable $e) {
                // The columns still print their introduction and the way in;
                // only the counts and the newest records are missing.
                $n_law = $n_bill = null;
                $lawsuits = $bills = array();
            }
        }
        $label = static function ($s) {
            return ucfirst(str_replace('_', ' ', (string) $s));
        };
        $meta = static function (array $parts) {
            return implode(" \u{00B7} ", array_filter($parts, 'strlen'));
        };

        $columns = array(
            array(
                'key'   => 'lawsuits',
                'title' => 'Lawsuits',
                'count' => $n_law === null ? '' : $n_law . ' tracked',
                'intro' => 'Legal cases against programs in the troubled teen industry: allegations of abuse, negligence and wrongful death, and how survivors and families are using the courts to hold programs accountable.',
                'url'   => kop_hub_page_url('page-lawsuits.php', '/lawsuits/'),
                'more'  => 'All lawsuits',
                'rows'  => array_map(static function ($c) use ($label, $meta) {
                    return array(
                        'anchor' => 'lawsuit-' . (int) $c['id'],
                        'title'  => (string) $c['case_name'],
                        'meta'   => $meta(array(
                            $c['filing_date'] ? substr((string) $c['filing_date'], 0, 4) : '',
                            (string) ($c['jurisdiction'] ?? ''),
                            $label($c['status'] ?? ''),
                        )),
                    );
                }, $lawsuits),
            ),
            array(
                'key'   => 'legislation',
                'title' => 'Legislation',
                'count' => $n_bill === null ? '' : $n_bill . ' ' . ($n_bill === 1 ? 'bill' : 'bills') . ' tracked',
                'intro' => 'Proposed and passed laws aimed at regulating the troubled teen industry: efforts to improve oversight, protect kids in residential care and create lasting change.',
                'url'   => kop_hub_page_url('page-legislation.php', '/legislative-efforts/'),
                'more'  => 'All legislation',
                'rows'  => array_map(static function ($b) use ($label, $meta) {
                    $when = $b['last_action_date'] ?: $b['introduced_date'];
                    return array(
                        'anchor' => 'bill-' . (int) $b['id'],
                        'title'  => trim($b['bill_number'] . ' ' . $b['bill_title']),
                        'meta'   => $meta(array(
                            (string) ($b['jurisdiction'] ?? ''),
                            $label($b['status'] ?? ''),
                            $when ? date_i18n('M j, Y', strtotime($when)) : '',
                        )),
                    );
                }, $bills),
            ),
        );
        ?>
        <div class="kop-hub-module kop-hub-law">
            <?php foreach ($columns as $col) : ?>
                <section class="kop-hub-col kop-hub-col--<?php echo esc_attr($col['key']); ?>" aria-labelledby="kop-hub-<?php echo esc_attr($col['key']); ?>-h">
                    <h2 class="kop-hub-h" id="kop-hub-<?php echo esc_attr($col['key']); ?>-h">
                        <a href="<?php echo esc_url($col['url']); ?>"><?php echo esc_html($col['title']); ?></a>
                        <?php if ($col['count'] !== '') : ?>
                            <span class="kop-hub-count"><?php echo esc_html($col['count']); ?></span>
                        <?php endif; ?>
                    </h2>
                    <p class="kop-hub-col-intro"><?php echo esc_html($col['intro']); ?></p>
                    <?php if ($col['rows']) : ?>
                        <h3 class="kop-hub-subh">Newest</h3>
                        <ul class="kop-hub-list">
                            <?php foreach ($col['rows'] as $row) : ?>
                                <li>
                                    <a href="<?php echo esc_url($col['url'] . '#' . $row['anchor']); ?>"><?php echo esc_html($row['title']); ?></a>
                                    <?php if ($row['meta'] !== '') : ?>
                                        <span class="kop-hub-meta"><?php echo esc_html($row['meta']); ?></span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                    <a class="kop-hub-more" href="<?php echo esc_url($col['url']); ?>"><?php echo esc_html($col['more']); ?></a>
                </section>
            <?php endforeach; ?>
        </div>
        <?php
    }
}

if (!function_exists('kop_hub_module_locations')) {
    /**
     * Where are the kids: every published state and country hub page, so the
     * map above has a text equivalent and the country pages are reachable.
     */
    function kop_hub_module_locations() {
        $groups = array(
            'States'    => 'templates/page-state.php',
            'Countries' => 'templates/page-country.php',
        );
        $out = array();
        foreach ($groups as $heading => $template) {
            $pages = get_posts(array(
                'post_type'      => 'page',
                'post_status'    => 'publish',
                'posts_per_page' => 200,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_key'       => '_wp_page_template',
                'meta_value'     => $template,
                'no_found_rows'  => true,
            ));
            if ($pages) {
                $out[$heading] = $pages;
            }
        }
        if (!$out) {
            return;
        }
        ?>
        <section class="kop-hub-module kop-hub-locations" aria-label="Browse by location">
            <?php foreach ($out as $heading => $pages) : ?>
                <h2 class="kop-hub-h"><?php echo esc_html($heading); ?> <span class="kop-hub-count"><?php echo count($pages); ?></span></h2>
                <ul class="kop-hub-grid">
                    <?php foreach ($pages as $p) : ?>
                        <li><a href="<?php echo esc_url(get_permalink($p)); ?>"><?php echo esc_html(get_the_title($p)); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            <?php endforeach; ?>
        </section>
        <?php
    }
}

get_header();

while (have_posts()) :
    the_post();
    $kop_hub_slug    = get_post_field('post_name', get_the_ID());
    $kop_hub_module  = kop_hub_module_for($kop_hub_slug);
    $kop_hub_config  = function_exists('kop_hub_config') ? kop_hub_config($kop_hub_slug) : array();
    $kop_hub_content = !isset($kop_hub_config['content']) || $kop_hub_config['content'] !== false;
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-hub'); ?>>
    <div class="entry-content-wrap">

        <?php
        // One step, Home > this hub. It is what tells a reader who landed
        // here from a search engine that they are in a section of a site.
        // inc/article-parts.php.
        if (function_exists('kop_article_breadcrumbs')) {
            kop_article_breadcrumbs($kop_hub_slug, get_the_title());
        }
        ?>

        <header class="entry-header page-title kop-hub-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php
            // The excerpt when an editor wrote one; otherwise the hub's
            // settings (inc/hub-shell.php) or a module file on
            // 'kop_hub_standfirst' (inc/hub-posts.php) can supply one.
            $kop_hub_standfirst = has_excerpt() ? get_the_excerpt() : '';
            if ($kop_hub_standfirst === '' && !empty($kop_hub_config['standfirst'])) {
                $kop_hub_standfirst = $kop_hub_config['standfirst'];
            }
            $kop_hub_standfirst = (string) apply_filters('kop_hub_standfirst', $kop_hub_standfirst, $kop_hub_slug);
            if ($kop_hub_standfirst !== '') : ?>
                <p class="kop-hub-standfirst"><?php echo esc_html($kop_hub_standfirst); ?></p>
            <?php endif; ?>
            <?php
            if (function_exists('kop_hub_actions')) {
                kop_hub_actions($kop_hub_slug);
            }
            ?>
        </header>

        <?php if (has_post_thumbnail()) : ?>
            <div class="post-thumbnail kop-hub-thumbnail">
                <?php the_post_thumbnail('full'); ?>
            </div>
        <?php endif; ?>

        <?php if ($kop_hub_content) : ?>
            <div class="entry-content single-content">
                <?php
                if (function_exists('kop_hub_the_content')) {
                    kop_hub_the_content();
                } else {
                    the_content();
                }
                wp_link_pages(array(
                    'before' => '<div class="page-links">',
                    'after'  => '</div>',
                ));
                ?>
            </div>
        <?php endif; ?>

        <?php if ($kop_hub_module) : ?>
            <div class="kop-hub-modules<?php echo $kop_hub_content ? '' : ' kop-hub-modules--lead'; ?>">
                <?php call_user_func($kop_hub_module); ?>
            </div>
        <?php endif; ?>

        <?php
        if (function_exists('kop_hub_reading')) {
            kop_hub_reading($kop_hub_slug);
        }
        if (function_exists('kop_hub_contribute')) {
            kop_hub_contribute($kop_hub_slug);
        }
        ?>

        <footer class="kop-hub-footer">
            <div class="kop-hub-footer-meta">
                <span>Updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time></span>
                <?php edit_post_link('Edit this page', '<span class="kop-hub-edit">', '</span>'); ?>
            </div>
            <?php
            if (function_exists('kop_hub_share')) {
                kop_hub_share();
            }
            ?>
        </footer>

    </div>
</article>
    <?php
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
endwhile;

get_footer();
