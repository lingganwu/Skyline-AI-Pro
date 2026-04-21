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
                if (is_array($value)) {
                    $sanitized[$key] = array_map('sanitize_text_field', $value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
            update_option('skyline_ai_settings', $sanitized);
            add_settings_error('skyline_messages', 'skyline_msg', 'Settings saved successfully!', 'updated');
        }
    }

    public function render_settings_page() {
        $schema = $this->core->get_config_schema();
        $current_settings = get_option('skyline_ai_settings', []);
        
        // Group settings
        $groups = [];
        foreach ($schema as $key => $cfg) {
            $group = $cfg['group'] ?? 'general';
            $groups[$group][] = ['key' => $key, 'cfg' => $cfg];
        }
        ?>
        <div class="wrap">
            <h1>🌌 Skyline AI Pro Control Center</h1>
            <?php if (get_settings_errors()) echo '<div class="notice notice-success is-dismissible">'; 
            settings_errors(); 
            echo '</div>'; ?>
            
            <form method="post" action="">
                <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                
                <?php foreach ($groups as $group_name => $items): ?>
                    <div class="sky-setting-group" style="margin-bottom: 30px; background: #fff; border: 1px solid #ccd0d4; border-radius: 8px; overflow: hidden;">
                        <h2 style="margin: 0; padding: 15px; background: #f6f7f7; border-bottom: 1px solid #ccd0d4; font-size: 1.2em; color: #2c3338;">
                            <?php echo strtoupper($group_name); ?>
                        </h2>
                        <table class="form-table" style="margin: 0;">
                            <?php foreach ($items as $item): 
                                $key = $item['key'];
                                $cfg = $item['cfg'];
                                $val = $current_settings[$key] ?? ($cfg['default'] ?? '');
                                ?>
                                <tr>
                                    <th scope="row" style="width: 200px;"><?php echo esc_html($cfg['label']); ?></th>
                                    <td>
                                        <?php if ($cfg['type'] === 'password'): ?>
                                            <input type="password" name="skyline_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($val); ?>" class="regular-text">
                                        <?php elseif ($cfg['type'] === 'textarea'): ?>
                                            <textarea name="skyline_settings[<?php echo $key; ?>]" rows="3" class="large-text"><?php echo esc_textarea($val); ?></textarea>
                                        <?php elseif ($cfg['type'] === 'bool'): ?>
                                            <input type="checkbox" name="skyline_settings[<?php echo $key; ?>]" value="1" <?php checked(1, (int)$val); ?>>
                                        <?php elseif ($cfg['type'] === 'select'): ?>
                                            <select name="skyline_settings[<?php echo $key; ?>]">
                                                <?php foreach ($cfg['options'] as $opt_val => $opt_label): ?>
                                                    <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        <?php else: ?>
                                            <input type="text" name="skyline_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($val); ?>" class="regular-text">
                                        <?php endif; ?>
                                        <?php if (isset($cfg['desc'])): ?>
                                            <p class="description"><?php echo esc_html($cfg['desc']); ?></p>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php endforeach; ?>
                
                <div style="margin-top: 20px;">
                    <input type="submit" name="skyline_save_settings" class="button button-primary button-large" value="Save All Configuration">
                </div>
            </form>
            
            <hr style="margin: 40px 0;">
            <h2>📊 Real-time Statistics</h2>
            <div class="sky-stats-grid" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:20px;">
                <div class="sky-stat-card" style="padding:20px; background:#fff; border:1px solid #ccd0d4; border-radius:8px; text-align:center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="color: #666; font-size: 0.9em; margin-bottom: 10px;">Total API Calls</div>
                    <div style="font-size: 2em; font-weight: bold; color: #4f46e5;"><?php echo esc_html($this->core->stat_get('api_calls')); ?></div>
                </div>
                <div class="sky-stat-card" style="padding:20px; background:#fff; border:1px solid #ccd0d4; border-radius:8px; text-align:center; box-shadow: 0 2px 4px rgba(0,0,0,0.05);">
                    <div style="color: #666; font-size: 0.9em; margin-bottom: 10px;">Total Imported</div>
                    <div style="font-size: 2em; font-weight: bold; color: #4f46e5;"><?php echo esc_html($this->core->stat_get('spider_count')); ?></div>
                </div>
            </div>
        </div>
        <?php
    }
}
