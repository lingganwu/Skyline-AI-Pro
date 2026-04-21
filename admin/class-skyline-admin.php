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
            add_settings_error('skyline_messages', 'skyline_msg', '配置已同步至云端，保存成功！', 'updated');
        }
    }

    public function render_settings_page() {
        $schema = $this->core->get_config_schema();
        $current_settings = get_option('skyline_ai_settings', []);
        
        // Translation Mapping
        $group_map = [
            'ai' => ['label' => 'AI 智能配置', 'icon' => '🤖'],
            'spider' => ['label' => '同步采集', 'icon' => '🕸️'],
            'oss' => ['label' => '云端存储', 'icon' => '☁️'],
            'seo' => ['label' => 'SEO 优化', 'icon' => '🚀'],
            'speed' => ['label' => '性能加速', 'icon' => '⚡'],
            'general' => ['label' => '通用设置', 'icon' => '⚙️'],
        ];
        
        $groups = [];
        foreach ($schema as $key => $cfg) {
            $group_id = $cfg['group'] ?? 'general';
            $group_name = $group_map[$group_id]['label'] ?? '其他设置';
            $groups[$group_id][] = ['key' => $key, 'cfg' => $cfg];
        }
        ?>
        <style>
            :root { 
                --sky-gradient: linear-gradient(135deg, #1e293b 0%, #4f46e5 100%);
                --sky-primary: #4f46e5; --sky-bg: #f1f5f9; --sky-border: #e2e8f0; 
                --sky-text-main: #0f172a; --sky-text-muted: #64748b;
            }
            .sky-admin-wrap { font-family: "Inter", -apple-system, system-ui, sans-serif; margin-top: 0; background: var(--sky-bg); min-height: 100vh; padding-bottom: 50px; }
            
            /* Header Section */
            .sky-hero { background: var(--sky-gradient); padding: 40px 30px; color: #fff; border-radius: 0 0 30px 30px; box-shadow: 0 10px 25px -5px rgba(79, 70, 229, 0.4); margin-left: -20px; margin-right: -20px; margin-bottom: 30px; }
            .sky-hero h1 { font-size: 32px; font-weight: 800; margin: 0; letter-spacing: -0.5px; display: flex; align-items: center; gap: 15px; }
            .sky-hero .version-pill { background: rgba(255,255,255,0.2); padding: 4px 12px; border-radius: 50px; font-size: 14px; font-weight: 500; backdrop-filter: blur(4px); }
            
            /* Tab Navigation */
            .sky-nav-container { display: flex; justify-content: center; margin: -25px 0 30px 0; position: relative; z-index: 10; }
            .sky-nav { display: flex; gap: 12px; background: #fff; padding: 8px; border-radius: 16px; box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); border: 1px solid var(--sky-border); }
            .sky-nav-btn { padding: 10px 20px; cursor: pointer; border: none; background: none; font-size: 15px; font-weight: 600; color: var(--sky-text-muted); transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 12px; display: flex; align-items: center; gap: 8px; }
            .sky-nav-btn:hover { color: var(--sky-primary); background: #f8fafc; }
            .sky-nav-btn.active { background: var(--sky-primary); color: #fff; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.3); }
            
            /* Content Layout */
            .sky-container { max-width: 1100px; margin: 0 auto; padding: 0 20px; }
            .sky-tab-content { display: none; animation: slideUp 0.4s ease-out; }
            .sky-tab-content.active { display: block; }
            @keyframes slideUp { from { opacity: 0; transform: translateY(20px); } to { opacity: 1; transform: translateY(0); } }
            
            /* Settings Grid */
            .sky-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(500px, 1fr)); gap: 20px; }
            .sky-item { background: #fff; border: 1px solid var(--sky-border); border-radius: 16px; padding: 20px; transition: all 0.2s; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
            .sky-item:hover { border-color: var(--sky-primary); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.05); transform: translateY(-2px); }
            .sky-item-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; }
            .sky-item-title { font-weight: 700; color: var(--sky-text-main); font-size: 16px; }
            
            .sky-field { display: flex; flex-direction: column; gap: 8px; }
            .sky-field label { font-size: 13px; color: var(--sky-text-muted); font-weight: 500; }
            .sky-field input[type="text"], .sky-field input[type="password"], .sky-field textarea, .sky-field select { 
                padding: 10px 14px; border: 1px solid var(--sky-border); border-radius: 10px; font-size: 14px; transition: all 0.2s; background: #fdfdfd;
            }
            .sky-field input:focus { border-color: var(--sky-primary); outline: none; box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.1); background: #fff; }
            .sky-desc { font-size: 12px; color: #94a3b8; margin-top: 5px; display: flex; align-items: center; gap: 4px; }
            
            /* API Test Button */
            .sky-test-btn { padding: 6px 12px; font-size: 12px; background: #fff; border: 1px solid var(--sky-primary); color: var(--sky-primary); border-radius: 6px; cursor: pointer; transition: all 0.2s; font-weight: 600; }
            .sky-test-btn:hover { background: var(--sky-primary); color: #fff; }
            .sky-test-res { font-size: 12px; margin-left: 10px; font-weight: 600; }

            /* Footer and Save */
            .sky-footer { margin-top: 40px; display: flex; justify-content: center; align-items: center; gap: 20px; }
            .sky-save-btn { background: var(--sky-gradient); color: #fff; padding: 16px 40px; border-radius: 14px; font-size: 16px; font-weight: 700; border: none; cursor: pointer; transition: all 0.3s; box-shadow: 0 10px 20px -5px rgba(79, 70, 229, 0.5); }
            .sky-save-btn:hover { transform: scale(1.05); box-shadow: 0 15px 25px -5px rgba(79, 70, 229, 0.6); }
            
            .sky-stats-bar { display: flex; justify-content: center; gap: 30px; margin-top: 30px; }
            .sky-stat-pill { background: #fff; padding: 10px 20px; border-radius: 50px; border: 1px solid var(--sky-border); display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--sky-text-muted); }
            .sky-stat-val { font-weight: 800; color: var(--sky-primary); font-size: 16px; }
        </style>

        <div class="sky-admin-wrap">
            <div class="sky-hero">
                <h1>🌌 Skyline AI Pro <span class="version-pill">Enterprise v1.5.0</span></h1>
            </div>

            <?php if (get_settings_errors()) echo '<div class="notice notice-success is-dismissible">'; 
            settings_errors(); 
            echo '</div>'; ?>

            <div class="sky-nav-container">
                <div class="sky-nav">
                    <?php $i = 0; foreach ($groups as $group_id => $items): ?>
                        <button type="button" class="sky-nav-btn <?php echo $i === 0 ? 'active' : ''; ?>" data-tab="<?php echo $group_id; ?>">
                            <span><?php echo $group_map[$group_id]['icon'] ?? '⚙️'; ?></span>
                            <?php echo $group_map[$group_id]['label'] ?? '其他设置'; ?>
                        </button>
                    <?php $i++; endforeach; ?>
                </div>
            </div>

            <div class="sky-container">
                <form method="post" action="">
                    <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                    
                    <?php $i = 0; foreach ($groups as $group_id => $items): ?>
                        <div class="sky-tab-content <?php echo $i === 0 ? 'active' : ''; ?>" id="tab-<?php echo $group_id; ?>">
                            <div class="sky-grid">
                                <?php foreach ($items as $item): 
                                    $key = $item['key'];
                                    $cfg = $item['cfg'];
                                    $val = $current_settings[$key] ?? ($cfg['default'] ?? '');
                                    ?>
                                    <div class="sky-item">
                                        <div class="sky-item-header">
                                            <div class="sky-item-title"><?php echo esc_html($cfg['label']); ?></div>
                                            <?php if($key === 'api_key'): ?>
                                                <button type="button" class="sky-test-btn" onclick="skyTestApi(this)">测试连接</button>
                                            <?php endif; ?>
                                        </div>
                                        <div class="sky-field">
                                            <?php if ($cfg['type'] === 'password'): ?>
                                                <input type="password" name="skyline_settings[<?php echo $key; ?>]" value="<?php echo esc_attr($val); ?>">
                                            <?php elseif ($cfg['type'] === 'textarea'): ?>
                                                <textarea name="skyline_settings[<?php echo $key; ?>]" rows="3"><?php echo esc_textarea($val); ?></textarea>
                                            <?php elseif ($cfg['type'] === 'bool'): ?>
                                                <label style="display:flex; align-items:center; gap:10px; cursor:pointer; padding: 10px 0;">
                                                    <input type="checkbox" name="skyline_settings[<?php echo $key; ?>]" value="1" <?php checked(1, (int)$val); ?> style="width:18px; height:18px;"> 
                                                    <span style="font-size:14px; color:#475569; font-weight:500;">已启用</span>
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
                                                <div class="sky-desc">💡 <?php echo esc_html($cfg['desc']); ?></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php $i++; endforeach; ?>

                    <div class="sky-footer">
                        <button type="submit" name="skyline_save_settings" class="sky-save-btn">保存所有配置</button>
                    </div>
                </form>

                <div class="sky-stats-bar">
                    <div class="sky-stat-pill">API 累计调用 <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span></div>
                    <div class="sky-stat-pill">采集文章总数 <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('spider_count')); ?></span></div>
                </div>
            </div>
        </div>

        <script>
            document.querySelectorAll('.sky-nav-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.sky-nav-btn').forEach(b => b.classList.remove('active'));
                    document.querySelectorAll('.sky-tab-content').forEach(c => c.classList.remove('active'));
                    btn.classList.add('active');
                    document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
                });
            });

            async function skyTestApi(btn) {
                const originalText = btn.innerText;
                btn.innerText = '检测中...';
                btn.disabled = true;
                
                try {
                    const response = await fetch(ajaxurl + '?action=sky_test_api&_wpnonce=' + '<?php echo wp_create_nonce('sky_ai_test_nonce'); ?>');
                    const res = await response.json();
                    if(res.success) {
                        btn.style.borderColor = '#22c55e';
                        btn.style.color = '#22c55e';
                        btn.innerText = '连接成功 ✅';
                    } else {
                        throw new Error();
                    }
                } catch(e) {
                    btn.style.borderColor = '#ef4444';
                    btn.style.color = '#ef4444';
                    btn.innerText = '连接失败 ❌';
                }
                setTimeout(() => {
                    btn.innerText = originalText;
                    btn.style.borderColor = '#4f46e5';
                    btn.style.color = '#4f46e5';
                    btn.disabled = false;
                }, 3000);
            }
        </script>
        <?php
    }
}
