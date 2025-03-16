<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}
require_once plugin_dir_path(__FILE__) . 'functions.php'; // Include functions.php
require_once plugin_dir_path(__FILE__) . 'log.php'; // Include log.php

function wescraper_upload_thumbnail() {
    wescraper_log("wescraper_upload_thumbnail() started");

    try {
        $json_file = plugin_dir_path(dirname(__FILE__)) . 'result.json';
        wescraper_log("JSON file path: " . $json_file);

        if (!file_exists($json_file)) {
            wescraper_log("result.json file not found!");
            throw new Exception("result.json file not found!");
        }

        $json_data = json_decode(file_get_contents($json_file), true);
        wescraper_log("JSON data decoded");

        if (!isset($json_data['thumbnail'])) {
            wescraper_log("No thumbnail URL found in result.json");
            throw new Exception("No thumbnail URL found in result.json");
        }

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
        
        // Save attachment ID to result.json
        $json_data['thumbnail_id'] = $attachment_id;
        file_put_contents($json_file, json_encode($json_data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        wescraper_log("Thumbnail ID saved to result.json");
        
        echo "✅ Thumbnail ID saved to result.json<br>";
        

        unlink($temp_file);
        wescraper_log("Temp file deleted");

    } catch (Exception $e) {
        wescraper_log("Exception caught: " . $e->getMessage());
        echo "❌ Error: " . $e->getMessage() . "<br>";
    }

    wescraper_log("wescraper_upload_thumbnail() finished");
}
?>