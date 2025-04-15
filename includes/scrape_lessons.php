<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'functions.php'; // Include functions.php
require_once plugin_dir_path(__FILE__) . 'upload_thumbnail.php';

/**
 * Scrape lessons from a course URL
 * 
 * @param array|string $args If array, contains url, cookies, is_arabic, and course_data. If string, it's the URL
 * @return array The scraped lesson data
 */
function wescraper_scrape_lessons($args) {
    // Process input parameters
    $url = '';
    $cookies = '';
    $is_arabic = false;
    $course_data = [];
    
    if (is_array($args)) {
        // New format with course_data for fallback descriptions
        $url = isset($args['url']) ? $args['url'] : '';
        $cookies = isset($args['cookies']) ? $args['cookies'] : '';
        $is_arabic = isset($args['is_arabic']) && $args['is_arabic'];
        $course_data = isset($args['course_data']) ? $args['course_data'] : [];
        
        wescraper_log("Using new parameter format with course data");
    } else {
        // Legacy format (just URL or is_arabic flag)
        if (is_bool($args)) {
            $is_arabic = $args;
        } else {
            $url = $args;
        }
        
        wescraper_log("Using legacy parameter format");
    }
    
    wescraper_log("Starting lesson scraping for URL: " . $url . ", is_arabic: " . ($is_arabic ? 'true' : 'false'));
    
    // Extract site type from URL
    $siteType = 'mindluster'; // Default to mindluster
    $detectedType = wescraper_detect_source_site($url);
    if ($detectedType) {
        $siteType = $detectedType;
        wescraper_log("Site type detected: " . $siteType);
    } else {
        wescraper_log("Unable to detect site type, using default: " . $siteType);
    }
    
    // Extract the main course description from course_data for use as fallback
    $main_course_description = "";
    if (!empty($course_data) && isset($course_data['description'])) {
        $main_course_description = $course_data['description'];
        wescraper_log("Main course description extracted for fallback use, length: " . mb_strlen($main_course_description));
    }

    try {
        wescraper_log("Starting wescraper_scrape_lessons function" . ($is_arabic ? " with Arabic processing" : ""));
        
        // Clean up all lesson data files
        $lesson_data_files = [
            plugin_dir_path(dirname(__FILE__)) . "lesson_data.json",
            plugin_dir_path(dirname(__FILE__)) . "arabic_lesson_data.json"
        ];
        
        foreach ($lesson_data_files as $file) {
            if (file_exists($file)) {
                unlink($file);
                wescraper_log("Deleted old lesson data file: " . basename($file));
            }
        }

        // Auto-detect if this should be Arabic processing
        if (!$is_arabic) {
            $result_json_path = plugin_dir_path(dirname(__FILE__)) . 'result.json';
            if (file_exists($result_json_path)) {
                $temp_data = json_decode(file_get_contents($result_json_path), true);
                if (is_array($temp_data) && isset($temp_data['course_name'])) {
                    // Check for Arabic characters in the course name
                    if (preg_match('/[\x{0600}-\x{06FF}]/u', $temp_data['course_name'])) {
                        $is_arabic = true;
                        wescraper_log("Auto-detected Arabic content, enabling Arabic processing");
                    }
                }
            }
        }

        // If we're using Arabic processing, make sure arabic_result.json exists and has valid data
        if ($is_arabic) {
            $arabic_result_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
            if (!file_exists($arabic_result_path)) {
                // Create arabic_result.json from result.json
                wescraper_create_arabic_result_json();
                wescraper_log("Created arabic_result.json from result.json for Arabic processing");
            } else {
                // Verify the file has a valid lessons array
                $temp_data = json_decode(file_get_contents($arabic_result_path), true);
                if (!isset($temp_data['lessons']) || !is_array($temp_data['lessons'])) {
                    // Recreate the file with valid data
                    wescraper_create_arabic_result_json();
                    wescraper_log("Recreated arabic_result.json with valid lessons data");
                }
            }
        }

        // For Arabic processing, we should work with arabic_result.json
        if ($is_arabic && file_exists(plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json')) {
            $resultJsonPath = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
            wescraper_log("Checking Arabic-specific result file: " . $resultJsonPath);
            
            // Check if arabic_result.json exists and has a valid lessons array
            $temp_data = json_decode(file_get_contents($resultJsonPath), true);
            if ($temp_data === null || !isset($temp_data['lessons']) || !is_array($temp_data['lessons'])) {
                wescraper_log("Arabic result file doesn't contain valid lessons data, falling back to result.json");
                $resultJsonPath = plugin_dir_path(dirname(__FILE__)) . 'result.json';
            } else {
                wescraper_log("Using Arabic-specific result.json: " . $resultJsonPath);
            }
        } else {
            // Read standard result.json
        $resultJsonPath = plugin_dir_path(dirname(__FILE__)) . 'result.json';
            wescraper_log("Using standard result.json: " . $resultJsonPath);
        }

        $jsonData = file_get_contents($resultJsonPath);
        if ($jsonData === false) {
            wescraper_log("CRITICAL ERROR: Failed to read JSON file: " . $resultJsonPath);
            return;
        }

        // Fix malformed array structure
        $jsonData = preg_replace('/\)\s*,\s*\[/', '],\n[', $jsonData);
        $jsonData = preg_replace('/\s+\(/', ' => Array(', $jsonData);

        // Ensure proper UTF-8 encoding of the JSON content
        $jsonData = mb_convert_encoding($jsonData, 'UTF-8', 'UTF-8');
        
        $data = json_decode($jsonData, true, 512, JSON_UNESCAPED_UNICODE);
        if ($data === null) {
            wescraper_log("CRITICAL ERROR: JSON decode failed. Error: " . json_last_error_msg());
            return;
        }

        // Debug output to check the structure of the data
        wescraper_log("JSON data structure: " . print_r(array_keys($data), true));
        
        // Check if 'lessons' key exists in data
        if (!isset($data['lessons']) || !is_array($data['lessons'])) {
            wescraper_log("CRITICAL ERROR: No 'lessons' array found in the JSON data");
            echo "❌ No lessons array found in the JSON data. Please scrape a course first.<br>";
            return;
        }
        
        // Check if lessons array is empty
        if (empty($data['lessons'])) {
            wescraper_log("CRITICAL ERROR: 'lessons' array is empty in the JSON data");
            echo "❌ The 'lessons' array is empty. Please scrape a course with lessons.<br>";
            return;
        }
        
        wescraper_log("Found " . count($data['lessons']) . " lessons in the result.json");
        echo "<strong>Found " . count($data['lessons']) . " lessons to scrape</strong><br>";

        // Ensure lessons array is properly indexed with no old data
        if (isset($data['lessons']) && is_array($data['lessons'])) {
            $data['lessons'] = array_values($data['lessons']);
            
            // Re-save the fixed result.json
            file_put_contents(
                $resultJsonPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        $cookiesFile = plugin_dir_path(dirname(__FILE__)) . 'cookies.json';
        $cookies = wescraper_get_cookies_from_json($cookiesFile);

        if (!$cookies) {
            echo '<div class="notice notice-error is-dismissible"><p>Failed to load cookies.</p></div>';
            return;
        }

        $allLessonData = [];

        foreach ($data['lessons'] as $index => $lesson) {
            // Properly decode Arabic title - improved for m3aarf
            $lessonTitle = $lesson['title'];
            // Only process if not already properly decoded
            if (preg_match('/\\\\u[0-9a-f]{4}/i', $lessonTitle) || strpos($lessonTitle, '&') !== false) {
            $lessonTitle = mb_convert_encoding(
                html_entity_decode($lesson['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                'UTF-8',
                'UTF-8'
            );
            }
            $lessonLink = $lesson['link'];
            $videoLength = $lesson['duration'] ?? "Unknown";

            echo "Processing: " . $lessonTitle . "<br>";

            // Use cookies only for mindluster
            $currentCookies = ($siteType === 'mindluster') ? $cookies : '';
            $lessonHtml = wescraper_fetch_url($lessonLink, $currentCookies);

            if ($lessonHtml) {
                if ($siteType === 'm3aarf') {
                    // m3aarf.com specific extraction with proper UTF-8 handling
                    libxml_use_internal_errors(true);
                    $dom = new DOMDocument('1.0', 'UTF-8');
                    // Correctly handle Arabic text in HTML
                    $html = preg_replace('/&(?!#?[a-z0-9]+;)/', '&amp;', $lessonHtml);
                    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
                    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
                    $xpath = new DOMXPath($dom);

                    // Extract video URL
                    if (preg_match('/<iframe[^>]*src="(https:\/\/www\.youtube\.com\/embed\/[^"]+)"/', $html, $matches)) {
                        $videoUrl = $matches[1];
                        preg_match('/embed\/([a-zA-Z0-9_-]+)/', $videoUrl, $idMatches);
                        $videoId = isset($idMatches[1]) ? $idMatches[1] : "N/A";
                    } 
                    // Try alternative pattern - video container with data-video attribute
                    else if (preg_match('/<div[^>]*class="[^"]*video-container[^"]*"[^>]*data-video="([^"]+)"/', $html, $matches)) {
                        $videoId = $matches[1];
                        $videoUrl = "https://www.youtube.com/embed/" . $videoId;
                        wescraper_log("Found video ID using data-video attribute: " . $videoId);
                    }
                    // Try pattern for YouTube player div
                    else if (preg_match('/<div[^>]*id="youtube-player"[^>]*data-id="([^"]+)"/', $html, $matches)) {
                        $videoId = $matches[1];
                        $videoUrl = "https://www.youtube.com/embed/" . $videoId;
                        wescraper_log("Found video ID using youtube-player div: " . $videoId);
                    }
                    else {
                        $videoUrl = "N/A";
                        $videoId = "N/A";
                    }

                    // Extract lesson content from the iframe in "ملحقات الدرس" section
                    $lessonContent = "  ";
                    
                    // First try to find the lesson attachments iframe using multiple patterns
                    $iframeFound = false;
                    
                    // Pattern 1: Look for iframe under "ملحقات الدرس" title
                    if (preg_match('/<div class="home_title">\s*ملحقات الدرس\s*<\/div>.*?<iframe\s+src="([^"]+)"[^>]*>/s', $html, $iframeMatches)) {
                        $descriptionUrl = $iframeMatches[1];
                        $iframeFound = true;
                    }
                    
                    // Pattern 2: Look for iframe in m3aarf_card div
                    if (!$iframeFound && preg_match('/<div class="row m3aarf_card">\s*<iframe\s+src="([^"]+)"[^>]*>/s', $html, $iframeMatches)) {
                        $descriptionUrl = $iframeMatches[1];
                        $iframeFound = true;
                    }
                    
                    // Pattern 3: Look for description URL directly in any iframe (with lesson/description pattern)
                    if (!$iframeFound && preg_match('/<iframe\s+src="(https?:\/\/www\.m3aarf\.com\/lesson\/description\/\d+)"[^>]*>/s', $html, $iframeMatches)) {
                        $descriptionUrl = $iframeMatches[1];
                        $iframeFound = true;
                    }
                    
                    // If we found an iframe with description URL
                    if ($iframeFound) {
                        // Use our specialized function to extract the iframe content
                        $lessonContent = wescraper_extract_m3aarf_iframe_content($descriptionUrl);
                        
                        // Check if the content extraction was successful
                        if (mb_strlen(trim($lessonContent)) < 20) {
                            wescraper_log("Warning: Extracted content is very short, may have failed. Length: " . mb_strlen($lessonContent));
                            
                            // Use the main course description as fallback
                            if (!empty($main_course_description)) {
                                wescraper_log("Using main course description as fallback");
                                $lessonContent = $main_course_description;
                            }
                        } else {
                            wescraper_log("Successfully extracted lesson content from iframe, length: " . mb_strlen($lessonContent));
                        }
                    } else {
                        wescraper_log("Could not find lesson description iframe, falling back to old method");
                        
                        // Fall back to alternative methods:
                        
                        // Method 1: Look for specific div with lesson content
                        $contentNode = $xpath->query('//*[@id="lesson_href"]/div[11]/div[2]')->item(0);
                        
                        if ($contentNode) {
                            $lessonContent = $dom->saveHTML($contentNode);
                            $lessonContent = wescraper_clean_arabic_text($lessonContent);
                            wescraper_log("Found content using fallback method 1 (specific div)");
                        } else {
                            // Method 2: Look for any content div
                            $contentNodes = $xpath->query('//div[@class="m3aarf_card"]');
                            
                            if ($contentNodes && $contentNodes->length > 0) {
                                $combinedContent = "";
                                foreach ($contentNodes as $node) {
                                    $combinedContent .= $dom->saveHTML($node) . "\n";
                                }
                                
                                $lessonContent = wescraper_clean_arabic_text($combinedContent);
                                wescraper_log("Found content using fallback method 2 (m3aarf_card divs)");
                            } else {
                                // Method 3: Use the main course description as final fallback
                                if (!empty($main_course_description)) {
                                    wescraper_log("All content extraction methods failed. Using main course description as fallback");
                                    $lessonContent = $main_course_description;
                                } else {
                                    wescraper_log("All content extraction methods failed and no main course description available");
                                }
                            }
                        }
                    }
                } else {
                    // Improved mindluster extraction
                    preg_match('/<img[^>]+src="https:\/\/img\.youtube\.com\/vi\/([^\/]+)\/hqdefault\.jpg"/', $lessonHtml, $videoIdMatches);
                    $videoId = isset($videoIdMatches[1]) ? $videoIdMatches[1] : "N/A";
                    $videoUrl = ($videoId !== "N/A") ? "https://www.youtube.com/embed/" . $videoId : "N/A";

                    // New improved mindluster content extraction using the "Lesson extensions" section
                    $lessonContent = " ";
                    
                    // First try to find the lesson extensions iframe using multiple patterns
                    $iframeFound = false;
                    
                    // Pattern 1: Look for iframe under "Lesson extensions" title
                    if (preg_match('/<div class="home_title">\s*Lesson extensions\s*<\/div>.*?<iframe\s+src="([^"]+)"[^>]*>/s', $lessonHtml, $iframeMatches)) {
                        $extensionUrl = $iframeMatches[1];
                        $iframeFound = true;
                        wescraper_log("Found lesson extension iframe URL in mindluster: " . $extensionUrl);
                    }
                    
                    // Pattern 2: Look for iframe in m3aarf_card div (which is actually used on mindluster too)
                    if (!$iframeFound && preg_match('/<div class="row m3aarf_card">\s*<iframe\s+src="([^"]+)"[^>]*>/s', $lessonHtml, $iframeMatches)) {
                        $extensionUrl = $iframeMatches[1];
                        $iframeFound = true;
                        wescraper_log("Found lesson extension iframe URL in m3aarf_card div: " . $extensionUrl);
                    }
                    
                    if ($iframeFound) {
                        // Use our specialized function to extract the iframe content
                        $lessonContent = wescraper_extract_mindluster_iframe_content($extensionUrl, $cookies);
                        
                        // Check if the content extraction was successful
                        if (mb_strlen(trim($lessonContent)) < 20) {
                            wescraper_log("Warning: Extracted content from mindluster is very short, may have failed. Length: " . mb_strlen($lessonContent));
                            
                            // Use the main course description as fallback
                            if (!empty($main_course_description)) {
                                wescraper_log("Using main course description as fallback for mindluster lesson");
                                $lessonContent = $main_course_description;
                            }
                        } else {
                            wescraper_log("Successfully extracted lesson content from mindluster iframe, length: " . mb_strlen($lessonContent));
                        }
                    } else {
                        // Fallback to old method if iframe not found
                        wescraper_log("No iframe found, falling back to old extraction method");
                    preg_match('/<div class="row m3aarf_card">.*?<iframe[^>]+src="([^"]+)"[^>]*>/s', $lessonHtml, $extensionIframeMatches);
                    if (isset($extensionIframeMatches[1])) {
                        $extensionUrl = $extensionIframeMatches[1];
                        $extensionHtml = wescraper_fetch_url($extensionUrl, $cookies);
                            
                            // Clean the text
                            $lessonContent = strip_tags($extensionHtml);
                            $lessonContent = wescraper_clean_text($lessonContent);
                            $lessonContent = nl2br($lessonContent);
                            
                            // If content is too short, use the main course description
                            if (mb_strlen(trim($lessonContent)) < 20 && !empty($main_course_description)) {
                                wescraper_log("Extracted content is too short, using main course description");
                                $lessonContent = $main_course_description;
                            }
                        } else {
                            // If no content was found, use the main course description as fallback
                            if (!empty($main_course_description)) {
                                wescraper_log("No lesson content found, using main course description as fallback");
                                $lessonContent = $main_course_description;
                    } else {
                                $lessonContent = "No Content Found";
                            }
                        }
                    }
                }

                // Add lesson details to the array
                $allLessonData[] = [
                    'title' => $lessonTitle,
                    'link' => $lessonLink,
                    'duration' => $videoLength,
                    'video_id' => $videoId,
                    'video_url' => $videoUrl,
                    'content' => $lessonContent,
                    // Include site type to help with processing
                    'site_type' => $siteType
                ];

                // Update the thumbnail from the first lesson if available
                if ($index === 0 && $videoId !== "N/A") {
                    $thumbnail = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                    
                    // Preserve existing data in result.json
                    if ($siteType === 'm3aarf') {
                        // Save thumbnail info to a separate file for Arabic courses
                        $arabic_thumbnail_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_thumbnail.json';
                        $thumbnail_data = ['thumbnail' => $thumbnail, 'video_id' => $videoId];
                        file_put_contents(
                            $arabic_thumbnail_path,
                            json_encode($thumbnail_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                        wescraper_log("Arabic thumbnail info saved to arabic_thumbnail.json");
                    } else {
                        // For non-Arabic content, update result.json but preserve existing fields
                        $result_json_path = plugin_dir_path(dirname(__FILE__)) . 'result.json';
                        if (file_exists($result_json_path)) {
                            $existing_data = json_decode(file_get_contents($result_json_path), true);
                            if (is_array($existing_data)) {
                                // Only update the thumbnail field, keep everything else
                                $existing_data['thumbnail'] = $thumbnail;
                    file_put_contents(
                                    $result_json_path,
                                    json_encode($existing_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                    echo "Course thumbnail updated in result.json from first lesson<br>";
                            }
                        }
                    }
                }

                echo "Extracted Video ID: " . $videoId . "<br>";
                echo "YouTube Video URL: " . $videoUrl . "<br>";
                echo "Title: " . $lessonTitle . "<br>";
                echo "Video Length: " . $videoLength . "<br>";
                echo "Content: " . mb_substr($lessonContent, 0, 100) . "...<br><br>";
            } else {
                echo "❌ Failed to fetch lesson: " . $lessonTitle . "<br>";
            }
        }

        // Save all lesson data to JSON file
        $lesson_json_path = '';
        if ($siteType === 'm3aarf' || $is_arabic) {
            $lesson_json_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_lesson_data.json';
        } else {
            $lesson_json_path = plugin_dir_path(dirname(__FILE__)) . 'lesson_data.json';
        }
        
        // Make sure we're not appending to an existing file
        if (file_exists($lesson_json_path)) {
            unlink($lesson_json_path);
            wescraper_log("Removed existing lesson data file to ensure clean data: " . basename($lesson_json_path));
        }
        
        file_put_contents(
            $lesson_json_path,
            json_encode($allLessonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        
        wescraper_log("✅ Lessons data saved successfully");
        echo "✅ Lessons data saved successfully<br>";
        
        // For Arabic sites, also save a copy of the final result in arabic_result.json
        if ($siteType === 'm3aarf' || $is_arabic) {
            // Don't overwrite existing arabic_result.json if it has valid data
            $arabic_result_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
            if (!file_exists($arabic_result_path)) {
                wescraper_create_arabic_result_json();
            }
        }

        // Call thumbnail upload for all site types
        wescraper_upload_thumbnail($siteType);
    } catch (Exception $e) {
        wescraper_log("Exception in wescraper_scrape_lessons: " . $e->getMessage());
        echo "❌ An error occurred while processing lessons. Please check the logs for more details.<br>";
    }
}

function decode_arabic_text($text) {
    // First convert to UTF-8
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // Decode JSON unicode escapes
    $text = json_decode('"' . str_replace('"', '\\"', $text) . '"', true) ?? $text;
    
    // Convert HTML entities to UTF-8
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Clean whitespace while preserving Arabic
    $text = preg_replace('/\s+/u', ' ', trim($text));
    
    return $text;
}

/**
 * Get lesson data from JSON file
 * @param bool $arabic Whether to get Arabic lesson data
 * @return array Lesson data array
 */
function wescraper_get_lesson_data($arabic = false) {
    // Choose the appropriate lesson data file
    if ($arabic) {
        $lesson_file = plugin_dir_path(dirname(__FILE__)) . 'arabic_lesson_data.json';
        wescraper_log("Using Arabic lesson data file: " . $lesson_file);
    } else {
        $lesson_file = plugin_dir_path(dirname(__FILE__)) . 'lesson_data.json';
        wescraper_log("Using standard lesson data file: " . $lesson_file);
    }
    
    // Check if the file exists
if (!file_exists($lesson_file)) {
        wescraper_log("Warning: " . basename($lesson_file) . " not found, will create new file");
        return [];
    } else {
        // Log file stats for debugging
        wescraper_log("File exists: " . $lesson_file . ", Size: " . filesize($lesson_file) . " bytes");
        
        // Attempt to read file content
    $lesson_content = file_get_contents($lesson_file);
    if ($lesson_content === false) {
            wescraper_log("Error: Failed to read " . basename($lesson_file));
            throw new Exception("Failed to read " . basename($lesson_file));
        }
        
        // Check for empty file
        if (empty(trim($lesson_content))) {
            wescraper_log("Warning: " . basename($lesson_file) . " is empty");
            return [];
        }
        
        // Remove BOM if present - use a more robust pattern
        $lesson_content = preg_replace('/^\xEF\xBB\xBF/', '', $lesson_content);
        
        // Clean up potential JSON issues
        $lesson_content = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x80-\x9F]/u', '', $lesson_content);
    
    // Ensure proper UTF-8 encoding
    $lesson_content = mb_convert_encoding($lesson_content, 'UTF-8', 'UTF-8');
    
        // Debug the JSON content
        wescraper_log("First 50 chars of content: " . substr($lesson_content, 0, 50));
        
        // Attempt to decode the JSON
    $lesson_json_data = json_decode($lesson_content, true);
        
        // Check for JSON errors
    if ($lesson_json_data === null) {
            wescraper_log("Warning: Invalid JSON in " . basename($lesson_file) . ": " . json_last_error_msg());
            return [];
        }
        
        wescraper_log("Successfully parsed " . count($lesson_json_data) . " lessons from " . basename($lesson_file));
        return $lesson_json_data;
    }
}

/**
 * Create or update the arabic_result.json file with data from result.json
 * This function ensures the arabic_result.json file always has complete data
 */
function wescraper_create_arabic_result_json() {
    $result_json_path = plugin_dir_path(dirname(__FILE__)) . 'result.json';
    $arabic_result_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
    
    // Only proceed if the original result.json exists
    if (!file_exists($result_json_path)) {
        wescraper_log("Cannot create arabic_result.json: result.json does not exist");
        return false;
    }
    
    // Read the original result.json
    $result_data = json_decode(file_get_contents($result_json_path), true);
    if ($result_data === null) {
        wescraper_log("Cannot create arabic_result.json: Failed to parse result.json");
        return false;
    }
    
    // Ensure it has a lessons array, even if empty
    if (!isset($result_data['lessons'])) {
        $result_data['lessons'] = [];
        wescraper_log("Added empty lessons array to result data");
    }
    
    // Check if arabic_result.json already exists - if so, preserve other fields
    $merged_data = $result_data;
    if (file_exists($arabic_result_path)) {
        $existing_arabic_data = json_decode(file_get_contents($arabic_result_path), true);
        if (is_array($existing_arabic_data)) {
            // Preserve fields from existing file that aren't in the new data
            foreach ($existing_arabic_data as $key => $value) {
                if (!isset($merged_data[$key]) && $key != 'lessons') {
                    $merged_data[$key] = $value;
                    wescraper_log("Preserved existing field in arabic_result.json: " . $key);
                }
            }
        }
    }
    
    // Create/update arabic_result.json with the complete data structure
    $result = file_put_contents(
        $arabic_result_path,
        json_encode($merged_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
    
    if ($result) {
        wescraper_log("Successfully created/updated arabic_result.json while preserving existing data");
        return true;
    } else {
        wescraper_log("Failed to write to arabic_result.json");
        return false;
    }
}

