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
        <?php
        // Collect all unique tags for the filter (including auto-generated tags)
        $allTags = [];
        $allTypes = [];
        foreach ($submissions as $item) {
            // Manual tags
            $itemTags = json_decode($item['tags'] ?? '[]', true) ?: [];

            // Auto-generate tags from location
            if (!empty($item['article_location'])) {
                $locationParts = array_map('trim', explode(',', $item['article_location']));
                if (count($locationParts) >= 2) {
                    $itemTags[] = end($locationParts);
                } elseif (count($locationParts) === 1) {
                    $itemTags[] = $locationParts[0];
                }
            }

            // Auto-generate tags from facilities
            $itemFacilities = json_decode($item['facilities_mentioned'] ?? '[]', true) ?: [];
            $itemTags = array_merge($itemTags, $itemFacilities);

            // Count unique tags
            foreach (array_unique($itemTags) as $tag) {
                $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
            }

            if (!empty($item['article_type'])) {
                $allTypes[$item['article_type']] = ($allTypes[$item['article_type']] ?? 0) + 1;
            }
        }
        arsort($allTags); // Sort by frequency
        ksort($allTypes);
        ?>

        <div class="news-filters">
            <div class="filter-group">
                <label class="filter-label">Filter by Type:</label>
                <div class="filter-buttons" id="type-filters">
                    <button class="filter-btn active" data-filter-type="all">All</button>
                    <?php foreach ($allTypes as $type => $count): ?>
                        <button class="filter-btn" data-filter-type="<?php echo esc_attr($type); ?>">
                            <?php echo esc_html(ucfirst($type)); ?> (<?php echo $count; ?>)
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>

            <?php if (!empty($allTags)): ?>
            <div class="filter-group">
                <label class="filter-label">Filter by Tag:</label>
                <div class="filter-buttons" id="tag-filters">
                    <button class="filter-btn active" data-filter-tag="all">All</button>
                    <?php foreach (array_slice($allTags, 0, 15) as $tag => $count): ?>
                        <button class="filter-btn" data-filter-tag="<?php echo esc_attr($tag); ?>">
                            <?php echo esc_html($tag); ?> (<?php echo $count; ?>)
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <div class="filter-status" id="filter-status"></div>
        </div>

        <div class="news-feed-grid">
            <?php foreach ($submissions as $item):
                // Decode JSON fields
                $facilities = json_decode($item['facilities_mentioned'], true) ?: [];
                $staff = json_decode($item['staff_mentioned'], true) ?: [];
                $warnings = json_decode($item['content_warnings'], true) ?: [];
                $tags = json_decode($item['tags'] ?? '[]', true) ?: [];

                // Auto-generate tags from other fields
                $autoTags = [];

                // Extract state from location (e.g., "Salt Lake City, Utah" -> "Utah")
                if (!empty($item['article_location'])) {
                    $locationParts = array_map('trim', explode(',', $item['article_location']));
                    if (count($locationParts) >= 2) {
                        $autoTags[] = end($locationParts); // Last part is usually state/country
                    } elseif (count($locationParts) === 1) {
                        $autoTags[] = $locationParts[0];
                    }
                }

                // Add facilities as tags
                foreach ($facilities as $facility) {
                    $autoTags[] = $facility;
                }

                // Merge auto-tags with manual tags, remove duplicates
                $tags = array_unique(array_merge($tags, $autoTags));
                
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
                <article class="news-card" data-type="<?php echo esc_attr($item['article_type']); ?>" data-tags="<?php echo esc_attr(implode(',', $tags)); ?>">
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

                    <?php if (!empty($tags)): ?>
                        <div class="news-tags">
                            <?php foreach ($tags as $tag): ?>
                                <span class="news-tag" data-tag="<?php echo esc_attr($tag); ?>"><?php echo esc_html($tag); ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="news-card-body">
                        <button type="button" class="news-summary-toggle" aria-expanded="false">
                            <span class="toggle-arrow">▸</span> Show Summary
                        </button>
                        <div class="news-summary" style="display: none;">
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

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Summary toggle functionality
    document.querySelectorAll('.news-summary-toggle').forEach(function(btn) {
        btn.addEventListener('click', function() {
            const summary = this.nextElementSibling;
            const arrow = this.querySelector('.toggle-arrow');
            const isHidden = summary.style.display === 'none';

            if (isHidden) {
                summary.style.display = 'block';
                arrow.textContent = '▾';
                this.setAttribute('aria-expanded', 'true');
                this.innerHTML = '<span class="toggle-arrow">▾</span> Hide Summary';
            } else {
                summary.style.display = 'none';
                arrow.textContent = '▸';
                this.setAttribute('aria-expanded', 'false');
                this.innerHTML = '<span class="toggle-arrow">▸</span> Show Summary';
            }
        });
    });

    const cards = document.querySelectorAll('.news-card');
    const typeFilters = document.getElementById('type-filters');
    const tagFilters = document.getElementById('tag-filters');
    const filterStatus = document.getElementById('filter-status');

    let activeType = 'all';
    let activeTag = 'all';

    function filterCards() {
        let visibleCount = 0;

        cards.forEach(card => {
            const cardType = card.dataset.type || '';
            const cardTags = card.dataset.tags ? card.dataset.tags.split(',') : [];

            const typeMatch = activeType === 'all' || cardType === activeType;
            const tagMatch = activeTag === 'all' || cardTags.includes(activeTag);

            if (typeMatch && tagMatch) {
                card.style.display = '';
                visibleCount++;
            } else {
                card.style.display = 'none';
            }
        });

        // Update status
        if (activeType === 'all' && activeTag === 'all') {
            filterStatus.textContent = '';
        } else {
            filterStatus.textContent = `Showing ${visibleCount} of ${cards.length} articles`;
        }
    }

    // Type filter clicks
    if (typeFilters) {
        typeFilters.addEventListener('click', function(e) {
            if (e.target.classList.contains('filter-btn')) {
                typeFilters.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                e.target.classList.add('active');
                activeType = e.target.dataset.filterType;
                filterCards();
            }
        });
    }

    // Tag filter clicks
    if (tagFilters) {
        tagFilters.addEventListener('click', function(e) {
            if (e.target.classList.contains('filter-btn')) {
                tagFilters.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                e.target.classList.add('active');
                activeTag = e.target.dataset.filterTag;
                filterCards();
            }
        });
    }

    // Clickable tags on cards
    document.querySelectorAll('.news-tag').forEach(tag => {
        tag.addEventListener('click', function() {
            const tagValue = this.dataset.tag;
            if (tagFilters) {
                const tagBtn = tagFilters.querySelector(`[data-filter-tag="${tagValue}"]`);
                if (tagBtn) {
                    tagFilters.querySelectorAll('.filter-btn').forEach(btn => btn.classList.remove('active'));
                    tagBtn.classList.add('active');
                    activeTag = tagValue;
                    filterCards();
                    // Scroll to top of filters
                    document.querySelector('.news-filters').scrollIntoView({ behavior: 'smooth', block: 'start' });
                }
            }
        });
    });
});
</script>

<?php
get_footer();
?>
