<?php
namespace WeScraper;

class Scraper {
    private $config;
    private $api_client;
    private $category_manager;
    private $cookies;
    private $media_uploader;

    public function __construct() {
        $this->load_dependencies();
        $this->init_components();
    }

    private function load_dependencies() {
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-tutor-api-client.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-category-helper.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-media-uploader.php';
    }

    private function init_components() {
        $this->config = $this->load_config();
        $this->api_client = new TutorAPIClient();
        $this->category_manager = new CategoryManager();
        $this->cookies = $this->get_cookies();
        $this->media_uploader = new MediaUploader();
    }

    private function load_config() {
        $config_file = WESCRAPER_PLUGIN_DIR . 'config.json';
        if (!file_exists($config_file)) {
            throw new \Exception("Config file not found!");
        }
        return json_decode(file_get_contents($config_file), true);
    }

    private function get_cookies() {
        $cookies_file = WESCRAPER_PLUGIN_DIR . 'cookies.json';
        if (!file_exists($cookies_file)) {
            throw new \Exception("❌ Error: cookies.json file not found!");
        }

        return $this->parse_cookies_file($cookies_file);
    }

    private function parse_cookies_file($filePath) {
        $json = file_get_contents($filePath);
        $cookiesArray = json_decode($json, true);

        if (!$cookiesArray) {
            throw new \Exception("❌ Error: Failed to parse cookies.json");
        }

        // Extract relevant cookies
        $cookieNames = ["__eoi", "laravel_session", "remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d", "XSRF-TOKEN"];
        $cookies = [];

        foreach ($cookiesArray as $cookie) {
            if (in_array($cookie["name"], $cookieNames)) {
                $cookies[] = $cookie["name"] . "=" . $cookie["value"];
            }
        }

        if (empty($cookies)) {
            throw new \Exception("❌ Error: No relevant cookies found in cookies.json!");
        }

        error_log("✅ Cookies loaded successfully!");
        return implode("; ", $cookies);
    }

    public function run() {
        try {
            // Check login status first
            if (!$this->check_login_status()) {
                throw new \Exception("Not logged in. Please check your cookies.");
            }

            // Get course data from URL in config
            $course_data = $this->scrape_course_data($this->config['course_url']);
            
            // Process course data and create course
            $result = $this->process_course($course_data);

            // Save results
            $this->save_results($result);

            return [
                'status' => 'success',
                'courses' => 1,
                'message' => 'Course processed successfully'
            ];

        } catch (\Exception $e) {
            throw new \Exception("Scraper error: " . $e->getMessage());
        }
    }

    private function check_login_status() {
        $this->log("Checking login status...");
        $ch = curl_init();
        $profileUrl = "https://www.mindluster.com/profile/info";

        curl_setopt($ch, CURLOPT_URL, $profileUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Cookie: " . $this->cookies,
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        $result = (strpos($response, 'My Account') !== false || strpos($response, 'p_name') !== false);
        if ($result) {
            $this->log("Login status: Logged in", 'success');
        } else {
            $this->log("Login status: Not logged in", 'error');
        }
        return $result;
    }

    private function scrape_course_data($url) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Cookie: " . $this->cookies,
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
        ]);

        $html = curl_exec($ch);
        
        if (curl_errno($ch)) {
            throw new \Exception("Curl error: " . curl_error($ch));
        }
        
        curl_close($ch);

        $data = [];

        // 1. Course Name
        preg_match('/<h1[^>]*id="course_title"[^>]*>(.*?)<\/h1>/', $html, $match);
        $data["course_name"] = trim($match[1] ?? "Not found");

        // 2. Category
        preg_match('/<li class="breadcrumb-item color">.*?<a[^>]*href="[^"]*certified\/cat\/\d+\/([^"]+)"[^>]*>/', $html, $match);
        $data["category"] = trim($match[1] ?? "Not found");

        // 3. Lesson Count (Fixed)
        preg_match('/<div class="home_title">[^|]+\|\s*(\d+)\s*<\/div>/', $html, $match);
        $data["lesson_count"] = trim($match[1] ?? "Not found");

        // 4. Course Description (Now Clean)
        preg_match_all('/<div class="m3aarf_card">(.*?)<\/div>/s', $html, $matches);
        $raw_description = isset($matches[1]) ? implode("\n", $matches[1]) : "Not found";
        $data["description"] = $this->cleanText($raw_description);

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

        // 6. Intro Video (Using First Lesson's Video)
        $data["intro_video"] = isset($lessons[0]) ? $lessons[0]["link"] : "Not found";

        // 7. Thumbnail will be set during lesson scraping
        $data["thumbnail"] = "pending";

        return $data;
    }

    private function cleanText($text) {
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

    private function process_course($course_data) {
        try {
            // Save initial result.json
            $this->save_result_json($course_data);

            // Get category ID
            $category_id = $this->category_manager->get_category_id($course_data['category']);
            if (!$category_id) {
                throw new \Exception("Failed to get/create category: " . $course_data['category']);
            }

            // Calculate total duration
            $total_duration = $this->calculate_total_duration($course_data['lessons']);

            // Prepare course data for API
            $api_course_data = [
                'post_title' => $course_data['course_name'],
                'post_content' => $course_data['description'],
                'post_status' => 'publish',
                'categories' => [$category_id],
                'meta' => [
                    '_tutor_course_duration' => $total_duration,
                    '_tutor_course_level' => 'intermediate',
                    '_tutor_course_benefits' => $course_data['description'],
                    '_tutor_course_requirements' => '',
                    '_tutor_course_target_audience' => '',
                    '_tutor_course_material_includes' => sprintf('%d video lessons', count($course_data['lessons']))
                ]
            ];

            // Create course through API
            $course_id = $this->api_client->create_course($api_course_data);
            if (!$course_id) {
                throw new \Exception("Failed to create course");
            }

            // Create main topic
            $topic_data = [
                'post_title' => 'Main Content',
                'course_id' => $course_id
            ];

            $topic_id = $this->api_client->create_topic($topic_data);
            if (!$topic_id) {
                throw new \Exception("Failed to create topic");
            }

            // Process lesson extensions and get detailed lesson data
            $detailed_lessons = $this->process_lesson_extensions($course_data['lessons']);
            
            // Save lesson_data.json
            $this->save_lesson_data($detailed_lessons);

            // Update lesson data with video URLs when creating lessons
            $processed_lessons = [];
            foreach ($course_data['lessons'] as $index => $lesson) {
                try {
                    $detailed_lesson = $detailed_lessons[$index] ?? null;
                    $lesson_data = [
                        'post_title' => $lesson['title'],
                        'post_content' => $detailed_lesson ? $detailed_lesson['extensions'] : '',
                        'post_status' => 'publish',
                        'menu_order' => $index,
                        'topic_id' => $topic_id,
                        'video' => [
                            'source' => 'youtube',
                            'source_url' => $detailed_lesson ? $detailed_lesson['video_url'] : $lesson['link'],
                            'duration' => $lesson['duration']
                        ]
                    ];

                    $lesson_id = $this->api_client->create_lesson($lesson_data);
                    if ($lesson_id) {
                        $processed_lessons[] = [
                            'id' => $lesson_id,
                            'title' => $lesson['title'],
                            'duration' => $lesson['duration'],
                            'video_url' => $detailed_lesson ? $detailed_lesson['video_url'] : null,
                            'extensions' => $detailed_lesson ? $detailed_lesson['extensions'] : null
                        ];
                    }
                } catch (\Exception $e) {
                    error_log("Error creating lesson: " . $e->getMessage());
                }
            }

            // Save current course ID
            $this->save_current_course_id($course_id, $course_data);

            // Prepare complete data for saving
            $complete_data = [
                'source_data' => $course_data,
                'course_id' => $course_id,
                'topic_id' => $topic_id,
                'category_id' => $category_id,
                'processed_lessons' => $processed_lessons
            ];

            return $complete_data;

        } catch (\Exception $e) {
            throw new \Exception("Course processing failed: " . $e->getMessage());
        }
    }

    private function save_current_course_id($course_id, $course_data) {
        $this->log("Saving current course ID...");
        $file = WESCRAPER_PLUGIN_DIR . 'data/current_course_id.json';
        $data = [
            'course_id' => $course_id,
            'course_name' => $course_data['course_name'],
            'created_at' => date('Y-m-d H:i:s'),
            'lesson_count' => intval($course_data['lesson_count'])
        ];
        
        file_put_contents($file, json_encode($data, JSON_PRETTY_PRINT));
        $this->log("Current course ID saved", 'success');
    }

    private function calculate_total_duration($lessons) {
        $total_minutes = 0;
        foreach ($lessons as $lesson) {
            if (!empty($lesson['duration'])) {
                // Parse duration in format HH:MM:SS
                $parts = array_reverse(explode(':', $lesson['duration']));
                if (count($parts) >= 2) {
                    $total_minutes += intval($parts[1]); // Minutes
                    if (isset($parts[2])) {
                        $total_minutes += intval($parts[2]) * 60; // Hours to minutes
                    }
                }
            }
        }
        return $total_minutes;
    }

    private function save_results($result) {
        $filename = WESCRAPER_PLUGIN_DIR . 'data/created_courses/' . 
                   date('Y-m-d_H-i-s') . '_course_' . $result['course_id'] . '.json';
        
        if (!file_exists(dirname($filename))) {
            mkdir(dirname($filename), 0777, true);
        }

        file_put_contents($filename, json_encode($result, JSON_PRETTY_PRINT));
    }

    private function process_lesson_extensions($lessons) {
        try {
            if (empty($lessons)) {
                throw new \Exception("No lessons found in course data");
            }

            $allLessonData = [];

            foreach ($lessons as $index => $lesson) {
                $lessonTitle = $lesson['title'];
                $lessonLink = $lesson['link'];
                $videoLength = $lesson['duration'] ?? "Unknown";

                $this->log("-----------------------------------------------------");
                $this->log("Processing lesson " . ($index + 1) . ": " . $lessonTitle);

                // Validate lesson data
                if (empty($lessonLink)) {
                    $this->log("Missing lesson link for: " . $lessonTitle, 'error');
                    continue;
                }

                $lessonHtml = $this->fetch_lesson_page($lessonLink);

                if (!$lessonHtml) {
                    $this->log("Failed to fetch lesson page: " . $lessonTitle, 'error');
                    continue;
                }

                // Create lesson data with title from course data
                $lessonData = [
                    'title' => $lessonTitle,
                    'video_url' => null,
                    'duration' => $videoLength,
                    'extensions' => "No Extensions Found"
                ];

                // Extract video ID and handle thumbnail
                preg_match('/<img[^>]+src="https:\/\/img\.youtube\.com\/vi\/([^\/]+)\/hqdefault\.jpg"/', $lessonHtml, $videoIdMatches);
                if (isset($videoIdMatches[1])) {
                    $videoId = $videoIdMatches[1];
                    $lessonData['video_url'] = "https://www.youtube.com/embed/" . $videoId;

                    // Set course thumbnail ONLY for the first lesson
                    if ($index === 0) {
                        try {
                            $thumbnail = "https://img.youtube.com/vi/{$videoId}/hqdefault.jpg";
                            $this->update_course_thumbnail($thumbnail);
                            $this->log("Course thumbnail updated in result.json from first lesson", 'success');
                        } catch (\Exception $e) {
                            $this->log("Failed to update thumbnail: " . $e->getMessage(), 'error');
                        }
                    }
                } else {
                    $this->log("No video ID found for lesson: " . $lessonTitle, 'error');
                }

                // Extract and process lesson extensions
                try {
                    $extensionText = $this->extract_lesson_extension($lessonHtml);
                    if ($extensionText !== "No Extensions Found" && $extensionText !== "Failed to load extension content") {
                        $lessonData['extensions'] = $extensionText;
                    }
                } catch (\Exception $e) {
                    $this->log("Error processing lesson extension: " . $e->getMessage(), 'error');
                }

                $allLessonData[] = $lessonData;
                $this->log("✅ Lesson data extracted successfully");
            }

            if (empty($allLessonData)) {
                throw new \Exception("No lesson data was successfully processed");
            }

            // Save lesson data
            $this->save_lesson_data($allLessonData);
            $this->log("✅ All lesson data saved to lesson_data.json");

            return $allLessonData;

        } catch (\Exception $e) {
            $this->log("❌ Error: " . $e->getMessage(), 'error');
            throw $e;
        }
    }

    private function fetch_lesson_page($url) {
        $ch = curl_init();
        
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Cookie: " . $this->cookies,
            "User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36"
        ]);

        $response = curl_exec($ch);
        curl_close($ch);

        return $response ?: false;
    }

    private function extract_lesson_extension($html) {
        // Extract lesson extension iframe URL
        preg_match('/<div class="row m3aarf_card">.*?<iframe[^>]+src="([^"]+)"[^>]*>/s', $html, $extensionIframeMatches);
        
        if (empty($extensionIframeMatches[1])) {
            return "No Extensions Found";
        }

        // Fetch the content from the extension iframe
        $extensionHtml = $this->fetch_lesson_page($extensionIframeMatches[1]);
        if (!$extensionHtml) {
            return "Failed to load extension content";
        }

        // Clean the fetched HTML
        $text = strip_tags($extensionHtml);
        
        // Remove embedded CSS
        $text = preg_replace('/\* \{[^}]+\}|\s*<style[^>]*>.*?<\/style>/is', '', $text);

        // Clean up formatting
        $text = trim(preg_replace('/\s+/', ' ', $text));
        
        return nl2br($text);
    }

    private function update_course_thumbnail($thumbnail_url) {
        try {
            $this->log("Uploading thumbnail...");
            // Upload thumbnail to WordPress media library
            $attachment_id = $this->media_uploader->upload_from_url(
                $thumbnail_url, 
                'Course Thumbnail'
            );

            // Update course featured image
            if ($attachment_id) {
                $current_course_file = WESCRAPER_PLUGIN_DIR . 'data/current_course_id.json';
                if (file_exists($current_course_file)) {
                    $current_course = json_decode(file_get_contents($current_course_file), true);
                    if (isset($current_course['course_id'])) {
                        // Set the thumbnail as course featured image
                        set_post_thumbnail($current_course['course_id'], $attachment_id);
                        
                        $this->log("Course thumbnail uploaded successfully", 'success');
                    }
                }
            }

            // Update result.json with the thumbnail URL
            $result_file = WESCRAPER_PLUGIN_DIR . 'data/result.json';
            if (file_exists($result_file)) {
                $jsonData = json_decode(file_get_contents($result_file), true);
                $jsonData['thumbnail'] = $thumbnail_url;
                file_put_contents($result_file, json_encode($jsonData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
                $this->log("✅ Thumbnail URL updated in result.json");
            }

        } catch (\Exception $e) {
            $this->log("Failed to upload thumbnail: " . $e->getMessage(), 'error');
        }
    }

    private function save_result_json($course_data) {
        $this->log("Saving course data to result.json...");
        $result_file = WESCRAPER_PLUGIN_DIR . 'data/result.json';
        $result = [
            'course_name' => $course_data['course_name'],
            'category' => $course_data['category'],
            'lesson_count' => $course_data['lesson_count'],
            'description' => $course_data['description'],
            'lessons' => $course_data['lessons'],
            // Thumbnail will be added later during lesson processing
        ];
        
        file_put_contents($result_file, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->log("Course data saved to result.json", 'success');
    }

    private function save_lesson_data($lesson_data) {
        $this->log("Saving lesson data to lesson_data.json...");
        $file = WESCRAPER_PLUGIN_DIR . 'data/lesson_data.json';
        if (file_put_contents($file, json_encode($lesson_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))) {
            $this->log("✅ Lesson data saved successfully!", 'success');
        } else {
            $this->log("Failed to save lesson data", 'error');
        }
    }

    private function log($message, $type = 'info') {
        $prefix = match($type) {
            'success' => "✅ ",
            'error' => "❌ ",
            'info' => "ℹ️ ",
            default => ""
        };
        error_log($prefix . $message . "\n");
    }
} 