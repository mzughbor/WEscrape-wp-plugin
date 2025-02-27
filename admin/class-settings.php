<?php
namespace WeScraper;

class Settings {
    private $options;

    public function __construct() {
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function register_settings() {
        register_setting('wescraper_options', 'wescraper_settings', [
            'sanitize_callback' => [$this, 'sanitize_settings']
        ]);

        add_settings_section(
            'wescraper_main',
            'Main Settings',
            [$this, 'section_callback'],
            'wescraper'
        );

        add_settings_field(
            'course_url',
            'Course URL',
            [$this, 'url_callback'],
            'wescraper',
            'wescraper_main'
        );
    }

    public function sanitize_settings($input) {
        $new_input = [];
        
        if (isset($input['course_url'])) {
            $new_input['course_url'] = esc_url_raw($input['course_url']);
        }
        
        return $new_input;
    }

    public function section_callback() {
        echo '<p>Configure your scraper settings here.</p>';
    }

    public function url_callback() {
        $value = isset($this->options['course_url']) ? $this->options['course_url'] : '';
        echo '<input type="url" id="course_url" name="wescraper_settings[course_url]" value="' . esc_attr($value) . '" class="regular-text">';
    }
} 