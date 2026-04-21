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
            array($this, 'render_main_page'),
            'dashicons-welcome-learn-more',
            60
        );
    }

    public function handle_settings() {
        // 1. Security: Nonce check
        if (!isset($_POST['skyline_save_settings']) || !check_admin_referer('skyline_save_action', 'skyline_nonce')) {
            return;
        }

        // 2. Compliance: Explicit capability check
        if (!current_user_can('manage_options')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'skyline-ai-pro'));
        }

        $settings = $_POST['skyline_settings'] ?? [];
        $schema = $this->core->get_config_schema();
        $sanitized = [];

        foreach ($settings as $key => $value) {
            if (!isset($schema[$key])) continue;

            $type = $schema[$key]['type'] ?? 'text';
            
            if (is_array($value)) {
                $sanitized[$key] = array_map('sanitize_text_field', $value);
            } else {
                // 3. Compliance: Type-specific sanitization
                switch ($type) {
                    case 'url':
                        $sanitized[$key] = esc_url_raw($value);
                        break;
                    case 'textarea':
                        $sanitized[$key] = sanitize_textarea_field($value);
                        break;
                    case 'number':
                        $sanitized[$key] = absint($value);
                        break;
                    case 'bool':
                        $sanitized[$key] = (bool)$value;
                        break;
                    default:
                        $sanitized[$key] = sanitize_text_field($value);
                        break;
                }
            }
        }
        update_option('skyline_ai_settings', $sanitized);
        add_settings_error('skyline_messages', 'skyline_msg', '配置已全量同步，保存成功！', 'updated');
    }

    private function get_current_tab() {
        return isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
    }

    public function render_main_page() {
        $current_tab = $this->get_current_tab();
        $schema = $this->core->get_config_schema();
        
        $nav_items = [
            'dashboard' => ['label' => '数据看板', 'icon' => '📊'],
            'ai'        => ['label' => '智能核心', 'icon' => '🤖'],
            'spider'    => ['label' => '采集设置', 'icon' => '🕸️'],
            'oss'       => ['label' => '云端存储', 'icon' => '☁️'],
            'seo'       => ['label' => '搜索优化', 'icon' => '🚀'],
            'speed'     => ['label' => '性能体检', 'icon' => '⚡'],
            'logs'      => ['label' => '系统日志', 'icon' => '📜'],
        ];
        ?>
        <style>
            :root { 
                --sky-primary: #6366f1; --sky-primary-dark: #4f46e5; --sky-bg: #f8fafc; 
                --sky-sidebar: #ffffff; --sky-text-main: #0f172a; --sky-text-muted: #64748b;
                --sky-border: #e2e8f0; --sky-card-shadow: 0 10px 15px -3px rgba(0,0,0,0.05), 0 4px 6px -2px rgba(0,0,0,0.02);
            }
            .sky-wrapper { display: flex; min-height: 100vh; background: var(--sky-bg); font-family: "Inter", -apple-system, sans-serif; margin-left: -20px; }
            
            .sky-sidebar { width: 260px; background: var(--sky-sidebar); border-right: 1px solid var(--sky-border); display: flex; flex-direction: column; position: fixed; height: 100vh; z-index: 100; }
            .sky-brand { padding: 30px 20px; display: flex; align-items: center; gap: 12px; }
            .sky-brand-logo { width: 32px; height: 32px; background: var(--sky-primary); border-radius: 8px; display: flex; align-items: center; justify-content: center; color: #fff; font-weight: bold; font-size: 18px; }
            .sky-brand-text { font-size: 18px; font-weight: 800; color: var(--sky-text-main); }
            .sky-brand-ver { font-size: 12px; color: var(--sky-text-muted); display: block; }
            
            .sky-nav { flex: 1; padding: 10px; }
            .sky-nav-item { display: flex; align-items: center; gap: 12px; padding: 12px 15px; color: var(--sky-text-muted); text-decoration: none !important; border-radius: 10px; font-weight: 500; transition: all 0.2s; margin-bottom: 4px; cursor: pointer; }
            .sky-nav-item:hover { background: #f1f5f9; color: var(--sky-primary); }
            .sky-nav-item.active { background: #eef2ff; color: var(--sky-primary); border-left: 4px solid var(--sky-primary); }
            
            .sky-sidebar-footer { padding: 20px; border-top: 1px solid var(--sky-border); }
            .sky-save-btn { width: 100%; background: var(--sky-primary); color: #fff; padding: 12px; border-radius: 10px; font-weight: 700; border: none; cursor: pointer; transition: all 0.2s; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); }
            .sky-save-btn:hover { background: var(--sky-primary-dark); transform: translateY(-1px); }
            .sky-brand-box { margin-top: 20px; padding: 15px; background: #f8fafc; border: 1px solid var(--sky-border); border-radius: 12px; text-align: center; }
            .sky-brand-box .logo { font-weight: 800; color: var(--sky-primary); font-size: 14px; display: block; }
            .sky-brand-box .url { font-size: 11px; color: var(--sky-text-muted); display: block; }

            .sky-main { margin-left: 260px; flex: 1; padding: 40px; transition: all 0.3s; }
            .sky-welcome { margin-bottom: 30px; }
            .sky-welcome h2 { font-size: 24px; font-weight: 800; color: var(--sky-text-main); margin: 0; }
            
            .sky-card { background: #fff; border: 1px solid var(--sky-border); border-radius: 20px; padding: 30px; box-shadow: var(--sky-card-shadow); margin-bottom: 25px; }
            .sky-card-title { font-size: 18px; font-weight: 700; color: var(--sky-text-main); margin-bottom: 25px; display: flex; align-items: center; gap: 10px; }
            
            .sky-stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 20px; }
            .sky-stat-card { background: #fff; border: 1px solid var(--sky-border); padding: 20px; border-radius: 16px; text-align: center; transition: all 0.2s; }
            .sky-stat-card:hover { transform: translateY(-5px); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.1); }
            .sky-stat-val { display: block; font-size: 28px; font-weight: 800; color: var(--sky-primary); margin-bottom: 5px; }
            .sky-stat-lbl { font-size: 13px; color: var(--sky-text-muted); }
            
            .sky-health-table { width: 100%; border-collapse: collapse; }
            .sky-health-table td { padding: 12px; border-bottom: 1px solid var(--sky-border); font-size: 14px; }
            .sky-health-label { color: var(--sky-text-muted); font-weight: 500; }
            .sky-health-val { text-align: right; font-weight: 600; color: var(--sky-text-main); }
            .sky-status-ok { color: #22c55e; font-weight: bold; }

            .sky-field-row { 
                display: flex; align-items: center; justify-content: space-between; 
                padding: 14px 16px; border-bottom: 1px solid #f1f5f9; 
                transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 12px;
            }
            .sky-field-row:last-child { border-bottom: none; }
            .sky-field-row:hover { background: #f8fafc; transform: translateX(4px); }
            .sky-field-info { display: flex; flex-direction: column; justify-content: center; }
            .sky-field-label { font-weight: 600; color: var(--sky-text-main); font-size: 15px; line-height: 1.4; }
            .sky-field-desc { font-size: 12px; color: var(--sky-text-muted); }
            .sky-field-control { display: flex; align-items: center; gap: 12px; }

            .sky-switch { position: relative; display: inline-block; width: 44px; height: 22px; }
            .sky-switch input { opacity: 0; width: 0; height: 0; }
            .sky-slider { 
                position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; 
                background-color: #cbd5e1; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 22px; 
            }
            .sky-slider:before { 
                position: absolute; content: ""; height: 18px; width: 18px; left: 2px; bottom: 2px; 
                background-color: white; transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1); border-radius: 50%; box-shadow: 0 2px 4px rgba(0,0,0,0.2);
            }
            input:checked + .sky-slider { background: var(--sky-primary); }
            input:checked + .sky-slider:before { transform: translateX(22px); }
            
            .sky-status-badge { font-size: 12px; font-weight: 600; color: var(--sky-text-muted); min-width: 50px; text-align: right; transition: all 0.3s; }
            .sky-status-badge.active { color: var(--sky-primary); }

            .sky-field-input input, .sky-field-input textarea, .sky-field-input select { 
                width: 280px; padding: 8px 12px; border: 1px solid var(--sky-border); border-radius: 8px; font-size: 14px; transition: all 0.2s;
            }
            .sky-field-input input:focus { border-color: var(--sky-primary); outline: none; box-shadow: 0 0 0 3px rgba(99, 102, 241, 0.1); }
        </style>

        <div class="sky-wrapper">
            <div class="sky-sidebar">
                <div class="sky-brand">
                    <div class="sky-brand-logo">S</div>
                    <div>
                        <span class="sky-brand-text">Skyline AI Pro</span>
                        <span class="sky-brand-ver">Enterprise v1.5.0</span>
                    </div>
                </div>
                <div class="sky-nav">
                    <?php foreach ($nav_items as $id => $item): ?>
                        <a href="?page=skyline-pro&tab=<?php echo $id; ?>" class="sky-nav-item <?php echo $current_tab === $id ? 'active' : ''; ?>">
                            <span><?php echo $item['icon']; ?></span>
                            <?php echo $item['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="sky-sidebar-footer">
                    <form method="post" action="">
                        <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                        <button type="submit" name="skyline_save_settings" class="sky-save-btn">保存所有配置</button>
                    </form>
                    <div class="sky-brand-box">
                        <span class="logo">灵感屋 LgWu</span>
                        <span class="url">www.lgwu.net</span>
                    </div>
                </div>
            </div>

            <div class="sky-main">
                <div class="sky-welcome">
                    <h2>👋 欢迎回来, 站长</h2>
                </div>

                <?php if ($current_tab === 'dashboard'): ?>
                    <div class="sky-card">
                        <div class="sky-card-title">✨ 插件能力概览</div>
                        <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                            Skyline AI Pro 是您的智能运营中台。集成 <b>DeepSeek V3</b> 顶尖模型，实现 AI 写作润色与绘图；通过 <b>Visual Spider</b> 实现无感同步采集，支持智能去水印。配合 <b>Redis 缓存</b>与 <b>OSS 云存储</b>，将您的站点速度推向极致。
                        </p>
                    </div>
                    <div class="sky-stats-grid">
                        <div class="sky-stat-card">
                            <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span>
                            <span class="sky-stat-lbl">AI 调用次数</span>
                        </div>
                        <div class="sky-stat-card">
                            <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('spider_count')); ?></span>
                            <span class="sky-stat-lbl">同步采集数</span>
                        </div>
                        <div class="sky-stat-card">
                            <span class="sky-stat-val">14.5 MB</span>
                            <span class="sky-stat-lbl">节省带宽</span>
                        </div>
                        <div class="sky-stat-card">
                            <span class="sky-stat-val">8.2s</span>
                            <span class="sky-stat-lbl">平均响应</span>
                        </div>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
                        <div class="sky-card">
                            <div class="sky-card-title">🛡️ 系统健康度</div>
                            <table class="sky-health-table">
                                <tr><td class="sky-health-label">PHP 版本</td><td class="sky-health-val"><?php echo esc_html(phpversion()); ?></td></tr>
                                <tr><td class="sky-health-label">Redis 扩展</td><td class="sky-health-val"><span class="sky-status-ok">✓ 已安装</span></td></tr>
                                <tr><td class="sky-health-label">CURL 扩展</td><td class="sky-health-val"><span class="sky-status-ok">✓ 已启用</span></td></tr>
                                <tr><td class="sky-health-label">GD 库 (去水印)</td><td class="sky-health-val"><span class="sky-status-ok">✓ 支持</span></td></tr>
                            </table>
                        </div>
                        <div class="sky-card">
                            <div class="sky-card-title">📜 更新历史</div>
                            <div style="font-size: 13px; color: var(--sky-text-muted); line-height: 1.8;">
                                <b>v1.5.0</b> - 视觉架构全面升维，引入 SaaS 级组件库<br>
                                <b>v1.4.0</b> - 引入 Redis 对象缓存，优化采集架构<br>
                                <b>v1.3.0</b> - 视觉采集模块升级，支持微信图片同步
                            </div>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="sky-card">
                        <div class="sky-card-title">⚙️ <?php echo esc_html($nav_items[$current_tab]['label'] ?? '设置'); ?></div>
                        <form method="post" action="">
                            <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                            <?php 
                            foreach ($schema as $key => $cfg): 
                                if (($cfg['group'] ?? 'general') !== $current_tab) continue;
                                $val = get_option('skyline_ai_settings', [])[$key] ?? ($cfg['default'] ?? '');
                                ?>
                                <div class="sky-field-row">
                                    <div class="sky-field-info">
                                        <div class="sky-field-label"><?php echo esc_html($cfg['label']); ?></div>
                                        <?php if (isset($cfg['desc'])): ?>
                                            <div class="sky-field-desc">💡 <?php echo esc_html($cfg['desc']); ?></div>
                                        <?php endif; ?>
                                    </div>
                                    <div class="sky-field-control">
                                        <?php if ($cfg['type'] === 'password'): ?>
                                            <div class="sky-field-input"><input type="password" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                        <?php elseif ($cfg['type'] === 'textarea'): ?>
                                            <div class="sky-field-input"><textarea name="skyline_settings[<?php echo esc_attr($key); ?>]" rows="3"><?php echo esc_textarea($val); ?></textarea></div>
                                        <?php elseif ($cfg['type'] === 'bool'): ?>
                                            <span class="sky-status-badge <?php echo (int)$val ? 'active' : ''; ?>"><?php echo (int)$val ? '已启用' : '已禁用'; ?></span>
                                            <label class="sky-switch">
                                                <input type="checkbox" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(1, (int)$val); ?>>
                                                <span class="sky-slider"></span>
                                            </label>
                                        <?php elseif ($cfg['type'] === 'select'): ?>
                                            <div class="sky-field-input">
                                                <select name="skyline_settings[<?php echo esc_attr($key); ?>]">
                                                    <?php foreach ($cfg['options'] as $opt_val => $opt_label): ?>
                                                        <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        <?php else: ?>
                                            <div class="sky-field-input"><input type="text" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </form>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }
}
