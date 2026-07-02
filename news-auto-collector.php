<?php
/*
Plugin Name: News Auto Collector Pro
Description: RSS news collector with advanced HTML/Media image extraction, optimized lookup, and complete WP-Cron implementation.
Version: 1.4
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

    // FIX (4): Optimize lookup using array_flip for O(1) hash map execution speed
    $existing_links_lookup = array_flip(array_column($existing, 'link'));
    $all_items = [];

    foreach ($feed_lines as $feed_line) {
        if (empty($feed_line)) continue;

        $category = 'general';
        $url = $feed_line;

        // FIX (1): Fully implemented robust parsing split
        if (strpos($feed_line, '|') !== false) {
            $parts = explode('|', $feed_line, 2);
            if (count($parts) === 2) {
                $category = trim($parts[0]);
                $url = trim($parts[1]);
            }
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        $feed = fetch_feed($url);
        if (is_wp_error($feed)) continue;

        $maxitems = $feed->get_item_quantity(10);
        $items = $feed->get_items(0, $maxitems);

        foreach ($items as $item) {
            $link = esc_url_raw($item->get_link());

            // Check flipped lookup table for high performance
            if (isset($existing_links_lookup[$link])) continue;

            // FIX (3): Advanced multi-tier thumbnail lookup logic
            $thumb = '';
            
            // Tier A: Check standard RSS enclosure
            $enclosures = $item->get_enclosures();
            if (!empty($enclosures)) {
                foreach ($enclosures as $enc) {
                    if ($enc->get_link()) {
                        $thumb = esc_url_raw($enc->get_link());
                        break;
                    }
                }
            }

            // Tier B: Fallback to media:content namespace elements inside item array
            if (empty($thumb) && isset($item->data['child']['http://search.yahoo.com/mrss/']['content'])) {
                $media_content = $item->data['child']['http://search.yahoo.com/mrss/']['content'];
                if (isset($media_content[0]['attribs']['']['url'])) {
                    $thumb = esc_url_raw($media_content[0]['attribs']['']['url']);
                }
            }

            // Tier C: Parse inline raw HTML <img> from content elements
            if (empty($thumb)) {
                $raw_content = $item->get_content() . ' ' . $item->get_description();
                if (preg_match('/<img[^>]+src=["\']([^"\']+)["\']/i', $raw_content, $matches)) {
                    $thumb = esc_url_raw($matches[1]);
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
        $merged = array_merge($all_items, $existing);

        usort($merged, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        $merged = array_slice($merged, 0, 50);

        update_option('nac_items', $merged);
        set_transient('nac_cache', $merged, HOUR_IN_SECONDS);
    }
}

/* ================================
    5. SECURE MANUAL TRIGGER HANDLER
================================ */
// FIX (5): Complete explicit routing logic connecting admin submission back to execution context
add_action('admin_post_nac_manual_trigger', function () {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access.');
    }

    if (!isset($_POST['nac_manual_nonce']) || !wp_verify_nonce($_POST['nac_manual_nonce'], 'nac_manual_action')) {
        wp_die('Security check failed.');
    }

    nac_fetch_news();

    wp_redirect(admin_url('admin.php?page=nac-admin&settings-updated=true'));
    exit;
});

/* ================================
    6. WP-CRON REGISTRATION
================================ */
// FIX (2): Fully configured activation hooks & event listeners
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

// Binds the active WP Cron schedule directly to the runner method
add_action('nac_hourly_event', 'nac_fetch_news');

/* ================================
    7. SHORTCODE DISPLAY INTERFACE
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
        return '<p>No news items found. Please trigger a manual sync or verify your feed configs.</p>';
    }

    $html = '<div class="nac-news-container" style="font-family: Arial, sans-serif; max-width: 100%;">';

    foreach ($items as $item) {
        if (!empty($atts['category']) && $item['category'] !== sanitize_key($atts['category'])) {
            continue;
        }

        $html .= '<div class="nac-news-item" style="margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid #eee; display: flex; gap: 15px; align-items: flex-start;">';

        if (!empty($item['thumbnail'])) {
            $html .= '<div class="nac-news-thumb" style="flex-shrink: 0;">';
            $html .= '<img src="' . esc_url($item['thumbnail']) . '" style="width: 120px; height: 80px; object-fit: cover; border-radius: 4px;">';
            $html .= '</div>';
        }

        $html .= '<div class="nac-news-content">';
        $html .= '<h4 style="margin: 0 0 5px 0; font-size: 16px; line-height:1.4;">';
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
