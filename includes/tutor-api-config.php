<?php
namespace WeScraper;

class TutorAPIConfig {
    private $api_base_url;
    private $api_key;
    private $api_secret;
    private $endpoints;

    public function __construct() {
        $this->api_base_url = Settings::get_api_base_url();
        $this->api_key = Settings::get_api_key();
        $this->api_secret = Settings::get_api_secret();
        $this->init_endpoints();
    }

    private function init_endpoints() {
        $this->endpoints = [
            'courses' => [
                'create' => 'courses',
                'update' => 'courses/%d',
                'delete' => 'courses/%d',
                'get' => 'courses/%d',
            ],
            'topics' => [
                'create' => 'topics',
                'update' => 'topics/%d',
                'delete' => 'topics/%d',
                'get' => 'topics/%d',
            ],
            'lessons' => [
                'create' => 'lessons',
                'update' => 'lessons/%d',
                'delete' => 'lessons/%d',
                'get' => 'lessons/%d',
            ],
            'categories' => [
                'create' => 'categories',
                'get_all' => 'categories',
            ]
        ];
    }

    public function get_base_url() {
        return $this->api_base_url;
    }

    public function get_token() {
        // Generate token using API key and secret if needed
        return $this->api_key;
    }

    public function get_endpoint($type, $action = 'create', $id = null) {
        if (!isset($this->endpoints[$type]) || !isset($this->endpoints[$type][$action])) {
            throw new \Exception("Invalid endpoint type or action: $type/$action");
        }

        $endpoint = $this->endpoints[$type][$action];
        
        if ($id !== null) {
            $endpoint = sprintf($endpoint, $id);
        }

        return $endpoint;
    }

    public function update_token($token) {
        $this->api_token = $token;
        update_option('tutor_api_token', $token);
    }

    public function update_base_url($url) {
        $this->api_base_url = $url;
        update_option('tutor_api_base_url', $url);
    }

    public function validate_config() {
        if (empty($this->api_key)) {
            throw new \Exception('API key is not configured');
        }

        if (empty($this->api_secret)) {
            throw new \Exception('API secret is not configured');
        }

        if (empty($this->api_base_url)) {
            throw new \Exception('API base URL is not configured');
        }

        return true;
    }
} 