<?php
/*
Plugin Name: WeScraper
Description: A scraper integration for WordPress to scrape mindluster to tutor using API and curl.
Version: 1.2
Author: mZughbor
Author URI: https://mzughbor.github.io/portfolio/
Text Domain: wescraper
*/

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

// Register taxonomy early
function wescraper_register_taxonomy() {
    if (!taxonomy_exists('course_category')) {
        register_taxonomy('course_category', 'courses', [
            'label' => __('Course Categories'),
            'rewrite' => ['slug' => 'course-category'],
            'hierarchical' => true,
            'show_ui' => true,
            'show_admin_column' => true,
            'query_var' => true,
        ]);
        flush_rewrite_rules();
    }
}
add_action('init', 'wescraper_register_taxonomy', 0);

// Include necessary files
require_once plugin_dir_path(__FILE__) . 'includes/functions.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin.php';
require_once plugin_dir_path(__FILE__) . 'includes/log.php';
require_once plugin_dir_path(__FILE__) . 'includes/cookies.php';
require_once plugin_dir_path(__FILE__) . 'includes/tutor-api-client.php';
require_once plugin_dir_path(__FILE__) . 'includes/tutor-api-config.php';
require_once plugin_dir_path(__FILE__) . 'includes/category-name.php';
require_once plugin_dir_path(__FILE__) . 'includes/scrape_lessons.php';
require_once plugin_dir_path(__FILE__) . 'includes/upload_thumbnail.php';
require_once plugin_dir_path(__FILE__) . 'includes/new_site_scraper.php';

/**
 * Check for various files that might be created for Arabic content
 * and ensure they exist with valid JSON
 */
function wescraper_check_arabic_files() {
    $files_to_check = [
        'arabic_result.json',
        'arabic_lesson_data.json',
        'arabic_thumbnail.json',
        'arabic_thumbnail_id.json'
    ];
    
    $base_dir = plugin_dir_path(__FILE__);
    
    // Only create placeholder files if result.json exists AND has valid content
    if (file_exists($base_dir . 'result.json')) {
        $result_json = json_decode(file_get_contents($base_dir . 'result.json'), true);
        
        // Only proceed if result.json has valid content
        if (!is_array($result_json) || 
            !isset($result_json['course_name']) || 
            !isset($result_json['description']) || 
            !isset($result_json['lessons'])) {
            if (function_exists('wescraper_log')) {
                wescraper_log("Not creating Arabic placeholders because result.json is incomplete or invalid");
            }
            return;
        }
        
        // Check for Arabic content in result.json
        $has_arabic = false;
        if (isset($result_json['course_name'])) {
            $has_arabic = preg_match('/[\x{0600}-\x{06FF}]/u', $result_json['course_name']);
        }
        
        foreach ($files_to_check as $file) {
            $full_path = $base_dir . $file;
            
            // Only create placeholders if they don't already exist
            if (!file_exists($full_path)) {
                if ($has_arabic) {
                    // If Arabic content detected, copy result.json to arabic_result.json
                    if ($file === 'arabic_result.json') {
                        copy($base_dir . 'result.json', $full_path);
                        if (function_exists('wescraper_log')) {
                            wescraper_log("Copied result.json to arabic_result.json for Arabic content");
                        }
                    } else {
                        // For other files, create a more useful placeholder
                        switch ($file) {
                            case 'arabic_lesson_data.json':
                                // Create a minimal lesson data structure
                                $lessons = [];
                                foreach ($result_json['lessons'] as $lesson) {
                                    $lessons[] = [
                                        'title' => $lesson['title'] ?? '',
                                        'video_url' => '',
                                        'video_length' => $lesson['duration'] ?? '00:00:00',
                                        'content' => 'Lesson content'
                                    ];
                                }
                                $placeholder_data = $lessons;
                                break;
                            
                            case 'arabic_thumbnail.json':
                                $placeholder_data = [
                                    'thumbnail' => $result_json['thumbnail'] ?? '',
                                    'video_id' => ''
                                ];
                                break;
                                
                            case 'arabic_thumbnail_id.json':
                                $placeholder_data = [
                                    'thumbnail_id' => $result_json['thumbnail_id'] ?? 1,
                                    'thumbnail' => $result_json['thumbnail'] ?? ''
                                ];
                                break;
                                
                            default:
                                $placeholder_data = [
                                    'note' => 'This is a placeholder for Arabic content processing',
                                    'created_at' => date('Y-m-d H:i:s')
                                ];
                        }
                        
                        file_put_contents(
                            $full_path, 
                            json_encode($placeholder_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
                        );
                        
                        if (function_exists('wescraper_log')) {
                            wescraper_log("Created useful placeholder file for Arabic content: " . $file);
                        }
                    }
                }
            }
        }
    }
}

// Run the file check on plugin activation
register_activation_hook(__FILE__, 'wescraper_check_arabic_files');

// Run the check on admin_init to ensure files exist when needed
add_action('admin_init', 'wescraper_check_arabic_files');

// Add settings menu
function wescraper_settings_page() {
    add_options_page(
        'WeScraper Settings',
        'WeScraper',
        'manage_options',
        'wescraper-settings',
        'wescraper_settings_page_content'
    );
}
add_action('admin_menu', 'wescraper_settings_page');

function wescraper_settings_page_content() {
    if (isset($_POST['wescraper_api_key']) && isset($_POST['wescraper_api_secret'])) {
        update_option('wescraper_api_key', sanitize_text_field($_POST['wescraper_api_key']));
        update_option('wescraper_api_secret', sanitize_text_field($_POST['wescraper_api_secret']));
        echo '<div class="updated"><p>Settings saved.</p></div>';
    }
    $api_key = get_option('wescraper_api_key');
    $api_secret = get_option('wescraper_api_secret');
    ?>
    <div class="wrap">
        <h2>WeScraper Settings</h2>
        <form method="post">
            <table class="form-table">
                <tr valign="top">
                    <th scope="row">API Key</th>
                    <td><input type="text" name="wescraper_api_key" value="<?php echo esc_attr($api_key); ?>" /></td>
                </tr>
                <tr valign="top">
                    <th scope="row">API Secret</th>
                    <td><input type="text" name="wescraper_api_secret" value="<?php echo esc_attr($api_secret); ?>" /></td>
                </tr>
            </table>
            <?php submit_button(); ?>
        </form>
    </div>
    <?php
}

?>