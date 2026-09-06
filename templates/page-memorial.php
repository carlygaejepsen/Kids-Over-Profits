<?php
/**
 * Template Name: In Loving Memory
 *
 * Memorial page listing young people who died in Troubled Teen Industry
 * programs, juvenile detention, and treatment facilities. Rows come from the
 * memorial_victims table (api/init-memorial-db.php) and render as cards in
 * the same visual language as the Lawsuits and Legislation pages.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';

$location_filter = isset($_GET['location']) ? sanitize_text_field($_GET['location']) : '';
$cause_filter    = isset($_GET['cause'])    ? sanitize_text_field($_GET['cause'])    : '';
$decade_filter   = isset($_GET['decade'])   ? sanitize_text_field($_GET['decade'])   : '';

$cause_labels = [
    'suicide'         => 'Suicide',
    'restraint'       => 'Restraint',
    'medical_neglect' => 'Medical neglect',
    'overdose'        => 'Overdose / medication',
    'escape_attempt'  => 'During escape attempt',
    'drowning'        => 'Drowning',
    'exposure'        => 'Exposure / heat',
    'violence'        => 'Violence',
    'accident'        => 'Accident',
    'other'           => 'Other',
    'unknown'         => 'Unknown',
];

try {
    $stmt = $pdo->prepare(
        "SELECT * FROM memorial_victims
         WHERE publication_status = 'published'
         ORDER BY date_of_death IS NULL, date_of_death DESC, id ASC"
    );
    $stmt->execute();
    $victims = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $victims = [];
}

$locations = [];
$causes    = [];
$decades   = [];
foreach ($victims as $v) {
    if ($v['location'])       { $locations[$v['location']] = true; }
    if ($v['cause_category']) { $causes[$v['cause_category']] = true; }
    if ($v['date_of_death']) {
        $decade = floor((int) substr($v['date_of_death'], 0, 4) / 10) * 10;
        $decades[$decade] = true;
    }
}
ksort($locations);
krsort($decades);
// Keep cause dropdown in the label-map order so "Other"/"Unknown" sit last.
$causes = array_intersect_key($cause_labels, $causes);

/**
 * Format a date according to how much of it is known.
 */
function kop_memorial_date($date, $precision) {
    if (!$date) { return ''; }
    $ts = strtotime($date);
    if (!$ts) { return ''; }
    switch ($precision) {
        case 'year':  return date('Y', $ts);
        case 'month': return date('F Y', $ts);
        default:      return date('F j, Y', $ts);
    }
}
?>

<div class="kop-records-page kop-memorial-page">
    <div class="kop-records-header">
        <h1>In Loving Memory</h1>
        <p>One of the worst things about surviving the Troubled Teen Industry is the knowledge that not all of us survive.</p>
        <p>In the last 50 years alone, there have been over 200 reported preventable deaths of young people, aged 18 and below, in reform homes, treatment facilities, and juvenile detention centers.</p>
        <p class="kop-memorial-grieve">We grieve them today and every day.</p>
    </div>

    <div class="kop-records-filters" id="memorial-filters" data-kop-bug-feature="memorial/filters" data-kop-bug-label="Memorial Filters">
        <select class="kop-filter-select" id="filter-location" data-filter="location">
            <option value="">All locations</option>
            <?php foreach ($locations as $loc => $_): ?>
                <option value="<?php echo esc_attr($loc); ?>" <?php selected($location_filter, $loc); ?>>
                    <?php echo esc_html($loc); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-cause" data-filter="cause">
            <option value="">All causes of death</option>
            <?php foreach ($causes as $slug => $label): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($cause_filter, $slug); ?>>
                    <?php echo esc_html($label); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-decade" data-filter="decade">
            <option value="">All years</option>
            <?php foreach ($decades as $decade => $_): ?>
                <option value="<?php echo esc_attr($decade); ?>" <?php selected($decade_filter, (string) $decade); ?>>
                    <?php echo esc_html($decade); ?>s
                </option>
            <?php endforeach; ?>
        </select>

        <button type="button" class="kop-filter-clear" id="clear-filters">Clear filters</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($victims); ?> remembered</span>
    </div>

    <div class="kop-records-grid" id="memorial-grid" data-kop-bug-feature="memorial/cards" data-kop-bug-label="Memorial Cards">
        <?php if (empty($victims)): ?>
            <div class="kop-records-empty">The memorial is being updated. Please check back soon.</div>
        <?php else: ?>
        <?php foreach ($victims as $v):
            $date_label   = kop_memorial_date($v['date_of_death'], $v['date_precision']);
            $decade       = $v['date_of_death'] ? floor((int) substr($v['date_of_death'], 0, 4) / 10) * 10 : '';
            $cause_slug   = $v['cause_category'] ?: 'unknown';
            $name         = trim((string) $v['name']);
            $name_unknown = $name === '' || preg_match('/^\(?\s*(unknown|unidentified|unnamed)/i', $name);
        ?>
        <div class="kop-record-card kop-memorial-card"
             data-location="<?php echo esc_attr($v['location'] ?? ''); ?>"
             data-cause="<?php echo esc_attr($cause_slug); ?>"
             data-decade="<?php echo esc_attr($decade); ?>">

            <div class="kop-card-header">
                <h2 class="kop-card-title<?php echo $name_unknown ? ' kop-memorial-name-unknown' : ''; ?>">
                    <?php echo esc_html($name !== '' ? $name : 'Name not released'); ?>
                </h2>
                <?php if ($v['age'] !== null && $v['age'] !== ''): ?>
                    <span class="kop-badge kop-badge-age">Age <?php echo (int) $v['age']; ?></span>
                <?php endif; ?>
            </div>

            <div class="kop-card-meta">
                <?php if ($date_label): ?>
                    <span class="kop-memorial-date"><?php echo esc_html($date_label); ?></span>
                <?php endif; ?>
                <?php if ($v['location']): ?>
                    <span class="kop-badge kop-badge-jurisdiction"><?php echo esc_html($v['location']); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($v['program']): ?>
            <div class="kop-card-tags">
                <span class="kop-tag kop-tag-facility"><?php echo esc_html($v['program']); ?></span>
            </div>
            <?php endif; ?>

            <?php if ($v['cause_of_death']): ?>
            <div class="kop-memorial-cause">
                <span class="kop-party-label">Cause of death:</span>
                <?php echo esc_html($v['cause_of_death']); ?>
            </div>
            <?php endif; ?>

            <?php if ($v['source_url'] || $v['kop_url']): ?>
            <div class="kop-card-links">
                <?php if ($v['source_url']): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($v['source_url']); ?>" target="_blank" rel="noopener">
                        <?php echo esc_html($v['source_name'] ?: 'Source'); ?> &rarr;
                    </a>
                <?php endif; ?>
                <?php if ($v['kop_url']): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($v['kop_url']); ?>">On Kids Over Profits &rarr;</a>
                <?php endif; ?>
            </div>
            <?php elseif ($v['source_name']): ?>
            <div class="kop-card-links">
                <span class="kop-memorial-source-note"><?php echo esc_html($v['source_name']); ?></span>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="kop-memorial-credit">
        Original source:
        <a href="https://1000placesudontwanttobe.wordpress.com/" target="_blank" rel="noopener">1000 places you don&rsquo;t want to be as a teenager</a>
    </p>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards   = Array.from(document.querySelectorAll('.kop-memorial-card'));
    const countEl = document.getElementById('filter-count');
    const selects = Array.from(document.querySelectorAll('#memorial-filters .kop-filter-select'));

    function applyFilters() {
        const f = {};
        selects.forEach(sel => { f[sel.dataset.filter] = sel.value; });
        let visible = 0;
        cards.forEach(card => {
            const show = (!f.location || card.dataset.location === f.location)
                      && (!f.cause    || card.dataset.cause    === f.cause)
                      && (!f.decade   || card.dataset.decade   === f.decade);
            card.hidden = !show;
            if (show) visible++;
        });
        countEl.textContent = visible + ' remembered';
    }

    selects.forEach(sel => sel.addEventListener('change', applyFilters));

    document.getElementById('clear-filters').addEventListener('click', function() {
        selects.forEach(sel => sel.value = '');
        applyFilters();
    });

    // Honor ?location= / ?cause= / ?decade= deep links that pre-selected an option server-side.
    if (selects.some(sel => sel.value)) { applyFilters(); }
});
</script>

<?php get_footer(); ?>
