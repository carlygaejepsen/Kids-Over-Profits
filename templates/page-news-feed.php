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
require_once get_stylesheet_directory() . '/api/news-mentions.php';

// Pagination settings
$per_page = 50;
$current_page = max(1, intval($_GET['pg'] ?? 1));
$offset = ($current_page - 1) * $per_page;

// Archive month filter (format: YYYY-MM)
$archive_month = isset($_GET['archive']) ? sanitize_text_field($_GET['archive']) : '';

// Ongoing-story view (?story=slug): show only that arc's articles.
$story_slug = isset($_GET['story']) ? sanitize_title($_GET['story']) : '';
$current_arc = null;

// Fetch approved submissions
$status_filter = ['approved', 'published'];
$placeholders = implode(',', array_fill(0, count($status_filter), '?'));

try {
    // Build WHERE clause
    $where = "status IN ($placeholders)";
    $params = $status_filter;

    if ($archive_month && preg_match('/^\d{4}-\d{2}$/', $archive_month)) {
        $where .= " AND DATE_FORMAT(publication_date, '%Y-%m') = ?";
        $params[] = $archive_month;
    }

    if ($story_slug !== '') {
        // Tolerates a database that predates the news_story_arcs migration.
        try {
            $arc_stmt = $pdo->prepare("SELECT * FROM news_story_arcs WHERE slug = ?");
            $arc_stmt->execute([$story_slug]);
            $current_arc = $arc_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        } catch (PDOException $e) {
            $current_arc = null;
        }
        if ($current_arc) {
            $where .= " AND story_arc_id = ?";
            $params[] = (int) $current_arc['id'];
        }
    }

    // Get total count for pagination
    $count_sql = "SELECT COUNT(*) FROM news_submissions WHERE $where";
    $count_stmt = $pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_items = (int) $count_stmt->fetchColumn();
    $total_pages = max(1, ceil($total_items / $per_page));

    // Fetch page of results
    $sql = "SELECT * FROM news_submissions
            WHERE $where
            ORDER BY publication_date DESC, created_at DESC
            LIMIT $per_page OFFSET $offset";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Collapse cross-outlet coverage: rows sharing a story_group_id render as
    // one card (the most recent article) with an "Also covered by" list.
    // Pre-migration databases have no story_group_id column; ?? null keeps
    // the feed working there.
    $grouped = [];
    $story_coverage = [];
    foreach ($submissions as $row) {
        $gid = $row['story_group_id'] ?? null;
        $story_key = $gid ? 'g' . $gid : 'i' . $row['id'];
        if (isset($grouped[$story_key])) {
            $story_coverage[$story_key][] = $row;
        } else {
            $grouped[$story_key] = $row;
            $story_coverage[$story_key] = [];
        }
    }

    // Coverage of a displayed story can also live outside this page's window
    // (other pages, other months). Pull those rows into the coverage lists.
    $page_gids = [];
    foreach ($grouped as $row) {
        if (!empty($row['story_group_id'])) {
            $page_gids[(int) $row['story_group_id']] = true;
        }
    }
    if (!empty($page_gids)) {
        $shown_ids = [];
        foreach ($submissions as $row) {
            $shown_ids[(int) $row['id']] = true;
        }
        $gid_ph = implode(',', array_fill(0, count($page_gids), '?'));
        $cov_sql = "SELECT id, article_title, alternate_title, publication_name,
                           publication_date, article_url, story_group_id
                    FROM news_submissions
                    WHERE story_group_id IN ($gid_ph) AND status IN ($placeholders)
                    ORDER BY publication_date DESC, id DESC";
        $cov_stmt = $pdo->prepare($cov_sql);
        $cov_stmt->execute(array_merge(array_keys($page_gids), $status_filter));
        foreach ($cov_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (isset($shown_ids[(int) $row['id']])) {
                continue;
            }
            $story_coverage['g' . (int) $row['story_group_id']][] = $row;
        }
    }
    $submissions = array_values($grouped);

    // Fetch available archive months for the dropdown
    $archive_sql = "SELECT DATE_FORMAT(publication_date, '%Y-%m') AS month_key,
                           DATE_FORMAT(publication_date, '%M %Y') AS month_label,
                           COUNT(*) AS cnt
                    FROM news_submissions
                    WHERE status IN ($placeholders) AND publication_date IS NOT NULL
                    GROUP BY month_key, month_label
                    ORDER BY month_key DESC";
    $archive_stmt = $pdo->prepare($archive_sql);
    $archive_stmt->execute($status_filter);
    $archive_months = $archive_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Ongoing story arcs: $arc_index (id => title/slug) powers the per-card
    // badge; $ongoing_arcs feeds the featured section on the unfiltered front
    // page. Tolerates a pre-migration database (no news_story_arcs table).
    $ongoing_arcs = [];
    $arc_index = [];
    try {
        $arc_rows = $pdo->query(
            "SELECT a.id, a.title, a.slug, a.description, a.status,
                    (SELECT COUNT(*) FROM news_submissions s
                     WHERE s.story_arc_id = a.id AND s.status IN ('approved', 'published')) AS article_count,
                    (SELECT MAX(s.publication_date) FROM news_submissions s
                     WHERE s.story_arc_id = a.id AND s.status IN ('approved', 'published')) AS latest_date
             FROM news_story_arcs a
             ORDER BY a.display_order ASC, a.id ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($arc_rows as $a) {
            $arc_index[(int) $a['id']] = ['title' => $a['title'], 'slug' => $a['slug']];
            if ($a['status'] === 'active' && (int) $a['article_count'] > 0) {
                $ongoing_arcs[] = $a;
            }
        }
        // Latest developments per featured arc (front page only).
        $show_ongoing = !$current_arc && $story_slug === '' && !$archive_month && $current_page === 1;
        if ($ongoing_arcs && $show_ongoing) {
            $dev_stmt = $pdo->prepare(
                "SELECT article_title, alternate_title, publication_name, publication_date, article_url
                 FROM news_submissions
                 WHERE story_arc_id = ? AND status IN ('approved', 'published')
                 ORDER BY publication_date DESC, id DESC
                 LIMIT 3"
            );
            foreach ($ongoing_arcs as &$oa) {
                $dev_stmt->execute([(int) $oa['id']]);
                $oa['latest'] = $dev_stmt->fetchAll(PDO::FETCH_ASSOC);
            }
            unset($oa);
        }
    } catch (PDOException $e) {
        $ongoing_arcs = [];
        $arc_index = [];
        $show_ongoing = false;
    }

} catch (PDOException $e) {
    $error_message = "Error fetching news: " . $e->getMessage();
    $submissions = [];
    $total_pages = 1;
    $archive_months = [];
    $ongoing_arcs = [];
    $arc_index = [];
    $show_ongoing = false;
}

?>

<div class="news-feed-container">
    <div class="news-feed-header">
        <?php if ($current_arc): ?>
            <a class="story-arc-back" href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>">&laquo; All news</a>
            <h1><span class="story-arc-flag">Ongoing story</span> <?php echo esc_html($current_arc['title']); ?></h1>
            <?php if (!empty($current_arc['description'])): ?>
                <p><?php echo esc_html($current_arc['description']); ?></p>
            <?php endif; ?>
            <p class="story-arc-count"><?php echo (int) $total_items; ?> article<?php echo $total_items === 1 ? '' : 's'; ?> in this story, newest first.</p>
        <?php else: ?>
            <h1>Troubled Teen Industry News</h1>
            <p>Latest updates, investigations, and reports monitored by our team.</p>
        <?php endif; ?>

        <?php if (!empty($archive_months) && !$current_arc): ?>
        <div class="news-archive-picker">
            <form method="get" action="">
                <label for="archive-select">Browse by month:</label>
                <select name="archive" id="archive-select" onchange="this.form.submit()">
                    <option value="">All Dates</option>
                    <?php foreach ($archive_months as $am): ?>
                        <option value="<?php echo esc_attr($am['month_key']); ?>"
                            <?php echo $archive_month === $am['month_key'] ? 'selected' : ''; ?>>
                            <?php echo esc_html($am['month_label']); ?> (<?php echo $am['cnt']; ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
            <?php if ($archive_month): ?>
                <a href="<?php echo esc_url(strtok($_SERVER['REQUEST_URI'], '?')); ?>" class="archive-clear-link">Clear filter</a>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

    <?php if (isset($error_message)): ?>
        <div class="news-error">
            <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($ongoing_arcs) && !empty($show_ongoing)): ?>
        <section class="ongoing-stories" data-kop-bug-feature="news-feed/ongoing-stories" data-kop-bug-label="Ongoing Stories">
            <h2 class="ongoing-stories-title">Ongoing Stories</h2>
            <p class="ongoing-stories-sub">Big stories we're following as they develop.</p>
            <div class="ongoing-stories-grid">
                <?php foreach ($ongoing_arcs as $oa):
                    $arc_url = '?story=' . rawurlencode($oa['slug']);
                    $latest_label = !empty($oa['latest_date']) ? date('M j, Y', strtotime($oa['latest_date'])) : '';
                ?>
                    <div class="ongoing-card">
                        <h3 class="ongoing-card-title"><a href="<?php echo esc_url($arc_url); ?>"><?php echo esc_html($oa['title']); ?></a></h3>
                        <div class="ongoing-card-meta">
                            <?php echo (int) $oa['article_count']; ?> article<?php echo (int) $oa['article_count'] === 1 ? '' : 's'; ?>
                            <?php if ($latest_label): ?> · updated <?php echo esc_html($latest_label); ?><?php endif; ?>
                        </div>
                        <?php if (!empty($oa['description'])): ?>
                            <p class="ongoing-card-desc"><?php echo esc_html($oa['description']); ?></p>
                        <?php endif; ?>
                        <?php if (!empty($oa['latest'])): ?>
                            <ul class="ongoing-card-latest">
                                <?php foreach ($oa['latest'] as $dev):
                                    $devTitle = !empty($dev['alternate_title']) ? $dev['alternate_title'] : $dev['article_title'];
                                    $devDate = !empty($dev['publication_date']) ? date('M j', strtotime($dev['publication_date'])) : '';
                                ?>
                                    <li>
                                        <?php if (!empty($dev['article_url'])): ?>
                                            <a href="<?php echo esc_url($dev['article_url']); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($devTitle); ?></a>
                                        <?php else: ?>
                                            <?php echo esc_html($devTitle); ?>
                                        <?php endif; ?>
                                        <span class="ongoing-dev-meta"><?php echo esc_html(trim(($dev['publication_name'] ?? '') . ($devDate ? ' · ' . $devDate : ''), ' ·')); ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                        <a class="ongoing-card-viewall" href="<?php echo esc_url($arc_url); ?>">Full story &raquo;</a>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if (empty($submissions)): ?>
        <div class="news-empty">
            <p>No news articles found at this time.</p>
        </div>
    <?php else: ?>
        <?php
        // Function to normalize tags to title case
        function normalizeTagCase($tag) {
            // Specific word overrides (lowercase => desired)
            $wordOverrides = [
                'wwasp' => 'WWASP',
                'maclaren' => 'MacLaren',
            ];

            // Convert to title case but preserve all-caps acronyms
            $words = explode(' ', $tag);
            $normalized = [];
            foreach ($words as $word) {
                $lowerWord = strtolower($word);
                if (isset($wordOverrides[$lowerWord])) {
                    $normalized[] = $wordOverrides[$lowerWord];
                    continue;
                }

                // Keep all-caps acronyms as-is (e.g., "TTI", "PTSD", "USA")
                if (strlen($word) <= 4 && strtoupper($word) === $word) {
                    $normalized[] = $word;
                } else {
                    $normalized[] = ucwords($lowerWord);
                }
            }
            return implode(' ', $normalized);
        }

        // Tag Mappings - Normalizing synonyms to canonical tags
        $tagMappings = [
            'juvenile detention' => 'Juvenile Justice',
            'youth detention' => 'Juvenile Justice',
            'juvenile hall' => 'Juvenile Justice',
            'detention center' => 'Juvenile Justice',
            'youth prison' => 'Juvenile Justice',
            'juvenile jail' => 'Juvenile Justice',
            // Escapes
            'escape' => 'Escape',
            'escapes' => 'Escape',
            'escaped' => 'Escape',
            'runaway' => 'Escape',
            'runaways' => 'Escape',
            'absconded' => 'Escape',
            'elopement' => 'Escape',
            // Riots
            'riot' => 'Riot',
            'riots' => 'Riot',
            'uprising' => 'Riot',
            'uprisings' => 'Riot',
            'disturbance' => 'Riot',
            'disturbances' => 'Riot',
            'melee' => 'Riot',
        ];

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
            'troubled teen', 'troubled teens', 'troubled teen industry', 'tti', 'troubled-teen industry',
            'residential treatment', 'residential treatment center', 'rtc',
            'therapeutic boarding school', 'treatment center', 'treatment facility',
            'behavioral health', 'mental health', 'mental health treatment',
            'reform', 'reform school', 'boot camp',
            'facility', 'program', 'institution',
            'baltimore city facilities', 'city facilities',
            // Generic people terms
            'adolescent', 'adolescents', 'teenager', 'teenagers', 'teen', 'teens',
            'youth', 'children', 'child', 'minor', 'minors', 'juvenile', 'juveniles',
            'survivor', 'survivors', 'victim', 'victims', 'student', 'students',
            // Generic news/legal terms
            'abuse allegations', 'allegations', 'misconduct',
            'investigation', 'report', 'news', 'article', 'lawsuit', 'lawsuit filed',
            'accountability', 'justice', 'legal', 'crime', 'criminal',
            // Generic Location/Policy terms
            'usa', 'united states', 'america', 'national',
            'child welfare', 'system',
            'policy', 'regulation', 'bill', 'law',
            'safety', 'health', 'protection', 'security',
            // Specific topics better suited for content warnings
            'psychotropic medication', 'unsanitary conditions'
        ];

        // Helper to process a tag: map, check exclusion, normalize
        function processTag($tag, $excludedTags, $tagMappings) {
            $tagTrimmed = trim($tag);
            $tagLower = strtolower($tagTrimmed);
            
            if (empty($tagLower)) return null;

            // Apply mappings
            if (isset($tagMappings[$tagLower])) {
                $tagTrimmed = $tagMappings[$tagLower];
                $tagLower = strtolower($tagTrimmed); // Update lower for exclude check
            }

            // Check exclusions
            if (in_array($tagLower, $excludedTags)) {
                return null;
            }

            // Normalize Case
            return normalizeTagCase($tagTrimmed);
        }

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
            $itemFacilities = kop_facility_mention_names($item['facilities_mentioned'] ?? '[]');
            $itemTags = array_merge($itemTags, $itemFacilities);

            // Filter out excluded tags and normalize to title case
            foreach (array_unique($itemTags) as $tag) {
                $normalizedTag = processTag($tag, $excludedTags, $tagMappings);
                if ($normalizedTag) {
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

        <div class="news-filters" data-kop-bug-feature="news-feed/filters" data-kop-bug-label="News Filters">
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
                $orgTags = [];
                $otherTags = [];

                // Tags to strictly force into "Facilities" category
                $forcedFacilities = [
                    'Asheville Academy For Girls',
                    'MacLaren Youth Correctional Facility', 
                ];

                // Tags to strictly force into "Parent Organizations" category
                $forcedOrgs = [
                    'WWASP'
                ];

                // US states list for matching
                $usStates = ['Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa','Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota','Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey','New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon','Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah','Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming'];

                // Get all facilities from all submissions for comparison
                $allFacilities = [];
                foreach ($submissions as $item) {
                    $itemFacilities = kop_facility_mention_names($item['facilities_mentioned'] ?? '[]');
                    $allFacilities = array_merge($allFacilities, $itemFacilities);
                }

                // Initialize Parent Orgs
                $parentOrgs = [];

                // Fetch from Master Database and Wiki Submissions
                try {
                    // 1. Facilities Master
                    $stmtMaster = $pdo->prepare("SELECT json_data FROM facilities_master");
                    $stmtMaster->execute();
                    while ($row = $stmtMaster->fetch(PDO::FETCH_ASSOC)) {
                        $data = json_decode($row['json_data'], true);
                        if (!$data) continue;

                        // Handle format (nested in 'data' or root)
                        $innerData = $data['data'] ?? $data;

                        // Operator Name -> Parent Org
                        if (!empty($innerData['operator']['name'])) {
                            $parentOrgs[] = $innerData['operator']['name'];
                        }

                        // Facility Names
                        if (!empty($innerData['facilities']) && is_array($innerData['facilities'])) {
                            foreach ($innerData['facilities'] as $fac) {
                                if (!empty($fac['identification']['name'])) {
                                    $allFacilities[] = $fac['identification']['name'];
                                }
                            }
                        }
                    }

                    // 2. Published Wiki Submissions
                    $stmtWiki = $pdo->prepare("SELECT program_name, organization, json_data FROM wiki_submissions WHERE status = 'published'");
                    $stmtWiki->execute();
                    while ($row = $stmtWiki->fetch(PDO::FETCH_ASSOC)) {
                        if (!empty($row['program_name'])) $allFacilities[] = $row['program_name'];
                        if (!empty($row['organization'])) $parentOrgs[] = $row['organization'];

                        $data = json_decode($row['json_data'], true);
                        if (!empty($data['campuses']) && is_array($data['campuses'])) {
                            foreach ($data['campuses'] as $campus) {
                                if (!empty($campus['campusName'])) {
                                    $allFacilities[] = $campus['campusName'];
                                }
                            }
                        }
                    }
                } catch (PDOException $e) {
                    // Fail silently if tables don't exist or error occurs
                }

                // Normalize facilities
                $allFacilities = array_map(function($f) { 
                    return normalizeTagCase(trim($f));
                }, $allFacilities);
                $allFacilities = array_unique($allFacilities);

                // Normalize parent orgs
                $parentOrgs = array_map(function($o) { 
                    return normalizeTagCase(trim($o));
                }, $parentOrgs);
                $parentOrgs = array_unique($parentOrgs);

                foreach ($allTags as $tag => $count) {
                    if (in_array($tag, $usStates)) {
                        $stateTags[$tag] = $count;
                    } elseif (in_array($tag, $parentOrgs) || in_array($tag, $forcedOrgs)) {
                        $orgTags[$tag] = $count;
                    } elseif (in_array($tag, $allFacilities) || in_array($tag, $forcedFacilities)) {
                        $facilityTags[$tag] = $count;
                    } else {
                        $otherTags[$tag] = $count;
                    }
                }

                // Alphabetize tags within each category
                ksort($stateTags);
                ksort($facilityTags);
                ksort($orgTags);
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
                            <span class="category-label">Location (<?php echo count($stateTags); ?>)</span>
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

                    <?php if (!empty($orgTags)): ?>
                    <div class="filter-tag-category">
                        <button class="filter-category-header" data-category="orgs">
                            <span class="category-label">Parent Organizations (<?php echo count($orgTags); ?>)</span>
                            <span class="category-arrow">▸</span>
                        </button>
                        <div class="filter-category-content" data-category-content="orgs" style="display: none;">
                            <div class="filter-buttons">
                                <?php foreach ($orgTags as $tag => $count): ?>
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
                            <span class="category-label">Facilities (<?php echo count($facilityTags); ?>)</span>
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
                            <span class="category-label">Topics (<?php echo count($otherTags); ?>)</span>
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

        <div class="news-feed-grid" data-kop-bug-feature="news-feed/grid" data-kop-bug-label="News Feed">
            <?php foreach ($submissions as $item):
                // Decode JSON fields
                $facilities = kop_facility_mention_names($item['facilities_mentioned'] ?? '[]');
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

                // Filter out excluded tags and normalize to title case
                $tags = array_filter(array_map(function($tag) use ($excludedTags, $tagMappings) {
                    return processTag($tag, $excludedTags, $tagMappings);
                }, $tags));
                
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

                // Other outlets' articles about this same story (see the
                // story_group_id collapse above the card loop).
                $story_key = !empty($item['story_group_id']) ? 'g' . $item['story_group_id'] : 'i' . $item['id'];
                $also_covered = $story_coverage[$story_key] ?? [];

                // Ongoing-story badge (hidden inside the story's own view).
                $card_arc = (!$current_arc && !empty($item['story_arc_id']))
                    ? ($arc_index[(int) $item['story_arc_id']] ?? null) : null;
            ?>
                <?php // Delimit tags with '|' — facility names contain commas ("Excel Academy, Conroe"). ?>
                <article class="news-card" data-type="<?php echo esc_attr($item['article_type']); ?>" data-tags="<?php echo esc_attr(implode('|', $tags)); ?>">
                    <div class="news-card-header">
                        <span class="news-type-badge type-<?php echo esc_attr($item['article_type']); ?>">
                            <?php echo esc_html(ucfirst($item['article_type'])); ?>
                        </span>
                        <?php if ($card_arc): ?>
                            <a class="arc-badge" href="<?php echo esc_url('?story=' . rawurlencode($card_arc['slug'])); ?>" title="Part of an ongoing story — view all coverage">
                                <?php echo esc_html($card_arc['title']); ?>
                            </a>
                        <?php endif; ?>
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

                    <?php if (!empty($also_covered)): ?>
                        <div class="news-coverage">
                            <span class="coverage-label">Also covered by:</span>
                            <?php foreach ($also_covered as $cov):
                                $covTitle = !empty($cov['alternate_title']) ? $cov['alternate_title'] : $cov['article_title'];
                                $covOutlet = $cov['publication_name'] ?: (parse_url($cov['article_url'] ?? '', PHP_URL_HOST) ?: 'Unknown outlet');
                                $covDate = !empty($cov['publication_date']) ? date('M j, Y', strtotime($cov['publication_date'])) : '';
                                $covLabel = $covOutlet . ($covDate ? " ($covDate)" : '');
                            ?>
                                <?php if (!empty($cov['article_url'])): ?>
                                    <a class="coverage-link" href="<?php echo esc_url($cov['article_url']); ?>" target="_blank" rel="noopener noreferrer" title="<?php echo esc_attr($covTitle); ?>">
                                        <?php echo esc_html($covLabel); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="coverage-link" title="<?php echo esc_attr($covTitle); ?>"><?php echo esc_html($covLabel); ?></span>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($warnings)): ?>
                        <div class="news-warnings">
                            <?php foreach ($warnings as $warning): ?>
                                <span class="warning-tag"><?php echo esc_html($warning); ?></span>
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

        <?php if ($total_pages > 1): ?>
        <nav class="news-pagination" aria-label="News feed pagination">
            <?php
            // Build base URL preserving archive filter
            $base_params = [];
            if ($archive_month) $base_params['archive'] = $archive_month;
            if ($current_arc) $base_params['story'] = $current_arc['slug'];

            function kop_pagination_url($page, $base_params) {
                $params = $base_params;
                if ($page > 1) $params['pg'] = $page;
                $query = http_build_query($params);
                return strtok($_SERVER['REQUEST_URI'], '?') . ($query ? '?' . $query : '');
            }
            ?>

            <?php if ($current_page > 1): ?>
                <a href="<?php echo esc_url(kop_pagination_url($current_page - 1, $base_params)); ?>" class="pagination-btn pagination-prev">&laquo; Newer</a>
            <?php endif; ?>

            <span class="pagination-info">Page <?php echo $current_page; ?> of <?php echo $total_pages; ?> (<?php echo $total_items; ?> articles)</span>

            <?php if ($current_page < $total_pages): ?>
                <a href="<?php echo esc_url(kop_pagination_url($current_page + 1, $base_params)); ?>" class="pagination-btn pagination-next">Older &raquo;</a>
            <?php endif; ?>
        </nav>
        <?php endif; ?>
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
            const cardTags = card.dataset.tags ? card.dataset.tags.split('|') : [];

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
