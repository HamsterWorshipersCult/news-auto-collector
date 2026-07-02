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
            $parts = explode('|',
