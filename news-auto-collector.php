<?php
/*
Plugin Name: News Auto Collector Pro
Description: RSS news collector with caching, thumbnails, categories, and duplicate prevention
Version: 1.2
Author: Custom Build
*/

if (!defined('ABSPATH')) exit;

/* ================================
   ADMIN MENU
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
   SETTINGS SAVE
================================ */
add_action('admin_init', function () {
    register_setting('nac_settings', 'nac_feeds');
    register_setting('nac_settings', 'nac_items');
});

/* ================================
   ADMIN PAGE
================================ */
function nac_admin_page() {
    $feeds = get_option('nac_feeds', '');
    ?>
    <div class="wrap">
        <h1>News Auto Collector</h1>

        <form method="post" action="options.php">
            <?php settings_fields('nac_settings'); ?>

            <h2>RSS Feeds</h2>
            <textarea name="nac_feeds" rows="10" cols="80"><?php echo esc_textarea($feeds); ?></textarea>

            <p>Format: one per line (optional category|url)</p>
            <p>Example: tech|https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml</p>

            <br>
            <input type="submit" class="button button-primary" value="Save">
        </form>

        <hr>

        <form method="post">
            <input type="hidden" name="nac_manual" value="1">
            <button class="button">Run Manual Update</button>
        </form>
    </div>
    <?php
}

/* ================================
   RSS FETCH FUNCTION
================================ */
function nac_fetch_news() {

    include_once(ABSPATH . WPINC . '/feed.php');

    $feeds = get_option('nac_feeds', '');
    $feeds = array_filter(array_map('trim', explode("\n", $feeds)));

    $existing = get_option('nac_items', []);
    if (!is_array($existing)) $existing = [];

    $existing_links = array_column($existing, 'link');

    $all_items = [];

    foreach ($feeds as $feed_line) {

        $category = 'general';
        $url = $feed_line;

        if (strpos($feed_line, '|') !== false) {
            list($category, $url) = array_map('trim', explode('|', $feed_line));
        }

        $feed = fetch_feed($url);
        if (is_wp_error($feed)) continue;

        $maxitems = $feed->get_item_quantity(10);
        $items = $feed->get_items(0, $maxitems);

        foreach ($items as $item) {

            $link = $item->get_link();

            // duplicate check
            if (in_array($link, $existing_links)) continue;

            // thumbnail
            $thumb = '';
            if ($item->get_enclosure()) {
                $enc = $item->get_enclosure();
                if ($enc && $enc->get_link()) {
                    $thumb = $enc->get_link();
                }
            }

            $all_items[] = [
                'title' => $item->get_title(),
                'link' => $link,
                'date' => $item->get_date('Y-m-d H:i:s'),
                'category' => $category,
                'thumbnail' => $thumb
            ];
        }
    }

    $merged = array_merge($existing, $all_items);

    usort($merged, function ($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    $merged = array_slice($merged, 0, 50);

    update_option('nac_items', $merged);

    set_transient('nac_cache', $merged, HOUR_IN_SECONDS);
}

/* ================================
   MANUAL TRIGGER
================================ */
add_action('admin_init', function () {
    if (isset($_POST['nac_manual'])) {
        nac_fetch_news();
    }
});

/* ================================
   AUTO CRON (HOURLY)
================================ */
register_activation_hook(__FILE__, function () {
    if (!wp_next_scheduled('nac_hourly_event')) {
        wp_schedule_event(time(), 'hourly', 'nac_hourly_event');
    }
});

/* ================================
   SHORTCODE
================================ */
add_shortcode('news_collector', function ($atts) {

    $atts = shortcode_atts([
        'category' => ''
    ], $atts);

    $items = get_transient('nac_cache');

    if ($items === false) {
        $items = get_option('nac_items', []);
    }

    if (!$items) return '<p>No news found.</p>';

    $html = '<div style="font-family:Arial;">';

    foreach ($items as $item) {

        if (!empty($atts['category']) && $item['category'] !== $atts['category']) {
            continue;
        }

        $html .= '<div style="margin-bottom:15px;padding:10px;border-bottom:1px solid #ddd;">';

        if (!empty($item['thumbnail'])) {
            $html .= '<img src="' . esc_url($item['thumbnail']) . '" style="width:120px;display:block;margin-bottom:5px;">';
        }

        $html .= '<a href="' . esc_url($item['link']) . '" target="_blank" style="font-weight:bold;">';
        $html .= esc_html($item['title']);
        $html .= '</a>';

        $html .= '<br><small>' . esc_html($item['date']) . ' | ' . esc_html($item['category']) . '</small>';

        $html .= '</div>';
    }

    $html .= '</div>';

    return $html;
});
