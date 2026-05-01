<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    private $core;
    
    public function __construct() {
        $this->core = Skyline_Core::instance();
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_settings']);
    }

    public function add_menu() {
        add_menu_page(
            __('Skyline AI Pro', 'skyline-ai-pro'),
            __('Skyline AI', 'skyline-ai-pro'),
            'manage_options',
            'skyline-pro',
            [$this, 'render_main_page'],
            'dashicons-welcome-learn-more',
            60
        );
    }

    public function handle_settings() {
        if (!isset($_POST['skyline_save_settings'])) return;
        
        // 1. Nonce 验证
        if (!wp_verify_nonce($_POST['skyline_nonce'] ?? '', 'skyline_save_action')) {
            add_settings_error('skyline_messages', 'skyline_msg', 
                __('安全验证失败，请重试。', 'skyline-ai-pro'), 'error');
            return;
        }
        
        // 2. 权限检查
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'skyline-ai-pro'));
        }
        
        // 3. 输入过滤
        $posted = $_POST['skyline_settings'] ?? [];
        $schema = $this->core->get_config_schema();
        $current_tab = sanitize_key($_GET['tab'] ?? 'dashboard');
        
        $sanitized = get_option('skyline_ai_settings', []);
        if (!is_array($sanitized)) $sanitized = [];

        foreach ($schema as $key => $cfg) {
            $group = $cfg['group'] ?? 'general';
            if ($group !== $current_tab) continue;

            $type = $cfg['type'] ?? 'text';

            if ($type === 'bool') {
                $sanitized[$key] = isset($posted[$key]);
            } elseif (isset($posted[$key])) {
                if (is_array($posted[$key])) {
                    $sanitized[$key] = array_map('sanitize_text_field', $posted[$key]);
                } else {
                    switch ($type) {
                        case 'url':
                            $sanitized[$key] = esc_url_raw($posted[$key]);
                            break;
                        case 'textarea':
                            $sanitized[$key] = sanitize_textarea_field($posted[$key]);
                            break;
                        case 'number':
                            $sanitized[$key] = absint($posted[$key]);
                            break;
                        case 'password':
                            // 密码字段：如果为空则不更新
                            if (!empty($posted[$key])) {
                                $sanitized[$key] = sanitize_text_field($posted[$key]);
                            }
                            break;
                        default:
                            $sanitized[$key] = sanitize_text_field($posted[$key]);
                            break;
                    }
                }
            }
        }

        update_option('skyline_ai_settings', $sanitized);
        
        // 清除缓存
        $this->core->clear_opt_cache();
        
        add_settings_error('skyline_messages', 'skyline_msg', 
            __('配置已保存！', 'skyline-ai-pro'), 'updated');
    }

    private function get_current_tab() { 
        return sanitize_key($_GET['tab'] ?? 'dashboard'); 
    }

    public function get_system_health() {
        return [
            'php_version' => [
                'status' => version_compare(PHP_VERSION, '7.4', '>=') ? 'ok' : 'warning',
                'label' => 'PHP ' . PHP_VERSION
            ],
            'redis' => [
                'status' => extension_loaded('redis') ? 'ok' : 'error',
                'label' => extension_loaded('redis') ? '✓ ' . __('已安装', 'skyline-ai-pro') : '✗ ' . __('未安装', 'skyline-ai-pro')
            ],
            'curl' => [
                'status' => extension_loaded('curl') ? 'ok' : 'error',
                'label' => extension_loaded('curl') ? '✓ ' . __('已启用', 'skyline-ai-pro') : '✗ ' . __('未启用', 'skyline-ai-pro')
            ],
            'gd' => [
                'status' => extension_loaded('gd') ? 'ok' : 'error',
                'label' => extension_loaded('gd') ? '✓ ' . __('支持', 'skyline-ai-pro') : '✗ ' . __('不支持', 'skyline-ai-pro')
            ],
            'memory' => [
                'status' => $this->check_memory_limit(),
                'label' => ini_get('memory_limit')
            ],
            'upload' => [
                'status' => 'ok',
                'label' => ini_get('upload_max_filesize')
            ]
        ];
    }
    
    private function check_memory_limit() {
        $limit = ini_get('memory_limit');
        $bytes = $this->convert_to_bytes($limit);
        return $bytes >= 134217728 ? 'ok' : 'warning'; // 128MB
    }
    
    private function convert_to_bytes($val) {
        $val = trim($val);
        $last = strtolower($val[strlen($val)-1]);
        $val = intval($val);
        switch ($last) {
            case 'g': $val *= 1024;
            case 'm': $val *= 1024;
            case 'k': $val *= 1024;
        }
        return $val;
    }

    public function render_main_page() {
        $current_tab = $this->get_current_tab();
        $schema = $this->core->get_config_schema();
        $health = $this->get_system_health();
        
        if (!is_array($schema)) $schema = [];

        $nav_items = [
            'dashboard' => ['label' => __('数据看板', 'skyline-ai-pro'), 'icon' => '📊'],
            'ai' => ['label' => __('智能核心', 'skyline-ai-pro'), 'icon' => '🤖'],
            'sync' => ['label' => __('内容同步', 'skyline-ai-pro'), 'icon' => '🔄'],
            'oss' => ['label' => __('云端存储', 'skyline-ai-pro'), 'icon' => '☁️'],
            'seo' => ['label' => __('搜索优化', 'skyline-ai-pro'), 'icon' => '🚀'],
            'speed' => ['label' => __('性能体检', 'skyline-ai-pro'), 'icon' => '⚡'],
            'logs' => ['label' => __('系统日志', 'skyline-ai-pro'), 'icon' => '📜'],
        ];
        ?>
        <form method="post" action="" id="skyline-settings-form">
        <div class="sky-wrapper">
            <div class="sky-sidebar">
                <div class="sky-brand">
                    <div class="sky-brand-logo">S</div>
                    <div>
                        <span class="sky-brand-text">Skyline AI Pro</span>
                        <span class="sky-brand-ver">v<?php echo SKY_VERSION; ?></span>
                    </div>
                </div>
                <div class="sky-nav">
                    <?php foreach ($nav_items as $id => $item): ?>
                        <a href="?page=skyline-pro&tab=<?php echo esc_attr($id); ?>" 
                           class="sky-nav-item <?php echo $current_tab === $id ? 'active' : ''; ?>">
                            <span><?php echo $item['icon']; ?></span> 
                            <?php echo esc_html($item['label']); ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="sky-sidebar-footer">
                    <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                    <button type="submit" name="skyline_save_settings" class="sky-save-btn">
                        <?php _e('保存所有配置', 'skyline-ai-pro'); ?>
                    </button>
                    <div class="sky-brand-box">
                        <span class="logo">灵感屋 LgWu</span>
                        <span class="url">www.lgwu.net</span>
                    </div>
                </div>
            </div>
            <div class="sky-main">
                <div class="sky-welcome">
                    <h2>👋 <?php printf(__('欢迎回来, %s', 'skyline-ai-pro'), wp_get_current_user()->display_name); ?></h2>
                </div>
                <?php settings_errors('skyline_messages'); ?>
                
                <?php if ($current_tab === 'dashboard'): ?>
                    <?php $this->render_dashboard($health); ?>
                <?php elseif ($current_tab === 'logs'): ?>
                    <?php $this->render_logs(); ?>
                <?php else: ?>
                    <?php $this->render_settings_tab($current_tab, $schema); ?>
                <?php endif; ?>
            </div>
        </div>
        </form>
        <?php
    }

    private function render_dashboard($health) {
        ?>
        <div class="sky-card">
            <div class="sky-card-title">✨ <?php _e('插件能力概览', 'skyline-ai-pro'); ?></div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                <?php _e('Skyline AI Pro 是您的智能运营中台。集成 DeepSeek V3，实现 AI 写作与绘图；配合 Redis 缓存与 OSS 云存储，将站点速度推向极致。', 'skyline-ai-pro'); ?>
            </p>
        </div>
        
        <div class="sky-stats-grid">
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span>
                <span class="sky-stat-lbl"><?php _e('AI 调用次数', 'skyline-ai-pro'); ?></span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('sync_count')); ?></span>
                <span class="sky-stat-lbl"><?php _e('资源同步数', 'skyline-ai-pro'); ?></span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_errors')); ?></span>
                <span class="sky-stat-lbl"><?php _e('API 错误', 'skyline-ai-pro'); ?></span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('cache_hits')); ?></span>
                <span class="sky-stat-lbl"><?php _e('缓存命中', 'skyline-ai-pro'); ?></span>
            </div>
        </div>
        
        <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
            <div class="sky-card">
                <div class="sky-card-title">🏥 <?php _e('系统健康', 'skyline-ai-pro'); ?></div>
                <div class="sky-health-grid">
                    <?php foreach ($health as $key => $item): ?>
                        <div class="sky-health-item">
                            <span class="sky-health-dot sky-health-<?php echo esc_attr($item['status']); ?>"></span>
                            <span class="sky-health-label"><?php echo esc_html($item['label']); ?></span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <div class="sky-card">
                <div class="sky-card-title">📋 <?php _e('快速操作', 'skyline-ai-pro'); ?></div>
                <div class="sky-quick-actions">
                    <button type="button" class="sky-btn sky-btn-secondary" onclick="skylineTestApi()">
                        🔌 <?php _e('测试 API 连接', 'skyline-ai-pro'); ?>
                    </button>
                    <button type="button" class="sky-btn sky-btn-secondary" onclick="skylineTestRedis()">
                        🗄️ <?php _e('测试 Redis 连接', 'skyline-ai-pro'); ?>
                    </button>
                    <button type="button" class="sky-btn sky-btn-secondary" onclick="skylineClearLogs()">
                        🗑️ <?php _e('清除日志', 'skyline-ai-pro'); ?>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    private function render_logs() {
        $log_file = WP_CONTENT_DIR . '/logs/skyline/skyline_ai.log';
        $lines = [];
        
        if (file_exists($log_file)) {
            $content = file_get_contents($log_file);
            $lines = array_filter(explode("\n", $content));
            $lines = array_slice($lines, -100); // 最后100行
        }
        ?>
        <div class="sky-card">
            <div class="sky-card-title">📜 <?php _e('系统日志', 'skyline-ai-pro'); ?></div>
            <div class="sky-log-container">
                <?php if (empty($lines)): ?>
                    <p class="sky-log-empty"><?php _e('暂无日志记录', 'skyline-ai-pro'); ?></p>
                <?php else: ?>
                    <pre class="sky-log-content"><?php foreach ($lines as $line): ?>
<span class="sky-log-line"><?php echo esc_html($line); ?></span>
<?php endforeach; ?></pre>
                <?php endif; ?>
            </div>
            <div class="sky-log-actions">
                <button type="button" class="sky-btn sky-btn-secondary" onclick="skylineClearLogs()">
                    🗑️ <?php _e('清除日志', 'skyline-ai-pro'); ?>
                </button>
                <button type="button" class="sky-btn sky-btn-secondary" onclick="location.reload()">
                    🔄 <?php _e('刷新', 'skyline-ai-pro'); ?>
                </button>
            </div>
        </div>
        <?php
    }

    private function render_settings_tab($current_tab, $schema) {
        $current_group_items = [];
        foreach ($schema as $key => $cfg) {
            if (($cfg['group'] ?? 'general') === $current_tab) {
                $current_group_items[] = ['key' => $key, 'cfg' => $cfg];
            }
        }
        
        if (empty($current_group_items)) {
            echo '<div class="sky-card"><p>' . __('该分类暂无配置项', 'skyline-ai-pro') . '</p></div>';
            return;
        }
        
        // 分块显示
        $chunks = array_chunk($current_group_items, 8);
        foreach ($chunks as $index => $chunk) {
            echo '<div class="sky-card">';
            echo '<div class="sky-card-title">';
            echo $index === 0 ? '⚙️ ' . esc_html($this->get_tab_label($current_tab)) : '➕ ' . __('附加配置', 'skyline-ai-pro');
            echo '</div>';
            
            echo '<div class="sky-settings-grid">';
            foreach ($chunk as $item) {
                $this->render_setting_field($item['key'], $item['cfg']);
            }
            echo '</div>';
            
            echo '</div>';
        }
    }
    
    private function get_tab_label($tab) {
        $labels = [
            'ai' => __('智能核心', 'skyline-ai-pro'),
            'sync' => __('内容同步', 'skyline-ai-pro'),
            'oss' => __('云端存储', 'skyline-ai-pro'),
            'seo' => __('搜索优化', 'skyline-ai-pro'),
            'speed' => __('性能体检', 'skyline-ai-pro'),
        ];
        return $labels[$tab] ?? $tab;
    }

    private function render_setting_field($key, $cfg) {
        $type = $cfg['type'] ?? 'text';
        $label = $cfg['label'] ?? $key;
        $desc = $cfg['desc'] ?? '';
        $value = $this->core->get_opt($key);
        $default = $cfg['default'] ?? '';
        
        echo '<div class="sky-field">';
        echo '<label class="sky-field-label" for="sky_' . esc_attr($key) . '">' . esc_html($label) . '</label>';
        
        switch ($type) {
            case 'bool':
                echo '<label class="sky-switch">';
                echo '<input type="checkbox" id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" ' . checked($value, true, false) . '>';
                echo '<span class="sky-slider"></span>';
                echo '</label>';
                break;
                
            case 'textarea':
                echo '<textarea id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" class="sky-textarea" rows="4">' . esc_textarea($value) . '</textarea>';
                break;
                
            case 'select':
                echo '<select id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" class="sky-select">';
                foreach ($cfg['options'] ?? [] as $opt_val => $opt_label) {
                    echo '<option value="' . esc_attr($opt_val) . '"' . selected($value, $opt_val, false) . '>' . esc_html($opt_label) . '</option>';
                }
                echo '</select>';
                break;
                
            case 'password':
                echo '<input type="password" id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" class="sky-input" value="" placeholder="' . __('输入以更新', 'skyline-ai-pro') . '">';
                break;
                
            case 'number':
                echo '<input type="number" id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" class="sky-input" value="' . esc_attr($value) . '" min="0">';
                break;
                
            default:
                echo '<input type="' . esc_attr($type) . '" id="sky_' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" class="sky-input" value="' . esc_attr($value) . '">';
                break;
        }
        
        if ($desc) {
            echo '<p class="sky-field-desc">' . esc_html($desc) . '</p>';
        }
        
        echo '</div>';
    }
}
