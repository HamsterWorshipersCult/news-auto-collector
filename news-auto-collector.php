<?php
/*
Plugin Name: News Auto Collector Pro
Description: RSS news collector with caching, thumbnails, categories, and duplicate prevention
Version: 1.3
Author: Custom Build
*/

if (!defined('ABSPATH')) exit;

/* ================================
    1. 관리자 메뉴 등록
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
    2. 설정 데이터 등록
================================ */
add_action('admin_init', function () {
    register_setting('nac_settings', 'nac_feeds', [
        'sanitize_callback' => 'sanitize_textarea_field' // 데이터 안전하게 정제
    ]);
    register_setting('nac_settings', 'nac_items');
});

/* ================================
    3. 관리자 설정 페이지 UI
================================ */
function nac_admin_page() {
    // 저장된 피드 불러오기 및 역슬래시 제거
    $feeds = wp_unslash(get_option('nac_feeds', ''));
    ?>
    <div class="wrap">
        <h1>News Auto Collector</h1>

        <?php settings_errors(); ?>

        <form method="post" action="options.php">
            <?php settings_fields('nac_settings'); ?>

            <h2>RSS Feeds</h2>
            <textarea name="nac_feeds" rows="10" cols="80" class="large-text code" placeholder="tech | https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml"><?php echo esc_textarea($feeds); ?></textarea>

            <p class="description">형식: 한 줄에 하나씩 입력 (카테고리 | URL)</p>
            <p class="description">예시: <code>tech | https://rss.nytimes.com/services/xml/rss/nyt/Technology.xml</code></p>

            <?php submit_button('설정 저장'); ?>
        </form>

        <hr style="margin: 30px 0;">

        <h2>수동 업데이트 실행</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('nac_manual_action', 'nac_manual_nonce'); ?>
            <input type="hidden" name="action" value="nac_manual_trigger">
            <button type="submit" class="button button-secondary">지금 RSS 뉴스 가져오기</button>
        </form>
    </div>
    <?php
}

/* ================================
    4. RSS 데이터 수집 코어 함수
================================ */
function nac_fetch_news() {
    // WordPress RSS 라이브러리 로드
    include_once(ABSPATH . WPINC . '/feed.php');

    $feeds_str = get_option('nac_feeds', '');
    if (empty($feeds_str)) return;

    // 엔터 단위로 쪼개고 빈 줄 및 공백 제거
    $feed_lines = array_filter(array_map('trim', explode("\n", $feeds_str)));
    
    $existing = get_option('nac_items', []);
    if (!is_array($existing)) $existing = [];

    // 중복 방지를 위한 기존 링크 목록 추출
    $existing_links = array_column($existing, 'link');
    $all_items = [];

    foreach ($feed_lines as $feed_line) {
        if (empty($feed_line)) continue;

        $category = 'general';
        $url = $feed_line;

        // 파이프(|) 기호 분리 처리
        if (strpos($feed_line, '|') !== false) {
            $parts = explode('|', $feed_line, 2);
            $category = trim($parts[0]);
            $url = trim($parts[1]);
        }

        // URL 유효성 검사
        if (!filter_var($url, FILTER_VALIDATE_URL)) continue;

        // RSS 가져오기
        $feed = fetch_feed($url);
        if (is_wp_error($feed)) continue;

        // 최신 10개 아이템 제한
        $maxitems = $feed->get_item_quantity(10);
        $items = $feed->get_items(0, $maxitems);

        foreach ($items as $item) {
            $link = esc_url_raw($item->get_link());

            // 중복된 링크는 건너뛰기
            if (in_array($link, $existing_links)) continue;

            // 썸네일(Enclosure) 추출
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

            // 제목 안전하게 정제하여 배열 저장
            $all_items[] = [
                'title'     => sanitize_text_field($item->get_title()),
                'link'      => $link,
                'date'      => $item->get_date('Y-m-d H:i:s'),
                'category'  => sanitize_key($category), // 영문/숫자/대시 형태로 정제
                'thumbnail' => $thumb
            ];
        }
    }

    if (!empty($all_items)) {
        // 기존 뉴스 데이터와 병합
        $merged = array_merge($all_items, $existing); // 새로운 뉴스를 위로 보냄

        // 날짜 기준 내림차순 정렬
        usort($merged, function ($a, $b) {
            return strtotime($b['date']) - strtotime($a['date']);
        });

        // 최대 50개만 유지
        $merged = array_slice($merged, 0, 50);

        // DB 및 캐시(트랜지언트) 저장
