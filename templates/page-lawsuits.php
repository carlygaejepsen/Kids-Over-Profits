<?php
/**
 * Template Name: Lawsuits
 *
 * Public directory of TTI-related lawsuits and court cases.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';

$status_filter  = isset($_GET['status'])       ? sanitize_text_field($_GET['status'])       : '';
$jurisdiction   = isset($_GET['jurisdiction']) ? sanitize_text_field($_GET['jurisdiction']) : '';
$claim_filter   = isset($_GET['claim'])        ? sanitize_text_field($_GET['claim'])        : '';

try {
    $where  = ["publication_status IN ('approved','published')"];
    $params = [];
    if ($status_filter)  { $where[] = 'status = ?';       $params[] = $status_filter; }
    if ($jurisdiction)   { $where[] = 'jurisdiction = ?'; $params[] = $jurisdiction; }

    $sql = 'SELECT * FROM lawsuits WHERE ' . implode(' AND ', $where)
         . ' ORDER BY filing_date DESC, id DESC';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $lawsuits = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collect filter options from all published records for dropdowns
    $all_stmt = $pdo->prepare("SELECT jurisdiction, status, claims FROM lawsuits WHERE publication_status IN ('approved','published')");
    $all_stmt->execute();
    $all_rows = $all_stmt->fetchAll(PDO::FETCH_ASSOC);

    $jurisdictions = [];
    $statuses      = [];
    $all_claims    = [];
    foreach ($all_rows as $row) {
        if ($row['jurisdiction']) $jurisdictions[$row['jurisdiction']] = true;
        if ($row['status'])       $statuses[$row['status']] = true;
        $claims = json_decode($row['claims'] ?? '[]', true) ?: [];
        foreach ($claims as $c) { if ($c) $all_claims[$c] = true; }
    }
    ksort($jurisdictions);
    ksort($statuses);
    ksort($all_claims);

} catch (PDOException $e) {
    $lawsuits = [];
    $jurisdictions = $statuses = $all_claims = [];
}
?>

<div class="kop-records-page kop-lawsuits-page">
    <div class="kop-records-header">
        <h1>Lawsuits &amp; Legal Cases</h1>
        <p>Court cases involving Troubled Teen Industry facilities, operators, and staff.</p>
        <p class="kop-records-cta">
            Know of a case we're missing?
            <a class="kop-card-link" href="<?php echo esc_url(home_url('/submit-lawsuit')); ?>">Submit a lawsuit for review &rarr;</a>
        </p>
    </div>

    <div class="kop-records-filters" id="lawsuits-filters">
        <select class="kop-filter-select" id="filter-status" data-filter="status">
            <option value="">All case statuses</option>
            <?php foreach ($statuses as $s => $_): ?>
                <option value="<?php echo esc_attr($s); ?>" <?php selected($status_filter, $s); ?>>
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $s))); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-jurisdiction" data-filter="jurisdiction">
            <option value="">All jurisdictions</option>
            <?php foreach ($jurisdictions as $j => $_): ?>
                <option value="<?php echo esc_attr($j); ?>" <?php selected($jurisdiction, $j); ?>>
                    <?php echo esc_html($j); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <select class="kop-filter-select" id="filter-claim" data-filter="claim">
            <option value="">All claim types</option>
            <?php foreach ($all_claims as $c => $_): ?>
                <option value="<?php echo esc_attr($c); ?>">
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $c))); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="button" class="kop-filter-clear" id="clear-filters">Clear filters</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($lawsuits); ?> cases</span>
    </div>

    <div class="kop-records-grid" id="lawsuits-grid">
        <?php if (empty($lawsuits)): ?>
            <div class="kop-records-empty">No lawsuits found. Check back soon.</div>
        <?php else: ?>
        <?php foreach ($lawsuits as $case):
            $plaintiffs   = json_decode($case['plaintiffs']   ?? '[]', true) ?: [];
            $defendants   = json_decode($case['defendants']   ?? '[]', true) ?: [];
            $facilities   = json_decode($case['facilities_mentioned'] ?? '[]', true) ?: [];
            $claims       = json_decode($case['claims']       ?? '[]', true) ?: [];
            $source_urls  = json_decode($case['source_urls']  ?? '[]', true) ?: [];
            $doc_urls     = json_decode($case['document_urls'] ?? '[]', true) ?: [];
            $tags         = json_decode($case['tags']         ?? '[]', true) ?: [];
            $filing_date  = $case['filing_date'] ? date('M j, Y', strtotime($case['filing_date'])) : '';
            $status_slug  = $case['status'] ?: 'unknown';
            $status_label = ucfirst(str_replace('_', ' ', $status_slug));
            $claims_json  = htmlspecialchars(json_encode($claims), ENT_QUOTES);
        ?>
        <div class="kop-record-card"
             data-status="<?php echo esc_attr($status_slug); ?>"
             data-jurisdiction="<?php echo esc_attr($case['jurisdiction'] ?? ''); ?>"
             data-claims="<?php echo $claims_json; ?>">

            <div class="kop-card-header">
                <h2 class="kop-card-title"><?php echo esc_html($case['case_name']); ?></h2>
                <span class="kop-badge kop-badge-<?php echo esc_attr($status_slug); ?>"><?php echo esc_html($status_label); ?></span>
            </div>

            <div class="kop-card-meta">
                <?php if ($case['jurisdiction']): ?>
                    <span class="kop-badge kop-badge-jurisdiction"><?php echo esc_html($case['jurisdiction']); ?></span>
                <?php endif; ?>
                <?php if ($case['court']): ?>
                    <span><?php echo esc_html($case['court']); ?></span>
                <?php endif; ?>
                <?php if ($case['case_number']): ?>
                    <span><?php echo esc_html($case['case_number']); ?></span>
                <?php endif; ?>
                <?php if ($filing_date): ?>
                    <span>Filed <?php echo esc_html($filing_date); ?></span>
                <?php endif; ?>
                <?php if ($case['settlement_amount']): ?>
                    <span>Settlement: <strong><?php echo esc_html($case['settlement_amount']); ?></strong></span>
                <?php endif; ?>
            </div>

            <?php if ($plaintiffs || $defendants): ?>
            <div class="kop-card-parties">
                <?php if ($plaintiffs): ?>
                    <div class="kop-party-row">
                        <span class="kop-party-label">Plaintiff<?php echo count($plaintiffs) > 1 ? 's' : ''; ?>:</span>
                        <?php echo esc_html(implode(', ', array_slice($plaintiffs, 0, 3))); ?>
                        <?php if (count($plaintiffs) > 3): ?><em> +<?php echo count($plaintiffs) - 3; ?> more</em><?php endif; ?>
                    </div>
                <?php endif; ?>
                <?php if ($defendants): ?>
                    <div class="kop-party-row">
                        <span class="kop-party-label">Defendant<?php echo count($defendants) > 1 ? 's' : ''; ?>:</span>
                        <?php echo esc_html(implode(', ', array_slice($defendants, 0, 3))); ?>
                        <?php if (count($defendants) > 3): ?><em> +<?php echo count($defendants) - 3; ?> more</em><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($claims || $facilities): ?>
            <div class="kop-card-tags">
                <?php foreach (array_slice($claims, 0, 5) as $claim): ?>
                    <span class="kop-tag"><?php echo esc_html(ucfirst(str_replace('_', ' ', $claim))); ?></span>
                <?php endforeach; ?>
                <?php foreach (array_slice($facilities, 0, 3) as $fac): ?>
                    <span class="kop-tag kop-tag-facility"><?php echo esc_html($fac); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($case['summary']): ?>
            <button type="button" class="kop-card-summary-toggle" aria-expanded="false">
                <span class="toggle-arrow">▸</span> Show summary
            </button>
            <div class="kop-card-summary" hidden>
                <?php echo nl2br(esc_html($case['summary'])); ?>
                <?php if ($case['outcome']): ?>
                    <p><strong>Outcome:</strong> <?php echo esc_html($case['outcome']); ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($source_urls || $doc_urls): ?>
            <div class="kop-card-links">
                <?php foreach (array_slice($source_urls, 0, 2) as $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">&#128196; Source</a>
                <?php endforeach; ?>
                <?php foreach (array_slice($doc_urls, 0, 2) as $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">&#128196; Court doc</a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<link rel="stylesheet" href="<?php echo get_stylesheet_directory_uri(); ?>/css/public-records.css?v=<?php echo filemtime(get_stylesheet_directory() . '/css/public-records.css'); ?>">
<style>
.kop-card-parties { font-size: 0.86em; color: #555; display: flex; flex-direction: column; gap: 0.2em; }
.kop-party-label  { font-weight: 600; margin-right: 0.3em; color: var(--kop-midnight-blue); }
.kop-tag-facility { background: var(--kop-powder-blue); border-color: var(--kop-teal); }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards = Array.from(document.querySelectorAll('.kop-record-card'));
    const countEl = document.getElementById('filter-count');

    function getFilters() {
        return {
            status:       document.getElementById('filter-status').value,
            jurisdiction: document.getElementById('filter-jurisdiction').value,
            claim:        document.getElementById('filter-claim').value,
        };
    }

    function applyFilters() {
        const f = getFilters();
        let visible = 0;
        cards.forEach(card => {
            const statusMatch = !f.status || card.dataset.status === f.status;
            const jurisMatch  = !f.jurisdiction || card.dataset.jurisdiction === f.jurisdiction;
            let claimMatch = true;
            if (f.claim) {
                try {
                    const claims = JSON.parse(card.dataset.claims || '[]');
                    claimMatch = claims.includes(f.claim);
                } catch(_) { claimMatch = false; }
            }
            const show = statusMatch && jurisMatch && claimMatch;
            card.hidden = !show;
            if (show) visible++;
        });
        countEl.textContent = visible + ' case' + (visible !== 1 ? 's' : '');
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
