<?php
/**
 * /operator/: every parent company with a page (inc/operator-pages.php routes
 * here with $GLOBALS['kop_operator_index'], rows from
 * kop_operator_history_index_rows()). The major companies as cards, the rest
 * as a list. Uses the facility profile stylesheet.
 */

if (!defined('ABSPATH')) {
    exit;
}

$kop_oi = isset($GLOBALS['kop_operator_index']) && is_array($GLOBALS['kop_operator_index']) ? $GLOBALS['kop_operator_index'] : array('rows' => array(), 'title' => 'Parent companies', 'summary' => '');
$kop_oi_major = array();
$kop_oi_other = array();
foreach ($kop_oi['rows'] as $r) {
    if ($r['major']) {
        $kop_oi_major[] = $r;
    } else {
        $kop_oi_other[] = $r;
    }
}
$kop_oi_bits = static function (array $r) {
    $bits = array();
    if ($r['programs'] > 0) $bits[] = $r['programs'] . ' ' . ($r['programs'] === 1 ? 'program' : 'programs') . ($r['open'] ? ', ' . $r['open'] . ' open' : '');
    if ($r['states'] > 1) $bits[] = $r['states'] . ' states and countries';
    if ($r['years'] !== '') $bits[] = $r['years'];
    if ($r['status'] !== '' && strcasecmp($r['status'], 'Active') !== 0) $bits[] = $r['status'];
    return $bits;
};

get_header();
?>
<article class="entry content-bg single-entry kop-facility-profile kop-facility-generated kop-operator-profile kop-operator-index" data-kop-bug-feature="operator-index" data-kop-bug-label="Parent companies index">

    <header class="kop-fp-header">
        <p class="kop-fp-eyebrow">Who runs the programs</p>
        <h1 class="entry-title kop-fp-title"><?php echo esc_html($kop_oi['title']); ?></h1>
        <p class="kop-fp-formerly"><?php echo esc_html($kop_oi['summary']); ?></p>
    </header>

    <div class="kop-fp-body kop-fp-generated-body kop-oi-body">

        <?php if ($kop_oi_major) : ?>
        <section class="kop-fp-section" id="major">
            <h2>Major companies</h2>
            <p class="kop-fp-count">Each page lists the programs the company has run, its history year by year, the people who ran it, news, lawsuits, deaths on record and the documents filed under it and its programs.</p>
            <ul class="kop-oi-cards">
                <?php foreach ($kop_oi_major as $r) : ?>
                    <li class="kop-oi-card">
                        <h3><a href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['name']); ?></a></h3>
                        <?php $bits = $kop_oi_bits($r); if ($bits) : ?><p class="kop-oi-meta"><?php echo esc_html(implode(' | ', $bits)); ?></p><?php endif; ?>
                        <?php if ($r['lede'] !== '') : ?><p class="kop-oi-lede"><?php echo esc_html($r['lede']); ?></p><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <?php if ($kop_oi_other) : ?>
        <section class="kop-fp-section" id="others">
            <h2>Other companies</h2>
            <ul class="kop-fp-records">
                <?php foreach ($kop_oi_other as $r) : ?>
                    <li>
                        <a href="<?php echo esc_url($r['url']); ?>"><?php echo esc_html($r['name']); ?></a>
                        <?php $bits = $kop_oi_bits($r); if ($bits) : ?><span class="meta"><?php echo esc_html(implode(' | ', $bits)); ?></span><?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </section>
        <?php endif; ?>

        <section class="kop-fp-section" id="contribute">
            <h2>Know something we do not?</h2>
            <p class="kop-fp-rail-text">Ownership changes, executives, documents and first-hand accounts go into the review queue and are checked before they are published.</p>
            <p class="kop-fp-rail-actions">
                <a class="kop-fp-rail-link" href="<?php echo esc_url(home_url('/tti-data-submission/')); ?>">Submit information</a>
            </p>
        </section>

    </div>

</article>
<?php
get_footer();
