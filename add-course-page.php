<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/tutor-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/category-name.php';
require_once plugin_dir_path(__FILE__) . 'includes/log.php';
require_once plugin_dir_path(__FILE__) . 'includes/scrape_lessons.php';

/**
 * Extract YouTube video ID from m3aarf lesson page
 * @param string $url The m3aarf lesson URL
 * @return string YouTube video ID or empty string if not found
 */
function wescraper_extract_youtube_id($url) {
    if (empty($url)) {
        return '';
    }
    
    // If the URL already contains a YouTube video ID, extract it
    if (preg_match('/youtube\.com\/watch\?v=([^&]+)/', $url, $matches) || 
        preg_match('/youtu\.be\/([^&?\/]+)/', $url, $matches) ||
        preg_match('/youtube\.com\/embed\/([^"&?\/\s]+)/', $url, $matches)) {
        return $matches[1];
    }
    
    // Otherwise try to load the page and extract the YouTube ID
    wescraper_log("Attempting to extract YouTube ID from: " . $url);
    
    $response = wp_remote_get($url, [
        'timeout' => 30,
        'headers' => [
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ]
    ]);
    
    if (is_wp_error($response)) {
        wescraper_log("Error fetching lesson page: " . $response->get_error_message());
        return '';
    }
    
    if (wp_remote_retrieve_response_code($response) !== 200) {
        wescraper_log("Non-200 response code: " . wp_remote_retrieve_response_code($response));
        return '';
    }
    
    $body = wp_remote_retrieve_body($response);
    
    // Extract YouTube ID from iframe src
    if (preg_match('/youtube\.com\/embed\/([^"&?\/\s]+)/', $body, $matches)) {
        wescraper_log("Found YouTube embed URL: youtube.com/embed/" . $matches[1]);
        return $matches[1];
    }
    
    // Try alternative patterns
    if (preg_match('/youtu\.be\/([^"&?\/\s]+)/', $body, $matches)) {
        wescraper_log("Found YouTube short URL: youtu.be/" . $matches[1]);
        return $matches[1];
    }
    
    if (preg_match('/data-video-id=[\'"]([^"\']+)[\'"]/', $body, $matches)) {
        wescraper_log("Found video ID in data attribute: " . $matches[1]);
        return $matches[1];
    }
    
    wescraper_log("Could not extract YouTube ID from page");
    return '';
}

function wescraper_update_course_thumbnail() {
    try {
        wescraper_log("Starting wescraper_update_course_thumbnail");
        
        // Get API credentials for API approach
        $api_key = get_option('wescraper_api_key', '');
        $api_secret = get_option('wescraper_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wescraper_log("WARNING: Missing API credentials for API fallback method");
        } else {
            $api_client = new TutorAPIClient($api_key, $api_secret);
            wescraper_log("TutorAPIClient initialized for thumbnail update");
        }
        
        // Get the last created course ID
        $course_id_file = plugin_dir_path(__FILE__) . 'current_course_id.json';
        if (!file_exists($course_id_file)) {
            throw new Exception("No course ID found. Please create a course first.");
        }
        
        $course_data = json_decode(file_get_contents($course_id_file), true);
        if (!isset($course_data['course_id'])) {
            throw new Exception("Invalid course ID data. Please create a course first.");
        }
        
        $course_id = $course_data['course_id'];
        wescraper_log("Found course ID: " . $course_id);
        
        // Find the thumbnail ID from multiple possible sources
        $thumbnail_id = null;
        
        // First check result.json (primary source)
        $result_json_file = plugin_dir_path(__FILE__) . 'result.json';
        if (file_exists($result_json_file)) {
            $result_data = json_decode(file_get_contents($result_json_file), true);
            if (isset($result_data['thumbnail_id']) && !empty($result_data['thumbnail_id'])) {
                $thumbnail_id = $result_data['thumbnail_id'];
                wescraper_log("Found thumbnail ID in result.json: " . $thumbnail_id);
            }
        }
        
        // If not found in result.json, check arabic_thumbnail_id.json
        if (empty($thumbnail_id)) {
            $thumbnail_id_file = plugin_dir_path(__FILE__) . 'arabic_thumbnail_id.json';
            if (file_exists($thumbnail_id_file)) {
                $thumbnail_data = json_decode(file_get_contents($thumbnail_id_file), true);
                if (isset($thumbnail_data['thumbnail_id']) && !empty($thumbnail_data['thumbnail_id'])) {
                    $thumbnail_id = $thumbnail_data['thumbnail_id'];
                    wescraper_log("Found thumbnail ID in arabic_thumbnail_id.json: " . $thumbnail_id);
                }
            }
        }
        
        if (empty($thumbnail_id)) {
            throw new Exception("No thumbnail ID found in any data file. Please upload a thumbnail first.");
        }
        
        wescraper_log("Found thumbnail ID: " . $thumbnail_id);
        
        // Try multiple approaches to set the thumbnail
        
        // Approach 1: Use WordPress function
        $success = false;
        $result = set_post_thumbnail($course_id, intval($thumbnail_id));
        if ($result) {
            wescraper_log("Successfully set thumbnail using WordPress function");
            $success = true;
        } else {
            wescraper_log("Failed to set thumbnail using WordPress function, trying direct database update");
            
            // Approach 2: Try direct database update
            global $wpdb;
            $meta_exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'",
                $course_id
            ));
            
            if ($meta_exists) {
                // Update existing meta
                $result = $wpdb->update(
                    $wpdb->postmeta,
                    array('meta_value' => $thumbnail_id),
                    array('post_id' => $course_id, 'meta_key' => '_thumbnail_id')
                );
                if ($result !== false) {
                    wescraper_log("Successfully updated thumbnail using direct database update");
                    $success = true;
                }
            } else {
                // Insert new meta
                $result = $wpdb->insert(
                    $wpdb->postmeta,
                    array(
                        'post_id' => $course_id,
                        'meta_key' => '_thumbnail_id',
                        'meta_value' => $thumbnail_id
                    )
                );
                if ($result !== false) {
                    wescraper_log("Successfully inserted thumbnail using direct database insert");
                    $success = true;
                }
            }
            
            // Clear cache if database update succeeded
            if ($success) {
                clean_post_cache($course_id);
            } else {
                wescraper_log("Failed direct database update, trying API approach");
            }
        }
        
        // Approach 3: Use API if direct methods failed and API client is available
        if (!$success && !empty($api_key) && !empty($api_secret)) {
            // Try to update the course with the thumbnail_id via API
            $update_data = [
                "ID" => (string)$course_id,
                "thumbnail_id" => (string)$thumbnail_id,
                "force_update" => true
            ];
            
            $update_response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_COURSES . '/' . $course_id,
                'PATCH',
                $update_data
            );
            
            if (isset($update_response['data'])) {
                wescraper_log("Successfully updated course thumbnail via API");
                $success = true;
            } else {
                wescraper_log("Failed to update course thumbnail via API");
            }
        }
        
        if ($success) {
            wescraper_log("Successfully updated thumbnail for course ID " . $course_id . " with thumbnail ID " . $thumbnail_id);
            echo "<div style='color:green; padding:15px; background:#eeffee; border:1px solid #aaffaa; margin:15px 0; border-radius:4px;'>";
            echo "<h3 style='margin-top:0;'>✅ Thumbnail Updated</h3>";
            echo "<p>Successfully updated the thumbnail for course <strong>" . htmlspecialchars($course_data['course_name']) . "</strong> (ID: " . $course_id . ") with thumbnail ID " . $thumbnail_id . ".</p>";
            echo "<p><a href='post.php?post=" . $course_id . "&action=edit' class='button button-primary' target='_blank'>View Course</a></p>";
            echo "</div>";
        } else {
            throw new Exception("Failed to update thumbnail after trying multiple approaches. There might be a permission issue.");
        }
        
    } catch (Exception $e) {
        echo "<div style='color:white; padding:15px; background:#d63638; border:1px solid #d63638; margin:15px 0; border-radius:4px;'>";
        echo "<h3 style='margin-top:0; color:white;'>❌ Error Updating Thumbnail</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "</div>";
        wescraper_log("❌ Exception in update_course_thumbnail: " . $e->getMessage());
    }
}

function wescraper_add_course_page() {
    ?>
    <div class="wrap">
        <h1>WeScraper - Add Course</h1>
        
        <div style="background-color: #fff; padding: 20px; border-radius: 5px; margin-bottom: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
            <h2>Instructions</h2>
            <ol>
                <li>First, scrape a course using the Scrape Course tool in the WeScraper menu</li>
                <li>Ensure your API credentials are set in the WeScraper Settings</li>
                <li>Click the "Add Course" button below to create the course from the scraped data</li>
                <li>If the thumbnail doesn't appear, use the "Update Thumbnail" button after course creation</li>
            </ol>
            
            <div style="background-color: #f8f8f8; padding: 15px; border-left: 4px solid #46b450; margin-top: 15px;">
                <p><strong>Note:</strong> The course will be created using data from the <code>result.json</code> file generated during scraping.</p>
            </div>
        </div>
        
        <div style="display: flex; gap: 20px;">
            <div style="flex: 3; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2>Create Course</h2>
                
                <!--
                <p>For best results, upload the thumbnail first:</p>
                
        <form method="post">
                    <input type="submit" name="upload_thumbnail" value=Upload Thumbnail" class="button button-secondary" style="width: 100%; text-align: center; margin-bottom: 15px;" />
                </form>
                -->

                <p>Create the course:</p>
                
                <form method="post">
                    <input type="submit" name="add_course" value="Add Course" class="button button-primary button-hero" style="background: #46b450; border-color: #46b450; display: block; width: 100%; text-align: center; margin-bottom: 15px;" />
                    <p style="color: #777; font-style: italic;">This will use our ultra-simplified approach that successfully creates courses with the API.</p>
                </form>
                
                <h3 style="margin-top: 20px;">Thumbnail Management</h3>
                <p>If the thumbnail doesn't appear after course creation, use this button:</p>
                <form method="post">
                    <input type="submit" name="update_thumbnail" value="Update Thumbnail for Last Course" class="button button-secondary" style="width: 100%; text-align: center; margin-bottom: 15px;" />
        </form>
            </div>
            
            <div style="flex: 2; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">
                <h2>Testing Tools</h2>
                <p>These buttons are for testing purposes only:</p>
                
                <form method="post" style="display: flex; flex-direction: column; gap: 10px;">
                    <input type="submit" name="test_add_course" value="Test Add Course (English)" class="button button-secondary" />
                    <input type="submit" name="test_add_arabic_course" value="Test Add Course (Arabic)" class="button button-secondary" />
                    <input type="submit" name="test_with_actual_json" value="Test With Actual JSON" class="button" style="background: #FF9900; color: white; border-color: #FF9900;" />
                </form>
            </div>
        </div>
    </div>
    <?php
    if (isset($_POST['add_course'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Course Creation Results</h2>';
        wescraper_process_add_course();
        echo '</div>';
    } else if (isset($_POST['upload_thumbnail'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Thumbnail Upload Results</h2>';
        
        // Check if upload_thumbnail.php is included
        if (!function_exists('wescraper_upload_thumbnail')) {
            require_once plugin_dir_path(__FILE__) . 'includes/upload_thumbnail.php';
        }
        
        // First check if we're dealing with Arabic content
        $is_arabic = false;
        $resultJsonPath = plugin_dir_path(__FILE__) . 'result.json';
        if (file_exists($resultJsonPath)) {
            $json_content = file_get_contents($resultJsonPath);
            $json_data = json_decode($json_content, true);
            if ($json_data && isset($json_data['course_name'])) {
                $is_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $json_data['course_name']);
            }
        }
        
        // Call the upload function with the correct site type
        wescraper_upload_thumbnail($is_arabic ? 'm3aarf' : 'mindluster');
        
        echo '</div>';
    } else if (isset($_POST['update_thumbnail'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Thumbnail Update Results</h2>';
        wescraper_update_course_thumbnail();
        echo '</div>';
    } else if (isset($_POST['test_add_course'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Test Results (English)</h2>';
        wescraper_test_add_course();
        echo '</div>';
    } else if (isset($_POST['test_add_arabic_course'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Test Results (Arabic)</h2>';
        wescraper_test_add_arabic_course();
        echo '</div>';
    } else if (isset($_POST['test_with_actual_json'])) {
        echo '<div style="margin-top: 20px; background-color: #fff; padding: 20px; border-radius: 5px; box-shadow: 0 1px 3px rgba(0,0,0,0.1);">';
        echo '<h2>Test Results (Actual JSON)</h2>';
        wescraper_test_with_actual_json();
        echo '</div>';
    }
}

function wescraper_process_add_course() {
    try {
        wescraper_log("Starting wescraper_process_add_course");

        // Get API credentials
        $api_key = get_option('wescraper_api_key', '');
        $api_secret = get_option('wescraper_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wescraper_log("ERROR: Missing API credentials. Please set them in the plugin settings");
            throw new Exception("Missing API credentials. Please set your Tutor LMS API key and secret in the plugin settings.");
        }
        
        // Check for thumbnail ID from various sources
        $thumbnail_id_file = plugin_dir_path(__FILE__) . 'arabic_thumbnail_id.json';
        $result_json_file = plugin_dir_path(__FILE__) . 'result.json';
        $has_thumbnail = false;
        $thumbnail_id = "1"; // Default thumbnail ID
        
        // First check if thumbnail_id is in result.json
        if (file_exists($result_json_file)) {
            $result_data = json_decode(file_get_contents($result_json_file), true);
            if (isset($result_data['thumbnail_id']) && !empty($result_data['thumbnail_id'])) {
                $has_thumbnail = true;
                $thumbnail_id = (string)$result_data['thumbnail_id'];
                wescraper_log("Found thumbnail ID in result.json: " . $thumbnail_id);
            }
        }
        
        // If not found, check arabic_thumbnail_id.json as fallback
        if (!$has_thumbnail && file_exists($thumbnail_id_file)) {
            $thumbnail_data = json_decode(file_get_contents($thumbnail_id_file), true);
            if (isset($thumbnail_data['thumbnail_id']) && !empty($thumbnail_data['thumbnail_id'])) {
                $has_thumbnail = true;
                $thumbnail_id = (string)$thumbnail_data['thumbnail_id'];
                wescraper_log("Found thumbnail ID in arabic_thumbnail_id.json: " . $thumbnail_id);
                
                // Copy the thumbnail ID to result.json for consistency
                if (file_exists($result_json_file)) {
                    $result_data = json_decode(file_get_contents($result_json_file), true);
                    $result_data['thumbnail_id'] = $thumbnail_id;
                    file_put_contents($result_json_file, 
                        json_encode($result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                    wescraper_log("Copied thumbnail ID to result.json for consistency");
                }
            }
        }
        
        // Also check for video_id in arabic_thumbnail.json for intro video
        $intro_video_id = '';
        $thumbnail_file = plugin_dir_path(__FILE__) . 'arabic_thumbnail.json';
        if (file_exists($thumbnail_file)) {
            $thumbnail_info = json_decode(file_get_contents($thumbnail_file), true);
            if (isset($thumbnail_info['video_id']) && !empty($thumbnail_info['video_id'])) {
                $intro_video_id = $thumbnail_info['video_id'];
                wescraper_log("Found intro video ID: " . $intro_video_id);
                
                // Update the JSON data with this video ID if not already set
                if (!isset($json_data['intro_video']) || empty($json_data['intro_video'])) {
                    $json_data['intro_video'] = 'https://www.youtube.com/watch?v=' . $intro_video_id;
                    wescraper_log("Added intro_video to JSON data: " . $json_data['intro_video']);
                }
            }
        }
        
        if (!$has_thumbnail) {
            echo "<div style='color:#856404; padding:15px; background:#fff3cd; border:1px solid #ffeeba; margin:15px 0; border-radius:4px;'>";
            echo "<h3 style='margin-top:0;'>⚠️ Thumbnail Warning</h3>";
            echo "<p>No course thumbnail ID was found. The course will be created with a default thumbnail.</p>";
            echo "<p>To add a custom thumbnail, run the 'Upload Thumbnail' command in the WeScraper menu first.</p>";
            echo "</div>";
            wescraper_log("Warning: No thumbnail ID found. Using default ID.");
        }
        
        wescraper_log("Using API credentials - Key: " . substr($api_key, 0, 5) . '...' . substr($api_key, -5) . ", Secret: " . substr($api_secret, 0, 3) . '...');
        
        // Initialize API client with credentials
        $api_client = new TutorAPIClient($api_key, $api_secret);
        wescraper_log("TutorAPIClient initialized");

        // Read the actual JSON file
        $resultJsonPath = plugin_dir_path(__FILE__) . 'result.json';
        wescraper_log("Reading from actual JSON file: " . $resultJsonPath);
        
        if (!file_exists($resultJsonPath)) {
            wescraper_log("Error: JSON file not found: " . $resultJsonPath);
            throw new Exception("Course data JSON file not found! Please scrape a course first.");
        }
        
        // Read and parse the JSON file
        $json_content = file_get_contents($resultJsonPath);
        if (empty($json_content)) {
            wescraper_log("Error: Empty JSON file: " . $resultJsonPath);
            throw new Exception("The JSON file is empty. Please scrape a course first.");
        }
        
        // Ensure proper UTF-8 encoding
        $json_content = mb_convert_encoding($json_content, 'UTF-8', 'UTF-8');
        
        $json_data = json_decode($json_content, true, 512, JSON_UNESCAPED_UNICODE);
        if ($json_data === null) {
            wescraper_log("Error parsing JSON: " . json_last_error_msg());
            throw new Exception("Error parsing JSON: " . json_last_error_msg());
        }
        
        wescraper_log("JSON data parsed successfully");
        
        // Check if content is Arabic
        $is_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $json_data['course_name']);
        if ($is_arabic) {
            wescraper_log("Detected Arabic content in course name: " . $json_data['course_name']);
        }
        
        // ========== ULTRA SIMPLIFIED APPROACH ==========
        // Use the same approach that worked in the test function
        
        // Prepare a clean course title
        $course_title = isset($json_data['course_name']) ? trim($json_data['course_name']) : "Untitled Course";
        
        // Get content
        $course_content = isset($json_data['description']) ? trim($json_data['description']) : "Course content";

        // Get course benefits from lessons
        $benefits = "";
        if (isset($json_data['lessons']) && is_array($json_data['lessons'])) {
            foreach ($json_data['lessons'] as $index => $lesson) {
                if (isset($lesson['title'])) {
                    $benefits .= "Learn " . html_entity_decode($lesson['title'], ENT_QUOTES | ENT_HTML5, 'UTF-8') . "\n";
                }
            }
        }
        if (empty($benefits)) {
            $benefits = "Learn course content\nGain valuable skills\nPractice techniques";
        }
        
        // Get category if possible - use strings for all values
        $category_id = "1"; // Default category ID
        $category_name = isset($json_data['category']) ? $json_data['category'] : "Uncategorized";
        
        try {
            $category_manager = new CategoryManager();
            $category_id_result = $category_manager->get_category_id($category_name);
            if ($category_id_result) {
                $category_id = (string)$category_id_result;
                wescraper_log("Using category ID: " . $category_id);
            }
        } catch (Exception $e) {
            wescraper_log("Warning: Could not get category ID: " . $e->getMessage() . " - Using default category.");
        }
        
        // Calculate total duration - use strings
        $duration_hours = "1";
        $duration_minutes = "0";
        
        if (isset($json_data['lessons']) && is_array($json_data['lessons'])) {
            $total_minutes = 0;
            foreach ($json_data['lessons'] as $lesson) {
                if (isset($lesson['duration']) && preg_match('/(\d+):(\d+):(\d+)/', $lesson['duration'], $matches)) {
                    $hours = (int)$matches[1];
                    $minutes = (int)$matches[2];
                    $seconds = (int)$matches[3];
                    
                    $total_minutes += ($hours * 60) + $minutes + ($seconds / 60);
                }
            }
            $duration_hours = (string)floor($total_minutes / 60);
            $duration_minutes = (string)round($total_minutes % 60);
        }
        
        // Build the ultra simplified course data structure
        $course_data = [
            "post_title" => $course_title,
            "post_content" => $course_content,
            "post_excerpt" => substr($course_content, 0, 155) . "...",
            "post_status" => "publish",
            "comment_status" => "open",
            "additional_content" => [
                "course_benefits" => $benefits,
                "course_target_audience" => "Students interested in " . $category_name,
                "course_duration" => [
                    "hours" => $duration_hours,
                    "minutes" => $duration_minutes
                ],
                "course_material_includes" => "Video lessons\nPractical examples\nLifetime access",
                "course_requirements" => "Basic knowledge of the subject"
            ],
            "course_level" => "beginner",
            "course_categories" => [$category_id],
            "thumbnail_id" => $thumbnail_id // Use the extracted thumbnail ID
        ];
        
        // For WordPress installations where user ID 1 doesn't work, try without specifying post_author
        
        wescraper_log("Ultra simplified course data prepared: " . print_r($course_data, true));
        echo "Creating course from JSON data...<br>";
        
        // Make the API request
        $response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_COURSES,
            'POST',
            $course_data
        );
        
        wescraper_log("API response received: " . print_r($response, true));

        if (!isset($response['data'])) {
            // Try with post_author = "1" if first attempt failed
            wescraper_log("First attempt failed. Trying with post_author = 1");
            $course_data["post_author"] = "1";
            
        $response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_COURSES,
            'POST',
            $course_data
        );

        if (!isset($response['data'])) {
                // Try with post_author = "2" if second attempt failed
                wescraper_log("Second attempt failed. Trying with post_author = 2");
                $course_data["post_author"] = "2";
                
                $response = $api_client->makeRequest(
                    TutorAPIConfig::ENDPOINT_COURSES,
                    'POST',
                    $course_data
                );
                
                if (!isset($response['data'])) {
                    // Handle error
                    $error_message = "Course creation failed after all attempts. ";
                    if (isset($response['message'])) {
                        $error_message .= "Message: " . $response['message'] . ". ";
                    }
                    if (isset($response['data']['details'])) {
                        $error_message .= "Details: " . print_r($response['data']['details'], true);
                    }
                    
                    wescraper_log("API Error: " . $error_message);
                    echo "<div style='color:red; padding:10px; background:#ffeeee; border:1px solid #ffaaaa; margin:10px 0;'>";
                    echo "<strong>API Error:</strong><br>";
                    echo "The course could not be created after multiple attempts. Error message:<br><br>";
                    echo $error_message;
                    echo "</div>";
                    
                    throw new Exception("API request failed: " . $error_message);
                }
            }
        }

        $course_id = $response['data'];
        wescraper_log("Course created successfully with ID: " . $course_id);
        echo "<div style='color:green; padding:15px; background:#eeffee; border:1px solid #aaffaa; margin:15px 0; border-radius:4px;'>";
        echo "<h3 style='margin-top:0;'>Course Created Successfully! 🎉</h3>";
        echo "<p><strong>Course ID:</strong> " . $course_id . "</p>";
        echo "<p><strong>Course Title:</strong> " . htmlspecialchars($course_title) . "</p>";
        echo "<p><strong>Category:</strong> " . htmlspecialchars($category_name) . "</p>";
        echo "<p><strong>Total Duration:</strong> " . $duration_hours . "h " . $duration_minutes . "m</p>";
        echo "<p><a href='edit.php?post_type=courses' class='button button-primary' style='margin-right:10px;' target='_blank'>View All Courses</a>";
        echo "<a href='post.php?post=" . $course_id . "&action=edit' class='button' target='_blank'>Edit This Course</a></p>";
        echo "</div>";
        
        // Save current course ID for reference
        $courseData = [
            'course_id' => $course_id,
            'course_name' => $json_data['course_name'],
            'created_at' => date('Y-m-d H:i:s'),
            'lesson_count' => count($json_data['lessons'])
        ];

        file_put_contents(
            plugin_dir_path(__FILE__) . "current_course_id.json",
            json_encode($courseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        
        wescraper_log("Current course ID saved to current_course_id.json");
        
        // Set the thumbnail immediately using WordPress function
        if ($has_thumbnail) {
            wescraper_log("Attempting to set course thumbnail using multiple approaches");
            $thumbnail_success = false;
            
            // Approach 1: Use WordPress function
            $thumbnail_result = set_post_thumbnail($course_id, intval($thumbnail_id));
            if ($thumbnail_result) {
                wescraper_log("Successfully set course thumbnail using WordPress function");
                $thumbnail_success = true;
            } else {
                wescraper_log("Failed to set course thumbnail using WordPress function, trying direct database update");
                
                // Approach 2: Try direct database update
                global $wpdb;
                $meta_exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM $wpdb->postmeta WHERE post_id = %d AND meta_key = '_thumbnail_id'",
                    $course_id
                ));
                
                if ($meta_exists) {
                    // Update existing meta
                    $result = $wpdb->update(
                        $wpdb->postmeta,
                        array('meta_value' => $thumbnail_id),
                        array('post_id' => $course_id, 'meta_key' => '_thumbnail_id')
                    );
                    if ($result !== false) {
                        wescraper_log("Successfully updated thumbnail using direct database update");
                        $thumbnail_success = true;
                    }
                } else {
                    // Insert new meta
                    $result = $wpdb->insert(
                        $wpdb->postmeta,
                        array(
                            'post_id' => $course_id,
                            'meta_key' => '_thumbnail_id',
                            'meta_value' => $thumbnail_id
                        )
                    );
                    if ($result !== false) {
                        wescraper_log("Successfully inserted thumbnail using direct database insert");
                        $thumbnail_success = true;
                    }
                }
                
                // Clear cache if database update succeeded
                if ($thumbnail_success) {
                    clean_post_cache($course_id);
                } else {
                    wescraper_log("Failed direct database update, trying API approach");
                }
            }
            
            // Approach 3: Use API if direct methods failed
            if (!$thumbnail_success) {
                wescraper_log("Trying to update course thumbnail via API");
                // Try to update the course with the thumbnail_id via API
                $update_data = [
                    "ID" => (string)$course_id,
                    "thumbnail_id" => (string)$thumbnail_id,
                    "force_update" => true
                ];
                
                $update_response = $api_client->makeRequest(
                    TutorAPIConfig::ENDPOINT_COURSES . '/' . $course_id,
                    'PATCH',
                    $update_data
                );
                
                if (isset($update_response['data'])) {
                    wescraper_log("Successfully updated course thumbnail via API");
                    $thumbnail_success = true;
                } else {
                    wescraper_log("Warning: Failed to update course thumbnail via all methods. Use the Update Thumbnail button after course creation.");
                    echo "<div style='color:#856404; padding:15px; background:#fff3cd; border:1px solid #ffeeba; margin:15px 0; border-radius:4px;'>";
                    echo "<h3 style='margin-top:0;'>⚠️ Thumbnail Warning</h3>";
                    echo "<p>The course was created successfully, but the thumbnail couldn't be set automatically.</p>";
                    echo "<p>Please use the \"Update Thumbnail\" button below to manually set the thumbnail.</p>";
                    echo "</div>";
                }
            }
        }
        
        // Create a topic with ultra simplified data
        $topic_title = $json_data['course_name'] . " - Lessons";
        if ($is_arabic) {
            $topic_title .= " - Course Topic";
        }
        
        $topic_data = [
            "topic_course_id" => (string)$course_id,
            "topic_title" => $topic_title,
            "topic_summary" => "All lessons for this course",
            "topic_author" => "1" // Use string for consistency
        ];
        
        wescraper_log("Topic data prepared: " . json_encode($topic_data));

        $topic_response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_TOPICS,
            'POST',
            $topic_data
        );

        if (!isset($topic_response['data'])) {
            wescraper_log("Error creating topic: " . print_r($topic_response, true));
            throw new Exception("Error creating topic: " . print_r($topic_response, true));
        }

        $topic_id = $topic_response['data'];
        wescraper_log("Topic created with ID: " . $topic_id);

        // Create lessons from the JSON data
        $lessons_created = 0;
        
        // Load lesson data from the appropriate json file
        $lesson_json_data = [];
        
        // Choose the correct lesson data file
        if ($is_arabic) {
            $lesson_data_path = plugin_dir_path(__FILE__) . 'arabic_lesson_data.json';
            wescraper_log("Loading Arabic lesson data from " . $lesson_data_path);
        } else {
            $lesson_data_path = plugin_dir_path(__FILE__) . 'lesson_data.json';
            wescraper_log("Loading standard lesson data from " . $lesson_data_path);
        }
        
        // Log the absolute file path for debugging
        wescraper_log("Absolute file path: " . realpath($lesson_data_path) ?: "File does not exist at path: " . $lesson_data_path);
        
        if (file_exists($lesson_data_path)) {
            $lesson_content = file_get_contents($lesson_data_path);
            $lesson_json_data = json_decode($lesson_content, true);
            if ($lesson_json_data === null || !is_array($lesson_json_data)) {
                wescraper_log("Warning: Could not parse lesson data from " . $lesson_data_path . ". JSON Error: " . json_last_error_msg());
                $lesson_json_data = [];
            } else {
                wescraper_log("Successfully loaded " . count($lesson_json_data) . " lessons from " . $lesson_data_path);
                // Debug first lesson content
                if (count($lesson_json_data) > 0 && isset($lesson_json_data[0]['content'])) {
                    $first_content = $lesson_json_data[0]['content'];
                    $content_preview = mb_substr($first_content, 0, 50) . "...";
                    wescraper_log("First lesson content preview: " . $content_preview);
                }
            }
        } else {
            wescraper_log("Warning: Lesson data file not found at " . $lesson_data_path);
        }
        
        // Ensure we have the right number of lessons in lesson_json_data
        if (count($lesson_json_data) < count($json_data['lessons'])) {
            wescraper_log("Warning: Loaded fewer lessons (" . count($lesson_json_data) . ") than expected (" . count($json_data['lessons']) . ")");
            
            // Resize the array to match the number of lessons
            $original_count = count($lesson_json_data);
            for ($i = $original_count; $i < count($json_data['lessons']); $i++) {
                $lesson_json_data[$i] = [
                    'title' => isset($json_data['lessons'][$i]['title']) ? $json_data['lessons'][$i]['title'] : "Lesson " . ($i + 1),
                    'link' => isset($json_data['lessons'][$i]['link']) ? $json_data['lessons'][$i]['link'] : "",
                    'duration' => isset($json_data['lessons'][$i]['duration']) ? $json_data['lessons'][$i]['duration'] : "00:00:00",
                    'video_id' => "",
                    'video_url' => "",
                    'content' => "Content for Lesson " . ($i + 1)
                ];
            }
            wescraper_log("Expanded lesson_json_data array to " . count($lesson_json_data) . " entries");
        }
        
        foreach ($json_data['lessons'] as $index => $lesson) {
            // Extract lesson data
            $lesson_title = isset($lesson['title']) ? trim($lesson['title']) : "Lesson " . ($index + 1);
            
            // For Arabic content, add English suffix
            if ($is_arabic) {
                $lesson_title .= " - Lesson " . ($index + 1);
            }
            
            // Parse duration
            $duration_parts = ['0', '0', '0'];
            if (isset($lesson['duration']) && preg_match('/(\d+):(\d+):(\d+)/', $lesson['duration'], $matches)) {
                $duration_parts = [$matches[1], $matches[2], $matches[3]];
            }
            
            // Get YouTube video URL from various sources
            $video_link = "";
            $youtube_id = "";
            
            // First check if we have video_url in the lesson_json_data
            if (isset($lesson_json_data[$index]) && isset($lesson_json_data[$index]['video_url']) && !empty($lesson_json_data[$index]['video_url'])) {
                $extracted_id = wescraper_extract_youtube_id($lesson_json_data[$index]['video_url']);
                if (!empty($extracted_id)) {
                    $youtube_id = $extracted_id;
                    $video_link = 'https://www.youtube.com/watch?v=' . $youtube_id;
                    wescraper_log("Using YouTube ID from lesson_json_data: " . $youtube_id . " for lesson " . ($index + 1));
                }
            }
            
            // If still empty, try to extract from the lesson link
            if (empty($youtube_id) && isset($lesson['link']) && !empty($lesson['link'])) {
                $extracted_id = wescraper_extract_youtube_id($lesson['link']);
                if (!empty($extracted_id)) {
                    $youtube_id = $extracted_id;
                    $video_link = 'https://www.youtube.com/watch?v=' . $youtube_id;
                    wescraper_log("Extracted YouTube ID from lesson link: " . $youtube_id . " for lesson " . ($index + 1));
                }
            }
            
            // If we still don't have a YouTube ID, check if there's a video_id directly in the lesson data
            if (empty($youtube_id) && isset($lesson['video_id']) && !empty($lesson['video_id'])) {
                $youtube_id = $lesson['video_id'];
                $video_link = 'https://www.youtube.com/watch?v=' . $youtube_id;
                wescraper_log("Using provided video_id: " . $youtube_id . " for lesson " . ($index + 1));
            }
            
            // If we still don't have a YouTube ID, use the intro_video from the course data
            if (empty($youtube_id) && isset($json_data['intro_video']) && !empty($json_data['intro_video'])) {
                $extracted_id = wescraper_extract_youtube_id($json_data['intro_video']);
                if (!empty($extracted_id)) {
                    $youtube_id = $extracted_id;
                    $video_link = 'https://www.youtube.com/watch?v=' . $youtube_id;
                    wescraper_log("Using course intro_video ID: " . $youtube_id . " for lesson " . ($index + 1));
                }
            }
            
            // Use a default YouTube video if all else fails
            if (empty($video_link)) {
                $video_link = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
                wescraper_log("Using default YouTube video for lesson " . ($index + 1));
            }
            
            // Create lesson content
            // Add more detailed debugging
            wescraper_log("Processing lesson index: " . $index . ", Title: " . $lesson_title);
            wescraper_log("Lesson JSON data array has " . count($lesson_json_data) . " entries");
            
            if (isset($lesson_json_data[$index])) {
                wescraper_log("JSON data entry exists for index " . $index);
                
                if (isset($lesson_json_data[$index]['content'])) {
                    wescraper_log("Content field exists for index " . $index . ", Length: " . mb_strlen($lesson_json_data[$index]['content']) . " chars");
                    
                    if (!empty($lesson_json_data[$index]['content'])) {
                        $content_preview = mb_substr($lesson_json_data[$index]['content'], 0, 50) . "...";
                        wescraper_log("Using content: " . $content_preview);
                    } else {
                        wescraper_log("Content field is empty for index " . $index);
                    }
                } else {
                    wescraper_log("Content field does not exist for index " . $index . ", available fields: " . implode(", ", array_keys($lesson_json_data[$index])));
                }
            } else {
                wescraper_log("No JSON data entry exists for index " . $index);
            }
            
            $lesson_content = isset($lesson_json_data[$index]['content']) && !empty($lesson_json_data[$index]['content']) 
                ? $lesson_json_data[$index]['content'] 
                : "Content for " . $lesson_title;
            
            // Log what's happening with content
            if (isset($lesson_json_data[$index]['content']) && !empty($lesson_json_data[$index]['content'])) {
                $content_length = mb_strlen($lesson_json_data[$index]['content']);
                wescraper_log("Using extracted content for lesson {$index}, length: {$content_length} chars");
            } else {
                wescraper_log("No content found for lesson {$index}, using default: 'Content for {$lesson_title}'");
            }

            // Create lesson with ultra simplified data structure
            $lesson_data = [
                "topic_id" => (string)$topic_id,
                "lesson_title" => $lesson_title,
                "lesson_content" => $lesson_content,
                "lesson_author" => "1", // Use string for consistency
                "video" => [
                    "source_type" => "youtube",
                    "source" => $video_link,
                    "runtime" => [
                        "hours" => $duration_parts[0],
                        "minutes" => $duration_parts[1],
                        "seconds" => $duration_parts[2]
                    ]
                ]
            ];
            
            wescraper_log("Creating lesson: " . $lesson_title);

            $lesson_response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_LESSONS,
                'POST',
                $lesson_data
            );

            if (!isset($lesson_response['data'])) {
                wescraper_log("Warning: Error creating lesson: " . print_r($lesson_response, true));
                wescraper_log("Continuing with next lesson...");
                continue;
            }
            
            $lesson_id = $lesson_response['data'];
            wescraper_log("Lesson created with ID: " . $lesson_id);
            $lessons_created++;
        }
        
        wescraper_log("Created {$lessons_created} lessons out of " . count($json_data['lessons']));
        
        wescraper_log("✅ Course creation process completed successfully!");
        echo "<div style='margin-top:20px; padding:15px; background:#f0f8ff; border:1px solid #add8e6; border-radius:4px;'>";
        echo "<h3 style='margin-top:0;'>🎓 Course Creation Complete!</h3>";
        echo "<p>Course <strong>" . htmlspecialchars($course_title) . "</strong> has been successfully created with <strong>" . $lessons_created . "</strong> lessons.</p>";
        echo "<p><a href='edit.php?post_type=courses' class='button button-primary' style='margin-right:10px;' target='_blank'>View All Courses</a>";
        echo "<a href='post.php?post=" . $course_id . "&action=edit' class='button' target='_blank'>Edit This Course</a></p>";
        echo "</div>";

    } catch (Exception $e) {
        echo "<div style='color:white; padding:15px; background:#d63638; border:1px solid #d63638; margin:15px 0; border-radius:4px;'>";
        echo "<h3 style='margin-top:0; color:white;'>❌ Error Occurred</h3>";
        echo "<p>" . $e->getMessage() . "</p>";
        echo "<p style='margin-top:10px; font-size:0.9em;'>Check the WeScraper log file for more details.</p>";
        echo "</div>";
        wescraper_log("❌ Exception: " . $e->getMessage());
    }
}

/**
 * Test function to create a course with static English data
 * This helps verify the API connection is working properly
 */
function wescraper_test_add_course() {
    try {
        wescraper_log("Starting wescraper_test_add_course with static English data");
        
        // Get API credentials
        $api_key = get_option('wescraper_api_key', '');
        $api_secret = get_option('wescraper_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wescraper_log("ERROR: Missing API credentials. Please set them in the plugin settings");
            throw new Exception("Missing API credentials. Please set your Tutor LMS API key and secret in the plugin settings.");
        }
        
        wescraper_log("Using API credentials - Key: " . substr($api_key, 0, 5) . '...' . substr($api_key, -5) . ", Secret: " . substr($api_secret, 0, 3) . '...');
        
        // Initialize API client with credentials
        $api_client = new TutorAPIClient($api_key, $api_secret);
        wescraper_log("TutorAPIClient initialized for test");
        
        // Create static test course data (simplified, English-only)
        $test_course_data = [
            "post_author" => 1,
            "post_content" => "This is a test course created via API. It contains sample content for testing purposes.",
            "post_title" => "Test Course",
            "post_excerpt" => "This is a test course excerpt.",
            "post_status" => "publish",
            "comment_status" => "open",
            "additional_content" => [
                "course_benefits" => "Learn test content\nUnderstand API integration\nPractice course creation",
                "course_target_audience" => "Students interested in testing",
                "course_duration" => [
                    "hours" => 1,
                    "minutes" => 30
                ],
                "course_material_includes" => "Video lessons\nPractical examples\nLifetime access",
                "course_requirements" => "Basic computer knowledge"
            ],
            "course_level" => "beginner",
            "course_categories" => [1],  // Use default category
            "thumbnail_id" => 1,  // Use default thumbnail
        ];
        
        wescraper_log("Test course data prepared: " . print_r($test_course_data, true));
        echo "Creating test course with English data...<br>";
        
        // Make the API request
        wescraper_log("Making test API request to create course");
        $response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_COURSES,
            'POST',
            $test_course_data
        );
        
        wescraper_log("API test response received: " . print_r($response, true));

        if (!isset($response['data'])) {
            // Handle error
            $error_message = "Test course creation failed. ";
            if (isset($response['message'])) {
                $error_message .= "Message: " . $response['message'] . ". ";
            }
            if (isset($response['data']['details'])) {
                $error_message .= "Details: " . print_r($response['data']['details'], true);
            }
            
            wescraper_log("API Test Error: " . $error_message);
            echo "<div style='color:red; padding:10px; background:#ffeeee; border:1px solid #ffaaaa; margin:10px 0;'>";
            echo "<strong>API Test Error:</strong><br>";
            echo "The test course could not be created. The following issues were reported:<br><br>";
            
            // Display missing fields in a readable format
            if (isset($response['data']['details']) && is_array($response['data']['details'])) {
                echo "<ul>";
                foreach ($response['data']['details'] as $field => $error) {
                    echo "<li><strong>{$field}:</strong> " . implode(", ", (array)$error) . "</li>";
                }
                echo "</ul>";
            }
            
            echo "Please check the log for more details.";
            echo "</div>";
            
            throw new Exception("API test request failed: " . $error_message);
        }

        $course_id = $response['data'];
        wescraper_log("Test course created successfully with ID: " . $course_id);
        echo "<div style='color:green; padding:10px; background:#eeffee; border:1px solid #aaffaa; margin:10px 0;'>";
        echo "<strong>Success!</strong> Test course created with ID: " . $course_id . "</div>";
        
        // Create a single test topic
        $topic_data = [
            "topic_course_id" => $course_id,
            "topic_title" => "Test Topic",
            "topic_summary" => "Sample topic for testing",
            "topic_author" => 1
        ];
        
        wescraper_log("Test topic data: " . json_encode($topic_data));
        
        $topic_response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_TOPICS,
            'POST',
            $topic_data
        );
        
        if (!isset($topic_response['data'])) {
            wescraper_log("Error creating test topic: " . print_r($topic_response, true));
            throw new Exception("Error creating test topic: " . print_r($topic_response, true));
        }
        
        $topic_id = $topic_response['data'];
        wescraper_log("Test topic created with ID: " . $topic_id);
        
        // Create a sample lesson
        $lesson_data = [
            "topic_id" => $topic_id,
            "lesson_title" => "Sample Test Lesson",
            "lesson_content" => "This is sample lesson content for testing the API integration.",
            "lesson_author" => 1,
            "video" => [
                "source_type" => "youtube",
                "source" => "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
                "runtime" => [
                    "hours" => 0,
                    "minutes" => 3,
                    "seconds" => 32
                ]
            ]
        ];
        
        wescraper_log("Test lesson data: " . json_encode($lesson_data));
        
        $lesson_response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_LESSONS,
            'POST',
            $lesson_data
        );
        
        if (!isset($lesson_response['data'])) {
            wescraper_log("Error creating test lesson: " . print_r($lesson_response, true));
            throw new Exception("Error creating test lesson: " . print_r($lesson_response, true));
        }
        
        $lesson_id = $lesson_response['data'];
        wescraper_log("Test lesson created with ID: " . $lesson_id);
        
        wescraper_log("✅ Test course creation process completed successfully!");
        echo "Test course creation process completed successfully. ✅<br>";
        echo "You can now try the regular 'Add Course' process with your real data.";
        
    } catch (Exception $e) {
        echo "❌ Test Error: " . $e->getMessage() . "<br>";
        wescraper_log("❌ Test Exception: " . $e->getMessage());
    }
}

/**
 * Test function to create a course with Arabic Lorem Ipsum data
 * This helps test API handling of Arabic content
 */
function wescraper_test_add_arabic_course() {
    try {
        wescraper_log("Starting wescraper_test_add_arabic_course with Arabic Lorem Ipsum data");
        
        // Make sure the Arabic Lorem Ipsum function exists
        if (!function_exists('wescraper_generate_arabic_lorem_ipsum')) {
            throw new Exception("The Arabic Lorem Ipsum generator function doesn't exist.");
        }
        
        // Get API credentials
        $api_key = get_option('wescraper_api_key', '');
        $api_secret = get_option('wescraper_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wescraper_log("ERROR: Missing API credentials. Please set them in the plugin settings");
            throw new Exception("Missing API credentials. Please set your Tutor LMS API key and secret in the plugin settings.");
        }
        
        wescraper_log("Using API credentials - Key: " . substr($api_key, 0, 5) . '...' . substr($api_key, -5) . ", Secret: " . substr($api_secret, 0, 3) . '...');
        
        // Initialize API client with credentials
        $api_client = new TutorAPIClient($api_key, $api_secret);
        wescraper_log("TutorAPIClient initialized for Arabic test");
        
        // Generate Arabic Lorem Ipsum content
        $arabic_title = wescraper_generate_arabic_lorem_ipsum(20, false); // Shorter for title
        $arabic_content = wescraper_generate_arabic_lorem_ipsum(500, true); // Longer for content
        $arabic_excerpt = wescraper_generate_arabic_lorem_ipsum(100, true); // Medium for excerpt
        $arabic_benefits = wescraper_generate_arabic_lorem_ipsum(200, true); // Medium for benefits
        
        // Create static test course data with Arabic content
        $test_course_data = [
            "post_author" => 1,
            "post_content" => $arabic_content,
            "post_title" => $arabic_title . " - Arabic Test Course",
            "post_excerpt" => $arabic_excerpt,
            "post_status" => "publish",
            "comment_status" => "open",
            "additional_content" => [
                "course_benefits" => $arabic_benefits,
                "course_target_audience" => "طلاب مهتمون باللغة العربية\nStudents interested in Arabic language",
                "course_duration" => [
                    "hours" => 1,
                    "minutes" => 30
                ],
                "course_material_includes" => "دروس فيديو\nملفات PDF\nوصول مدى الحياة\nVideo lessons\nPDF files\nLifetime access",
                "course_requirements" => "معرفة أساسية بالكمبيوتر\nمعرفة اللغة العربية\nBasic computer knowledge\nArabic language knowledge"
            ],
            "course_level" => "beginner",
            "course_categories" => [1],  // Use default category
            "thumbnail_id" => 1,  // Use default thumbnail
        ];
        
        // Process the data through our Arabic data preparation function
        $test_course_data = wescraper_prepare_api_data($test_course_data);
        
        wescraper_log("Arabic test course data prepared");
        echo "Creating test course with Arabic Lorem Ipsum data...<br>";
        
        // Make the API request
        wescraper_log("Making test API request to create Arabic course");
        $response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_COURSES,
            'POST',
            $test_course_data
        );
        
        wescraper_log("API Arabic test response received: " . print_r($response, true));

        if (!isset($response['data'])) {
            // Handle error
            $error_message = "Arabic test course creation failed. ";
            if (isset($response['message'])) {
                $error_message .= "Message: " . $response['message'] . ". ";
            }
            if (isset($response['data']['details'])) {
                $error_message .= "Details: " . print_r($response['data']['details'], true);
            }
            
            wescraper_log("API Arabic Test Error: " . $error_message);
            echo "<div style='color:red; padding:10px; background:#ffeeee; border:1px solid #ffaaaa; margin:10px 0;'>";
            echo "<strong>API Arabic Test Error:</strong><br>";
            echo "The Arabic test course could not be created. The following issues were reported:<br><br>";
            
            // Display missing fields in a readable format
            if (isset($response['data']['details']) && is_array($response['data']['details'])) {
                echo "<ul>";
                foreach ($response['data']['details'] as $field => $error) {
                    echo "<li><strong>{$field}:</strong> " . implode(", ", (array)$error) . "</li>";
                }
                echo "</ul>";
            }
            
            echo "Please check the log for more details.";
            echo "</div>";
            
            throw new Exception("API Arabic test request failed: " . $error_message);
        }

        $course_id = $response['data'];
        wescraper_log("Arabic test course created successfully with ID: " . $course_id);
        echo "<div style='color:green; padding:10px; background:#eeffee; border:1px solid #aaffaa; margin:10px 0;'>";
        echo "<strong>Success!</strong> Arabic test course created with ID: " . $course_id . "</div>";
        
        // Create a single test topic with Arabic content
        $topic_title = wescraper_generate_arabic_lorem_ipsum(30, false);
        $topic_data = [
            "topic_course_id" => $course_id,
            "topic_title" => $topic_title . " - Test Topic",
            "topic_summary" => wescraper_generate_arabic_lorem_ipsum(100, true),
            "topic_author" => 1
        ];
        
        wescraper_log("Arabic test topic data prepared");
        
        $topic_response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_TOPICS,
            'POST',
            $topic_data
        );
        
        if (!isset($topic_response['data'])) {
            wescraper_log("Error creating Arabic test topic: " . print_r($topic_response, true));
            throw new Exception("Error creating Arabic test topic: " . print_r($topic_response, true));
        }
        
        $topic_id = $topic_response['data'];
        wescraper_log("Arabic test topic created with ID: " . $topic_id);
        
        // Create a sample lesson with Arabic content
        $lesson_title = wescraper_generate_arabic_lorem_ipsum(25, false);
        $lesson_data = [
            "topic_id" => $topic_id,
            "lesson_title" => $lesson_title . " - Arabic Lesson",
            "lesson_content" => wescraper_generate_arabic_lorem_ipsum(400, true),
            "lesson_author" => 1,
            "video" => [
                "source_type" => "youtube",
                "source" => "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
                "runtime" => [
                    "hours" => 0,
                    "minutes" => 3,
                    "seconds" => 32
                ]
            ]
        ];
        
        wescraper_log("Arabic test lesson data prepared");
        
        $lesson_response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_LESSONS,
            'POST',
            $lesson_data
        );
        
        if (!isset($lesson_response['data'])) {
            wescraper_log("Error creating Arabic test lesson: " . print_r($lesson_response, true));
            throw new Exception("Error creating Arabic test lesson: " . print_r($lesson_response, true));
        }
        
        $lesson_id = $lesson_response['data'];
        wescraper_log("Arabic test lesson created with ID: " . $lesson_id);
        
        wescraper_log("✅ Arabic test course creation process completed successfully!");
        echo "Arabic test course creation process completed successfully. ✅<br>";
        echo "You can now try the regular 'Add Course' process with your scraped Arabic data.";
        
    } catch (Exception $e) {
        echo "❌ Arabic Test Error: " . $e->getMessage() . "<br>";
        wescraper_log("❌ Arabic Test Exception: " . $e->getMessage());
    }
}

/**
 * Test function that uses the actual JSON file but with the reliable test approach
 */
function wescraper_test_with_actual_json() {
    try {
        wescraper_log("Starting wescraper_test_with_actual_json with actual JSON data");
        
        // Get API credentials
        $api_key = get_option('wescraper_api_key', '');
        $api_secret = get_option('wescraper_api_secret', '');
        
        if (empty($api_key) || empty($api_secret)) {
            wescraper_log("ERROR: Missing API credentials. Please set them in the plugin settings");
            throw new Exception("Missing API credentials. Please set your Tutor LMS API key and secret in the plugin settings.");
        }
        
        wescraper_log("Using API credentials - Key: " . substr($api_key, 0, 5) . '...' . substr($api_key, -5) . ", Secret: " . substr($api_secret, 0, 3) . '...');
        
        // Initialize API client with credentials
        $api_client = new TutorAPIClient($api_key, $api_secret);
        wescraper_log("TutorAPIClient initialized for real JSON test");
        
        // Read the actual JSON file
        $resultJsonPath = plugin_dir_path(__FILE__) . 'result.json';
        wescraper_log("Reading from actual JSON file: " . $resultJsonPath);
        
        if (!file_exists($resultJsonPath)) {
            wescraper_log("Error: JSON file not found: " . $resultJsonPath);
            throw new Exception("Course data JSON file not found! Please scrape a course first.");
        }
        
        // Read and parse the JSON file
        $json_content = file_get_contents($resultJsonPath);
        if (empty($json_content)) {
            wescraper_log("Error: Empty JSON file: " . $resultJsonPath);
            throw new Exception("The JSON file is empty. Please scrape a course first.");
        }
        
        // Ensure proper UTF-8 encoding
        $json_content = mb_convert_encoding($json_content, 'UTF-8', 'UTF-8');
        
        $json_data = json_decode($json_content, true, 512, JSON_UNESCAPED_UNICODE);
        if ($json_data === null) {
            wescraper_log("Error parsing JSON: " . json_last_error_msg());
            throw new Exception("Error parsing JSON: " . json_last_error_msg());
        }
        
        wescraper_log("JSON data parsed successfully: " . substr(print_r($json_data, true), 0, 500) . "...");
        
        // Check if content is Arabic
        $is_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $json_data['course_name']);
        if ($is_arabic) {
            wescraper_log("Detected Arabic content in course_name: " . $json_data['course_name']);
        }

        // ========== ULTRA SIMPLIFIED APPROACH ==========
        // Create the absolute bare minimum data required for course creation
        // Using string values for all fields which might reduce encoding issues
        
        $course_title = isset($json_data['course_name']) ? trim($json_data['course_name']) : "Untitled Course";
        
        $test_course_data = [
            "post_author" => "1", // String instead of integer
            "post_content" => "This is a test course with content from the actual JSON file.",
            "post_title" => $course_title,
            "post_excerpt" => "Test course excerpt.",
            "post_status" => "publish",
            "comment_status" => "open",
            "additional_content" => [
                "course_benefits" => "Learn test content\nUnderstand API integration\nPractice course creation",
                "course_target_audience" => "Students interested in testing",
                "course_duration" => [
                    "hours" => "1",
                    "minutes" => "30"
                ],
                "course_material_includes" => "Video lessons\nPractical examples\nLifetime access",
                "course_requirements" => "Basic computer knowledge"
            ],
            "course_level" => "beginner",
            "course_categories" => ["1"],  // Array of strings
            "thumbnail_id" => "1",  // String instead of integer
        ];
        
        // Make another copy of the course data
        $original_data = $test_course_data;
        
        wescraper_log("Ultra simplified test course data prepared");
        echo "Creating test course with ultra simplified data format...<br>";
        
        try {
            // Make the API request
            $response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_COURSES,
                'POST',
                $test_course_data
            );
            
            wescraper_log("API response received: " . print_r($response, true));
            
            if (!isset($response['data'])) {
                throw new Exception("API response did not contain expected data structure");
            }
            
            $course_id = $response['data'];
            wescraper_log("Course created successfully with ID: " . $course_id);
            echo "<div style='color:green; padding:10px; background:#eeffee; border:1px solid #aaffaa; margin:10px 0;'>";
            echo "<strong>Success!</strong> Test course created with ID: " . $course_id . "</div>";
            
            // Create a topic for the lessons using the absolute minimum data
            $topic_data = [
                "topic_course_id" => (string)$course_id,
                "topic_title" => "Test Topic",
                "topic_summary" => "Sample topic for testing",
                "topic_author" => "1"
            ];
            
            wescraper_log("Ultra simplified topic data prepared");
            
            $topic_response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_TOPICS,
                'POST',
                $topic_data
            );
            
            if (!isset($topic_response['data'])) {
                throw new Exception("Topic creation failed");
            }
            
            $topic_id = $topic_response['data'];
            wescraper_log("Topic created with ID: " . $topic_id);
            
            // Create a single test lesson
            $lesson_data = [
                "topic_id" => (string)$topic_id,
                "lesson_title" => "Test Lesson",
                "lesson_content" => "This is a test lesson for API validation.",
                "lesson_author" => "1",
                "video" => [
                    "source_type" => "youtube",
                    "source" => "https://www.youtube.com/watch?v=dQw4w9WgXcQ",
                    "runtime" => [
                        "hours" => "0",
                        "minutes" => "3",
                        "seconds" => "32"
                    ]
                ]
            ];
            
            wescraper_log("Ultra simplified lesson data prepared");
            
            $lesson_response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_LESSONS,
                'POST',
                $lesson_data
            );
            
            if (!isset($lesson_response['data'])) {
                throw new Exception("Lesson creation failed");
            }
            
            $lesson_id = $lesson_response['data'];
            wescraper_log("Lesson created with ID: " . $lesson_id);
            
            wescraper_log("✅ Ultra simplified test completed successfully!");
            echo "Test course creation process completed successfully. ✅<br>";
            echo "The ultra simplified approach worked. Now you can use this approach with your real data.";
            
            // Show the recommended approach
            echo "<div style='margin-top: 20px; padding: 15px; background: #f5f5f5; border: 1px solid #ddd;'>";
            echo "<strong>Recommended Solution:</strong><br>";
            echo "The test succeeded with an ultra simplified data structure. This approach should be used for your real course creation as well.";
            echo "</div>";
            
        } catch (Exception $e) {
            wescraper_log("ULTRA SIMPLIFIED TEST FAILED: " . $e->getMessage());
            echo "<div style='color:red; padding:10px; background:#ffeeee; border:1px solid #ffaaaa; margin:10px 0;'>";
            echo "<strong>Ultra Simplified Test Error:</strong><br>";
            echo $e->getMessage() . "<br><br>";
            echo "This indicates a fundamental API configuration issue.<br>";
            echo "Please verify your API credentials and WordPress user ID.";
            echo "</div>";
            
            // Try with admin ID = 2
            try {
                wescraper_log("Retrying with admin ID = 2");
                echo "Retrying with admin ID = 2...<br>";
                
                $original_data["post_author"] = "2";
                
                $response = $api_client->makeRequest(
                    TutorAPIConfig::ENDPOINT_COURSES,
                    'POST',
                    $original_data
                );
                
                if (isset($response['data'])) {
                    $course_id = $response['data'];
                    wescraper_log("Course created successfully with admin ID 2! Course ID: " . $course_id);
                    echo "<div style='color:green; padding:10px; background:#eeffee; border:1px solid #aaffaa; margin:10px 0;'>";
                    echo "<strong>Success!</strong> Test course created with admin ID 2 and course ID: " . $course_id . "</div>";
                    echo "Please use admin ID 2 for all future course creation.";
                } else {
                    throw new Exception("Still failed with admin ID 2");
                }
            } catch (Exception $retry_error) {
                wescraper_log("Retry with admin ID 2 also failed: " . $retry_error->getMessage());
                echo "Retry with admin ID 2 also failed.<br>";
                
                // Try with no post_author (let API use default)
                try {
                    wescraper_log("Retrying without specifying post_author");
                    echo "Retrying without specifying post_author...<br>";
                    
                    unset($original_data["post_author"]);
                    
                    $response = $api_client->makeRequest(
                        TutorAPIConfig::ENDPOINT_COURSES,
                        'POST',
                        $original_data
                    );
                    
                    if (isset($response['data'])) {
                        $course_id = $response['data'];
                        wescraper_log("Course created successfully without specifying post_author! Course ID: " . $course_id);
                        echo "<div style='color:green; padding:10px; background:#eeffee; border:1px solid #aaffaa; margin:10px 0;'>";
                        echo "<strong>Success!</strong> Test course created without specifying post_author. Course ID: " . $course_id . "</div>";
                        echo "Please remove the post_author field from your data for all future course creation.";
                    } else {
                        throw new Exception("Still failed without post_author");
                    }
                } catch (Exception $retry_error2) {
                    wescraper_log("All attempts failed. Please contact your WordPress administrator to check user IDs and API permissions.");
                    echo "<div style='color:red; padding:10px; background:#ffeeee; border:1px solid #ffaaaa; margin:10px 0;'>";
                    echo "<strong>All Attempts Failed:</strong><br>";
                    echo "Please contact your WordPress administrator to check user IDs and API permissions.<br>";
                    echo "The user ID provided in post_author does not exist or does not have permission to create courses.";
                    echo "</div>";
                }
            }
        }

    } catch (Exception $e) {
        echo "❌ Error: " . $e->getMessage() . "<br>";
        wescraper_log("❌ Exception: " . $e->getMessage());
    }
}
