<?php
/**
 * The Document Archive (/document-archive/, templates/page-document-archive.php).
 *
 * The page used to be eight FileBird document-library blocks stacked one
 * after another, each a paged grid of one folder, with nothing saying which
 * of the several thousand documents matter or how the rest are organized.
 * It is now a front door to the whole library:
 *
 *   Start here     a few documents to read first (research library items an
 *                  editor rated "Start here", else kop_doc_archive_featured())
 *   By type        government reports, legislation, research, handbooks...
 *   Networks       the chains and trade groups with the largest archives
 *   Every program  each top-level folder A to Z, filterable
 *   Recently added the newest documents filed anywhere
 *
 * Every collection opens on the same page at ?collection=<folder id>, using
 * the theme's own folder renderer (the [filebird_folder] shortcode), so there
 * is one archive URL to link to and nothing depends on the FileBird blocks.
 * The page's old blocks are skipped at render time, not deleted.
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!defined('KOP_DOC_ARCHIVE_SLUG')) {
    define('KOP_DOC_ARCHIVE_SLUG', 'document-archive');
}

/**
 * Collections by kind of document. folder => FileBird folder id; url => a
 * page that already presents the material better than a folder listing.
 * accent picks the colour of the card's top edge.
 */
function kop_doc_archive_types() {
    return apply_filters('kop_doc_archive_types', array(
        array('label' => 'Government reports', 'folder' => 25, 'accent' => 'navy',
              'desc' => 'Congressional investigations, GAO and Inspector General reports, state audits.'),
        array('label' => 'Legislation', 'folder' => 51, 'accent' => 'teal',
              'desc' => 'Bills, testimony and statutes on youth residential programs.'),
        array('label' => 'Academic research', 'folder' => 27, 'accent' => 'orange',
              'desc' => 'Peer-reviewed studies, theses and books on the industry and its methods.'),
        array('label' => 'Program handbooks', 'folder' => 64, 'accent' => 'coral',
              'desc' => 'Rulebooks, level systems and parent manuals, in the programs\' own words.'),
        array('label' => 'Transport companies', 'folder' => 207, 'accent' => 'mint',
              'desc' => 'Records of the services hired to take children to programs.'),
        array('label' => 'Woodbury Reports', 'folder' => 8, 'accent' => 'chartreuse',
              'desc' => 'The educational consultants\' trade newsletter, which listed and promoted programs.'),
        array('label' => 'Court records', 'url_template' => 'page-lawsuits.php', 'url_fallback' => '/lawsuits/', 'accent' => 'navy',
              'desc' => 'Complaints, motions and rulings, filed with each lawsuit we track.'),
    ));
}

/** Chains, networks and trade groups whose folders hold the largest archives. */
function kop_doc_archive_networks() {
    return apply_filters('kop_doc_archive_networks', array(7, 22, 150, 11, 38, 18, 5, 47, 20, 12, 19, 37));
}

/**
 * The fallback "Start here" row, by title, until an editor rates documents in
 * the research library (tier 1 there wins). note => why it is worth reading.
 */
function kop_doc_archive_featured() {
    return apply_filters('kop_doc_archive_featured', array(
        'Warehouses of Neglect'                                   => 'The 2024 Senate investigation into how the largest operators are paid to house children in foster care.',
        'Child Welfare: Abuse of Youth Placed in Residential'     => 'The federal auditors\' 2024 review of abuse in residential facilities.',
        'Residential Treatment Programs: Concerns Regarding Abuse' => 'The 2007 congressional investigation that first documented deaths and abuse across the industry.',
        'The Kids Are Not Alright'                                => 'How private equity firms profit from behavioral health services for young people.',
    ));
}

/** Folder names that are site plumbing rather than an archive. */
function kop_doc_archive_skip_names() {
    return array('blog', 'stock photos', 'recent user submissions', 'uncategorized', 'google drive');
}

/** A filename-slug title ("gao-08-713t") read as words. */
function kop_doc_archive_title($title) {
    $title = trim(html_entity_decode((string) $title, ENT_QUOTES, 'UTF-8'));
    if ($title !== '' && strpos($title, ' ') === false && preg_match('/[-_]/', $title)) {
        $title = ucfirst(trim(preg_replace('/[-_]+/', ' ', $title)));
    }
    return $title;
}

/** Up to $n cover image URLs for a folder's documents, documents with a preview first. */
function kop_doc_archive_covers($folder_id, $n = 3) {
    if (!function_exists('kop_get_folder_attachments') || !function_exists('kop_get_attachment_preview_url')) {
        return array();
    }
    $covers = array();
    $attachments = kop_get_folder_attachments((int) $folder_id);
    // PDFs first: their previews are pages, which read as documents; a
    // photograph filed alongside reads as a photograph.
    usort($attachments, function ($a, $b) {
        $pa = strpos((string) $a->post_mime_type, 'pdf') !== false ? 0 : 1;
        $pb = strpos((string) $b->post_mime_type, 'pdf') !== false ? 0 : 1;
        return $pa - $pb;
    });
    foreach ($attachments as $att) {
        $url = kop_get_attachment_preview_url($att->ID, 'medium');
        if ($url) {
            $covers[] = $url;
            if (count($covers) >= $n) {
                break;
            }
        }
    }
    return $covers;
}

/**
 * Everything the landing view needs, cached: counting and finding covers
 * walks the whole folder table. Cleared when an attachment is added or
 * removed, and every six hours.
 */
function kop_doc_archive_data() {
    $cached = get_transient('kop_doc_archive_v1');
    if (is_array($cached)) {
        return $cached;
    }
    global $wpdb;

    $folders = function_exists('kop_get_filebird_folders') ? kop_get_filebird_folders() : array();
    $folders = function_exists('kop_attach_folder_file_counts') ? kop_attach_folder_file_counts($folders) : array();
    $by_id = array();
    foreach ($folders as $f) {
        $by_id[(int) $f['id']] = $f;
    }

    $types = array();
    $type_ids = array();
    foreach (kop_doc_archive_types() as $type) {
        $row = array('label' => $type['label'], 'desc' => $type['desc'], 'accent' => $type['accent'],
                     'folder' => 0, 'count' => 0, 'covers' => array(), 'url' => '');
        if (!empty($type['folder'])) {
            $fid = (int) $type['folder'];
            if (!isset($by_id[$fid]) || (int) $by_id[$fid]['files'] === 0) {
                continue;
            }
            $type_ids[$fid] = true;
            $row['folder'] = $fid;
            $row['count']  = (int) $by_id[$fid]['files'];
            $row['covers'] = kop_doc_archive_covers($fid, 3);
        } else {
            $row['url'] = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template($type['url_template']) : '';
            if ($row['url'] === '') {
                $row['url'] = home_url($type['url_fallback']);
            }
        }
        $types[] = $row;
    }

    // Every top-level folder, same-named duplicates folded into the copy that
    // holds the most documents (the tree carries many empty duplicates).
    $skip = kop_doc_archive_skip_names();
    $programs = array();
    foreach ($folders as $f) {
        if ((int) $f['parent'] !== 0 || (int) $f['files'] === 0 || isset($type_ids[(int) $f['id']])) {
            continue;
        }
        $name = trim(html_entity_decode((string) $f['name'], ENT_QUOTES, 'UTF-8'));
        $key  = strtolower($name);
        if ($name === '' || in_array($key, $skip, true)) {
            continue;
        }
        if (!isset($programs[$key]) || (int) $f['files'] > $programs[$key]['best']) {
            $total = isset($programs[$key]) ? $programs[$key]['count'] : 0;
            $programs[$key] = array('name' => $name, 'folder' => (int) $f['id'], 'best' => (int) $f['files'], 'count' => $total);
        }
        $programs[$key]['count'] += (int) $f['files'];
    }
    uasort($programs, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });

    $networks = array();
    foreach (kop_doc_archive_networks() as $fid) {
        if (!isset($by_id[$fid]) || (int) $by_id[$fid]['files'] === 0) {
            continue;
        }
        $key = strtolower(trim(html_entity_decode((string) $by_id[$fid]['name'], ENT_QUOTES, 'UTF-8')));
        $networks[] = array(
            'name'   => isset($programs[$key]) ? $programs[$key]['name'] : $by_id[$fid]['name'],
            'folder' => isset($programs[$key]) ? $programs[$key]['folder'] : $fid,
            'count'  => isset($programs[$key]) ? $programs[$key]['count'] : (int) $by_id[$fid]['files'],
            'covers' => kop_doc_archive_covers($fid, 1),
        );
    }

    // Distinct documents filed in any folder; hidden PDF preview images do not count.
    $hidden = function_exists('kop_get_hidden_preview_ids') ? kop_get_hidden_preview_ids() : array();
    $not_hidden = $hidden ? ' AND p.ID NOT IN (' . implode(',', array_map('intval', $hidden)) . ')' : '';
    $rel = $wpdb->prefix . 'fbv_attachment_folder';
    $total = (int) $wpdb->get_var(
        "SELECT COUNT(DISTINCT af.attachment_id) FROM $rel af
         INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id
         WHERE p.post_type = 'attachment'{$not_hidden}"
    );

    // Newest documents (not images) filed in a folder that is not plumbing.
    $recent = array();
    $rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title, p.post_date, p.post_mime_type, MIN(af.folder_id) AS folder_id
         FROM $rel af INNER JOIN {$wpdb->posts} p ON p.ID = af.attachment_id
         WHERE p.post_type = 'attachment' AND p.post_mime_type NOT LIKE 'image/%'{$not_hidden}
         GROUP BY p.ID ORDER BY p.post_date DESC LIMIT 40"
    );
    foreach ((array) $rows as $r) {
        $fid = (int) $r->folder_id;
        $fname = isset($by_id[$fid]) ? trim(html_entity_decode((string) $by_id[$fid]['name'], ENT_QUOTES, 'UTF-8')) : '';
        if ($fname === '' || in_array(strtolower($fname), $skip, true)) {
            continue;
        }
        $recent[] = array(
            'id'     => (int) $r->ID,
            'title'  => kop_doc_archive_title($r->post_title),
            'date'   => (string) $r->post_date,
            'mime'   => (string) $r->post_mime_type,
            'url'    => (string) wp_get_attachment_url((int) $r->ID),
            'thumb'  => function_exists('kop_get_attachment_preview_url') ? kop_get_attachment_preview_url((int) $r->ID, 'thumbnail') : '',
            'folder' => $fid,
            'fname'  => $fname,
        );
        if (count($recent) >= 6) {
            break;
        }
    }

    $data = array(
        'total'    => $total,
        'types'    => $types,
        'networks' => $networks,
        'programs' => array_values($programs),
        'recent'   => $recent,
    );
    set_transient('kop_doc_archive_v1', $data, 6 * HOUR_IN_SECONDS);
    return $data;
}

add_action('add_attachment', 'kop_doc_archive_flush');
add_action('delete_attachment', 'kop_doc_archive_flush');
function kop_doc_archive_flush() {
    delete_transient('kop_doc_archive_v1');
}

/** The "Start here" row: rated research library items, else the fallback titles. */
function kop_doc_archive_featured_items() {
    if (!function_exists('kop_research_library_items')) {
        return array();
    }
    $items = kop_research_library_items();
    $rated = array_values(array_filter($items, function ($i) { return (int) $i['relevance'] === 1 && empty($i['missing']); }));
    if ($rated) {
        return array_slice($rated, 0, 4);
    }
    $out = array();
    foreach (kop_doc_archive_featured() as $needle => $note) {
        foreach ($items as $item) {
            if (empty($item['missing']) && stripos($item['title'], $needle) === 0) {
                if ($item['relevance_note'] === '') {
                    $item['relevance_note'] = $note;
                }
                $out[] = $item;
                break;
            }
        }
    }
    return $out;
}

/** Link to a collection on the archive page. */
function kop_doc_archive_collection_url($folder_id) {
    $page = get_page_by_path(KOP_DOC_ARCHIVE_SLUG);
    $base = $page ? get_permalink($page) : home_url('/' . KOP_DOC_ARCHIVE_SLUG . '/');
    return add_query_arg('collection', (int) $folder_id, $base);
}

/** The folder a ?collection= request names, or null when it is not a real folder. */
function kop_doc_archive_requested_folder() {
    $id = isset($_GET['collection']) ? absint($_GET['collection']) : 0;
    if (!$id || !function_exists('kop_get_filebird_folders')) {
        return null;
    }
    foreach (kop_get_filebird_folders() as $f) {
        if ((int) $f->id === $id) {
            return array('id' => $id, 'name' => trim(html_entity_decode((string) $f->name, ENT_QUOTES, 'UTF-8')));
        }
    }
    return null;
}

/** "Government reports - Document Archive" as the browser title of a collection view. */
add_filter('document_title_parts', 'kop_doc_archive_title_parts');
function kop_doc_archive_title_parts($parts) {
    if (is_page(KOP_DOC_ARCHIVE_SLUG) && ($folder = kop_doc_archive_requested_folder())) {
        $parts['title'] = $folder['name'] . ' documents';
    }
    return $parts;
}

/** The page's old FileBird blocks are the archive this replaces. */
add_filter('pre_render_block', 'kop_doc_archive_skip_blocks', 10, 2);
function kop_doc_archive_skip_blocks($pre, $block) {
    if ($pre === null && !empty($block['blockName']) && $block['blockName'] === 'filebird/document-library'
        && is_page(KOP_DOC_ARCHIVE_SLUG)) {
        return '';
    }
    return $pre;
}

/** Stacked covers, or a plain paper shape when a collection has none. */
function kop_doc_archive_render_stack($covers) {
    echo '<span class="kop-da-stack" aria-hidden="true">';
    if (!$covers) {
        echo '<span class="kop-da-sheet kop-da-sheet-blank"></span><span class="kop-da-sheet kop-da-sheet-blank"></span><span class="kop-da-sheet kop-da-sheet-blank"></span>';
    } else {
        foreach (array_reverse($covers) as $url) {
            echo '<span class="kop-da-sheet"><img src="' . esc_url($url) . '" alt="" loading="lazy"></span>';
        }
    }
    echo '</span>';
}

/** Attributes that hand a file link to the theme's document viewer (js/document-library.js). */
function kop_doc_archive_doc_attrs($title, $mime, $thumb) {
    return ' class="kop-rl-doc nofancybox" data-title="' . esc_attr($title) . '" data-mime="' . esc_attr($mime) . '" data-thumb="' . esc_url($thumb) . '"';
}

/** The landing view. */
function kop_doc_archive_render_landing() {
    $data     = kop_doc_archive_data();
    $featured = kop_doc_archive_featured_items();
    $submit   = get_page_by_path('anon-submit');
    $research = get_page_by_path('researchreports');
    ?>
    <div class="kop-da-stats" role="list">
        <span class="kop-da-stat" role="listitem"><strong><?php echo esc_html(number_format_i18n($data['total'])); ?></strong> documents</span>
        <span class="kop-da-stat" role="listitem"><strong><?php echo esc_html(number_format_i18n(count($data['programs']))); ?></strong> program and company archives</span>
        <span class="kop-da-stat" role="listitem">Free to read and download</span>
    </div>

    <?php if ($featured) : ?>
    <section class="kop-da-section kop-da-featured" aria-labelledby="kop-da-start">
        <h2 id="kop-da-start" class="kop-da-h">Start here</h2>
        <p class="kop-da-lede">New to this? These reports are the clearest account of what happens inside youth residential programs and why it keeps happening.</p>
        <ul class="kop-da-feature-grid">
            <?php foreach ($featured as $item) :
                $off_site = !$item['is_file'];
                $href = $item['summary_url'] !== '' ? $item['summary_url'] : $item['file_url'];
                ?>
                <li class="kop-da-feature">
                    <a class="kop-da-feature-cover" href="<?php echo esc_url($href); ?>" tabindex="-1" aria-hidden="true"<?php echo ($off_site && $item['summary_url'] === '') ? ' target="_blank" rel="noopener"' : ''; ?>>
                        <?php if ($item['cover']) : ?>
                            <img src="<?php echo esc_url($item['cover']); ?>" alt="" loading="lazy">
                        <?php else : ?>
                            <span class="kop-da-sheet-blank"></span>
                        <?php endif; ?>
                    </a>
                    <div class="kop-da-feature-body">
                        <p class="kop-da-kicker"><?php echo esc_html(implode(' / ', array_filter(array($item['byline'], $item['year'] ? (string) $item['year'] : ''), 'strlen'))); ?></p>
                        <h3 class="kop-da-feature-title"><a href="<?php echo esc_url($href); ?>"<?php echo ($off_site && $item['summary_url'] === '') ? ' target="_blank" rel="noopener"' : ''; ?>><?php echo esc_html($item['title']); ?></a></h3>
                        <?php if ($item['relevance_note'] !== '') : ?>
                            <p class="kop-da-feature-why"><?php echo esc_html($item['relevance_note']); ?></p>
                        <?php endif; ?>
                        <p class="kop-da-feature-links">
                            <?php if ($item['summary_url'] !== '') : ?>
                                <a href="<?php echo esc_url($item['summary_url']); ?>"><?php echo esc_html($item['summary_label']); ?></a>
                            <?php endif; ?>
                            <?php if ($item['is_file']) : ?>
                                <a href="<?php echo esc_url($item['file_url']); ?>"<?php echo kop_doc_archive_doc_attrs($item['title'], $item['mime'], $item['cover']); ?> target="_blank" rel="noopener">Read the <?php echo esc_html($item['file_label']); ?></a>
                            <?php else : ?>
                                <a href="<?php echo esc_url($item['file_url']); ?>" target="_blank" rel="noopener">Read it at the publisher</a>
                            <?php endif; ?>
                        </p>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php if ($research) : ?>
            <a class="kop-da-more" href="<?php echo esc_url(get_permalink($research)); ?>">All reports and studies, with notes</a>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($data['types']) : ?>
    <section class="kop-da-section" aria-labelledby="kop-da-types">
        <h2 id="kop-da-types" class="kop-da-h">Browse by type</h2>
        <ul class="kop-da-type-grid">
            <?php foreach ($data['types'] as $type) :
                $href = $type['folder'] ? kop_doc_archive_collection_url($type['folder']) : $type['url'];
                ?>
                <li class="kop-da-type kop-da-accent-<?php echo esc_attr($type['accent']); ?>">
                    <a class="kop-da-type-link" href="<?php echo esc_url($href); ?>">
                        <?php kop_doc_archive_render_stack($type['covers']); ?>
                        <span class="kop-da-type-text">
                            <span class="kop-da-type-name"><?php echo esc_html($type['label']); ?></span>
                            <?php if ($type['count']) : ?>
                                <span class="kop-da-count"><?php echo esc_html(number_format_i18n($type['count'])); ?> documents</span>
                            <?php endif; ?>
                            <span class="kop-da-type-desc"><?php echo esc_html($type['desc']); ?></span>
                        </span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($data['networks']) : ?>
    <section class="kop-da-section" aria-labelledby="kop-da-networks">
        <h2 id="kop-da-networks" class="kop-da-h">Networks, chains and trade groups</h2>
        <p class="kop-da-lede">The largest archives belong to the organizations that ran, referred to or vouched for many programs at once.</p>
        <ul class="kop-da-network-grid">
            <?php foreach ($data['networks'] as $net) : ?>
                <li>
                    <a class="kop-da-network" href="<?php echo esc_url(kop_doc_archive_collection_url($net['folder'])); ?>">
                        <span class="kop-da-network-cover" aria-hidden="true"><?php if ($net['covers']) : ?><img src="<?php echo esc_url($net['covers'][0]); ?>" alt="" loading="lazy"><?php endif; ?></span>
                        <span class="kop-da-network-name"><?php echo esc_html($net['name']); ?></span>
                        <span class="kop-da-count"><?php echo esc_html(number_format_i18n($net['count'])); ?></span>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($data['programs']) : ?>
    <section class="kop-da-section kop-da-programs" aria-labelledby="kop-da-all">
        <h2 id="kop-da-all" class="kop-da-h">Every program archive, A to Z</h2>
        <div class="kop-da-filter" hidden>
            <label for="kop-da-filter-input">Find a program or company</label>
            <input id="kop-da-filter-input" type="search" autocomplete="off" placeholder="Type a name, e.g. Provo">
            <p class="kop-da-filter-empty" hidden>No archive by that name. Try part of the name, or <a href="<?php echo esc_url(home_url('/?s=')); ?>">search the whole site</a>.</p>
        </div>
        <?php
        $letters = array();
        foreach ($data['programs'] as $p) {
            $l = strtoupper(substr(preg_replace('/^(the|a)\s+/i', '', $p['name']), 0, 1));
            $letters[ctype_alpha($l) ? $l : '#'][] = $p;
        }
        ksort($letters);
        ?>
        <div class="kop-da-az">
            <?php foreach ($letters as $letter => $group) : ?>
                <div class="kop-da-letter">
                    <h3 class="kop-da-letter-h"><?php echo esc_html($letter); ?></h3>
                    <ul>
                        <?php foreach ($group as $p) : ?>
                            <li data-name="<?php echo esc_attr(strtolower($p['name'])); ?>">
                                <a href="<?php echo esc_url(kop_doc_archive_collection_url($p['folder'])); ?>"><?php echo esc_html($p['name']); ?></a>
                                <span class="kop-da-n"><?php echo esc_html(number_format_i18n($p['count'])); ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

    <?php if ($data['recent']) : ?>
    <section class="kop-da-section" aria-labelledby="kop-da-recent">
        <h2 id="kop-da-recent" class="kop-da-h">Recently added</h2>
        <ul class="kop-da-recent">
            <?php foreach ($data['recent'] as $doc) : ?>
                <li>
                    <span class="kop-da-recent-thumb" aria-hidden="true"><?php if ($doc['thumb']) : ?><img src="<?php echo esc_url($doc['thumb']); ?>" alt="" loading="lazy"><?php endif; ?></span>
                    <span class="kop-da-recent-text">
                        <a href="<?php echo esc_url($doc['url']); ?>"<?php echo kop_doc_archive_doc_attrs($doc['title'], $doc['mime'], $doc['thumb']); ?> target="_blank" rel="noopener"><?php echo esc_html($doc['title']); ?></a>
                        <span class="kop-da-recent-meta">
                            <a href="<?php echo esc_url(kop_doc_archive_collection_url($doc['folder'])); ?>"><?php echo esc_html($doc['fname']); ?></a>
                            &middot; <time datetime="<?php echo esc_attr(mysql2date('c', $doc['date'])); ?>"><?php echo esc_html(mysql2date(get_option('date_format'), $doc['date'])); ?></time>
                        </span>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </section>
    <?php endif; ?>

    <?php if ($submit && $submit->post_status === 'publish') : ?>
    <section class="kop-da-cta" aria-labelledby="kop-da-give">
        <h2 id="kop-da-give">Have a document we should hold?</h2>
        <p>Handbooks, contracts, inspection reports, letters home. Send it through the anonymous portal; uploads are encrypted and you do not need to give your name.</p>
        <a class="kop-da-cta-btn" href="<?php echo esc_url(get_permalink($submit)); ?>">Send a document</a>
    </section>
    <?php endif; ?>
    <?php
}

/** One collection, opened from the landing view. */
function kop_doc_archive_render_collection($folder) {
    $back = get_page_by_path(KOP_DOC_ARCHIVE_SLUG);
    ?>
    <p class="kop-da-back"><a href="<?php echo esc_url($back ? get_permalink($back) : home_url('/')); ?>">&larr; The whole archive</a></p>
    <div class="kop-da-collection">
        <?php echo do_shortcode('[filebird_folder folder_id="' . (int) $folder['id'] . '" merge="name" layout="grid"]'); ?>
    </div>
    <?php
}
