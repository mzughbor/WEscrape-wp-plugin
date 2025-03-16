<?php
/*
Plugin Name: WeScraper
Description: A scraper integration for WordPress to scrape mindluster to tutor using API and curl.
Version: 1.1
Author: mZughbor
Author URI: https://mzughbor.github.io/old-portfolio/
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