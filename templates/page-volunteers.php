<?php
/**
 * Template Name: Volunteer Projects
 *
 * Public listing of volunteer opportunities.
 */

if (!defined('ABSPATH')) { exit; }

get_header();
require_once get_stylesheet_directory() . '/api/config.php';

$type_filter   = isset($_GET['type'])   ? sanitize_text_field($_GET['type'])   : '';
$status_filter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : 'open';

$type_labels = [
    'research'   => 'Research',
    'data_entry' => 'Data Entry',
    'advocacy'   => 'Advocacy',
    'tech'       => 'Tech',
    'outreach'   => 'Outreach',
    'other'      => 'Other',
];

try {
    $where  = ["publication_status = 'published'"];
    $params = [];
    if ($type_filter)   { $where[] = 'project_type = ?'; $params[] = $type_filter; }
    if ($status_filter) { $where[] = 'status = ?';       $params[] = $status_filter; }

    $sql = 'SELECT * FROM volunteer_projects WHERE ' . implode(' AND ', $where)
         . " ORDER BY FIELD(status,'open','filled','completed','archived'), title ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Status counts for filter tabs
    $count_stmt = $pdo->prepare("SELECT status, COUNT(*) as cnt FROM volunteer_projects WHERE publication_status = 'published' GROUP BY status");
    $count_stmt->execute();
    $status_counts = [];
    foreach ($count_stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $status_counts[$r['status']] = (int) $r['cnt'];
    }

} catch (PDOException $e) {
    $projects = [];
    $status_counts = [];
}
?>

<div class="kop-records-page kop-volunteers-page">
    <div class="kop-records-header">
        <h1>Volunteer Opportunities</h1>
        <p>Help us track and expose the Troubled Teen Industry. All skill levels welcome.</p>
    </div>

    <div class="kop-records-filters" id="volunteer-filters">
        <select class="kop-filter-select" id="filter-status" data-filter="status">
            <option value="" <?php selected($status_filter, ''); ?>>All statuses</option>
            <option value="open"      <?php selected($status_filter, 'open'); ?>>Open<?php if (!empty($status_counts['open'])): ?> (<?php echo $status_counts['open']; ?>)<?php endif; ?></option>
            <option value="filled"    <?php selected($status_filter, 'filled'); ?>>Filled</option>
            <option value="completed" <?php selected($status_filter, 'completed'); ?>>Completed</option>
        </select>

        <select class="kop-filter-select" id="filter-type" data-filter="type">
            <option value="">All types</option>
            <?php foreach ($type_labels as $slug => $label): ?>
                <option value="<?php echo esc_attr($slug); ?>" <?php selected($type_filter, $slug); ?>><?php echo esc_html($label); ?></option>
            <?php endforeach; ?>
        </select>

        <button type="button" class="kop-filter-clear" id="clear-filters">Show all</button>
        <span class="kop-filter-count" id="filter-count"><?php echo count($projects); ?> project<?php echo count($projects) !== 1 ? 's' : ''; ?></span>
    </div>

    <div class="kop-records-grid" id="volunteers-grid">
        <?php if (empty($projects)): ?>
            <div class="kop-records-empty">
                <p>No open volunteer projects right now &mdash; check back soon, or <a href="<?php echo esc_url(home_url('/contact')); ?>">get in touch</a> if you'd like to help.</p>
            </div>
        <?php else: ?>
        <?php foreach ($projects as $project):
            $skills   = json_decode($project['skills_needed']  ?? '[]', true) ?: [];
            $status   = $project['status'] ?: 'open';
            $type     = $project['project_type'] ?: 'other';
            $type_label = $type_labels[$type] ?? ucfirst($type);
        ?>
        <div class="kop-record-card kop-vol-card"
             data-status="<?php echo esc_attr($status); ?>"
             data-type="<?php echo esc_attr($type); ?>">

            <div class="kop-card-header">
                <h2 class="kop-card-title"><?php echo esc_html($project['title']); ?></h2>
                <span class="kop-badge kop-badge-<?php echo esc_attr($status); ?>">
                    <?php echo esc_html(ucfirst($status)); ?>
                </span>
            </div>

            <div class="kop-card-meta">
                <span class="kop-badge kop-badge-type"><?php echo esc_html($type_label); ?></span>
                <?php if ($project['jurisdiction']): ?>
                    <span class="kop-badge kop-badge-jurisdiction"><?php echo esc_html($project['jurisdiction']); ?></span>
                <?php endif; ?>
            </div>

            <?php if ($project['description']): ?>
            <div class="kop-vol-description">
                <?php echo nl2br(esc_html($project['description'])); ?>
            </div>
            <?php endif; ?>

            <?php if ($skills): ?>
            <div class="kop-card-tags">
                <?php foreach ($skills as $skill): ?>
                    <span class="kop-tag"><?php echo esc_html($skill); ?></span>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <?php if ($project['signup_url'] || $project['contact_email']): ?>
            <div class="kop-vol-cta">
                <?php if ($status === 'open'): ?>
                    <?php if ($project['signup_url']): ?>
                        <a class="kop-card-cta" href="<?php echo esc_url($project['signup_url']); ?>" target="_blank" rel="noopener">Sign up to volunteer</a>
                    <?php elseif ($project['contact_email']): ?>
                        <a class="kop-card-cta kop-card-cta-email" href="mailto:<?php echo esc_attr($project['contact_email']); ?>">Contact us to volunteer</a>
                    <?php endif; ?>
                <?php else: ?>
                    <span class="kop-vol-status-note"><?php echo esc_html(ucfirst($status)); ?> &mdash; not currently accepting volunteers.</span>
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
.kop-vol-description {
    font-size: 0.9em;
    line-height: 1.6;
    color: #444;
}
.kop-vol-cta { margin-top: auto; padding-top: 0.5em; }
.kop-vol-status-note { font-size: 0.85em; color: #888; font-style: italic; }
</style>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const cards   = Array.from(document.querySelectorAll('.kop-record-card'));
    const countEl = document.getElementById('filter-count');

    function applyFilters() {
        const status = document.getElementById('filter-status').value;
        const type   = document.getElementById('filter-type').value;
        let visible  = 0;
        cards.forEach(card => {
            const show = (!status || card.dataset.status === status)
                      && (!type   || card.dataset.type   === type);
            card.hidden = !show;
            if (show) visible++;
        });
        countEl.textContent = visible + ' project' + (visible !== 1 ? 's' : '');
    }

    document.querySelectorAll('.kop-filter-select').forEach(sel => sel.addEventListener('change', applyFilters));

    document.getElementById('clear-filters').addEventListener('click', function() {
        document.querySelectorAll('.kop-filter-select').forEach(sel => sel.value = '');
        applyFilters();
    });
});
</script>

<?php get_footer(); ?>
