<?php
namespace WeScraper;

class Init {
    private static $instance = null;

    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function load_dependencies() {
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-config.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-scraper.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-category-helper.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/class-tutor-api-client.php';
        require_once WESCRAPER_PLUGIN_DIR . 'includes/tutor-api-config.php';
        
        if (is_admin()) {
            require_once WESCRAPER_PLUGIN_DIR . 'admin/class-admin.php';
            require_once WESCRAPER_PLUGIN_DIR . 'admin/class-settings.php';
        }
    }

    private function init_hooks() {
        add_action('init', [$this, 'init_plugin']);
        
        if (is_admin()) {
            new Admin();
            new Settings();
        }
    }

    public function init_plugin() {
        load_plugin_textdomain('wescraper', false, dirname(WESCRAPER_PLUGIN_BASENAME) . '/languages');
    }
} 