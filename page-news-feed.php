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
        // Function to normalize tags to title case
        function normalizeTagCase($tag) {
            // Convert to title case but preserve all-caps acronyms
            $words = explode(' ', $tag);
            $normalized = [];
            foreach ($words as $word) {
                // Keep all-caps acronyms as-is (e.g., "TTI", "PTSD", "USA")
                if (strlen($word) <= 4 && strtoupper($word) === $word) {
                    $normalized[] = $word;
                } else {
                    $normalized[] = ucwords(strtolower($word));
                }
            }
            return implode(' ', $normalized);
        }

        // Tags to exclude - generic terms that apply to almost every TTI article
        $excludedTags = [
            // Generic abuse terms (specific types belong in content warnings)
            'abuse', 'child abuse', 'teen abuse', 'youth abuse',
            'physical abuse', 'sexual abuse', 'emotional abuse', 'psychological abuse',
            'verbal abuse', 'mental abuse', 'spiritual abuse', 'medical abuse',
            'neglect', 'medical neglect', 'educational neglect',
            'restraint', 'seclusion', 'isolation',
            'assault', 'sexual assault', 'physical assault',
            'trauma', 'ptsd', 'mistreatment',
            // Generic TTI/facility terms
            'boarding school', 'boarding schools',
            'troubled teen', 'troubled teens', 'troubled teen industry', 'tti',
            'residential treatment', 'residential treatment center', 'rtc',
            'therapeutic boarding school', 'treatment center', 'treatment facility',
            'behavioral health', 'mental health', 'mental health treatment',
            'reform', 'reform school', 'boot camp',
            'facility', 'program', 'institution',
            // Generic people terms
            'adolescent', 'adolescents', 'teenager', 'teenagers', 'teen', 'teens',
            'youth', 'children', 'child', 'minor', 'minors', 'juvenile', 'juveniles',
            'survivor', 'survivors', 'victim', 'victims', 'student', 'students',
            // Generic news/legal terms
            'abuse allegations', 'allegations', 'misconduct',
            'investigation', 'report', 'news', 'article', 'lawsuit', 'lawsuit filed'
        ];

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

            // Filter out excluded tags and normalize to title case
            foreach (array_unique($itemTags) as $tag) {
                $tagLower = strtolower(trim($tag));
                if (!empty($tagLower) && !in_array($tagLower, $excludedTags)) {
                    $normalizedTag = normalizeTagCase(trim($tag));
                    $allTags[$normalizedTag] = ($allTags[$normalizedTag] ?? 0) + 1;
                }
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

            <?php if (!empty($allTags)): 
                // Categorize tags for the filter section
                $stateTags = [];
                $facilityTags = [];
                $otherTags = [];

                // US states list for matching
                $usStates = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];

                // Get all facilities from all submissions for comparison
                $allFacilities = [];
                foreach ($submissions as $item) {
                    $itemFacilities = json_decode($item['facilities_mentioned'] ?? '[]', true) ?: [];
                    $allFacilities = array_merge($allFacilities, $itemFacilities);
                }
                $allFacilities = array_unique($allFacilities);

                foreach ($allTags as $tag => $count) {
                    if (in_array($tag, $usStates)) {
                        $stateTags[$tag] = $count;
                    } elseif (in_array($tag, $allFacilities)) {
                        $facilityTags[$tag] = $count;
                    } else {
                        $otherTags[$tag] = $count;
                    }
                }

                // Alphabetize tags within each category
                ksort($stateTags);
                ksort($facilityTags);
                ksort($otherTags);
            ?>
            <div class="filter-group collapsible-filter">
                <div class="filter-header" id="tag-filter-header">
                    <label class="filter-label">Filter by Tag:</label>
                    <button class="filter-toggle-btn" aria-expanded="false">
                        <span class="toggle-text">Show Tags</span>
                        <span class="toggle-arrow">▸</span>
                    </button>
                </div>
                <div id="tag-filters" style="display: none;">
                    <div class="filter-buttons" style="margin-bottom: 0.5rem;">
                        <button class="filter-btn active" data-filter-tag="all">All Tags</button>
                    </div>
                    
                    <?php if (!empty($stateTags)): ?>
                    <div class="filter-tag-category">
                        <button class="filter-category-header" data-category="states">
                            <span class="category-label">📍 Location (<?php echo count($stateTags); ?>)</span>
                            <span class="category-arrow">▸</span>
                        </button>
                        <div class="filter-category-content" data-category-content="states" style="display: none;">
                            <div class="filter-buttons">
                                <?php foreach ($stateTags as $tag => $count): ?>
                                    <button class="filter-btn" data-filter-tag="<?php echo esc_attr($tag); ?>">
                                        <?php echo esc_html($tag); ?> (<?php echo $count; ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($facilityTags)): ?>
                    <div class="filter-tag-category">
                        <button class="filter-category-header" data-category="facilities">
                            <span class="category-label">🏢 Facilities (<?php echo count($facilityTags); ?>)</span>
                            <span class="category-arrow">▸</span>
                        </button>
                        <div class="filter-category-content" data-category-content="facilities" style="display: none;">
                            <div class="filter-buttons">
                                <?php foreach ($facilityTags as $tag => $count): ?>
                                    <button class="filter-btn" data-filter-tag="<?php echo esc_attr($tag); ?>">
                                        <?php echo esc_html($tag); ?> (<?php echo $count; ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <?php if (!empty($otherTags)): ?>
                    <div class="filter-tag-category">
                        <button class="filter-category-header" data-category="topics">
                            <span class="category-label">🏷️ Topics (<?php echo count($otherTags); ?>)</span>
                            <span class="category-arrow">▸</span>
                        </button>
                        <div class="filter-category-content" data-category-content="topics" style="display: none;">
                            <div class="filter-buttons">
                                <?php foreach ($otherTags as $tag => $count): ?>
                                    <button class="filter-btn" data-filter-tag="<?php echo esc_attr($tag); ?>">
                                        <?php echo esc_html($tag); ?> (<?php echo $count; ?>)
                                    </button>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
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
and normalize to title case
                $tags = array_filter(array_map(function($tag) use ($excludedTags) {
                    $tagLower = strtolower(trim($tag));
                    if (!empty($tagLower) && !in_array($tagLower, $excludedTags)) {
                        return normalizeTagCase(trim($tag));
                    }
                    return null;
                }, $tags)   return !empty($tagLower) && !in_array($tagLower, $excludedTags);
                });
                
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
                            <div class="news-tags-list">
                                <?php foreach ($tags as $tag): ?>
                                    <span class="news-tag" data-tag="<?php echo esc_attr($tag); ?>"><?php echo esc_html($tag); ?></span>
                                <?php endforeach; ?>
                            </div>
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

    // Tag filter toggle logic
    const tagFilterToggle = document.querySelector('.filter-toggle-btn');
    const tagFilterButtons = document.getElementById('tag-filters');
    if (tagFilterToggle && tagFilterButtons) {
        tagFilterToggle.addEventListener('click', function() {
            const isHidden = tagFilterButtons.style.display === 'none';
            if (isHidden) {
                tagFilterButtons.style.display = 'block';
                this.setAttribute('aria-expanded', 'true');
                this.querySelector('.toggle-text').textContent = 'Hide Tags';
                this.querySelector('.toggle-arrow').textContent = '▾';
            } else {
                tagFilterButtons.style.display = 'none';
                this.setAttribute('aria-expanded', 'false');
                this.querySelector('.toggle-text').textContent = 'Show Tags';
                this.querySelector('.toggle-arrow').textContent = '▸';
            }
        });
    }

    // Filter category collapsing
    document.querySelectorAll('.filter-category-header').forEach(header => {
        header.addEventListener('click', function() {
            const category = this.dataset.category;
            const content = document.querySelector(`[data-category-content="${category}"]`);
            const arrow = this.querySelector('.category-arrow');
            
            if (content.style.display === 'none') {
                content.style.display = 'block';
                arrow.textContent = '▾';
            } else {
                content.style.display = 'none';
                arrow.textContent = '▸';
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
                // If tags are hidden, show them when a tag is clicked
                if (tagFilterButtons.style.display === 'none') {
                    tagFilterToggle.click();
                }

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
