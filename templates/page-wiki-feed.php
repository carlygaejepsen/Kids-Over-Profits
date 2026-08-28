<?php
/**
 * Template Name: Wiki Feed
 *
 * Displays a feed of approved wiki/program submissions.
 */

// Enqueue styles and scripts
function kop_enqueue_wiki_feed_assets() {
    wp_enqueue_style('wiki-feed-css', get_stylesheet_directory_uri() . '/css/wiki-feed.css', array(), filemtime(get_stylesheet_directory() . '/css/wiki-feed.css'));
    
    // Add marked.js for markdown rendering (local file)
    wp_enqueue_script('marked-js', get_stylesheet_directory_uri() . '/js/marked.min.js', array(), '15.0.0', true);
    // DOMPurify sanitizes marked.js output before it's inserted into the DOM (defends against
    // stored XSS in submitted markdown like <img src=x onerror=...>).
    wp_enqueue_script('dompurify-js', get_stylesheet_directory_uri() . '/js/purify.min.js', array(), '3.1.6', true);
}
add_action('wp_enqueue_scripts', 'kop_enqueue_wiki_feed_assets');

get_header();
?>

<div class="wiki-feed-container">
    <div class="wiki-feed-header">
        <h1>Program Database Additions</h1>
        <p>Recently approved contributions to the TTI Program Database.</p>
        <?php if (!empty($_GET['search'])): ?>
            <p class="wiki-feed-filter-notice">
                Showing entries matching <strong><?php echo esc_html(sanitize_text_field(wp_unslash($_GET['search']))); ?></strong>
                &mdash; <a href="<?php echo esc_url(remove_query_arg('search')); ?>">show all</a>
            </p>
        <?php endif; ?>
    </div>

    <?php
    // Database connection
    require_once get_stylesheet_directory() . '/api/config.php';

    $status_filter = ['published', 'approved'];
    $placeholders = implode(',', array_fill(0, count($status_filter), '?'));

    // Deep link from the site search results page: ?search= narrows the feed.
    $feed_search = isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '';

    try {
        $sql = "SELECT * FROM wiki_submissions
                WHERE status IN ($placeholders) ";
        $params = $status_filter;

        if ($feed_search !== '') {
            $sql .= "AND (program_name LIKE ? OR organization LIKE ? OR city_state LIKE ? OR program_type LIKE ?) ";
            $like = '%' . $feed_search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $sql .= "ORDER BY created_at DESC
                LIMIT 50";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Raw DB errors leak schema details to public visitors — log instead.
        error_log('Wiki feed error: ' . $e->getMessage());
        $error_message = 'The wiki feed is temporarily unavailable. Please try again later.';
        $submissions = [];
    }

    if (isset($error_message)): ?>
        <div style="color: red; text-align: center; padding: 20px;">
            <?php echo esc_html($error_message); ?>
        </div>
    <?php endif; ?>

    <?php if (empty($submissions)): ?>
        <div style="text-align: center; padding: 40px; color: #666;">
            <?php if ($feed_search !== ''): ?>
                <p>No approved programs match that search. <a href="<?php echo esc_url(remove_query_arg('search')); ?>">Show all entries</a></p>
            <?php else: ?>
                <p>No recently approved programs found.</p>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="wiki-feed-grid" data-kop-bug-feature="wiki-feed/grid" data-kop-bug-label="Wiki Feed">
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
                                    data-id="<?php echo esc_attr($item['id']); ?>"
                                    data-label="<?php echo esc_attr($btnLabel); ?>"
                                    onclick="toggleMarkdown(this)">
                                <?php echo esc_html($btnLabel); ?>
                            </button>
                            
                            <div class="markdown-content" style="display: none;">
                                <!-- Hidden raw content (escaped; JS decodes entities before parsing) -->
                                <script type="text/template" class="raw-markdown"><?php echo esc_html($markdown); ?></script>
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
            // Decode HTML entities in raw content (php echo escapes them) — done outside the
            // try so it's available in the catch fallback.
            const txt = document.createElement('textarea');
            txt.innerHTML = raw;
            let decodedRaw = txt.value;
            // Ensure headers have spaces after # (Reddit/legacy might be lenient, marked is strict)
            decodedRaw = decodedRaw.replace(/(^|\n)(#{1,6})([^\s#])/g, '$1$2 $3');

            try {
                if (typeof marked !== 'undefined') {
                    const parseFn = typeof marked.parse === 'function' ? marked.parse : marked;
                    if (typeof marked.setOptions === 'function') {
                        marked.setOptions({ gfm: true, breaks: true });
                    }
                    const rawHtml = parseFn(decodedRaw);
                    if (typeof DOMPurify !== 'undefined') {
                        rendered.innerHTML = DOMPurify.sanitize(rawHtml, { USE_PROFILES: { html: true } });
                    } else {
                        // DOMPurify missing: refuse to render HTML (any payload would execute).
                        throw new Error('DOMPurify sanitizer not loaded');
                    }
                } else {
                    throw new Error('marked library not found');
                }
            } catch (e) {
                console.error('Markdown rendering failed:', e);
                // Fallback: show as plain pre-wrapped text via textContent — never innerHTML.
                rendered.textContent = '';
                const warning = document.createElement('div');
                warning.style.color = 'red';
                warning.style.fontSize = '0.8em';
                warning.style.marginBottom = '10px';
                warning.textContent = 'Rendering failed (showing raw text): ' + e.message;
                rendered.appendChild(warning);
                const pre = document.createElement('div');
                pre.style.whiteSpace = 'pre-wrap';
                pre.style.fontFamily = 'sans-serif';
                pre.style.color = '#333';
                pre.textContent = decodedRaw || raw;
                rendered.appendChild(pre);
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
