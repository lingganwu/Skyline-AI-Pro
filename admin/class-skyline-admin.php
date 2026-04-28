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
        
        foreach ($settings as $key => $val) {
            if (is_array($val)) continue;
            $settings[$key] = sanitize_text_field($val);
        }
        
        update_option('skyline_ai_settings', $settings);
        add_settings_error('sky_msg', 'sky_msg', '配置已保存', 'updated');
    }

    public function render_page() {
        $settings = get_option('skyline_ai_settings', []);
        $active_tab = $_GET['tab'] ?? 'general';
        ?>
        <div class="wrap">
            <style>
                .sky-admin-header { background: #4f46e5; color: white; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
                .sky-tabs { display: flex; gap: 10px; margin-bottom: 20px; border-bottom: 2px solid #ddd; padding-bottom: 10px; }
                .sky-tab { padding: 10px 20px; cursor: pointer; text-decoration: none; color: #666; border-radius: 5px; background: #f0f0f0; font-weight: 500; }
                .sky-tab.active { background: #4f46e5; color: white; }
                .sky-panel { background: white; padding: 20px; border: 1px solid #ddd; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
                .sky-field-row { margin-bottom: 15px; display: flex; align-items: center; }
                .sky-field-label { width: 200px; font-weight: 600; }
                .sky-field-input { flex: 1; }
            </style>
            <div class="sky-admin-header">
                <h1>🚀 Skyline AI Pro 控制中心</h1>
                <p>灵感屋 (lgwu.net) 旗舰级智能运营中台 - V2.0.0</p>
            </div>

            <div class="sky-tabs">
                <a href="?page=skyline-pro&tab=general" class="sky-tab <?php echo $active_tab === 'general' ? 'active' : ''; ?>">⚙️ 核心配置</a>
                <a href="?page=skyline-pro&tab=cos" class="sky-tab <?php echo $active_tab === 'cos' ? 'active' : ''; ?>">☁️ 存储同步 (COS)</a>
                <a href="?page=skyline-pro&tab=redis" class="sky-tab <?php echo $active_tab === 'redis' ? 'active' : ''; ?>">⚡ 缓存加速 (Redis)</a>
                <a href="?page=skyline-pro&tab=ai" class="sky-tab <?php echo $active_tab === 'ai' ? 'active' : ''; ?>">🤖 AI 模型</a>
            </div>

            <form method="post">
                <?php wp_nonce_field('sky_save', 'sky_nonce'); ?>
                <div class="sky-panel">
                    <?php if ($active_tab === 'general'): ?>
                        <h3>核心通用设置</h3>
                        <div class="sky-field-row">
                            <div class="sky-field-label">插件状态</div>
                            <div class="sky-field-input">
                                <input type="checkbox" name="skyline_settings[sky_enabled]" value="yes" <?php checked($settings['sky_enabled'] ?? '', 'yes'); ?>> 启用所有 AI 功能
                            </div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">日志等级</div>
                            <div class="sky-field-input">
                                <select name="skyline_settings[log_level]">
                                    <option value="info" <?php selected($settings['log_level'] ?? '', 'info'); ?>>Info (标准)</option>
                                    <option value="debug" <?php selected($settings['log_level'] ?? '', 'debug'); ?>>Debug (调试)</option>
                                    <option value="error" <?php selected($settings['log_level'] ?? '', 'error'); ?>>Error (仅错误)</option>
                                </select>
                            </div>
                        </div>
                    <?php elseif ($active_tab === 'cos'): ?>
                        <h3>Tencent COS 存储同步</h3>
                        <div class="sky-field-row">
                            <div class="sky-field-label">COS 启用</div>
                            <div class="sky-field-input">
                                <input type="checkbox" name="skyline_settings[oss_enable]" value="yes" <?php checked($settings['oss_enable'] ?? '', 'yes'); ?>> 启用同步至云存储
                            </div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">SecretId</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[oss_ak]" value="<?php echo esc_attr($settings['oss_ak'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">SecretKey</div>
                            <div class="sky-field-input"><input type="password" class="regular-text" name="skyline_settings[oss_sk]" value="<?php echo esc_attr($settings['oss_sk'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">存储桶 (Bucket)</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[oss_bucket]" value="<?php echo esc_attr($settings['oss_bucket'] ?? ''); ?>"></td></tr>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Endpoint</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[oss_endpoint]" value="<?php echo esc_attr($settings['oss_endpoint'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">访问域名 (Domain)</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[oss_domain]" value="<?php echo esc_attr($settings['oss_domain'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">同步后删除本地</div>
                            <div class="sky-field-input">
                                <input type="checkbox" name="skyline_settings[oss_delete_local]" value="yes" <?php checked($settings['oss_delete_local'] ?? '', 'yes'); ?>> 同步成功后删除本地副本
                            </div>
                        </div>
                    <?php elseif ($active_tab === 'redis'): ?>
                        <h3>Redis 高速缓存</h3>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Redis 启用</div>
                            <div class="sky-field-input">
                                <input type="checkbox" name="skyline_settings[redis_enable]" value="yes" <?php checked($settings['redis_enable'] ?? '', 'yes'); ?>> 启用页面/对象级缓存
                            </div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Redis Host</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[redis_host]" value="<?php echo esc_attr($settings['redis_host'] ?? '127.0.0.1'); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Redis Port</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[redis_port]" value="<?php echo esc_attr($settings['redis_port'] ?? '6379'); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Redis Auth</div>
                            <div class="sky-field-input"><input type="password" class="regular-text" name="skyline_settings[redis_auth]" value="<?php echo esc_attr($settings['redis_auth'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">Redis DB</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[redis_db]" value="<?php echo esc_attr($settings['redis_db'] ?? '0'); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">排除路径</div>
                            <div class="sky-field-input">
                                <textarea name="skyline_settings[redis_exclude]" rows="5" class="large-text" placeholder="每行一个路径，例如 /wp-admin/"><?php echo esc_textarea($settings['redis_exclude'] ?? ''); ?></textarea>
                            </div>
                        </div>
                    <?php elseif ($active_tab === 'ai'): ?>
                        <h3>AI 核心参数</h3>
                        <div class="sky-field-row">
                            <div class="sky-field-label">API Key</div>
                            <div class="sky-field-input"><input type="password" class="regular-text" name="skyline_settings[api_key]" value="<?php echo esc_attr($settings['api_key'] ?? ''); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">API Endpoint</div>
                            <div class="sky-field-input"><input type="text" class="regular-text" name="skyline_settings[api_url]" value="<?php echo esc_attr($settings['api_url'] ?? 'https://api.deepseek.com/v1/chat/completions'); ?>"></div>
                        </div>
                        <div class="sky-field-row">
                            <div class="sky-field-label">默认模型</div>
                            <div class="sky-field-input">
                                <select name="skyline_settings[api_model]">
                                    <option value="deepseek-chat" <?php selected($settings['api_model'] ?? '', 'deepseek-chat'); ?>>DeepSeek V3 (默认)</option>
                                    <option value="gpt-4" <?php selected($settings['api_model'] ?? '', 'gpt-4'); ?>>GPT-4</option>
                                    <option value="claude-3" <?php selected($settings['api_model'] ?? '', 'claude-3'); ?>>Claude 3</option>
                                </select>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <input type="submit" name="save_skyline" class="button-primary" value="保存当前页配置" style="padding: 10px 30px; font-size: 16px;">
                </div>
            </form>
        </div>
        <?php
    }
}
