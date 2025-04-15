<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'scrape_lessons.php';
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'new_site_scraper.php';
require_once plugin_dir_path(dirname(__FILE__)) . '/add-course-page.php';

function wescraper_scrape_course() {
    $courseUrl = get_option('wescraper_course_url', "");
    $coursesFile = plugin_dir_path(dirname(__FILE__)) . 'scraped_courses.txt';

    // Skip empty URLs
    if (empty(trim($courseUrl))) {
        return;
    }

    // Check if course URL already exists
    if (file_exists($coursesFile)) {
        $courses = file($coursesFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $courses = array_map('strtolower', array_map('trim', $courses));
        if (in_array(strtolower(trim($courseUrl)), $courses)) {
            return "duplicate"; // Return status instead of echoing
        }
    }

    // Clean up old JSON files before creating new ones
    wescraper_log("Cleaning up old JSON files before scraping new course");
    $files_to_clean = [
        plugin_dir_path(dirname(__FILE__)) . 'result.json',
        plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json',
        plugin_dir_path(dirname(__FILE__)) . 'lesson_data.json',
        plugin_dir_path(dirname(__FILE__)) . 'arabic_lesson_data.json',
        plugin_dir_path(dirname(__FILE__)) . 'arabic_thumbnail.json',
        plugin_dir_path(dirname(__FILE__)) . 'arabic_thumbnail_id.json'
    ];
    
    foreach ($files_to_clean as $file) {
        if (file_exists($file)) {
            // Don't delete the file, just empty its contents to clean data
            file_put_contents($file, '');
            wescraper_log("Cleared contents of " . basename($file));
        }
    }

    // Detect source site from URL
    $sourceSite = wescraper_detect_source_site($courseUrl);
    if (!$sourceSite) {
        echo '<div class="notice notice-error is-dismissible"><p>❌ Error: Unsupported site URL. Please check the URL format.</p></div>';
        return;
    }

    $cookiesFile = plugin_dir_path(__FILE__) . '../cookies.json';
    $cookies = wescraper_get_cookies_from_json($cookiesFile);
    if (!$cookies) {
        echo '<p>❌ Error: Failed to load cookies.</p>';
        return;
    }

    $courseData = false;
    
    // Choose scraper based on detected source site
    switch ($sourceSite) {
        case 'mindluster':
            $html = wescraper_fetch_url($courseUrl, $cookies);
            wescraper_log("Mindluster HTML length: " . strlen($html));
            if ($html) {
                $courseData = wescraper_extract_data($html, 'mindluster');
            }
            break;
        case 'm3aarf':
            wescraper_log("Starting m3aarf scraping process");
            // Don't pass cookies for m3aarf
            $courseData = wescraper_extract_data($courseUrl, 'm3aarf', '');
            if (!$courseData) {
                wescraper_log("M3aarf scraping failed to return data");
            } else {
                // For m3aarf sites, fetch and add lessons immediately
                wescraper_log("Fetching lessons for m3aarf site immediately");
                $html = wescraper_fetch_url($courseUrl, '');
                if ($html) {
                    // Extract lessons directly
                    $lessons = m3aarf_extract_lessons($courseUrl, $html);
                    if (is_array($lessons) && !empty($lessons)) {
                        $courseData['lessons'] = $lessons;
                        $courseData['lesson_count'] = count($lessons);
                        wescraper_log("Added " . count($lessons) . " lessons to course data immediately");
                        
                        // Save thumbnail from first lesson
                        if (isset($lessons[0]['link'])) {
                            $lessonHtml = wescraper_fetch_url($lessons[0]['link'], '');
                            if ($lessonHtml && preg_match('/<iframe[^>]*src="(https:\/\/www\.youtube\.com\/embed\/[^"]+)"/', $lessonHtml, $matches)) {
                                $videoUrl = $matches[1];
                                preg_match('/embed\/([a-zA-Z0-9_-]+)/', $videoUrl, $idMatches);
                                $videoId = isset($idMatches[1]) ? $idMatches[1] : "N/A";
                                if ($videoId !== "N/A") {
                                    $courseData['thumbnail'] = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                                }
                            }
                        }
                    }
                }
            }
            break;
        case 'new_site':
            $courseData = new_site_scrape_course($courseUrl, $cookies);
            break;
        default:
            wescraper_log("Unsupported site detected: " . $sourceSite);
            echo '<p>❌ Error: Unsupported site detected.</p>';
            return;
    }

    if ($courseData) {
        wescraper_log("Course data successfully extracted: " . print_r($courseData, true));
        
        // Check if we need to preserve existing data (like thumbnail_id)
        $existingData = [];
        $resultJsonPath = plugin_dir_path(__FILE__) . "../result.json";
        
        if (file_exists($resultJsonPath)) {
            $existingJson = file_get_contents($resultJsonPath);
            $existingData = json_decode($existingJson, true);
            
            if (is_array($existingData)) {
                // Preserve important fields like thumbnail_id
                if (isset($existingData['thumbnail_id'])) {
                    $courseData['thumbnail_id'] = $existingData['thumbnail_id'];
                }
                if (isset($existingData['thumbnail'])) {
                    $courseData['thumbnail'] = $existingData['thumbnail'];
                }
            }
        }
        
        // Ensure proper encoding of Arabic text
        array_walk_recursive($courseData, function(&$item) {
            if (is_string($item)) {
                $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
            }
        });
        
        $resultJson = json_encode($courseData, 
            JSON_PRETTY_PRINT | 
            JSON_UNESCAPED_SLASHES | 
            JSON_UNESCAPED_UNICODE | 
            JSON_PRESERVE_ZERO_FRACTION
        );
        
        if ($resultJson === false) {
            wescraper_log("JSON encoding failed: " . json_last_error_msg());
            return "failed_json";
        }

        if (file_put_contents(plugin_dir_path(__FILE__) . "../result.json", $resultJson)) {
            // Only add to scraped_courses.txt after successful save
            if (!file_exists($coursesFile)) {
                touch($coursesFile);
            }
            file_put_contents($coursesFile, $courseUrl . "\n", FILE_APPEND);
            
            // For Arabic sites, also create arabic_result.json immediately
            if ($sourceSite === 'm3aarf') {
                wescraper_create_arabic_result_json();
                
                // Also save lesson data to arabic_lesson_data.json
                if (isset($courseData['lessons']) && !empty($courseData['lessons'])) {
                    $lesson_json_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_lesson_data.json';
                    $allLessonData = [];
                    
                    foreach ($courseData['lessons'] as $lesson) {
                        $lessonHtml = wescraper_fetch_url($lesson['link'], '');
                        if ($lessonHtml) {
                            // Extract video info
                            if (preg_match('/<iframe[^>]*src="(https:\/\/www\.youtube\.com\/embed\/[^"]+)"/', $lessonHtml, $matches)) {
                                $videoUrl = $matches[1];
                                preg_match('/embed\/([a-zA-Z0-9_-]+)/', $videoUrl, $idMatches);
                                $videoId = isset($idMatches[1]) ? $idMatches[1] : "N/A";
                            } else {
                                $videoUrl = "N/A";
                                $videoId = "N/A";
                            }
                            
                            // Extract lesson content (simplified)
                            $lessonContent = "";
                            if (preg_match('/<div class="home_title">\s*ملحقات الدرس\s*<\/div>.*?<iframe\s+src="([^"]+)"[^>]*>/s', $lessonHtml, $iframeMatches)) {
                                $lessonContent = wescraper_extract_m3aarf_iframe_content($iframeMatches[1]);
                            }
                            
                            $allLessonData[] = [
                                'title' => $lesson['title'],
                                'link' => $lesson['link'],
                                'duration' => $lesson['duration'],
                                'video_id' => $videoId,
                                'video_url' => $videoUrl,
                                'content' => $lessonContent,
                                'site_type' => 'm3aarf'
                            ];
                        }
                    }
                    
                    if (!empty($allLessonData)) {
                        file_put_contents(
                            $lesson_json_path,
                            json_encode($allLessonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                        );
                        wescraper_log("Saved " . count($allLessonData) . " detailed lessons to arabic_lesson_data.json");
                    }
                }
            }
            
            return "success";
        } else {
            wescraper_log("Failed to write to result.json");
            return "failed_save";
        }
    } else {
        wescraper_log("Course data extraction failed");
        return "failed_scrape";
    }
}

// Add admin page to trigger the test
add_action('admin_menu', 'wescraper_add_admin_menu');

function wescraper_add_admin_menu() {
    add_menu_page(
        'WeScraper Course Scraper',
        'Course Scraper',
        'manage_options',
        'wescraper-scrape',
        'wescraper_scrape_page'
    );
}

function wescraper_scrape_page() {
    $currentUrl = get_option('wescraper_course_url', "");
    $status = null;

    // Only process form if it was actually submitted
    if (isset($_POST['wescraper_scrape_now']) && isset($_POST['wescraper_course_url'])) {
        // Update the URL option
        $newUrl = sanitize_url($_POST['wescraper_course_url']);
        update_option('wescraper_course_url', $newUrl);
        
        // Call scrape function and get status
        $status = wescraper_scrape_course();
        
        // Show appropriate message based on status
        if ($status === "duplicate") {
            echo '<div class="notice notice-warning is-dismissible"><p>Course URL already scraped!</p></div>';
        } elseif ($status === "success") {
            echo '<div class="notice notice-success is-dismissible"><p>✅ Course data saved to result.json!</p><p>All previous course data was cleaned to prevent mixing of lessons.</p></div>';
        } elseif ($status === "failed_save") {
            echo "<p>❌ Failed to save course data to result.json.</p>";
        } elseif ($status === "failed_scrape") {
            echo "<p>❌ Failed to fetch course data.</p>";
        }
    }

    $resultJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../result.json')) {
        $resultJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../result.json');
    }

    $lessonJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../lesson_data.json')) {
        $lessonJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../lesson_data.json');
    }
    
    // Arabic specific JSON files
    $arabicResultJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../arabic_result.json')) {
        $arabicResultJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../arabic_result.json');
    }
    
    $arabicLessonJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../arabic_lesson_data.json')) {
        $arabicLessonJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../arabic_lesson_data.json');
    }

    $textareaDirection = is_rtl() ? 'ltr' : 'rtl';

    ?>
    <div class="wrap">
        <h2>WeScraper Course Scraper</h2>
        <form method="post">
            <label for="wescraper_course_url">Course URL:</label><br>
            <input type="text" name="wescraper_course_url" id="wescraper_course_url" value="<?php echo esc_attr($currentUrl); ?>" style="width: 500px;"><br><br>
            <p class="description">Supported sites: Mindluster, M3AARF, New Site</p>
            
            <input type="hidden" name="wescraper_scrape_now" value="1">
            <input type="submit" class="button button-primary" value="Scrape Course">
        </form>

        <br>
        <h3>Latest Scraped Data (result.json)</h3>
        <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo esc_attr($textareaDirection); ?>;">
            <?php echo esc_textarea($resultJsonContent); ?>
        </textarea>
        <br>
        <form method="post">
            <input type="hidden" name="wescraper_scrape_lessons" value="1">
            <input type="submit" class="button button-secondary" value="Scrape Lessons">
            <?php 
            // First check for Arabic content in result.json
            $has_arabic_content = false;
            $result_json_path = plugin_dir_path(__FILE__) . '../result.json';
            
            if (file_exists($result_json_path)) {
                $temp_data = json_decode(file_get_contents($result_json_path), true);
                if (is_array($temp_data) && isset($temp_data['course_name'])) {
                    // Check for Arabic characters in the course name
                    if (preg_match('/[\x{0600}-\x{06FF}]/u', $temp_data['course_name'])) {
                        $has_arabic_content = true;
                        echo '<span style="color:#0073aa; margin-left:10px;"><strong>✓ Arabic content detected!</strong> Special Arabic handling automatically enabled.</span>';
                    }
                }
                
                // If no Arabic content detected automatically, still offer checkbox for manual override
                if (!$has_arabic_content) {
                    echo '<label style="margin-left: 10px;"><input type="checkbox" name="wescraper_arabic_processing" value="1" style="margin-right: 5px;"> Force Arabic processing</label>';
                    echo '<p class="description" style="margin-top: 5px; margin-left: 10px;"><small>✓ Use this only if the content has Arabic but wasn\'t detected automatically</small></p>';
                } else {
                    echo '<input type="hidden" name="wescraper_arabic_processing" value="1">';
                }
            }
            ?>
        </form>
        <?php
        if (isset($_POST['wescraper_scrape_lessons'])) {
            echo '<div class="notice notice-info is-dismissible"><p><strong>Starting lesson scraping process</strong></p><p>Old lesson data will be cleaned to prevent mixing of content between courses.</p></div>';
            $arabicProcessing = isset($_POST['wescraper_arabic_processing']) ? true : $has_arabic_content;
            
            // Extract course data to use description as fallback for lesson content
            $courseData = wescraper_extract_data($html, $sourceSite, $cookies);
            if (!$courseData) {
                wescraper_log("Course extraction failed, will still attempt to scrape lessons");
                $courseData = [];
            } else {
                wescraper_log("Course data extracted successfully for fallback use");
            }
            
            // Pass course data to lesson scraper
            $lessonData = [
                'url' => $courseUrl,
                'cookies' => $cookies,
                'is_arabic' => $arabicProcessing,
                'course_data' => $courseData
            ];
            wescraper_scrape_lessons($lessonData);
        }
        ?>
        <br>
        <h3>Latest Scraped Lessons (lesson_data.json)</h3>
        <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo esc_attr($textareaDirection); ?>;">
            <?php echo esc_textarea($lessonJsonContent); ?>
        </textarea>
        
        <?php if (file_exists(plugin_dir_path(__FILE__) . '../arabic_result.json')): ?>
            <br>
            <h3>Arabic Course Data (arabic_result.json)</h3>
            <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;">
                <?php echo esc_textarea($arabicResultJsonContent); ?>
            </textarea>
            
            <br>
            <h3>Arabic Lesson Data (arabic_lesson_data.json)</h3>
            <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;">
                <?php echo esc_textarea($arabicLessonJsonContent); ?>
            </textarea>
            
            <br>
            <div class="notice notice-info is-dismissible" style="padding: 10px; margin: 10px 0;">
                <p><strong>Arabic Content Detected</strong></p>
                <p>Your course contains Arabic content. The system will automatically use specialized Arabic processing when adding the course.</p>
            </div>
        <?php endif; ?>
        <br>
        
        <?php 
        // Check if we have course data before showing the Add Course button
        $has_course_data = false;
        $course_data_file = '';
        
        if (file_exists(plugin_dir_path(__FILE__) . '../result.json')) {
            $temp_data = json_decode(file_get_contents(plugin_dir_path(__FILE__) . '../result.json'), true);
            if (is_array($temp_data) && isset($temp_data['course_name'])) {
                $has_course_data = true;
                $course_data_file = 'result.json';
                
                // Check if it contains Arabic text
                if (preg_match('/[\x{0600}-\x{06FF}]/u', $temp_data['course_name'])) {
                    $is_arabic = true;
                    echo '<div class="notice notice-info" style="padding: 10px; margin: 10px 0; border-left-color: #0073aa;">';
                    echo '<p><strong>Ready to add Arabic course: ' . esc_html($temp_data['course_name']) . '</strong></p>';
                    echo '<p>The system will automatically apply special handling for Arabic content.</p>';
                    echo '</div>';
                } else {
                    echo '<div class="notice notice-info" style="padding: 10px; margin: 10px 0;">';
                    echo '<p><strong>Ready to add course: ' . esc_html($temp_data['course_name']) . '</strong></p>';
                    echo '</div>';
                }
            }
        }
        
        if (!$has_course_data) {
            echo '<div class="notice notice-warning" style="padding: 10px; margin: 10px 0;">';
            echo '<p><strong>No course data found!</strong></p>';
            echo '<p>Please scrape a course first before trying to add it.</p>';
            echo '</div>';
        }
        
        wescraper_add_course_page();
        ?>
    </div>
    <?php
}
?>