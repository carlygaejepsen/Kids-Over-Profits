<?php
/**
 * /documents/ (every document type with its count) and /documents/<type>/
 * (every document of that type, grouped by the program whose page lists it,
 * filterable by name, state and decade). inc/doc-types.php routes here with
 * $GLOBALS['kop_doc_types_page'] = kop_dt_page_data(). Styled by
 * css/document-types.css on the site map's panel (css/site-map.css); the
 * filter is js/document-types.js and works without it as a plain list.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_dt = isset($GLOBALS['kop_doc_types_page']) && is_array($GLOBALS['kop_doc_types_page'])
    ? $GLOBALS['kop_doc_types_page']
    : array('type' => '', 'types' => kop_dt_types(), 'counts' => array(), 'items' => array(), 'url' => kop_dt_url(), 'archive_url' => home_url('/document-archive/'));
$kop_dt_is_admin = function_exists('current_user_can') && current_user_can('manage_options');
$kop_dt_type = $kop_dt['type'];
$kop_dt_def = $kop_dt_type !== '' ? $kop_dt['types'][$kop_dt_type] : null;

// Grouped by the program (or collection) that holds each document.
$kop_dt_groups = array();
$kop_dt_states = array();
$kop_dt_decades = array();
foreach ($kop_dt['items'] as $kop_dt_it) {
    $kop_dt_k = $kop_dt_it['place']['kind'] . ':' . $kop_dt_it['place']['id'] . ':' . $kop_dt_it['place']['name'];
    $kop_dt_groups[$kop_dt_k]['place'] = $kop_dt_it['place'];
    $kop_dt_groups[$kop_dt_k]['items'][] = $kop_dt_it;
    if ($kop_dt_it['place']['state'] !== '') $kop_dt_states[$kop_dt_it['place']['state']] = true;
    if ($kop_dt_it['year'] !== '') $kop_dt_decades[substr($kop_dt_it['year'], 0, 3) . '0'] = true;
}
ksort($kop_dt_states);
ksort($kop_dt_decades);

get_header();
?>
<article class="entry content-bg single-entry kop-site-map kop-dt-page" data-kop-bug-feature="document-types" data-kop-bug-label="Documents by type">
    <div class="entry-content-wrap">

        <nav class="kop-sm-trail" aria-label="Breadcrumb">
            <ol>
                <li><a href="<?php echo esc_url(home_url('/')); ?>">Home</a></li>
                <li><a href="<?php echo esc_url($kop_dt['archive_url']); ?>">Document Archive</a></li>
                <?php if ($kop_dt_def) : ?>
                    <li><a href="<?php echo esc_url($kop_dt['url']); ?>">Documents by type</a></li>
                    <li aria-current="page"><?php echo esc_html($kop_dt_def['plural']); ?></li>
                <?php else : ?>
                    <li aria-current="page">Documents by type</li>
                <?php endif; ?>
            </ol>
        </nav>

        <?php if (!$kop_dt_def) : ?>
            <header class="entry-header kop-sm-header">
                <h1 class="entry-title">Documents by type</h1>
                <p class="kop-sm-standfirst">Every document in our archive, sorted by what kind of document it is. Open a type to read the same kind of paper from many programs side by side: how one program's handbook compares with another's, what enrollment contracts asked parents to sign, what inspectors found from one state to the next.</p>
                <?php if ($kop_dt_is_admin) : ?>
                    <p class="kop-dt-admin-note">Set a document's type with the pencil on its tile, or at <a href="<?php echo esc_url(admin_url('admin.php?page=kop-document-types')); ?>">KOP Tools &gt; Document Types</a>.</p>
                <?php endif; ?>
            </header>
            <?php
            $kop_dt_group = null;
            foreach ($kop_dt['types'] as $kop_dt_slug => $kop_dt_t) :
                $kop_dt_n = (int) ($kop_dt['counts'][$kop_dt_slug] ?? 0);
                if ($kop_dt_n === 0) continue;
                if ($kop_dt_t['group'] !== $kop_dt_group) :
                    if ($kop_dt_group !== null) echo '</ul></section>';
                    $kop_dt_group = $kop_dt_t['group'];
                    ?>
                    <section class="kop-dt-group">
                        <h2><?php echo esc_html($kop_dt_group); ?></h2>
                        <ul class="kop-dt-types">
                <?php endif; ?>
                <li class="kop-dt-type">
                    <a href="<?php echo esc_url(kop_dt_url($kop_dt_slug)); ?>">
                        <span class="kop-dt-type__name"><?php echo esc_html($kop_dt_t['plural']); ?></span>
                        <span class="kop-dt-type__count"><?php echo esc_html(number_format_i18n($kop_dt_n)); ?></span>
                    </a>
                    <p><?php echo esc_html($kop_dt_t['desc']); ?></p>
                </li>
            <?php endforeach; ?>
            <?php if ($kop_dt_group !== null) echo '</ul></section>'; ?>
            <?php if ($kop_dt_group === null) : ?>
                <p class="kop-dt-empty">The documents are still being sorted. Browse them by program in the <a href="<?php echo esc_url($kop_dt['archive_url']); ?>">Document Archive</a> meanwhile.</p>
            <?php endif; ?>

        <?php else : ?>
            <header class="entry-header kop-sm-header">
                <h1 class="entry-title"><?php echo esc_html($kop_dt_def['plural']); ?></h1>
                <p class="kop-sm-standfirst"><?php echo esc_html($kop_dt_def['desc']); ?></p>
                <p class="kop-dt-summary"><?php
                    $kop_dt_progs = count(array_filter($kop_dt_groups, function ($g) { return $g['place']['kind'] === 'facility'; }));
                    echo esc_html(number_format_i18n(count($kop_dt['items'])) . ' document' . (count($kop_dt['items']) === 1 ? '' : 's')
                        . ($kop_dt_progs ? ' from ' . number_format_i18n($kop_dt_progs) . ' program' . ($kop_dt_progs === 1 ? '' : 's') : '') . '.');
                ?> We are still sorting the archive, so some are not listed here yet.</p>
            </header>

            <nav class="kop-dt-others" aria-label="Other document types">
                <?php foreach ($kop_dt['types'] as $kop_dt_slug => $kop_dt_t) :
                    if ((int) ($kop_dt['counts'][$kop_dt_slug] ?? 0) === 0) continue; ?>
                    <a href="<?php echo esc_url(kop_dt_url($kop_dt_slug)); ?>"<?php echo $kop_dt_slug === $kop_dt_type ? ' aria-current="page"' : ''; ?>><?php echo esc_html($kop_dt_t['plural']); ?></a>
                <?php endforeach; ?>
            </nav>

            <?php if (!$kop_dt['items']) : ?>
                <p class="kop-dt-empty">None of these are listed yet.</p>
            <?php else : ?>
                <form class="kop-dt-filter" role="search" hidden>
                    <label class="kop-dt-filter__field">
                        <span>Program or title</span>
                        <input type="search" name="q" autocomplete="off" placeholder="Name a program, company or word">
                    </label>
                    <?php if (count($kop_dt_states) > 1) : ?>
                        <label class="kop-dt-filter__field">
                            <span>State</span>
                            <select name="state">
                                <option value="">Every state</option>
                                <?php foreach (array_keys($kop_dt_states) as $kop_dt_s) : ?>
                                    <option value="<?php echo esc_attr($kop_dt_s); ?>"><?php echo esc_html($kop_dt_s); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <?php if (count($kop_dt_decades) > 1) : ?>
                        <label class="kop-dt-filter__field">
                            <span>Decade</span>
                            <select name="decade">
                                <option value="">Any year</option>
                                <?php foreach (array_keys($kop_dt_decades) as $kop_dt_d) : ?>
                                    <option value="<?php echo esc_attr($kop_dt_d); ?>"><?php echo esc_html($kop_dt_d); ?>s</option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                    <?php endif; ?>
                    <p class="kop-dt-filter__count" aria-live="polite"></p>
                </form>

                <?php foreach ($kop_dt_groups as $kop_dt_g) :
                    $kop_dt_p = $kop_dt_g['place']; ?>
                    <section class="kop-dt-place" data-name="<?php echo esc_attr(strtolower($kop_dt_p['name'])); ?>" data-state="<?php echo esc_attr($kop_dt_p['state']); ?>">
                        <h2>
                            <?php if ($kop_dt_p['url'] !== '') : ?>
                                <a href="<?php echo esc_url($kop_dt_p['url']); ?>"><?php echo esc_html($kop_dt_p['name']); ?></a>
                            <?php else : ?>
                                <?php echo esc_html($kop_dt_p['name']); ?>
                            <?php endif; ?>
                            <?php if ($kop_dt_p['state'] !== '') : ?><span class="kop-dt-place__state"><?php echo esc_html($kop_dt_p['state']); ?></span><?php endif; ?>
                            <span class="kop-dt-place__count">(<?php echo count($kop_dt_g['items']); ?>)</span>
                        </h2>
                        <ul class="kop-dt-docs">
                            <?php foreach ($kop_dt_g['items'] as $kop_dt_it) : ?>
                                <li class="kop-dt-doc" data-title="<?php echo esc_attr(strtolower($kop_dt_it['title'])); ?>" data-year="<?php echo esc_attr($kop_dt_it['year']); ?>"<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('doc:' . (int) $kop_dt_it['id'], $kop_dt_it['title']) : ''; ?>>
                                    <a href="<?php echo esc_url($kop_dt_it['url']); ?>" target="_blank" rel="noopener">
                                        <span class="kop-dt-doc__thumb">
                                            <?php if ($kop_dt_it['thumb'] !== '') : ?>
                                                <img src="<?php echo esc_url($kop_dt_it['thumb']); ?>" alt="" loading="lazy" decoding="async" onerror="this.remove()">
                                            <?php endif; ?>
                                            <span class="kop-dt-doc__ext"><?php echo esc_html($kop_dt_it['ext'] !== '' ? $kop_dt_it['ext'] : 'DOC'); ?></span>
                                        </span>
                                        <span class="kop-dt-doc__title"><?php echo esc_html($kop_dt_it['title']); ?></span>
                                        <?php if ($kop_dt_it['year'] !== '') : ?><span class="kop-dt-doc__year"><?php echo esc_html($kop_dt_it['year']); ?></span><?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    </section>
                <?php endforeach; ?>
                <p class="kop-dt-empty kop-dt-nomatch" hidden>Nothing matches. Clear the filter to see them all.</p>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</article>
<?php
get_footer();
