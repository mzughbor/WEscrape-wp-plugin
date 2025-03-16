<?php
if (!defined('ABSPATH')) {
    exit;
}

require_once plugin_dir_path(__FILE__) . 'log.php';

class CategoryManager {
    private $taxonomy = 'course-category'; // Default taxonomy name for Tutor LMS

    public function __construct() {
        // Use a safer way to get the taxonomy name
        if (function_exists('tutor') && is_object(tutor()) && property_exists(tutor(), 'course_category')) {
            $this->taxonomy = tutor()->course_category;
        }
        wescraper_log("CategoryManager initialized with taxonomy: " . $this->taxonomy);
    }

    public function get_category_id($category_name) {
        global $wpdb;
        
        if (empty($category_name)) {
            wescraper_log("Error: Category name is empty.");
            return false;
        }
        
        wescraper_log("Checking category: " . $category_name . " in taxonomy: " . $this->taxonomy);

        // Check if the category exists using direct database query for reliability
        $term_id = $wpdb->get_var($wpdb->prepare(
            "SELECT t.term_id FROM {$wpdb->terms} t 
             JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id 
             WHERE t.name = %s AND tt.taxonomy = %s",
            $category_name,
            $this->taxonomy
        ));
        
        if ($term_id) {
            wescraper_log("Category '" . $category_name . "' exists with ID: " . $term_id);
            return intval($term_id);
        } else {
            wescraper_log("Category '" . $category_name . "' does not exist. Attempting to create.");

            // Create category if not found
            $term = wp_insert_term(
                $category_name,
                $this->taxonomy,
                array(
                    'description' => 'Category for ' . $category_name . ' courses',
                    'slug' => sanitize_title($category_name)
                )
            );

            if (!is_wp_error($term) && isset($term['term_id'])) {
                wescraper_log("Category '" . $category_name . "' created successfully. Term ID: " . intval($term['term_id']));
                return intval($term['term_id']);
            } else {
                $error_message = is_wp_error($term) ? $term->get_error_message() : 'Unknown error';
                wescraper_log("Failed to create category: " . $category_name . " - Error: " . $error_message);
                
                // If we can't create the category, try to use an existing one as fallback
                $default_term_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT tt.term_id FROM {$wpdb->term_taxonomy} tt WHERE tt.taxonomy = %s LIMIT 1",
                    $this->taxonomy
                ));
                
                if ($default_term_id) {
                    wescraper_log("Using default category with ID: " . $default_term_id . " as fallback");
                    return intval($default_term_id);
                }
                
                return false;
            }
        }
    }
    
    // Add a method to set the category for a course after creation
    public function set_course_category($course_id, $category_id) {
        if (!$course_id || !$category_id) {
            wescraper_log("Error: Invalid course ID or category ID");
            return false;
        }
        
        wescraper_log("Setting category ID " . $category_id . " for course ID " . $course_id);
        
        // Set the course category using WordPress taxonomy functions
        $result = wp_set_object_terms($course_id, intval($category_id), $this->taxonomy);
        
        if (is_wp_error($result)) {
            wescraper_log("Error setting category: " . $result->get_error_message());
            return false;
        }
        
        wescraper_log("Category successfully set for course");
        return true;
    }
    
    // Method to get the taxonomy name
    public function get_taxonomy_name() {
        return $this->taxonomy;
    }
}
