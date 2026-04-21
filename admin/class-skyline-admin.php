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
            add_settings_error('skyline_messages', 'skyline_msg', 'Configuration updated successfully!', 'updated');
        }
    }

    public function render_settings_page() {
        $schema = $this->core->get_config_schema();
        $current_settings = get_option('skyline_ai_settings', []);
        
        $groups = [];
        foreach ($schema as $key => $cfg) {
            $group = $cfg['group'] ?? 'general';
            $groups[$group][] = ['key' => $key, 'cfg' => $cfg];
        }
        ?>
        <style>
            :root { --sky-primary: #4f46e5; --sky-bg: #f8fafc; --sky-border: #e2e8f0; --sky-text: #1e293b; }
            .sky-admin-wrap { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif; margin-top: 20px; max-width: 1200px; }
            .sky-header { margin-bottom: 30px; display: flex; align-items: center; gap: 15px; }
            .sky-header h1 { font-size: 24px; font-weight: 700; color: var(--sky-text); margin: 0; }
            .sky-header .badge { background: var(--sky-primary); color: #fff; font-size: 12px; padding: 4px 10px; border-radius: 20px; font-weight: 600; }
            
            /* Tab Navigation */
            .sky-tabs { display: flex; gap: 10px; border-bottom: 2px solid var(--sky-border); margin-bottom: 25px; overflow-x: auto; }
            .sky-tab { padding: 12px 24px; cursor: pointer; border: none; background: none; font-size: 15px; font-weight: 500; color: #64748b; transition: all 0.3s ease; border-bottom: 3px solid transparent; margin-bottom: -2px; }
            .sky-tab:hover { color: var(--sky-primary); }
            .sky-tab.active { color: var(--sky-primary); border-bottom-color: var(--sky-primary); font-weight: 600; }
            
            /* Content Area */
            .sky-tab-content { display: none; animation: fadeIn 0.4s ease; }
            .sky-tab-content.active { display: block; }
            @keyframes fadeIn { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
            
            /* Setting Card */
            .sky-card { background: #fff; border: 1px solid var(--sky-border); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); margin-bottom: 20px; overflow: hidden; }
            .sky-card-header { background: #fcfcfd; padding: 15px 20px; border-bottom: 1px solid var(--sky-border); font-weight: 600; color: var(--sky-text); }
            .sky-card-body { padding: 0; }
            .sky-row { display: flex; align-items: flex-start; padding: 18px 20px; border-bottom: 1px solid #f1f5f9; transition: background 0.2s; }
            .sky-row:last-child { border-bottom: none; }
            .sky-row:hover { background: #f8fafc; }
            .sky-label { width: 220px; font-weight: 500; color: #475569; font-size: 14px; padding-top: 8px; }
            .sky-input-wrap { flex: 1; }
            .sky-input-wrap input[type="text"], .sky-input-wrap input[type="password"], .sky-input-wrap textarea, .sky-input-wrap select { 
                width: 100%; padding: 8px 12px; border: 1px solid var(--sky-border); border-radius: 6px; font-size: 14px; transition: border-color 0.2s; 
            }
            .sky-input-wrap input:focus, .sky-input-wrap textarea:focus { border-color: var(--sky-primary); outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); }
            .sky-desc { font-size: 12px; color: #94a3b8; margin-top: 6px; }
            
            /* Stats */
            .sky-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 20px; margin-top: 30px; }
            .sky-stat-item { background: #fff; padding: 20px; border-radius: 12px; border: 1px solid var(--sky-border); text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.02); }
            .sky-stat-label { font-size: 13px; color: #64748b; margin-bottom: 8px; }
            .sky-stat-value { font-size: 28px; font-weight: 800; color: var(--sky-primary); }
            
            /* Footer */
            .sky-footer { margin-top: 40px; padding: 20px 0; display: flex; justify-content: flex-end; }
            .sky-save-btn { background: var(--sky-primary); color: #fff; padding: 12px 30px; border-radius: 8px; font-weight: 600; border: none; cursor: pointer; transition: transform 0.2s, background 0.2s; box-shadow: 0 4px 10px rgba(79, 70, 229, 0.3); }
            .sky-save-btn:hover { background: #4338ca; transform: translateY(-1px); }
            .sky-save-btn:active { transform: translateY(0); }
        </style>

        <div class="sky-admin-wrap">
            <div class="sky-header">
                <h1>🌌 Skyline AI Pro Control Center</h1>
                <span class="badge">Enterprise v1.5.0</span>
            </div>

            <?php if (get_settings_errors()) echo '<div class="notice notice-success is-dismissible">'; 
            settings_errors(); 
            echo '</div>'; ?>

            <form method="post" action="">
                <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                
                <div class="sky-tabs">
                    <?php $i = 0; foreach ($groups as $group_name => $items): ?>
                        <button type="button" class="sky-tab <?php echo $i === 0 ? 'active' : ''; ?>" data-tab="<?php echo $group_name; ?>">
                            <?php echo strtoupper($group_name); ?>
                        </button>
                    <?php $i++; endforeach; ?>
                </div>

                <?php $i = 0; foreach ($groups as $group_name => $items): ?>
                    <div class="sky-tab-content <?php echo $i === 0 ? 'active' : ''; ?>" id="tab-<?php echo $group_name; ?>">
                        <div class="sky-card">
                            <div class="sky-card-header">⚙️ <?php echo strtoupper($group_name); ?> Settings</div>
                            <div class="sky-card-body">
                                <?php foreach ($items as $item): 
                                    $key = $item['key'];
                                    $cfg = $item['cfg'];
                                    $val = $current_settings[$key] ?? ($cfg['default'] ?? '');
                                    ?>
                                    <div class="sky-row">
                                        <div class="sky-label"><?php echo esc_html($cfg['label']); ?></div>
                                        <div class="sky-input-wrap">
                                            <?php if ($cfg['type'] === 'password'): ?>
                                                <input type="password" name="skyline_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($val); ?>">
                                            <?php elseif ($cfg['type'] === 'textarea'): ?>
                                                <textarea name="skyline_settings[<?php echo $key; ?>]" rows="3"><?php echo esc_textarea($val); ?></textarea>
                                            <?php elseif ($cfg['type'] === 'bool'): ?>
                                                <label style="display:flex; align-items:center; gap:8px; cursor:pointer;">
                                                    <input type="checkbox" name="skyline_settings[<?php echo $key; ?>]" value="1" <?php checked(1, (int)$val); ?>> 
                                                    <span style="font-size:14px; color:#475569;">Enabled</span>
                                                </label>
                                            <?php elseif ($cfg['type'] === 'select'): ?>
                                                <select name="skyline_settings[<?php echo $key; ?>]">
                                                    <?php foreach ($cfg['options'] as $opt_val => $opt_label): ?>
                                                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else: ?>
                                                <input type="text" name="skyline_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($val); ?>">
                                            <?php endif; ?>
                                            <?php if (isset($cfg['desc'])): ?>
                                                <div class="sky-desc"><?php echo esc_html($cfg['desc']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                <?php $i++; endforeach; ?>

                <div class="sky-footer">
                    <input type="submit" name="skyline_save_settings" class="sky-save-btn" value="Save All Configuration">
                </div>
            </form>

            <div class="sky-stats">
                <div class="sky-stat-item">
                    <div class="sky-stat-label">Total API Calls</div>
                    <div class="sky-stat-value"><?php echo esc_html($this->core->stat_get('api_calls')); ?></div>
                </div>
                <div class="sky-stat-item">
                    <div class="sky-stat-label">Total Imported</div>
                    <div class="sky-stat-value"><?php echo esc_html($this->core->stat_get('spider_count')); ?></div>
                </div>
            </div>
        </div>

        <script>
            document.querySelectorAll('.sky-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.sky-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.sky-tab-content').forEach(c => c.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById('tab-' + tab.dataset.tab).classList.add('active');
                });
            });
        </script>
        <?php
    }
}
