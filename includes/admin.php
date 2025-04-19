<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'scrape_lessons.php';
require_once plugin_dir_path(__FILE__) . 'functions.php';
require_once plugin_dir_path(__FILE__) . 'new_site_scraper.php';
require_once plugin_dir_path(dirname(__FILE__)) . '/add-course-page.php';

/**
 * Normalize a course URL for consistent duplicate checking
 */
function wescraper_normalize_url($url) {
    $url = trim(strtolower($url));
    // Remove fragment
    $url = preg_replace('/#.*$/', '', $url);
    // Remove trailing slash (unless it's just the root)
    $url = preg_replace('#(?<!:)/+$#', '', $url);
    // Optionally, remove query string (uncomment if needed)
    // $url = preg_replace('/\?.*$/', '', $url);
    return $url;
}

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
        $courses = array_map('wescraper_normalize_url', $courses);
        $normalizedUrl = wescraper_normalize_url($courseUrl);
        if (in_array($normalizedUrl, $courses)) {
            wescraper_log("Duplicate course URL detected: $normalizedUrl");
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
            $normalizedUrl = wescraper_normalize_url($courseUrl);
            file_put_contents($coursesFile, $normalizedUrl . "\n", FILE_APPEND);
            wescraper_log("Added new course URL to scraped_courses.txt: $normalizedUrl");
            
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

    ?>
    <div class="wrap">
        <h2>WeScraper Course Scraper</h2>
        <form id="wescraper-scrape-course-form" method="post">
            <label for="wescraper_course_url">Course URL:</label><br>
            <input type="text" name="wescraper_course_url" id="wescraper_course_url" value="<?php echo esc_attr($currentUrl); ?>" style="width: 500px;"><br><br>
            <p class="description">Supported sites: Mindluster, M3AARF, New Site</p>
            
            <input type="hidden" name="wescraper_scrape_now" value="1">
            <input type="submit" class="button button-primary" value="Scrape Course">
        </form>
        <div id="wescraper-scrape-course-result" style="margin-top:20px;"></div>
        <script>
function clearAllJsonTextareas() {
    jQuery('#wescraper-result-json').val('');
    jQuery('#wescraper-lesson-json').val('');
    jQuery('#wescraper-arabic-result-json').val('');
    jQuery('#wescraper-arabic-lesson-json').val('');
    jQuery('#wescraper-arabic-thumbnail-json').val('');
    jQuery('#wescraper-arabic-thumbnail-id-json').val('');
}
jQuery(document).ready(function($){
        $("#wescraper-scrape-course-form").on("submit", function(e){
        e.preventDefault();
        clearAllJsonTextareas();
                var btn = $(this).find("input[type='submit']");
                btn.prop("disabled",true).val("Processing...");
                var url = $("#wescraper_course_url").val();
                $.post(ajaxurl, { action: "wescraper_scrape_course", wescraper_course_url: url, wescraper_scrape_now: 1 }, function(response){
                    $("#wescraper-scrape-course-result").html(response);
                    btn.prop("disabled",false).val("Scrape Course");
                    
                    // After scrape completes, fetch and update all JSON textareas
                    $.post(ajaxurl, { action: 'wescraper_get_result_json' }, function(jsonResponse) {
                        if (jsonResponse.success && jsonResponse.data) {
                            // Update result.json textarea
                            if (jsonResponse.data.result_json) {
                                $('#wescraper-result-json').val(jsonResponse.data.result_json);
                            }
                            
                            // Update lesson_data.json textarea
                            if (jsonResponse.data.lesson_json) {
                                $('#wescraper-lesson-json').val(jsonResponse.data.lesson_json);
                            }
                            
                            // Update arabic_result.json textarea
                            if (jsonResponse.data.arabic_result_json) {
                                $('#wescraper-arabic-result-json').val(jsonResponse.data.arabic_result_json);
                            }
                            
                            // Update arabic_lesson_data.json textarea
                            if (jsonResponse.data.arabic_lesson_json) {
                                $('#wescraper-arabic-lesson-json').val(jsonResponse.data.arabic_lesson_json);
                            }
                            
                            console.log('Updated all JSON data in textareas after scrape.');
                        } else {
                            console.error('Failed to load JSON after scrape:', jsonResponse);
                        }
                    }).fail(function(jqXHR, textStatus, errorThrown) {
                        console.error('AJAX error when updating textareas:', textStatus, errorThrown);
                    });
                });
            });
        });
        </script>
        <br>
        <h3>Latest Scraped Data (result.json)</h3>
        <textarea id="wescraper-result-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo is_rtl() ? 'ltr' : 'rtl'; ?>;">
        <?php 
        $resultJsonContent = '';
        if (file_exists(plugin_dir_path(__FILE__) . '../result.json')) {
            $resultJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../result.json');
        }
        echo esc_textarea($resultJsonContent); ?>
        </textarea>
        <div id="wescraper-course-notice"></div>
        <br>
        <div id="wescraper-scrape-lessons-ui">
            <button id="wescraper-scrape-lessons-btn" class="button button-secondary">Scrape Lessons</button><br>
            <progress id="wescraper-progress-bar" value="0" max="100" style="width:300px;"></progress> <span id="wescraper-progress-text"></span>
        </div>
        <div id="wescraper-scrape-lessons-result" style="margin-top:20px;"></div>
        <script>
        jQuery(document).ready(function($) {
            var pollingInterval = null;
            var scrapeBtn = $('#wescraper-scrape-lessons-btn');
            var progressBar = $('#wescraper-progress-bar');
            var progressText = $('#wescraper-progress-text');
            var resultTextarea = $('#wescraper-result-json');
            var courseNotice = $('#wescraper-course-notice');

            function updateCourseInfo() {
                // First update with the course info
                $.post(ajaxurl, { action: 'wescraper_get_course_info' }, function(response) {
                    if (response.result_json !== undefined) {
                        resultTextarea.val(response.result_json);
                    }
                    if (response.notice_html !== undefined) {
                        courseNotice.html(response.notice_html);
                    }
                    
                    // Then fetch and update all JSON textareas
                    updateAllJsonTextareas();
                });
            }

            // Function to update all JSON textareas
            function updateAllJsonTextareas() {
                $.post(ajaxurl, { action: 'wescraper_get_result_json' }, function(jsonResponse) {
                    if (jsonResponse.success && jsonResponse.data) {
                        // Update result.json textarea
                        if (jsonResponse.data.result_json) {
                            $('#wescraper-result-json').val(jsonResponse.data.result_json);
                        }
                        
                        // Update lesson_data.json textarea
                        if (jsonResponse.data.lesson_json) {
                            $('#wescraper-lesson-json').val(jsonResponse.data.lesson_json);
                        }
                        
                        // Update arabic_result.json textarea
                        if (jsonResponse.data.arabic_result_json) {
                            $('#wescraper-arabic-result-json').val(jsonResponse.data.arabic_result_json);
                        }
                        
                        // Update arabic_lesson_data.json textarea
                        if (jsonResponse.data.arabic_lesson_json) {
                            $('#wescraper-arabic-lesson-json').val(jsonResponse.data.arabic_lesson_json);
                        }
                        
                        // Update arabic_thumbnail.json textarea
                        if (jsonResponse.data.arabic_thumbnail_json) {
                            $('#wescraper-arabic-thumbnail-json').val(jsonResponse.data.arabic_thumbnail_json);
                        }
                        // Update arabic_thumbnail_id.json textarea
                        if (jsonResponse.data.arabic_thumbnail_id_json) {
                            $('#wescraper-arabic-thumbnail-id-json').val(jsonResponse.data.arabic_thumbnail_id_json);
                        }
                        console.log('Updated all JSON data in textareas.');
                    } else {
                        console.error('Failed to load JSON:', jsonResponse);
                    }
                }).fail(function(jqXHR, textStatus, errorThrown) {
                    console.error('AJAX error when updating textareas:', textStatus, errorThrown);
                });
            }
            
            function pollScrapeStatus() {
                $.post(ajaxurl, { action: 'wescraper_check_scrape_status' }, function(response) {
                    if (response.status === 'running') {
                        var percent = response.progress || 0;
                        progressBar.val(percent);
                        progressText.text('Scraping: ' + percent + '%');
                    } else if (response.status === 'done') {
                        clearInterval(pollingInterval);
                        progressBar.val(100);
                        progressText.text('✅ Scraping complete!');
                        scrapeBtn.prop('disabled', false);
                        // Fetch and display the updated JSON files
                        updateAllJsonTextareas();
                        updateCourseInfo();
                    } else if (response.status === 'error') {
                        clearInterval(pollingInterval);
                        progressText.text('❌ Error: ' + response.message);
                        scrapeBtn.prop('disabled', false);
                        updateCourseInfo();
                    }
                });
            }

            scrapeBtn.on('click', function(e) {
        e.preventDefault();
        clearAllJsonTextareas();
                scrapeBtn.prop('disabled', true);
                progressBar.val(0);
                progressText.text('Starting scrape...');
                $.post(ajaxurl, { action: 'wescraper_start_scrape' }, function(response) {
                    if (response.status === 'started') {
                        pollingInterval = setInterval(pollScrapeStatus, 3000);
                    } else {
                        progressText.text('❌ Failed to start scrape: ' + response.message);
                        scrapeBtn.prop('disabled', false);
                    }
                });
            });
        });
        </script>
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
        <br>
        <h3>Latest Scraped Lessons (lesson_data.json)</h3>
        <textarea id="wescraper-lesson-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo is_rtl() ? 'ltr' : 'rtl'; ?>;">
            <?php 
            $lessonJsonContent = '';
            if (file_exists(plugin_dir_path(__FILE__) . '../lesson_data.json')) {
                $lessonJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../lesson_data.json');
            }
            echo esc_textarea($lessonJsonContent); ?>
        </textarea>
        
        <?php if (file_exists(plugin_dir_path(__FILE__) . '../arabic_result.json')): ?>
            <br>
            <h3>Arabic Course Data (arabic_result.json)</h3>
            <textarea id="wescraper-arabic-result-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;">
                <?php 
                $arabicResultJsonContent = '';
                if (file_exists(plugin_dir_path(__FILE__) . '../arabic_result.json')) {
                    $arabicResultJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../arabic_result.json');
                }
                echo esc_textarea($arabicResultJsonContent); ?>
            </textarea>
            
            <br>
            <h3>Arabic Lesson Data (arabic_lesson_data.json)</h3>
            <textarea id="wescraper-arabic-lesson-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;">
                <?php 
                $arabicLessonJsonContent = '';
                if (file_exists(plugin_dir_path(__FILE__) . '../arabic_lesson_data.json')) {
                    $arabicLessonJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../arabic_lesson_data.json');
                }
                echo esc_textarea($arabicLessonJsonContent); ?>
            </textarea>
            
            <br>
            <div class="notice notice-info is-dismissible" style="padding: 10px; margin: 10px 0;">
                <p><strong>Arabic Content Detected</strong></p>
                <p>Your course contains Arabic content. The system will automatically use specialized Arabic processing when adding the course.</p>
            </div>
            <br>
            <h3>Arabic Thumbnail Data (arabic_thumbnail.json)</h3>
            <textarea id="wescraper-arabic-thumbnail-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;"></textarea>
            <br>
            <h3>Arabic Thumbnail ID Data (arabic_thumbnail_id.json)</h3>
            <textarea id="wescraper-arabic-thumbnail-id-json" rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: rtl;"></textarea>
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

// Register AJAX handler for scrape course
add_action('wp_ajax_wescraper_scrape_course','wescraper_handle_scrape_course_ajax');

// AJAX handler to return all JSON file contents
add_action('wp_ajax_wescraper_get_result_json', function() {
    $plugin_dir = plugin_dir_path(dirname(__FILE__));
    $response = [];
    
    // Get result.json
    $result_json_file = $plugin_dir . 'result.json';
    if (file_exists($result_json_file)) {
        $response['result_json'] = file_get_contents($result_json_file);
    }
    
    // Get lesson_data.json
    $lesson_json_file = $plugin_dir . 'lesson_data.json';
    if (file_exists($lesson_json_file)) {
        $response['lesson_json'] = file_get_contents($lesson_json_file);
    }
    
    // Get arabic_result.json
    $arabic_result_json_file = $plugin_dir . 'arabic_result.json';
    if (file_exists($arabic_result_json_file)) {
        $response['arabic_result_json'] = file_get_contents($arabic_result_json_file);
    }
    
    // Get arabic_lesson_data.json
    $arabic_lesson_json_file = $plugin_dir . 'arabic_lesson_data.json';
    if (file_exists($arabic_lesson_json_file)) {
        $response['arabic_lesson_json'] = file_get_contents($arabic_lesson_json_file);
    }

    // Get arabic_thumbnail.json
    $arabic_thumbnail_json_file = $plugin_dir . 'arabic_thumbnail.json';
    if (file_exists($arabic_thumbnail_json_file)) {
        $response['arabic_thumbnail_json'] = file_get_contents($arabic_thumbnail_json_file);
    }

    // Get arabic_thumbnail_id.json
    $arabic_thumbnail_id_json_file = $plugin_dir . 'arabic_thumbnail_id.json';
    if (file_exists($arabic_thumbnail_id_json_file)) {
        $response['arabic_thumbnail_id_json'] = file_get_contents($arabic_thumbnail_id_json_file);
    }
    
    if (!empty($response)) {
        wp_send_json_success($response);
    } else {
        wp_send_json_error(['message' => 'No JSON files found']);
    }
});
function wescraper_handle_scrape_course_ajax(){
    $url = isset($_POST['wescraper_course_url']) ? sanitize_text_field($_POST['wescraper_course_url']) : '';
    update_option('wescraper_course_url', $url);
    $status = wescraper_scrape_course();
    if ($status === "duplicate") {
        echo '<div class="notice notice-warning is-dismissible"><p>Course URL already scraped!</p></div>';
    } elseif ($status === "success") {
        echo '<div class="notice notice-success is-dismissible"><p>✅ Course data saved to result.json!</p><p>All previous course data was cleaned to prevent mixing of lessons.</p></div>';
    } elseif ($status === "failed_save") {
        echo "<p>❌ Failed to save course data to result.json.</p>";
    } elseif ($status === "failed_scrape") {
        echo "<p>❌ Failed to fetch course data.</p>";
    }
    wp_die();
}

// Enqueue admin JS for async scraping UI
add_action('admin_enqueue_scripts', function() {
    wp_enqueue_script('wescraper-admin-js', plugin_dir_url(__FILE__) . '../wescraper-admin.js', ['jquery'], null, true);
});

// AJAX handler to start scraping (async)
add_action('wp_ajax_wescraper_start_scrape', function() {
    update_option('wescraper_scrape_status', [
        'status' => 'running',
        'progress' => 0,
        'message' => 'Started',
        'started_at' => time(),
    ]);
    // Schedule WP Cron event for background scraping
    if (!wp_next_scheduled('wescraper_do_background_scrape')) {
        wp_schedule_single_event(time() + 2, 'wescraper_do_background_scrape');
    }
    wp_send_json(['status' => 'started']);
});

// AJAX handler to check scrape status
add_action('wp_ajax_wescraper_check_scrape_status', function() {
    $status = get_option('wescraper_scrape_status', ['status'=>'idle','progress'=>0]);
    wp_send_json($status);
});


?>
