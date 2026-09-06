<?php
/**
 * Template Name: Lawsuits
 *
 * Public directory of TTI-related lawsuits and court cases.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';
require_once get_stylesheet_directory() . '/api/facility-aliases.php';  // kop_normalize_name_key, kop_collect_self_names

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

    if ($claim_filter !== '') {
        $filtered = [];
        foreach ($lawsuits as $case) {
            $claims = json_decode((string)($case['claims'] ?? '[]'), true);
            $claims = is_array($claims) ? $claims : [];
            if (in_array($claim_filter, $claims, true)) {
                $filtered[] = $case;
            }
        }
        $lawsuits = $filtered;
    }

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

// Facilities each case is linked to (lawsuit_facility_links -> facilities_master)
// and the news coverage of each case (lawsuit_news_links -> news_submissions).
// Both tables are optional; a missing one just leaves the card without links.
$facility_links = [];   // lawsuit_id => [ ['name' => unique_name, 'keys' => set of normalized names] ]
$news_links     = [];   // lawsuit_id => [ article rows ]
$lawsuit_ids    = array_map(static function ($r) { return (int)$r['id']; }, $lawsuits);
if ($lawsuit_ids) {
    $ph = implode(',', array_fill(0, count($lawsuit_ids), '?'));
    try {
        $stmt = $pdo->prepare(
            "SELECT lf.lawsuit_id, fm.unique_name, fm.json_data
             FROM lawsuit_facility_links lf
             JOIN facilities_master fm ON fm.id = lf.facility_id
             WHERE lf.lawsuit_id IN ($ph)
             ORDER BY lf.lawsuit_id, fm.unique_name"
        );
        $stmt->execute($lawsuit_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $decoded = json_decode((string)$r['json_data'], true);
            $keys = [kop_normalize_name_key((string)$r['unique_name']) => true];
            foreach (array_merge(kop_collect_self_names($decoded), kop_collect_match_aliases($decoded)) as $alias) {
                $k = kop_normalize_name_key($alias);
                if ($k !== '') $keys[$k] = true;
            }
            $facility_links[(int)$r['lawsuit_id']][] = ['name' => $r['unique_name'], 'keys' => $keys];
        }
    } catch (PDOException $e) {
        $facility_links = [];
    }
    try {
        $stmt = $pdo->prepare(
            "SELECT ln.lawsuit_id, n.id, n.article_title, n.alternate_title,
                    n.publication_name, n.publication_date, n.article_url
             FROM lawsuit_news_links ln
             JOIN news_submissions n ON n.id = ln.news_id
             WHERE ln.lawsuit_id IN ($ph)
               AND n.status IN ('approved','published')
               AND n.article_url IS NOT NULL AND n.article_url <> ''
             ORDER BY n.publication_date DESC, n.id DESC"
        );
        $stmt->execute($lawsuit_ids);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $news_links[(int)$r['lawsuit_id']][] = $r;
        }
    } catch (PDOException $e) {
        $news_links = [];
    }
}

// Facility "profile" = the program index filtered to that facility, which is
// how the rest of the site deep-links a facility (global search, story arcs).
$index_url = function_exists('kop_asl_page_url_by_template') ? kop_asl_page_url_by_template('page-tti-program-index.php') : '';
if (!$index_url) $index_url = home_url('/tti-program-index/');
$facility_profile_url = static function (string $name) use ($index_url): string {
    return add_query_arg('search', rawurlencode($name), $index_url);
};

/**
 * Pair a case's mentioned facility names with its linked facilities_master rows.
 * A mention links when one of its spellings (kop_facility_name_variants) matches
 * the linked row's unique_name or an alias; a lone mention pairs with a lone
 * link regardless.
 * Linked rows nothing paired with are appended so every profile is reachable.
 *
 * @return array<int, array{label:string, url:?string}>
 */
$facility_tags_for = static function (array $mentions, array $linked) use ($facility_profile_url): array {
    $tags = [];
    $used = [];
    foreach ($mentions as $mention) {
        $match = null;
        foreach (kop_facility_name_variants((string)$mention) as $variant) {
            $key = kop_normalize_name_key($variant);
            foreach ($linked as $i => $lf) {
                if (!isset($used[$i]) && $key !== '' && isset($lf['keys'][$key])) { $match = $i; break 2; }
            }
        }
        if ($match === null && count($mentions) === 1 && count($linked) === 1 && !isset($used[0])) {
            $match = 0;
        }
        if ($match !== null) {
            $used[$match] = true;
            $tags[] = ['label' => $mention, 'url' => $facility_profile_url($linked[$match]['name'])];
        } else {
            $tags[] = ['label' => $mention, 'url' => null];
        }
    }
    foreach ($linked as $i => $lf) {
        if (!isset($used[$i])) {
            $tags[] = ['label' => $lf['name'], 'url' => $facility_profile_url($lf['name'])];
        }
    }
    return $tags;
};
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

    <div class="kop-records-filters" id="lawsuits-filters" data-kop-bug-feature="lawsuits/filters" data-kop-bug-label="Lawsuits Filters">
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
                <option value="<?php echo esc_attr($c); ?>" <?php selected($claim_filter, $c); ?>>
                    <?php echo esc_html(ucfirst(str_replace('_', ' ', $c))); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <button type="button" class="kop-filter-clear" id="clear-filters">Clear filters</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($lawsuits); ?> cases</span>
    </div>

    <div class="kop-records-grid" id="lawsuits-grid" data-kop-bug-feature="lawsuits/table" data-kop-bug-label="Lawsuits Table">
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
            $lawsuit_id   = (int)$case['id'];
            $facility_tags = $facility_tags_for($facilities, $facility_links[$lawsuit_id] ?? []);
            $coverage      = $news_links[$lawsuit_id] ?? [];
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

            <?php if ($claims || $facility_tags): ?>
            <div class="kop-card-tags">
                <?php foreach (array_slice($claims, 0, 5) as $claim): ?>
                    <span class="kop-tag"><?php echo esc_html(ucfirst(str_replace('_', ' ', $claim))); ?></span>
                <?php endforeach; ?>
                <?php foreach (array_slice($facility_tags, 0, 5) as $tag): ?>
                    <?php if ($tag['url']): ?>
                        <a class="kop-tag kop-tag-facility" href="<?php echo esc_url($tag['url']); ?>" title="View this facility in the program index"><?php echo esc_html($tag['label']); ?></a>
                    <?php else: ?>
                        <span class="kop-tag kop-tag-facility"><?php echo esc_html($tag['label']); ?></span>
                    <?php endif; ?>
                <?php endforeach; ?>
                <?php if (count($facility_tags) > 5): ?><em class="kop-tag-more">+<?php echo count($facility_tags) - 5; ?> more</em><?php endif; ?>
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

            <?php if ($coverage): ?>
            <div class="kop-card-coverage">
                <span class="kop-party-label">News coverage:</span>
                <ul class="kop-coverage-list">
                    <?php foreach ($coverage as $i => $art):
                        $art_title = $art['alternate_title'] ?: $art['article_title'];
                        $art_meta  = array_filter([
                            $art['publication_name'],
                            $art['publication_date'] ? date('M j, Y', strtotime($art['publication_date'])) : '',
                        ]);
                    ?>
                    <li class="kop-coverage-item"<?php echo $i >= 3 ? ' hidden data-coverage-extra' : ''; ?>>
                        <a href="<?php echo esc_url($art['article_url']); ?>" target="_blank" rel="noopener"><?php echo esc_html($art_title); ?></a>
                        <?php if ($art_meta): ?><span class="kop-coverage-meta"><?php echo esc_html(implode(' - ', $art_meta)); ?></span><?php endif; ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (count($coverage) > 3): ?>
                    <button type="button" class="kop-coverage-more" aria-expanded="false">Show <?php echo count($coverage) - 3; ?> more article<?php echo count($coverage) - 3 === 1 ? '' : 's'; ?></button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <?php if ($source_urls || $doc_urls): ?>
            <div class="kop-card-links">
                <?php foreach (array_slice($source_urls, 0, 2) as $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Source &rarr;</a>
                <?php endforeach; ?>
                <?php foreach (array_slice($doc_urls, 0, 2) as $url): ?>
                    <a class="kop-card-link" href="<?php echo esc_url($url); ?>" target="_blank" rel="noopener">Court doc &rarr;</a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

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

    document.querySelectorAll('.kop-coverage-more').forEach(btn => {
        const label = btn.textContent;
        btn.addEventListener('click', function() {
            const expand = this.getAttribute('aria-expanded') !== 'true';
            this.closest('.kop-card-coverage').querySelectorAll('[data-coverage-extra]')
                .forEach(li => { li.hidden = !expand; });
            this.setAttribute('aria-expanded', expand);
            this.textContent = expand ? 'Show fewer articles' : label;
        });
    });
});
</script>

<?php get_footer(); ?>
