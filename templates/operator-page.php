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
$kop_op_history_edit = function_exists('kop_ie_attr') ? kop_ie_attr('operator:' . (int) $page['id'] . ':history', 'its history') : '';
$kop_op_has_history = !empty($page['history']) || !empty($page['timeline']) || $kop_op_history_edit !== '';
$kop_op_people = $page['people'] ?? array('leaders' => array(), 'others' => array());
$kop_op_has_people = !empty($kop_op_people['leaders']) || !empty($kop_op_people['others']);
if ($kop_op_has_history) $kop_op_sections['history'] = 'History';
if ($page['facilities']) $kop_op_sections['programs'] = 'Programs';
if ($kop_op_has_people) $kop_op_sections['people'] = 'People';
if ($page['parents'] || $page['subsidiaries']) $kop_op_sections['ownership'] = 'Ownership';
if ($page['news']) $kop_op_sections['news'] = 'News coverage';
if ($page['lawsuits']) $kop_op_sections['lawsuits'] = 'Lawsuits';
if ($page['memorials']) $kop_op_sections['memorials'] = 'Deaths on record';
$kop_op_has_research = !empty($page['research']);
$kop_op_has_unsilenced = !empty($page['unsilenced']['groups']);
$kop_op_has_survivor_sites = !empty($page['survivor_sites']);
$kop_op_has_program_docs = !empty($page['program_docs']['programs']);
$kop_op_has_docs = !empty($page['documents']['html']) || $kop_op_has_program_docs || $kop_op_has_research || $kop_op_has_unsilenced || $kop_op_has_survivor_sites;
if ($kop_op_has_docs) $kop_op_sections['documents'] = 'Documents';
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
<?php
// An admin's pencil (inc/inline-edit.php); '' for everyone else.
$kop_op_edit = function_exists('kop_ie_attr') ? kop_ie_attr('operator:' . (int) $page['id'] . ':all', 'this company') : '';
?>
<article id="operator-<?php echo (int) $page['id']; ?>" class="entry content-bg single-entry kop-facility-profile kop-facility-generated kop-operator-profile" data-kop-bug-feature="operator-page" data-kop-bug-label="Operator page: <?php echo esc_attr($page['name']); ?>">

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">Parent company profile</p>
        <h1 class="entry-title kop-fp-title"<?php echo $kop_op_edit; ?>><?php echo esc_html($page['name']); ?></h1>
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
            <dl class="kop-fp-facts"<?php echo $kop_op_edit; ?>>
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

            <h2>Also see</h2>
            <ul class="kop-fp-list">
                <?php if ($page['network_url'] !== '') : ?>
                    <li><a href="<?php echo esc_url($page['network_url']); ?>">On the network map</a><span class="meta">Its programs, people and owners</span></li>
                <?php endif; ?>
                <?php if (!empty($page['index_url'])) : ?>
                    <li><a href="<?php echo esc_url($page['index_url']); ?>">Every parent company</a><span class="meta">The companies behind the programs</span></li>
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

            <?php if (count($kop_op_sections) > 1) : ?>
            <nav class="kop-fp-jump" aria-label="On this page">
                <?php foreach ($kop_op_sections as $anchor => $label) : ?>
                    <a href="#<?php echo esc_attr($anchor); ?>"><?php echo esc_html($label); ?></a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

            <?php if ($kop_op_has_history) : ?>
            <section class="kop-fp-section kop-op-history" id="history">
                <h2>History</h2>
                <?php if (!empty($page['history'])) : ?>
                    <div class="kop-op-history-text"<?php echo $kop_op_history_edit; ?>>
                        <?php if ($page['history']['status'] === 'draft') : ?>
                            <p class="kop-op-draft"><strong>Draft.</strong> Only admins see this history. Check it against its sources, then use the pencil to correct it and set it to Published.</p>
                            <?php if (!empty($page['history']['notes'])) : ?>
                                <details class="kop-op-review-notes" open>
                                    <summary>What to check before publishing</summary>
                                    <p><?php echo esc_html($page['history']['notes']); ?></p>
                                </details>
                            <?php endif; ?>
                        <?php endif; ?>
                        <?php foreach ($page['history']['paragraphs'] as $kop_op_para) : ?>
                            <p><?php echo kop_operator_history_paragraph_html($kop_op_para); // Escaped inside. ?></p>
                        <?php endforeach; ?>
                        <?php if ($page['history']['sources']) : ?>
                            <h3 class="kop-fp-subhead">Sources</h3>
                            <ol class="kop-op-sources">
                                <?php foreach ($page['history']['sources'] as $kop_op_src) : ?>
                                    <li><?php if ($kop_op_src['url'] !== '') : ?><a href="<?php echo esc_url($kop_op_src['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($kop_op_src['label']); ?></a><?php else : ?><?php echo esc_html($kop_op_src['label']); ?><?php endif; ?></li>
                                <?php endforeach; ?>
                            </ol>
                        <?php endif; ?>
                    </div>
                <?php elseif ($kop_op_history_edit !== '') : ?>
                    <p class="kop-op-draft"<?php echo $kop_op_history_edit; ?>>No written history yet. Use the pencil to add one; it stays a draft, seen only by admins, until it is set to Published.</p>
                <?php endif; ?>
                <?php if (!empty($page['timeline'])) : ?>
                    <h3 class="kop-fp-subhead">Year by year</h3>
                    <p class="kop-fp-count">From the records: the years it ran each program (from the network map, else the program's own opening and closing), lawsuits filed and deaths on record.</p>
                    <?php
                    $kop_op_tl_first = array_slice($page['timeline'], 0, 12);
                    $kop_op_tl_rest = array_slice($page['timeline'], 12);
                    $kop_op_tl_print = static function (array $years) {
                        foreach ($years as $y) {
                            echo '<li><p class="kop-fp-tl-head"><span class="kop-fp-tl-when">' . (int) $y['year'] . '</span></p>';
                            foreach ($y['events'] as $e) {
                                echo '<p class="kop-fp-tl-text"><strong>' . esc_html($e['text']) . '</strong>';
                                if ($e['items']) {
                                    $links = array();
                                    foreach ($e['items'] as $it) {
                                        $links[] = $it['url'] !== ''
                                            ? '<a href="' . esc_url($it['url']) . '">' . esc_html($it['name']) . '</a>'
                                            : esc_html($it['name']);
                                    }
                                    echo ': ' . implode('; ', $links);
                                }
                                echo '</p>';
                            }
                            echo '</li>';
                        }
                    };
                    ?>
                    <ol class="kop-fp-timeline">
                        <?php $kop_op_tl_print($kop_op_tl_first); ?>
                    </ol>
                    <?php if ($kop_op_tl_rest) : ?>
                        <details class="kop-fp-more">
                            <summary><?php echo esc_html(count($kop_op_tl_rest) . ' more ' . (count($kop_op_tl_rest) === 1 ? 'year' : 'years')); ?></summary>
                            <ol class="kop-fp-timeline">
                                <?php $kop_op_tl_print($kop_op_tl_rest); ?>
                            </ol>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page['facilities']) : ?>
            <section class="kop-fp-section" id="programs">
                <h2>Programs it has run</h2>
                <p class="kop-fp-count"><?php echo esc_html(count($page['facilities']) . ' on record' . ($page['open_count'] ? ', open ones first' : '') . '.'); ?></p>
                <ul class="kop-fp-records kop-fp-siblings">
                    <?php foreach ($page['facilities'] as $f) :
                        $bits = array_filter(array($f['place'], $f['years'], ($f['status'] !== '' && $f['status'] !== 'Unknown') ? $f['status'] : ''), 'strlen');
                        ?>
                        <li<?php echo function_exists('kop_ie_attr') ? kop_ie_attr('facility:' . (int) $f['id'] . ':all', $f['name']) : ''; ?>>
                            <a href="<?php echo esc_url($f['url']); ?>"><?php echo esc_html($f['name']); ?></a>
                            <?php if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($kop_op_has_people) : ?>
            <section class="kop-fp-section" id="people">
                <h2>People</h2>
                <p class="kop-fp-count">The people the network map ties to the company, with their other jobs in the industry.</p>
                <?php if ($kop_op_people['leaders']) : ?>
                    <ul class="kop-fp-staff">
                        <?php foreach ($kop_op_people['leaders'] as $person) : ?>
                            <li class="kop-fp-person<?php echo !empty($person['career']) ? ' kop-fp-person--career' : ''; ?>">
                                <p class="kop-fp-person-name"><?php echo esc_html($person['name']); ?></p>
                                <?php if ($person['role'] !== '') : ?><p class="kop-fp-person-role"><?php echo esc_html($person['role']); ?></p><?php endif; ?>
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
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($kop_op_people['others']) : ?>
                    <details class="kop-fp-more">
                        <summary><?php echo esc_html(count($kop_op_people['others']) . ' other staff on record'); ?></summary>
                        <ul class="kop-fp-people">
                            <?php foreach ($kop_op_people['others'] as $person) : ?>
                                <li><?php echo esc_html($person['name'] . ($person['role'] !== '' ? ', ' . $person['role'] : '')); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </details>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page['parents'] || $page['subsidiaries']) : ?>
            <section class="kop-fp-section" id="ownership"<?php echo $kop_op_edit; ?>>
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

            <?php if ($kop_op_has_docs) : ?>
            <section class="kop-fp-section kop-fp-documents" id="documents">
                <h2>Documents</h2>
                <?php if (!empty($page['documents']['html'])) : ?>
                    <?php echo $page['documents']['html']; // FileBird shortcode output. ?>
                <?php endif; ?>
                <?php if ($kop_op_has_program_docs) :
                    $kop_op_pd_total = (int) $page['program_docs']['total'];
                    $kop_op_pd_n = count($page['program_docs']['programs']);
                    ?>
                    <h3 class="kop-fp-subhead">Filed under its programs</h3>
                    <p class="kop-fp-count"><?php echo esc_html(number_format($kop_op_pd_total) . ' ' . ($kop_op_pd_total === 1 ? 'document' : 'documents') . ' across ' . $kop_op_pd_n . ' ' . ($kop_op_pd_n === 1 ? 'program' : 'programs') . ': inspection reports, court records, clippings and more, each on its program page.'); ?></p>
                    <ul class="kop-fp-records kop-op-program-docs">
                        <?php foreach ($page['program_docs']['programs'] as $kop_op_pd) : ?>
                            <li><a href="<?php echo esc_url($kop_op_pd['url']); ?>"><?php echo esc_html($kop_op_pd['name']); ?></a><span class="meta"><?php echo esc_html(number_format($kop_op_pd['count']) . ' ' . ($kop_op_pd['count'] === 1 ? 'document' : 'documents')); ?></span></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <?php if ($kop_op_has_research) : ?>
                    <h3 class="kop-fp-subhead">Research that mentions this company</h3>
                    <ul class="kop-fp-records">
                        <?php foreach ($page['research'] as $kop_op_doc) : ?>
                            <li>
                                <a href="<?php echo esc_url($kop_op_doc['url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($kop_op_doc['title']); ?></a>
                                <?php $kop_op_doc_meta = array_filter(array($kop_op_doc['byline'], $kop_op_doc['why']), 'strlen'); ?>
                                <?php if ($kop_op_doc_meta) : ?>
                                    <span class="meta"><?php echo esc_html(implode(' - ', $kop_op_doc_meta)); ?></span>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                    <p class="kop-fp-count"><a href="<?php echo esc_url($page['research'][0]['library']); ?>">The whole research library</a></p>
                <?php endif; ?>
                <?php if ($kop_op_has_unsilenced) : ?>
                    <?php echo kop_unsilenced_render($page['unsilenced'], $page['name']); // Escaped inside. ?>
                <?php endif; ?>
                <?php if ($kop_op_has_survivor_sites) : ?>
                    <?php echo kop_survivor_archives_render($page['survivor_sites'], $page['name']); // Escaped inside. ?>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <?php if ($page['notes']) : ?>
            <section class="kop-fp-section" id="notes"<?php echo $kop_op_edit; ?>>
                <h2>Research notes</h2>
                <ul class="kop-fp-notes">
                    <?php foreach ($page['notes'] as $note) : ?>
                        <li><?php echo esc_html($note); ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
            <?php endif; ?>

            <?php if ($page['websites']) : ?>
            <section class="kop-fp-section" id="links"<?php echo $kop_op_edit; ?>>
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
