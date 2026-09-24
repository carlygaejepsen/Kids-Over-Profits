<?php
/**
 * Generated operator (parent company) page (/operator/<slug>/).
 *
 * Not a selectable page template: inc/operator-pages.php routes the request
 * here with the view model in $GLOBALS['kop_operator_page'] (built by
 * kop_operator_page_data()). Same layout and classes as the generated
 * facility page (templates/facility-page.php, css/facility-profile.css):
 * header, a facts rail, a reading column of sections.
 */

if (!defined('ABSPATH')) {
    exit;
}

$page = isset($GLOBALS['kop_operator_page']) && is_array($GLOBALS['kop_operator_page']) ? $GLOBALS['kop_operator_page'] : null;
if (!$page) {
    get_header();
    echo '<p>Company not found.</p>';
    get_footer();
    return;
}

$kop_op_sections = array();
if ($page['facilities']) $kop_op_sections['programs'] = 'Programs';
if ($page['parents'] || $page['subsidiaries']) $kop_op_sections['ownership'] = 'Ownership';
if ($page['news']) $kop_op_sections['news'] = 'News coverage';
if ($page['lawsuits']) $kop_op_sections['lawsuits'] = 'Lawsuits';
if ($page['memorials']) $kop_op_sections['memorials'] = 'Deaths on record';
if (!empty($page['documents']['html'])) $kop_op_sections['documents'] = 'Documents';
if ($page['notes']) $kop_op_sections['notes'] = 'Research notes';
if ($page['websites']) $kop_op_sections['links'] = 'Websites';

$kop_op_company_list = static function (array $items) {
    echo '<ul class="kop-fp-records">';
    foreach ($items as $c) {
        echo '<li>';
        if ($c['url'] !== '') {
            echo '<a href="' . esc_url($c['url']) . '">' . esc_html($c['name']) . '</a>';
        } else {
            echo '<span>' . esc_html($c['name']) . '</span>';
        }
        echo '</li>';
    }
    echo '</ul>';
};

get_header();
?>
<article id="operator-<?php echo (int) $page['id']; ?>" class="entry content-bg single-entry kop-facility-profile kop-facility-generated kop-operator-profile" data-kop-bug-feature="operator-page" data-kop-bug-label="Operator page: <?php echo esc_attr($page['name']); ?>">

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">Parent company profile</p>
        <h1 class="entry-title kop-fp-title"><?php echo esc_html($page['name']); ?></h1>
        <?php if ($page['current_name'] !== '') : ?>
            <p class="kop-fp-formerly">Now known as <?php echo esc_html($page['current_name']); ?></p>
        <?php endif; ?>
        <?php if ($page['aka']) : ?>
            <p class="kop-fp-formerly">Also known as <?php echo esc_html(implode(', ', $page['aka'])); ?></p>
        <?php endif; ?>
        <div class="kop-fp-badges">
            <?php if ($page['status'] !== '') : ?>
                <span class="kop-fp-status kop-fp-status--<?php echo esc_attr($page['status_class']); ?>"><?php echo esc_html($page['status']); ?></span>
            <?php endif; ?>
            <?php if ($page['facilities']) : ?>
                <span class="kop-fp-place"><?php echo esc_html(count($page['facilities']) . ' ' . (count($page['facilities']) === 1 ? 'program' : 'programs') . ($page['open_count'] ? ', ' . $page['open_count'] . ' open' : '')); ?></span>
            <?php endif; ?>
        </div>
    </header>

    <div class="kop-fp-grid">

        <aside class="kop-fp-rail" aria-label="Company facts">
            <h2>At a glance</h2>
            <dl class="kop-fp-facts">
                <?php foreach ($page['facts'] as $fact) : ?>
                    <div>
                        <dt><?php echo esc_html($fact['label']); ?></dt>
                        <?php if (is_array($fact['value'])) : ?>
                            <?php foreach ($fact['value'] as $v) : ?><dd><?php echo esc_html($v); ?></dd><?php endforeach; ?>
                        <?php else : ?>
                            <dd><?php echo esc_html($fact['value']); ?></dd>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
                <?php if ($page['parents']) : ?>
                    <div>
                        <dt><?php echo count($page['parents']) > 1 ? 'Parent companies' : 'Parent company'; ?></dt>
                        <?php foreach ($page['parents'] as $p) : ?>
                            <dd><?php if ($p['url'] !== '') : ?><a href="<?php echo esc_url($p['url']); ?>"><?php echo esc_html($p['name']); ?></a><?php else : ?><?php echo esc_html($p['name']); ?><?php endif; ?></dd>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php if ($page['place_count'] > 0) : ?>
                    <div><dt>States and countries</dt><dd><?php echo (int) $page['place_count']; ?></dd></div>
                <?php endif; ?>
            </dl>

            <?php if ($page['network_url'] !== '') : ?>
                <h2>Also see</h2>
                <ul class="kop-fp-list">
                    <li><a href="<?php echo esc_url($page['network_url']); ?>">On the network map</a><span class="meta">Its programs, people and owners</span></li>
                </ul>
            <?php endif; ?>

            <h2>Know something we do not?</h2>
            <p class="kop-fp-rail-text">Corrections, documents and first-hand accounts go into the review queue and are checked before they are published.</p>
            <p class="kop-fp-rail-actions">
                <button type="button" class="kop-submit-info-btn" data-kop-submit-type="facility" data-kop-submit-name="<?php echo esc_attr($page['name']); ?>">Submit info</button>
                <a class="kop-fp-rail-link" href="<?php echo esc_url($page['submit_url']); ?>">Full submission form</a>
            </p>
        </aside>

        <div class="kop-fp-body kop-fp-generated-body">

            <p class="kop-fp-summary"><?php echo esc_html($page['summary']); ?></p>

            <?php if (count($kop_op_sections) > 1) : ?>
            <nav class="kop-fp-jump" aria-label="On this page">
                <?php foreach ($kop_op_sections as $anchor => $label) : ?>
                    <a href="#<?php echo esc_attr($anchor); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <?php if ($page['facilities']) : ?>
            <section class="kop-fp-section" id="programs">
                <h2>Programs it has run</h2>
                <p class="kop-fp-count"><?php echo esc_html(count($page['facilities']) . ' on record' . ($page['open_count'] ? ', open ones first' : '') . '.'); ?></p>
                <ul class="kop-fp-records kop-fp-siblings">
                    <?php foreach ($page['facilities'] as $f) :
                        $bits = array_filter(array($f['place'], $f['years'], ($f['status'] !== '' && $f['status'] !== 'Unknown') ? $f['status'] : ''), 'strlen');
                        ?>
                        <li>
                            <a href="<?php echo esc_url($f['url']); ?>"><?php echo esc_html($f['name']); ?></a>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($page['parents'] || $page['subsidiaries']) : ?>
            <section class="kop-fp-section" id="ownership">
                <h2>Ownership</h2>
                <?php if ($page['parents']) : ?>
                    <h3 class="kop-fp-subhead">Owned by</h3>
                    <?php $kop_op_company_list($page['parents']); ?>
                <?php endif; ?>
                <?php if ($page['subsidiaries']) : ?>
                    <h3 class="kop-fp-subhead">Companies it has owned</h3>
                    <?php $kop_op_company_list($page['subsidiaries']); ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page['news']) : ?>
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
                            <?php if ($n['about'] === 'facility') : ?><span class="meta">About one of its programs</span><?php endif; ?>
                            <?php if ($n['summary'] !== '') : ?><p class="kop-fp-record-summary"><?php echo esc_html($n['summary']); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($page['lawsuits']) : ?>
            <section class="kop-fp-section" id="lawsuits">
                <h2>Lawsuits</h2>
                <ul class="kop-fp-records">
                    <?php foreach ($page['lawsuits'] as $l) :
                        $bits = array_filter(array($l['year'], $l['status'], $l['court'], $l['case_number']), 'strlen');
                        ?>
                        <li>
                            <a href="<?php echo esc_url($page['lawsuits_url']); ?>"><?php echo esc_html($l['case_name']); ?></a>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                            <?php if ($l['outcome'] !== '') : ?><p class="kop-fp-record-summary"><strong>Outcome:</strong> <?php echo esc_html($l['outcome']); ?></p><?php endif; ?>
                            <?php if ($l['summary'] !== '') : ?><p class="kop-fp-record-summary"><?php echo esc_html($l['summary']); ?></p><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <p class="kop-fp-count"><a href="<?php echo esc_url($page['lawsuits_url']); ?>">Every case in the lawsuit directory</a></p>
            </section>
            <?php endif; ?>

            <?php if ($page['memorials']) : ?>
            <section class="kop-fp-section" id="memorials">
                <h2>Deaths on record</h2>
                <p class="kop-fp-count">Young people who died at, or after being in, one of its programs.</p>
                <ul class="kop-fp-records">
                    <?php foreach ($page['memorials'] as $m) :
                        $bits = array_filter(array($m['age'] !== '' ? 'age ' . $m['age'] : '', $m['program'], $m['date_label'], $m['cause']), 'strlen');
                        ?>
                        <li>
                            <?php if ($m['kop_url'] !== '' && preg_match('#^https?://#i', $m['kop_url'])) : ?>
                                <a href="<?php echo esc_url($m['kop_url']); ?>"><?php echo esc_html($m['name']); ?></a>
                            <?php else : ?>
                                <span><?php echo esc_html($m['name']); ?></span>
                            <?php endif; ?>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if (!empty($page['documents']['html'])) : ?>
            <section class="kop-fp-section kop-fp-documents" id="documents">
                <h2>Documents</h2>
                <?php echo $page['documents']['html']; // FileBird shortcode output. ?>
            </section>
            <?php endif; ?>

            <?php if ($page['notes']) : ?>
            <section class="kop-fp-section" id="notes">
                <h2>Research notes</h2>
                <ul class="kop-fp-notes">
                    <?php foreach ($page['notes'] as $note) : ?>
                        <li><?php echo esc_html($note); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($page['websites']) : ?>
            <section class="kop-fp-section" id="links">
                <h2>Websites</h2>
                <p class="kop-fp-count">A company's own website is linked as an archived snapshot, so the page reads as it did when it was captured.</p>
                <ul class="kop-fp-records">
                    <?php foreach ($page['websites'] as $l) : ?>
                        <li>
                            <a href="<?php echo esc_url($l['url']); ?>" target="_blank" rel="noopener nofollow"><?php echo esc_html($l['label'] !== '' ? $l['label'] : $l['url']); ?></a>
                            <?php if (!empty($l['live_url'])) : ?>
                                <span class="meta"><a href="<?php echo esc_url($l['go_url']); ?>" target="_blank" rel="nofollow noreferrer noopener">live site</a></span>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

        </div>

    </div>

    <footer class="kop-fp-footer">
        <?php if ($page['updated_label'] !== '') : ?>
            <span>Record updated <?php echo esc_html($page['updated_label']); ?>.</span>
        <?php endif; ?>
        <span>Generated from the Kids Over Profits facility database.</span>
        <a href="<?php echo esc_url($page['submit_url']); ?>">Suggest a correction</a>
    </footer>

</article>
<?php
get_footer();
