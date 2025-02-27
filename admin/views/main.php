<?php
if (!defined('ABSPATH')) exit;
?>

<div class="wrap wescraper-wrap">
    <h1><?php _e('WeScraper', 'wescraper'); ?></h1>

    <?php settings_errors(); ?>

    <div class="wescraper-card">
        <h2><?php _e('Settings', 'wescraper'); ?></h2>
        <form method="post" action="options.php">
            <?php
            settings_fields('wescraper_options');
            do_settings_sections('wescraper');
            submit_button(__('Save Settings', 'wescraper'));
            ?>
        </form>
    </div>

    <div class="wescraper-card">
        <h2><?php _e('Run Scraper', 'wescraper'); ?></h2>
        <p><?php _e('Click the button below to start the scraping process.', 'wescraper'); ?></p>
        <form id="wescraper-form" method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('run_scraper'); ?>
            <input type="hidden" name="action" value="run_scraper">
            <button type="submit" class="button button-primary">
                <?php _e('Start Scraping', 'wescraper'); ?>
            </button>
        </form>
    </div>

    <?php
    // Show recent results
    $results_dir = WESCRAPER_PLUGIN_DIR . 'data/created_courses/';
    if (file_exists($results_dir)) {
        $files = glob($results_dir . '*.json');
        if (!empty($files)) {
            echo '<div class="wescraper-card">';
            echo '<h2>' . __('Recent Results', 'wescraper') . '</h2>';
            echo '<table class="wp-list-table widefat fixed striped">';
            echo '<thead><tr>';
            echo '<th>' . __('Date', 'wescraper') . '</th>';
            echo '<th>' . __('Course', 'wescraper') . '</th>';
            echo '<th>' . __('Status', 'wescraper') . '</th>';
            echo '</tr></thead><tbody>';
            
            rsort($files); // Show newest first
            foreach (array_slice($files, 0, 5) as $file) {
                $data = json_decode(file_get_contents($file), true);
                echo '<tr>';
                echo '<td>' . date('Y-m-d H:i:s', filemtime($file)) . '</td>';
                echo '<td>' . esc_html($data['source_data']['course_name']) . '</td>';
                echo '<td>' . __('Completed', 'wescraper') . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
            echo '</div>';
        }
    }
    ?>
</div> 