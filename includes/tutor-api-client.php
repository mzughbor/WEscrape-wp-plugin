<?php
require_once plugin_dir_path(__FILE__) . 'tutor-api-config.php';

class TutorAPIClient {
    private $api_key;
    private $api_secret;
    private $last_response;
    private $debug = true;

    public function __construct($api_key = null, $api_secret = null) {
        $this->api_key = $api_key ?? get_option('wescraper_api_key');
        $this->api_secret = $api_secret ?? get_option('wescraper_api_secret');
    }

    private function debug($message) {
        if ($this->debug) {
            error_log("[DEBUG] " . $message);
            wescraper_log("[API DEBUG] " . $message);
        }
    }
    
    public function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = TutorAPIConfig::API_BASE_URL . $endpoint;
        
        $this->debug("Making $method request to: $url");
        
        // Show API credentials debug (partial)
        $this->debug("Using API credentials - Key: " . substr($this->api_key, 0, 5) . '...' . substr($this->api_key, -5) . 
                     ", Secret: " . substr($this->api_secret, 0, 3) . '...');
        
        // For POST requests, ensure required fields are present and properly formatted
        if ($method === 'POST' && $endpoint === TutorAPIConfig::ENDPOINT_COURSES && is_array($data)) {
            // Validate required field presence
            $required_fields = [
                'post_author' => 1,
                'post_content' => 'Course content',
                'post_title' => 'Untitled Course',
                'post_status' => 'publish',
                'course_level' => 'beginner'
            ];
            
            foreach ($required_fields as $field => $default) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    $this->debug("Adding missing required field: $field with default value");
                    $data[$field] = $default;
                }
            }
            
            // Convert non-string fields to strings for validation
            if (isset($data['post_author']) && !is_string($data['post_author'])) {
                $data['post_author'] = (string)$data['post_author'];
                $this->debug("Converted post_author to string: " . $data['post_author']);
            }
        }
        
        // Initialize cURL
        $ch = curl_init();
        
        // Common cURL options
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Increase timeouts to prevent premature failure
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
        curl_setopt($ch, CURLOPT_TIMEOUT, 60); // 60 second timeout for API requests
        
        // Set up Basic Auth
        $auth_header = 'Authorization: Basic ' . base64_encode($this->api_key . ':' . $this->api_secret);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            $auth_header
        ));
        
        // Configure request based on HTTP method
        if ($method === 'GET') {
            // For GET requests, add parameters to URL
            if ($data && is_array($data)) {
                $url .= '?' . http_build_query($data);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
        } else {
            // Handle request body for non-GET requests
            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
            } else {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            }
            
            // If we have data, encode and add to request
            if ($data) {
                // Ensure we don't have invalid UTF-8 sequences
                $json_data = null;
                
                try {
                    // Try with regular JSON encoding first
                    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE);
                    
                    if ($json_data === false) {
                        $this->debug("ERROR: JSON encoding failed: " . json_last_error_msg());
                        
                        // Try to recursively clean each field
                        $cleaned_data = $this->recursivelyCleanData($data);
                        $json_data = json_encode($cleaned_data, JSON_UNESCAPED_UNICODE);
                        
                        if ($json_data === false) {
                            $this->debug("ERROR: JSON encoding still failed after cleaning");
                            $json_data = json_encode(["error" => "JSON encoding failed"]);
                        } else {
                            $this->debug("JSON encoding succeeded after cleaning data");
                        }
                    }
                } catch (Exception $e) {
                    $this->debug("Exception during JSON encoding: " . $e->getMessage());
                    $json_data = json_encode(["error" => "JSON encoding exception"]);
                }
                
                $this->debug("JSON data to be sent: " . substr($json_data, 0, 500) . (strlen($json_data) > 500 ? '...' : ''));
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
            }
        }
        
        // Execute the request
        $response_body = curl_exec($ch);
        $response_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Check for cURL errors
        if (curl_errno($ch)) {
            $curl_error = curl_error($ch);
            $this->debug("cURL Error: " . $curl_error);
            curl_close($ch);
            throw new Exception("API request failed: " . $curl_error);
        }
        
        curl_close($ch);
        
        $this->debug("API Response Code: " . $response_code);
        $this->debug("API Response Body: " . substr($response_body, 0, 500) . (strlen($response_body) > 500 ? '...' : ''));
        
        // Save the last response
        $this->last_response = [
            'code' => $response_code,
            'body' => $response_body
        ];
        
        // Parse JSON response
        $response_data = json_decode($response_body, true);
        
        // Handle non-OK responses
        if ($response_code < 200 || $response_code >= 300) {
            if (isset($response_data['message'])) {
                throw new Exception("API request failed with status code: " . $response_code . " Response: " . $response_body);
            } else {
                throw new Exception("API request failed with status code: " . $response_code);
            }
        }
        
        return $response_data;
    }
    
    /**
     * Recursively clean data to ensure valid UTF-8 for JSON encoding
     */
    private function recursivelyCleanData($data) {
        if (is_string($data)) {
            // Ensure string is valid UTF-8
            return mb_convert_encoding($data, 'UTF-8', 'UTF-8');
        } elseif (is_array($data)) {
            // Process array items
            $result = [];
            foreach ($data as $key => $value) {
                $result[$key] = $this->recursivelyCleanData($value); 
            }
            return $result;
        } elseif (is_object($data)) {
            // Convert objects to arrays and process
            return $this->recursivelyCleanData((array)$data);
        } else {
            // Return numeric and other types as is
            return $data;
        }
    }
    
    public function getLastResponse() {
        return $this->last_response;
    }
}