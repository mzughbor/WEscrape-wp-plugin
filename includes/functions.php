<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Function to clean description text and remove HTML
function wescraper_clean_text($text) {
    // Remove ads and unwanted scripts (adsbygoogle)
    $text = preg_replace('/\(adsbygoogle = window.adsbygoogle \|\| \[\]\)\.push\(\{\}\);/s', '', $text);
    
    // Remove all HTML tags
    $text = strip_tags($text);
    
    // Convert HTML entities to normal text
    $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
    
    // Remove excessive newlines, carriage returns, and extra spaces
    $text = preg_replace('/\s+/', ' ', trim($text));

    return $text;
}

function wescraper_extract_data($html) {
    $data = [];

    // 1. Course Name
    preg_match('/<h1[^>]*id="course_title"[^>]*>(.*?)<\/h1>/', $html, $match);
    $data["course_name"] = trim($match[1] ?? "Not found");
    wescraper_log("course name extracted: " . $data["course_name"]);

    // 2. Category
    preg_match('/<li class="breadcrumb-item color">.*?<a[^>]*href="[^"]*certified\/cat\/\d+\/([^"]+)"[^>]*>/', $html, $match);
    $data["category"] = trim($match[1] ?? "Not found");
    wescraper_log("category extracted: " . $data["category"]);

    // 3. Lesson Count (Fixed)
    preg_match('/<div class="home_title">[^|]+\|\s*(\d+)\s*<\/div>/', $html, $match);
    $data["lesson_count"] = trim($match[1] ?? "Not found");
    wescraper_log("lesson count extracted: " . $data["lesson_count"]);

    // 4. Course Description (Now Clean)
    preg_match_all('/<div class="m3aarf_card">(.*?)<\/div>/s', $html, $matches);
    $raw_description = isset($matches[1]) ? implode("\n", $matches[1]) : "Not found";
    $data["description"] = wescraper_clean_text($raw_description);
    wescraper_log("description extracted: " . $data["description"]);

    // 5. Lessons (Links, Titles, and Durations)
    preg_match_all('/<a[^>]*href="(https:\/\/www\.mindluster\.com\/lesson\/\d+-video)"[^>]*title="([^"]+)"/', $html, $matches);
    preg_match_all('/<span class="lesson_duration">([^<]+)<\/span>/', $html, $durationMatches);

    $lessons = [];
    foreach ($matches[1] as $index => $link) {
        $lessons[] = [
            "title" => trim($matches[2][$index]),
            "link" => $link,
            "duration" => $durationMatches[1][$index] ?? "Unknown"
        ];
    }
    $data["lessons"] = $lessons;
    wescraper_log("lessons extracted: " . print_r($data["lessons"],true));

    // 6. Intro Video (Using First Lesson's Video)
    $data["intro_video"] = isset($lessons[0]) ? $lessons[0]["link"] : "Not found";
    wescraper_log("intro video extracted: " . $data["intro_video"]);

    // 7. Thumbnail will be set during lesson scraping
    $data["thumbnail"] = "pending"; // Will be updated during lesson scraping
    wescraper_log("thumbnail extracted: " . $data["thumbnail"]);

    return $data;
}

function wescraper_fetch_url($url, $cookies = '') {
    wescraper_log("Fetching URL: " . $url);
    $args = array(
        'headers' => array(
            'Cookie' => $cookies,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ),
        'sslverify' => true,
    );
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        wescraper_log("wp_remote_get error: " . print_r($response->get_error_message(), true));
        return false; // Handle error
    }

    $body = wp_remote_retrieve_body($response);
    if(empty($body)){
        wescraper_log("Retrieved body is empty.");
    } else {
        wescraper_log("Retrieved body length: " . strlen($body));
    }
    return $body;
}
?>