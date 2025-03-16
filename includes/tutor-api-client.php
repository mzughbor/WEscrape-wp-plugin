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
            echo "[DEBUG] " . $message . "\n";
        }
    }
    
    public function makeRequest($endpoint, $method = 'GET', $data = null) {
        $url = TutorAPIConfig::API_BASE_URL . $endpoint;
        
        $this->debug("Making $method request to: $url");
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        
        // Set up Basic Auth
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $this->api_key . ':' . $this->api_secret);
        
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json'
        ];
        
        //$this->debug("Headers: " . print_r($headers, true));
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, 1);
            if ($data) {
                $json_data = json_encode($data);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $json_data);
                //$this->debug("POST Data: " . $json_data);
            }
        } elseif ($method === 'PUT') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        } elseif ($method === 'DELETE') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        }
        
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        // Enable curl debug information
        curl_setopt($ch, CURLOPT_VERBOSE, true);
        $verbose = fopen('php://temp', 'w+');
        curl_setopt($ch, CURLOPT_STDERR, $verbose);
        
        $response = curl_exec($ch);
        $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        
        // Get curl debug information
        rewind($verbose);
        $verbose_log = stream_get_contents($verbose);
        //$this->debug("Curl verbose output: " . $verbose_log);
        
        $this->last_response = $response;
        
        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new Exception("Curl error: $error\nURL: $url\nMethod: $method");
        }
        
        curl_close($ch);
        
        $this->debug("API Response Code: " . $http_code);
        $this->debug("API Response Body: " . $response);
        
        if ($http_code >= 400) {
            throw new Exception('API request failed with status code: ' . $http_code . ' Response: ' . $response);
        }
        
        $decoded_response = json_decode($response, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new Exception('Invalid JSON response: ' . $response);
        }
        
        return $decoded_response;
    }
    
    public function getLastResponse() {
        return $this->last_response;
    }
}