<?php
/**
 * Template Name: Wiki Feed
 *
 * Displays a feed of approved wiki/program submissions.
 */

// Enqueue styles and scripts
function kop_enqueue_wiki_feed_assets() {
    wp_enqueue_style('wiki-feed-css', get_stylesheet_directory_uri() . '/css/wiki-feed.css', array(), filemtime(get_stylesheet_directory() . '/css/wiki-feed.css'));
    
    // Add marked.js for markdown rendering
    wp_enqueue_script('marked-js', 'https://cdn.jsdelivr.net/npm/marked/marked.min.js', array(), '15.0.0', true);
}
add_action('wp_enqueue_scripts', 'kop_enqueue_wiki_feed_assets');

get_header();
?>

<div class="wiki-feed-container">
    <div class="wiki-feed-header">
        <h1>Program Database Additions</h1>
        <p>Recently approved contributions to the TTI Program Database.</p>
    </div>

    <?php
    // Database connection
    require_once get_stylesheet_directory() . '/api/config.php';

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

    if (isset($error_message)): ?>
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
                
                // Markdown logic
                $markdown = $item['generated_markdown'] ?? '';
                $isOriginal = false;

                if (empty($markdown) && !empty($item['json_data'])) {
                    $decoded = json_decode($item['json_data'], true);
                    if (is_array($decoded)) {
                        $markdown = $decoded['generatedMarkdown'] ?? $decoded['generated_markdown'] ?? '';
                    }
                }

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

                $btnLabel = $isOriginal ? 'View Original Wiki Entry' : 'View Full Wiki Entry';
                if (empty($markdown)) $btnLabel = 'No Entry Content Available';
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
                            <button type="button" class="toggle-markdown-btn" 
                                    data-id="<?php echo $item['id']; ?>"
                                    data-label="<?php echo esc_attr($btnLabel); ?>"
                                    onclick="toggleMarkdown(this)">
                                <?php echo esc_html($btnLabel); ?>
                            </button>
                            
                            <div class="markdown-content" style="display: none;">
                                <!-- Hidden raw content -->
                                <script type="text/template" class="raw-markdown"><?php echo $markdown; ?></script>
                                <!-- Rendered container -->
                                <div class="rendered-markdown"></div>
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
    const section = btn.closest('.wiki-markdown-section');
    const content = section.querySelector('.markdown-content');
    const rendered = section.querySelector('.rendered-markdown');
    const raw = section.querySelector('.raw-markdown').innerHTML;
    const originalLabel = btn.getAttribute('data-label');

    if (content.style.display === 'none') {
        // Render if not already done
        if (!rendered.innerHTML && raw.trim()) {
            if (typeof marked !== 'undefined') {
                // Configure marked for safer rendering
                marked.setOptions({
                    headerIds: false,
                    mangle: false,
                    gfm: true,
                    breaks: true
                });
                rendered.innerHTML = marked.parse(raw);
            } else {
                rendered.innerHTML = '<pre style="white-space:pre-wrap">' + raw + '</pre>';
                console.warn('marked.js not loaded, showing raw text');
            }
        }
        
        content.style.display = 'block';
        btn.textContent = 'Hide Wiki Entry';
        btn.classList.add('active');
    } else {
        content.style.display = 'none';
        btn.textContent = originalLabel;
        btn.classList.remove('active');
    }
}
</script>

<?php
get_footer();
?>
