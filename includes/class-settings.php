<?php
namespace WeScraper;

class Settings {
    const OPTION_GROUP = 'wescraper_settings';
    
    public static function init() {
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function register_settings() {
        register_setting(self::OPTION_GROUP, 'tutor_api_key');
        register_setting(self::OPTION_GROUP, 'tutor_api_secret');
        register_setting(self::OPTION_GROUP, 'tutor_api_base_url');
        register_setting(self::OPTION_GROUP, 'wp_app_password');
    }

    public static function get_api_key() {
        return get_option('tutor_api_key', '');
    }

    public static function get_api_secret() {
        return get_option('tutor_api_secret', '');
    }

    public static function get_api_base_url() {
        return get_option('tutor_api_base_url', site_url('/wp-json/tutor/v1/'));
    }

    public static function get_app_password() {
        return get_option('wp_app_password', '');
    }
} 