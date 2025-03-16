<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'includes/tutor-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/category-name.php';
require_once plugin_dir_path(__FILE__) . 'includes/log.php';

function wescraper_add_course_page() {
    ?>
    <div class="wrap">
        <h2>Add Course</h2>
        <form method="post">
            <input type="submit" name="add_course" value="Add Course" class="button button-primary" />
        </form>
    </div>
    <?php
    if (isset($_POST['add_course'])) {
        wescraper_process_add_course();
    }
}

function wescraper_process_add_course() {
    try {
        
        wescraper_log("Starting wescraper_process_add_course");

        $api_client = new TutorAPIClient();
        wescraper_log("TutorAPIClient initialized");

        // Read the result.json file
        $json_file = plugin_dir_path(__FILE__) . '/result.json';
        if (!file_exists($json_file)) {
            wescraper_log("Error: result.json file not found!");
            throw new Exception("result.json file not found!");
        }

        wescraper_log("result.json file found");
        
        $json_data = json_decode(file_get_contents($json_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wescraper_log("Error parsing JSON: " . json_last_error_msg());
            throw new Exception("Error parsing JSON: " . json_last_error_msg());
        }
        wescraper_log("result.json parsed successfully");

        // Calculate total duration from lessons
        $total_minutes = 0;
        foreach ($json_data['lessons'] as $lesson) {
            $duration_parts = explode(':', $lesson['duration']);
            $total_minutes += ($duration_parts[0] * 60) + $duration_parts[1] + ($duration_parts[2] / 60);
        }
        $duration_hours = floor($total_minutes / 60);
        //$duration_minutes = intval(round($total_minutes % 60));
        $duration_minutes = round($total_minutes % 60);



        wescraper_log("Total duration calculated: " . $duration_hours . " hours, " . $duration_minutes . " minutes");

        // Format course benefits from lessons
        $benefits = array_map(function($lesson) {
            return "Learn " . $lesson['title'];
        }, $json_data['lessons']);

        wescraper_log("Course benefits formatted: " . implode(", ", $benefits));

        // Read lesson data first to get intro video
        $lesson_file = plugin_dir_path(__FILE__) . '/lesson_data.json';
        if (!file_exists($lesson_file)) {
            wescraper_log("Error: lesson_data.json file not found!");
            throw new Exception("lesson_data.json file not found!");
        }
        
        wescraper_log("lesson_data.json file found");

        $lesson_json_data = json_decode(file_get_contents($lesson_file), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            wescraper_log("Error parsing lesson_data.json: " . json_last_error_msg());
            throw new Exception("Error parsing lesson_data.json: " . json_last_error_msg());
        }
        
        wescraper_log("lesson_data.json parsed successfully");
        
        // Get first lesson's video for course intro
        // $intro_video = isset($lesson_json_data[0]['video_url']) ? $lesson_json_data[0]['video_url'] : null;
        // wescraper_log("Intro video URL (from lesson data): " . ($intro_video ? $intro_video : "null"));
        // no need for the intro video the api never succssed in that...

        // Convert embed URL to watch URL for intro video
        /*
        if ($intro_video && preg_match('/embed\/([^\/\?]+)/', $intro_video, $matches)) {
            $video_id = $matches[1];
            $intro_video = 'https://www.youtube.com/watch?v=' . $video_id;
            wescraper_log("Converted intro video URL: " . $intro_video);
        }
        wescraper_log("Final intro video URL: " . ($intro_video ? $intro_video : "null"));
        */
        
        // Get category ID for the course
        $category_manager = new CategoryManager();
        wescraper_log("CategoryManager initialized");

        // Get category from result.json
        $category_name = $json_data['category'];
        
        try {
            $category_id = $category_manager->get_category_id($category_name);
            
            wescraper_log("Category name: " . $category_name . ", Category ID: " . ($category_id ? $category_id : "null"));

            if (!$category_id) {
                wescraper_log("Warning: Failed to get/create category ID for: " . $category_name . ". Will use default category.");
                // Try to get the Uncategorized category as fallback
                $category_id = 1; // Default "Uncategorized" category ID
            }
        } catch (Exception $e) {
            wescraper_log("Error getting category: " . $e->getMessage() . ". Will use default category.");
            $category_id = 1; // Default "Uncategorized" category ID
        }

        echo "Using category: " . $category_name . " (ID: " . $category_id . ")<br><br>";

        // Prepare course data according to API requirements
        $course_data = [
            "post_author" => 1,
            "post_content" => $json_data['description'],
            "post_title" => $json_data['course_name'],
            "post_excerpt" => substr($json_data['description'], 0, 155) . '...',
            "post_status" => "publish",
            "comment_status" => "open",
            "additional_content" => [
                "course_benefits" => implode("\n", $benefits),
                "course_target_audience" => "Students interested in " . $category_name,
                "course_duration" => [
                    "hours" => $duration_hours,
                    "minutes" => $duration_minutes
                ],
                "course_material_includes" => "Video lessons\nPractical examples\nLifetime access",
                "course_requirements" => "Basic computer knowledge"
            ],
            "course_level" => "beginner",
            "course_categories" => [$category_id],  // Using dynamic category ID
            "thumbnail_id" => isset($json_data['thumbnail_id']) ? $json_data['thumbnail_id'] : 1,  // Use uploaded thumbnail ID
            "video" => $intro_video ? [
                "source_type" => "youtube",
                "source" => $intro_video
            ] : null
        ];
        
            //[2025-03-14 21:34:37] Exception: API request failed with status code: 400 Response:
            //{"code":"tutor_create_course","message":"Course create failed","data":{"status":400,"details":{"video_source":"Invalid video source"}}}


        echo "Creating new course from result.json...<br>";
        //echo "Course data to be sent: <br>";
        //echo json_encode($course_data, JSON_PRETTY_PRINT) . "<br><br>";

        wescraper_log("Course data to be sent: " . json_encode($course_data, JSON_PRETTY_PRINT));

        // Create the course
        $response = $api_client->makeRequest(
            TutorAPIConfig::ENDPOINT_COURSES,
            'POST',
            $course_data
        );

        if (!isset($response['data'])) {
            wescraper_log("Course creation failed. Response: " . print_r($response, true));
            throw new Exception("Course creation failed. Response: " . print_r($response, true));
        }

        $course_id = $response['data'];
        wescraper_log("Course created successfully with ID: " . $course_id);
        echo "Course created successfully with ID: " . $course_id . "<br><br>";

        // Set the course category explicitly after course creation
        $category_set = $category_manager->set_course_category($course_id, $category_id);
        if ($category_set) {
            wescraper_log("Category ID " . $category_id . " successfully set for course ID " . $course_id);
        } else {
            wescraper_log("Warning: Failed to set category for course. Will try alternative method.");
            // Try alternative method using direct wp_set_object_terms
            // Get the taxonomy name from the category manager
            $taxonomy = $category_manager->get_taxonomy_name();
            $result = wp_set_object_terms($course_id, intval($category_id), $taxonomy);
            if (!is_wp_error($result)) {
                wescraper_log("Category set using alternative method");
            } else {
                wescraper_log("Error setting category using alternative method: " . $result->get_error_message());
            }
        }

        // Save current course ID to a separate file
        $courseData = [
            'course_id' => $course_id,
            'course_name' => $json_data['course_name'],
            'created_at' => date('Y-m-d H:i:s'),
            'lesson_count' => count($json_data['lessons'])
        ];

        if (!is_writable(plugin_dir_path(__FILE__))) {
            wescraper_log("Error: Cannot write to directory " . plugin_dir_path(__FILE__));
            echo "************************************************************<br>";
            throw new Exception("Error: Cannot write to directory " . plugin_dir_path(__FILE__));
        }
        
        file_put_contents(plugin_dir_path(__FILE__) . "/current_course_id.json",
            json_encode($courseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        wescraper_log("Current course ID saved to current_course_id.json");
        echo "Current course ID saved to current_course_id.json <br>";

        // Before creating topics and lessons, read the current course ID
        $current_course_file = plugin_dir_path(__FILE__) . '/current_course_id.json';
        if (!file_exists($current_course_file)) {
            wescraper_log("Error: current_course_id.json not found! Please create course first.");
            throw new Exception("current_course_id.json not found! Please create course first.");
        }
        wescraper_log("Reading current_course_id.json");
        $current_course_data = json_decode(file_get_contents($current_course_file), true);
        $current_course_id = $current_course_data['course_id'];
        wescraper_log("Current course ID from file: " . $current_course_id);

        // Process lessons and create topics
        // Create a single topic for all lessons
        $topic_data = [
            "topic_course_id" => $current_course_id,
            "topic_title" => $json_data['course_name'] . " - Lessons",
            "topic_summary" => "All lessons for " . $json_data['course_name'],
            "topic_author" => 1
        ];

        wescraper_log("Topic data: " . json_encode($topic_data));

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

        // Process each lesson
        foreach ($json_data['lessons'] as $index => $lesson) {
            $lesson_title = $lesson['title'];
            $lesson_duration = $lesson['duration'];
            $duration_parts = explode(':', $lesson_duration);

            $lesson_file = plugin_dir_path(__FILE__) . '/lesson_data.json';
            $lesson_json_data = json_decode(file_get_contents($lesson_file), true);
            $video_link = $lesson_json_data[$index]['video_url'];

            // Convert embed URL to watch URL
            if (preg_match('/embed\/([^\/\?]+)/', $video_link, $matches)) {
                $video_id = $matches[1];
                $video_link = 'https://www.youtube.com/watch?v=' . $video_id;
            }

            wescraper_log("Processing lesson: " . $lesson_title . ", Duration: " . $lesson_duration . ", Video: " . $video_link);

            // Create lesson
            $lesson_data = [
                "topic_id" => $topic_id,  // Use the actual topic ID
                "lesson_title" => $lesson_title,
                "lesson_content" => isset($lesson_json_data[$index]['extensions']) ? $lesson_json_data[$index]['extensions'] : "Lesson content for " . $lesson_title,
                "lesson_author" => 1,
                "video" => [
                    "source_type" => "youtube",
                    "source" => $video_link,
                    "runtime" => [
                        "hours" => (int)$duration_parts[0],
                        "minutes" => (int)$duration_parts[1],
                        "seconds" => (int)$duration_parts[2]
                    ]
                ]
            ];

            wescraper_log("Lesson data: " . json_encode($lesson_data));

            $lesson_response = $api_client->makeRequest(
                TutorAPIConfig::ENDPOINT_LESSONS,
                'POST',
                $lesson_data
            );

            if (!isset($lesson_response['data'])) {
                wescraper_log("Error creating lesson: " . print_r($lesson_response, true));
                throw new Exception("Error creating lesson: " . print_r($lesson_response, true));
            }

            $created_lesson_id = $lesson_response['data'];
            wescraper_log("Lesson created with ID: " . $created_lesson_id);
        }

        wescraper_log("Course creation process completed successfully.");
        echo "Course creation process completed successfully. <br>";

    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "<br>";
        wescraper_log("Exception: " . $e->getMessage());
    }
}
