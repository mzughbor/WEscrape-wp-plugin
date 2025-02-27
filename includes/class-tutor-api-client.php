<?php
namespace WeScraper;

class TutorAPIClient {
    private $config;

    public function __construct() {
        $this->config = $this->load_config();
    }

    private function load_config() {
        $config_file = WESCRAPER_PLUGIN_DIR . 'includes/tutor-api-config.php';
        if (!file_exists($config_file)) {
            throw new \Exception("API config file not found!");
        }
        require_once $config_file;
        return new TutorAPIConfig();
    }

    public function create_course($data) {
        return $this->makeRequest(
            $this->config->get_endpoint('courses', 'create'),
            'POST',
            $data
        );
    }

    public function create_topic($data) {
        return $this->makeRequest(
            $this->config->get_endpoint('topics', 'create'),
            'POST',
            $data
        );
    }

    public function create_lesson($data) {
        return $this->makeRequest(
            $this->config->get_endpoint('lessons', 'create'),
            'POST',
            $data
        );
    }

    public function update_course($course_id, $data) {
        return $this->makeRequest(
            $this->config->get_endpoint('courses', 'update', $course_id),
            'PUT',
            $data
        );
    }

    public function delete_course($course_id) {
        return $this->makeRequest(
            $this->config->get_endpoint('courses', 'delete', $course_id),
            'DELETE'
        );
    }

    public function get_course($course_id) {
        return $this->makeRequest(
            $this->config->get_endpoint('courses', 'get', $course_id),
            'GET'
        );
    }

    public function get_categories() {
        return $this->makeRequest(
            $this->config->get_endpoint('categories', 'get_all'),
            'GET'
        );
    }

    public function makeRequest($endpoint, $method = 'GET', $data = null) {
        try {
            $this->config->validate_config();
            
            $url = $this->config->get_base_url() . $endpoint;
            
            $args = [
                'method' => $method,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->config->get_token(),
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json'
                ],
                'timeout' => 30,
                'sslverify' => false // Only if needed for local development
            ];

            if ($data !== null) {
                $args['body'] = json_encode($data);
            }

            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                throw new \Exception('API request failed: ' . $response->get_error_message());
            }

            $status_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            $result = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Invalid JSON response from API');
            }

            // Handle different status codes
            if ($status_code >= 400) {
                $error_message = isset($result['message']) ? $result['message'] : 'Unknown error';
                throw new \Exception("API error ({$status_code}): {$error_message}");
            }

            // Handle specific Tutor LMS API responses
            if (isset($result['success']) && $result['success'] === false) {
                throw new \Exception("API operation failed: " . ($result['message'] ?? 'Unknown error'));
            }

            return $result;

        } catch (\Exception $e) {
            // Log the error if needed
            error_log("Tutor API Error: " . $e->getMessage());
            throw $e;
        }
    }

    public function validate_connection() {
        try {
            // Try to get categories as a simple API test
            $this->get_categories();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
} 