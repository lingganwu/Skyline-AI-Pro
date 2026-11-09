<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    private $core;
    
    public function __construct() {
        $this->core = Skyline_Core::instance();
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_settings']);
        add_action('wp_ajax_skyline_health_check', [$this, 'ajax_health_check']);
    }

    public function add_menu() {
        add_menu_page(
            'Skyline AI Pro',
            'Skyline AI',
            'manage_options',
            'skyline-pro',
            [$this, 'render_main_page'],
            'dashicons-welcome-learn-more',
            60
        );
    }

    public function handle_settings() {
        if (!isset($_POST['skyline_save_settings']) || !wp_verify_nonce($_POST['skyline_nonce'] ?? '', 'skyline_save_action')) {
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_die(__('权限不足', 'skyline-ai-pro'));
        }
        
        $posted = $_POST['skyline_settings'] ?? [];
        $schema = $this->core->get_config_schema();
        $current_tab = sanitize_key($_GET['tab'] ?? 'dashboard');

        $sanitized = get_option('skyline_ai_settings', []);
        if (!is_array($sanitized)) $sanitized = [];

        // S2：只统计“确实写了字段”的次数。数据看板（dashboard）与系统
        // 日志（logs）在 schema 里根本没有对应 group，循环会跳过全部字段、
        // 一个字都没写——此时绝不能再报“✅ 配置已保存！”。
        $wrote = 0;

        foreach ($schema as $key => $cfg) {
            $group = $cfg['group'] ?? 'general';
            if ($group !== $current_tab) continue;

            $type = $cfg['type'] ?? 'text';

            if ($type === 'bool') {
                // 复选框未勾选时键缺失，本身就是“存 false”：算一次真实写入。
                $sanitized[$key] = isset($posted[$key]);
                $wrote++;
            } elseif ($type === 'spider_mode') {
                // 图片同步方式：只接受精确的 '1'（自动），其余一律按 '0'
                // （手动）存。不能走默认 sanitize_text_field——任意非空字符
                // 串在 PHP 里都是真值，篡改提交 'yes' 会被 trigger_async_tasks
                // 误读成自动同步。缺键时也按手动存（fail-closed：不自动同步
                // 总比意外自动同步安全；正常从内容同步页提交时两个单选项必
                // 有一个被选中，键不会缺）。
                $sanitized[$key] = (isset($posted[$key]) && $posted[$key] === '1') ? '1' : '0';
                $wrote++;
            } elseif (isset($posted[$key])) {
                if (is_array($posted[$key])) {
                    $sanitized[$key] = array_map('sanitize_text_field', $posted[$key]);
                    $wrote++;
                } else {
                    switch ($type) {
                        case 'url':
                            $sanitized[$key] = esc_url_raw($posted[$key]);
                            $wrote++;
                            break;
                        case 'textarea':
                            $sanitized[$key] = sanitize_textarea_field($posted[$key]);
                            $wrote++;
                            break;
                        case 'number':
                            $sanitized[$key] = absint($posted[$key]);
                            $wrote++;
                            break;
                        case 'password':
                            // M21：密码字段留空表示“不改”，绝不拿空值覆盖已
                            // 有的密钥（render_field 也不再回显密钥原文）。
                            // 只有用户主动填入新值时才更新。
                            if (trim((string)$posted[$key]) !== '') {
                                $sanitized[$key] = sanitize_text_field($posted[$key]);
                                $wrote++;
                            }
                            break;
                        default:
                            $sanitized[$key] = sanitize_text_field($posted[$key]);
                            $wrote++;
                            break;
                    }
                }
            }
        }

        if ($wrote === 0) {
            // S2：本页无可保存的配置（看板/日志页）——不写库、不清缓存，
            // 并明确告诉用户什么都没存，而不是报一次假成功。
            add_settings_error('skyline_messages', 'skyline_msg', 'ℹ️ 本页无可保存的配置，未做任何修改。', 'error');
            return;
        }

        // L33：密钥不要 autoload——新 option 默认 autoload 会让每个请求
        // 都把密钥读进内存。
        update_option('skyline_ai_settings', $sanitized, false);

        foreach ($schema as $key => $cfg) {
            delete_transient('sky_opt_' . md5($key));
        }

        if (class_exists('Skyline_Infra')) {
            $infra = Skyline_Infra::instance();
            if (method_exists($infra, 'cache_del')) {
                foreach ($schema as $key => $cfg) {
                    $infra->cache_del('sky_opt_' . md5($key));
                }
            }
        }

        // M20：清掉 Skyline_Core::$options 内存缓存，否则同一请求内
        // 渲染设置页时开关/输入项显示的仍是保存前的值。方法由 core.php
        // 的修复员另加；这里先确认存在再调（方法未到位时静默跳过，
        // 上面的 transient/Redis 清理仍会让下一次请求读到新值）。
        if (method_exists($this->core, 'clear_opt_cache')) {
            $this->core->clear_opt_cache();
        }

        add_settings_error('skyline_messages', 'skyline_msg', '✅ 配置已保存！', 'updated');
    }

    public function ajax_health_check() {
        check_ajax_referer('skyline_ajax_nonce');
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
        return sanitize_key($_GET['tab'] ?? 'dashboard');
    }

    public function get_system_health() {
        return [
            'php_version' => phpversion(),
            'redis' => $this->check_redis_health(),
            'curl' => extension_loaded('curl') ? ['status' => 'ok', 'label' => '✓ 已启用（仅扩展检查，未验证请求）'] : ['status' => 'error', 'label' => '✗ 未启用'],
            'gd' => $this->check_gd_health(),
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

    // M18：Redis 健康度读真实状态，不再只看 extension_loaded。
    // 优先复用 Skyline_Infra::init_redis() 建好的真实连接（ping + 一次
    // setex/get/del 读写验证）；开关没开时明确标“已禁用”，不给绿色。
    private function check_redis_health() {
        if (!extension_loaded('redis')) {
            return ['status' => 'error', 'label' => '✗ 未安装'];
        }
        if (!$this->core->get_opt('redis_enable')) {
            return ['status' => 'warning', 'label' => '已禁用（开关未开）'];
        }
        if (!class_exists('Skyline_Infra')) {
            return ['status' => 'warning', 'label' => '✓ 已安装（未验证连接）'];
        }
        try {
            $infra = Skyline_Infra::instance();
            $ref = new ReflectionProperty($infra, 'redis');
            $ref->setAccessible(true);
            $conn = $ref->getValue($infra);
            if (!$conn) {
                return ['status' => 'error', 'label' => '✗ 连接失败（扩展已装，服务未连通）'];
            }
            if ($conn->ping() === false) {
                return ['status' => 'error', 'label' => '✗ 已连接但 ping 失败'];
            }
            $test_key = 'sky:health:ping';
            $conn->setex($test_key, 10, '1');
            if ($conn->get($test_key) !== '1') {
                return ['status' => 'error', 'label' => '✗ 连接成功但读写验证失败'];
            }
            $conn->del($test_key);
            return ['status' => 'ok', 'label' => '✓ 已连接（ping + 读写验证通过）'];
        } catch (Throwable $e) {
            return ['status' => 'error', 'label' => '✗ 连接失败：' . mb_substr($e->getMessage(), 0, 80)];
        }
    }

    // M18：GD 只查扩展，不查 WebP 支持——标签里如实注明，并把 WebP
    // 支持情况也写出来（转 WebP 功能依赖它）。
    private function check_gd_health() {
        if (!extension_loaded('gd')) {
            return ['status' => 'error', 'label' => '✗ 不支持'];
        }
        $webp = function_exists('gd_info') ? !empty(gd_info()['WebP Support']) : null;
        $note = $webp === null ? '' : ($webp ? '，WebP 支持' : '，WebP 不支持');
        return ['status' => 'ok', 'label' => '✓ 已启用（仅扩展检查' . $note . '）'];
    }

    private function check_memory_limit() {
        $limit = ini_get('memory_limit');
        $bytes = $this->convert_to_bytes($limit);
        return $bytes >= 134217728 ? 'ok' : 'warning';
    }

    private function convert_to_bytes($val) {
        $val = trim((string)$val);
        // L1：空字符串在 PHP 8 下 $val[-1] 会报 Warning，直接返回 0。
        if ($val === '') return 0;
        // L1/M18：memory_limit=-1 表示“无限制”，返回 PHP_INT_MAX，
        // 否则会被 check_memory_limit() 误报为 warning。
        if ($val === '-1') return PHP_INT_MAX;
        $last = strtolower($val[strlen($val) - 1]);
        $val = intval($val);
        switch ($last) {
            case 'g': $val *= 1024; // no break
            case 'm': $val *= 1024; // no break
            case 'k': $val *= 1024;
        }
        return $val;
    }

    public function render_main_page() {
        $current_tab = $this->get_current_tab();
        $schema = $this->core->get_config_schema();
        $health = $this->get_system_health();
        
        if (!is_array($schema)) $schema = [];

        // 加载外部资源
        wp_enqueue_style('skyline-admin-css', SKY_URL . 'assets/css/admin.css', [], SKY_VERSION);
        wp_enqueue_script('skyline-admin-js', SKY_URL . 'assets/js/admin.js', ['jquery'], SKY_VERSION, true);
        wp_localize_script('skyline-admin-js', 'skyline_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('skyline_ajax_nonce')
        ]);

        $nav_items = [
            'dashboard' => ['label' => '📊 数据看板', 'icon' => '📊'],
            'ai' => ['label' => '🤖 智能核心', 'icon' => '🤖'],
            'spider' => ['label' => '🕸️ 内容同步', 'icon' => '🕸️'],
            'oss' => ['label' => '☁️ 云端存储', 'icon' => '☁️'],
            'seo' => ['label' => '🚀 搜索优化', 'icon' => '🚀'],
            'speed' => ['label' => '⚡ 性能体检', 'icon' => '⚡'],
            'logs' => ['label' => '📜 系统日志', 'icon' => '📜'],
        ];
        ?>
        <form method="post" action="" id="skyline-settings-form">
        <div class="sky-wrapper">
            <div class="sky-sidebar">
                <div class="sky-brand">
                    <div class="sky-brand-logo">S</div>
                    <div>
                        <span class="sky-brand-text">Skyline AI Pro</span>
                        <span class="sky-brand-ver">Enterprise v<?php echo SKY_VERSION; ?></span>
                    </div>
                </div>
                <div class="sky-nav">
                    <?php foreach ($nav_items as $id => $item): ?>
                        <a href="?page=skyline-pro&tab=<?php echo esc_attr($id); ?>" 
                           class="sky-nav-item <?php echo $current_tab === $id ? 'active' : ''; ?>">
                            <?php echo $item['label']; ?>
                        </a>
                    <?php endforeach; ?>
                </div>
                <div class="sky-sidebar-footer">
                    <?php wp_nonce_field('skyline_save_action', 'skyline_nonce'); ?>
                    <?php if (!in_array($current_tab, ['dashboard', 'logs'], true)): ?>
                    <button type="submit" name="skyline_save_settings" class="sky-save-btn">
                        💾 保存本页配置
                    </button>
                    <?php endif; ?>
                    <div class="sky-brand-box">
                        <span class="logo">灵感屋 LgWu</span>
                        <span class="url">www.lgwu.net</span>
                    </div>
                </div>
            </div>
            <div class="sky-main">
                <div class="sky-welcome">
                    <h2>👋 欢迎回来, <?php echo esc_html(wp_get_current_user()->display_name); ?></h2>
                </div>
                <?php settings_errors('skyline_messages'); ?>
                
                <?php if ($current_tab === 'dashboard'): ?>
                    <?php $this->render_dashboard($health); ?>
                <?php elseif ($current_tab === 'ai'): ?>
                    <?php $this->render_ai_tab($schema, $nav_items); ?>
                <?php elseif ($current_tab === 'logs'): ?>
                    <?php $this->render_logs_tab(); ?>
                <?php else: ?>
                    <?php $this->render_settings_tab($current_tab, $schema, $nav_items); ?>
                <?php endif; ?>
            </div>
        </div>
        </form>
        
        <script>
        // M19：成功分支原来直接拼 r.data，但 API 测试成功时 r.data 是
        // {reply: "..."} 对象，拼出来是 [object Object]。
        // 改为取 r.data.reply || r.data；另加 loading/禁用态（防重复点击）
        // 与请求失败处理（.fail()）。
        function testService(actionName, btn) {
            var $btn = btn ? jQuery(btn) : null;
            if ($btn && $btn.prop('disabled')) return; // 防重复点击
            var origText = $btn ? $btn.html() : '';
            if ($btn) $btn.prop('disabled', true).html('⏳ 诊断中…');
            jQuery.post(ajaxurl, {
                action: actionName,
                _ajax_nonce: '<?php echo wp_create_nonce('sky_ai_test_nonce'); ?>'
            }, function(r) {
                if (r.success) {
                    // r.data 可能是字符串（Redis/OSS 测试）或对象（API 测试
                    // 返回 {reply: "..."}）；对象无 reply 时用 JSON 兜底，
                    // 绝不再直接拼出 [object Object]。
                    var msg = r.data;
                    if (msg && typeof msg === 'object') msg = msg.reply || JSON.stringify(msg);
                    alert('✅ 诊断成功: ' + msg);
                } else {
                    alert('❌ 诊断失败: ' + r.data);
                }
            }).fail(function() {
                alert('❌ 请求失败：网络错误或服务器无响应，请稍后重试。');
            }).always(function() {
                if ($btn) $btn.prop('disabled', false).html(origText);
            });
        }

        function savePromptTemplate() {
            let name = jQuery('#new-prompt-name').val().trim();
            let template = jQuery('#new-prompt-template').val().trim();
            if (!name || !template) return alert('请填写模板名称和内容');
            
            jQuery.post(ajaxurl, {
                action: 'sky_save_prompt',
                name: name,
                template: template,
                _ajax_nonce: '<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'
            }, function(r) {
                if (r.success) {
                    alert('✅ 模板保存成功');
                    loadPrompts();
                    jQuery('#new-prompt-name, #new-prompt-template').val('');
                }
            });
        }

        // L6：模板名/内容先转义再拼 HTML（原代码直接插值后 .html()，
        // 是管理员之间的存储型 XSS 面）；模板不足 100 字时不加 "..."。
        function skyEscapeHtml(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c];
            });
        }

        function loadPrompts() {
            jQuery.post(ajaxurl, {
                action: 'sky_get_prompts',
                _ajax_nonce: '<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'
            }, function(r) {
                if (r.success) {
                    let html = '<table class="widefat"><thead><tr><th>名称</th><th style="width:60%">模板预览</th><th>操作</th></tr></thead><tbody>';
                    Object.keys(r.data || {}).forEach(id => {
                        let p = r.data[id];
                        let tpl = p.template || '';
                        let preview = tpl.length > 100 ? tpl.substring(0, 100) + '...' : tpl;
                        let safeId = skyEscapeHtml(id).replace(/'/g, "\\'");
                        html += `<tr><td>${skyEscapeHtml(p.name)}</td><td style="max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${skyEscapeHtml(preview)}</td><td><button class="button button-small" onclick="deletePrompt('${safeId}')">删除</button></td></tr>`;
                    });
                    html += '</tbody></table>';
                    jQuery('#sky-prompt-list').html(html);
                }
            });
        }

        function deletePrompt(id) {
            if (confirm('确定删除此模板？')) {
                jQuery.post(ajaxurl, {
                    action: 'sky_delete_prompt',
                    id: id,
                    _ajax_nonce: '<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'
                }, function(r) {
                    if (r.success) {
                        alert('✅ 模板已删除');
                        loadPrompts();
                    } else {
                        alert('❌ 删除失败: ' + (r.data || '未知错误'));
                    }
                }).fail(function() { alert('网络请求失败'); });
            }
        }

        function clearLogs() {
            jQuery.post(ajaxurl, {
                action: 'sky_clear_logs',
                _ajax_nonce: '<?php echo wp_create_nonce('sky_clear_logs_nonce'); ?>'
            }, function(r) {
                if (r.success) {
                    alert('日志已清空');
                    location.reload();
                }
            });
        }

        jQuery(document).ready(function() {
            if ('<?php echo esc_js($current_tab); ?>' === 'ai') loadPrompts();
        });
        </script>
        <?php
    }

    private function render_dashboard($health) {
        ?>
        <div class="sky-card">
            <div class="sky-card-title">✨ 插件能力概览</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                Skyline AI Pro 是您的智能运营中台。集成 DeepSeek V3，实现 AI 写作与绘图；配合 Redis 缓存与 OSS 云存储，缓存命中时可减少重复生成与回源。
            </p>
        </div>

        <?php
        // L34：看板数字补口径说明与命中率。cache_miss 有现成统计
        // （infra.php 里 cache 未命中即 stat_incr('cache_miss')），
        // 直接用 stat_get 读；统计起点无固定时间窗口，如实标注“累计”。
        $hits = (int)$this->core->stat_get('cache_hits');
        $miss = (int)$this->core->stat_get('cache_miss');
        $hit_rate = ($hits + $miss) > 0 ? round($hits / ($hits + $miss) * 100, 1) : null;
        ?>
        <div class="sky-stats-grid">
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span>
                <span class="sky-stat-lbl">AI 调用次数</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">仅计未缓存调用，含测试 Ping 与自动 SEO 的每次子调用，非对话条数</span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('spider_count')); ?></span>
                <span class="sky-stat-lbl">资源同步数</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">计成功下载的图片张数，非文章数；同一张图重复下载会重复计</span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_errors')); ?></span>
                <span class="sky-stat-lbl">API 错误</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">API 调用失败次数累计</span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($hits); ?></span>
                <span class="sky-stat-lbl">缓存命中</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">页面缓存 + API 缓存命中之和，非单纯页面缓存命中</span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo esc_html($miss); ?></span>
                <span class="sky-stat-lbl">缓存未命中</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">缓存查询未命中次数；命中率 = 命中 /（命中 + 未命中）</span>
            </div>
            <div class="sky-stat-card">
                <span class="sky-stat-val"><?php echo $hit_rate === null ? '—' : esc_html($hit_rate . '%'); ?></span>
                <span class="sky-stat-lbl">缓存命中率</span>
                <span class="sky-stat-note" style="display:block; font-size:12px; color: var(--sky-text-muted); line-height:1.5; margin-top:4px;">命中 <?php echo esc_html($hits); ?> / 未命中 <?php echo esc_html($miss); ?>；无请求时不显示</span>
            </div>
        </div>
        <p style="color: var(--sky-text-muted); font-size: 12px; margin-top: 8px;">
            统计口径：以上数字均为自统计启用起的累计值，无固定时间窗口；缓存命中为 0 时请结合命中率判断——无请求与从不命中都会显示 0。
        </p>

        <style>@media (max-width: 480px) { .sky-dashboard-grid { grid-template-columns: 1fr !important; } }</style>
        <div class="sky-dashboard-grid" style="display:grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
            <div class="sky-card">
                <div class="sky-card-title">🛡️ 系统健康度</div>
                <table class="sky-health-table">
                    <tr>
                        <td class="sky-health-label">PHP 版本</td>
                        <td class="sky-health-val"><?php echo esc_html(phpversion()); ?></td>
                    </tr>
                    <tr>
                        <td class="sky-health-label">内存限制</td>
                        <td class="sky-health-val">
                            <span class="sky-status-<?php echo $health['memory']['status']; ?>">
                                <?php echo esc_html($health['memory']['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="sky-health-label">上传限制</td>
                        <td class="sky-health-val"><?php echo esc_html($health['upload']['label']); ?></td>
                    </tr>
                    <tr>
                        <td class="sky-health-label">Redis 扩展</td>
                        <td class="sky-health-val">
                            <span class="sky-status-<?php echo $health['redis']['status']; ?>">
                                <?php echo esc_html($health['redis']['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="sky-health-label">CURL 扩展</td>
                        <td class="sky-health-val">
                            <span class="sky-status-<?php echo $health['curl']['status']; ?>">
                                <?php echo esc_html($health['curl']['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <tr>
                        <td class="sky-health-label">GD 库 (去水印)</td>
                        <td class="sky-health-val">
                            <span class="sky-status-<?php echo $health['gd']['status']; ?>">
                                <?php echo esc_html($health['gd']['label']); ?>
                            </span>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="sky-card">
                <div class="sky-card-title">📜 更新历史</div>
                <div style="font-size: 13px; color: var(--sky-text-muted); line-height: 1.8;">
                    <b>v2.1.0</b> - 聊天面板美化 + WebP 转换 + 图片同步自动/手动切换 + 看板娘气泡重做；审核 61 项全修（wp-cron 饿死、保存假成功、API 错误统计、9 个摆设开关等）<br>
                    <b>v2.0.0</b> - 旗舰级架构重建，引入企业级视觉语言<br>
                    <b>v1.5.0</b> - 视觉架构升维，增强合规性<br>
                    <b>v1.4.0</b> - 引入 Redis 缓存（私有直连客户端，不接管 WordPress 对象缓存）
                </div>
            </div>
        </div>
        
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">⚡ 快速操作</div>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary" onclick="testService('sky_test_api', this)">
                    🔌 测试 API 连接
                </button>
                <button type="button" class="button button-secondary" onclick="testService('sky_test_redis', this)">
                    🗄️ 测试 Redis 连接
                </button>
                <button type="button" class="button button-secondary" onclick="testService('sky_test_oss', this)">
                    ☁️ 测试 OSS 连接
                </button>
                <button type="button" class="button button-secondary" onclick="clearLogs()">
                    🗑️ 清空日志
                </button>
            </div>
        </div>
        <?php
    }

    // L21：按语义分组，不再用 array_chunk($items, 6) 机械切块、每块都叫
    // “➕ 附加配置”。AI：接口 / 助手 / 可信代理；性能：Redis / 图片
    // 优化 / 其他开关。其他 tab 只有一组，标题由调用方用导航名填充。
    // schema 新增了字段时，未列入分组表的键自动归入“⚙️ 其他配置”。
    private function group_tab_items($current_tab, $items) {
        $defs = [];
        if ($current_tab === 'ai') {
            $defs = [
                '🔌 接口配置' => ['api_base', 'api_img_base', 'api_key', 'chat_model', 'image_model', 'system_prompt'],
                '🤖 悬浮助手' => ['robot_enable', 'robot_only_logged', 'robot_img'],
                '🛡️ 可信代理' => ['trusted_proxy_ips'],
            ];
        } elseif ($current_tab === 'speed') {
            $defs = [
                '🗄️ Redis 缓存' => ['redis_enable', 'redis_smart_purge', 'redis_host', 'redis_port', 'redis_auth', 'redis_db', 'redis_ttl', 'redis_exclude', 'redis_serializer', 'redis_compression'],
                '🖼️ 图片优化' => ['turbo_enable_image_opt', 'turbo_webp_convert', 'turbo_webp_quality', 'turbo_webp_keep_original', 'turbo_quality', 'turbo_max_width', 'turbo_limit_5m', 'turbo_allow_svg'],
                '⚙️ 其他开关' => ['turbo_disable_emoji', 'turbo_disable_embeds', 'turbo_disable_xmlrpc', 'turbo_sanitize_names', 'turbo_lazy_load'],
            ];
        }
        if (empty($defs)) {
            return [['title' => null, 'items' => $items]];
        }
        $by_key = [];
        foreach ($items as $item) $by_key[$item['key']] = $item;
        $groups = [];
        foreach ($defs as $title => $keys) {
            $group_items = [];
            foreach ($keys as $k) {
                if (isset($by_key[$k])) { $group_items[] = $by_key[$k]; unset($by_key[$k]); }
            }
            if (!empty($group_items)) $groups[] = ['title' => $title, 'items' => $group_items];
        }
        if (!empty($by_key)) {
            $groups[] = ['title' => '⚙️ 其他配置', 'items' => array_values($by_key)];
        }
        return $groups;
    }

    private function render_ai_tab($schema, $nav_items) {
        $current_tab = 'ai';
        ?>
        <div class="sky-settings-grid">
            <?php
            $current_group_items = [];
            foreach ($schema as $key => $cfg) {
                if (($cfg['group'] ?? 'general') === $current_tab) {
                    $current_group_items[] = ['key' => $key, 'cfg' => $cfg];
                }
            }
            $groups = $this->group_tab_items($current_tab, $current_group_items);
            foreach ($groups as $group):
            ?>
                <div class="sky-setting-block">
                    <div class="sky-setting-header">
                        <?php echo esc_html($group['title']); ?>
                    </div>
                    <?php foreach ($group['items'] as $item):
                        $key = $item['key'];
                        $cfg = $item['cfg'];
                        $val = $this->core->get_opt($key);
                    ?>
                        <div class="sky-field-row">
                            <div class="sky-field-info">
                                <div class="sky-field-label"><?php echo esc_html($cfg['label']); ?></div>
                                <?php if (isset($cfg['desc'])): ?>
                                    <div class="sky-field-desc">💡 <?php echo esc_html($cfg['desc']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="sky-field-control">
                                <?php $this->render_field($key, $cfg, $val); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="sky-card" style="margin-top: 24px;">
            <div class="sky-card-title">📋 Prompt 模板库</div>
            <div id="sky-prompt-list"></div>
            <div style="margin-top: 16px; padding: 16px; background: var(--sky-bg); border-radius: 8px;">
                <input type="text" id="new-prompt-name" placeholder="模板名称" style="width: 280px; max-width: 100%; box-sizing: border-box;">
                <textarea id="new-prompt-template" rows="4" style="width: 100%; margin-top: 8px;" placeholder="Prompt 模板内容..."></textarea>
                <button type="button" class="button button-primary" onclick="savePromptTemplate()" style="margin-top: 8px;">
                    + 保存新模板
                </button>
            </div>
        </div>
        <?php
    }

    // L16：日志页只读末尾 N 行（默认 200 行），不把整个文件读进内存；
    // 从文件尾部按 8KB 块倒读，凑够行数即停。
    private function read_log_tail($file, $lines = 200) {
        $fp = @fopen($file, 'rb');
        if (!$fp) return '';
        $size = filesize($file);
        $chunk = 8192;
        $pos = $size;
        $buf = '';
        $count = 0;
        while ($pos > 0 && $count <= $lines) {
            $read = min($chunk, $pos);
            $pos -= $read;
            fseek($fp, $pos);
            $buf = fread($fp, $read) . $buf;
            $count = substr_count($buf, "\n");
        }
        fclose($fp);
        $all = explode("\n", $buf);
        if (count($all) > $lines) $all = array_slice($all, -$lines);
        return implode("\n", $all);
    }

    private function log_file_date($file) {
        if (preg_match('/skyline_ai-(\d{8})/', basename($file), $m)) {
            return substr($m[1], 0, 4) . '-' . substr($m[1], 4, 2) . '-' . substr($m[1], 6, 2);
        }
        return date('Y-m-d', filemtime($file));
    }

    private function render_logs_tab() {
        $log_dir = SKY_PATH . 'logs';
        $log_files = glob($log_dir . '/skyline_ai*.log') ?: [];
        $today = date('Ymd');
        $today_log = '';
        foreach ($log_files as $f) {
            if (strpos(basename($f), 'skyline_ai-' . $today) === 0) { $today_log = $f; break; }
        }
        // 最新文件（仅用于在“今天无日志”时注明日期，不直接显示其内容）。
        $latest_log = $log_files ? $log_files[count($log_files) - 1] : '';
        ?>
        <div class="sky-card">
            <div class="sky-card-title">
                📜 系统日志
                <button type="button" class="button" onclick="clearLogs()" style="float: right;">
                    🗑️ 清空日志
                </button>
            </div>
            <?php if ($today_log && file_exists($today_log)): ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">
                    日志文件：<?php echo esc_html(basename($today_log)); ?>（<?php echo esc_html(date('Y-m-d')); ?>，仅显示末尾 200 行）
                </p>
                <pre id="sky-log-content"><?php echo esc_html($this->read_log_tail($today_log, 200)); ?></pre>
            <?php else: ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">
                    今天（<?php echo esc_html(date('Y-m-d')); ?>）暂无日志记录。
                    <?php if ($latest_log): ?>
                        最近一次日志文件：<?php echo esc_html(basename($latest_log)); ?>（<?php echo esc_html($this->log_file_date($latest_log)); ?>），非今日日志，故不在此显示。
                    <?php endif; ?>
                </p>
                <pre id="sky-log-content">暂无日志记录</pre>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_settings_tab($current_tab, $schema, $nav_items) {
        ?>
        <div class="sky-settings-grid">
            <?php
            $current_group_items = [];
            foreach ($schema as $key => $cfg) {
                if (($cfg['group'] ?? 'general') === $current_tab) {
                    $current_group_items[] = ['key' => $key, 'cfg' => $cfg];
                }
            }
            $groups = $this->group_tab_items($current_tab, $current_group_items);
            foreach ($groups as $group_index => $group):
            ?>
                <div class="sky-setting-block">
                    <div class="sky-setting-header">
                        <?php echo $group['title'] !== null ? esc_html($group['title']) : '⚙️ ' . $nav_items[$current_tab]['label']; ?>
                        <?php if ($current_tab === 'oss' && $group_index === 0): ?>
                            <button type="button" class="button button-primary" onclick="testService('sky_test_oss', this)" style="float: right; margin-top: -4px;">
                                🔌 测试 OSS 连接
                            </button>
                        <?php elseif ($current_tab === 'speed' && $group_index === 0): ?>
                            <button type="button" class="button button-primary" onclick="testService('sky_test_redis', this)" style="float: right; margin-top: -4px;">
                                🔌 测试 Redis 连接
                            </button>
                        <?php endif; ?>
                    </div>
                    <?php foreach ($group['items'] as $item):
                        $key = $item['key'];
                        $cfg = $item['cfg'];
                        $val = $this->core->get_opt($key);
                    ?>
                        <div class="sky-field-row">
                            <div class="sky-field-info">
                                <div class="sky-field-label"><?php echo esc_html($cfg['label']); ?></div>
                                <?php if (isset($cfg['desc'])): ?>
                                    <div class="sky-field-desc">💡 <?php echo esc_html($cfg['desc']); ?></div>
                                <?php endif; ?>
                            </div>
                            <div class="sky-field-control">
                                <?php $this->render_field($key, $cfg, $val); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <?php if ($current_tab === 'speed'): ?>
            <?php $this->render_webp_batch_card(); ?>
        <?php endif; ?>
        <?php if ($current_tab === 'oss'): ?>
            <?php $this->render_pending_delete_queue(); ?>
        <?php endif; ?>
        <?php
    }

    // L20：Zero-Disk 待删队列只读展示。读 _sky_oss_pending_delete 元数据
    // （上传成功后登记，24 小时后由 WP-Cron 二次校验才删），只列出附件、
    // 登记时间、预计删除时间与待删文件，不提供任何删除按钮。
    private function render_pending_delete_queue() {
        $zero_disk_on = (bool)$this->core->get_opt('oss_delete_local');
        $ids = function_exists('get_posts') ? get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => '_sky_oss_pending_delete',
            'posts_per_page' => 100,
            'fields' => 'ids',
        ]) : [];
        if (!is_array($ids)) $ids = [];
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">🗑️ Zero-Disk 待删队列（只读）</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                上传成功后登记，24 小时后由 WP-Cron 二次校验 COS 对象（大小/ETag）通过才删除本地文件。
                <?php if (!$zero_disk_on): ?>
                    当前 Zero-Disk 开关已关闭：待删登记作废，不会执行删除；重新开启后新上传才会重新登记。
                <?php endif; ?>
            </p>
            <?php if (empty($ids)): ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">当前没有已登记待删的文件。</p>
            <?php else: ?>
                <table class="widefat">
                    <thead><tr><th>附件</th><th>登记时间</th><th>预计删除时间</th><th>待删文件</th></tr></thead>
                    <tbody>
                    <?php foreach ($ids as $id):
                        $pending = get_post_meta($id, '_sky_oss_pending_delete', true);
                        if (!is_array($pending) || empty($pending['files'])) continue;
                        $reg_time = isset($pending['time']) ? (int)$pending['time'] : 0;
                        $file_names = [];
                        foreach ((array)$pending['files'] as $entry) {
                            if (is_array($entry) && !empty($entry['path'])) $file_names[] = basename($entry['path']);
                        }
                    ?>
                        <tr>
                            <td>#<?php echo esc_html($id); ?></td>
                            <td><?php echo $reg_time ? esc_html(date('Y-m-d H:i:s', $reg_time)) : '—'; ?></td>
                            <td><?php echo $reg_time ? esc_html(date('Y-m-d H:i:s', $reg_time + DAY_IN_SECONDS)) : '—'; ?></td>
                            <td><?php echo esc_html(implode('、', $file_names)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // 批量转 WebP 工具卡片（性能体检页）：扫描 + 分批转换 + 进度。
    // 动态内容一律用 textContent 拼节点，不用 .html() 拼服务器返回的
    // 字符串（R33 的教训：未转义拼接即 XSS 面）。
    private function render_webp_batch_card() {
        $supported = class_exists('Skyline_Turbo_Mod') && Skyline_Turbo_Mod::webp_supported();
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">🖼️ 批量转 WebP（旧图优化）</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                把媒体库里已有的 JPG/PNG 旧图批量转成 WebP。GD WebP 支持状态：
                <strong><?php echo $supported ? '✅ 支持' : '❌ 不支持'; ?></strong>
                <?php if (!$supported): ?>
                    当前服务器 GD 未编译 WebP 支持，上传转换与批量工具都不会执行；请先让服务器支持 WebP 后再用。
                <?php endif; ?>
            </p>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                规则：只转 JPG/PNG、≤10MB；已转过的跳过；Zero-Disk 待删登记中的跳过；本地文件缺失的跳过；每批 20 张；
                转完自动同步到 COS（已开 COS 时）；原图按上方“转 WebP 后保留原图”设置处理。
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary" id="sky-webp-scan-btn" onclick="skyWebpScan()" <?php echo $supported ? '' : 'disabled'; ?>>
                    🔍 扫描可转换图片
                </button>
                <button type="button" class="button button-primary" id="sky-webp-start-btn" onclick="skyWebpStart()" disabled>
                    ▶️ 开始批量转换
                </button>
                <button type="button" class="button" id="sky-webp-stop-btn" onclick="skyWebpStop()" disabled>
                    ⏹️ 停止
                </button>
            </div>
            <div id="sky-webp-status" style="margin-top: 12px; font-size: 13px;"></div>
            <div id="sky-webp-progress" style="display: none; margin-top: 8px; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden;">
                <div id="sky-webp-bar" style="height: 100%; width: 0%; background: #6366f1;"></div>
            </div>
            <ul id="sky-webp-failures" style="margin-top: 10px; font-size: 13px; color: #b91c1c; list-style: disc inside;"></ul>
        </div>
        <script>
        var skyWebpIds = [], skyWebpPos = 0, skyWebpTotal = 0, skyWebpDone = 0, skyWebpOk = 0, skyWebpFail = 0, skyWebpStopping = false;
        function skyWebpSetText(id, txt) { var el = document.getElementById(id); if (el) el.textContent = txt; }
        function skyWebpScan() {
            skyWebpSetText('sky-webp-status', '正在扫描…');
            jQuery.post(ajaxurl, {
                action: 'sky_webp_scan',
                _ajax_nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>'
            }, function(r) {
                if (!r.success) { skyWebpSetText('sky-webp-status', '❌ 扫描失败：' + (r.data || '未知错误')); return; }
                if (!r.data.supported) { skyWebpSetText('sky-webp-status', '❌ 当前服务器 GD 不支持 WebP，无法转换。'); return; }
                skyWebpIds = r.data.ids || [];
                skyWebpTotal = skyWebpIds.length;
                skyWebpPos = 0; skyWebpDone = 0; skyWebpOk = 0; skyWebpFail = 0;
                var sk = r.data.skipped || {};
                skyWebpSetText('sky-webp-status', '可转换 ' + skyWebpTotal + ' 张（已跳过：已转过 ' + (sk.converted || 0) + '、本地缺失 ' + (sk.missing || 0) + '、超 10MB ' + (sk.toolarge || 0) + '、待删登记中 ' + (sk.pending_delete || 0) + '）。');
                document.getElementById('sky-webp-start-btn').disabled = skyWebpTotal === 0;
                document.getElementById('sky-webp-progress').style.display = skyWebpTotal ? 'block' : 'none';
                document.getElementById('sky-webp-bar').style.width = '0%';
                document.getElementById('sky-webp-failures').innerHTML = '';
            }).fail(function() { skyWebpSetText('sky-webp-status', '❌ 扫描请求失败（网络错误）。'); });
        }
        function skyWebpStart() {
            if (!skyWebpIds.length) return;
            skyWebpStopping = false;
            document.getElementById('sky-webp-start-btn').disabled = true;
            document.getElementById('sky-webp-scan-btn').disabled = true;
            document.getElementById('sky-webp-stop-btn').disabled = false;
            skyWebpNext();
        }
        function skyWebpStop() {
            skyWebpStopping = true;
            skyWebpSetText('sky-webp-status', '已停止：已处理 ' + skyWebpDone + ' / ' + skyWebpTotal + '，成功 ' + skyWebpOk + '，失败 ' + skyWebpFail + '。');
            document.getElementById('sky-webp-start-btn').disabled = false;
            document.getElementById('sky-webp-scan-btn').disabled = false;
            document.getElementById('sky-webp-stop-btn').disabled = true;
        }
        function skyWebpNext() {
            if (skyWebpStopping) return;
            if (skyWebpPos >= skyWebpIds.length) {
                skyWebpSetText('sky-webp-status', '✅ 全部完成：共 ' + skyWebpTotal + ' 张，成功 ' + skyWebpOk + '，失败 ' + skyWebpFail + '。');
                document.getElementById('sky-webp-start-btn').disabled = false;
                document.getElementById('sky-webp-scan-btn').disabled = false;
                document.getElementById('sky-webp-stop-btn').disabled = true;
                return;
            }
            var batch = skyWebpIds.slice(skyWebpPos, skyWebpPos + 20);
            jQuery.post(ajaxurl, {
                action: 'sky_webp_batch',
                ids: batch,
                _ajax_nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>'
            }, function(r) {
                if (!r.success) {
                    skyWebpSetText('sky-webp-status', '❌ 批量转换失败：' + (r.data || '未知错误'));
                    document.getElementById('sky-webp-start-btn').disabled = false;
                    document.getElementById('sky-webp-scan-btn').disabled = false;
                    document.getElementById('sky-webp-stop-btn').disabled = true;
                    return;
                }
                var results = r.data.results || [];
                results.forEach(function(it) {
                    skyWebpDone++;
                    if (it.status === 'converted') { skyWebpOk++; }
                    else if (it.status === 'failed') {
                        skyWebpFail++;
                        var li = document.createElement('li');
                        li.textContent = '附件 #' + it.id + '：' + (it.reason || '转换失败');
                        document.getElementById('sky-webp-failures').appendChild(li);
                    }
                });
                skyWebpPos += results.length;
                document.getElementById('sky-webp-bar').style.width = (skyWebpTotal ? Math.round(skyWebpDone / skyWebpTotal * 100) : 0) + '%';
                skyWebpSetText('sky-webp-status', '处理中：' + skyWebpDone + ' / ' + skyWebpTotal + '，成功 ' + skyWebpOk + '，失败 ' + skyWebpFail + '。');
                skyWebpNext();
            }).fail(function() {
                skyWebpSetText('sky-webp-status', '❌ 批量请求失败（网络错误），已停止。');
                document.getElementById('sky-webp-start-btn').disabled = false;
                document.getElementById('sky-webp-scan-btn').disabled = false;
                document.getElementById('sky-webp-stop-btn').disabled = true;
            });
        }
        </script>
        <?php
    }

    // L15：number 字段的默认 min/max（schema 没写时用）。端口 1–65535、
    // DB 号 0–15、质量类 0–100、其余数量类下限 0、无明确上限。
    private function number_field_min($key) {
        return 0;
    }

    private function number_field_max($key) {
        switch ($key) {
            case 'redis_port': return 65535;
            case 'redis_db': return 15;
            case 'turbo_quality':
            case 'turbo_webp_quality': return 100;
            case 'spider_max_img': return 100;
            default: return null;
        }
    }

    private function render_field($key, $cfg, $val) {
        $type = $cfg['type'] ?? 'text';
        
        switch ($type) {
            case 'password':
                // M21：绝不把密钥原文回显进 value（页面源码即见明文，且 XSS
                // 可从 DOM 直接读走）。留空表示不改；保存时空值不覆盖已有值
                // （见 handle_settings 的 password 分支）。
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<input type="password" name="skyline_settings[' . esc_attr($key) . ']" value="" autocomplete="off" placeholder="留空表示不改">';
                echo '</div>';
                break;

            case 'number':
                // L15：number 字段用 type="number" + 合理 min/max（schema 未
                // 写 min/max 时按字段名给默认值），不再走 text 输入框。
                $num_min = isset($cfg['min']) ? (int)$cfg['min'] : $this->number_field_min($key);
                $num_max = isset($cfg['max']) ? (int)$cfg['max'] : $this->number_field_max($key);
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<input type="number" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" min="' . $num_min . '"' . ($num_max !== null ? ' max="' . $num_max . '"' : '') . ' step="1">';
                echo '</div>';
                // turbo_quality 的 desc 在 core.php 的 schema 里（另一位修复
                // 员负责）；schema 没写 desc 时这里补一行 0–100 的说明，
                // 避免与 schema 的 desc 重复出现。
                if ($key === 'turbo_quality' && empty($cfg['desc'])) {
                    echo '<div class="sky-field-desc">💡 取值 0–100，越高画质越好、文件越大。</div>';
                }
                break;

            case 'url':
                // L15：url 字段用 type="url"，浏览器做格式校验、移动端出
                // 网址键盘，不再走纯 text 输入框。
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<input type="url" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '">';
                echo '</div>';
                break;
                
            case 'textarea':
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<textarea name="skyline_settings[' . esc_attr($key) . ']" rows="3">' . esc_textarea($val) . '</textarea>';
                echo '</div>';
                break;
                
            case 'spider_mode':
                // 图片同步方式（两个单选项，替代原来的 spider_auto 复选框）。
                // 显示必须按保存值来，不能按默认值来：线上存的是 b:0（手动），
                // 而 schema 默认是 true（自动）。(bool) 强制转换与
                // trigger_async_tasks 里的真值判断完全一致（'0'/0/false/''
                // 都是假），界面上选中什么，发布时就是什么行为。
                // 注意不能用现成的 'select' 渲染：WP 的 selected() 是字符
                // 串严格比较，存的 false 会变成 ''，'0' 选项永远选不中，浏
                // 览器会默认显示第一个选项（自动）——这正是要修的显示错误。
                // 选中态样式用 CSS :has(input:checked)，不用 JS，不用在
                // label 上写死 active 类（点了另一个后类会过时）。
                $is_auto = (bool)$val;
                echo '<div class="sky-mode-switch" role="radiogroup" aria-label="图片同步方式">';
                echo '<label class="sky-mode-opt">';
                echo '<input type="radio" name="skyline_settings[' . esc_attr($key) . ']" value="1"' . ($is_auto ? ' checked' : '') . '> 自动（发布时自动同步）';
                echo '</label>';
                echo '<label class="sky-mode-opt">';
                echo '<input type="radio" name="skyline_settings[' . esc_attr($key) . ']" value="0"' . (!$is_auto ? ' checked' : '') . '> 手动（仅用一键同步按钮）';
                echo '</label>';
                echo '</div>';
                break;

            case 'bool':
                echo '<span class="sky-status-badge ' . ((int)$val ? 'active' : '') . '">';
                echo (int)$val ? '已启用' : '已禁用';
                echo '</span>';
                echo '<label class="sky-switch">';
                echo '<input type="checkbox" name="skyline_settings[' . esc_attr($key) . ']" value="1" ' . checked(1, (int)$val, false) . '>';
                echo '<span class="sky-slider"></span>';
                echo '</label>';
                break;
                
            case 'select':
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<select name="skyline_settings[' . esc_attr($key) . ']">';
                foreach ($cfg['options'] as $opt_val => $opt_label) {
                    echo '<option value="' . esc_attr($opt_val) . '" ' . selected($val, $opt_val, false) . '>';
                    echo esc_html($opt_label);
                    echo '</option>';
                }
                echo '</select>';
                echo '</div>';
                break;
                
            default:
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<input type="text" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '">';
                echo '</div>';
                break;
        }
    }
}
