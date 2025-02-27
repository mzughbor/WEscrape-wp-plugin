<?php
namespace WeScraper;

class CategoryManager {
    private $category_ids = [];
    private $json_file;

    public function __construct() {
        $this->json_file = WESCRAPER_PLUGIN_DIR . 'data/categories.json';
        $this->load_categories();
    }

    private function load_categories() {
        if (!file_exists($this->json_file)) {
            throw new \Exception("Categories file not found: " . $this->json_file);
        }

        $json_content = file_get_contents($this->json_file);
        $this->category_ids = json_decode($json_content, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \Exception("Error parsing categories JSON: " . json_last_error_msg());
        }
    }

    private function save_categories() {
        if (!is_writable(dirname($this->json_file))) {
            throw new \Exception("Directory is not writable: " . dirname($this->json_file));
        }

        $json_content = json_encode($this->category_ids, JSON_PRETTY_PRINT);
        if ($json_content === false) {
            throw new \Exception("Error encoding categories to JSON");
        }

        if (file_put_contents($this->json_file, $json_content) === false) {
            throw new \Exception("Error saving categories to JSON file");
        }
    }

    public function get_category_id($category_name) {
        // Normalize category name to match the format in JSON
        $category_name = str_replace(' ', '-', $category_name);
        
        if (isset($this->category_ids[$category_name])) {
            return $this->category_ids[$category_name];
        }

        // If category doesn't exist, create it
        $category_id = $this->create_category($category_name);
        
        // Store the new category
        $this->category_ids[$category_name] = $category_id;
        $this->save_categories();

        return $category_id;
    }

    private function create_category($name) {
        // Create category in WordPress
        $display_name = str_replace('-', ' ', $name);
        $category_id = wp_insert_term($display_name, 'course-category');
        
        if (is_wp_error($category_id)) {
            throw new \Exception("Failed to create category: " . $category_id->get_error_message());
        }

        return $category_id['term_id'];
    }

    public function get_all_categories() {
        return $this->category_ids;
    }
} 