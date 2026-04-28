<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    public function __construct() {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_settings']);
    }

    public function add_menu() {
        add_menu_page('Skyline AI Pro', 'Skyline AI', 'manage_options', 'skyline-pro', [$this, 'render_page'], 'dashicons-welcome-learn-more', 60);
    }

    public function handle_settings() {
        if (!isset($_POST['save_skyline']) || !check_admin_referer('sky_save', 'sky_nonce')) return;
        $settings = $_POST['skyline_settings'] ?? [];
        
        // Sanitization
        foreach ($settings as $key => $val) {
            if (is_array($val)) continue;
            $settings[$key] = sanitize_text_field($val);
        }
        
        update_option('skyline_ai_settings', $settings);
        add_settings_error('sky_msg', 'sky_msg', '配置已保存', 'updated');
    }

    public function render_page() {
        $settings = get_option('skyline_ai_settings', []);
        ?>
        <div class="wrap">
            <h1>Skyline AI Pro 设置</h1>
            <form method="post">
                <?php wp_nonce_field('sky_save', 'sky_nonce'); ?>
                <table class="form-table">
                    <tr><th>COS 启用</th><td><input type="checkbox" name="skyline_settings[oss_enable]" value="yes" <?php checked($settings['oss_enable'] ?? '', 'yes'); ?>></td></tr>
                    <tr><th>COS SecretId</th><td><input type="text" name="skyline_settings[oss_ak]" value="<?php echo esc_attr($settings['oss_ak'] ?? ''); ?>"></td></tr>
                    <tr><th>COS SecretKey</th><td><input type="password" name="skyline_settings[oss_sk]" value="<?php echo esc_attr($settings['oss_sk'] ?? ''); ?>"></td></tr>
                    <tr><th>COS Bucket</th><td><input type="text" name="skyline_settings[oss_bucket]" value="<?php echo esc_attr($settings['oss_bucket'] ?? ''); ?>"></td></tr>
                    <tr><th>COS Endpoint</th><td><input type="text" name="skyline_settings[oss_endpoint]" value="<?php echo esc_attr($settings['oss_endpoint'] ?? ''); ?>"></td></tr>
                    <tr><th>COS Domain</th><td><input type="text" name="skyline_settings[oss_domain]" value="<?php echo esc_attr($settings['oss_domain'] ?? ''); ?>"></td></tr>
                    <tr><th>删除本地文件</th><td><input type="checkbox" name="skyline_settings[oss_delete_local]" value="yes" <?php checked($settings['oss_delete_local'] ?? '', 'yes'); ?>></td></tr>
                    <tr><th>Redis Host</th><td><input type="text" name="skyline_settings[redis_host]" value="<?php echo esc_attr($settings['redis_host'] ?? '127.0.0.1'); ?>"></td></tr>
                </table>
                <input type="submit" name="save_skyline" class="button-primary" value="保存设置">
            </form>
        </div>
        <?php
    }
}
