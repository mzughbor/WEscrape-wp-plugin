<?php
namespace WeScraper;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_post_run_scraper', [$this, 'handle_scraper_run']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
        add_action('admin_menu', [$this, 'add_menu_page']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('WeScraper', 'wescraper'),
            __('WeScraper', 'wescraper'),
            'manage_options',
            'wescraper',
            [$this, 'render_admin_page'],
            'dashicons-download',
            30
        );
    }

    public function render_admin_page() {
        include WESCRAPER_PLUGIN_DIR . 'admin/views/main.php';
    }

    public function enqueue_admin_scripts($hook) {
        if ($hook !== 'toplevel_page_wescraper') {
            return;
        }

        wp_enqueue_style(
            'wescraper-admin',
            WESCRAPER_PLUGIN_URL . 'admin/css/admin.css',
            [],
            WESCRAPER_VERSION
        );

        wp_enqueue_script(
            'wescraper-admin',
            WESCRAPER_PLUGIN_URL . 'admin/js/admin.js',
            ['jquery'],
            WESCRAPER_VERSION,
            true
        );
    }

    public function handle_scraper_run() {
        check_admin_referer('run_scraper');
        
        try {
            $scraper = new Scraper();
            $result = $scraper->run();
            
            wp_redirect(add_query_arg([
                'page' => 'wescraper',
                'message' => 'success',
                'courses' => $result['courses'] ?? 0
            ], admin_url('admin.php')));
        } catch (\Exception $e) {
            wp_redirect(add_query_arg([
                'page' => 'wescraper',
                'message' => 'error',
                'error' => urlencode($e->getMessage())
            ], admin_url('admin.php')));
        }
        exit;
    }

    public function register_rest_routes() {
        register_rest_route('wescraper/v1', '/run', [
            'methods' => 'POST',
            'callback' => [$this, 'handle_rest_run'],
            'permission_callback' => function() {
                return current_user_can('manage_options');
            }
        ]);
    }

    public function handle_rest_run(\WP_REST_Request $request) {
        try {
            $scraper = new Scraper();
            $result = $scraper->run();
            return new \WP_REST_Response($result, 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function add_menu_page() {
        add_menu_page(
            'WeScraper Settings',
            'WeScraper',
            'manage_options',
            'wescraper-settings',
            [$this, 'render_settings_page'],
            'dashicons-admin-generic'
        );
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>WeScraper Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields(Settings::OPTION_GROUP);
                do_settings_sections(Settings::OPTION_GROUP);
                ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">Tutor API Key</th>
                        <td>
                            <input type="text" name="tutor_api_key" 
                                value="<?php echo esc_attr(Settings::get_api_key()); ?>" 
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Tutor API Secret</th>
                        <td>
                            <input type="password" name="tutor_api_secret" 
                                value="<?php echo esc_attr(Settings::get_api_secret()); ?>" 
                                class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">WordPress Application Password</th>
                        <td>
                            <input type="password" name="wp_app_password" 
                                value="<?php echo esc_attr(Settings::get_app_password()); ?>" 
                                class="regular-text">
                            <p class="description">
                                Generate this from WordPress Users → Profile → Application Passwords
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Base URL</th>
                        <td>
                            <input type="url" name="tutor_api_base_url" 
                                value="<?php echo esc_url(Settings::get_api_base_url()); ?>" 
                                class="regular-text">
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }
} 