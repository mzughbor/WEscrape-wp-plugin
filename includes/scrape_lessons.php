<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'functions.php'; // Include functions.php
require_once plugin_dir_path(__FILE__) . 'upload_thumbnail.php';

function wescraper_scrape_lessons() {
    $jsonData = file_get_contents(plugin_dir_path(dirname(__FILE__)) . 'result.json');
    $data = json_decode($jsonData, true);

    if ($data === null) {
        echo '<div class="notice notice-error is-dismissible"><p>Error decoding JSON data.</p></div>';
        return;
    }

    $cookiesFile = plugin_dir_path(dirname(__FILE__)) . 'cookies.json';
    $cookies = wescraper_get_cookies_from_json($cookiesFile);

    if (!$cookies) {
        echo '<div class="notice notice-error is-dismissible"><p>Failed to load cookies.</p></div>';
        return;
    }

    $allLessonData = [];

    foreach ($data['lessons'] as $index => $lesson) {
        $lessonTitle = $lesson['title'];
        $lessonLink = $lesson['link'];
        $videoLength = $lesson['duration'] ?? "Unknown";

        echo "Processing: " . $lessonTitle . "<br>";

        $lessonHtml = wescraper_fetch_url($lessonLink, $cookies);

        if ($lessonHtml) {
            preg_match('/<img[^>]+src="https:\/\/img\.youtube\.com\/vi\/([^\/]+)\/hqdefault\.jpg"/', $lessonHtml, $videoIdMatches);
            $videoId = isset($videoIdMatches[1]) ? $videoIdMatches[1] : "N/A";

            if ($index === 0 && $videoId !== "N/A") {
                $thumbnail = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";

                $jsonData = json_decode(file_get_contents(plugin_dir_path(dirname(__FILE__)) . 'result.json'), true);
                $jsonData['thumbnail'] = $thumbnail;
                file_put_contents(plugin_dir_path(dirname(__FILE__)) . 'result.json', json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                echo "Course thumbnail updated in result.json from first lesson<br>";
            }

            $videoUrl = ($videoId !== "N/A") ? "https://www.youtube.com/embed/" . $videoId : "N/A";

            echo "Extracted Video ID: " . $videoId . "<br>";
            echo "YouTube Video URL: " . $videoUrl . "<br>";

            preg_match('/<div class="row m3aarf_card">.*?<iframe[^>]+src="([^"]+)"[^>]*>/s', $lessonHtml, $extensionIframeMatches);
            $extensionUrl = isset($extensionIframeMatches[1]) ? $extensionIframeMatches[1] : null;

            if ($extensionUrl) {
                $extensionHtml = wescraper_fetch_url($extensionUrl, $cookies);
                $lessonExtensionText = strip_tags($extensionHtml);
                $lessonExtensionText = preg_replace('/\* \{[^}]+\}|\s*<style[^>]*>.*?<\/style>/is', '', $lessonExtensionText);
                $lessonExtensionText = trim(preg_replace('/\s+/', ' ', $lessonExtensionText));
                $lessonExtensionText = nl2br($lessonExtensionText);
            } else {
                $lessonExtensionText = "No Extensions Found";
            }

            echo "Title: " . $lessonTitle . "<br>";
            echo "Video Length: " . $videoLength . "<br>";
            echo "Extensions: " . $lessonExtensionText . "<br><br>";

            $lessonData = [
                'title' => $lessonTitle,
                'video_url' => $videoUrl,
                'video_length' => $videoLength,
                'extensions' => $lessonExtensionText,
            ];

            $allLessonData[] = $lessonData;
        } else {
            echo "❌ Failed to fetch lesson page for: " . $lessonTitle . "<br>";
        }
    }

    if (!empty($allLessonData)) {
        file_put_contents(plugin_dir_path(dirname(__FILE__)) . "lesson_data.json", json_encode($allLessonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        echo "✅ Lessons data saved to lesson_data.json!<br>";
        wescraper_log('✅ Lessons data saved to lesson_data.json!<br>');
        wescraper_upload_thumbnail();
    } else {
        wescraper_log('❌ No lessons data to save.<br>');
        echo "❌ No lessons data to save.<br>";
    }
}
?>