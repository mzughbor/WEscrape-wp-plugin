<?php
namespace WeScraper;

class MediaUploader {
    public function upload_from_url($url, $title = '') {
        // Require WordPress media handling functions
        require_once(ABSPATH . 'wp-admin/includes/media.php');
        require_once(ABSPATH . 'wp-admin/includes/file.php');
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Download URL to temporary file
        $tmp = download_url($url);

        if (is_wp_error($tmp)) {
            throw new \Exception('Failed to download image: ' . $tmp->get_error_message());
        }

        // Set file data
        $file_array = array(
            'name' => basename($url),
            'tmp_name' => $tmp
        );

        // Set upload parameters
        $post_data = array(
            'post_title' => $title,
            'post_content' => '',
            'post_status' => 'publish'
        );

        // Upload the file
        $attachment_id = media_handle_sideload($file_array, 0, $title, $post_data);

        // Clean up temporary file
        @unlink($tmp);

        if (is_wp_error($attachment_id)) {
            throw new \Exception('Failed to upload image: ' . $attachment_id->get_error_message());
        }

        return $attachment_id;
    }
} 