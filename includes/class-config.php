<?php
namespace WeScraper;

class Config {
    private static $instance = null;
    private $settings;

    private function __construct() {
        $this->settings = get_option('wescraper_settings', []);
    }

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function get($key, $default = null) {
        return isset($this->settings[$key]) ? $this->settings[$key] : $default;
    }

    public function set($key, $value) {
        $this->settings[$key] = $value;
        update_option('wescraper_settings', $this->settings);
    }

    public function get_all() {
        return $this->settings;
    }

    public function get_api_url() {
        return get_rest_url(null, 'wescraper/v1');
    }

    public function get_nonce() {
        return wp_create_nonce('wp_rest');
    }
} 