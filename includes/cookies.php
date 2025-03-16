<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

function wescraper_get_cookies_from_json($filePath) {
    wescraper_log("Attempting to load cookies from: " . $filePath);
    $json = file_get_contents($filePath);
    if ($json === false) {
        wescraper_log("Failed to read cookies file.");
        return false;
    }
    $cookiesArray = json_decode($json, true);

    if (!$cookiesArray) {
        wescraper_log("Failed to decode cookies JSON.");
        return false;
    }

    // Extract relevant cookies
    $cookieNames = ["__eoi", "laravel_session", "remember_web_59ba36addc2b2f9401580f014c7f58ea4e30989d", "XSRF-TOKEN"];
    $cookies = [];

    foreach ($cookiesArray as $cookie) {
        if (in_array($cookie["name"], $cookieNames)) {
            $cookies[] = $cookie["name"] . "=" . $cookie["value"];
        }
    }

    if (empty($cookies)) {
        wescraper_log("No relevant cookies found.");
        return false;
    }

    wescraper_log("Cookies loaded successfully.");
    return implode("; ", $cookies);
}
?>