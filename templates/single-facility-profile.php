<?php
/**
 * Template Name: Facility Profile
 * Template Post Type: post
 * Description: Wide layout for Facility Profile posts. The editor content is
 * printed exactly as stored through the_content(); the template only adds a
 * facts rail built from the post's custom fields, the facilities_master
 * record with the same name, and the lawsuits linked to that record.
 *
 * Nothing here reads or rewrites post_content, so switching a post to this
 * template (or back to Default) cannot lose anything the editor holds.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_fp_css_path = get_stylesheet_directory() . '/css/facility-profile.css';
if (file_exists($kop_fp_css_path)) {
    wp_enqueue_style(
        'kop-facility-profile',
        get_stylesheet_directory_uri() . '/css/facility-profile.css',
        array(),
        filemtime($kop_fp_css_path)
    );
}

if (!function_exists('kop_fp_meta')) {
    /** Trimmed string meta, or the raw value when it is an array. */
    function kop_fp_meta($post_id, $key) {
        $value = get_post_meta($post_id, $key, true);
        if (is_array($value)) {
            return array_values(array_filter(array_map('trim', array_map('strval', $value)), 'strlen'));
        }
        return is_string($value) ? trim($value) : '';
    }
}

if (!function_exists('kop_fp_strip_label')) {
    /**
     * Some profiles store the field label inside the value ("Founded: 1961",
     * "Founder: Joe Gauld"). Drop a leading "Word:" so the rail label is not
     * printed twice. Values that are only a label ("Founded:") become empty.
     */
    function kop_fp_strip_label($value) {
        $value = trim((string) $value);
        $value = preg_replace('/^[A-Za-z ]{3,20}:\s*/', '', $value);
        return trim($value);
    }
}

if (!function_exists('kop_fp_host_label')) {
    /** "linktr.ee/hydesurvivors" style label for an external link. */
    function kop_fp_host_label($url) {
        $host = wp_parse_url($url, PHP_URL_HOST);
        $path = wp_parse_url($url, PHP_URL_PATH);
        $host = $host ? preg_replace('/^www\./', '', $host) : $url;
        $path = $path ? rtrim($path, '/') : '';
        return $host . $path;
    }
}

if (!function_exists('kop_fp_facility_node')) {
    /**
     * facilities_master rows come in two shapes: {facility: ...} and the
     * per-facility {__facility_ref: true, data: {facility: ...}} rows. Return
     * the facility object either way.
     */
    function kop_fp_facility_node($decoded) {
        if (!is_array($decoded)) {
            return array();
        }
        if (isset($decoded['facility']) && is_array($decoded['facility'])) {
            return $decoded['facility'];
        }
        if (isset($decoded['data']['facility']) && is_array($decoded['data']['facility'])) {
            return $decoded['data']['facility'];
        }
        return array();
    }
}

$kop_fp_post_id = get_the_ID();

// ---- Custom fields (ACF stores plain meta, so no ACF dependency) ----------
$kop_fp_name          = kop_fp_meta($kop_fp_post_id, 'facility_name') ?: get_the_title();
$kop_fp_addresses     = array_values(array_filter(array(
    kop_fp_meta($kop_fp_post_id, 'facility_address'),
    kop_fp_meta($kop_fp_post_id, 'facility_address_2'),
    kop_fp_meta($kop_fp_post_id, 'facility_address_3'),
    kop_fp_meta($kop_fp_post_id, 'facility_address_4'),
), 'strlen'));
$kop_fp_founded       = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'date_founded'));
$kop_fp_closed        = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'date_closed'));
$kop_fp_founders      = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'founders'));
$kop_fp_previous_name = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'previous_name'));
$kop_fp_parent        = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'parent_company'));
$kop_fp_investors     = kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'investors'));
$kop_fp_external      = kop_fp_meta($kop_fp_post_id, 'external_profile');
$kop_fp_treatments    = kop_fp_meta($kop_fp_post_id, 'treatments');
$kop_fp_treatments    = is_array($kop_fp_treatments) ? $kop_fp_treatments : array();
$kop_fp_transfers     = array_values(array_filter(array(
    kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'staff_transfers')),
    kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'staff_transfers_2')),
    kop_fp_strip_label(kop_fp_meta($kop_fp_post_id, 'staff_transfers_3')),
), 'strlen'));

// ---- facilities_master record and linked lawsuits -------------------------
$kop_fp_record_id = 0;
$kop_fp_facility  = array();
$kop_fp_lawsuits  = array();
$kop_fp_config    = get_stylesheet_directory() . '/api/config.php';
if (file_exists($kop_fp_config)) {
    require_once $kop_fp_config;
}
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT id, json_data FROM facilities_master WHERE unique_name = ? LIMIT 1');
        $stmt->execute(array($kop_fp_name));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $kop_fp_record_id = (int) $row['id'];
            $kop_fp_facility  = kop_fp_facility_node(json_decode((string) $row['json_data'], true));
        }
        if ($kop_fp_record_id) {
            $stmt = $pdo->prepare(
                "SELECT l.id, l.case_name, l.filing_date, l.status, l.court
                 FROM lawsuit_facility_links lf
                 JOIN lawsuits l ON l.id = lf.lawsuit_id
                 WHERE lf.facility_id = ?
                   AND l.publication_status IN ('approved','published')
                 ORDER BY l.filing_date DESC, l.id DESC"
            );
            $stmt->execute(array($kop_fp_record_id));
            $kop_fp_lawsuits = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (Throwable $e) {
        $kop_fp_facility = array();
        $kop_fp_lawsuits = array();
    }
}

$kop_fp_status = '';
if (!empty($kop_fp_facility['operatingPeriod']['status'])) {
    $kop_fp_status = trim((string) $kop_fp_facility['operatingPeriod']['status']);
}
if ($kop_fp_founded === '' && !empty($kop_fp_facility['operatingPeriod']['startYear'])) {
    $kop_fp_founded = (string) $kop_fp_facility['operatingPeriod']['startYear'];
}
if ($kop_fp_closed === '' && !empty($kop_fp_facility['operatingPeriod']['endYear'])) {
    $kop_fp_closed = (string) $kop_fp_facility['operatingPeriod']['endYear'];
}
if (!$kop_fp_addresses && !empty($kop_fp_facility['address'])) {
    $kop_fp_addresses = array((string) $kop_fp_facility['address']);
}
$kop_fp_type   = !empty($kop_fp_facility['facilityDetails']['type']) ? (string) $kop_fp_facility['facilityDetails']['type'] : '';
$kop_fp_gender = !empty($kop_fp_facility['facilityDetails']['gender']) ? (string) $kop_fp_facility['facilityDetails']['gender'] : '';
$kop_fp_ages   = '';
if (!empty($kop_fp_facility['facilityDetails']['ageRange']['min']) || !empty($kop_fp_facility['facilityDetails']['ageRange']['max'])) {
    $kop_fp_ages = trim((string) ($kop_fp_facility['facilityDetails']['ageRange']['min'] ?? '') . ' to ' . (string) ($kop_fp_facility['facilityDetails']['ageRange']['max'] ?? ''), ' to');
}

// ---- State hub -------------------------------------------------------------
$kop_fp_state_abbrev = '';
if (!empty($kop_fp_facility['locationDetails']['state'])) {
    $kop_fp_state_abbrev = (string) $kop_fp_facility['locationDetails']['state'];
} elseif ($kop_fp_addresses && preg_match('/\b([A-Z]{2})\s+\d{5}/', $kop_fp_addresses[0], $m)) {
    $kop_fp_state_abbrev = $m[1];
}
$kop_fp_state_name = '';
$kop_fp_state_url  = '';
if ($kop_fp_state_abbrev && function_exists('kop_state_canonical_name')) {
    $kop_fp_state_name = kop_state_canonical_name($kop_fp_state_abbrev);
    if (strlen($kop_fp_state_name) <= 2) {
        $kop_fp_state_name = '';
    }
}
if ($kop_fp_state_name === '') {
    // Fall back to a category named after a state (the profiles are filed that way).
    foreach (get_the_category($kop_fp_post_id) as $cat) {
        if (function_exists('kop_state_slug_to_name') && kop_state_slug_to_name($cat->slug)) {
            $kop_fp_state_name = kop_state_slug_to_name($cat->slug);
            break;
        }
    }
}
if ($kop_fp_state_name !== '' && function_exists('kop_state_slug')) {
    $state_page = get_page_by_path(kop_state_slug($kop_fp_state_name));
    if ($state_page && $state_page->post_status === 'publish') {
        $kop_fp_state_url = get_permalink($state_page);
    }
}

// ---- Site links ----------------------------------------------------------------
$kop_fp_index_url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-tti-program-index.php') : '';
if (!$kop_fp_index_url) {
    $kop_fp_index_url = home_url('/tti-program-index/');
}
$kop_fp_record_url = $kop_fp_record_id ? add_query_arg('search', rawurlencode($kop_fp_name), $kop_fp_index_url) : '';

$kop_fp_lawsuits_url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-lawsuits.php') : '';
if (!$kop_fp_lawsuits_url) {
    $kop_fp_lawsuits_url = home_url('/lawsuits/');
}

$kop_fp_has_facts = $kop_fp_addresses || $kop_fp_founded || $kop_fp_closed || $kop_fp_founders
    || $kop_fp_parent || $kop_fp_investors || $kop_fp_treatments || $kop_fp_transfers
    || $kop_fp_type || $kop_fp_gender || $kop_fp_ages;

get_header();

while (have_posts()) :
    the_post();
    ?>
<article id="post-<?php the_ID(); ?>" <?php post_class('entry content-bg single-entry kop-facility-profile'); ?>>

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">
            Facility profile
            <?php if ($kop_fp_state_name !== '') : ?>
                <span aria-hidden="true">&middot;</span>
                <?php if ($kop_fp_state_url) : ?>
                    <a href="<?php echo esc_url($kop_fp_state_url); ?>"><?php echo esc_html($kop_fp_state_name); ?></a>
                <?php else : ?>
                    <?php echo esc_html($kop_fp_state_name); ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <h1 class="entry-title kop-fp-title"><?php the_title(); ?></h1>
        <?php if ($kop_fp_previous_name !== '') : ?>
            <p class="kop-fp-formerly">Formerly <?php echo esc_html($kop_fp_previous_name); ?></p>
        <?php endif; ?>
        <?php if ($kop_fp_status !== '') :
            $status_class = 'kop-fp-status--' . sanitize_html_class(strtolower($kop_fp_status));
            ?>
            <span class="kop-fp-status <?php echo esc_attr($status_class); ?>"><?php echo esc_html($kop_fp_status); ?></span>
        <?php endif; ?>
    </header>

    <div class="kop-fp-grid">

        <div class="entry-content single-content kop-fp-body">
            <?php if (has_post_thumbnail()) : ?>
                <figure class="kop-fp-figure post-thumbnail">
                    <?php the_post_thumbnail('full'); ?>
                </figure>
            <?php endif; ?>

            <?php
            the_content();
            wp_link_pages(array(
                'before' => '<div class="page-links">',
                'after'  => '</div>',
            ));
            ?>
        </div>

        <aside class="kop-fp-rail" aria-label="Facility facts">

            <?php if ($kop_fp_has_facts) : ?>
            <h2>At a glance</h2>
            <dl class="kop-fp-facts">
                <?php if ($kop_fp_addresses) : ?>
                    <div>
                        <dt><?php echo count($kop_fp_addresses) > 1 ? 'Addresses' : 'Address'; ?></dt>
                        <?php foreach ($kop_fp_addresses as $addr) : ?>
                            <dd><?php echo esc_html($addr); ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($kop_fp_founded !== '' || $kop_fp_closed !== '') : ?>
                    <div>
                        <dt>Operated</dt>
                        <dd>
                            <?php
                            if ($kop_fp_founded !== '' && $kop_fp_closed !== '') {
                                echo esc_html($kop_fp_founded . ' to ' . $kop_fp_closed);
                            } elseif ($kop_fp_founded !== '') {
                                echo esc_html($kop_fp_founded . ' to present');
                            } else {
                                echo esc_html('Closed ' . $kop_fp_closed);
                            }
                            ?>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($kop_fp_type !== '') : ?>
                    <div><dt>Type</dt><dd><?php echo esc_html($kop_fp_type); ?></dd></div>
                <?php endif; ?>
                <?php if ($kop_fp_ages !== '' || $kop_fp_gender !== '') : ?>
                    <div>
                        <dt>Serves</dt>
                        <dd><?php echo esc_html(trim(($kop_fp_gender ? $kop_fp_gender : '') . ($kop_fp_ages ? ', ages ' . $kop_fp_ages : ''), ', ')); ?></dd>
                    </div>
                <?php endif; ?>
                <?php if ($kop_fp_founders !== '') : ?>
                    <div><dt>Founded by</dt><dd><?php echo esc_html($kop_fp_founders); ?></dd></div>
                <?php endif; ?>
                <?php if ($kop_fp_parent !== '') : ?>
                    <div><dt>Parent company</dt><dd><?php echo esc_html($kop_fp_parent); ?></dd></div>
                <?php endif; ?>
                <?php if ($kop_fp_investors !== '') : ?>
                    <div><dt>Investors</dt><dd><?php echo esc_html($kop_fp_investors); ?></dd></div>
                <?php endif; ?>
                <?php if ($kop_fp_treatments) : ?>
                    <div>
                        <dt>Reported practices</dt>
                        <dd>
                            <ul class="kop-fp-chips">
                                <?php foreach ($kop_fp_treatments as $t) : ?>
                                    <li><?php echo esc_html($t); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </dd>
                    </div>
                <?php endif; ?>
                <?php if ($kop_fp_transfers) : ?>
                    <div>
                        <dt>Staff connections</dt>
                        <?php foreach ($kop_fp_transfers as $t) : ?>
                            <dd><?php echo esc_html($t); ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </dl>
            <?php endif; ?>

            <?php if ($kop_fp_lawsuits) : ?>
            <h2>Lawsuits</h2>
            <ul class="kop-fp-list">
                <?php foreach ($kop_fp_lawsuits as $case) :
                    $year = !empty($case['filing_date']) ? substr((string) $case['filing_date'], 0, 4) : '';
                    $bits = array_filter(array($year, $case['status'] ?? '', $case['court'] ?? ''), 'strlen');
                    ?>
                    <li>
                        <a href="<?php echo esc_url($kop_fp_lawsuits_url); ?>"><?php echo esc_html($case['case_name']); ?></a>
                        <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' &middot; ', $bits)); ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if ($kop_fp_record_url || $kop_fp_state_url || $kop_fp_external) : ?>
            <h2>Also see</h2>
            <ul class="kop-fp-list">
                <?php if ($kop_fp_record_url) : ?>
                    <li><a href="<?php echo esc_url($kop_fp_record_url); ?>">Database record</a><span class="meta">TTI Program Index</span></li>
                <?php endif; ?>
                <?php if ($kop_fp_state_url) : ?>
                    <li><a href="<?php echo esc_url($kop_fp_state_url); ?>"><?php echo esc_html($kop_fp_state_name); ?> hub</a><span class="meta">Every facility, lawsuit, and bill in the state</span></li>
                <?php endif; ?>
                <?php if ($kop_fp_external) : ?>
                    <li><a href="<?php echo esc_url($kop_fp_external); ?>" rel="noopener nofollow" target="_blank">Survivor community</a><span class="meta"><?php echo esc_html(kop_fp_host_label($kop_fp_external)); ?></span></li>
                <?php endif; ?>
            </ul>
            <?php endif; ?>

        </aside>
    </div>

    <footer class="kop-fp-footer">
        <span>Last updated <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>"><?php echo esc_html(get_the_modified_date()); ?></time>.</span>
        <a href="<?php echo esc_url(home_url('/tti-data-submission/')); ?>">Suggest a correction</a>
        <?php edit_post_link('Edit this profile', '<span class="kop-fp-edit">', '</span>'); ?>
    </footer>

    <?php
    // Pingbacks and reader comments stay with the post, as they did under the
    // stock layout.
    if (comments_open() || get_comments_number()) :
        ?>
        <div class="kop-fp-comments">
            <?php comments_template(); ?>
        </div>
    <?php endif; ?>

</article>
    <?php
endwhile;

get_footer();
