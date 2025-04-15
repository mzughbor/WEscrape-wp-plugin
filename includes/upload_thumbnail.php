<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
require_once plugin_dir_path(__FILE__) . 'functions.php'; // Include functions.php
require_once plugin_dir_path(__FILE__) . 'log.php'; // Include log.php

function wescraper_upload_thumbnail($siteType = '') {
    wescraper_log("wescraper_upload_thumbnail() started for site type: " . $siteType);

    try {
        // Choose the correct JSON file based on site type
        if ($siteType === 'm3aarf') {
            // For Arabic courses, use the separate thumbnail file
            $json_file = plugin_dir_path(dirname(__FILE__)) . 'arabic_thumbnail.json';
            $result_file = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
            
            wescraper_log("Using Arabic-specific files: " . $json_file);
        } else {
            // For other sites, use the regular result.json
            $json_file = plugin_dir_path(dirname(__FILE__)) . 'result.json';
            $result_file = $json_file;
        }
        
        wescraper_log("JSON file path: " . $json_file);

        if (!file_exists($json_file)) {
            wescraper_log("Thumbnail JSON file not found: " . $json_file);
            
            if ($siteType === 'm3aarf' && file_exists(plugin_dir_path(dirname(__FILE__)) . 'result.json')) {
                // Fallback to result.json for Arabic sites
                $json_file = plugin_dir_path(dirname(__FILE__)) . 'result.json';
                wescraper_log("Falling back to result.json for Arabic site");
            } else {
                throw new Exception("Thumbnail JSON file not found!");
            }
        }

        $json_data = json_decode(file_get_contents($json_file), true);
        wescraper_log("JSON data decoded from " . $json_file);

        // Check if we're using the Arabic thumbnail file which has different structure
        if ($siteType === 'm3aarf' && isset($json_data['video_id'])) {
            $video_id = $json_data['video_id'];
            $thumbnail_url = $json_data['thumbnail'];
            wescraper_log("Using Arabic thumbnail data: video_id=" . $video_id);
        } else if (!isset($json_data['thumbnail'])) {
            wescraper_log("No thumbnail URL found in JSON file");
            throw new Exception("No thumbnail URL found in JSON file");
        } else {
            // Standard processing for result.json
            // Extract video ID from different possible URL formats
            $video_id = null;
            $thumbnail_url = $json_data['thumbnail'];
            wescraper_log("Original thumbnail URL: " . $thumbnail_url);
            
            // Case 1: Standard YouTube thumbnail URL format (vi/VIDEO_ID/hqdefault.jpg)
            if (preg_match('/vi\/([^\/]+)\/hqdefault\.jpg/', $thumbnail_url, $matches)) {
                $video_id = $matches[1];
                wescraper_log("Video ID extracted from standard thumbnail URL: " . $video_id);
            } 
            // Case 2: YouTube video URL format (watch?v=VIDEO_ID)
            else if (preg_match('/watch\?v=([^&]+)/', $thumbnail_url, $matches)) {
                $video_id = $matches[1];
                wescraper_log("Video ID extracted from watch URL: " . $video_id);
                // Construct proper thumbnail URL
                $thumbnail_url = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
                wescraper_log("Constructed thumbnail URL: " . $thumbnail_url);
            }
            // Case 3: YouTube embed URL format (embed/VIDEO_ID)
            else if (preg_match('/embed\/([^\/\?]+)/', $thumbnail_url, $matches)) {
                $video_id = $matches[1];
                wescraper_log("Video ID extracted from embed URL: " . $video_id);
                // Construct proper thumbnail URL
                $thumbnail_url = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
                wescraper_log("Constructed thumbnail URL: " . $thumbnail_url);
            }
            // Case 4: Direct video ID
            else if (preg_match('/^[a-zA-Z0-9_-]{11}$/', $thumbnail_url)) {
                $video_id = $thumbnail_url;
                wescraper_log("Direct video ID detected: " . $video_id);
                // Construct proper thumbnail URL
                $thumbnail_url = "https://img.youtube.com/vi/{$video_id}/hqdefault.jpg";
                wescraper_log("Constructed thumbnail URL: " . $thumbnail_url);
            }
            // Case 5: Unknown format - can't extract video ID
            else {
                wescraper_log("Could not extract video ID from thumbnail URL. Using original URL.");
                // We'll continue with the original URL and hope it works
            }
        }

        $temp_dir = wp_upload_dir()['basedir'] . '/wescraper_temp';
        wescraper_log("Upload directory: " . print_r($temp_dir, true));

        wescraper_log("Temp directory: " . $temp_dir);

        if (!file_exists($temp_dir)) {
            wp_mkdir_p($temp_dir);
            wescraper_log("Temp directory created");
        }

        // Use video ID for filename if available, otherwise use a timestamp
        $filename = $video_id ? $video_id . '.jpg' : 'thumbnail_' . time() . '.jpg';
        $temp_file = $temp_dir . '/' . $filename;
        wescraper_log("Temp file path: " . $temp_file);

        // Download the thumbnail image
        $image_content = @file_get_contents($thumbnail_url);
        if (!$image_content) {
            wescraper_log("Failed to download thumbnail image from: " . $thumbnail_url);
            throw new Exception("Failed to download thumbnail image");
        }

        if (!file_put_contents($temp_file, $image_content)) {
            wescraper_log("Failed to save thumbnail image to: " . $temp_file);
            throw new Exception("Failed to save thumbnail image");
        }

        wescraper_log("Thumbnail downloaded successfully");
        echo "✅ Thumbnail downloaded successfully<br>";
        echo "Temp file path: " . $temp_file . "<br>";
        echo "Temp file exists: " . (file_exists($temp_file) ? "Yes" : "No") . "<br>";
        echo "Temp file size: " . filesize($temp_file) . "<br>";


        // Add these checks
        if (!file_exists($temp_file)) {
            wescraper_log("Error: Temp file does not exist after download.");
            throw new Exception("Error: Temp file does not exist after download.");
        }

        if (filesize($temp_file) <= 0) {
            wescraper_log("Error: Temp file size is zero or invalid.");
            throw new Exception("Error: Temp file size is zero or invalid.");
        }


        $upload_file = array(
            'name' => $filename,
            'type' => 'image/jpeg',
            'tmp_name' => $temp_file,
            'error' => 0,
            'size' => filesize($temp_file),
        );

        wescraper_log("Upload file array created");

        //$upload_overrides = array();
        $upload_overrides = array('test_form' => false);


        wescraper_log("Upload File: " . print_r($upload_file, true));


        //$movefile = wp_handle_upload($upload_file, $upload_overrides);
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $attachment_id = media_handle_sideload($upload_file, 0);
        if (is_wp_error($attachment_id)) {
            throw new Exception("Media upload failed: " . $attachment_id->get_error_message());
        }
        
        wescraper_log("media_handle_sideload() called, Attachment ID: " . $attachment_id);
        echo "✅ Thumbnail uploaded successfully with ID: " . $attachment_id . "<br>";
        
        // Save attachment ID to all necessary files to ensure it's available everywhere
        // Save to result.json first (primary file) while preserving existing data
        $result_json_path = plugin_dir_path(dirname(__FILE__)) . 'result.json';
        if (file_exists($result_json_path)) {
            $result_data = json_decode(file_get_contents($result_json_path), true);
            if (is_array($result_data)) {
                // Preserve all existing data, just update thumbnail fields
                $result_data['thumbnail_id'] = $attachment_id;
                if (!isset($result_data['thumbnail']) || empty($result_data['thumbnail'])) {
                    $result_data['thumbnail'] = $thumbnail_url;
                }
                file_put_contents($result_json_path, 
                    json_encode($result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                );
                wescraper_log("Thumbnail ID saved to result.json while preserving existing data");
            }
        }
        
        // Save to arabic_thumbnail_id.json for compatibility with Arabic sites
        $arabic_thumbnail_id_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_thumbnail_id.json';
        $thumbnail_id_data = ['thumbnail_id' => $attachment_id, 'thumbnail' => $thumbnail_url];
        file_put_contents(
            $arabic_thumbnail_id_path,
            json_encode($thumbnail_id_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
        );
        wescraper_log("Thumbnail ID saved to arabic_thumbnail_id.json");
        
        // If this is an Arabic site, also save to arabic_result.json if it exists
        if ($siteType === 'm3aarf') {
            $arabic_result_path = plugin_dir_path(dirname(__FILE__)) . 'arabic_result.json';
            if (file_exists($arabic_result_path)) {
                $arabic_result_data = json_decode(file_get_contents($arabic_result_path), true);
                if (is_array($arabic_result_data)) {
                    $arabic_result_data['thumbnail_id'] = $attachment_id;
                    if (!isset($arabic_result_data['thumbnail']) || empty($arabic_result_data['thumbnail'])) {
                        $arabic_result_data['thumbnail'] = $thumbnail_url;
                    }
                    file_put_contents($arabic_result_path, 
                        json_encode($arabic_result_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                    );
                    wescraper_log("Thumbnail ID saved to arabic_result.json");
                }
            }
        }
        
        wescraper_log("Thumbnail ID saved to all necessary files");
        echo "✅ Thumbnail ID saved to all necessary files<br>";
        echo "Now you can use the 'Add Course' button to create a course with this thumbnail.<br>";
        
        unlink($temp_file);
        wescraper_log("Temp file deleted");

    } catch (Exception $e) {
        wescraper_log("Exception caught: " . $e->getMessage());
        echo "❌ Error: " . $e->getMessage() . "<br>";
    }

    wescraper_log("wescraper_upload_thumbnail() finished");
}
?>