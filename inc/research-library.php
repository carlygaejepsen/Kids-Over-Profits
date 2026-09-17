<?php
/**
 * Research & Reports library.
 *
 * The /researchreports/ page used to carry a hand-built Gutenberg grid of
 * eleven cards (cover image, title link, "notes and quotes" line). This module
 * renders that same card grid from the FileBird folders instead, so every
 * document filed under Academia and Government shows up without anyone editing
 * the page, while the curated summary-page links from the old grid ride along
 * on the matching documents (kop_research_library_overrides()).
 *
 * The legacy grid block is suppressed at render time
 * (kop_research_strip_legacy_grid) rather than deleted, so nothing in the
 * editor is lost.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Page slug this library belongs to. */
if (!defined('KOP_RESEARCH_SLUG')) {
    define('KOP_RESEARCH_SLUG', 'researchreports');
}

/**
 * Capability required to edit a card in place. Matches the bar the document
 * folder pages already use for editor-only notices.
 */
if (!defined('KOP_RESEARCH_CAP')) {
    define('KOP_RESEARCH_CAP', 'edit_pages');
}

/**
 * Set on an attachment once someone has edited its card here. While it is set,
 * the attachment's own title and description win over the seed map below, so an
 * edit made in the UI is what shows. Deleting it restores the seed value.
 */
if (!defined('KOP_RESEARCH_EDITED_META')) {
    define('KOP_RESEARCH_EDITED_META', 'kop_research_edited');
}

/** Option holding edits to the entries that have no attachment behind them. */
if (!defined('KOP_RESEARCH_EXTERNAL_OPTION')) {
    define('KOP_RESEARCH_EXTERNAL_OPTION', 'kop_research_external_overrides');
}

/**
 * FileBird folders feeding the library: folder id => kind label shown on the
 * card badge. Filter 'kop_research_library_folders' to add a folder.
 */
function kop_research_library_folders() {
    return apply_filters('kop_research_library_folders', array(
        27 => 'Academic',
        25 => 'Government',
    ));
}

/**
 * Curated detail for individual documents, keyed by the file's basename
 * (lowercase, no extension) or by 'id:<attachment id>'.
 *
 * Every key is optional:
 *   title         - replaces the media title (use the publication's real title)
 *   byline        - author or issuing body, printed under the title
 *   year          - sorts the card and prints in the meta line
 *   summary       - page ID or URL on this site holding notes and key points
 *   summary_label - link text for that page
 *
 * The first block is the eleven cards from the page's original grid.
 */
function kop_research_library_overrides() {
    return apply_filters('kop_research_library_overrides', array(
        'senate-finance-committee-tti-report-2024' => array(
            'title'   => 'Warehouses of Neglect: How Taxpayers Are Funding Systemic Abuse in Youth Residential Treatment Facilities',
            'byline'  => 'U.S. Senate Committee on Finance',
            'year'    => 2024,
            'summary' => 134,
        ),
        'gao-2024-report' => array(
            'title'   => 'Child Welfare: Abuse of Youth Placed in Residential Facilities',
            'byline'  => 'U.S. Government Accountability Office',
            'year'    => 2024,
            'summary' => 490,
        ),
        'dhhs-2024-foster-care-monitoring-maltreatment' => array(
            'title'  => 'Many States Lack Information to Monitor Maltreatment in Residential Facilities for Children in Foster Care',
            'byline' => 'HHS Office of Inspector General',
            'year'   => 2024,
        ),
        'overt-covert-conversion-therapy' => array(
            'title'         => 'Overt and Covert Conversion Therapy Practices in Therapeutic Boarding Schools',
            'byline'        => 'Sarah Golightley',
            'year'          => 2023,
            'summary'       => 421,
            'summary_label' => 'Notes, quotes and summary',
        ),
        'gao-2022-tti-report' => array(
            'title'  => 'Child Welfare: HHS Should Facilitate Information Sharing Between States to Help Prevent and Address Maltreatment in Residential Facilities',
            'byline' => 'U.S. Government Accountability Office',
            'year'   => 2022,
        ),
        'fountain-of-youth' => array(
            'title'  => 'Fountain of Youth: Surviving Institutional Child Abuse in the Troubled Teen Industry',
            'byline' => 'Andrew Gordon Brown',
            'year'   => 2022,
        ),
        'gao-08-713t' => array(
            'title'  => 'Residential Programs: Selected Cases of Death, Abuse, and Deceptive Marketing',
            'byline' => 'U.S. Government Accountability Office',
            'year'   => 2008,
        ),

        // Folder documents whose media title is only a filename.
        'gao-seclusion-and-restraint' => array(
            'title'  => 'Seclusions and Restraints: Selected Cases of Death and Abuse at Public and Private Schools and Treatment Centers',
            'byline' => 'U.S. Government Accountability Office',
            'year'   => 2009,
        ),
        'chrg-110hhrg38055' => array(
            'byline' => 'U.S. House Committee on Education and Labor',
            'year'   => 2007,
        ),
        'abuse-and-neglect-of-children-in-institutions-congressional-hearing-1979' => array(
            'byline' => 'U.S. Senate',
            'year'   => 1979,
        ),
        'advisory_full-committee_june-26-2024-ways-and-means' => array(
            'byline' => 'U.S. House Committee on Ways and Means',
            'year'   => 2024,
        ),
        'la24-06-governmental-and-private-facilities-for-children-inspections-december-2022-report-final-website' => array(
            'title'  => 'Governmental and Private Facilities for Children: Inspections (LA24-06)',
            'byline' => 'Utah Office of the Legislative Auditor General',
            'year'   => 2022,
        ),
    ));
}

/**
 * Cards that have no file in either folder: the four entries from the original
 * grid that linked straight out to a publisher. Each carries its own cover
 * attachment ID, taken from the block markup it replaces, and a stable 'id'
 * the in-place editor stores its edits against.
 */
function kop_research_library_external() {
    return apply_filters('kop_research_library_external', array(
        array(
            'id'            => 'pesp-kids-are-not-alright',
            'title'         => 'The Kids Are Not Alright: How Private Equity Profits Off of Behavioral Health Services for Vulnerable and At-Risk Youth',
            'byline'        => 'Private Equity Stakeholder Project',
            'year'          => 2022,
            'kind'          => 'Report',
            'url'           => 'https://pestakeholder.org/reports/the-kids-are-not-alright-how-private-equity-profits-off-of-behavioral-health-services-for-vulnerable-and-at-risk-youth/',
            'cover'         => 509,
            'summary'       => 182,
            'summary_label' => 'Notes and quotes',
        ),
        array(
            'id'     => 'think-of-us-away-from-home',
            'title'  => 'Away From Home: Youth Experiences of Institutional Placements in Foster Care',
            'byline' => 'Think of Us',
            'year'   => 2021,
            'kind'   => 'Report',
            'url'    => 'https://www.thinkofus.org/case-studies/away-from-home',
            'cover'  => 512,
        ),
        array(
            'id'     => 'ndrn-desperation-without-dignity',
            'title'  => 'Desperation without Dignity: Conditions of Children Placed in For-Profit Residential Facilities',
            'byline' => 'National Disability Rights Network',
            'year'   => 2021,
            'kind'   => 'Report',
            'url'    => 'https://www.ndrn.org/resource/desperation-without-dignity/',
            'cover'  => 514,
        ),
        array(
            'id'            => 'gao-2007-residential-treatment',
            'title'         => 'Residential Treatment Programs: Concerns Regarding Abuse and Death in Certain Programs for Troubled Youth',
            'byline'        => 'U.S. Government Accountability Office',
            'year'          => 2007,
            'kind'          => 'Government',
            'url'           => 'https://www.gao.gov/products/gao-08-146t',
            'cover'         => 508,
            'summary'       => 329,
            'summary_label' => 'Notes, trigger warnings and more',
        ),
    ));
}

/**
 * Source documents for PDF preview images sitting in these folders.
 *
 * kop_get_folder_attachments() hides a "<name>-pdf.jpg" whose PDF is still in
 * the media library (see kop_get_hidden_preview_ids). Four Academia covers were
 * filed here while their PDF went to another folder, so hiding the cover made
 * the document vanish from this view entirely. Map each hidden preview back to
 * its document and pull that document in.
 *
 * @param int[] $folder_ids
 * @return WP_Post[]
 */
function kop_research_recover_sidecar_documents($folder_ids) {
    global $wpdb;

    $folder_ids = array_values(array_unique(array_map('intval', (array) $folder_ids)));
    if (empty($folder_ids) || !function_exists('kop_get_hidden_preview_ids')) {
        return array();
    }
    $hidden = array_map('intval', kop_get_hidden_preview_ids());
    if (empty($hidden)) {
        return array();
    }

    $table = $wpdb->prefix . 'fbv_attachment_folder';
    $f_ph  = implode(',', array_fill(0, count($folder_ids), '%d'));
    $h_ph  = implode(',', array_fill(0, count($hidden), '%d'));
    $in_folder = $wpdb->get_col($wpdb->prepare(
        "SELECT DISTINCT attachment_id FROM $table
         WHERE folder_id IN ($f_ph) AND attachment_id IN ($h_ph)",
        array_merge($folder_ids, $hidden)
    ));
    if (empty($in_folder)) {
        return array();
    }

    $doc_ids = array();
    foreach ($in_folder as $preview_id) {
        $file = (string) get_post_meta((int) $preview_id, '_wp_attached_file', true);
        if ($file === '') {
            continue;
        }
        if (!preg_match('/^(.*)-(pdf|docx?)(-[0-9]+)?(-scaled)?\.(jpe?g|png|webp)$/i', basename($file), $m)) {
            continue;
        }
        $stem   = $m[1] . '.' . strtolower($m[2]);
        $dir    = trim(dirname($file), '.');
        $target = ($dir !== '' ? $dir . '/' : '') . $stem;

        $doc_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT pm.post_id FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'
               AND pm.meta_value = %s LIMIT 1",
            $target
        ));
        if (!$doc_id) {
            // The 2026 restore filed some documents at the uploads root while
            // their preview kept a dated folder, and the other way round.
            $doc_id = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm
                 INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
                 WHERE pm.meta_key = '_wp_attached_file' AND p.post_type = 'attachment'
                   AND pm.meta_value LIKE %s LIMIT 1",
                '%' . $wpdb->esc_like($stem)
            ));
        }
        if ($doc_id) {
            $doc_ids[$doc_id] = (int) $preview_id;
        }
    }
    if (empty($doc_ids)) {
        return array();
    }

    $posts = get_posts(array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'posts_per_page' => -1,
        'post__in'       => array_keys($doc_ids),
        'orderby'        => 'title',
        'order'          => 'ASC',
    ));
    foreach ($posts as $p) {
        // Remember which preview stood in for it, so the card still has a cover
        // even where the document's own previews were never generated, and keep
        // the preview's title: the 2026 restore left many documents titled after
        // their raw filename while the preview got a readable version.
        $p->kop_sidecar_preview = $doc_ids[$p->ID];
        $p->kop_sidecar_title   = get_the_title($doc_ids[$p->ID]);
    }
    return $posts;
}

/**
 * True when an attachment's title is just its filename (underscores, hyphens
 * and all), which is how the 2026 media restore left a couple of thousand
 * records. See the restored-media note in the theme docs.
 */
function kop_research_is_filename_title($title, $file) {
    $norm = function ($text) {
        return preg_replace('/[^a-z0-9]+/', '', strtolower((string) $text));
    };
    $stem = pathinfo(basename((string) $file), PATHINFO_FILENAME);
    return $stem !== '' && $norm($title) === $norm($stem);
}

/**
 * Of two titles for the same file, is the candidate the more readable one?
 * Both are filename-derived here, so "readable" means it broke the filename
 * into words, or at least dropped the underscores.
 */
function kop_research_title_reads_better($candidate, $current) {
    $words = function ($text) {
        return count(preg_split('/\s+/', trim((string) $text), -1, PREG_SPLIT_NO_EMPTY));
    };
    if ($words($candidate) > $words($current)) {
        return true;
    }
    return strpos((string) $current, '_') !== false && strpos((string) $candidate, '_') === false;
}

/**
 * Bills and statutes are tracked in the Legislation section (page-legislation.php
 * and the legislation table), not here, so the Government folder's copies of them
 * are kept out of this library.
 *
 * Matches a bill number in the title or the filename: "California SB 524",
 * "Utah-SB-127", "HB 1234", joint and concurrent resolutions. Filter
 * 'kop_research_is_legislation' to force a document in or out.
 *
 * @param string $title
 * @param string $file  Attached file path or basename.
 * @return bool
 */
function kop_research_is_legislation($title, $file) {
    $pattern = '/\b(?:S|H|A)(?:B|R|JR|CR)[\s._-]?\d+\b/i';
    $is_bill = preg_match($pattern, (string) $title)
        || preg_match($pattern, basename((string) $file));

    return (bool) apply_filters('kop_research_is_legislation', $is_bill, $title, $file);
}

/** Last plausible publication year in a string, or 0. */
function kop_research_year_from($text) {
    if (is_string($text) && preg_match_all('/\b(19[3-9]\d|20[0-4]\d)\b/', $text, $m)) {
        return (int) end($m[1]);
    }
    return 0;
}

/**
 * Edits made to the entries that have no attachment behind them, as
 * id => array(title, description, cover_id). Stored in one option because
 * there is no post to hang meta on.
 */
function kop_research_external_edits() {
    $stored = get_option(KOP_RESEARCH_EXTERNAL_OPTION, array());
    return is_array($stored) ? $stored : array();
}

/** Permalink for a summary override given as a page ID or a URL. */
function kop_research_summary_url($summary) {
    if (is_numeric($summary)) {
        $url = get_permalink((int) $summary);
        return $url ? $url : '';
    }
    return is_string($summary) ? esc_url_raw($summary) : '';
}

/**
 * Every library card, newest first.
 *
 * @return array[] each with title, byline, year, kind, file_url, file_label,
 *                 file_size, cover, summary_url, summary_label
 */
function kop_research_library_items() {
    if (!function_exists('kop_get_folder_attachments')) {
        return array();
    }

    $overrides = kop_research_library_overrides();
    $items     = array();
    $seen      = array();

    foreach (kop_research_library_folders() as $folder_id => $kind) {
        $attachments = array_merge(
            kop_get_folder_attachments((int) $folder_id),
            kop_research_recover_sidecar_documents(array((int) $folder_id))
        );

        foreach ($attachments as $attachment) {
            if (isset($seen[$attachment->ID])) {
                continue;
            }
            $seen[$attachment->ID] = true;

            $file = (string) get_post_meta($attachment->ID, '_wp_attached_file', true);

            if (kop_research_is_legislation($attachment->post_title, $file)) {
                continue;
            }
            $base = strtolower(pathinfo(basename($file !== '' ? $file : (string) $attachment->post_title), PATHINFO_FILENAME));

            $override = array();
            if (isset($overrides['id:' . $attachment->ID])) {
                $override = $overrides['id:' . $attachment->ID];
            } elseif (isset($overrides[$base])) {
                $override = $overrides[$base];
            }

            // Once a card has been edited in place the attachment's own title
            // and description are what someone chose, so they beat the seed map.
            $edited = (string) get_post_meta($attachment->ID, KOP_RESEARCH_EDITED_META, true) !== '';

            $title = (!$edited && isset($override['title']))
                ? $override['title']
                : (string) $attachment->post_title;
            if ($edited || !isset($override['title'])) {
                if (!empty($attachment->kop_sidecar_title)
                    && kop_research_is_filename_title($title, $file)
                    && kop_research_title_reads_better($attachment->kop_sidecar_title, $title)) {
                    $title = (string) $attachment->kop_sidecar_title;
                }
                // Only clean up titles that are still the raw filename. A title
                // somebody typed is already cased the way the publication cases
                // it, and kop_title_case() would capitalise its "of" and "and".
                if (function_exists('kop_title_case') && kop_research_is_filename_title($title, $file)) {
                    $title = kop_title_case(str_replace(array('_', '-'), ' ', $title));
                }
            }

            // Academia captions are already written as "by Author, Year".
            $byline = isset($override['byline'])
                ? $override['byline']
                : trim(preg_replace('/^by\s+/i', '', (string) $attachment->post_excerpt));
            $byline = trim(preg_replace('/,\s*(19|20)\d{2}\s*$/', '', $byline));

            $year = isset($override['year']) ? (int) $override['year'] : 0;
            if (!$year) {
                $year = kop_research_year_from($attachment->post_excerpt);
            }
            if (!$year) {
                $year = kop_research_year_from($attachment->post_title);
            }
            if (!$year) {
                $year = kop_research_year_from($base);
            }

            $cover = function_exists('kop_get_attachment_preview_url')
                ? kop_get_attachment_preview_url($attachment->ID, 'large')
                : wp_get_attachment_image_url($attachment->ID, 'large');
            if (!$cover && !empty($attachment->kop_sidecar_preview)) {
                $cover = wp_get_attachment_image_url((int) $attachment->kop_sidecar_preview, 'large');
            }

            $file_url = wp_get_attachment_url($attachment->ID);
            $path     = get_attached_file($attachment->ID);
            $type     = wp_check_filetype((string) $file_url);

            $items[] = array(
                'key'           => 'att:' . $attachment->ID,
                'is_file'       => true,
                'mime'          => (string) $attachment->post_mime_type,
                'title'         => $title,
                'description'   => trim((string) $attachment->post_content),
                'byline'        => $byline,
                'year'          => $year,
                'kind'          => $kind,
                'file_url'      => $file_url,
                'file_label'    => $type['ext'] ? strtoupper($type['ext']) : 'File',
                'file_size'     => ($path && file_exists($path)) ? size_format(filesize($path)) : '',
                'cover'         => $cover,
                'cover_id'      => (int) get_post_meta($attachment->ID, 'kop_cover_image_id', true),
                'summary_url'   => isset($override['summary']) ? kop_research_summary_url($override['summary']) : '',
                'summary_label' => isset($override['summary_label']) ? $override['summary_label'] : 'Notes, quotes and key points',
            );
        }
    }

    $external_edits = kop_research_external_edits();

    foreach (kop_research_library_external() as $entry) {
        $edit     = isset($external_edits[$entry['id']]) ? $external_edits[$entry['id']] : array();
        $cover_id = isset($edit['cover_id']) ? (int) $edit['cover_id'] : (int) $entry['cover'];

        $items[] = array(
            'key'           => 'ext:' . $entry['id'],
            'is_file'       => false,
            'mime'          => '',
            'title'         => isset($edit['title']) ? $edit['title'] : $entry['title'],
            'description'   => isset($edit['description']) ? $edit['description'] : '',
            'byline'        => isset($entry['byline']) ? $entry['byline'] : '',
            'year'          => isset($entry['year']) ? (int) $entry['year'] : 0,
            'kind'          => isset($entry['kind']) ? $entry['kind'] : 'Report',
            'file_url'      => $entry['url'],
            'file_label'    => 'Publisher',
            'file_size'     => '',
            'cover'         => $cover_id ? wp_get_attachment_image_url($cover_id, 'large') : '',
            'cover_id'      => $cover_id,
            'summary_url'   => isset($entry['summary']) ? kop_research_summary_url($entry['summary']) : '',
            'summary_label' => isset($entry['summary_label']) ? $entry['summary_label'] : 'Notes, quotes and key points',
        );
    }

    usort($items, function ($a, $b) {
        if ($a['year'] !== $b['year']) {
            return $b['year'] - $a['year'];   // newest first, undated last
        }
        return strcasecmp($a['title'], $b['title']);
    });

    return $items;
}

/**
 * Hub module for /researchreports/: the merged card grid.
 */
function kop_hub_module_research() {
    $items = kop_research_library_items();
    if (empty($items)) {
        return;
    }
    $can_edit = current_user_can(KOP_RESEARCH_CAP);
    ?>
    <section class="kop-hub-module kop-research-library<?php echo $can_edit ? ' kop-rl-editable' : ''; ?>" aria-label="Research and reports library">
        <h2 class="kop-hub-h">Reports, studies and records
            <span class="kop-hub-count"><?php echo count($items); ?> documents</span>
            <?php if ($can_edit) : ?>
                <button type="button" class="kop-rl-edit-toggle" aria-pressed="false">Edit entries</button>
            <?php endif; ?>
        </h2>
        <ul class="kop-rl-grid">
            <?php
            foreach ($items as $item) :
                $primary  = $item['summary_url'] !== '' ? $item['summary_url'] : $item['file_url'];
                $off_site = ($item['summary_url'] === '');
                $meta     = array_filter(array($item['kind'], $item['year'] ? (string) $item['year'] : ''), 'strlen');

                // The Easy FancyBox plugin grabs every a[href*=".pdf"] on the
                // page and drops it into a small blank iframe. Opt these links
                // out with its documented .nofancybox class and hand them to the
                // theme's own document modal (js/document-library.js) instead,
                // which previews a PDF full size with Open and Download buttons.
                $doc_attrs = '';
                if ($item['is_file']) {
                    $doc_attrs = ' data-title="' . esc_attr($item['title']) . '"'
                        . ' data-mime="' . esc_attr($item['mime']) . '"'
                        . ' data-thumb="' . esc_url($item['cover']) . '"';
                }
                // Only a link that actually points at the file opens the modal.
                $primary_doc = $item['is_file'] && $off_site;
                $cls = function ($base, $is_doc) {
                    return trim($base . ($is_doc ? ' kop-rl-doc nofancybox' : ''));
                };
                ?>
                <li class="kop-rl-card" data-key="<?php echo esc_attr($item['key']); ?>" data-cover-id="<?php echo (int) $item['cover_id']; ?>">
                    <a class="<?php echo esc_attr($cls('kop-rl-cover', $primary_doc)); ?>" href="<?php echo esc_url($primary); ?>"<?php echo $primary_doc ? $doc_attrs : ''; ?><?php echo $off_site ? ' target="_blank" rel="noopener"' : ''; ?> tabindex="-1" aria-hidden="true">
                        <?php if ($item['cover']) : ?>
                            <img src="<?php echo esc_url($item['cover']); ?>" alt="" loading="lazy">
                        <?php else : ?>
                            <span class="kop-rl-noc"><?php echo esc_html($item['file_label']); ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if ($can_edit) : ?>
                        <button type="button" class="kop-rl-edit" data-key="<?php echo esc_attr($item['key']); ?>">
                            <span aria-hidden="true">&#9998;</span>
                            <span class="screen-reader-text">Edit <?php echo esc_attr($item['title']); ?></span>
                        </button>
                    <?php endif; ?>
                    <div class="kop-rl-body">
                        <?php if ($meta) : ?>
                            <span class="kop-rl-kind"><?php echo esc_html(implode(' / ', $meta)); ?></span>
                        <?php endif; ?>
                        <h3 class="kop-rl-title">
                            <a<?php echo $primary_doc ? ' class="kop-rl-doc nofancybox"' : ''; ?> href="<?php echo esc_url($primary); ?>"<?php echo $primary_doc ? $doc_attrs : ''; ?><?php echo $off_site ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($item['title']); ?></a>
                        </h3>
                        <?php if ($item['byline'] !== '') : ?>
                            <p class="kop-rl-byline"><?php echo esc_html($item['byline']); ?></p>
                        <?php endif; ?>
                        <p class="kop-rl-desc"<?php echo $item['description'] === '' ? ' hidden' : ''; ?>><?php echo esc_html($item['description']); ?></p>
                        <p class="kop-rl-links">
                            <?php if ($item['summary_url'] !== '') : ?>
                                <a class="kop-rl-summary" href="<?php echo esc_url($item['summary_url']); ?>"><?php echo esc_html($item['summary_label']); ?></a>
                            <?php endif; ?>
                            <a class="<?php echo esc_attr($cls('kop-rl-file', $item['is_file'])); ?>" href="<?php echo esc_url($item['file_url']); ?>"<?php echo $doc_attrs; ?> target="_blank" rel="noopener"><?php
                                echo esc_html($item['file_label'] . ($item['file_size'] !== '' ? ' / ' . $item['file_size'] : ''));
                            ?></a>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($can_edit) { kop_research_render_editor_dialog(); } ?>
    </section>
    <?php
}

add_filter('kop_hub_modules', 'kop_research_register_hub_module');
function kop_research_register_hub_module($modules) {
    $modules[KOP_RESEARCH_SLUG] = 'kop_hub_module_research';
    return $modules;
}

/* -- In-place editing ----------------------------------------------------- */

/**
 * The one dialog every card's pencil button opens. Printed once per page, for
 * editors only; js/research-library-editor.js fills it from the card that was
 * clicked and writes the result back into that card on save.
 */
function kop_research_render_editor_dialog() {
    ?>
    <dialog class="kop-rl-dialog" id="kop-rl-dialog" aria-label="Edit entry">
        <form method="dialog" class="kop-rl-form">
            <h3 class="kop-rl-form-h">Edit entry</h3>

            <label class="kop-rl-field">
                <span>Title</span>
                <input type="text" name="title" class="kop-rl-input-title" required maxlength="300">
            </label>

            <label class="kop-rl-field">
                <span>Description</span>
                <textarea name="description" class="kop-rl-input-desc" rows="4" maxlength="2000"
                          placeholder="A sentence or two about what this document says. Plain text."></textarea>
            </label>

            <div class="kop-rl-field">
                <span>Photo</span>
                <div class="kop-rl-photo">
                    <img class="kop-rl-photo-preview" src="" alt="">
                    <div class="kop-rl-photo-actions">
                        <button type="button" class="kop-rl-photo-pick">Choose image</button>
                        <button type="button" class="kop-rl-photo-clear">Use the document's own first page</button>
                    </div>
                </div>
            </div>

            <p class="kop-rl-form-msg" role="status" aria-live="polite"></p>

            <div class="kop-rl-form-actions">
                <button type="button" class="kop-rl-cancel">Cancel</button>
                <button type="button" class="kop-rl-save">Save</button>
            </div>
        </form>
    </dialog>
    <?php
}

/**
 * The document preview modal used by the library pages, so a card's PDF opens
 * in the same full-size viewer instead of the Easy FancyBox plugin's blank
 * iframe. Public — every visitor gets it.
 */
function kop_research_enqueue_doc_modal() {
    if (!is_page(KOP_RESEARCH_SLUG)) {
        return;
    }

    $dir = get_stylesheet_directory();
    $uri = get_stylesheet_directory_uri();

    if (file_exists($dir . '/css/document-library.css')) {
        wp_enqueue_style(
            'kop-document-library-style',
            $uri . '/css/document-library.css',
            array('kop-colors'),
            filemtime($dir . '/css/document-library.css')
        );
    }
    if (file_exists($dir . '/js/document-library.js')) {
        wp_enqueue_script(
            'kop-document-library-script',
            $uri . '/js/document-library.js',
            array('jquery'),
            filemtime($dir . '/js/document-library.js'),
            true
        );
    }
}
add_action('wp_enqueue_scripts', 'kop_research_enqueue_doc_modal');

/**
 * Editor assets, only on this page and only for users who can edit it.
 * wp_enqueue_media() brings in the media library modal the photo picker uses.
 */
function kop_research_enqueue_editor() {
    if (!is_page(KOP_RESEARCH_SLUG) || !current_user_can(KOP_RESEARCH_CAP)) {
        return;
    }

    wp_enqueue_media();

    $relative = '/js/research-library-editor.js';
    $path     = get_stylesheet_directory() . $relative;
    wp_enqueue_script(
        'kop-research-library-editor',
        get_stylesheet_directory_uri() . $relative,
        array(),
        file_exists($path) ? filemtime($path) : time(),
        true
    );
    wp_localize_script('kop-research-library-editor', 'KOP_RESEARCH_EDITOR', array(
        'endpoint' => esc_url_raw(rest_url('kop/v1/research-entry')),
        'nonce'    => wp_create_nonce('wp_rest'),
    ));
}
add_action('wp_enqueue_scripts', 'kop_research_enqueue_editor');

/**
 * POST kop/v1/research-entry
 *
 * Body: key ("att:<id>" or "ext:<slug>"), title, description, cover_id.
 * cover_id 0 clears the override so the card falls back to the document's own
 * generated first page.
 */
function kop_research_register_rest() {
    register_rest_route(
        'kop/v1',
        '/research-entry',
        array(
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => 'kop_research_save_entry',
            'permission_callback' => function () {
                return current_user_can(KOP_RESEARCH_CAP);
            },
            'args' => array(
                'key' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'title' => array(
                    'required'          => true,
                    'type'              => 'string',
                    'sanitize_callback' => 'sanitize_text_field',
                ),
                'description' => array(
                    'required'          => false,
                    'type'              => 'string',
                    'default'           => '',
                    // Plain text only: the host firewall rejects POST bodies
                    // carrying HTML (see the WAF note in the theme docs).
                    'sanitize_callback' => 'sanitize_textarea_field',
                ),
                'cover_id' => array(
                    'required'          => false,
                    'type'              => 'integer',
                    'default'           => 0,
                    'sanitize_callback' => 'absint',
                ),
            ),
        )
    );
}
add_action('rest_api_init', 'kop_research_register_rest');

/** Save one card's title, description and photo. */
function kop_research_save_entry($request) {
    $key         = (string) $request->get_param('key');
    $title       = trim((string) $request->get_param('title'));
    $description = trim((string) $request->get_param('description'));
    $cover_id    = (int) $request->get_param('cover_id');

    if ($title === '') {
        return new WP_Error('kop_research_title', 'A title is required.', array('status' => 400));
    }
    if ($cover_id && get_post_type($cover_id) !== 'attachment') {
        return new WP_Error('kop_research_cover', 'That photo is not in the media library.', array('status' => 400));
    }

    if (strpos($key, 'att:') === 0) {
        $id = (int) substr($key, 4);
        if (get_post_type($id) !== 'attachment') {
            return new WP_Error('kop_research_key', 'No such document.', array('status' => 404));
        }
        if (!current_user_can('edit_post', $id)) {
            return new WP_Error('kop_research_cap', 'You cannot edit that document.', array('status' => 403));
        }

        wp_update_post(array(
            'ID'           => $id,
            'post_title'   => $title,
            'post_content' => $description,
        ));
        if ($cover_id) {
            update_post_meta($id, 'kop_cover_image_id', $cover_id);
        } else {
            delete_post_meta($id, 'kop_cover_image_id');
        }
        // Marks the attachment's own fields as authoritative from now on.
        update_post_meta($id, KOP_RESEARCH_EDITED_META, current_time('mysql'));

        $cover = function_exists('kop_get_attachment_preview_url')
            ? kop_get_attachment_preview_url($id, 'large')
            : wp_get_attachment_image_url($id, 'large');

    } elseif (strpos($key, 'ext:') === 0) {
        $id    = substr($key, 4);
        $known = wp_list_pluck(kop_research_library_external(), 'id');
        if (!in_array($id, $known, true)) {
            return new WP_Error('kop_research_key', 'No such entry.', array('status' => 404));
        }

        $edits      = kop_research_external_edits();
        $edits[$id] = array(
            'title'       => $title,
            'description' => $description,
            'cover_id'    => $cover_id,
        );
        update_option(KOP_RESEARCH_EXTERNAL_OPTION, $edits, false);

        $cover = $cover_id ? wp_get_attachment_image_url($cover_id, 'large') : '';

    } else {
        return new WP_Error('kop_research_key', 'Unrecognised entry.', array('status' => 400));
    }

    // The hidden-preview list keys off attachment titles and files; drop it so
    // the next render rebuilds against what was just saved.
    delete_transient('kop_hidden_preview_ids');

    return rest_ensure_response(array(
        'ok'          => true,
        'key'         => $key,
        'title'       => $title,
        'description' => $description,
        'cover_id'    => $cover_id,
        'cover'       => $cover ? $cover : '',
    ));
}

/**
 * Drop the legacy card grid from the page's own content.
 *
 * The eleven cards it holds are all in the library above now, so leaving the
 * block in would print each of them twice. The block itself stays in the
 * editor untouched; delete it there and this filter becomes a no-op.
 *
 * Runs at priority 8, before do_blocks() (priority 9), so the block markup is
 * still parseable.
 */
function kop_research_strip_legacy_grid($content) {
    if (!is_singular('page') || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    if (get_post_field('post_name', get_the_ID()) !== KOP_RESEARCH_SLUG) {
        return $content;
    }
    if (strpos($content, '<!-- wp:group') === false) {
        return $content;
    }

    $blocks = parse_blocks($content);
    $kept   = array();
    foreach ($blocks as $block) {
        $name   = isset($block['blockName']) ? $block['blockName'] : '';
        $layout = isset($block['attrs']['layout']['type']) ? $block['attrs']['layout']['type'] : '';
        if ($name === 'core/group' && $layout === 'grid') {
            continue;
        }
        $kept[] = $block;
    }
    if (count($kept) === count($blocks)) {
        return $content;
    }
    return serialize_blocks($kept);
}
add_filter('the_content', 'kop_research_strip_legacy_grid', 8);
