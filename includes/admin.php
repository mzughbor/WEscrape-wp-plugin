<?php
if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path(__FILE__) . 'scrape_lessons.php';
require_once plugin_dir_path(__FILE__) . 'functions.php'; // Include functions.php
require_once plugin_dir_path(dirname(__FILE__)) . '/add-course-page.php'; // Include add-course-page.php

function wescraper_scrape_course() {
    $courseUrl = get_option('wescraper_course_url', "https://www.mindluster.com/certificate/16402/Resin-jewelry-video");
    $coursesFile = plugin_dir_path(dirname(__FILE__)) . 'scraped_courses.txt';

    // Check if course URL already exists
    if (file_exists($coursesFile)) {
        $courses = file($coursesFile, FILE_IGNORE_NEW_LINES);
        if (in_array($courseUrl, $courses)) {
            echo '<div class="notice notice-warning is-dismissible"><p>Course URL already scraped!</p></div>';
            return; // Skip scraping
        }
    }

    $cookiesFile = plugin_dir_path(__FILE__) . '../cookies.json';

    $cookies = wescraper_get_cookies_from_json($cookiesFile);
    if (!$cookies) {
        echo '<p>❌ Error: Failed to load cookies.</p>';
        return;
    }

    $html = wescraper_fetch_url($courseUrl, $cookies);

    if ($html) {
        $courseData = wescraper_extract_data($html);
        $resultJson = json_encode($courseData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (file_put_contents(plugin_dir_path(__FILE__) . "../result.json", $resultJson)) {
            echo "<p>✅ Course data saved to result.json!</p>";
            wescraper_log("Course data saved to result.json");

            // Append URL to scraped_courses.txt
            file_put_contents($coursesFile, $courseUrl . "\n", FILE_APPEND);
        } else {
            echo "<p>❌ Failed to save course data to result.json.</p>";
            wescraper_log("Failed to save course data to result.json");
        }
    } else {
        echo "<p>❌ Failed to fetch course data.</p>";
        wescraper_log("Failed to fetch course data.");
    }
}

// Add admin page to trigger the test
add_action('admin_menu', 'wescraper_add_admin_menu');

function wescraper_add_admin_menu() {
    add_menu_page(
        'WeScraper Course Scraper',
        'Course Scraper',
        'manage_options',
        'wescraper-scrape',
        'wescraper_scrape_page'
    );
}

function wescraper_scrape_page() {
    $currentUrl = get_option('wescraper_course_url', "https://www.mindluster.com/certificate/16402/Resin-jewelry-video");

    if (isset($_POST['wescraper_scrape_now'])) {
        update_option('wescraper_course_url', sanitize_url($_POST['wescraper_course_url']));
        wescraper_scrape_course();
    }

    $resultJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../result.json')) {
        $resultJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../result.json');
    }

    $lessonJsonContent = '';
    if (file_exists(plugin_dir_path(__FILE__) . '../lesson_data.json')) {
        $lessonJsonContent = file_get_contents(plugin_dir_path(__FILE__) . '../lesson_data.json');
    }

    $textareaDirection = is_rtl() ? 'ltr' : 'rtl';

    ?>
    <div class="wrap">
        <h2>WeScraper Course Scraper</h2>
        <form method="post">
            <label for="wescraper_course_url">Course URL:</label><br>
            <input type="text" name="wescraper_course_url" id="wescraper_course_url" value="<?php echo esc_attr($currentUrl); ?>" style="width: 500px;"><br><br>
            <input type="hidden" name="wescraper_scrape_now" value="1">
            <input type="submit" class="button button-primary" value="Scrape Course">
        </form>
        <?php
        if (isset($_POST['wescraper_scrape_now'])) {
            wescraper_scrape_course();
        }
        ?>
        <br>
        <h3>Latest Scraped Data (result.json)</h3>
        <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo esc_attr($textareaDirection); ?>;">
            <?php echo esc_textarea($resultJsonContent); ?>
        </textarea>
        <br>
        <form method="post">
            <input type="hidden" name="wescraper_scrape_lessons" value="1">
            <input type="submit" class="button button-secondary" value="Scrape Lessons">
        </form>
        <?php
        if (isset($_POST['wescraper_scrape_lessons'])) {
            wescraper_scrape_lessons();
        }
        ?>
        <br>
        <h3>Latest Scraped Lessons (lesson_data.json)</h3>
        <textarea rows="20" cols="80" style="width: 90%; height: 400px; overflow: auto; background-color: #f8f8f8; border: 1px solid #ccc; padding: 10px; font-family: monospace; direction: <?php echo esc_attr($textareaDirection); ?>;">
            <?php echo esc_textarea($lessonJsonContent); ?>
        </textarea>
        <br>
        <?php wescraper_add_course_page(); ?>
    </div>
    <?php
}
?>