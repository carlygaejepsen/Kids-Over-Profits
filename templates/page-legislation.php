<?php
/**
 * Template Name: Legislation Tracker
 *
 * Public tracker of TTI-related bills and laws.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';

$status_filter    = isset($_GET['status'])       ? sanitize_text_field($_GET['status'])       : '';
$jurisdiction     = isset($_GET['jurisdiction']) ? sanitize_text_field($_GET['jurisdiction']) : '';
$position_filter  = isset($_GET['position'])     ? sanitize_text_field($_GET['position'])     : '';
$level_filter     = isset($_GET['level'])        ? sanitize_text_field($_GET['level'])        : '';

try {
    $where  = ["publication_status IN ('approved','published')"];
    $params = [];
    if ($status_filter)    { $where[] = 'status = ?';              $params[] = $status_filter; }
    if ($jurisdiction)     { $where[] = 'jurisdiction = ?';        $params[] = $jurisdiction; }
    if ($position_filter)  { $where[] = 'position = ?';            $params[] = $position_filter; }
    if ($level_filter === 'federal') { $where[] = "jurisdiction = 'Federal'"; }
    if ($level_filter === 'state')   { $where[] = "jurisdiction != 'Federal' AND jurisdiction != ''"; }

    $sql = 'SELECT * FROM legislation WHERE ' . implode(' AND ', $where)
         . ' ORDER BY last_action_date DESC, introduced_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $bills = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $all_stmt = $pdo->prepare("SELECT jurisdiction, status, position FROM legislation WHERE publication_status IN ('approved','published')");
    $all_stmt->execute();
    $all_rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

    $jurisdictions = [];
    $statuses      = [];
    $positions     = [];
    foreach ($all_rows as $row) {
        if ($row['jurisdiction']) $jurisdictions[$row['jurisdiction']] = true;
        if ($row['status'])       $statuses[$row['status']] = true;
        if ($row['position'] && $row['position'] !== 'unknown') $positions[$row['position']] = true;
    }
    ksort($jurisdictions);
    ksort($statuses);
    ksort($positions);

} catch (PDOException $e) {
    $bills = [];
    $jurisdictions = $statuses = $positions = [];
}

$status_labels = [
    'introduced'    => 'Introduced',
    'in_committee'  => 'In Committee',
    'passed_house'  => 'Passed House',
    'passed_senate' => 'Passed Senate',
    'signed'        => 'Signed',
    'enacted'       => 'Enacted',
    'vetoed'        => 'Vetoed',
    'dead'          => 'Dead',
    'unknown'       => 'Unknown',
];
?>

<div class="kop-records-page kop-legislation-page">
    <div class="kop-records-header">
        <h1>Legislation Tracker</h1>
        <p>Bills and laws affecting Troubled Teen Industry facilities, tracked across all 50 states and Congress.</p>
    </div>

    <div class="kop-records-filters" id="legislation-filters">
        <select class="kop-filter-select" id="filter-level" data-filter="level">
            <option value="">Federal &amp; State</option>
            <option value="federal" <?php selected($level_filter, 'federal'); ?>>Federal only</option>
            <option value="state"   <?php selected($level_filter, 'state'); ?>>State only</option>
        </select>

        <select class="kop-filter-select" id="filter-jurisdiction" data-filter="jurisdiction">
            <option value="">All jurisdictions</option>
            <?php foreach ($jurisdictions as $j => $_): ?>
                <option value="<?php echo esc_attr($j); ?>" <?php selected($jurisdiction, $j); ?>><?php echo esc_html($j); ?></option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-status" data-filter="status">
            <option value="">All statuses</option>
            <?php foreach ($statuses as $s => $_): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html($status_labels[$s] ?? ucfirst(str_replace('_', ' ', $s))); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <?php if ($positions): ?>
        <select class="kop-filter-select" id="filter-position" data-filter="position">
            <option value="">All positions</option>
            <?php foreach ($positions as $p => $_): ?>
                <option value="<?php echo esc_attr($p); ?>" <?php selected($position_filter, $p); ?>><?php echo esc_html(ucfirst($p)); ?></option>
            <?php endforeach; ?>
        </select>
        <?php endif; ?>

        <button type="button" class="kop-filter-clear" id="clear-filters">Clear filters</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($bills); ?> bill<?php echo count($bills) !== 1 ? 's' : ''; ?></span>
    </div>

    <div class="kop-records-grid" id="legislation-grid">
        <?php if (empty($bills)): ?>
            <div class="kop-records-empty">No legislation records found. Check back soon.</div>
        <?php else: ?>
        <?php foreach ($bills as $bill):
            $sponsors     = json_decode($bill['sponsors']       ?? '[]', true) ?: [];
            $subject_tags = json_decode($bill['subject_tags']   ?? '[]', true) ?: [];
            $tags         = json_decode($bill['tags']           ?? '[]', true) ?: [];
            $all_tags     = array_unique(array_merge($subject_tags, $tags));
            $status_slug  = $bill['status'] ?: 'unknown';
            $status_label = $status_labels[$status_slug] ?? ucfirst(str_replace('_', ' ', $status_slug));
            $position     = $bill['position'] ?? 'unknown';
            $intro_date   = $bill['introduced_date']   ? date('M j, Y', strtotime($bill['introduced_date']))   : '';
            $last_date    = $bill['last_action_date']   ? date('M j, Y', strtotime($bill['last_action_date'])) : '';
            $is_federal   = ($bill['jurisdiction'] ?? '') === 'Federal';
        ?>
        <div class="kop-record-card"
             data-status="<?php echo esc_attr($status_slug); ?>"
             data-jurisdiction="<?php echo esc_attr($bill['jurisdiction'] ?? ''); ?>"
             data-position="<?php echo esc_attr($position); ?>"
             data-level="<?php echo $is_federal ? 'federal' : 'state'; ?>">

            <div class="kop-card-header">
                <h2 class="kop-card-title">
                    <?php if ($bill['bill_number']): ?>
                        <span class="kop-bill-number"><?php echo esc_html($bill['bill_number']); ?></span>
                    <?php endif; ?>
                    <?php echo esc_html($bill['bill_title']); ?>
                </h2>
                <span class="kop-badge kop-badge-<?php echo esc_attr($status_slug); ?>"><?php echo esc_html($status_label); ?></span>
            </div>

            <div class="kop-card-meta">
                <?php if ($bill['jurisdiction']): ?>
                    <span class="kop-badge kop-badge-jurisdiction"><?php echo esc_html($bill['jurisdiction']); ?></span>
                <?php endif; ?>
                <?php if ($position && $position !== 'unknown'): ?>
                    <span class="kop-badge kop-badge-<?php echo esc_attr($position); ?>">KOP: <?php echo esc_html(ucfirst($position)); ?></span>
                <?php endif; ?>
                <?php if ($bill['session_year']): ?>
                    <span><?php echo esc_html($bill['session_year']); ?> session</span>
                <?php endif; ?>
                <?php if ($bill['chamber'] && $bill['chamber'] !== 'unknown'): ?>
                    <span><?php echo esc_html(ucfirst(str_replace('_', ' ', $bill['chamber']))); ?></span>
                <?php endif; ?>
                <?php if ($intro_date): ?>
                    <span>Introduced <?php echo esc_html($intro_date); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($bill['last_action_text'] || $last_date): ?>
            <div class="kop-bill-last-action">
                <span class="kop-party-label">Last action:</span>
                <?php if ($last_date): ?><span class="kop-last-date"><?php echo esc_html($last_date); ?></span><?php endif; ?>
                <?php if ($bill['last_action_text']): ?>&mdash; <?php echo esc_html($bill['last_action_text']); ?><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($sponsors): ?>
            <div class="kop-bill-sponsors">
                <span class="kop-party-label">Sponsor<?php echo count($sponsors) > 1 ? 's' : ''; ?>:</span>
                <?php echo esc_html(implode(', ', array_slice($sponsors, 0, 3))); ?>
                <?php if (count($sponsors) > 3): ?><em> +<?php echo count($sponsors) - 3; ?> more</em><?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($all_tags): ?>
            <div class="kop-card-tags">
                <?php foreach (array_slice($all_tags, 0, 6) as $tag): ?>
                    <span class="kop-tag"><?php echo esc_html(ucfirst($tag)); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($bill['summary']): ?>
            <button type="button" class="kop-card-summary-toggle" aria-expanded="false">
                <span class="toggle-arrow">▸</span> Show summary
            </button>
            <div class="kop-card-summary" hidden>
                <?php echo nl2br(esc_html($bill['summary'])); ?>
            </div>
            <?php endif; ?>

            <?php if ($bill['full_text_url'] || $bill['official_url']): ?>
            <div class="kop-card-links">
                <?php if ($bill['official_url']): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($bill['official_url']); ?>" target="_blank" rel="noopener">&#128196; Official tracker</a>
                <?php endif; ?>
                <?php if ($bill['full_text_url']): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($bill['full_text_url']); ?>" target="_blank" rel="noopener">&#128196; Full text</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/public-records.css?v=<?php echo filemtime(get_stylesheet_directory() . '/css/public-records.css'); ?>">
<style>
.kop-card-parties, .kop-bill-sponsors, .kop-bill-last-action { font-size: 0.86em; color: #555; }
.kop-party-label  { font-weight: 600; margin-right: 0.3em; color: var(--kop-midnight-blue); }
.kop-bill-number  { font-size: 0.85em; font-weight: 700; color: var(--kop-teal); display: block; margin-bottom: 0.15em; }
.kop-last-date    { font-weight: 600; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards   = Array.from(document.querySelectorAll('.kop-record-card'));
    const countEl = document.getElementById('filter-count');

    function applyFilters() {
        const level  = document.getElementById('filter-level').value;
        const juris  = document.getElementById('filter-jurisdiction').value;
        const status = document.getElementById('filter-status').value;
        const posEl  = document.getElementById('filter-position');
        const pos    = posEl ? posEl.value : '';

        let visible = 0;
        cards.forEach(card => {
            const show = (!level  || card.dataset.level        === level)
                      && (!juris  || card.dataset.jurisdiction === juris)
                      && (!status || card.dataset.status       === status)
                      && (!pos    || card.dataset.position     === pos);
            card.hidden = !show;
            if (show) visible++;
        });
        const label = visible === 1 ? 'bill' : 'bills';
        countEl.textContent = visible + ' ' + label;
    }

    document.querySelectorAll('.kop-filter-select').forEach(sel => sel.addEventListener('change', applyFilters));

    document.getElementById('clear-filters').addEventListener('click', function() {
        document.querySelectorAll('.kop-filter-select').forEach(sel => sel.value = '');
        applyFilters();
    });

    document.querySelectorAll('.kop-card-summary-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const summary = this.nextElementSibling;
            const open = summary.hasAttribute('hidden');
            summary.toggleAttribute('hidden', !open);
            this.setAttribute('aria-expanded', open);
            this.querySelector('.toggle-arrow').textContent = open ? '▾' : '▸';
            this.childNodes[1].textContent = open ? ' Hide summary' : ' Show summary';
        });
    });
});
</script>

<?php get_footer(); ?>
