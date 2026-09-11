<?php
/**
 * Template Name: Hub Page
 * Description: The navigation hub pages (History, Survivors, Families, Law &
 * Policy, ...) keep their curated editor content; this template prints it
 * unchanged inside the same article markup Kadence uses, then appends a live
 * module chosen by page slug (kop_hub_module_for()). Pages with no module get
 * only the consistent header and footer.
 */

if (!defined('ABSPATH')) {
    exit;
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

if (!function_exists('kop_hub_page_url')) {
    /** Permalink of the page using a child template, with a fallback path. */
    function kop_hub_page_url($template, $fallback) {
        $url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template($template) : '';
        return $url ? $url : home_url($fallback);
    }
}

if (!function_exists('kop_hub_module_law_policy')) {
    /**
     * Law & Policy: live counts and the newest published lawsuits and bills,
     * each column linking to its directory.
     */
    function kop_hub_module_law_policy() {
        $pdo = kop_hub_pdo();
        if (!$pdo) {
            return;
        }
        $lawsuits = array();
        $bills    = array();
        $n_law    = 0;
        $n_bill   = 0;
        try {
            $n_law = (int) $pdo->query("SELECT COUNT(*) FROM lawsuits WHERE publication_status IN ('approved','published')")->fetchColumn();
            $stmt  = $pdo->query(
                "SELECT case_name, filing_date, status, jurisdiction FROM lawsuits
                 WHERE publication_status IN ('approved','published')
                 ORDER BY filing_date DESC, id DESC LIMIT 5"
            );
            $lawsuits = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();

            $n_bill = (int) $pdo->query("SELECT COUNT(*) FROM legislation WHERE publication_status IN ('approved','published')")->fetchColumn();
            $stmt   = $pdo->query(
                "SELECT bill_number, bill_title, jurisdiction, status, last_action_date, introduced_date FROM legislation
                 WHERE publication_status IN ('approved','published')
                 ORDER BY last_action_date DESC, introduced_date DESC, id DESC LIMIT 5"
            );
            $bills = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : array();
        } catch (Throwable $e) {
            return;
        }
        if (!$lawsuits && !$bills) {
            return;
        }
        $lawsuits_url    = kop_hub_page_url('page-lawsuits.php', '/lawsuits/');
        $legislation_url = kop_hub_page_url('page-legislation.php', '/legislative-efforts/');
        $label = static function ($s) {
            return ucfirst(str_replace('_', ' ', (string) $s));
        };
        ?>
        <section class="kop-hub-module kop-hub-law" aria-label="Latest lawsuits and legislation">
            <div class="kop-hub-col">
                <h2 class="kop-hub-h">Latest lawsuits <span class="kop-hub-count"><?php echo (int) $n_law; ?> tracked</span></h2>
                <ul class="kop-hub-list">
                    <?php foreach ($lawsuits as $c) : ?>
                        <li>
                            <a href="<?php echo esc_url($lawsuits_url); ?>"><?php echo esc_html($c['case_name']); ?></a>
                            <span class="kop-hub-meta"><?php echo esc_html(implode(" \u{00B7} ", array_filter(array(
                                $c['filing_date'] ? substr((string) $c['filing_date'], 0, 4) : '',
                                $c['jurisdiction'] ?? '',
                                $label($c['status'] ?? ''),
                            ), 'strlen'))); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="kop-hub-more" href="<?php echo esc_url($lawsuits_url); ?>">All lawsuits</a>
            </div>
            <div class="kop-hub-col">
                <h2 class="kop-hub-h">Latest legislation <span class="kop-hub-count"><?php echo (int) $n_bill; ?> bills tracked</span></h2>
                <ul class="kop-hub-list">
                    <?php foreach ($bills as $b) :
                        $when = $b['last_action_date'] ?: $b['introduced_date'];
                        ?>
                        <li>
                            <a href="<?php echo esc_url($legislation_url); ?>"><?php echo esc_html(trim($b['bill_number'] . ' ' . $b['bill_title'])); ?></a>
                            <span class="kop-hub-meta"><?php echo esc_html(implode(" \u{00B7} ", array_filter(array(
                                $b['jurisdiction'] ?? '',
                                $label($b['status'] ?? ''),
                                $when ? date_i18n('M j, Y', strtotime($when)) : '',
                            ), 'strlen'))); ?></span>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <a class="kop-hub-more" href="<?php echo esc_url($legislation_url); ?>">All legislation</a>
            </div>
        </section>
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
    $kop_hub_slug   = get_post_field('post_name', get_the_ID());
    $kop_hub_module = kop_hub_module_for($kop_hub_slug);
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-hub'); ?>>
    <div class="entry-content-wrap">

        <header class="entry-header page-title title-align-center kop-hub-header">
            <h1 class="entry-title"><?php the_title(); ?></h1>
            <?php if (has_excerpt()) : ?>
                <p class="kop-hub-standfirst"><?php echo esc_html(get_the_excerpt()); ?></p>
            <?php endif; ?>
        </header>

        <?php if (has_post_thumbnail()) : ?>
            <div class="post-thumbnail kop-hub-thumbnail">
                <?php the_post_thumbnail('full'); ?>
            </div>
        <?php endif; ?>

        <div class="entry-content single-content">
            <?php
            the_content();
            wp_link_pages(array(
                'before' => '<div class="page-links">',
                'after'  => '</div>',
            ));
            ?>
        </div>

        <?php if ($kop_hub_module) : ?>
            <div class="kop-hub-modules">
                <?php call_user_func($kop_hub_module); ?>
            </div>
        <?php endif; ?>

        <footer class="kop-hub-footer">
            <span>Updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time></span>
            <?php edit_post_link('Edit this page', '<span class="kop-hub-edit">', '</span>'); ?>
        </footer>

    </div>
</article>
    <?php
    if (comments_open() || get_comments_number()) {
        comments_template();
    }
endwhile;

get_footer();
