<?php
namespace WeScraper;

class TutorAPIConfig {
    private $api_base_url;
    private $api_token;
    private $endpoints;

    public function __construct() {
        $this->api_base_url = get_option('tutor_api_base_url', site_url('/wp-json/tutor/v1/'));
        $this->api_token = get_option('tutor_api_token', '');
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
        return $this->api_token;
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
        if (empty($this->api_token)) {
            throw new \Exception('API token is not configured');
        }

        if (empty($this->api_base_url)) {
            throw new \Exception('API base URL is not configured');
        }

        return true;
    }
} 