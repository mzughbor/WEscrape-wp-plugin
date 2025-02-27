<?php
/**
 * Plugin Name: WeScraper
 * Description: A scraper integration for WordPress to scrape mindluster to tutor using API and curl.
 * Version: 1.0
 * Author: mZughbor
 * Text Domain: wescraper
 */

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('WESCRAPER_VERSION', '1.0.0');
define('WESCRAPER_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WESCRAPER_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WESCRAPER_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader for plugin classes
spl_autoload_register(function ($class) {
    $prefix = 'WeScraper\\';
    $base_dir = WESCRAPER_PLUGIN_DIR . 'includes/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// Initialize plugin
function wescraper_init() {
    require_once WESCRAPER_PLUGIN_DIR . 'includes/class-init.php';
    return WeScraper\Init::get_instance();
}

// Start the plugin
add_action('plugins_loaded', 'wescraper_init');

// Activation hook
register_activation_hook(__FILE__, 'wescraper_activate');
function wescraper_activate() {
    // Create necessary directories
    $directories = [
        WESCRAPER_PLUGIN_DIR . 'data',
        WESCRAPER_PLUGIN_DIR . 'data/created_courses',
    ];

    foreach ($directories as $dir) {
        if (!file_exists($dir)) {
            wp_mkdir_p($dir);
        }
    }
    
    // Initialize categories.json if it doesn't exist
    $categories_file = WESCRAPER_PLUGIN_DIR . 'data/categories.json';
    if (!file_exists($categories_file)) {
        $default_categories = [
            'Business' => 33,
            'Cooking' => 35,
            'Digital Marketing' => 37,
            'Fitness' => 39,
            'Graphic Design' => 96,
            'Kitchen and Cooking' => 95,
            'Languages' => 128,
            'Marketing' => 97,
            'Motivation' => 48,
            'Online Art' => 49,
            'Photography' => 52,
            'Programming' => 53,
            'Mobile Development' => 127,
            'Yoga' => 57,
            'Computer-Softwares' => 137,
            'Engineering' => 138,
            'Computer-Science' => 139,
            'Music' => 140,
            'woman-and-Beauty' => 141,
            'Drawing' => 142,
            'Mathematics' => 143,
            'Web-Design' => 144
        ];
        
        file_put_contents($categories_file, json_encode($default_categories, JSON_PRETTY_PRINT));
    }

    // Create .htaccess to protect data directory
    $htaccess_file = WESCRAPER_PLUGIN_DIR . 'data/.htaccess';
    if (!file_exists($htaccess_file)) {
        $htaccess_content = "Order deny,allow\nDeny from all";
        file_put_contents($htaccess_file, $htaccess_content);
    }

    // Create index.php to prevent directory listing
    $index_file = WESCRAPER_PLUGIN_DIR . 'data/index.php';
    if (!file_exists($index_file)) {
        file_put_contents($index_file, '<?php // Silence is golden');
    }
}

// Deactivation hook
register_deactivation_hook(__FILE__, 'wescraper_deactivate');
function wescraper_deactivate() {
    // Cleanup if needed
} 