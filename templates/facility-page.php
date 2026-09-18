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

$kop_fp_list = static function (array $items, $class = 'kop-fp-list') {
    if (!$items) return;
    echo '<ul class="' . esc_attr($class) . '">';
    foreach ($items as $item) {
        echo '<li>' . esc_html($item) . '</li>';
    }
    echo '</ul>';
};

$kop_fp_sections = array();
$kop_fp_has_practices = !empty($page['practices']);
$kop_fp_has_staff = !empty($page['staff']);
$kop_fp_has_notes = !empty($page['notes']) || !empty($page['field_notes']);
$kop_fp_has_news = !empty($page['news']);
$kop_fp_has_lawsuits = !empty($page['lawsuits']);
$kop_fp_has_memorials = !empty($page['memorials']);
$kop_fp_has_inspections = !empty($page['inspections']);
$kop_fp_has_docs = !empty($page['documents']['html']);
$kop_fp_has_wiki = !empty($page['wiki']);
$kop_fp_has_siblings = !empty($page['siblings']);
$kop_fp_has_resources = !empty($page['resources']) || !empty($page['profile_links']);

if ($kop_fp_has_inspections) $kop_fp_sections['inspections'] = 'Licensing and inspections';
if ($kop_fp_has_news) $kop_fp_sections['news'] = 'News coverage';
if ($kop_fp_has_lawsuits) $kop_fp_sections['lawsuits'] = 'Lawsuits';
if ($kop_fp_has_memorials) $kop_fp_sections['memorials'] = 'Deaths on record';
if ($kop_fp_has_practices) $kop_fp_sections['practices'] = 'Reported practices';
if ($kop_fp_has_staff) $kop_fp_sections['staff'] = 'Staff';
if ($kop_fp_has_docs) $kop_fp_sections['documents'] = 'Documents';
if ($kop_fp_has_notes) $kop_fp_sections['notes'] = 'Research notes';
if ($kop_fp_has_wiki) $kop_fp_sections['wiki'] = 'Wiki entries';
if ($kop_fp_has_siblings) $kop_fp_sections['related'] = 'Same operator';
if ($kop_fp_has_resources) $kop_fp_sections['resources'] = 'Materials and links';

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
        <h1 class="entry-title kop-fp-title"><?php echo esc_html($page['name']); ?></h1>
        <?php if ($page['current_name'] !== '') : ?>
            <p class="kop-fp-formerly">Now known as <?php echo esc_html($page['current_name']); ?></p>
        <?php endif; ?>
        <?php if ($page['formerly']) : ?>
            <p class="kop-fp-formerly">Formerly <?php echo esc_html(implode(', ', $page['formerly'])); ?></p>
        <?php endif; ?>
        <?php if ($page['aka']) : ?>
            <p class="kop-fp-formerly">Also known as <?php echo esc_html(implode(', ', $page['aka'])); ?></p>
        <?php endif; ?>
        <div class="kop-fp-badges">
            <?php if ($page['status'] !== '') : ?>
                <span class="kop-fp-status kop-fp-status--<?php echo esc_attr($page['status_class']); ?>"><?php echo esc_html($page['status']); ?></span>
            <?php endif; ?>
            <?php if ($page['place'] !== '') : ?>
                <span class="kop-fp-place"><?php echo esc_html($page['place']); ?></span>
            <?php endif; ?>
        </div>
    </header>

    <div class="kop-fp-grid">

        <aside class="kop-fp-rail" aria-label="Facility facts">
            <h2>At a glance</h2>
            <dl class="kop-fp-facts">
                <?php if ($page['addresses']) : ?>
                    <div>
                        <dt><?php echo count($page['addresses']) > 1 ? 'Addresses' : 'Address'; ?></dt>
                        <?php foreach ($page['addresses'] as $addr) : ?>
                            <dd><?php echo esc_html($addr); ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($page['former_locations']) : ?>
                    <div>
                        <dt>Former locations</dt>
                        <?php foreach ($page['former_locations'] as $fl) : ?>
                            <dd><?php echo esc_html($fl['line'] . ($fl['years'] !== '' ? ' (' . $fl['years'] . ')' : '')); ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($page['facts'] as $fact) : ?>
                    <div>
                        <dt><?php echo esc_html($fact['label']); ?></dt>
                        <?php if (is_array($fact['value'])) : ?>
                            <?php foreach ($fact['value'] as $v) : ?><dd><?php echo esc_html($v); ?></dd><?php endforeach; ?>
                        <?php elseif ($fact['label'] === 'Operator' && $page['operator']['url'] !== '') : ?>
                            <dd><a href="<?php echo esc_url($page['operator']['url']); ?>"><?php echo esc_html($fact['value']); ?></a></dd>
                        <?php else : ?>
                            <dd><?php echo esc_html($fact['value']); ?></dd>
                        <?php endif; ?>
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

        <div class="kop-fp-body kop-fp-generated-body">

            <p class="kop-fp-summary"><?php echo esc_html($page['summary']); ?></p>

            <?php if (count($kop_fp_sections) > 1) : ?>
            <nav class="kop-fp-jump" aria-label="On this page">
                <?php foreach ($kop_fp_sections as $anchor => $label) : ?>
                    <a href="#<?php echo esc_attr($anchor); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <?php if ($kop_fp_has_inspections) :
                $insp = $page['inspections'];
                $sum = $insp['summary'];
                ?>
            <section class="kop-fp-section" id="inspections">
                <h2>Licensing and inspections</h2>
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
                        <?php echo (int) $insp['total']; ?> inspection <?php echo $insp['total'] === 1 ? 'report' : 'reports'; ?> on file<?php echo count($insp['reports']) < $insp['total'] ? ', newest ' . count($insp['reports']) . ' shown' : ''; ?>.
                        <?php foreach ($insp['page_urls'] as $url => $sname) : ?>
                            <a href="<?php echo esc_url($url); ?>">Search all <?php echo esc_html($sname); ?> reports</a>
                        <?php endforeach; ?>
                    </p>
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
                            </li>
                        <?php endforeach; ?>
                    </ol>
                <?php elseif ($insp['page_url'] !== '') : ?>
                    <p class="kop-fp-count">A licensing record is on file. <a href="<?php echo esc_url($insp['page_url']); ?>">Search the state's inspection reports</a>.</p>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_news) : ?>
            <section class="kop-fp-section" id="news">
                <h2>News coverage</h2>
                <ul class="kop-fp-records">
                    <?php foreach ($page['news'] as $n) :
                        $bits = array_filter(array($n['outlet'], $n['date_label'], $n['type']), 'strlen');
                        ?>
                        <li>
                            <?php if ($n['url'] !== '' && preg_match('#^https?://#i', $n['url'])) : ?>
                                <a href="<?php echo esc_url($n['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($n['title']); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html($n['title']); ?></span>
                            <?php endif; ?>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                            <?php if ($n['summary'] !== '') : ?><p class="kop-fp-record-summary"><?php echo esc_html($n['summary']); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_lawsuits) : ?>
            <section class="kop-fp-section" id="lawsuits">
                <h2>Lawsuits</h2>
                <ul class="kop-fp-records">
                    <?php foreach ($page['lawsuits'] as $l) :
                        $bits = array_filter(array($l['year'], $l['status'], $l['court'], $l['case_number']), 'strlen');
                        ?>
                        <li>
                            <a href="<?php echo esc_url($page['lawsuits_url']); ?>"><?php echo esc_html($l['case_name']); ?></a>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                            <?php if ($l['link_type'] === 'mentioned') : ?><span class="meta">Names this facility</span><?php endif; ?>
                            <?php if ($l['outcome'] !== '') : ?><p class="kop-fp-record-summary"><strong>Outcome:</strong> <?php echo esc_html($l['outcome']); ?></p><?php endif; ?>
                            <?php if ($l['summary'] !== '') : ?><p class="kop-fp-record-summary"><?php echo esc_html($l['summary']); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="kop-fp-count"><a href="<?php echo esc_url($page['lawsuits_url']); ?>">Every case in the lawsuit directory</a></p>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_memorials) : ?>
            <section class="kop-fp-section" id="memorials">
                <h2>Deaths on record</h2>
                <ul class="kop-fp-records">
                    <?php foreach ($page['memorials'] as $m) :
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
                                <span class="meta"><a href="<?php echo esc_url($m['source_url']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($m['source_name'] !== '' ? $m['source_name'] : 'Source'); ?></a></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="kop-fp-count"><a href="<?php echo esc_url($page['memorial_url']); ?>">The memorial</a></p>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_practices) : ?>
            <section class="kop-fp-section" id="practices">
                <h2>Reported practices</h2>
                <?php foreach ($page['practices'] as $group) : ?>
                    <h3 class="kop-fp-subhead"><?php echo esc_html($group['label']); ?></h3>
                    <?php $kop_fp_list($group['items'], 'kop-fp-chips'); ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_staff) :
                $staff_labels = array('administrator' => 'Administration', 'notableStaff' => 'Notable staff', 'pastTTIJobs' => 'Staff who came from other programs');
                ?>
            <section class="kop-fp-section" id="staff">
                <h2>Staff</h2>
                <?php foreach ($staff_labels as $key => $label) :
                    if (empty($page['staff'][$key])) continue;
                    ?>
                    <h3 class="kop-fp-subhead"><?php echo esc_html($label); ?></h3>
                    <?php $kop_fp_list($page['staff'][$key], 'kop-fp-people'); ?>
                <?php endforeach; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_docs) : ?>
            <section class="kop-fp-section kop-fp-documents" id="documents">
                <h2>Documents</h2>
                <?php echo $page['documents']['html']; // Shortcode output, escaped by the shortcode. ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_notes) : ?>
            <section class="kop-fp-section" id="notes">
                <h2>Research notes</h2>
                <?php $kop_fp_list($page['notes'], 'kop-fp-notes'); ?>
                <?php if ($page['field_notes']) : ?>
                    <ul class="kop-fp-notes">
                        <?php foreach ($page['field_notes'] as $fn) : ?>
                            <li><?php if ($fn['label'] !== '') : ?><strong><?php echo esc_html($fn['label']); ?>:</strong> <?php endif; ?><?php echo esc_html($fn['text']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($kop_fp_has_wiki) : ?>
            <section class="kop-fp-section" id="wiki">
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
            <section class="kop-fp-section" id="related">
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

            <?php if ($kop_fp_has_resources) : ?>
            <section class="kop-fp-section" id="resources">
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
                    <h3 class="kop-fp-subhead">External links</h3>
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
                                    <span class="meta"><a href="<?php echo esc_url($l['live_url']); ?>" target="_blank" rel="nofollow noreferrer noopener">live site</a></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>
            <?php endif; ?>

        </div>

    </div>

    <footer class="kop-fp-footer">
        <?php if ($page['updated_label'] !== '') : ?>
            <span>Record updated <time datetime="<?php echo esc_attr(date('c', strtotime($page['updated_at']) ?: time())); ?>"><?php echo esc_html($page['updated_label']); ?></time>.</span>
        <?php endif; ?>
        <span>Generated from the Kids Over Profits facility database.</span>
        <a href="<?php echo esc_url($page['submit_url']); ?>">Suggest a correction</a>
    </footer>

</article>
<?php
get_footer();
