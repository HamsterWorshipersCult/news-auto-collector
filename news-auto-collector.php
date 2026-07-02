<?php
/*
Plugin Name: News Auto Collector Pro
Description: RSS news collector with caching, thumbnails, categories, and duplicate prevention
Version: 1.3
Author: Custom Build
*/

if (!defined('ABSPATH')) exit;

/* ================================
    1. ADMIN MENU
================================ */
add_action('admin_menu', function () {
    add_menu_page(
        'News Collector',
        'News Collector',
        'manage_options',
        'nac-admin',
        'nac_admin_page'
    );
});

/* ================================
    2. SETTINGS REGISTER
================================ */
add_action('admin_init', function () {
    register_setting('nac_settings', 'nac_feeds', [
        'sanitize_callback' => 'sanitize_textarea_field'
    ]);
    register_setting('nac_settings', 'nac_items');
});

/* ================================
    3. ADMIN PAGE UI
================================ */
function nac_admin_page() {
    // wp_unslash prevents backslashes from being added to the URLs on save
    $feeds = wp_unslash(get_option('nac_feeds', ''));
    ?>
    <div class="wrap">
        <h1>News Auto Collector</h1>

        <?php settings_errors(); ?>

        <form method="post" action="options.php">
            <?php settings_fields('nac_settings'); ?>

            <h2>RSS Feeds</h2>
            <textarea name="nac_feeds" rows="10" cols="80" class="large-text code" placeholder="tech | https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml"><?php echo esc_textarea($feeds); ?></textarea>

            <p class="description">Format: one per line (category | url)</p>
            <p class="description">Example: <code>tech | https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml</code></p>

            <?php submit_button('Save Settings'); ?>
        </form>

        <hr style="margin: 30px 0;">

        <h2>Manual Sync</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('nac_manual_action', 'nac_manual_nonce'); ?>
            <input type="hidden" name="action" value="nac_manual_trigger">
            <button type="submit" class="button button-secondary">Run Manual Update Now</button>
        </form>
    </div>
    <?php
}

/* ================================
    4. RSS FETCH CORE FUNCTION
================================ */
function nac_fetch_news() {
    include_once(ABSPATH . WPINC . '/feed.php');

    $feeds_str = get_option('nac_feeds', '');
    if (empty($feeds_str)) return;

    $feed_lines = array_filter(array_map('trim', explode("\n", $feeds_str)));
    
    $existing = get_option('nac_items', []);
    if (!is_array($existing)) $existing = [];

    $existing_links = array_column($existing, 'link');
    $all_items = [];

    foreach ($feed_lines as $feed_line) {
        if (empty($feed_line)) continue;

        $category = 'general';
        $url = $feed_line;

        if (strpos($feed_line, '|') !== false) {
            $parts = explode('|', $feed_line, 2);
            $category = trim($parts[0]);
            $url = trim($parts[1]);
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        $feed = fetch_feed($url);
        if (is_wp_error($feed)) continue;

        $maxitems = $feed->get_item_quantity(10);
        $items = $feed->get_items(0, $maxitems);

        foreach ($items as $item) {
            $link = esc_url_raw($item->get_link());

            if (in_array($link, $existing_links)) continue;

            $thumb = '';
            $enclosures = $item->get_enclosures();
            if (!empty($enclosures)) {
                foreach ($enclosures as $enc) {
                    if ($enc->get_link()) {
                        $thumb = esc_url_raw($enc->get_link());
                        break;
                    }
                }
            }

            $all_items[] = [
                'title'     => sanitize_text_field($item->get_title()),
                'link'      => $link,
                'date'      => $item->get_date('Y-m-d H:i:s'),
                'category'  => sanitize_key($category),
                'thumbnail' => $thumb
            ];
        }
    }

    if (!empty($all_items)) {
        // Merge incoming items to stay at the top
        $merged = array_merge($all_items, $existing);

        // Sort by date descending
        usort($merged, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // Slice to keep only top 50 items
        $merged = array_slice($merged, 0, 50);

        update_option('nac_items', $merged);
        set_transient('nac_cache', $merged, HOUR_IN_SECONDS);
    }
}

/* ================================
    5. SECURE MANUAL TRIGGER
================================ */
add_action('admin_post_nac_manual_trigger', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access.');
    }

    if (!isset($_POST['nac_manual_nonce']) || !wp_verify_nonce($_POST['nac_manual_nonce'], 'nac_manual_action')) {
        wp_die('Security check failed.');
    }

    nac_fetch_news();

    // Redirect ensures form isn't resubmitted on page refresh
    wp_redirect(admin_url('admin.php?page=nac-admin&settings-updated=true'));
    exit;
});

/* ================================
    6. CRON ACTIVATION & DEACTIVATION
================================ */
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('nac_hourly_event')) {
        wp_schedule_event(time(), 'hourly', 'nac_hourly_event');
    }
});

register_deactivation_hook(__FILE__, function () {
    $timestamp = wp_next_scheduled('nac_hourly_event');
    if ($timestamp) {
        wp_unschedule_event($timestamp, 'nac_hourly_event');
    }
});

add_action('nac_hourly_event', 'nac_fetch_news');

/* ================================
    7. SHORTCODE `[news_collector category="tech"]`
================================ */
add_shortcode('news_collector', function ($atts) {
    $atts = shortcode_atts([
        'category' => ''
    ], $atts);

    $items = get_transient('nac_cache');

    if ($items === false) {
        $items = get_option('nac_items', []);
        if (!empty($items)) {
            set_transient('nac_cache', $items, HOUR_IN_SECONDS);
        }
    }

    if (empty($items)) {
        return '<p>No news items found. Run manual sync via settings if it\'s empty.</p>';
    }

    $html = '<div class="nac-news-container" style="font-family: Arial, sans-serif; max-width: 100%;">';

    foreach ($items as $item) {
        if (!empty($atts['category']) && $item['category'] !== sanitize_key($atts['category'])) {
            continue;
        }

        $html .= '<div class="nac-news-item" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; display: flex; gap: 15px; align-items: flex-start;">';

        if (!empty($item['thumbnail'])) {
            $html .= '<div class="nac-news-thumb" style="flex-shrink: 0;">';
            $html .= '<img src="' . esc_url($item['thumbnail']) . '" style="width: 120px; height: auto; object-fit: cover; border-radius: 4px;">';
            $html .= '</div>';
        }

        $html .= '<div class="nac-news-content">';
        $html .= '<h4 style="margin: 0 0 5px 0; font-size: 16px;">';
        $html .= '<a href="' . esc_url($item['link']) . '" target="_blank" rel="noopener noreferrer" style="color: #333; text-decoration: none; font-weight: bold;">';
        $html .= esc_html($item['title']);
        $html .= '</a>';
        $html .= '</h4>';

        $html .= '<span style="font-size: 12px; color: #888;">' . esc_html($item['date']) . ' | Category: ' . esc_html($item['category']) . '</span>';
        $html .= '</div>';

        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
});
