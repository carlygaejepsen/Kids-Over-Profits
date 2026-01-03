<?php
/**
 * Template Name: Wiki Feed
 *
 * Displays a feed of approved wiki/program submissions.
 */

// Enqueue styles
function kop_enqueue_wiki_feed_styles() {
    wp_enqueue_style('wiki-feed-css', get_stylesheet_directory_uri() . '/css/wiki-feed.css', array(), filemtime(get_stylesheet_directory() . '/css/wiki-feed.css'));
}
add_action('wp_enqueue_scripts', 'kop_enqueue_wiki_feed_styles');

get_header();

// Database connection
// Use the same config/PDO logic as the API for consistency
require_once get_stylesheet_directory() . '/api/config.php';

// Fetch approved/published submissions
// "published" is the final status for wiki entries used in get-master-data.php
$status_filter = ['published', 'approved'];
$placeholders = implode(',', array_fill(0, count($status_filter), '?'));

try {
    $sql = "SELECT * FROM wiki_submissions 
            WHERE status IN ($placeholders) 
            ORDER BY created_at DESC 
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($status_filter);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching wiki entries: " . $e->getMessage();
    $submissions = [];
}

?>

<div class="wiki-feed-container">
    <div class="wiki-feed-header">
        <h1>Program Database Additions</h1>
        <p>Recently approved contributions to the TTI Program Database.</p>
    </div>

    <?php if (isset($error_message)): ?>
        <div style="color: red; text-align: center; padding: 20px;">
            <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($submissions)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <p>No recently approved programs found.</p>
        </div>
    <?php else: ?>
        <div class="wiki-feed-grid">
            <?php foreach ($submissions as $item): 
                // Format Date
                $dateAdded = $item['created_at'] ? date('M j, Y', strtotime($item['created_at'])) : 'Unknown Date';
                
                // Program Type Class
                $typeClass = '';
                $typeLower = strtolower($item['program_type'] ?? '');
                if (strpos($typeLower, 'wilderness') !== false) $typeClass = 'type-wilderness';
                elseif (strpos($typeLower, 'residential') !== false) $typeClass = 'type-residential';
                elseif (strpos($typeLower, 'boarding') !== false) $typeClass = 'type-boarding';
                elseif (strpos($typeLower, 'behavioral') !== false) $typeClass = 'type-behavioral';
                
                $org = $item['organization'] ?: 'Independent / Unknown';
                $years = $item['years_active'] ?: 'Unknown';
                
                // Try to get markdown from column, then fallback to JSON data
                $markdown = $item['generated_markdown'] ?? '';
                $isOriginal = false;

                if (empty($markdown) && !empty($item['json_data'])) {
                    $decoded = json_decode($item['json_data'], true);
                    if (is_array($decoded)) {
                        $markdown = $decoded['generatedMarkdown'] ?? $decoded['generated_markdown'] ?? '';
                    }
                }

                // If still empty, try Original Markdown
                if (empty($markdown)) {
                    $markdown = $item['original_markdown'] ?? '';
                    if (!empty($markdown)) {
                        $isOriginal = true;
                    } elseif (!empty($item['json_data'])) {
                        $decoded = json_decode($item['json_data'], true);
                        if (is_array($decoded)) {
                            $markdown = $decoded['originalMarkdown'] ?? $decoded['original_markdown'] ?? '';
                            if (!empty($markdown)) $isOriginal = true;
                        }
                    }
                }

                $btnLabel = 'No Markdown Available';
                if (!empty($markdown)) {
                    $btnLabel = $isOriginal ? 'View Original Markdown' : 'View Generated Markdown';
                }
            ?>
                <article class="wiki-card">
                    <div class="wiki-card-header">
                        <span class="wiki-type-badge <?php echo esc_attr($typeClass); ?>">
                            <?php echo esc_html($item['program_type'] ?: 'Program'); ?>
                        </span>
                        <h2 class="wiki-card-title">
                            <?php echo esc_html($item['program_name']); ?>
                        </h2>
                    </div>

                    <div class="wiki-card-meta">
                        <span class="meta-item location">
                            📍 <?php echo esc_html($item['city_state']); ?>
                        </span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item years">
                            📅 <?php echo esc_html($years); ?>
                        </span>
                    </div>

                    <div class="wiki-card-body">
                        <div class="wiki-summary">
                            <strong>Organization:</strong> <?php echo esc_html($org); ?>
                        </div>
                        
                        <div class="wiki-markdown-section" style="margin-top: 15px;">
                            <button type="button" class="toggle-markdown-btn" onclick="toggleMarkdown(this)" style="background: #edf2f7; border: 1px solid #cbd5e0; padding: 5px 10px; border-radius: 4px; cursor: pointer; font-size: 0.85em;">
                                <?php echo esc_html($btnLabel); ?>
                            </button>
                            <div class="markdown-content" style="display: none; margin-top: 10px; background: #f7fafc; border: 1px solid #e2e8f0; padding: 10px; border-radius: 4px; max-height: 300px; overflow-y: auto;">
                                <?php if (empty($markdown)): ?>
                                    <em style="color: #666;">No markdown content found for this entry.</em>
                                <?php else: ?>
                                    <pre style="white-space: pre-wrap; word-break: break-word; font-family: monospace; font-size: 0.9em; margin: 0;"><?php echo esc_html($markdown); ?></pre>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="wiki-card-footer">
                        Added on <?php echo esc_html($dateAdded); ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
function toggleMarkdown(btn) {
    const content = btn.nextElementSibling;
    if (content.style.display === 'none') {
        content.style.display = 'block';
        btn.textContent = 'Hide Markdown';
    } else {
        content.style.display = 'none';
        btn.textContent = 'View Generated Markdown';
    }
}
</script>

<?php
get_footer();
?>
