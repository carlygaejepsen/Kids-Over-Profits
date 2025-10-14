<?php
/**
 * Facility report layout template part.
 *
 * @package Kids_Over_Profits
 */

if (!isset($args['slug'])) {
    return;
}

$intro_html         = $args['intro_html'] ?? '';
$search_placeholder = $args['search_placeholder'] ?? __('Search all facilities...', 'kidsoverprofits');
$clear_label        = $args['clear_label'] ?? __('Clear Search', 'kidsoverprofits');
$loading_message    = $args['loading_message'] ?? __('Loading report data...', 'kidsoverprofits');
$sort_options       = $args['sort_options'] ?? array();
$controls_classes   = array('controls');

if (!empty($args['controls_class'])) {
    $controls_classes[] = $args['controls_class'];
}

$controls_class_attr = implode(' ', array_map('sanitize_html_class', $controls_classes));
?>

<?php if (!empty($intro_html)) : ?>
    <p><?php echo wp_kses_post($intro_html); ?></p>
<?php endif; ?>

<div class="facility-report-container facility-report-container--<?php echo esc_attr($args['slug']); ?>">
    <header class="report-header">
        <nav id="alphabet-filter" class="alphabet-filter" aria-label="<?php esc_attr_e('Facility alphabet filter', 'kidsoverprofits'); ?>"></nav>

        <div class="<?php echo esc_attr($controls_class_attr); ?>">
            <label class="screen-reader-text" for="searchInput">
                <?php esc_html_e('Search facilities', 'kidsoverprofits'); ?>
            </label>
            <input type="text" id="searchInput" placeholder="<?php echo esc_attr($search_placeholder); ?>" />

            <?php if (!empty($sort_options)) : ?>
                <label class="screen-reader-text" for="sortBy">
                    <?php esc_html_e('Sort facilities', 'kidsoverprofits'); ?>
                </label>
                <select id="sortBy">
                    <?php foreach ($sort_options as $option) :
                        $value = $option['value'] ?? '';
                        $label = $option['label'] ?? '';
                        ?>
                        <option value="<?php echo esc_attr($value); ?>">
                            <?php echo esc_html($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            <?php endif; ?>

            <button id="clearSearch" class="clear-btn">
                <?php echo esc_html($clear_label); ?>
            </button>
        </div>
    </header>

    <main id="report-container" class="facility-report-list" aria-live="polite">
        <p class="loading-message"><?php echo esc_html($loading_message); ?></p>
    </main>
</div>

<div class="report-meta">
    <div class="report-meta__updated">
        <span class="report-meta__label"><?php esc_html_e('Last updated on:', 'kidsoverprofits'); ?></span>
        <time datetime="<?php echo esc_attr(get_the_modified_date('c')); ?>" class="report-meta__value">
            <?php echo esc_html(get_the_modified_date()); ?>
        </time>
    </div>
</div>
