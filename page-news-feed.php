<?php
/**
 * Template Name: News Feed
 *
 * Displays a feed of approved news submissions.
 */

// Enqueue styles
function kop_enqueue_news_feed_styles() {
    wp_enqueue_style('news-feed-css', get_stylesheet_directory_uri() . '/css/news-feed.css', array(), filemtime(get_stylesheet_directory() . '/css/news-feed.css'));
}
add_action('wp_enqueue_scripts', 'kop_enqueue_news_feed_styles');

get_header();

// Database connection
global $wpdb; // Use global WPDB or the custom connection if preferred, but existing code uses PDO in API.
// Let's use the same PDO logic as the API for consistency with the JSON data structure.
require_once get_stylesheet_directory() . '/api/config.php';

// Fetch approved submissions
$status_filter = ['approved', 'published'];
$placeholders = implode(',', array_fill(0, count($status_filter), '?'));

try {
    $sql = "SELECT * FROM news_submissions 
            WHERE status IN ($placeholders) 
            ORDER BY publication_date DESC, created_at DESC 
            LIMIT 50";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($status_filter);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $error_message = "Error fetching news: " . $e->getMessage();
    $submissions = [];
}

?>

<div class="news-feed-container">
    <div class="news-feed-header">
        <h1>Troubled Teen Industry News</h1>
        <p>Latest updates, investigations, and reports monitored by our team.</p>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="news-error">
            <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($submissions)): ?>
        <div class="news-empty">
            <p>No news articles found at this time.</p>
        </div>
    <?php else: ?>
        <div class="news-feed-grid">
            <?php foreach ($submissions as $item): 
                // Decode JSON fields
                $facilities = json_decode($item['facilities_mentioned'], true) ?: [];
                $staff = json_decode($item['staff_mentioned'], true) ?: [];
                $warnings = json_decode($item['content_warnings'], true) ?: [];
                
                // Format Date
                $pubDate = $item['publication_date'] ? date('M j, Y', strtotime($item['publication_date'])) : 'Unknown Date';
                
                // Determine Title (Use alternate if available, otherwise original)
                // Actually, the requirement might be to use the alternate title if the original was sensationalist.
                // But usually we want to show the one that is "better". 
                // Let's default to article_title, but if alternate_title exists and is non-empty, maybe use that?
                // The API saves both. Let's stick to article_title for now as the primary, 
                // unless we see a pattern where alternate is the "safe" one.
                // Re-reading news_processor.php: "Original title is sensationalist... (create alternate title)"
                // So yes, alternate_title is likely the preferred one for display if it exists.
                $displayTitle = !empty($item['alternate_title']) ? $item['alternate_title'] : $item['article_title'];
            ?>
                <article class="news-card">
                    <div class="news-card-header">
                        <span class="news-type-badge type-<?php echo esc_attr($item['article_type']); ?>">
                            <?php echo esc_html(ucfirst($item['article_type'])); ?>
                        </span>
                        <h2 class="news-card-title">
                            <a href="<?php echo esc_url($item['article_url']); ?>" target="_blank" rel="noopener noreferrer">
                                <?php echo esc_html($displayTitle); ?>
                            </a>
                        </h2>
                    </div>

                    <div class="news-card-meta">
                        <span class="meta-item author">
                            <?php echo esc_html($item['author'] ?: 'Unknown Author'); ?>
                        </span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item publication">
                            <?php echo esc_html($item['publication_name'] ?: 'Unknown Publication'); ?>
                        </span>
                        <span class="meta-separator">•</span>
                        <span class="meta-item date">
                            <?php echo esc_html($pubDate); ?>
                        </span>
                    </div>

                    <?php if (!empty($warnings)): ?>
                        <div class="news-warnings">
                            <?php foreach ($warnings as $warning): ?>
                                <span class="warning-tag">⚠️ <?php echo esc_html($warning); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="news-card-body">
                        <div class="news-summary">
                            <?php echo nl2br(esc_html($item['summary'])); ?>
                        </div>
                    </div>

                    <?php if (!empty($facilities) || !empty($staff)): ?>
                        <div class="news-entities">
                            <?php if (!empty($facilities)): ?>
                                <div class="entity-group">
                                    <span class="entity-label">Facilities:</span>
                                    <span class="entity-value"><?php echo esc_html(implode(', ', $facilities)); ?></span>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($staff)): ?>
                                <div class="entity-group">
                                    <span class="entity-label">Key Figures:</span>
                                    <span class="entity-value"><?php echo esc_html(implode(', ', $staff)); ?></span>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="news-card-footer">
                        <a href="<?php echo esc_url($item['article_url']); ?>" target="_blank" rel="noopener noreferrer" class="read-more-btn">
                            Read Original Article 
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                            </svg>
                        </a>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<?php
get_footer();
?>
