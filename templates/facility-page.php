<?php
/**
 * Generated facility page (/facility/<slug>/).
 *
 * Not a selectable page template: inc/facility-pages.php routes the request
 * here with the view model in $GLOBALS['kop_facility_page'] (built by
 * kop_facility_page_data()). Nothing on this page comes from wp_posts; it is
 * the facilities_v2 record plus every table that links to it.
 *
 * Layout mirrors templates/single-facility-profile.php (the hand-written
 * profiles): header, a reading column of sections, a facts rail.
 */

if (!defined('ABSPATH')) {
    exit;
}

$page = isset($GLOBALS['kop_facility_page']) && is_array($GLOBALS['kop_facility_page']) ? $GLOBALS['kop_facility_page'] : null;
if (!$page) {
    get_header();
    echo '<p>Facility not found.</p>';
    get_footer();
    return;
}

// An admin's pencil on a group of the record's fields (inc/inline-edit.php); '' for everyone else.
$kop_fp_edit = static function ($group, $label) use ($page) {
    return function_exists('kop_ie_attr') ? kop_ie_attr('facility:' . (int) $page['id'] . ':' . $group, $label) : '';
};
// Rail fact label => the group of fields behind it.
$kop_fp_fact_groups = array(
    'Type' => 'details', 'Serves' => 'details', 'Capacity' => 'details', 'Current census' => 'details', 'Ownership' => 'details',
    'Operated' => 'status',
    'Operator' => 'people', 'Owners' => 'people', 'Other operators' => 'people', 'Past operators' => 'people', 'Investors' => 'people', 'Known referrers' => 'people',
    'Accreditation' => 'credentials', 'Past accreditation' => 'credentials', 'Memberships' => 'credentials', 'Certifications' => 'credentials', 'Licensing' => 'credentials',
);

$kop_fp_list = static function (array $items, $class = 'kop-fp-list') {
    if (!$items) return;
    echo '<ul class="' . esc_attr($class) . '">';
    foreach ($items as $item) {
        echo '<li>' . esc_html($item) . '</li>';
    }
    echo '</ul>';
};

// Where a fact, staff entry or incident came from: the word "source" (source 1, source 2 ...) linking to
// the page it cites, the full citation in the link's preview.
$kop_fp_sources = static function ($sources, $tag = 'dd') {
    if (!$sources) return;
    $links = array();
    foreach (array_values($sources) as $n => $src) {
        $label = 'source' . (count($sources) > 1 ? ' ' . ($n + 1) : '');
        $preview = $src['cite'] !== '' ? $src['cite'] : $src['source'];
        // A wiki citation with no address links the page it names.
        $url = $src['url'] !== '' ? $src['url'] : kop_facility_pages_wiki_url($src['cite']);
        $links[] = $url !== ''
            ? kop_citation_link($url, $label, $preview, true, '', true)
            : '<span title="' . esc_attr($preview) . '">' . esc_html($label) . '</span>';
    }
    echo '<' . $tag . ' class="kop-fp-src">' . implode(', ', $links) . '</' . $tag . '>';
};

// A section heading's icon (inc/icons.php), drawn in the heading's colour.
$kop_fp_icon = static function ($name) {
    return function_exists('kop_icon') ? kop_icon($name, array('class' => 'kop-fp-h-icon')) : '';
};

// One serious finding (kop_facility_pages_violations()): the kind of harm, the date, the state's words.
$kop_fp_violation_card = static function (array $v) {
    $long = $v['short'] !== $v['excerpt'];
    $tone = in_array($v['category'], array('death', 'sexual_abuse'), true) ? 'grave' : ($v['severe'] ? 'severe' : 'serious');
    ?>
    <article class="kop-fp-vcard kop-fp-vcard--<?php echo esc_attr($tone); ?>" id="finding-<?php echo (int) $v['id']; ?>">
        <p class="kop-fp-vcard-head">
            <span class="kop-fp-vtag"><?php echo esc_html($v['label']); ?></span>
            <?php if ($v['date_label'] !== '') : ?><span class="kop-fp-vdate">Inspected <?php echo esc_html($v['date_label']); ?></span><?php endif; ?>
        </p>
        <blockquote class="kop-fp-vquote"><?php echo function_exists('kop_ih_excerpt_html') ? kop_ih_excerpt_html($v['short']) : '<p>' . esc_html($v['short']) . '</p>'; ?></blockquote>
        <?php if ($long) : ?>
            <details class="kop-fp-vfull">
                <summary>Read the whole finding</summary>
                <blockquote class="kop-fp-vquote"><?php echo function_exists('kop_ih_excerpt_html') ? kop_ih_excerpt_html($v['excerpt']) : '<p>' . esc_html($v['excerpt']) . '</p>'; ?></blockquote>
            </details>
        <?php endif; ?>
        <p class="kop-fp-vfoot">
            <?php if (!empty($v['home'])) : ?><span class="kop-fp-at-home">At <?php echo !empty($v['home_url']) ? '<a href="' . esc_url($v['home_url']) . '">' . esc_html($v['home']) . '</a>' : esc_html($v['home']); ?></span><?php endif; ?>
            <span><?php echo esc_html(trim('From the ' . $v['state'] . ' inspection report' . ($v['state_label'] !== '' ? '. ' . $v['state_label'] : ''))); ?></span>
            <?php if (count($v['kinds']) > 1) : ?><span>Also: <?php echo esc_html(implode(', ', array_diff($v['kinds'], array($v['label'])))); ?></span><?php endif; ?>
            <?php if ($v['source_url'] !== '' && preg_match('#^https?://#i', $v['source_url'])) : ?>
                <?php echo kop_citation_link($v['source_url'], "State's report", $v['short'], true); ?>
            <?php endif; ?>
        </p>
    </article>
    <?php
};

// One news article: its picture (the article's own, else the publication's logo, else the outlet's initial), then the words.
$kop_fp_news_card = static function (array $n) {
    $has_url = $n['url'] !== '' && preg_match('#^https?://#i', $n['url']);
    $img = $n['image'] ?? null;
    $kind = is_array($img) ? $img['kind'] : 'none';
    $outlet = $n['outlet'] !== '' ? $n['outlet'] : (string) preg_replace('/^www\./', '', (string) wp_parse_url($n['url'], PHP_URL_HOST));
    $initial = $outlet !== '' ? strtoupper(mb_substr(preg_replace('/^(?:The|A)\s+/i', '', $outlet), 0, 1)) : '';
    $tag = $has_url ? 'a' : 'span';
    ?>
    <li class="kop-fp-news-item"<?php echo function_exists('kop_ie_attr') && !empty($n['id']) ? kop_ie_attr('rec:news:' . (int) $n['id'], 'this article') : ''; ?>>
        <<?php echo $tag; ?> class="kop-fp-news-thumb kop-fp-news-thumb--<?php echo esc_attr($kind); ?>"<?php echo $has_url ? ' href="' . esc_url($n['url']) . '" target="_blank" rel="noopener" tabindex="-1" aria-hidden="true"' : ' aria-hidden="true"'; ?>>
            <?php if (is_array($img)) : ?>
                <img src="<?php echo esc_url($img['src']); ?>" alt="" loading="lazy" decoding="async"<?php echo $kind === 'photo' ? ' width="640" height="360"' : ' width="160" height="160"'; ?>>
            <?php else : ?>
                <span class="kop-fp-news-initial"><?php echo esc_html($initial); ?></span>
            <?php endif; ?>
        </<?php echo $tag; ?>>
        <div class="kop-fp-news-body">
            <p class="kop-fp-news-meta">
                <?php if ($outlet !== '') : ?><span class="kop-fp-news-outlet"><?php echo esc_html($outlet); ?></span><?php endif; ?>
                <?php if ($n['date_label'] !== '') : ?><span><?php echo esc_html($n['date_label']); ?></span><?php endif; ?>
                <?php if ($n['type'] !== '') : ?><span class="kop-fp-news-type"><?php echo esc_html(ucfirst($n['type'])); ?></span><?php endif; ?>
                <?php if (!empty($n['home'])) : ?><span class="kop-fp-at-home">About <?php echo esc_html($n['home']); ?></span><?php endif; ?>
            </p>
            <h3 class="kop-fp-news-title">
                <?php if ($has_url) : ?>
                    <a href="<?php echo esc_url($n['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($n['title']); ?></a>
                <?php else : ?>
                    <?php echo esc_html($n['title']); ?>
                <?php endif; ?>
            </h3>
            <?php if ($n['summary'] !== '') : ?><p class="kop-fp-news-summary"><?php echo esc_html($n['summary']); ?></p><?php endif; ?>
        </div>
    </li>
    <?php
};

// The lists the dated sections print. A renamed program's page prints each
// once per name (inc/facility-eras.php), so they are written once here.
$kop_fp_memorial_list = static function (array $items) use ($page) {
    ?>
    <ul class="kop-fp-records kop-fp-deaths">
        <?php foreach ($items as $m) :
            $bits = array_filter(array($m['age'] !== '' ? 'age ' . $m['age'] : '', $m['date_label'], $m['cause']), 'strlen');
            ?>
            <li>
                <?php if ($m['kop_url'] !== '' && preg_match('#^https?://#i', $m['kop_url'])) : ?>
                    <a href="<?php echo esc_url($m['kop_url']); ?>"><?php echo esc_html($m['name']); ?></a>
                <?php else : ?>
                    <a href="<?php echo esc_url($page['memorial_url']); ?>"><?php echo esc_html($m['name']); ?></a>
                <?php endif; ?>
                <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                <?php if ($m['source_url'] !== '' && preg_match('#^https?://#i', $m['source_url'])) : ?>
                    <span class="meta"><?php echo kop_citation_link($m['source_url'], $m['source_name'] !== '' ? $m['source_name'] : 'Source', $m['source_name'], true, '', true); ?></span>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};
$kop_fp_violation_cards = static function (array $items) use ($kop_fp_violation_card) {
    $kop_fp_v_first = array_slice($items, 0, 4);
    $kop_fp_v_rest = array_slice($items, 4);
    ?>
    <div class="kop-fp-vcards">
        <?php foreach ($kop_fp_v_first as $v) $kop_fp_violation_card($v); ?>
    </div>
    <?php if ($kop_fp_v_rest) : ?>
        <details class="kop-fp-more">
            <summary><?php echo count($kop_fp_v_rest); ?> more serious <?php echo count($kop_fp_v_rest) === 1 ? 'finding' : 'findings'; ?></summary>
            <div class="kop-fp-vcards">
                <?php foreach ($kop_fp_v_rest as $v) $kop_fp_violation_card($v); ?>
            </div>
        </details>
    <?php endif; ?>
    <?php
};
$kop_fp_lawsuit_list = static function (array $items) use ($page) {
    ?>
    <ul class="kop-fp-records kop-fp-cases">
        <?php foreach ($items as $l) :
            $bits = array_filter(array($l['year'], $l['status'], $l['court'], $l['case_number']), 'strlen');
            ?>
            <li<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('rec:lawsuit:' . (int) $l['id'], 'this lawsuit') : ''; ?>>
                <a href="<?php echo esc_url($page['lawsuits_url']); ?>"><?php echo esc_html($l['case_name']); ?></a>
                <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                <?php if ($l['link_type'] === 'mentioned') : ?><span class="meta">Names this facility</span><?php endif; ?>
                <?php if (!empty($l['home'])) : ?><span class="meta">About <?php echo !empty($l['home_url']) ? '<a href="' . esc_url($l['home_url']) . '">' . esc_html($l['home']) . '</a>' : esc_html($l['home']); ?></span><?php endif; ?>
                <?php if ($l['outcome'] !== '') : ?><p class="kop-fp-record-summary"><strong>Outcome:</strong> <?php echo esc_html($l['outcome']); ?></p><?php endif; ?>
                <?php if ($l['summary'] !== '') : ?><p class="kop-fp-record-summary"><?php echo esc_html($l['summary']); ?></p><?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
    <?php
};
$kop_fp_incident_list = static function (array $items) use ($kop_fp_sources) {
    ?>
    <ol class="kop-fp-timeline">
        <?php foreach ($items as $inc) : ?>
            <li>
                <?php if ($inc['when'] !== '' || $inc['kind'] !== '') : ?>
                    <p class="kop-fp-tl-head">
                        <?php if ($inc['when'] !== '') : ?><span class="kop-fp-tl-when"><?php echo esc_html($inc['when']); ?></span><?php endif; ?>
                        <?php if ($inc['kind'] !== '') : ?><span class="kop-fp-tl-kind"><?php echo esc_html($inc['kind']); ?></span><?php endif; ?>
                    </p>
                <?php endif; ?>
                <?php $kop_fp_inc_src = array_values(array_filter(array_merge(array($inc), $inc['also'] ?? array()), static function ($s) { return $s['source'] !== ''; })); ?>
                <p class="kop-fp-tl-text"><?php echo kop_facility_pages_cited_html($inc['text']); ?><?php if ($kop_fp_inc_src) $kop_fp_sources($kop_fp_inc_src, 'span'); ?></p>
            </li>
        <?php endforeach; ?>
    </ol>
    <?php
};
$kop_fp_news_list = static function (array $items) use ($kop_fp_news_card) {
    $kop_fp_n_first = array_slice($items, 0, 6);
    $kop_fp_n_rest = array_slice($items, 6);
    ?>
    <ul class="kop-fp-news">
        <?php foreach ($kop_fp_n_first as $n) $kop_fp_news_card($n); ?>
    </ul>
    <?php if ($kop_fp_n_rest) : ?>
        <details class="kop-fp-more">
            <summary><?php echo count($kop_fp_n_rest); ?> more <?php echo count($kop_fp_n_rest) === 1 ? 'article' : 'articles'; ?></summary>
            <ul class="kop-fp-news">
                <?php foreach ($kop_fp_n_rest as $n) $kop_fp_news_card($n); ?>
            </ul>
        </details>
    <?php endif; ?>
    <?php
};
// $tag: the heading over each group, one level under the section's own.
$kop_fp_staff_lists = static function (array $staff, $tag = 'h3') use ($kop_fp_sources) {
    $staff_labels = array('administrator' => 'Administration', 'notableStaff' => 'Notable staff', 'pastTTIJobs' => 'Staff who came from other programs');
    ?>
    <?php foreach ($staff_labels as $key => $label) :
        if (empty($staff[$key])) continue;
        ?>
        <<?php echo $tag; ?> class="kop-fp-subhead"><?php echo esc_html($label); ?></<?php echo $tag; ?>>
        <?php if ($key === 'pastTTIJobs') : ?>
            <ul class="kop-fp-people">
                <?php foreach ($staff[$key] as $person) : ?>
                    <li><?php echo esc_html($person['text']); ?><?php if ($person['source'] !== '') $kop_fp_sources(array($person), 'span'); ?></li>
                <?php endforeach; ?>
            </ul>
        <?php else : ?>
            <ul class="kop-fp-staff">
                <?php foreach ($staff[$key] as $person) : ?>
                    <li class="kop-fp-person<?php echo !empty($person['career']) ? ' kop-fp-person--career' : ''; ?>">
                        <?php if (isset($person['name'])) : ?>
                            <p class="kop-fp-person-name"><?php echo esc_html($person['name']); ?></p>
                            <?php if ($person['role'] !== '' || $person['source'] !== '') : ?>
                                <p class="kop-fp-person-role"><?php echo esc_html($person['role']); ?><?php if ($person['source'] !== '') $kop_fp_sources(array($person), 'span'); ?></p>
                            <?php endif; ?>
                            <?php if (!empty($person['career'])) : ?>
                                <div class="kop-fp-career">
                                    <p class="kop-fp-career-label">Elsewhere in the industry</p>
                                    <ul>
                                        <?php foreach ($person['career'] as $job) :
                                            $job_meta = trim($job['role'] . ($job['years'] !== '' ? ($job['role'] !== '' ? ', ' : '') . $job['years'] : ''));
                                            ?>
                                            <li>
                                                <?php if ($job['url'] !== '') : ?>
                                                    <a href="<?php echo esc_url($job['url']); ?>"><?php echo esc_html($job['place']); ?></a>
                                                <?php else : ?>
                                                    <span class="kop-fp-career-place"><?php echo esc_html($job['place']); ?></span>
                                                <?php endif; ?>
                                                <?php if ($job_meta !== '') : ?><span class="kop-fp-career-role"><?php echo esc_html($job_meta); ?></span><?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </div>
                            <?php endif; ?>
                        <?php else : ?>
                            <p class="kop-fp-person-name"><?php echo esc_html($person['text']); ?></p>
                            <?php if ($person['source'] !== '') $kop_fp_sources(array($person), 'span'); ?>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    <?php endforeach; ?>
    <?php
};

// A renamed program: one section per name holds what is dated to its years,
// and the ordinary sections below keep only what no name took.
$kop_fp_eras = !empty($page['eras']['list']) ? $page['eras'] : null;
$kop_fp_totals = array();
foreach (array('memorials', 'lawsuits', 'incidents', 'news') as $kop_fp_k) $kop_fp_totals[$kop_fp_k] = count($page[$kop_fp_k] ?? array());
$kop_fp_totals['staff'] = count($page['staff']['administrator'] ?? array()) + count($page['staff']['notableStaff'] ?? array());
if ($kop_fp_eras) {
    foreach (array('memorials', 'lawsuits', 'incidents', 'news', 'staff') as $kop_fp_k) $page[$kop_fp_k] = $kop_fp_eras['rest'][$kop_fp_k];
    $kop_fp_totals = $kop_fp_eras['totals'];
}
$kop_fp_era_kinds = array(
    'memorials'  => array('candle', 'Deaths on record'),
    'violations' => array('alert-triangle', 'Serious violations'),
    'lawsuits'   => array('scale', 'Lawsuits'),
    'incidents'  => array('siren', 'Incidents on record'),
    'news'       => array('newspaper', 'News coverage'),
    'staff'      => array('users', 'Staff'),
);

$kop_fp_sections = array();
// Homes of a program (inc/program-homes.php): a program lists them, a home lists the others.
$kop_fp_homes = !empty($page['program_homes']['homes']) ? $page['program_homes']['homes'] : (!empty($page['home_of']['others']) ? $page['home_of']['others'] : array());
$kop_fp_is_program = !empty($page['program_homes']['homes']);
if ($kop_fp_homes) $kop_fp_sections['homes'] = $kop_fp_is_program ? 'Homes' : 'Other homes';
$kop_fp_has_practices = !empty($page['practices']);
$kop_fp_has_staff = !empty($page['staff']);
$kop_fp_has_notes = !empty($page['notes']) || !empty($page['field_notes']);
$kop_fp_has_testimony = !empty($page['testimony']) || !empty($page['forum']);
$kop_fp_has_videos = !empty($page['videos']);
$kop_fp_has_news = !empty($page['news']);
$kop_fp_has_lawsuits = !empty($page['lawsuits']);
$kop_fp_has_memorials = !empty($page['memorials']);
$kop_fp_has_inspections = !empty($page['inspections']);
$kop_fp_has_research = !empty($page['research']);
$kop_fp_has_unsilenced = !empty($page['unsilenced']['groups']);
$kop_fp_has_survivor_sites = !empty($page['survivor_sites']);
$kop_fp_has_docs = !empty($page['documents']['html']) || $kop_fp_has_research || $kop_fp_has_unsilenced || $kop_fp_has_survivor_sites;
$kop_fp_has_wiki = !empty($page['wiki']);
$kop_fp_has_siblings = !empty($page['siblings']);
$kop_fp_has_resources = !empty($page['resources']) || !empty($page['profile_links']) || !empty($page['resource_links']);
$kop_fp_has_network = !empty($page['network']['groups']);

$kop_fp_violations = $kop_fp_has_inspections ? (array) ($page['inspections']['violations'] ?? array()) : array();
// A program page also shows the findings at its homes, each naming the home.
if (!empty($page['program_homes']['violations'])) {
    $kop_fp_violations = array_merge($kop_fp_violations, $page['program_homes']['violations']);
    usort($kop_fp_violations, static function ($a, $b) {
        return ($b['severe'] <=> $a['severe']) ?: ($b['weight'] <=> $a['weight']) ?: strcmp((string) $b['date'], (string) $a['date']);
    });
}
if ($kop_fp_eras) $kop_fp_violations = $kop_fp_eras['rest']['violations'];
else $kop_fp_totals['violations'] = count($kop_fp_violations);
$kop_fp_has_violations = !empty($kop_fp_violations);
$kop_fp_has_incidents = !empty($page['incidents']);

// A merged Facility Profile post (kop_facility_pages_merged_profiles()): its
// words, cut at its headings by kop_facility_profile_parts(), fill this page.
// Its written sections come first, its lawsuits, news, documents and survivor
// links join the record's own sections, its facts go in the rail. Nothing is
// reworded; the post stays where the words are edited.
$kop_fp_profile = !empty($page['profile_post']) && function_exists('get_post') ? get_post((int) $page['profile_post']) : null;
$kop_fp_parts = ($kop_fp_profile && function_exists('kop_facility_profile_parts')) ? kop_facility_profile_parts((string) $kop_fp_profile->post_content) : null;
$kop_fp_prose = array();
$kop_fp_psec = array();
foreach ($kop_fp_parts ? $kop_fp_parts['sections'] : array() as $kop_fp_s) {
    if ($kop_fp_s['kind'] !== 'prose' && !isset($kop_fp_psec[$kop_fp_s['kind']])) {
        $kop_fp_psec[$kop_fp_s['kind']] = $kop_fp_s;
    } else {
        $kop_fp_prose[] = $kop_fp_s;
    }
}
if ($kop_fp_parts) {
    $kop_fp_has_lawsuits = $kop_fp_has_lawsuits || isset($kop_fp_psec['lawsuits']);
    $kop_fp_has_news = $kop_fp_has_news || isset($kop_fp_psec['news']);
    $kop_fp_has_docs = $kop_fp_has_docs || isset($kop_fp_psec['documents']);
    $kop_fp_has_testimony = $kop_fp_has_testimony || isset($kop_fp_psec['testimony']);
}
// The post's own Related section takes #related; the operator's other programs move aside.
$kop_fp_id = static function ($anchor) use ($kop_fp_psec) {
    return ($anchor === 'related' && isset($kop_fp_psec['related'])) ? 'operator-programs' : $anchor;
};
// The post's old anchor (#survivors, #doclibrary) on the section it joined, so links to it still land.
$kop_fp_alias = static function ($kind, $anchor) use ($kop_fp_psec) {
    if (!isset($kop_fp_psec[$kind]) || $kop_fp_psec[$kind]['id'] === $anchor) return '';
    return '<span id="' . esc_attr($kop_fp_psec[$kind]['id']) . '" class="kop-fp-anchor"></span>';
};
// A section the post shares with the record keeps the post's heading.
$kop_fp_title = static function ($kind, $default) use ($kop_fp_psec) {
    return isset($kop_fp_psec[$kind]) ? $kop_fp_psec[$kind]['title'] : $default;
};
// A profile section's items in order: link previews as cards, every other block through the_content.
$kop_fp_render_items = static function (array $items) use ($kop_fp_news_card) {
    $cards = array();
    $flush = static function () use (&$cards, $kop_fp_news_card) {
        if (!$cards) return;
        echo '<ul class="kop-fp-news kop-fp-links">';
        foreach ($cards as $c) $kop_fp_news_card($c);
        echo '</ul>';
        $cards = array();
    };
    foreach ($items as $it) {
        if ($it['type'] === 'card') {
            $cards[] = $it['card'];
            continue;
        }
        $flush();
        echo kop_facility_profile_render($it['raw']);
    }
    $flush();
};
$kop_fp_prose_icons = array(
    'intro' => 'book', 'leadership' => 'users', 'philo' => 'lightbulb', 'discipline' => 'eye', 'iso' => 'lock',
    'abuse' => 'alert-triangle', 'therapy' => 'user-x', 'videoplaylist' => 'tv', 'related' => 'link',
);
$kop_fp_profile_section = static function (array $s, $class = '') use ($kop_fp_icon, $kop_fp_render_items, $kop_fp_prose_icons) {
    ?>
    <section class="kop-fp-section kop-fp-prose<?php echo $class !== '' ? ' ' . esc_attr($class) : ''; ?>" id="<?php echo esc_attr($s['id']); ?>">
        <h2><?php echo $kop_fp_icon($kop_fp_prose_icons[$s['id']] ?? 'file-text'); ?><?php echo esc_html($s['title']); ?></h2>
        <?php $kop_fp_render_items($s['items']); ?>
    </section>
    <?php
};

if ($kop_fp_eras) foreach ($kop_fp_eras['list'] as $kop_fp_era) $kop_fp_sections[$kop_fp_era['id']] = 'As ' . $kop_fp_era['name'];
if ($kop_fp_has_memorials) $kop_fp_sections['memorials'] = 'Deaths on record';
if ($kop_fp_has_violations) $kop_fp_sections['violations'] = 'Serious violations';
if ($kop_fp_has_lawsuits) $kop_fp_sections['lawsuits'] = 'Lawsuits';
if ($kop_fp_has_incidents) $kop_fp_sections['incidents'] = 'Incidents';
if ($kop_fp_has_news) $kop_fp_sections['news'] = 'News coverage';
if ($kop_fp_has_videos && !isset($kop_fp_psec['videos'])) $kop_fp_sections['videos'] = 'Videos';
if ($kop_fp_has_staff) $kop_fp_sections['staff'] = 'Staff';
if ($kop_fp_has_practices) $kop_fp_sections['practices'] = 'Reported practices';
if ($kop_fp_has_inspections) $kop_fp_sections['inspections'] = 'Licensing';
if ($kop_fp_has_network) $kop_fp_sections['network'] = 'Network';
if ($kop_fp_has_docs) $kop_fp_sections['documents'] = 'Documents';
if ($kop_fp_has_testimony) $kop_fp_sections['testimony'] = 'Survivor testimony';
if ($kop_fp_has_notes) $kop_fp_sections['notes'] = 'Research notes';
if ($kop_fp_has_wiki) $kop_fp_sections['wiki'] = 'Wiki entries';
if ($kop_fp_has_siblings) $kop_fp_sections['related'] = 'Same operator';
if ($kop_fp_has_resources) $kop_fp_sections['resources'] = 'Materials and links';

// The jump links with a profile: its written sections (named as its Index
// named them), then the record's, its videos after the news, its Related last.
if ($kop_fp_parts) {
    $kop_fp_nav = array();
    foreach ($kop_fp_prose as $kop_fp_s) $kop_fp_nav[$kop_fp_s['id']] = $kop_fp_parts['nav'][$kop_fp_s['id']] ?? $kop_fp_s['title'];
    foreach ($kop_fp_sections as $kop_fp_k => $kop_fp_v) {
        $kop_fp_kind = array('lawsuits' => 'lawsuits', 'news' => 'news', 'documents' => 'documents', 'testimony' => 'testimony')[$kop_fp_k] ?? '';
        $kop_fp_nav[$kop_fp_id($kop_fp_k)] = $kop_fp_kind !== '' ? $kop_fp_title($kop_fp_kind, $kop_fp_v) : $kop_fp_v;
        if ($kop_fp_k === 'news' && isset($kop_fp_psec['videos'])) $kop_fp_nav[$kop_fp_psec['videos']['id']] = $kop_fp_psec['videos']['title'];
    }
    if (isset($kop_fp_psec['videos'])) $kop_fp_nav += array($kop_fp_psec['videos']['id'] => $kop_fp_psec['videos']['title']);
    if (isset($kop_fp_psec['related'])) $kop_fp_nav[$kop_fp_psec['related']['id']] = $kop_fp_psec['related']['title'];
    $kop_fp_sections = $kop_fp_nav;
}

get_header();
?>
<article id="facility-<?php echo (int) $page['id']; ?>" class="entry content-bg single-entry kop-facility-profile kop-facility-generated" data-kop-bug-feature="facility-page" data-kop-bug-label="Facility page: <?php echo esc_attr($page['name']); ?>">

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">
            Facility profile
            <?php if ($page['hub_name'] !== '') : ?>
                <span aria-hidden="true">&middot;</span>
                <?php if ($page['hub_url'] !== '') : ?>
                    <a href="<?php echo esc_url($page['hub_url']); ?>"><?php echo esc_html($page['hub_name']); ?></a>
                <?php else : ?>
                    <?php echo esc_html($page['hub_name']); ?>
                <?php endif; ?>
            <?php endif; ?>
        </p>
        <h1 class="entry-title kop-fp-title"<?php echo $kop_fp_edit('names', 'names'); ?>><?php echo esc_html($page['name']); ?></h1>
        <?php if ($page['current_name'] !== '') : ?>
            <p class="kop-fp-formerly">Now known as <?php echo esc_html($page['current_name']); ?></p>
        <?php endif; ?>
        <?php if ($page['formerly']) : ?>
            <p class="kop-fp-formerly">Formerly <?php echo esc_html(implode(', ', $page['formerly'])); ?><?php $kop_fp_sources($page['fact_sources']['formerly'] ?? array(), 'span'); ?></p>
        <?php endif; ?>
        <?php if ($page['aka']) : ?>
            <p class="kop-fp-formerly">Also known as <?php echo esc_html(implode(', ', $page['aka'])); ?></p>
        <?php endif; ?>
        <?php if (!empty($page['home_of'])) : $kop_fp_ho = $page['home_of']; ?>
            <p class="kop-fp-formerly kop-fp-home-of">One of <?php echo (int) $kop_fp_ho['count']; ?> homes of <?php echo $kop_fp_ho['program']['url'] !== '' ? '<a href="' . esc_url($kop_fp_ho['program']['url']) . '">' . esc_html($kop_fp_ho['program']['name']) . '</a>' : esc_html($kop_fp_ho['program']['name']); ?></p>
        <?php elseif ($kop_fp_is_program) : ?>
            <p class="kop-fp-formerly kop-fp-home-of">A program of <?php echo count($kop_fp_homes); ?> licensed homes</p>
        <?php endif; ?>
        <div class="kop-fp-badges"<?php echo $kop_fp_edit('status', 'status and years'); ?>>
            <?php if ($page['status'] !== '') : ?>
                <span class="kop-fp-status kop-fp-status--<?php echo esc_attr($page['status_class']); ?>"><?php echo esc_html($page['status']); ?></span>
            <?php endif; ?>
            <?php if ($page['place'] !== '') : ?>
                <span class="kop-fp-place"><?php echo esc_html($page['place']); ?></span>
            <?php endif; ?>
            <?php if ($page['status'] !== 'Closed' && function_exists('kop_ie_can') && kop_ie_can()) : ?>
                <button type="button" class="kop-ie-mini kop-ie-quick" data-kop-quick="fstatus:<?php echo (int) $page['id']; ?>:Closed"
                    data-kop-quick-confirm="<?php echo esc_attr('Mark ' . $page['name'] . ' closed? Its years stay as they are.'); ?>">Mark closed</button>
            <?php endif; ?>
        </div>
    </header>

    <div class="kop-fp-grid">

        <aside class="kop-fp-rail" aria-label="Facility facts">
            <h2>At a glance</h2>
            <dl class="kop-fp-facts">
                <?php if ($page['addresses']) : ?>
                    <div<?php echo $kop_fp_edit('location', 'address'); ?>>
                        <dt><?php echo count($page['addresses']) > 1 ? 'Addresses' : 'Address'; ?></dt>
                        <?php foreach ($page['addresses'] as $addr) : ?>
                            <dd><?php echo esc_html($addr); ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($page['former_locations']) : ?>
                    <div<?php echo $kop_fp_edit('location', 'former locations'); ?>>
                        <dt>Former locations</dt>
                        <?php foreach ($page['former_locations'] as $fl) : ?>
                            <dd><?php echo esc_html($fl['line'] . ($fl['years'] !== '' ? ' (' . $fl['years'] . ')' : '')); ?></dd>
                        <?php endforeach; ?>
                        <?php $kop_fp_sources($page['fact_sources']['former_locations'] ?? array()); ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($page['facts'] as $fact) : ?>
                    <div<?php echo isset($kop_fp_fact_groups[$fact['label']]) ? $kop_fp_edit($kop_fp_fact_groups[$fact['label']], strtolower($fact['label'])) : ''; ?>>
                        <dt><?php echo esc_html($fact['label']); ?></dt>
                        <?php if (is_array($fact['value'])) : ?>
                            <?php foreach ($fact['value'] as $v) : ?><dd><?php echo esc_html($v); ?></dd><?php endforeach; ?>
                        <?php elseif ($fact['label'] === 'Operator' && $page['operator']['url'] !== '') : ?>
                            <dd><a href="<?php echo esc_url($page['operator']['url']); ?>"><?php echo esc_html($fact['value']); ?></a></dd>
                        <?php else : ?>
                            <dd><?php echo esc_html($fact['value']); ?></dd>
                        <?php endif; ?>
                        <?php $kop_fp_sources($page['fact_sources'][$fact['label']] ?? array()); ?>
                    </div>
                <?php endforeach; ?>
                <?php foreach ($kop_fp_parts ? $kop_fp_parts['facts'] : array() as $kop_fp_f) : ?>
                    <div class="kop-fp-profile-fact">
                        <dt><?php echo $kop_fp_f['label'] !== '' ? wp_kses_post($kop_fp_f['label']) : 'History'; ?></dt>
                        <?php foreach ($kop_fp_f['lines'] as $kop_fp_line) : ?><dd><?php echo wp_kses_post($kop_fp_line); ?></dd><?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
            </dl>

            <h2>Also see</h2>
            <ul class="kop-fp-list">
                <li><a href="<?php echo esc_url($page['index_url']); ?>">Database record</a><span class="meta">Location Index</span></li>
                <?php if ($page['hub_url'] !== '') : ?>
                    <li><a href="<?php echo esc_url($page['hub_url']); ?>"><?php echo esc_html($page['hub_name']); ?> hub</a><span class="meta">Every facility, lawsuit, and bill in <?php echo esc_html($page['hub_name']); ?></span></li>
                <?php endif; ?>
                <?php if ($kop_fp_has_inspections && $page['inspections']['page_url'] !== '') : ?>
                    <li><a href="<?php echo esc_url($page['inspections']['page_url']); ?>">State inspection reports</a><span class="meta">Searchable archive</span></li>
                <?php endif; ?>
            </ul>

            <h2>Know something we do not?</h2>
            <p class="kop-fp-rail-text">Corrections, documents and first-hand accounts go into the review queue and are checked before they are published.</p>
            <p class="kop-fp-rail-actions">
                <button type="button" class="kop-submit-info-btn" data-kop-submit-type="facility" data-kop-submit-name="<?php echo esc_attr($page['name']); ?>">Submit info</button>
                <a class="kop-fp-rail-link" href="<?php echo esc_url($page['submit_url']); ?>">Full submission form</a>
            </p>
        </aside>

        <div class="kop-fp-body kop-fp-generated-body<?php echo $kop_fp_parts ? '' : ' kop-fp-reorder'; ?>">

            <p class="kop-fp-summary"<?php echo $kop_fp_edit('all', 'every field of this facility'); ?>><?php echo esc_html($page['summary']); ?></p>

            <?php
            // The record in numbers, worst first; each tile jumps to its section.
            $kop_fp_tiles = array(
                array('memorials', 'candle', (int) $kop_fp_totals['memorials'], 'death on record', 'deaths on record', 'grave'),
                array('violations', 'alert-triangle', (int) $kop_fp_totals['violations'], 'serious violation confirmed by inspectors', 'serious violations confirmed by inspectors', 'grave'),
                array('lawsuits', 'scale', (int) $kop_fp_totals['lawsuits'], 'lawsuit', 'lawsuits', 'warn'),
                array('incidents', 'siren', (int) $kop_fp_totals['incidents'], 'incident on record', 'incidents on record', 'warn'),
                array('news', 'newspaper', (int) $kop_fp_totals['news'], 'news article', 'news articles', 'info'),
                array('staff', 'users', (int) $kop_fp_totals['staff'], 'staff member named', 'staff members named', 'info'),
            );
            // A tile jumps to its section, or on a renamed program's page to the first name that has the kind.
            $kop_fp_tile_anchor = static function ($kind) use ($kop_fp_eras, $kop_fp_id) {
                foreach ($kop_fp_eras ? $kop_fp_eras['list'] : array() as $era) {
                    if (!empty($era[$kind])) return $era['id'] . '-' . $kind;
                }
                return $kop_fp_id($kind);
            };
            $kop_fp_tiles = array_values(array_filter($kop_fp_tiles, static function ($t) { return $t[2] > 0; }));
            if (count($kop_fp_tiles) >= 2) : ?>
            <ul class="kop-fp-stats" aria-label="This record in numbers">
                <?php foreach ($kop_fp_tiles as $t) : ?>
                    <li class="kop-fp-stat kop-fp-stat--<?php echo esc_attr($t[5]); ?>">
                        <a href="#<?php echo esc_attr($kop_fp_tile_anchor($t[0])); ?>">
                            <?php echo $kop_fp_icon($t[1]); ?>
                            <span class="kop-fp-stat-n"><?php echo (int) $t[2]; ?></span>
                            <span class="kop-fp-stat-label"><?php echo esc_html($t[2] === 1 ? $t[3] : $t[4]); ?></span>
                        </a>
                    </li>
                <?php endforeach; ?>
            </ul>
            <?php endif; ?>

            <?php if (count($kop_fp_sections) > 1) : ?>
            <nav class="kop-fp-jump" aria-label="On this page">
                <?php foreach ($kop_fp_sections as $anchor => $label) : ?>
                    <a href="#<?php echo esc_attr($anchor); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <?php if ($kop_fp_homes) : ?>
            <section class="kop-fp-section kop-fp-homes-section" id="<?php echo $kop_fp_id('homes'); ?>">
                <?php if ($kop_fp_is_program) : ?>
                    <h2>Homes</h2>
                    <p class="kop-fp-count"><?php
                        $kop_fp_open_homes = count(array_filter($kop_fp_homes, static function ($h) { return strcasecmp($h['status'], 'Open') === 0; }));
                        echo esc_html('The state licenses this program home by home: ' . count($kop_fp_homes) . ' on record' . ($kop_fp_open_homes ? ', ' . $kop_fp_open_homes . ' open' : '') . (!empty($page['program_homes']['reports']) ? ', with ' . number_format((int) $page['program_homes']['reports']) . ' inspection reports between them' : '') . '. Each has its own page with its licence and reports; their news, lawsuits and serious findings are gathered here too.');
                    ?></p>
                <?php else : ?>
                    <h2>Other homes of <?php echo esc_html($page['home_of']['program']['name']); ?></h2>
                <?php endif; ?>
                <ul class="kop-fp-records kop-fp-homes">
                    <?php foreach ($kop_fp_homes as $h) :
                        $bits = array_filter(array($h['home_name'] !== $h['name'] ? $h['name'] : '', $h['place'], $h['years'], ($h['status'] !== '' && $h['status'] !== 'Unknown') ? $h['status'] : ''), 'strlen');
                        ?>
                        <li<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('facility:' . (int) $h['id'] . ':all', $h['name']) : ''; ?>>
                            <?php if ($h['url'] !== '') : ?><a href="<?php echo esc_url($h['url']); ?>"><?php echo esc_html($h['home_name']); ?></a><?php else : ?><span><?php echo esc_html($h['home_name']); ?></span><?php endif; ?>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!$kop_fp_is_program && $page['home_of']['program']['url'] !== '') : ?>
                    <p class="kop-fp-count"><a href="<?php echo esc_url($page['home_of']['program']['url']); ?>">The whole program, with every home's news, lawsuits and serious findings</a></p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_parts) :
                // The post is the global post while its blocks render (embeds, the
                // FileBird library, link previews). setup_postdata() also sets the
                // globals $page and $pages, and this template runs in global scope:
                // keep the view model.
                $kop_fp_page = $page;
                $GLOBALS['post'] = $kop_fp_profile;
                setup_postdata($kop_fp_profile);
                $page = $kop_fp_page;
                if (has_post_thumbnail($kop_fp_profile)) : ?>
                    <figure class="kop-fp-figure">
                        <?php echo get_the_post_thumbnail($kop_fp_profile, 'full'); ?>
                    </figure>
                <?php endif;
                foreach ($kop_fp_parts['lead'] as $kop_fp_b) echo kop_facility_profile_render($kop_fp_b['raw']);
                foreach ($kop_fp_prose as $kop_fp_s) $kop_fp_profile_section($kop_fp_s);
            elseif ($kop_fp_profile) :
                // A post with no h2 to cut at: printed whole.
                $kop_fp_page = $page;
                $GLOBALS['post'] = $kop_fp_profile;
                setup_postdata($kop_fp_profile);
                $page = $kop_fp_page;
                ?>
            <div class="entry-content single-content kop-fp-body kop-fp-profile" id="profile">
                <?php the_content(); ?>
            </div>
            <?php endif; ?>

            <?php foreach ($kop_fp_eras ? $kop_fp_eras['list'] : array() as $kop_fp_era) :
                $kop_fp_era_meta = array_filter(array($kop_fp_era['years'], $kop_fp_era['operators'] ? implode(', ', $kop_fp_era['operators']) : ''), 'strlen');
                $kop_fp_era_parts = 0;
                ?>
            <section class="kop-fp-section kop-fp-era" id="<?php echo esc_attr($kop_fp_era['id']); ?>">
                <h2><span class="kop-fp-era-as">As</span> <?php echo esc_html($kop_fp_era['name']); ?></h2>
                <?php if ($kop_fp_era_meta) : ?><p class="kop-fp-era-meta"><?php echo esc_html(implode(' | ', $kop_fp_era_meta)); ?></p><?php endif; ?>
                <?php if ($kop_fp_era['url'] !== '') : ?><p class="kop-fp-count"><a href="<?php echo esc_url($kop_fp_era['url']); ?>">The record kept under this name</a></p><?php endif; ?>
                <?php foreach ($kop_fp_era_kinds as $kop_fp_k => $kop_fp_kind) :
                    $kop_fp_items = $kop_fp_era[$kop_fp_k];
                    if (!$kop_fp_items) continue;
                    $kop_fp_era_parts++;
                    // The pencil edits this record's list, so it shows where this record's entries stand.
                    $kop_fp_era_edit = '';
                    if ($kop_fp_k === 'incidents' || $kop_fp_k === 'staff') {
                        $kop_fp_own = $kop_fp_k === 'staff' ? array_merge(...array_values($kop_fp_items)) : $kop_fp_items;
                        foreach ($kop_fp_own as $kop_fp_it) {
                            if ((int) ($kop_fp_it['_fid'] ?? 0) !== (int) $page['id']) continue;
                            $kop_fp_era_edit = $kop_fp_k === 'staff' ? $kop_fp_edit('staff', 'staff') : $kop_fp_edit('practices', 'critical incidents');
                            break;
                        }
                    }
                    ?>
                    <div class="kop-fp-era-part<?php echo $kop_fp_k === 'violations' ? ' kop-fp-violations' : ''; ?>" id="<?php echo esc_attr($kop_fp_era['id'] . '-' . $kop_fp_k); ?>"<?php echo $kop_fp_era_edit; ?>>
                        <h3 class="kop-fp-era-kind"><?php echo $kop_fp_icon($kop_fp_kind[0]); ?><?php echo esc_html($kop_fp_kind[1]); ?></h3>
                        <?php
                        if ($kop_fp_k === 'memorials') $kop_fp_memorial_list($kop_fp_items);
                        elseif ($kop_fp_k === 'violations') $kop_fp_violation_cards($kop_fp_items);
                        elseif ($kop_fp_k === 'lawsuits') $kop_fp_lawsuit_list($kop_fp_items);
                        elseif ($kop_fp_k === 'incidents') $kop_fp_incident_list($kop_fp_items);
                        elseif ($kop_fp_k === 'news') $kop_fp_news_list($kop_fp_items);
                        else $kop_fp_staff_lists($kop_fp_items, 'h4');
                        ?>
                    </div>
                <?php endforeach; ?>
                <?php if (!$kop_fp_era_parts) : ?><p class="kop-fp-count">Nothing on record is dated to these years yet.</p><?php endif; ?>
            </section>
            <?php endforeach; ?>

            <?php if ($kop_fp_has_memorials) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('memorials'); ?>">
                <h2><?php echo $kop_fp_icon('candle'); ?>Deaths on record</h2>
                <?php $kop_fp_memorial_list($page['memorials']); ?>
                <p class="kop-fp-count"><a href="<?php echo esc_url($page['memorial_url']); ?>">The memorial</a></p>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_violations) : ?>
            <section class="kop-fp-section kop-fp-violations" id="<?php echo $kop_fp_id('violations'); ?>">
                <h2><?php echo $kop_fp_icon('alert-triangle'); ?>Serious violations</h2>
                <p class="kop-fp-count">What state inspectors found and confirmed here, in their own words. Each finding was picked out of the inspection reports and checked by our team before it was listed.</p>
                <?php $kop_fp_violation_cards($kop_fp_violations); ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_lawsuits) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('lawsuits'); ?>">
                <?php echo $kop_fp_alias('lawsuits', 'lawsuits'); ?>
                <h2><?php echo $kop_fp_icon('scale'); ?><?php echo esc_html($kop_fp_title('lawsuits', 'Lawsuits')); ?></h2>
                <?php if (isset($kop_fp_psec['lawsuits'])) $kop_fp_render_items($kop_fp_psec['lawsuits']['items']); ?>
                <?php if (!empty($page['lawsuits'])) : ?>
                <?php $kop_fp_lawsuit_list($page['lawsuits']); ?>
                <?php endif; ?>
                <p class="kop-fp-count"><a href="<?php echo esc_url($page['lawsuits_url']); ?>">Every case in the lawsuit directory</a></p>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_incidents) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('incidents'); ?>"<?php echo $kop_fp_edit('practices', 'critical incidents'); ?>>
                <h2><?php echo $kop_fp_icon('siren'); ?>Incidents on record</h2>
                <?php $kop_fp_incident_list($page['incidents']); ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_news) :
                $kop_fp_n_all = (array) ($page['news'] ?? array());
                $kop_fp_n_media = array();
                if (isset($kop_fp_psec['news'])) {
                    // The post's own news links lead; a record article it already links is not repeated.
                    $kop_fp_n_key = static function ($u) { return strtolower(rtrim(preg_replace('#^https?://(www\.)?#i', '', (string) $u), '/')); };
                    $kop_fp_n_cards = array();
                    foreach ($kop_fp_psec['news']['items'] as $it) {
                        if ($it['type'] === 'card') $kop_fp_n_cards[] = $it['card'];
                        else $kop_fp_n_media[] = $it;
                    }
                    $kop_fp_n_seen = array();
                    foreach ($kop_fp_n_cards as $c) $kop_fp_n_seen[$kop_fp_n_key($c['url'])] = true;
                    $kop_fp_n_all = array_merge($kop_fp_n_cards, array_values(array_filter($kop_fp_n_all, static function ($n) use ($kop_fp_n_seen, $kop_fp_n_key) {
                        return !isset($kop_fp_n_seen[$kop_fp_n_key($n['url'])]);
                    })));
                }
                ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('news'); ?>">
                <?php echo $kop_fp_alias('news', 'news'); ?>
                <h2><?php echo $kop_fp_icon('newspaper'); ?><?php echo esc_html($kop_fp_title('news', 'News coverage')); ?></h2>
                <?php if ($kop_fp_n_media) $kop_fp_render_items($kop_fp_n_media); ?>
                <?php $kop_fp_news_list($kop_fp_n_all); ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_videos && !isset($kop_fp_psec['videos'])) :
                $kop_fp_vids = $page['videos'];
                ?>
            <section class="kop-fp-section kop-fp-videos" id="videos">
                <h2><?php echo $kop_fp_icon('tv'); ?>Videos</h2>
                <div class="kop-vc" data-kop-video-carousel>
                    <div class="kop-vc-viewport" role="group" aria-roledescription="carousel" aria-label="Videos about <?php echo esc_attr($page['name']); ?>" tabindex="0">
                        <?php foreach ($kop_fp_vids as $vi => $v) : ?>
                            <figure class="kop-vc-slide" id="kop-vc-<?php echo (int) $vi; ?>" role="group" aria-roledescription="slide" aria-label="<?php echo (int) $vi + 1; ?> of <?php echo count($kop_fp_vids); ?>">
                                <div class="kop-vc-frame" data-provider="<?php echo esc_attr($v['provider']); ?>" data-id="<?php echo esc_attr($v['id']); ?>" data-title="<?php echo esc_attr($v['title']); ?>">
                                    <button type="button" class="kop-vc-play" aria-label="Play video: <?php echo esc_attr($v['title']); ?>">
                                        <?php if ($v['thumb'] !== '') : ?><img src="<?php echo esc_url($v['thumb']); ?>" alt="" loading="lazy" decoding="async"><?php endif; ?>
                                        <span class="kop-vc-icon" aria-hidden="true"><?php echo $kop_fp_icon('tv'); ?></span>
                                    </button>
                                </div>
                                <figcaption>
                                    <span class="kop-vc-title"><?php echo esc_html($v['title']); ?></span>
                                    <a href="<?php echo esc_url($v['url']); ?>" target="_blank" rel="noopener nofollow">Watch on <?php echo $v['provider'] === 'vimeo' ? 'Vimeo' : 'YouTube'; ?></a>
                                </figcaption>
                            </figure>
                        <?php endforeach; ?>
                    </div>
                    <?php if (count($kop_fp_vids) > 1) : ?>
                    <div class="kop-vc-controls">
                        <button type="button" class="kop-vc-btn" data-dir="-1" aria-label="Previous video">&larr; Previous</button>
                        <span class="kop-vc-count" aria-live="polite"><span data-kop-vc-now>1</span> of <?php echo count($kop_fp_vids); ?></span>
                        <button type="button" class="kop-vc-btn" data-dir="1" aria-label="Next video">Next &rarr;</button>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if (isset($kop_fp_psec['videos'])) $kop_fp_profile_section($kop_fp_psec['videos'], 'kop-fp-videos'); ?>

            <?php if ($kop_fp_has_staff) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('staff'); ?>"<?php echo $kop_fp_edit('staff', 'staff'); ?>>
                <h2><?php echo $kop_fp_icon('users'); ?>Staff</h2>
                <?php $kop_fp_staff_lists($page['staff']); ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_practices) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('practices'); ?>"<?php echo $kop_fp_edit('practices', 'reported practices'); ?>>
                <h2>Reported practices</h2>
                <?php foreach ($page['practices'] as $group) : ?>
                    <h3 class="kop-fp-subhead"><?php echo esc_html($group['label']); ?></h3>
                    <?php $kop_fp_list($group['items'], 'kop-fp-chips'); ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_inspections) :
                $insp = $page['inspections'];
                $sum = $insp['summary'];
                ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('inspections'); ?>">
                <h2><?php echo $kop_fp_icon('clipboard'); ?>Licensing and inspections</h2>
                <dl class="kop-fp-inline-facts">
                    <?php if ($sum['licensed_names'] && (count($sum['licensed_names']) > 1 || strcasecmp($sum['licensed_names'][0], $page['name']) !== 0)) : ?>
                        <div><dt>Licensed as</dt><dd><?php echo esc_html(implode('; ', $sum['licensed_names'])); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['licensed_program_name'] !== '') : ?>
                        <div><dt>Program</dt><dd><?php echo esc_html($sum['licensed_program_name']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['program_category'] !== '') : ?>
                        <div><dt>License category</dt><dd><?php echo esc_html($sum['program_category']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['executive_director'] !== '') : ?>
                        <div><dt>Executive director</dt><dd><?php echo esc_html($sum['executive_director']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['bed_capacity'] !== '') : ?>
                        <div><dt>Licensed capacity</dt><dd><?php echo esc_html($sum['bed_capacity']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['license_expiration'] !== '') : ?>
                        <div><dt>License expires</dt><dd><?php echo esc_html($sum['license_expiration']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['relicense_visit_date'] !== '') : ?>
                        <div><dt>Relicensing visit</dt><dd><?php echo esc_html($sum['relicense_visit_date']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['licensing_action'] !== '') : ?>
                        <div><dt>Licensing action</dt><dd><?php echo esc_html($sum['licensing_action']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['phone'] !== '') : ?>
                        <div><dt>Phone on file</dt><dd><?php echo esc_html($sum['phone']); ?></dd></div>
                    <?php endif; ?>
                    <?php if ($sum['addresses']) : ?>
                        <div><dt><?php echo count($sum['addresses']) > 1 ? 'Licensed addresses' : 'Licensed address'; ?></dt><dd><?php echo esc_html(implode('; ', $sum['addresses'])); ?></dd></div>
                    <?php endif; ?>
                </dl>
                <?php if ($insp['total'] > 0) : ?>
                    <p class="kop-fp-count">
                        <?php echo (int) $insp['total']; ?> inspection <?php echo $insp['total'] === 1 ? 'report' : 'reports'; ?> on file<?php echo $kop_fp_has_violations ? '; the serious findings in them are listed above' : ''; ?>.
                        <?php foreach ($insp['page_urls'] as $url => $sname) : ?>
                            <a href="<?php echo esc_url($url); ?>">Search all <?php echo esc_html($sname); ?> reports</a>
                        <?php endforeach; ?>
                    </p>
                    <?php
                    $kop_fp_finding_total = 0;
                    $kop_fp_reports_with = 0;
                    foreach ($insp['reports'] as $r) {
                        $n = count($r['findings'] ?? array());
                        $kop_fp_finding_total += $n;
                        if ($n) $kop_fp_reports_with++;
                    }
                    ?>
                    <details class="kop-fp-more"<?php echo $kop_fp_finding_total ? ' open' : ''; ?>>
                        <summary><?php echo count($insp['reports']) < $insp['total'] ? 'The newest ' . count($insp['reports']) . ' reports' : 'Every report'; ?>, by date<?php
                            echo $kop_fp_finding_total
                                ? ': ' . (int) $kop_fp_finding_total . ' ' . ($kop_fp_finding_total === 1 ? 'finding' : 'findings') . ' in ' . (int) $kop_fp_reports_with . ' ' . ($kop_fp_reports_with === 1 ? 'report' : 'reports')
                                : '';
                        ?></summary>
                        <ol class="kop-fp-reports">
                            <?php foreach ($insp['reports'] as $r) : ?>
                                <li>
                                    <span class="kop-fp-report-date"><?php echo esc_html($r['date_label'] !== '' ? $r['date_label'] : 'Undated'); ?></span>
                                    <?php if ($r['summary'] !== '') : ?>
                                        <span class="kop-fp-report-summary"><?php echo esc_html($r['summary']); ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['url'] !== '' && preg_match('#^https?://#i', $r['url'])) : ?>
                                        <a class="kop-fp-report-link" href="<?php echo esc_url($r['url']); ?>" target="_blank" rel="noopener">Open report</a>
                                    <?php endif; ?>
                                    <?php if (!empty($r['findings'])) : ?>
                                        <details class="kop-fp-findings">
                                            <summary><?php echo count($r['findings']) === 1 ? '1 finding' : count($r['findings']) . ' findings'; ?></summary>
                                            <ul>
                                                <?php foreach ($r['findings'] as $f) : ?>
                                                    <li>
                                                        <?php if ($f['standard'] !== '' || $f['label'] !== '') : ?>
                                                            <p class="kop-fp-finding-rule">
                                                                <?php if ($f['label'] !== '') : ?><strong><?php echo esc_html($f['label']); ?></strong><?php endif; ?>
                                                                <?php if ($f['standard'] !== '') : ?><span><?php echo esc_html(($f['label'] !== '' ? ': ' : '') . $f['standard']); ?></span><?php endif; ?>
                                                            </p>
                                                        <?php endif; ?>
                                                        <p class="kop-fp-finding-text"><?php echo nl2br(esc_html($f['text'])); ?></p>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </details>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    </details>
                <?php elseif ($insp['page_url'] !== '') : ?>
                    <p class="kop-fp-count">A licensing record is on file. <a href="<?php echo esc_url($insp['page_url']); ?>">Search the state's inspection reports</a>.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_network) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('network'); ?>">
                <h2><?php echo $kop_fp_icon('shuffle'); ?>Connections on the network map</h2>
                <p class="kop-fp-count">The companies, people and programs our research map ties to this program. <a href="<?php echo esc_url($page['network']['map_url']); ?>">Open it on the network map</a>.</p>
                <?php echo function_exists('kop_network_map_embed_html') ? kop_network_map_embed_html((int) $page['id']) : ''; ?>
                <?php foreach ($page['network']['groups'] as $group) : ?>
                    <h3 class="kop-fp-subhead"><?php echo esc_html($group['label']); ?></h3>
                    <ul class="kop-fp-records kop-fp-connections">
                        <?php foreach ($group['items'] as $item) : ?>
                            <li>
                                <?php if ($item['url'] !== '') : ?>
                                    <a href="<?php echo esc_url($item['url']); ?>"><?php echo esc_html($item['name']); ?></a>
                                <?php else : ?>
                                    <span><?php echo esc_html($item['name']); ?></span>
                                <?php endif; ?>
                                <?php if ($item['role'] !== '') : ?><span class="meta"><?php echo esc_html($item['role']); ?></span><?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_docs) : ?>
            <section class="kop-fp-section kop-fp-documents" id="<?php echo $kop_fp_id('documents'); ?>">
                <?php echo $kop_fp_alias('documents', 'documents'); ?>
                <h2><?php echo esc_html($kop_fp_title('documents', 'Documents')); ?></h2>
                <?php if (isset($kop_fp_psec['documents'])) $kop_fp_render_items($kop_fp_psec['documents']['items']); ?>
                <?php if (!empty($page['documents']['html'])) : ?>
                    <?php echo $page['documents']['html']; // Shortcode output, escaped by the shortcode. ?>
                <?php endif; ?>
                <?php if ($kop_fp_has_research) : ?>
                    <h3 class="kop-fp-subhead">Research that mentions this program</h3>
                    <ul class="kop-fp-records">
                        <?php foreach ($page['research'] as $kop_fp_doc) : ?>
                            <li>
                                <a href="<?php echo esc_url($kop_fp_doc['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($kop_fp_doc['title']); ?></a>
                                <?php
                                $kop_fp_doc_meta = array_filter(array($kop_fp_doc['byline'], $kop_fp_doc['why'], isset($kop_fp_doc['pages']) && $kop_fp_doc['pages'] !== '' ? 'Named on ' . $kop_fp_doc['pages'] : ''), 'strlen');
                                if ($kop_fp_doc_meta) :
                                ?>
                                    <span class="meta"><?php echo esc_html(implode(' - ', $kop_fp_doc_meta)); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php if (!empty($page['research'][0]['library'])) : ?>
                        <p class="kop-fp-count"><a href="<?php echo esc_url($page['research'][0]['library']); ?>">The whole research library</a></p>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($kop_fp_has_unsilenced) : ?>
                    <?php echo kop_unsilenced_render($page['unsilenced'], $page['name']); // Escaped inside. ?>
                <?php endif; ?>
                <?php if ($kop_fp_has_survivor_sites) : ?>
                    <?php echo kop_survivor_archives_render($page['survivor_sites'], $page['name']); // Escaped inside. ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_testimony) : ?>
            <section class="kop-fp-section kop-fp-testimony-section" id="<?php echo $kop_fp_id('testimony'); ?>"<?php echo $kop_fp_edit('testimony', 'survivor testimony'); ?>>
                <?php echo $kop_fp_alias('testimony', 'testimony'); ?>
                <h2><?php echo $kop_fp_icon('users'); ?><?php echo esc_html($kop_fp_title('testimony', 'Survivor testimony')); ?></h2>
                <p class="kop-fp-testimony-lead">What people who lived or worked here say happened. Survivors' own words are published with their permission; forum accounts are what was posted publicly and are reported as claims, not findings.</p>
                <?php if (isset($kop_fp_psec['testimony'])) $kop_fp_render_items($kop_fp_psec['testimony']['items']); ?>
                <?php foreach ((array) ($page['testimony'] ?? array()) as $kop_fp_t) : ?>
                    <figure class="kop-fp-testimony">
                        <blockquote><?php foreach (preg_split('/\R\s*\R/', $kop_fp_t['text']) as $kop_fp_para) : ?><p><?php echo nl2br(esc_html(trim($kop_fp_para))); ?></p><?php endforeach; ?></blockquote>
                        <figcaption><?php echo !empty($kop_fp_t['submitted']) ? 'Submitted by a survivor' : 'Survivor account'; ?><?php if ($kop_fp_t['date_label'] !== '') : ?>, shared <?php echo esc_html($kop_fp_t['date_label']); ?><?php endif; ?></figcaption>
                    </figure>
                <?php endforeach; ?>
                <?php if (!empty($page['forum'])) : $kop_fp_forum = $page['forum']; ?>
                    <h3 class="kop-fp-subhead">Reported on survivor forums</h3>
                    <?php if (!empty($kop_fp_forum['incidents'])) : ?>
                    <ol class="kop-fp-timeline kop-fp-forum">
                        <?php foreach ($kop_fp_forum['incidents'] as $inc) : ?>
                            <li>
                                <?php if ($inc['when'] !== '' || $inc['kind'] !== '') : ?>
                                    <p class="kop-fp-tl-head">
                                        <?php if ($inc['when'] !== '') : ?><span class="kop-fp-tl-when"><?php echo esc_html($inc['when']); ?></span><?php endif; ?>
                                        <?php if ($inc['kind'] !== '') : ?><span class="kop-fp-tl-kind"><?php echo esc_html($inc['kind']); ?></span><?php endif; ?>
                                    </p>
                                <?php endif; ?>
                                <p class="kop-fp-tl-text"><?php echo esc_html($inc['text']); ?><?php if ($inc['source'] !== '') $kop_fp_sources(array($inc), 'span'); ?></p>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>
                    <?php if (!empty($kop_fp_forum['leads'])) $kop_fp_list($kop_fp_forum['leads'], 'kop-fp-notes'); ?>
                    <?php if (!empty($kop_fp_forum['links'])) : ?>
                    <ul class="kop-fp-records kop-fp-forum-links">
                        <?php foreach ($kop_fp_forum['links'] as $kop_fp_fl) : ?>
                            <li><a href="<?php echo esc_url($kop_fp_fl['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($kop_fp_fl['label']); ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_notes) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('notes'); ?>"<?php echo $kop_fp_edit('notes', 'research notes'); ?>>
                <h2>Research notes</h2>
                <?php if ($page['notes']) : ?>
                    <ul class="kop-fp-notes">
                        <?php foreach ($page['notes'] as $kop_fp_note) : ?>
                            <li><?php echo kop_facility_pages_cited_html($kop_fp_note); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($page['field_notes']) : ?>
                    <ul class="kop-fp-notes">
                        <?php foreach ($page['field_notes'] as $fn) : ?>
                            <li><?php if ($fn['label'] !== '') : ?><strong><?php echo esc_html($fn['label']); ?>:</strong> <?php endif; ?><?php echo kop_facility_pages_cited_html($fn['text']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_wiki) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('wiki'); ?>">
                <h2>Wiki entries</h2>
                <ul class="kop-fp-records">
                    <?php foreach ($page['wiki'] as $w) :
                        $bits = array_filter(array($w['organization'], $w['place'], $w['years']), 'strlen');
                        ?>
                        <li>
                            <a href="<?php echo esc_url($page['wiki_url']); ?>"><?php echo esc_html($w['title']); ?></a>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_siblings) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('related'); ?>">
                <h2>Other programs run by <?php echo esc_html($page['operator']['name'] !== '' ? $page['operator']['name'] : 'the same operator'); ?></h2>
                <ul class="kop-fp-records kop-fp-siblings">
                    <?php foreach ($page['siblings'] as $s) :
                        $bits = array_filter(array($s['place'], ($s['status'] !== '' && $s['status'] !== 'Unknown') ? $s['status'] : ''), 'strlen');
                        ?>
                        <li>
                            <?php if ($s['url'] !== '') : ?>
                                <a href="<?php echo esc_url($s['url']); ?>"><?php echo esc_html($s['name']); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html($s['name']); ?></span>
                            <?php endif; ?>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if (isset($kop_fp_psec['related'])) $kop_fp_profile_section($kop_fp_psec['related']); ?>

            <?php if ($kop_fp_has_resources) : ?>
            <section class="kop-fp-section" id="<?php echo $kop_fp_id('resources'); ?>">
                <h2>Materials and links</h2>
                <?php if ($page['resources']) :
                    $grouped = array();
                    foreach ($page['resources'] as $r) $grouped[$r['group']][] = $r;
                    ?>
                    <p class="kop-fp-count">Materials the project holds for this facility. Ask through the correction form to see any of them.</p>
                    <?php foreach ($grouped as $group => $items) : ?>
                        <h3 class="kop-fp-subhead"><?php echo esc_html($group); ?></h3>
                        <ul class="kop-fp-notes">
                            <?php foreach ($items as $r) : ?>
                                <li><?php echo esc_html($r['label']); ?><?php if ($r['detail'] !== '') : ?><span class="kop-fp-detail"><?php echo esc_html($r['detail']); ?></span><?php endif; ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endforeach; ?>
                <?php endif; ?>
                <?php if ($page['profile_links']) : ?>
                    <h3 class="kop-fp-subhead"<?php echo $kop_fp_edit('links', 'external links'); ?>>External links</h3>
                    <?php
                    // A program's own site is linked as an archived snapshot
                    // (inc/facility-pages.php); say so once rather than on every row.
                    $kop_fp_has_archived = false;
                    foreach ($page['profile_links'] as $l) {
                        if (!empty($l['live_url'])) { $kop_fp_has_archived = true; break; }
                    }
                    ?>
                    <?php if ($kop_fp_has_archived) : ?>
                        <p class="kop-fp-count">A program's own website is linked as an archived snapshot, so the page reads as it did when it was captured.</p>
                    <?php endif; ?>
                    <ul class="kop-fp-records">
                        <?php foreach ($page['profile_links'] as $l) : ?>
                            <li>
                                <a href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($l['label']); ?></a>
                                <?php if (!empty($l['live_url'])) : ?>
                                    <span class="meta"><a href="<?php echo esc_url($l['go_url']); ?>" target="_blank" rel="nofollow noreferrer noopener">live site</a></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php foreach ((array) ($page['resource_links'] ?? array()) as $kop_fp_group) : ?>
                    <h3 class="kop-fp-subhead"><?php echo esc_html($kop_fp_group['label']); ?></h3>
                    <ul class="kop-fp-records">
                        <?php foreach ($kop_fp_group['links'] as $l) : ?>
                            <li>
                                <a href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($l['label']); ?></a>
                                <?php if (!empty($l['live_url'])) : ?>
                                    <span class="meta"><a href="<?php echo esc_url($l['go_url']); ?>" target="_blank" rel="nofollow noreferrer noopener">live site</a></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php foreach ((array) ($kop_fp_group['credits'] ?? array()) as $kop_fp_credit) : ?>
                        <p class="kop-fp-count">
                            <?php echo $kop_fp_credit['count'] < count($kop_fp_group['links']) ? esc_html(sprintf('%d of these from', $kop_fp_credit['count'])) : 'From'; ?>
                            <a href="<?php echo esc_url($kop_fp_credit['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($kop_fp_credit['label']); ?></a>.
                        </p>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

        </div>

    </div>

    <?php
    /* Where to report this one. Somebody who has just read a facility's record
     * is the likeliest person in the site to need it, so it sits at the end of
     * the record rather than behind another click. One line and a deep link
     * into that state's block, not the whole directory. Prints nothing for a
     * state the directory has not covered.
     *
     * A closed program gets different words, not no words. The agency that
     * licensed it has nothing left to act on, so saying it can would be false
     * hope; but the staff may still hold licences, and deadlines on abuse of
     * a child run from the survivor's age, not from the closing date, so the
     * link stays. The deadline note on the reporting page says the rest. */
    $kop_fp_report_url = function_exists('kop_reporting_state_url')
        ? kop_reporting_state_url($page['state_name'] ?: $page['state_code'])
        : '';
    $kop_fp_closed = $page['status'] === 'Closed';
    if ($kop_fp_report_url !== '') :
    ?>
        <aside class="kop-fp-reporting<?php echo $kop_fp_closed ? ' kop-fp-reporting--closed' : ''; ?>">
            <h2>Reporting this program</h2>
            <?php if ($kop_fp_closed) : ?>
                <p>
                    This program closed<?php echo $page['end_year'] !== '' ? ' in ' . esc_html($page['end_year']) : ''; ?>,
                    so the state agency that licensed it can no longer act against it. Other channels may still
                    be able to: the boards that license the people who worked here, law enforcement, and the
                    civil courts.
                </p>
                <p>
                    <a href="<?php echo esc_url($kop_fp_report_url); ?>">The <?php echo esc_html($page['state_name']); ?>
                    reporting channels</a> list each one and what it can do. Time limits depend on the state, the
                    kind of harm and the survivor's age, and many states have lengthened or removed them for
                    child sexual abuse, so a program closing long ago does not by itself mean it is too late.
                    Laws change, so the time limits we list were correct when we checked them but may have
                    been updated since; a lawyer can tell you where things stand now.
                </p>
            <?php else : ?>
                <p>
                    If something happened here, <a href="<?php echo esc_url($kop_fp_report_url); ?>">the
                    <?php echo esc_html($page['state_name']); ?> reporting channels</a> list who can act and
                    what each one can actually do - the board that licenses the therapist, the agency that
                    licenses the program, and the bodies with a right to investigate it.
                </p>
            <?php endif; ?>
        </aside>
    <?php endif; ?>

    <footer class="kop-fp-footer">
        <?php if ($page['updated_label'] !== '') : ?>
            <span>Record updated <time datetime="<?php echo esc_attr(date('c', strtotime($page['updated_at']) ?: time())); ?>"><?php echo esc_html($page['updated_label']); ?></time>.</span>
        <?php endif; ?>
        <?php if ($kop_fp_parts && $kop_fp_parts['updated'] !== '') : ?>
            <span>Profile <?php echo esc_html(lcfirst($kop_fp_parts['updated'])); ?>.</span>
        <?php elseif ($kop_fp_profile) : ?>
            <span>Profile updated <time datetime="<?php echo esc_attr(get_the_modified_date('c', $kop_fp_profile)); ?>"><?php echo esc_html(get_the_modified_date('', $kop_fp_profile)); ?></time>.</span>
        <?php endif; ?>
        <span>Generated from the Kids Over Profits facility database.</span>
        <a href="<?php echo esc_url($page['submit_url']); ?>">Suggest a correction</a>
        <?php if ($kop_fp_profile) edit_post_link('Edit this profile', '<span class="kop-fp-edit">', '</span>', $kop_fp_profile->ID); ?>
    </footer>
    <?php if ($kop_fp_profile) wp_reset_postdata(); ?>

</article>
<?php
get_footer();
