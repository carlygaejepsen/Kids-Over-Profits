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

    // Sort + pagination. The table holds hundreds of approved entries; the old
    // fixed LIMIT 50 silently hid everything but the newest fifty.
    $per_page  = 50;
    $feed_sort = (isset($_GET['sort']) && $_GET['sort'] === 'name') ? 'name' : 'newest';
    $feed_page = isset($_GET['pg']) ? max(1, (int) $_GET['pg']) : 1;
    $total_rows  = 0;
    $total_pages = 1;

    try {
        $where  = "WHERE status IN ($placeholders) ";
        $params = $status_filter;

        if ($feed_search !== '') {
            $where .= "AND (program_name LIKE ? OR organization LIKE ? OR city_state LIKE ? OR program_type LIKE ?) ";
            $like = '%' . $feed_search . '%';
            array_push($params, $like, $like, $like, $like);
        }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM wiki_submissions $where");
        $countStmt->execute($params);
        $total_rows  = (int) $countStmt->fetchColumn();
        $total_pages = max(1, (int) ceil($total_rows / $per_page));
        if ($feed_page > $total_pages) {
            $feed_page = $total_pages;
        }
        $offset = ($feed_page - 1) * $per_page;

        $order = $feed_sort === 'name'
            ? "ORDER BY program_name ASC, created_at DESC"
            : "ORDER BY created_at DESC, id DESC";

        // LIMIT/OFFSET are ints we computed ourselves; PDO can't bind them as
        // named params reliably, so interpolate.
        $sql = "SELECT * FROM wiki_submissions $where $order LIMIT $per_page OFFSET $offset";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $submissions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (PDOException $e) {
        // Raw DB errors leak schema details to public visitors — log instead.
        error_log('Wiki feed error: ' . $e->getMessage());
        $error_message = 'The wiki feed is temporarily unavailable. Please try again later.';
        $submissions = [];
    }

    // Build a link to this feed with one query arg changed (keeps search/sort/pg).
    $feed_link = static function (array $overrides) {
        $args = [
            'search' => isset($_GET['search']) ? sanitize_text_field(wp_unslash($_GET['search'])) : '',
            'sort'   => isset($_GET['sort']) ? sanitize_key($_GET['sort']) : '',
            'pg'     => isset($_GET['pg']) ? (int) $_GET['pg'] : 0,
        ];
        $args = array_merge($args, $overrides);
        $url = remove_query_arg(['search', 'sort', 'pg']);
        foreach ($args as $k => $v) {
            if ($v === '' || $v === 0 || $v === null || ($k === 'pg' && (int) $v <= 1) || ($k === 'sort' && $v === 'newest')) {
                continue;
            }
            $url = add_query_arg($k, $v, $url);
        }
        return $url;
    };

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
        <?php
        $first_shown = ($feed_page - 1) * $per_page + 1;
        $last_shown  = min($total_rows, $feed_page * $per_page);
        $render_pager = static function ($cls) use ($feed_page, $total_pages, $feed_link) {
            if ($total_pages <= 1) return;
            echo '<nav class="wiki-feed-pager ' . esc_attr($cls) . '" aria-label="Wiki feed pages">';
            if ($feed_page > 1) {
                echo '<a class="pager-link" href="' . esc_url($feed_link(['pg' => $feed_page - 1])) . '">&larr; Previous</a>';
            } else {
                echo '<span class="pager-link is-disabled">&larr; Previous</span>';
            }
            echo '<span class="pager-status">Page ' . (int) $feed_page . ' of ' . (int) $total_pages . '</span>';
            if ($feed_page < $total_pages) {
                echo '<a class="pager-link" href="' . esc_url($feed_link(['pg' => $feed_page + 1])) . '">Next &rarr;</a>';
            } else {
                echo '<span class="pager-link is-disabled">Next &rarr;</span>';
            }
            echo '</nav>';
        };
        ?>
        <div class="wiki-feed-toolbar">
            <span class="wiki-feed-count">
                Showing <?php echo (int) $first_shown; ?>&ndash;<?php echo (int) $last_shown; ?> of <?php echo (int) $total_rows; ?> entries
            </span>
            <span class="wiki-feed-sort">
                Sort:
                <?php if ($feed_sort === 'newest'): ?>
                    <strong>Newest</strong>
                <?php else: ?>
                    <a href="<?php echo esc_url($feed_link(['sort' => 'newest', 'pg' => 1])); ?>">Newest</a>
                <?php endif; ?>
                &middot;
                <?php if ($feed_sort === 'name'): ?>
                    <strong>A&ndash;Z</strong>
                <?php else: ?>
                    <a href="<?php echo esc_url($feed_link(['sort' => 'name', 'pg' => 1])); ?>">A&ndash;Z</a>
                <?php endif; ?>
            </span>
        </div>
        <?php $render_pager('pager-top'); ?>
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
                
                // Markdown logic. Prefer the markdown that was actually uploaded
                // (original_markdown): it is the verbatim wiki text. The generated
                // column is a parse->regenerate round trip of it and is lossy
                // (dropped staff entries, placeholder "not added yet" sections,
                // duplicated headings). Fall back to generated only for entries
                // authored in the form, which never had an original.
                $decoded = [];
                if (!empty($item['json_data'])) {
                    $tmp = json_decode($item['json_data'], true);
                    if (is_array($tmp)) $decoded = $tmp;
                }

                $markdown = trim((string) ($item['original_markdown'] ?? ''));
                if ($markdown === '') {
                    $markdown = trim((string) ($decoded['originalMarkdown'] ?? $decoded['original_markdown'] ?? ''));
                }
                $isOriginal = $markdown !== '';

                if ($markdown === '') {
                    $markdown = trim((string) ($item['generated_markdown'] ?? ''));
                    if ($markdown === '') {
                        $markdown = trim((string) ($decoded['generatedMarkdown'] ?? $decoded['generated_markdown'] ?? ''));
                    }
                }

                $btnLabel = $isOriginal ? 'View Wiki Entry' : 'View Generated Wiki Entry';
                if ($markdown === '') $btnLabel = 'No Entry Content Available';
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
        <?php $render_pager('pager-bottom'); ?>
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
            // Reddit renders "** Name**" / "**Name **" as bold; CommonMark does not
            // (no whitespace allowed just inside the delimiters). Most of the
            // uploaded wiki pages use the spaced form, so tighten it.
            decodedRaw = decodedRaw.replace(/\*\*[ \t]+([^*\n][^*\n]*?)[ \t]*\*\*/g, '**$1**');
            decodedRaw = decodedRaw.replace(/\*\*([^*\n][^*\n]*?[^*\s])[ \t]+\*\*/g, '**$1**');

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
