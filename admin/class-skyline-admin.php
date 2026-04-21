<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    private $core;

    public function __construct() {
        $this->core = Skyline_Core::instance();
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_settings'));
    }

    public function add_menu() {
        add_menu_page(
            'Skyline AI Pro',
            'Skyline AI',
            'manage_options',
            'skyline-pro',
            array($this, 'render_settings_page'),
            'dashicons-welcome-learn-more',
            60
        );
    }

    public function handle_settings() {
        if (isset($_POST['skyline_save_settings']) && check_admin_referer('skyline_save_action', 'skyline_nonce')) {
            $settings = $_POST['skyline_settings'] ?? [];
            $sanitized = [];
            foreach ($settings as $key => $value) {
                $sanitized[$key] = sanitize_text_field($value);
            }
            update_option('skyline_settings', $sanitized);
            add_settings_error('skyline_messages', 'skyline_msg', 'Settings saved successfully!', 'updated');
        }
    }

    public function render_settings_page() {
        ?>
        <div class="wrap">
            <h1>🌌 Skyline AI Pro Settings</h1>
            <?php if (get_settings_errors()) echo '<div class="notice notice-success is-dismissible">'; 
            settings_errors(); 
            echo '</div>'; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row">API Key</th>
                        <td><input type="text" name="skyline_settings[api_key]" value="<?php echo esc_attr(get_option('skyline_settings')['api_key'] ?? ''); ?>" class="regular-text"></td>
                    </tr>
                    <tr>
                        <th scope="row">Model</th>
                        <td><input type="text" name="skyline_settings[model]" value="<?php echo esc_attr(get_option('skyline_settings')['model'] ?? 'gpt-4'); ?>" class="regular-text"></td>
                    </tr>
                </table>
                <input type="submit" name="skyline_save_settings" class="button button-primary" value="Save Changes">
            </form>
            
            <hr>
            <h2>📊 Statistics</h2>
            <div class="sky-stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px;">
                <div class="sky-stat-card" style="padding:20px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">
                    <strong>API Calls:</strong> <?php echo esc_html($this->core->stat_get('api_calls')); ?>
                </div>
                <div class="sky-stat-card" style="padding:20px; background:#fff; border:1px solid #ccd0d4; border-radius:4px;">
                    <strong>Imported:</strong> <?php echo esc_html($this->core->stat_get('spider_count')); ?>
                </div>
            </div>
        </div>
        <?php
    }
}
