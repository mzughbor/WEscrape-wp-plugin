<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Function to clean description text and remove HTML
function wescraper_clean_text($text) {
    // Remove CSS styling that often appears in content
    $text = preg_replace('/\*\s*{\s*font-size:\s*\d+px;\s*}/', '', $text);
    $text = preg_replace('/\*\s*{\s*font[-\w]*:\s*[^}]+}/', '', $text);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $text);
    
    // Remove ads and unwanted scripts - improved to catch all variations
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{.*?\}\);/s', '', $text);
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\s*\{\s*\}\s*\);/s', '', $text);
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\..+?;/s', '', $text);
    
    // More aggressive adsbygoogle cleaning
    $text = preg_replace('/adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\s*;?/i', '', $text);
    $text = preg_replace('/window\.adsbygoogle\s*\|\|\s*\[\]\.push\(.*?\);?/i', '', $text);
    $text = preg_replace('/adsbygoogle\.push\(.*?\);?/i', '', $text);
    
    // Remove any other ad-related scripts
    $text = preg_replace('/<script.*?adsbygoogle.*?<\/script>/s', '', $text);
    $text = preg_replace('/<ins\s+class="adsbygoogle.*?<\/ins>/s', '', $text);
    
    // Remove tracking and short URLs
    $text = preg_replace('/http:\/\/bit\.ly\/[\w-]+/i', '', $text);
    $text = preg_replace('/https:\/\/bit\.ly\/[\w-]+/i', '', $text);
    
    // Remove common marketing text patterns
    $text = preg_replace('/(Learn|Click|Visit|Check|Subscribe|Follow).*?(http|www|\.com|\.org|\.net)/i', '', $text);
    
    // Clean up hashtags and social media references
    $text = preg_replace('/#[\w-]+\b/', '', $text);
    $text = preg_replace('/\b(Instagram|Twitter|Facebook|LinkedIn):?\s+https?:\/\/[^\s]+/i', '', $text);
    
    // Check for Arabic content
    $has_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $text);
    
    // Special handling for Arabic content
    if ($has_arabic) {
        // Fix broken HTML entities in Arabic text
        $text = preg_replace('/&(?!#?[a-z0-9]+;)/', '&amp;', $text);
    }
    
    // Remove all HTML tags
    $text = strip_tags($text);
    
    // Convert HTML entities to normal text with UTF-8 encoding
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // Remove excessive newlines, carriage returns, and extra spaces
    // Use unicode flag for Arabic content
    $text = preg_replace('/\s+/u', ' ', trim($text));
    
    // Special handling for Arabic
    if ($has_arabic) {
        // Remove any remaining Unicode escape sequences
        $text = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function($matches) {
            return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE');
        }, $text);
        
        // Final clean-up to ensure proper UTF-8 encoding
        $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    }
    
    // Clean up multiple occurrences of the same word repeated
    $text = preg_replace('/\b(\w+)(\s+\1){2,}\b/i', '$1', $text);
    
    // Clean up double spaces and extra whitespace
    $text = preg_replace('/\s{2,}/', ' ', $text);
    
    // Final trim to remove leading/trailing spaces
    $text = trim($text);
    
    // If text is empty after cleaning, provide a default message
    if (empty($text)) {
        $text = "No content available";
    }

    return $text;
}

// Add a specific function for cleaning Arabic text
function wescraper_clean_arabic_text($text) {
    // First detect if there is any Arabic content
    if (!preg_match('/[\x{0600}-\x{06FF}]/u', $text)) {
        // If no Arabic content, use regular cleaning
        return wescraper_clean_text($text);
    }
    
    // Special handling for Arabic text
    
    // Remove CSS styling that often appears in content
    $text = preg_replace('/\*\s*{\s*font-size:\s*\d+px;\s*}/', '', $text);
    $text = preg_replace('/\*\s*{\s*font[-\w]*:\s*[^}]+}/', '', $text);
    $text = preg_replace('/<style[^>]*>.*?<\/style>/s', '', $text);
    
    // Remove ads and unwanted scripts - improved to catch all variations
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{.*?\}\);/s', '', $text);
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\s*\{\s*\}\s*\);/s', '', $text);
    $text = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\..+?;/s', '', $text);
    
    // More aggressive adsbygoogle cleaning
    $text = preg_replace('/adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\s*;?/i', '', $text);
    $text = preg_replace('/window\.adsbygoogle\s*\|\|\s*\[\]\.push\(.*?\);?/i', '', $text);
    $text = preg_replace('/adsbygoogle\.push\(.*?\);?/i', '', $text);
    
    // Remove any other ad-related scripts
    $text = preg_replace('/<script.*?adsbygoogle.*?<\/script>/s', '', $text);
    $text = preg_replace('/<ins\s+class="adsbygoogle.*?<\/ins>/s', '', $text);
    
    // Remove tracking and short URLs
    $text = preg_replace('/http:\/\/bit\.ly\/[\w-]+/i', '', $text);
    $text = preg_replace('/https:\/\/bit\.ly\/[\w-]+/i', '', $text);
    
    // 1. Convert HTML entities and fix broken entities
    $text = preg_replace('/&(?!#?[a-z0-9]+;)/', '&amp;', $text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    
    // 2. Remove HTML tags
    $text = strip_tags($text);
    
    // 3. Clean whitespace with unicode flag
    $text = preg_replace('/\s+/u', ' ', trim($text));
    
    // 4. Convert any Unicode escape sequences
    $text = preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function($matches) {
        return mb_convert_encoding(pack('H*', $matches[1]), 'UTF-8', 'UTF-16BE');
    }, $text);
    
    // 5. Ensure proper UTF-8 encoding
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // Clean up multiple occurrences of the same word repeated
    $text = preg_replace('/\b(\w+)(\s+\1){2,}\b/i', '$1', $text);
    
    // Clean up double spaces and extra whitespace
    $text = preg_replace('/\s{2,}/', ' ', $text);
    
    // Final trim to remove leading/trailing spaces
    $text = trim($text);
    
    // If text is empty after cleaning, provide a default message
    if (empty($text)) {
        $text = "لا يوجد محتوى متاح"; // "No content available" in Arabic
    }

    return $text;
}

// Function to detect source site from URL
function wescraper_detect_source_site($url) {
    wescraper_log("Detecting source site for URL: " . $url);
    
    if (empty($url)) {
        wescraper_log("Empty URL provided");
        return false;
    }

    $url = strtolower($url);
    wescraper_log("Normalized URL: " . $url);
    
    // Define site patterns and their identifiers
    $site_patterns = [
        'mindluster' => [
            'patterns' => [
                '/mindluster\.com/',
                '/mindluster\.org/',
                '/mindluster\.net/'
            ],
            'name' => 'mindluster'
        ],
        'm3aarf' => [
            'patterns' => [
                '/m3aarf\.com/'
            ],
            'name' => 'm3aarf'
        ],
        'new_site' => [
            'patterns' => [
                '/new-site\.com/',
                '/new-site\.org/',
                '/new-site\.net/'
            ],
            'name' => 'new_site'
        ]
        // Add more sites here as needed
    ];

    foreach ($site_patterns as $site) {
        foreach ($site['patterns'] as $pattern) {
            if (preg_match($pattern, $url)) {
                wescraper_log("Site detected: " . $site['name']);
                return $site['name'];
            }
        }
    }

    wescraper_log("No matching site found for URL: " . $url);
    return false; // Return false if no matching site found
}

function wescraper_extract_data($input, $site_type = 'mindluster', $cookies = '') {
    wescraper_log("Starting data extraction for site type: " . $site_type);
    
    switch ($site_type) {
        case 'm3aarf':
            wescraper_log("Calling m3aarf_scrape_course with URL: " . $input);
            $result = m3aarf_scrape_course($input, $cookies);
            if (!$result) {
                wescraper_log("m3aarf_scrape_course returned false");
                return false;
            }
            wescraper_log("Course data successfully extracted: " . print_r($result, true));
            return $result;
            
        case 'mindluster':
            wescraper_log("Processing mindluster extraction");
            // For mindluster, $input is the HTML
            $data = [];

            // 1. Course Name
            preg_match('/<h1[^>]*id="course_title"[^>]*>(.*?)<\/h1>/', $input, $match);
            $data["course_name"] = trim($match[1] ?? "Not found");
            wescraper_log("course name extracted: " . $data["course_name"]);

            // 2. Category
            preg_match('/<li class="breadcrumb-item color">.*?<a[^>]*href="[^"]*certified\/cat\/\d+\/([^"]+)"[^>]*>/', $input, $match);
            $data["category"] = trim($match[1] ?? "Not found");
            wescraper_log("category extracted: " . $data["category"]);

            // 3. Lesson Count (Fixed)
            preg_match('/<div class="home_title">[^|]+\|\s*(\d+)\s*<\/div>/', $input, $match);
            $data["lesson_count"] = trim($match[1] ?? "Not found");
            wescraper_log("lesson count extracted: " . $data["lesson_count"]);

            // 4. Course Description (Now Clean)
            preg_match_all('/<div class="m3aarf_card">(.*?)<\/div>/s', $input, $matches);
            $raw_description = isset($matches[1]) ? implode("\n", $matches[1]) : "Not found";
            
            // Remove ad content and scripts that might be in the description
            $raw_description = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $raw_description);
            $raw_description = preg_replace('/<ins\b[^>]*>.*?<\/ins>/is', '', $raw_description);
            $raw_description = preg_replace('/<div[^>]*class="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $raw_description);
            $raw_description = preg_replace('/<div[^>]*id="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $raw_description);
            $raw_description = preg_replace('/<iframe[^>]*>[^<]*<\/iframe>/is', '', $raw_description);
            
            // Use specialized Arabic text cleaning
            $has_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $raw_description);
            if ($has_arabic) {
                $data["description"] = wescraper_clean_arabic_text($raw_description);
            } else {
            $data["description"] = wescraper_clean_text($raw_description);
            }
            wescraper_log("description extracted: " . $data["description"]);

            // 5. Lessons (Links, Titles, and Durations)
            preg_match_all('/<a[^>]*href="(https:\/\/www\.mindluster\.com\/lesson\/\d+-video)"[^>]*title="([^"]+)"/', $input, $matches);
            preg_match_all('/<span class="lesson_duration">([^<]+)<\/span>/', $input, $durationMatches);

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
        default:
            wescraper_log("Unsupported site type: " . $site_type);
            return false;
    }
}

function wescraper_fetch_url($url, $cookies = '') {
    wescraper_log("Fetching URL: " . $url);
    //wescraper_log("Using cookies: " . $cookies);
    
    $args = array(
        'headers' => array(
            'Cookie' => $cookies,
            'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/91.0.4472.124 Safari/537.36'
        ),
        'sslverify' => true,
    );
    
    wescraper_log("Request args: " . print_r($args, true));
    $response = wp_remote_get($url, $args);

    if (is_wp_error($response)) {
        wescraper_log("wp_remote_get error: " . print_r($response->get_error_message(), true));
        return false;
    }

    $body = wp_remote_retrieve_body($response);
    $status = wp_remote_retrieve_response_code($response);
    wescraper_log("Response status code: " . $status);
    //wescraper_log("Response headers: " . print_r(wp_remote_retrieve_headers($response), true));
    
    if(empty($body)){
        wescraper_log("Retrieved body is empty.");
    } else {
        wescraper_log("Retrieved body length: " . strlen($body));
        //wescraper_log("First 500 chars of body: " . substr($body, 0, 500));
    }
    return $body;
}

function wescraper_encode_json($data) {
    // Ensure proper encoding of all strings in the data
    array_walk_recursive($data, function(&$item) {
        if (is_string($item)) {
            $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');
        }
    });

    // Encode with consistent flags
    $json = json_encode($data, 
        JSON_PRETTY_PRINT | 
        JSON_UNESCAPED_SLASHES | 
        JSON_UNESCAPED_UNICODE | 
        JSON_PRESERVE_ZERO_FRACTION
    );

    if ($json === false) {
        wescraper_log("JSON encoding failed: " . json_last_error_msg());
        return false;
    }

    return $json;
}

function wescraper_decode_json($json) {
    // Ensure proper UTF-8 encoding of the JSON string
    $json = mb_convert_encoding($json, 'UTF-8', 'UTF-8');
    
    // Decode with proper flags
    $data = json_decode($json, true, 512, JSON_UNESCAPED_UNICODE);
    
    if ($data === null) {
        wescraper_log("JSON decoding failed: " . json_last_error_msg());
        return false;
    }

    return $data;
}

/**
 * Clean Arabic text for API submission to prevent escaping issues and empty fields
 */
function wescraper_clean_arabic_text_for_api($text) {
    if (empty($text)) {
        return "Default text - " . date('Y-m-d H:i:s');
    }
    
    // Normalize and clean text
    $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
    
    // Trim whitespace
    $text = trim($text);
    
    // Make sure we don't end up with empty text
    if (empty($text)) {
        return "Default text - " . date('Y-m-d H:i:s');
    }
    
    // Ensure text is at least 10 characters long for API requirements
    if (mb_strlen($text) < 10) {
        $text .= " - - - - - - - - - -";
    }
    
    // Log the cleaned text
    wescraper_log("Cleaned Arabic text for API: " . mb_substr($text, 0, 50) . (mb_strlen($text) > 50 ? '...' : ''));
    
    return $text;
}

/**
 * Prepare a data structure for API submission with Arabic content
 * This function will recursively process all strings in the array
 */
function wescraper_prepare_api_data($data) {
    if (!is_array($data)) {
        return $data;
    }
    
    // First check that all required fields exist and handle missing data
    $required_fields = [
        'post_title', 'post_content', 'post_status', 'post_author', 'course_level'
    ];
    
    foreach ($required_fields as $field) {
        if (!isset($data[$field]) || empty($data[$field])) {
            // Set sensible defaults for missing fields
            switch ($field) {
                case 'post_title':
                    $data[$field] = 'Untitled Course ' . date('Y-m-d H:i:s');
                    break;
                case 'post_content':
                    $data[$field] = 'Course content created on ' . date('Y-m-d H:i:s');
                    break;
                case 'post_status':
                    $data[$field] = 'publish';
                    break;
                case 'post_author':
                    $data[$field] = 1;
                    break;
                case 'course_level':
                    $data[$field] = 'beginner';
                    break;
                default:
                    $data[$field] = 'Default value for ' . $field;
            }
            
            wescraper_log("Set default value for missing required field: " . $field);
        }
    }
    
    // For Arabic content, manually set post_title and post_content with English fallbacks to ensure API validation passes
    if (isset($data['post_title']) && preg_match('/[\x{0600}-\x{06FF}]/u', $data['post_title'])) {
        // Check and ensure post_title is not empty and at least 5 chars
        if (mb_strlen(trim($data['post_title'])) < 5) {
            $data['post_title'] = trim($data['post_title']) . ' - Course ' . date('Y-m-d H:i:s');
            wescraper_log("Added suffix to post_title to meet length requirements");
        }
        
        // Create a fallback title that includes original Arabic + English
        $data['post_title'] = trim($data['post_title']) . ' - Arabic Course ' . date('Y-m-d H:i:s');
        wescraper_log("Modified post_title with English suffix for API validation");
    }
    
    // For Arabic content, ensure post_content is long enough and has some English
    if (isset($data['post_content']) && preg_match('/[\x{0600}-\x{06FF}]/u', $data['post_content'])) {
        // Check and ensure post_content is not empty and at least 20 chars
        if (mb_strlen(trim($data['post_content'])) < 20) {
            $data['post_content'] = trim($data['post_content']) . ' - ' . 
                "Additional content details for this Arabic course. This course covers topics in depth and provides comprehensive learning material.";
            wescraper_log("Extended post_content to meet length requirements");
        }
        
    }
    
    // Check and ensure additional_content fields are properly formatted
    if (isset($data['additional_content']) && is_array($data['additional_content'])) {
        $additional_fields = [
            'course_benefits', 'course_target_audience', 'course_requirements', 'course_material_includes'
        ];
        
        foreach ($additional_fields as $field) {
            if (!isset($data['additional_content'][$field]) || empty($data['additional_content'][$field])) {
                $data['additional_content'][$field] = 'Default ' . str_replace('_', ' ', $field);
                wescraper_log("Set default value for missing additional content field: " . $field);
            }
            
            // For Arabic content, ensure it also has some English
            if (isset($data['additional_content'][$field]) && 
                is_string($data['additional_content'][$field]) && 
                preg_match('/[\x{0600}-\x{06FF}]/u', $data['additional_content'][$field])) {
                
                $data['additional_content'][$field] = trim($data['additional_content'][$field]) . 
                    "\n(This field contains Arabic content)";
                wescraper_log("Added English note to $field for API validation");
            }
        }
        
        // Ensure course_duration has hours and minutes as integers
        if (!isset($data['additional_content']['course_duration']) || 
            !is_array($data['additional_content']['course_duration'])) {
            $data['additional_content']['course_duration'] = ['hours' => 1, 'minutes' => 0];
        } else {
            // Make sure they're integers
            $data['additional_content']['course_duration']['hours'] = 
                (int)($data['additional_content']['course_duration']['hours'] ?? 1);
            $data['additional_content']['course_duration']['minutes'] = 
                (int)($data['additional_content']['course_duration']['minutes'] ?? 0);
        }
    } else {
        // Create a default additional_content structure
        $data['additional_content'] = [
            'course_benefits' => 'Course benefits',
            'course_target_audience' => 'All students',
            'course_requirements' => 'Basic knowledge',
            'course_material_includes' => 'Video lessons',
            'course_duration' => ['hours' => 1, 'minutes' => 0]
        ];
        wescraper_log("Created default additional_content structure");
    }
    
    // Force post_author to be an integer
    if (isset($data['post_author'])) {
        $data['post_author'] = (int)$data['post_author'];
    }
    
    // Ensure course_categories is an array of integers
    if (!isset($data['course_categories']) || !is_array($data['course_categories'])) {
        $data['course_categories'] = [1]; // Default category ID
    } else {
        // Convert each category ID to integer
        foreach ($data['course_categories'] as &$cat_id) {
            $cat_id = (int)$cat_id;
        }
    }
    
    // Ensure thumbnail_id is an integer
    if (!isset($data['thumbnail_id'])) {
        $data['thumbnail_id'] = 1; // Default thumbnail ID
    } else {
        $data['thumbnail_id'] = (int)$data['thumbnail_id'];
    }
    
    return $data;
}

/**
 * Generate Arabic Lorem Ipsum text for testing purposes
 * 
 * @param int $length Approximate length of text to generate (in characters)
 * @param bool $with_english Whether to include English translation hints
 * @return string Generated Arabic Lorem Ipsum text
 */
function wescraper_generate_arabic_lorem_ipsum($length = 200, $with_english = true) {
    // Array of Arabic Lorem Ipsum paragraphs from istizada.com
    $arabic_lorem_paragraphs = [
        "هذا النص هو مثال لنص يمكن أن يستبدل في نفس المساحة، لقد تم توليد هذا النص من مولد النص العربى، حيث يمكنك أن تولد مثل هذا النص أو العديد من النصوص الأخرى إضافة إلى زيادة عدد الحروف التى يولدها التطبيق.",
        "إذا كنت تحتاج إلى عدد أكبر من الفقرات يتيح لك مولد النص العربى زيادة عدد الفقرات كما تريد، النص لن يبدو مقسما ولا يحوي أخطاء لغوية، مولد النص العربى مفيد لمصممي المواقع على وجه الخصوص.",
        "حيث يحتاج العميل فى كثير من الأحيان أن يطلع على صورة حقيقية لتصميم الموقع، ومن هنا وجب على المصمم أن يضع نصوصا مؤقتة على التصميم ليظهر للعميل الشكل كاملاً، دور مولد النص العربى أن يوفر على المصمم عناء البحث عن نص بديل.",
        "هذا النص يمكن أن يتم تركيبه على أي تصميم دون مشكلة فلن يبدو وكأنه نص منسوخ، غير منظم، غير منسق، أو حتى غير مفهوم. لأنه مازال نصاً بديلاً ومؤقتاً.",
        "لوريم ايبسوم هو نموذج افتراضي يوضع في التصاميم لتعرض على العميل ليتصور طريقة وضع النصوص بالتصاميم سواء كانت تصاميم مطبوعه ... بروشور او فلاير على سبيل المثال ... او نماذج مواقع انترنت."
    ];
    
    // Array of English explanations to mix with Arabic text when requested
    $english_explanations = [
        "This is Arabic Lorem Ipsum text used for testing.",
        "The text above is a placeholder for demonstration purposes.",
        "This is dummy Arabic content for testing the API integration.",
        "Arabic placeholder text for website design and development.",
        "This course uses Arabic content with proper RTL formatting."
    ];
    
    // Randomly select and combine paragraphs until we reach approximate desired length
    $result = '';
    $current_length = 0;
    
    while ($current_length < $length) {
        // Pick a random paragraph
        $paragraph = $arabic_lorem_paragraphs[array_rand($arabic_lorem_paragraphs)];
        $result .= $paragraph . "\n\n";
        $current_length += mb_strlen($paragraph);
        
        // Add English explanation if requested
        if ($with_english && count($english_explanations) > 0) {
            $english = $english_explanations[array_rand($english_explanations)];
            $result .= $english . "\n\n";
        }
    }
    
    // Trim to roughly the desired length
    if (mb_strlen($result) > $length) {
        $result = mb_substr($result, 0, $length);
    }
    
    return trim($result);
}

/**
 * Special function to extract content from m3aarf iframe URLs
 * This handles the specific case of lesson description iframes
 * 
 * @param string $url The iframe URL to fetch content from
 * @return string The extracted and cleaned content
 */
function wescraper_extract_m3aarf_iframe_content($url) {
    wescraper_log("Extracting content from M3AARF iframe: " . $url);
    
    // Fetch the content
    $html = wescraper_fetch_url($url);
    if (empty($html)) {
        wescraper_log("Failed to fetch iframe content");
        return "Could not load content";
    }
    
    // Load the content with DOMDocument for better parsing
    libxml_use_internal_errors(true); // Suppress warnings for malformed HTML
    $dom = new DOMDocument('1.0', 'UTF-8');
    $html = mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8');
    @$dom->loadHTML('<?xml encoding="UTF-8">' . $html);
    libxml_clear_errors();
    
    $xpath = new DOMXPath($dom);
    
    // Try multiple strategies to find the content
    
    // Strategy 1: Look for articles or main content divs
    $contentNodes = $xpath->query('//article | //div[contains(@class, "content")] | //div[contains(@class, "m3aarf_card")]');
    
    if ($contentNodes && $contentNodes->length > 0) {
        $combinedContent = "";
        foreach ($contentNodes as $node) {
            $combinedContent .= $dom->saveHTML($node) . "\n";
        }
        
        // Clean up the extracted content
        $cleanContent = wescraper_clean_arabic_text($combinedContent);
        if (mb_strlen(trim($cleanContent)) > 20) {
            wescraper_log("Content extracted using strategy 1 (main content blocks)");
            return $cleanContent;
        }
    }
    
    // Strategy 2: Look for any div with reasonable content amount
    $divNodes = $xpath->query('//div[string-length(.) > 100]');
    
    if ($divNodes && $divNodes->length > 0) {
        // Get the div with the most content
        $bestNode = null;
        $maxLength = 0;
        
        foreach ($divNodes as $node) {
            $content = $node->textContent;
            $length = mb_strlen(trim($content));
            
            if ($length > $maxLength) {
                $maxLength = $length;
                $bestNode = $node;
            }
        }
        
        if ($bestNode) {
            $content = $dom->saveHTML($bestNode);
            $cleanContent = wescraper_clean_arabic_text($content);
            wescraper_log("Content extracted using strategy 2 (longest div): " . mb_substr($cleanContent, 0, 50) . "...");
            return $cleanContent;
        }
    }
    
    // Strategy 3: Fall back to simple body content extraction
    $bodyContent = "";
    $bodyNodes = $xpath->query('//body');
    
    if ($bodyNodes && $bodyNodes->length > 0) {
        $bodyContent = $dom->saveHTML($bodyNodes->item(0));
        // Clean and return
        $cleanContent = wescraper_clean_arabic_text($bodyContent);
        wescraper_log("Content extracted using strategy 3 (body fallback): " . mb_substr($cleanContent, 0, 50) . "...");
        return $cleanContent;
    }
    
    // If all else fails, just clean the entire HTML
    wescraper_log("All extraction strategies failed, using full page content");
    return wescraper_clean_arabic_text($html);
}

/**
 * Special function to extract content from mindluster iframe URLs
 * This handles the specific case of lesson extension iframes
 * 
 * @param string $url The iframe URL to fetch content from
 * @param string $cookies Cookies needed for mindluster authentication
 * @return string The extracted and cleaned content
 */
function wescraper_extract_mindluster_iframe_content($url, $cookies = '') {
    wescraper_log("Extracting iframe content from mindluster: " . $url);
    
    // Make sure the URL isn't empty
    if (empty($url)) {
        wescraper_log("URL is empty for mindluster iframe");
        return "No content available (empty URL)";
    }
    
    // Fetch the content from the iframe URL - mindluster requires cookies
    $html = wescraper_fetch_url($url, $cookies);
    
    if (!$html) {
        wescraper_log("Failed to fetch content from mindluster iframe URL");
        return "No content available (fetch failed)";
    }
    
    // First pass cleanup - remove most problematic elements
    $cleaned_html = $html;
    
    // Remove adsbygoogle code patterns - thorough cleanup
    $cleaned_html = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\{.*?\}\);/s', '', $cleaned_html);
    $cleaned_html = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\.push\(\s*\{\s*\}\s*\);/s', '', $cleaned_html);
    $cleaned_html = preg_replace('/\(adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\)\..+?;/s', '', $cleaned_html);
    $cleaned_html = preg_replace('/adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\];/s', '', $cleaned_html);
    
    // Remove head, script, and style tags
    $cleaned_html = preg_replace('/<head>.*?<\/head>/s', '', $cleaned_html);
    $cleaned_html = preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/<style\b[^>]*>.*?<\/style>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/\*\s*{\s*font-size:\s*\d+px;\s*}/', '', $cleaned_html);
    $cleaned_html = preg_replace('/\*\s*{\s*font[-\w]*:\s*[^}]+}/', '', $cleaned_html);
    
    // Remove ad-related elements
    $cleaned_html = preg_replace('/<ins\b[^>]*>.*?<\/ins>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/<div[^>]*class="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/<div[^>]*id="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/<iframe[^>]*>[^<]*<\/iframe>/is', '', $cleaned_html);
    $cleaned_html = preg_replace('/<a[^>]*href="[^"]*(?:admob|adsense|advert|banner|sponsor)[^"]*"[^>]*>.*?<\/a>/is', '', $cleaned_html);
    
    // Clean anything that looks like an adsbygoogle inline code
    $cleaned_html = preg_replace('/adsbygoogle.*window\.adsbygoogle.*\[\]\.push\(.*\);?/s', '', $cleaned_html);
    
    // Get the main content
    if (preg_match('/<body[^>]*>(.*?)<\/body>/is', $cleaned_html, $matches)) {
        $bodyContent = $matches[1];
    } else {
        $bodyContent = $cleaned_html;
    }
    
    // Extract meaningful content from the body
    $content = strip_tags($bodyContent);
    $content = wescraper_clean_text($content);
    
    // Check content length - if too short, it might not have worked properly
    if (mb_strlen(trim($content)) < 10) {
        wescraper_log("Warning: Extracted content from mindluster is very short, might not be valid");
        
        // Try a different approach, looking for specific divs
        $simpleHtml = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        @$simpleHtml->loadHTML('<?xml encoding="UTF-8">' . $cleaned_html);
        $xpath = new DOMXPath($simpleHtml);
        
        // Try method 1: Look for container div
        $contentDivs = $xpath->query('//div[@class="container"]');
        if ($contentDivs && $contentDivs->length > 0) {
            $extractedContent = '';
            foreach ($contentDivs as $div) {
                $extractedContent .= $simpleHtml->saveHTML($div);
            }
            
            // Second pass cleanup on extracted DOM content
            $extractedContent = preg_replace('/<ins\b[^>]*>.*?<\/ins>/is', '', $extractedContent);
            $extractedContent = preg_replace('/<div[^>]*class="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $extractedContent);
            $extractedContent = preg_replace('/<div[^>]*id="[^"]*ad[^"]*"[^>]*>.*?<\/div>/is', '', $extractedContent);
            $extractedContent = preg_replace('/<iframe[^>]*>[^<]*<\/iframe>/is', '', $extractedContent);
            $extractedContent = preg_replace('/adsbygoogle.*window\.adsbygoogle.*\[\]\.push\(.*\);?/s', '', $extractedContent);
            
            $content = strip_tags($extractedContent);
            $content = wescraper_clean_text($content);
            wescraper_log("Extracted content from mindluster using DOM approach (container div)");
        }
        
        // Try method 2: Look for any paragraph tags with content
        if (mb_strlen(trim($content)) < 10) {
            $paragraphs = $xpath->query('//p[string-length(normalize-space(.)) > 20]');
            if ($paragraphs && $paragraphs->length > 0) {
                $paragraphContent = '';
                foreach ($paragraphs as $p) {
                    $paragraphContent .= $simpleHtml->saveHTML($p) . "\n";
                }
                
                $content = strip_tags($paragraphContent);
                $content = wescraper_clean_text($content);
                wescraper_log("Extracted content from mindluster using paragraph tags");
            }
        }
    }
    
    // Final cleaning pass to remove any remaining adsbygoogle text
    $content = preg_replace('/adsbygoogle\s*=\s*window\.adsbygoogle\s*\|\|\s*\[\]\s*;?/i', '', $content);
    $content = preg_replace('/window\.adsbygoogle\s*\|\|\s*\[\]\.push\(.*?\);?/i', '', $content);
    
    // Remove excessive whitespace
    $content = preg_replace('/\s{2,}/', ' ', $content);
    $content = trim($content);
    
    wescraper_log("Content extracted successfully from mindluster iframe, length: " . mb_strlen($content));
    return nl2br($content);
}
?>