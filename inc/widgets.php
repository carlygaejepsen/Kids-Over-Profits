<?php
/**
 * Homepage Sidebar Widgets
 *
 * Registers the "Homepage Dynamic Sidebar" widget area and three custom
 * widgets that pull live data from facilities_master and news_submissions.
 */

if (!defined('ABSPATH')) {
    exit;
}

add_action('widgets_init', 'kop_register_homepage_sidebar');

function kop_register_homepage_sidebar() {
    register_sidebar(array(
        'id'            => 'kop-homepage-sidebar',
        'name'          => 'Homepage Dynamic Sidebar',
        'description'   => 'Place the News Reel, Recent Facilities, or Site Stats widgets here. Assign this sidebar to the homepage via Kadence page settings.',
        'before_widget' => '<div id="%1$s" class="kop-widget %2$s">',
        'after_widget'  => '</div>',
        'before_title'  => '<h3 class="kop-widget-title">',
        'after_title'   => '</h3>',
    ));

    register_widget('KOP_News_Reel_Widget');
    register_widget('KOP_Recent_Facilities_Widget');
    register_widget('KOP_Stats_Widget');
    register_widget('KOP_Ongoing_Stories_Widget');
}


// =============================================================================
// Widget 1: News Reel
// =============================================================================

class KOP_News_Reel_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'kop_news_reel',
            'KOP News Reel',
            array('description' => 'Latest approved news articles from the database.')
        );
    }

    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title'] ?? 'Latest News');
        $limit = max(1, min(20, intval($instance['limit'] ?? 5)));

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        $articles = $this->fetch_articles($limit);

        if (empty($articles)) {
            echo '<p class="kop-widget-empty">No news articles found.</p>';
        } else {
            echo '<ul class="kop-news-reel-list">';
            foreach ($articles as $article) {
                $display_title = !empty($article['alternate_title'])
                    ? $article['alternate_title']
                    : $article['article_title'];
                $pub  = $article['publication_name'] ?? '';
                $date = $article['publication_date']
                    ? date('M j, Y', strtotime($article['publication_date']))
                    : '';
                $meta = implode(' &bull; ', array_filter([$pub, $date]));
                ?>
                <li class="kop-news-reel-item">
                    <a class="kop-news-reel-link"
                       href="<?php echo esc_url($article['article_url']); ?>"
                       target="_blank" rel="noopener noreferrer">
                        <?php echo esc_html($display_title); ?>
                    </a>
                    <?php if ($meta): ?>
                        <span class="kop-news-reel-meta"><?php echo wp_kses($meta, array()); ?></span>
                    <?php endif; ?>
                </li>
                <?php
            }
            echo '</ul>';

            $news_page = get_page_by_path('news');
            if ($news_page) {
                echo '<a class="kop-news-reel-more" href="' . esc_url(get_permalink($news_page)) . '">View all news &rarr;</a>';
            }
        }

        echo $args['after_widget'];
    }

    private function fetch_articles($limit) {
        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT article_title, alternate_title, article_url, publication_name, publication_date
                 FROM news_submissions
                 WHERE status IN ('approved', 'published')
                 ORDER BY publication_date DESC, created_at DESC
                 LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return (array) $rows;
    }

    public function form($instance) {
        $title = $instance['title'] ?? 'Latest News';
        $limit = $instance['limit'] ?? 5;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title:</label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>">Number of articles (1–20):</label>
            <input class="tiny-text" type="number" min="1" max="20"
                   id="<?php echo esc_attr($this->get_field_id('limit')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('limit')); ?>"
                   value="<?php echo esc_attr($limit); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return array(
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
            'limit' => max(1, min(20, intval($new_instance['limit'] ?? 5))),
        );
    }
}


// =============================================================================
// Widget 2: Recent Facilities
// =============================================================================

class KOP_Recent_Facilities_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'kop_recent_facilities',
            'KOP Recent Facilities',
            array('description' => 'Recently added or updated facilities from the database.')
        );
    }

    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title'] ?? 'Recently Updated');
        $limit = max(1, min(20, intval($instance['limit'] ?? 5)));

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        $facilities = $this->fetch_facilities($limit);

        if (empty($facilities)) {
            echo '<p class="kop-widget-empty">No facility records found.</p>';
        } else {
            echo '<ul class="kop-recent-facilities-list">';
            foreach ($facilities as $row) {
                $data     = json_decode($row['json_data'], true);
                $inner    = (isset($data['data']) && is_array($data['data'])) ? $data['data'] : $data;
                $operator = isset($inner['operator']['name']) ? $inner['operator']['name'] : '';
                $fac_count = (isset($inner['facilities']) && is_array($inner['facilities']))
                    ? count($inner['facilities']) : 0;
                $name = $operator ?: $row['unique_name'];
                ?>
                <li class="kop-recent-facility-item">
                    <span class="kop-recent-facility-name"><?php echo esc_html($name); ?></span>
                    <?php if ($fac_count > 0): ?>
                        <span class="kop-recent-facility-meta">
                            <?php echo $fac_count; ?> facilit<?php echo $fac_count === 1 ? 'y' : 'ies'; ?>
                        </span>
                    <?php endif; ?>
                </li>
                <?php
            }
            echo '</ul>';
        }

        echo $args['after_widget'];
    }

    private function fetch_facilities($limit) {
        $connection = kop_get_facilities_database_connection();
        $table      = kop_discover_facilities_master_table($connection);
        if (!$table) return array();

        global $wpdb;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT unique_name, json_data FROM `{$table}` ORDER BY updated_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
        return (array) $rows;
    }

    public function form($instance) {
        $title = $instance['title'] ?? 'Recently Updated';
        $limit = $instance['limit'] ?? 5;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title:</label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>">Number of items (1–20):</label>
            <input class="tiny-text" type="number" min="1" max="20"
                   id="<?php echo esc_attr($this->get_field_id('limit')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('limit')); ?>"
                   value="<?php echo esc_attr($limit); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return array(
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
            'limit' => max(1, min(20, intval($new_instance['limit'] ?? 5))),
        );
    }
}


// =============================================================================
// Widget 3: Site Stats
// =============================================================================

class KOP_Stats_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'kop_site_stats',
            'KOP Site Stats',
            array('description' => 'Live counts from the database: facilities, news, pending submissions.')
        );
    }

    public function widget($args, $instance) {
        $title = apply_filters('widget_title', $instance['title'] ?? 'By The Numbers');

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }

        $stats = $this->fetch_stats();

        echo '<div class="kop-stats-grid">';
        foreach ($stats as $stat) {
            ?>
            <div class="kop-stat-item">
                <span class="kop-stat-value"><?php echo esc_html($stat['value']); ?></span>
                <span class="kop-stat-label"><?php echo esc_html($stat['label']); ?></span>
            </div>
            <?php
        }
        echo '</div>';

        echo $args['after_widget'];
    }

    private function fetch_stats() {
        global $wpdb;
        $stats = array();

        $connection = kop_get_facilities_database_connection();
        $table      = kop_discover_facilities_master_table($connection);

        if ($table) {
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$table}`");
            $stats[] = array(
                'value' => number_format($count),
                'label' => 'Facilities Tracked',
            );
        }

        $news_count = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM news_submissions WHERE status IN ('approved','published')"
        );
        if ($news_count > 0) {
            $stats[] = array(
                'value' => number_format($news_count),
                'label' => 'News Articles',
            );
        }

        $pending = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM suggested_edits WHERE status = 'pending'"
        );
        if ($pending >= 0) {
            $stats[] = array(
                'value' => number_format($pending),
                'label' => 'Pending Edits',
            );
        }

        return $stats;
    }

    public function form($instance) {
        $title = $instance['title'] ?? 'By The Numbers';
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title:</label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return array(
            'title' => sanitize_text_field($new_instance['title'] ?? ''),
        );
    }
}


// =============================================================================
// Widget 4: Ongoing Stories
// =============================================================================

/**
 * Featured ongoing stories (news story arcs, curated in
 * api/manage-story-arcs.php). Thin wrapper around the
 * [kop_ongoing_stories] shortcode renderer in inc/features.php, so the
 * cards match the news feed's Ongoing Stories section. Renders nothing
 * when no active arc has published articles.
 */
class KOP_Ongoing_Stories_Widget extends WP_Widget {

    public function __construct() {
        parent::__construct(
            'kop_ongoing_stories',
            'KOP Ongoing Stories',
            array('description' => 'Big ongoing stories (e.g. a lawsuit, a closure) with their latest developments.')
        );
    }

    public function widget($args, $instance) {
        if (!function_exists('kop_ongoing_stories_shortcode')) {
            return;
        }
        $title = apply_filters('widget_title', $instance['title'] ?? 'Ongoing Stories');
        $limit = max(1, min(12, intval($instance['limit'] ?? 3)));
        $articles = max(0, min(5, intval($instance['articles'] ?? 3)));

        // The widget title takes over as the heading; suppress the
        // shortcode's built-in one.
        $body = kop_ongoing_stories_shortcode(array(
            'limit'    => $limit,
            'articles' => $articles,
            'heading'  => '',
        ));
        if ($body === '') {
            return;   // no active stories — render no widget shell either
        }

        echo $args['before_widget'];
        if ($title) {
            echo $args['before_title'] . esc_html($title) . $args['after_title'];
        }
        echo $body;
        echo $args['after_widget'];
    }

    public function form($instance) {
        $title = $instance['title'] ?? 'Ongoing Stories';
        $limit = $instance['limit'] ?? 3;
        $articles = $instance['articles'] ?? 3;
        ?>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('title')); ?>">Title:</label>
            <input class="widefat" type="text"
                   id="<?php echo esc_attr($this->get_field_id('title')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('title')); ?>"
                   value="<?php echo esc_attr($title); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('limit')); ?>">Number of stories (1–12):</label>
            <input class="tiny-text" type="number" min="1" max="12"
                   id="<?php echo esc_attr($this->get_field_id('limit')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('limit')); ?>"
                   value="<?php echo esc_attr($limit); ?>">
        </p>
        <p>
            <label for="<?php echo esc_attr($this->get_field_id('articles')); ?>">Latest articles per story (0–5):</label>
            <input class="tiny-text" type="number" min="0" max="5"
                   id="<?php echo esc_attr($this->get_field_id('articles')); ?>"
                   name="<?php echo esc_attr($this->get_field_name('articles')); ?>"
                   value="<?php echo esc_attr($articles); ?>">
        </p>
        <?php
    }

    public function update($new_instance, $old_instance) {
        return array(
            'title'    => sanitize_text_field($new_instance['title'] ?? ''),
            'limit'    => max(1, min(12, intval($new_instance['limit'] ?? 3))),
            'articles' => max(0, min(5, intval($new_instance['articles'] ?? 3))),
        );
    }
}
