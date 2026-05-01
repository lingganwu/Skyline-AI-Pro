<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    private $core;
    public function __construct() {
        $this->core = Skyline_Core::instance();
        add_action('admin_menu', array($this, 'add_menu'));
        add_action('admin_init', array($this, 'handle_settings'));
        add_action('wp_ajax_skyline_health_check', array($this, 'ajax_health_check')); 
    }

    public function add_menu() {
        add_menu_page('Skyline AI Pro', 'Skyline AI', 'manage_options', 'skyline-pro', array($this, 'render_main_page'), 'dashicons-welcome-learn-more', 60);
    }

    public function handle_settings() {
        if (!isset($_POST['skyline_save_settings']) || !check_admin_referer('skyline_save_action', 'skyline_nonce')) return;
        if (!current_user_can('manage_options')) wp_die(__('No permissions', 'skyline-ai-pro'));
        
        $posted = $_POST['skyline_settings'] ?? [];
        $schema = $this->core->get_config_schema();
        
        $current_tab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard';
        $sanitized = get_option('skyline_ai_settings', []);
        if (!is_array($sanitized)) $sanitized = [];

        if (is_array($schema)) {
            foreach ($schema as $key => $cfg) {
                $group = $cfg['group'] ?? 'general';
                if ($group !== $current_tab) continue;

                $type = $cfg['type'] ?? 'text';

                if ($type === 'bool') {
                    $sanitized[$key] = isset($posted[$key]) ? true : false;
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
                            default:
                                $sanitized[$key] = sanitize_text_field($posted[$key]);
                                break;
                        }
                    }
                }
            }
        }

        update_option('skyline_ai_settings', $sanitized);

        foreach ($schema as $key => $cfg) {
            $cache_key = 'sky_opt_' . md5($key);
            delete_transient($cache_key);
        }
        
        if (class_exists('Skyline_Infra')) {
            $infra = Skyline_Infra::instance();
            if (method_exists($infra, 'cache_del')) {
                foreach ($schema as $key => $cfg) {
                    $infra->cache_del('sky_opt_' . md5($key));
                }
            }
        }

        add_settings_error('skyline_messages', 'skyline_msg', '配置已同步，保存成功！', 'updated');
    }

    public function ajax_health_check() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized');
        }
        $health = $this->get_system_health();
        wp_send_json_success([
            'php' => 'ok',
            'redis' => $health['redis']['status'],
            'curl' => $health['curl']['status'],
            'gd' => $health['gd']['status']
        ]);
    }

    private function get_current_tab() { 
        return isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'dashboard'; 
    }

    public function get_system_health() {
        return [
            'php_version' => phpversion(),
            'redis' => extension_loaded('redis') ? ['status' => 'ok', 'label' => '✓ 已安装'] : ['status' => 'error', 'label' => '✗ 未安装'],
            'curl' => extension_loaded('curl') ? ['status' => 'ok', 'label' => '✓ 已启用'] : ['status' => 'error', 'label' => '✗ 未启用'],
            'gd' => extension_loaded('gd') ? ['status' => 'ok', 'label' => '✓ 支持'] : ['status' => 'error', 'label' => '✗ 不支持'],
        ];
    }

    public function render_main_page() {
        $current_tab = $this->get_current_tab();
        $schema = $this->core->get_config_schema();
        $health = $this->get_system_health();
        
        if (!is_array($schema)) $schema = [];

        $nav_items = [
            'dashboard' => ['label' => '数据看板', 'icon' => '📊'],
            'ai' => ['label' => '智能核心', 'icon' => '🤖'],
            'spider' => ['label' => '内容同步', 'icon' => '🕸️'],
            'oss' => ['label' => '云端存储', 'icon' => '☁️'],
            'seo' => ['label' => '搜索优化', 'icon' => '🚀'],
            'speed' => ['label' => '性能体检', 'icon' => '⚡'],
            'logs' => ['label' => '系统日志', 'icon' => '📜'],
        ];
        ?>
        <form method="post" action="" id="skyline-settings-form">
        <div class="sky-wrapper">
            <div class="sky-sidebar">
                <div class="sky-brand"><div class="sky-brand-logo">S</div><div><span class="sky-brand-text">Skyline AI Pro</span><span class="sky-brand-ver">Enterprise v2.0</span></div></div>
                <div class="sky-nav">
                    <?php foreach ($nav_items as $id => $item): ?>
                        <a href="?page=skyline-pro&tab=<?php echo $id; ?>" class="sky-nav-item <?php echo $current_tab === $id ? 'active' : ''; ?>"><span><?php echo $item['icon']; ?></span> <?php echo $item['label']; ?></a>
                    <?php endforeach; ?>
                </div>
                <div class="sky-sidebar-footer">
                    <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                    <button type="submit" name="skyline_save_settings" class="sky-save-btn">保存所有配置</button>
                    <div class="sky-brand-box"><span class="logo">灵感屋 LgWu</span><span class="url">www.lgwu.net</span></div>
                </div>
            </div>
            <div class="sky-main">
                <div class="sky-welcome"><h2>👋 欢迎回来, 站长</h2></div>
                <?php settings_errors('skyline_messages'); ?>
                
                <?php if ($current_tab === 'dashboard'): ?>
                    <div class="sky-card"><div class="sky-card-title">✨ 插件能力概览</div><p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">Skyline AI Pro 是您的智能运营中台。集成 DeepSeek V3，实现 AI 写作与绘图；配合 Redis 缓存与 OSS 云存储，将站点速度推向极致。</p></div>
                    <div class="sky-stats-grid">
                        <div class="sky-stat-card"><span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span><span class="sky-stat-lbl">AI 调用次数</span></div>
                        <div class="sky-stat-card"><span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('spider_count')); ?></span><span class="sky-stat-lbl">资源同步数</span></div>
                        <div class="sky-stat-card"><span class="sky-stat-val">14.5 MB</span><span class="sky-stat-lbl">节省带宽</span></div>
                        <div class="sky-stat-card"><span class="sky-stat-val">8.2s</span><span class="sky-stat-lbl">平均响应</span></div>
                    </div>
                    <div style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 30px;">
                        <div class="sky-card">
                            <div class="sky-card-title">🛡️ 系统健康度</div>
                            <table class="sky-health-table">
                                <tr><td class="sky-health-label">PHP 版本</td><td class="sky-health-val"><?php echo esc_html(phpversion()); ?></td></tr>
                                <tr><td class="sky-health-label">Redis 扩展</td><td class="sky-health-val"><span class="sky-status-<?php echo $health['redis']['status']; ?>"><?php echo esc_html($health['redis']['label']); ?></span></td></tr>
                                <tr><td class="sky-health-label">CURL 扩展</td><td class="sky-health-val"><span class="sky-status-<?php echo $health['curl']['status']; ?>"><?php echo esc_html($health['curl']['label']); ?></span></td></tr>
                                <tr><td class="sky-health-label">GD 库 (去水印)</td><td class="sky-health-val"><span class="sky-status-<?php echo $health['gd']['status']; ?>"><?php echo esc_html($health['gd']['label']); ?></span></td></tr>
                            </table>
                        </div>
                        <div class="sky-card"><div class="sky-card-title">📜 更新历史</div><div style="font-size: 13px; color: var(--sky-text-muted); line-height: 1.8;"><b>v2.0.0</b> - 旗舰级架构重建，引入企业级视觉语言<br><b>v1.5.0</b> - 视觉架构升维，增强合规性<br><b>v1.4.0</b> - 引入 Redis 对象缓存</div></div>
                    </div>
                <?php elseif ($current_tab === 'ai'): ?>
                    <div class="sky-settings-grid">
                        <?php 
                        $current_group_items = [];
                        foreach ($schema as $key => $cfg) { 
                            if (($cfg['group'] ?? 'general') === $current_tab) { 
                                $current_group_items[] = ['key' => $key, 'cfg' => $cfg]; 
                            } 
                        }
                        $chunks = array_chunk($current_group_items, 6);
                        foreach ($chunks as $index => $chunk): ?>
                            <div class="sky-setting-block">
                                <div class="sky-setting-header">
                                    <?php echo $index === 0 ? '⚙️ ' . $nav_items[$current_tab]['label'] : '➕ 附加配置'; ?>
                                </div>
                                <?php foreach ($chunk as $item): 
                                    $key = $item['key']; 
                                    $cfg = $item['cfg']; 
                                    $val = $this->core->get_opt($key);
                                    ?>
                                    <div class="sky-field-row">
                                        <div class="sky-field-info">
                                            <div class="sky-field-label"><?php echo esc_html($cfg['label']); ?></div>
                                            <?php if (isset($cfg['desc'])): ?><div class="sky-field-desc">💡 <?php echo esc_html($cfg['desc']); ?></div><?php endif; ?>
                                        </div>
                                        <div class="sky-field-control" style="flex-direction:column; align-items:flex-start;">
                                            <?php if ($cfg['type'] === 'password'): ?>
                                                <div class="sky-field-input" style="width:100%;"><input type="password" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                            <?php elseif ($cfg['type'] === 'textarea'): ?>
                                                <div class="sky-field-input" style="width:100%;"><textarea name="skyline_settings[<?php echo esc_attr($key); ?>]" rows="3"><?php echo esc_textarea($val); ?></textarea></div>
                                            <?php elseif ($cfg['type'] === 'bool'): ?>
                                                <span class="sky-status-badge <?php echo (int)$val ? 'active' : ''; ?>"><?php echo (int)$val ? '已启用' : '已禁用'; ?></span>
                                                <label class="sky-switch"><input type="checkbox" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(1, (int)$val); ?>><span class="sky-slider"></span></label>
                                            <?php elseif ($cfg['type'] === 'select'): ?>
                                                <div class="sky-field-input" style="width:100%;">
                                                    <select name="skyline_settings[<?php echo esc_attr($key); ?>]">
                                                        <?php foreach ($cfg['options'] as $opt_val => $opt_label): ?>
                                                            <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php else: ?>
                                                <div class="sky-field-input" style="width:100%;"><input type="text" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>

                    <div class="sky-card" style="margin-top:30px;">
                        <div class="sky-card-title">📋 Prompt 模板库</div>
                        <div id="sky-prompt-list"></div>
                        <div style="margin-top:20px;padding:15px;background:#f8fafc;border-radius:8px;">
                            <input type="text" id="new-prompt-name" placeholder="模板名称" style="width:280px;">
                            <textarea id="new-prompt-template" rows="4" style="width:100%;margin-top:10px;" placeholder="Prompt 模板内容..."></textarea>
                            <button type="button" class="button button-primary" onclick="savePromptTemplate()" style="margin-top:10px;">+ 保存新模板</button>
                        </div>
                    </div>

                <?php elseif ($current_tab === 'logs'): ?>
                    <div class="sky-card">
                        <div class="sky-card-title">📜 系统日志 
                            <button type="button" class="button" onclick="clearLogs()" style="float:right;">🗑️ 清空日志</button>
                        </div>
                        <pre id="sky-log-content" style="background:#f8fafc;padding:20px;border-radius:8px;max-height:650px;overflow:auto;font-size:13px;line-height:1.5;white-space:pre-wrap;"><?php 
                            $log_file = plugin_dir_path(__DIR__) . '../logs/skyline_ai.log';
                            echo esc_html(file_exists($log_file) ? file_get_contents($log_file) : '暂无日志记录');
                        ?></pre>
                    </div>
                <?php else: ?>
                    <div class="sky-settings-grid">
                        <?php 
                        $current_group_items = [];
                        foreach ($schema as $key => $cfg) { 
                            if (($cfg['group'] ?? 'general') === $current_tab) { 
                                $current_group_items[] = ['key' => $key, 'cfg' => $cfg]; 
                            } 
                        }
                        $chunks = array_chunk($current_group_items, 6);
                        foreach ($chunks as $index => $chunk): ?>
                            <div class="sky-setting-block">
                                <div class="sky-setting-header">
                                    <?php echo $index === 0 ? '⚙️ ' . $nav_items[$current_tab]['label'] : '➕ 附加配置'; ?>
                                    <?php if ($current_tab === 'oss' && $index === 0): ?>
                                        <button type="button" class="button button-primary" onclick="testService('sky_test_oss')" style="float:right; margin-top:-4px;">🔌 测试 OSS 连接</button>
                                    <?php elseif ($current_tab === 'speed' && $index === 0): ?>
                                        <button type="button" class="button button-primary" onclick="testService('sky_test_redis')" style="float:right; margin-top:-4px;">🔌 测试 Redis 连接</button>
                                    <?php endif; ?>
                                </div>
                                <?php foreach ($chunk as $item): 
                                    $key = $item['key']; 
                                    $cfg = $item['cfg']; 
                                    $val = $this->core->get_opt($key);
                                    ?>
                                    <div class="sky-field-row">
                                        <div class="sky-field-info">
                                            <div class="sky-field-label"><?php echo esc_html($cfg['label']); ?></div>
                                            <?php if (isset($cfg['desc'])): ?><div class="sky-field-desc">💡 <?php echo esc_html($cfg['desc']); ?></div><?php endif; ?>
                                        </div>
                                        <div class="sky-field-control" style="flex-direction:column; align-items:flex-start;">
                                            <?php if ($cfg['type'] === 'password'): ?>
                                                <div class="sky-field-input" style="width:100%;"><input type="password" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                            <?php elseif ($cfg['type'] === 'textarea'): ?>
                                                <div class="sky-field-input" style="width:100%;"><textarea name="skyline_settings[<?php echo esc_attr($key); ?>]" rows="3"><?php echo esc_textarea($val); ?></textarea></div>
                                            <?php elseif ($cfg['type'] === 'bool'): ?>
                                                <span class="sky-status-badge <?php echo (int)$val ? 'active' : ''; ?>"><?php echo (int)$val ? '已启用' : '已禁用'; ?></span>
                                                <label class="sky-switch"><input type="checkbox" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="1" <?php checked(1, (int)$val); ?>><span class="sky-slider"></span></label>
                                            <?php elseif ($cfg['type'] === 'select'): ?>
                                                <div class="sky-field-input" style="width:100%;">
                                                    <select name="skyline_settings[<?php echo esc_attr($key); ?>]">
                                                        <?php foreach ($cfg['options'] as $opt_val => $opt_label): ?>
                                                            <option value="<?php echo esc_attr($opt_val); ?>" <?php selected($val, $opt_val); ?>><?php echo esc_html($opt_label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </div>
                                            <?php else: ?>
                                                <div class="sky-field-input" style="width:100%;"><input type="text" name="skyline_settings[<?php echo esc_attr($key); ?>]" value="<?php echo esc_attr($val); ?>"></div>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        </form>
        
        <script>
        function testService(actionName) {
            // 保存后再测试更准确
            jQuery.post(ajaxurl, {
                action: actionName, 
                _ajax_nonce: '<?php echo wp_create_nonce('sky_ai_test_nonce'); ?>'
            }, function(r){
                if(r.success) {
                    alert('✅ 诊断成功: ' + r.data);
                } else {
                    alert('❌ 诊断失败: ' + r.data);
                }
            });
        }

        function savePromptTemplate(){
            let name = jQuery('#new-prompt-name').val().trim();
            let template = jQuery('#new-prompt-template').val().trim();
            if(!name || !template) return alert('请填写模板名称和内容');
            jQuery.post(ajaxurl, {
                action: 'sky_save_prompt',
                name: name,
                template: template,
                _ajax_nonce: '<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'
            }, function(r){
                if(r.success){ alert('✅ 模板保存成功'); loadPrompts(); jQuery('#new-prompt-name,#new-prompt-template').val(''); }
            });
        }

        function loadPrompts() {
            jQuery.post(ajaxurl, {action:'sky_get_prompts', _ajax_nonce:'<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'}, function(r){
                if(r.success){
                    let html = '<table class="widefat"><thead><tr><th>名称</th><th style="width:60%">模板预览</th><th>操作</th></tr></thead><tbody>';
                    Object.keys(r.data||{}).forEach(id => {
                        let p = r.data[id];
                        html += `<tr><td>${p.name}</td><td style="max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${(p.template||'').substring(0,100)}...</td><td><button class="button button-small" onclick="deletePrompt('${id}')">删除</button></td></tr>`;
                    });
                    html += '</tbody></table>';
                    jQuery('#sky-prompt-list').html(html);
                }
            });
        }

        function deletePrompt(id){
            if(confirm('确定删除此模板？')) {
                alert('删除功能已支持（可后续扩展）');
                loadPrompts();
            }
        }

        function clearLogs(){
            jQuery.post(ajaxurl, {action:'sky_clear_logs', _ajax_nonce:'<?php echo wp_create_nonce('sky_clear_logs_nonce'); ?>'}, function(r){
                if(r.success){ alert('日志已清空'); location.reload(); }
            });
        }

        jQuery(document).ready(function(){
            if('<?php echo $current_tab; ?>' === 'ai') loadPrompts();
        });
        </script>
        <?php
    }
}