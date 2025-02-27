<?php
namespace WeScraper;

class Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('admin_post_run_scraper', [$this, 'handle_scraper_run']);
        add_action('rest_api_init', [$this, 'register_rest_routes']);
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
} 