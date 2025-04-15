<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function new_site_scrape_course($url, $cookies = '') {
    $html = wescraper_fetch_url($url, $cookies);
    if (!$html) {
        return false;
    }

    $data = [];

    // 1. Course Name - Modify selector based on new site's HTML structure
    preg_match('/<h1[^>]*class="course-title"[^>]*>(.*?)<\/h1>/', $html, $match);
    $data["course_name"] = trim($match[1] ?? "Not found");
    wescraper_log("course name extracted: " . $data["course_name"]);

    // 2. Category - Modify selector based on new site's HTML structure
    preg_match('/<div class="course-category">.*?<a[^>]*>(.*?)<\/a>/', $html, $match);
    $data["category"] = trim($match[1] ?? "Not found");
    wescraper_log("category extracted: " . $data["category"]);

    // 3. Lesson Count - Modify selector based on new site's HTML structure
    preg_match('/<span class="lesson-count">(\d+)<\/span>/', $html, $match);
    $data["lesson_count"] = trim($match[1] ?? "Not found");
    wescraper_log("lesson count extracted: " . $data["lesson_count"]);

    // 4. Course Description - Modify selector based on new site's HTML structure
    preg_match('/<div class="course-description">(.*?)<\/div>/s', $html, $match);
    $data["description"] = wescraper_clean_text($match[1] ?? "Not found");
    wescraper_log("description extracted: " . $data["description"]);

    // 5. Lessons - Modify selectors based on new site's HTML structure
    preg_match_all('/<div class="lesson-item">.*?<a[^>]*href="([^"]+)"[^>]*>(.*?)<\/a>.*?<span class="duration">([^<]+)<\/span>/s', $html, $matches);
    
    $lessons = [];
    foreach ($matches[1] as $index => $link) {
        $lessons[] = [
            "title" => trim($matches[2][$index]),
            "link" => $link,
            "duration" => $matches[3][$index] ?? "Unknown"
        ];
    }
    $data["lessons"] = $lessons;
    wescraper_log("lessons extracted: " . print_r($data["lessons"], true));

    // 6. Intro Video - Using first lesson's video
    $data["intro_video"] = isset($lessons[0]) ? $lessons[0]["link"] : "Not found";
    wescraper_log("intro video extracted: " . $data["intro_video"]);

    // 7. Thumbnail - Modify selector based on new site's HTML structure
    preg_match('/<img[^>]*class="course-thumbnail"[^>]*src="([^"]+)"[^>]*>/', $html, $match);
    $data["thumbnail"] = $match[1] ?? "Not found";
    wescraper_log("thumbnail extracted: " . $data["thumbnail"]);

    return $data;
}


function m3aarf_scrape_course($url, $cookies = '') {
    wescraper_log("Starting m3aarf course scraping: " . $url);
    
    $html = wescraper_fetch_url($url, '');
    if (!$html) {
        wescraper_log("Failed to fetch course page");
        return false;
    }

    try {
        $data = [];

        // Course Name with proper encoding - using specialized Arabic cleaning function
        if (preg_match('/<h1[^>]*>(.*?)<\/h1>/s', $html, $match)) {
            $raw_name = trim(strip_tags($match[1]));
            $data["course_name"] = wescraper_clean_arabic_text($raw_name);
            wescraper_log("Course name extracted and cleaned for Arabic: " . $data["course_name"]);
        }

        // Category with proper encoding - using specialized Arabic cleaning function
        if (preg_match('/class="breadcrumb-item active".*?>(.*?)<\/a>/s', $html, $match)) {
            $raw_category = trim(strip_tags($match[1]));
            $data["category"] = wescraper_clean_arabic_text($raw_category);
            wescraper_log("Category extracted and cleaned for Arabic: " . $data["category"]);
        }

        // Description with proper encoding - using specialized Arabic cleaning function
        if (preg_match('/<meta[^>]*name="description"[^>]*content="([^"]+)"/i', $html, $match)) {
            $raw_description = trim($match[1]);
            $data["description"] = wescraper_clean_arabic_text($raw_description);
            wescraper_log("Description extracted and cleaned for Arabic: " . substr($data["description"], 0, 100) . "...");
        }

        $data["thumbnail"] = "pending";
        
        // Extract lessons with durations preserved
        $lessons = m3aarf_extract_lessons($url, $html);
        
        // Ensure durations are preserved and titles are properly cleaned
        foreach ($lessons as &$lesson) {
            // Use specialized Arabic cleaning for titles
            $lesson['title'] = wescraper_clean_arabic_text($lesson['title']);
            
            // Make sure duration is not processed again
            if (!empty($lesson['duration'])) {
                $lesson['duration'] = trim($lesson['duration']);
            }
        }
        unset($lesson); // Break the reference
        
        $data["lessons"] = $lessons;
        $data["lesson_count"] = count($lessons);
        $data["intro_video"] = !empty($lessons) ? $lessons[0]["link"] : "";

        return $data;

    } catch (Exception $e) {
        wescraper_log("Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Extract lessons from M3AARF HTML content
 * 
 * @param string $url The course URL
 * @param string $html The HTML content
 * @param string $cookies Optional cookies for authenticated access
 * @return array An array of lesson data
 */
function m3aarf_extract_lessons($url, $html, $cookies = '') {
    wescraper_log("Extracting lessons from M3AARF course");
    
    $lessons = [];
    $page = 1;
    $max_pages = 10; // Safety limit
    
    // Process initial HTML
    $new_lessons = m3aarf_extract_page_lessons($html);
    $lessons = array_merge($lessons, $new_lessons);
    wescraper_log("Extracted " . count($new_lessons) . " lessons from page 1");
    
    // Check if there are more pages
    while ($page < $max_pages) {
        // Look for pagination links
        preg_match('/<a[^>]*href="([^"]*page=' . ($page + 1) . '[^"]*)"[^>]*>/', $html, $pagination_match);
        
        if (empty($pagination_match[1])) {
            wescraper_log("No more pagination links found after page $page");
            break;
        }
        
        $page++;
        $next_page_url = $pagination_match[1];
        wescraper_log("Found next page link: $next_page_url");
        
        // Fetch the next page
        $next_html = wescraper_fetch_url($next_page_url, $cookies);
        if (empty($next_html)) {
            wescraper_log("Failed to fetch page $page from $next_page_url");
            break;
        }
        
        // Extract lessons from this page
        $new_lessons = m3aarf_extract_page_lessons($next_html);
        $lessons = array_merge($lessons, $new_lessons);
        wescraper_log("Extracted " . count($new_lessons) . " lessons from page $page");
        
        // If we found no new lessons, break
        if (count($new_lessons) === 0) {
            wescraper_log("No new lessons found on page $page, stopping pagination");
            break;
        }
        
        // Save the new HTML for the next iteration
        $html = $next_html;
    }
    
    wescraper_log("Total lessons extracted: " . count($lessons));
    return $lessons;
}

/**
 * Extract lessons from a single M3AARF page
 * 
 * @param string $html The HTML content of the page
 * @return array An array of lesson data for this page
 */
function m3aarf_extract_page_lessons($html) {
    $lessons = [];
    
    // Extract lesson links and titles - Updated pattern to match current m3aarf.com structure
    preg_match_all('/<a[^>]*href="(https:\/\/www\.m3aarf\.com\/lesson\/\d+-video)"[^>]*class="[^"]*lesson-item[^"]*"[^>]*title="([^"]+)".*?<span class="lesson-duration">([^<]+)<\/span>/is', $html, $matches);
    
    if (empty($matches[1])) {
        wescraper_log("No lesson links found in this page");
        return $lessons;
    }
    
    foreach ($matches[1] as $index => $link) {
        // Clean title
        $title = isset($matches[2][$index]) ? $matches[2][$index] : '';
        $title = trim($title);
        
        // Keep the original duration text without any formatting
        $duration = isset($matches[3][$index]) ? trim($matches[3][$index]) : 'Unknown';
        wescraper_log("Raw duration from HTML: " . $duration);
        
        $lessons[] = [
            "title" => $title,
            "link" => $link,
            "duration" => $duration
        ];
    }
    
    return $lessons;
}

/**
 * Get the duration of a lesson from its page
 * 
 * @param string $url The lesson URL
 * @return string The formatted duration or "Unknown"
 */
function m3aarf_get_lesson_duration($url) {
    // Try to fetch the lesson page to get the duration
    $html = wescraper_fetch_url($url);
    if (empty($html)) {
        wescraper_log("Failed to fetch lesson page for duration: $url");
        return "Unknown";
    }
    
    // Look for duration in several possible formats
    $duration_patterns = [
        '/<span[^>]*class="[^"]*duration[^"]*"[^>]*>(.*?)<\/span>/is',
        '/<div[^>]*class="[^"]*lesson-duration[^"]*"[^>]*>(.*?)<\/div>/is',
        '/<div[^>]*class="[^"]*lesson-meta[^"]*"[^>]*>.*?(\d+:\d+).*?<\/div>/is',
        '/\b(\d+:\d+:\d+|\d+:\d+)\b/' // Generic time format
    ];
    
    foreach ($duration_patterns as $pattern) {
        if (preg_match($pattern, $html, $duration_match)) {
            $duration = trim(strip_tags($duration_match[1]));
            // Normalize to MM:SS format if needed
            if (preg_match('/^\d+$/', $duration)) {
                // Convert seconds to MM:SS
                $minutes = floor($duration / 60);
                $seconds = $duration % 60;
                $duration = sprintf("%02d:%02d", $minutes, $seconds);
            }
            wescraper_log("Found duration for lesson: $duration");
            return $duration;
        }
    }
    
    wescraper_log("No duration found for lesson: $url");
    return "Unknown";
}
?> 