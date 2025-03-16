<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function wescraper_log($message) {
    $log_file = plugin_dir_path(dirname(__FILE__)) . 'wescraper.log';
    $timestamp = date('Y-m-d H:i:s');
    if (is_array($message) || is_object($message)) {
        $log_message = "[$timestamp] " . print_r($message, true) . "\n";
    } else {
        $log_message = "[$timestamp] $message\n";
    }
    file_put_contents($log_file, $log_message, FILE_APPEND);
}
?>