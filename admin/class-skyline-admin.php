<?php
if (!defined('ABSPATH')) exit;

class Skyline_Admin {
    private $core;
    
    public function __construct() {
        $this->core = Skyline_Core::instance();
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'handle_settings']);
        add_action('wp_ajax_skyline_health_check', [$this, 'ajax_health_check']);
        // C1/S1 批量：扫描 + 分批跑（与 WebP 批量同款：nonce + manage_options）。
        add_action('wp_ajax_sky_spider_scan', [$this, 'ajax_spider_scan']);
        add_action('wp_ajax_sky_spider_batch', [$this, 'ajax_spider_batch']);
        add_action('wp_ajax_sky_seo_scan', [$this, 'ajax_seo_scan']);
        add_action('wp_ajax_sky_seo_batch', [$this, 'ajax_seo_batch']);
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
        // A1：清空聊天记录（AI 页“最近聊天记录”卡片里的表单按钮，与保存
        // 配置共用同一个表单与 nonce；先于保存分支处理，清空后直接返回，
        // 不保存随表单一起提交的配置值）。
        if (isset($_POST['skyline_clear_chat_log'])) {
            if (!wp_verify_nonce($_POST['skyline_nonce'] ?? '', 'skyline_save_action')) {
                return;
            }
            if (!current_user_can('manage_options')) {
                wp_die(__('权限不足', 'skyline-ai-pro'));
            }
            $this->core->clear_chat_log();
            add_settings_error('skyline_messages', 'skyline_msg', '✅ 聊天记录已清空。', 'updated');
            return;
        }

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
                            // v2.2.1：浏览器自动填充会把已保存的用户名（如
                            // “管理员用户名”）填进 URL 框；无 scheme 的假值绝不能
                            // 覆盖已存配置。空值 = 用户清空（沿旧语义存 ''）；
                            // 非空但不是 http(s) 地址的拒绝保存、保留旧值，
                            // 并报错提示（http(s) 才放行，FreeLLMAPI 的
                            // http:// 内网/公网地址与端口都不拦）。
                            $raw_url = trim((string)$posted[$key]);
                            if ($raw_url === '') {
                                $sanitized[$key] = '';
                                $wrote++;
                            } elseif (preg_match('#^https?://#i', $raw_url)) {
                                $sanitized[$key] = esc_url_raw($raw_url);
                                $wrote++;
                            } else {
                                add_settings_error('skyline_messages', 'skyline_msg_' . $key, '⚠️ “' . $cfg['label'] . '”的值不是合法的 http(s) 地址（可能是浏览器自动填充），已保留原值，未保存。', 'error');
                            }
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
                            // v2.2.1：生图密钥（api_img_key）例外——留空即
                            // “跟随主 API Key”，已填过的要清掉时，勾选
                            // “更改”后留空提交即清空（此时字段已启用、键
                            // 会提交空值；未勾选时字段禁用、键根本不提交，
                            // 不会误清）。其他密码字段仍是“空=不改”，因为
                            // 清空主密钥/OSS 密钥没有安全语义。
                            if (trim((string)$posted[$key]) !== '') {
                                $sanitized[$key] = sanitize_text_field($posted[$key]);
                                $wrote++;
                            } elseif ($key === 'api_img_key') {
                                $sanitized[$key] = '';
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

    // C1/S1 批量入口：四个端点都只给管理员（nonce + manage_options，
    // 与 WebP 批量同款）。响应里只有文章 id/标题/计数/缺项名，不回显
    // 密钥、不回显完整 API 响应体。
    public function ajax_spider_scan() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!class_exists('Skyline_Content')) wp_send_json_error('内容模块未加载');
        wp_send_json_success(Skyline_Content::instance()->scan_external_images());
    }

    public function ajax_spider_batch() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!class_exists('Skyline_Content')) wp_send_json_error('内容模块未加载');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        wp_send_json_success(Skyline_Content::instance()->batch_spider_posts($ids));
    }

    public function ajax_seo_scan() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!class_exists('Skyline_Content')) wp_send_json_error('内容模块未加载');
        wp_send_json_success(Skyline_Content::instance()->scan_seo_missing());
    }

    public function ajax_seo_batch() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!class_exists('Skyline_Content')) wp_send_json_error('内容模块未加载');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : [];
        $batch_size = isset($_POST['batch_size']) ? (int)$_POST['batch_size'] : Skyline_Content::SEO_BATCH_SIZE_DEFAULT;
        wp_send_json_success(Skyline_Content::instance()->batch_seo_posts($ids, $batch_size));
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
        // v2.2.2：admin.js 页头加载（主入口的 admin_enqueue_scripts 钩子
        // 已挂好，这里再挂一次是兜底——同一 handle 重复挂是 no-op，以钩
        // 子那次为准）。三张批量卡片的内联脚本在正文里直接调
        // SkyBatchRunner.create()，页脚加载时 SkyBatchRunner 尚不存在 →
        // ReferenceError，整段内联脚本中断、点击静默失败。
        // skyline_ajax 的 localize 已移到钩子（页头打印前挂好）。
        wp_enqueue_style('skyline-admin-css', SKY_URL . 'assets/css/admin.css', [], SKY_VERSION);
        wp_enqueue_script('skyline-admin-js', SKY_URL . 'assets/js/admin.js', ['jquery'], SKY_VERSION, false);

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
        <form method="post" action="" id="skyline-settings-form" autocomplete="off">
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
                        <span class="logo">Skyline AI Pro</span>
                        <span class="url">AI 智能运营中台</span>
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

        // v2.2.3：OSS/缓存卡片共用的失败文案与单次操作助手。
        // skyAjaxFailDetail 在 admin.js 的 SkyBatchRunner IIFE 内、不在
        // 全局作用域，这里单写一份同款文案（带 HTTP 状态，403/504/500/
        // 断网可区分）；nonce 取法与 WebP/蜘蛛/SEO 三张卡一致——内联
        // wp_create_nonce('skyline_ajax_nonce')。
        function skyFailText(xhr, textStatus) {
            var status = (xhr && xhr.status) ? xhr.status : 0;
            if (status === 403) return 'HTTP 403（可能是登录过期或 nonce 失效，请刷新页面重试）';
            if (status === 504 || textStatus === 'timeout') return 'HTTP 504/超时（请求超时，请稍后重试）';
            if (status === 500) return 'HTTP 500（服务端错误，请看服务器日志）';
            if (status) return 'HTTP ' + status;
            return '网络中断或超时（HTTP 0）';
        }

        // 单次操作：点后禁用给定按钮（可传一个元素或一组选择器）、请求
        // 结束恢复；成功/失败文案写状态区；失败带 HTTP 状态。data 可能是
        // 字符串或 {message, ...} 对象，两种都认。
        function skyOssPost(action, extra, btns, statusId, workingText) {
            var $btns = (typeof btns === 'string') ? jQuery(btns) : (btns ? jQuery(btns) : jQuery());
            if ($btns.length && $btns.first().prop('disabled')) return;
            $btns.prop('disabled', true);
            var $st = statusId ? jQuery('#' + statusId) : jQuery();
            if ($st.length) $st.text(workingText || '处理中…');
            var params = { action: action, _ajax_nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>' };
            if (extra) for (var k in extra) params[k] = extra[k];
            jQuery.post(ajaxurl, params, function(r) {
                var msg = '';
                if (r.success) {
                    var d = r.data;
                    if (d && typeof d === 'object') msg = d.message || JSON.stringify(d);
                    else msg = d || '操作成功';
                    if ($st.length) $st.text('✅ ' + msg);
                } else {
                    if ($st.length) $st.text('❌ 操作失败：' + (r.data || '未知错误'));
                }
            }).fail(function(xhr, textStatus) {
                if ($st.length) $st.text('❌ 操作失败（' + skyFailText(xhr, textStatus) + '）');
            }).always(function() {
                $btns.prop('disabled', false);
            });
        }

        // P-6：缓存清理（只清 Redis 层，不碰 EdgeOne）。
        function skyClearCache(scope, btn) {
            skyOssPost('sky_clear_cache', { scope: scope }, '#sky-cache-clear-page-btn, #sky-cache-clear-all-btn', 'sky-cache-clear-status', '正在清理缓存…');
        }

        // O-7：手动重试全部上传失败尺寸。
        function skyOssRetryFailed(btn) {
            skyOssPost('sky_oss_retry_failed', null, btn, 'sky-oss-failed-status', '正在重试全部失败尺寸…');
        }

        // O-6/O-8：待删队列单行重试校验 / 取消登记 / 立即校验一次。
        function skyPurgeRetry(id, btn) {
            skyOssPost('sky_oss_purge_retry', { attachment_id: id }, btn, 'sky-purge-queue-status', '正在校验附件 #' + id + '…');
        }
        function skyPurgeCancel(id, btn) {
            if (!confirm('确定取消该附件的待删登记吗？取消后本地文件将保留，不再删除。')) return;
            skyOssPost('sky_oss_purge_cancel', { attachment_id: id }, btn, 'sky-purge-queue-status', '正在取消登记…');
        }
        function skyPurgeNow(btn) {
            skyOssPost('sky_oss_purge_now', null, btn, 'sky-purge-queue-status', '正在校验待删队列…');
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

        // v2.2.3：模板库缓存（供“填入系统人设”按 id 取全文，避免把
        // 模板内容拼进 onclick 属性里转义）；模板只能存/看/删的断链
        // 由此补上——点“填入系统人设”即把模板写进 system_prompt，
        // 下一次 AI 调用（call_api 拼 system 消息）就会用上。
        var skyPromptCache = {};

        function loadPrompts() {
            jQuery.post(ajaxurl, {
                action: 'sky_get_prompts',
                _ajax_nonce: '<?php echo wp_create_nonce('sky_prompt_nonce'); ?>'
            }, function(r) {
                if (r.success) {
                    skyPromptCache = r.data || {};
                    let html = '<table class="widefat"><thead><tr><th>名称</th><th style="width:60%">模板预览</th><th>操作</th></tr></thead><tbody>';
                    Object.keys(r.data || {}).forEach(id => {
                        let p = r.data[id];
                        let tpl = p.template || '';
                        let preview = tpl.length > 100 ? tpl.substring(0, 100) + '...' : tpl;
                        let safeId = skyEscapeHtml(id).replace(/'/g, "\\'");
                        html += `<tr><td>${skyEscapeHtml(p.name)}</td><td style="max-width:400px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${skyEscapeHtml(preview)}</td><td><button class="button button-small" onclick="skyUsePrompt('${safeId}')">填入系统人设</button> <button class="button button-small" onclick="deletePrompt('${safeId}')">删除</button></td></tr>`;
                    });
                    html += '</tbody></table>';
                    jQuery('#sky-prompt-list').html(html);
                }
            });
        }

        // v2.2.3：把模板全文填进系统人设 textarea（#sky-field-system_prompt，
        // render_field 的 textarea 分支所给 id）。人设已有非空内容时先
        // confirm，取消则不填；填入后滚动到该字段并短暂高亮。
        function skyUsePrompt(id) {
            var p = skyPromptCache[id];
            if (!p || !p.template) { alert('模板内容为空，无法填入。'); return; }
            var ta = document.getElementById('sky-field-system_prompt');
            if (!ta) { alert('未找到系统人设字段，请先打开“智能核心”页再试。'); return; }
            if (ta.value.trim() !== '' && !confirm('系统人设已有内容，确定要用模板覆盖吗？')) return;
            ta.value = p.template;
            if (ta.dispatchEvent) ta.dispatchEvent(new Event('input', { bubbles: true }));
            ta.scrollIntoView({ behavior: 'smooth', block: 'center' });
            ta.style.backgroundColor = '#fef9c3';
            ta.style.outline = '2px solid #6366f1';
            setTimeout(function() { ta.style.backgroundColor = ''; ta.style.outline = ''; }, 2000);
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
        <?php
        // v2.2.2：概览只介绍模型的功能，不写当前配置——厂商/模型名是
        // 用户可改的设置（在“智能核心”里看），写进概览既多余、换了
        // 配置还得跟着改。原来按 api_base 是否为空分两支、反推厂商
        // 再拼模型名的整段逻辑整个去掉，固定一段功能介绍。
        $ov_text = 'Skyline AI Pro 是您的智能运营中台。AI 对话：回答访客问题、撰写文章与文案、自动补全英文 Slug、摘要与标签；AI 绘图：按文字描述生成文章配图；图片同步：把文章里的外链图片一键转成本地并同步到云存储；配合 Redis 缓存与 OSS 云存储，缓存命中时可减少重复生成与回源。';
        ?>
        <div class="sky-card">
            <div class="sky-card-title">✨ 插件能力概览</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                <?php echo esc_html($ov_text); ?>
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
            <div class="sky-stat-card" title="仅计未缓存调用，含测试 Ping 与自动 SEO 的每次子调用，非对话条数">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_calls')); ?></span>
                <span class="sky-stat-lbl">AI 调用</span>
            </div>
            <div class="sky-stat-card" title="计成功下载的图片张数，非文章数；同一张图重复下载会重复计">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('spider_count')); ?></span>
                <span class="sky-stat-lbl">资源同步</span>
            </div>
            <div class="sky-stat-card" title="API 调用失败次数累计">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo esc_html($this->core->stat_get('api_errors')); ?></span>
                <span class="sky-stat-lbl">API 错误</span>
            </div>
            <div class="sky-stat-card" title="页面缓存 + API 缓存命中之和，非单纯页面缓存命中">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo esc_html($hits); ?></span>
                <span class="sky-stat-lbl">缓存命中</span>
            </div>
            <div class="sky-stat-card" title="缓存查询未命中次数；命中率 = 命中 /（命中 + 未命中）">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo esc_html($miss); ?></span>
                <span class="sky-stat-lbl">缓存未命中</span>
            </div>
            <div class="sky-stat-card" title="<?php echo esc_attr('命中 ' . $hits . ' / 未命中 ' . $miss . '；无请求时不显示'); ?>">
                <span class="sky-stat-tip" aria-hidden="true">ⓘ</span>
                <span class="sky-stat-val"><?php echo $hit_rate === null ? '—' : esc_html($hit_rate . '%'); ?></span>
                <span class="sky-stat-lbl">命中率</span>
            </div>
        </div>
        <p style="color: var(--sky-text-muted); font-size: 12px; margin-top: 8px; margin-bottom: 0;">
            统计口径：以上数字均为自统计启用起的累计值，无固定时间窗口；缓存命中为 0 时请结合命中率判断——无请求与从不命中都会显示 0。
        </p>

        <?php
        // D2：最近文章处理状态表——最近 10 篇已发布文章，点标题进编辑页。
        // SEO 状态直接读已有 meta：有 _sky_seo_done 显示“已完成”；未完成
        // 且 _sky_seo_fail_count ≥ 1 显示“失败×N 次”；未完成且失败 0 次
        // 显示“未完成”。图片同步读 auto_spider() 每次跑完写的
        // _sky_spider_last（成功/失败/跳过数 + 时间）；从未同步过显示“未同步”。
        $recent_posts = get_posts(['post_type' => 'post', 'post_status' => 'publish', 'numberposts' => 10, 'orderby' => 'date', 'order' => 'DESC']);
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">📋 最近文章处理状态</div>
            <?php if (empty($recent_posts)) : ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">暂无已发布文章。</p>
            <?php else : ?>
            <table class="sky-health-table">
                <tr>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">标题</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">发布时间</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">SEO 状态</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">图片同步</th>
                </tr>
                <?php foreach ($recent_posts as $rp) :
                    $rp_id = (int)$rp->ID;
                    $seo_done = get_post_meta($rp_id, '_sky_seo_done', true);
                    $seo_fails = (int)get_post_meta($rp_id, '_sky_seo_fail_count', true);
                    if (!empty($seo_done)) { $seo_label = '已完成'; $seo_cls = 'ok'; }
                    elseif ($seo_fails >= 1) { $seo_label = '失败×' . $seo_fails . ' 次'; $seo_cls = 'error'; }
                    else { $seo_label = '未完成'; $seo_cls = ''; }
                    $spider = get_post_meta($rp_id, '_sky_spider_last', true);
                    if (is_array($spider) && isset($spider['success'])) {
                        $sp_label = '成功 ' . (int)$spider['success'] . ' / 失败 ' . (int)($spider['failed'] ?? 0);
                        $sp_cls = ((int)($spider['failed'] ?? 0) > 0) ? 'error' : '';
                        $sp_time = !empty($spider['time']) ? date('Y-m-d H:i', (int)$spider['time']) : '';
                    } else {
                        $sp_label = '未同步'; $sp_cls = ''; $sp_time = '';
                    }
                    $rp_date = !empty($rp->post_date) ? date('Y-m-d H:i', strtotime($rp->post_date)) : '—';
                ?>
                <tr>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;">
                        <a href="<?php echo esc_attr(admin_url('post.php?post=' . $rp_id . '&action=edit')); ?>"><?php echo esc_html($rp->post_title); ?></a>
                    </td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px; color: var(--sky-text-muted);"><?php echo esc_html($rp_date); ?></td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;">
                        <?php if ($seo_cls !== '') : ?><span class="sky-status-<?php echo $seo_cls; ?>"><?php echo esc_html($seo_label); ?></span><?php else : ?><?php echo esc_html($seo_label); ?><?php endif; ?>
                    </td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;">
                        <?php if ($sp_cls !== '') : ?><span class="sky-status-<?php echo $sp_cls; ?>"><?php echo esc_html($sp_label); ?></span><?php else : ?><?php echo esc_html($sp_label); ?><?php endif; ?><?php if ($sp_time !== '') : ?> <span style="color: var(--sky-text-muted); font-size: 12px;"><?php echo esc_html($sp_time); ?></span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>

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
                        <td class="sky-health-label">GD 库 (水印 / WebP)</td>
                        <td class="sky-health-val">
                            <span class="sky-status-<?php echo $health['gd']['status']; ?>">
                                <?php echo esc_html($health['gd']['label']); ?>
                            </span>
                        </td>
                    </tr>
                    <?php
                    // D3：定时任务健康行——下次触发 + 待处理 Skyline 任务数，
                    // 有逾期任务标红（红字样式与健康表一致）。统计口径见
                    // Skyline_OSS_Mod::cron_health()（infra.php，cron 注册旁）。
                    $cron_health = (class_exists('Skyline_OSS_Mod') && method_exists('Skyline_OSS_Mod', 'cron_health'))
                        ? Skyline_OSS_Mod::cron_health() : null;
                    ?>
                    <tr>
                        <td class="sky-health-label">定时任务（wp-cron）</td>
                        <td class="sky-health-val">
                            <?php if ($cron_health === null) : ?>
                                统计不可用（OSS 模块未加载）
                            <?php elseif ($cron_health['pending'] === 0) : ?>
                                无待处理任务
                            <?php else : ?>
                                下次 <?php echo esc_html(date('Y-m-d H:i', (int)$cron_health['next_ts'])); ?> · 待处理 <?php echo (int)$cron_health['pending']; ?> 个
                                <?php if ($cron_health['overdue'] > 0) : ?>
                                    <span class="sky-status-error">⚠️ 逾期 <?php echo (int)$cron_health['overdue']; ?> 个</span>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>
            
            <div class="sky-card">
                <div class="sky-card-title">📜 更新历史</div>
                <div style="font-size: 13px; color: var(--sky-text-muted); line-height: 1.8;">
                    <b>v2.2.6</b> - readme.txt 重写为英文（FAQ/截图/隐私披露/厂商 Terms/Support）；删除去水印功能；默认厂商改中性（留空自选，模型默认亦留空）；系统人设改通用版；插件头补 Plugin URI / Author URI；新增 GPL-2.0 LICENSE 文件<br>
                    <b>v2.2.5</b> - 单站硬编码通用化（前台欢迎语/推荐问题、气泡提示、后台品牌不再写本站名称）；删除损坏的默认头像 robot.png；插件头补齐 WordPress.org 要求字段；COS SDK 缺失时友好提示不致命；卸载时清理插件数据<br>
                    <b>v2.2.4</b> - AI 回复 Markdown 渲染（小标题、列表、代码块、链接）；系统人设改访客视角（接待来访的设计师、学生和景观爱好者，帮访客找站内资源，不提写文章/SEO/内容创作）<br>
                    <b>v2.2.3</b> - 修复 WebP 扫描 HTTP 524（分页 + 20 秒预算 + 续扫）；模型厂商下拉改不入库的预设选择器；模板库可一键填入系统人设；手动清缓存与 OSS 失败重试/待删队列操作入口；SEO Meta 输出（默认关）；对话端点补 /v4 与 /v1/chat；蜘蛛主开关三路生效；定时任务回调提到主文件注册<br>
                    <b>v2.2.2</b> - 修复批量卡片全瘫：admin.js 改页头加载（内联脚本调 SkyBatchRunner.create() 时驱动已定义）；批量/扫描失败文案带 HTTP 状态（403/504/500/断网可区分）；数据看板概览改成功能介绍，不再写当前厂商/模型名<br>
                    <b>v2.2.1</b> - 批量进度实时显示（WebP/图片同步/SEO 共用组件，在途批次可见、停止立即生效）+ 失败/跳过逐篇给原因；敏感字段防浏览器自动填充（密码框默认禁用、生图密钥独立）；生图 API Key 独立（留空跟随主 Key）；Prompt 模板库移到聊天记录上方<br>
                    <b>v2.2.0</b> - API 厂商预设下拉 + 模型联动（7 家 + 自定义，已存配置自动反推厂商）；前台聊天报错按原因区分（504 超时/403/60 秒超时/断网）<br>
                    <b>v2.1.1</b> - 审核遗留小尾巴全修：预览防 XSS、手动同步补排除检查、英文 Slug 不带中文人设、每日上限按本地天重置、WebP 原图删失败可重试、生图端点安全默认值；重新保存复活已放弃的自动 SEO、接口报错带出真实 message<br>
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
                '🔌 接口配置' => ['api_base', 'api_img_base', 'api_key', 'api_img_key', 'chat_model', 'image_model', 'system_prompt'],
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
        // v2.2.0：厂商下拉的选中态按已存 api_base 的 host 反推（旧配置没有
        // api_vendor 键也能对上；对不上落“自定义”并保留原值），不以 schema
        // 默认值 siliconflow 为准；两个模型 datalist 同样按反推出的厂商填充。
        // 只在渲染时算，不写库、不覆盖用户已存的自定义值；切厂商的联动只
        // 在用户主动改下拉时发生（见 assets/js/admin.js），加载页面不改值。
        $vendor_key = Skyline_Core::detect_api_vendor($this->core->get_opt('api_base', ''));
        $vendors = Skyline_Core::get_api_vendors();
        if (!isset($vendors[$vendor_key])) $vendor_key = 'custom';
        ?>
        <?php // v2.2.1：Prompt 模板库已移到“最近聊天记录”卡片正上方
              // （见本函数后部）；只挪位置，结构、id 与 JS 一字不动。 ?>

        <?php // v2.2.3：厂商预设选择器（A-1）：独立下拉，不入库——没有
              // name 属性、不随表单提交；选中态由 API Endpoint 地址反推
              // （$vendor_key），当前厂商不单独保存。切换时 admin.js 的
              // skyApplyVendor 即时改写下方字段，点“保存本页配置”才生效。 ?>
        <div class="sky-setting-block" style="margin-bottom: 16px;">
            <div class="sky-field-row">
                <div class="sky-field-info">
                    <div class="sky-field-label">模型厂商</div>
                    <div class="sky-field-desc">💡 切换厂商后，下方 API Endpoint、生图根地址与两个模型下拉即时更新（要点“保存本页配置”才生效）；选“自定义”可自由填写任意地址与模型。当前厂商由 API Endpoint 地址自动识别，不单独保存。</div>
                </div>
                <div class="sky-field-control">
                    <div class="sky-field-input" style="width:100%;">
                        <select id="sky-field-api_vendor">
                            <?php foreach ($vendors as $vk => $v): ?>
                                <option value="<?php echo esc_attr($vk); ?>" <?php selected($vendor_key, $vk); ?>><?php echo esc_html($v['label']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="sky-settings-grid sky-tab-ai">
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
                        if ($key === 'chat_model') $cfg['options'] = $vendors[$vendor_key]['chat_models'];
                        if ($key === 'image_model') $cfg['options'] = $vendors[$vendor_key]['image_models'];
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

        <?php // v2.2.0：厂商表以 JSON 嵌在页面里，供 assets/js/admin.js 做下拉联动；纯 PHP json_encode，不依赖 WP 函数。 ?>
        <script type="application/json" id="sky-api-vendors"><?php echo json_encode($vendors, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?></script>

        <?php // v2.2.1：Prompt 模板库移到“最近聊天记录”卡片正上方——
              // 原来在页首（v2.2.0 收口时提到最前面）；只挪位置，结构、
              // id 与 JS（loadPrompts/savePromptTemplate）一字不动。 ?>
        <div class="sky-card">
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
        // A1：最近聊天记录——读 core 的 skyline_ai_chat_log option（新记录
        // 在前，上限 50 条、超 30 天的写入时已清理）。失败行在“回答摘要”
        // 列显示简短错误（不含密钥与完整响应体），状态列只分成功/失败。
        // 注意：客户端“网络错误”（fetch 失败、EdgeOne 504、60 秒超时）
        // 根本到不了服务端 handler，记不进这张表——卡片下方已注明此局限。
        // 清空按钮是外层表单的 submit（表单 POST + 同表单 nonce，不写新 JS）；
        // 处理见 handle_settings() 顶部的 skyline_clear_chat_log 分支。
        $chat_log = method_exists($this->core, 'get_chat_log') ? $this->core->get_chat_log() : [];
        if (!is_array($chat_log)) $chat_log = [];
        ?>
        <div class="sky-card" style="margin-top: 24px;">
            <div class="sky-card-title">
                💬 最近聊天记录
                <button type="submit" name="skyline_clear_chat_log" value="1" class="button">
                    🗑️ 清空记录
                </button>
            </div>
            <?php if (empty($chat_log)) : ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">暂无聊天记录。访客通过前台悬浮助手发起的聊天会在这里留一条记录（问题、回答摘要、耗时与成败）。</p>
            <?php else : ?>
            <table class="sky-health-table">
                <tr>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">时间</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">问题</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">回答摘要</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">耗时</th>
                    <th style="text-align: left; padding: 10px 0; border-bottom: 1px solid var(--sky-border); color: var(--sky-text-muted); font-size: 13px;">状态</th>
                </tr>
                <?php foreach ($chat_log as $cl_entry) :
                    if (!is_array($cl_entry)) continue;
                    $cl_time = !empty($cl_entry['time']) ? date('Y-m-d H:i', (int)$cl_entry['time']) : '—';
                    $cl_q = (string)($cl_entry['question'] ?? '');
                    $cl_ok = (($cl_entry['status'] ?? '') === 'ok');
                    $cl_reply = $cl_ok ? (string)($cl_entry['reply'] ?? '') : (string)($cl_entry['error'] ?? '');
                    $cl_ms = (int)($cl_entry['ms'] ?? 0);
                    $cl_dur = $cl_ms >= 1000 ? round($cl_ms / 1000, 1) . ' 秒' : $cl_ms . ' 毫秒';
                ?>
                <tr>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px; color: var(--sky-text-muted); white-space: nowrap;"><?php echo esc_html($cl_time); ?></td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;"><?php echo esc_html($cl_q); ?></td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;"><?php echo $cl_reply !== '' ? esc_html($cl_reply) : '<span style="color: var(--sky-text-muted);">—</span>'; ?></td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px; color: var(--sky-text-muted); white-space: nowrap;"><?php echo esc_html($cl_dur); ?></td>
                    <td style="padding: 10px 0; border-bottom: 1px solid var(--sky-border); font-size: 13px;">
                        <?php if ($cl_ok) : ?><span class="sky-status-ok">✅ 成功</span><?php else : ?><span class="sky-status-error">❌ 失败</span><?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
            <p style="color: var(--sky-text-muted); font-size: 12px; margin-top: 8px;">
                仅保留最近 50 条、30 天内的记录；回答只存前 200 字摘要，IP 只存哈希。注意：访客浏览器侧的“网络错误”（请求发不出去、EdgeOne 504、60 秒超时）到不了服务器，记不进这张表；这里只覆盖到达服务端的聊天。
            </p>
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
                <button type="button" class="button" onclick="clearLogs()">
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
        <div class="sky-settings-grid sky-tab-<?php echo esc_attr($current_tab); ?>">
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
                            <button type="button" class="button button-primary" onclick="testService('sky_test_oss', this)">
                                🔌 测试 OSS 连接
                            </button>
                        <?php elseif ($current_tab === 'speed' && $group_index === 0): ?>
                            <button type="button" class="button button-primary" onclick="testService('sky_test_redis', this)">
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
            <?php $this->render_cache_clear_card(); ?>
        <?php endif; ?>
        <?php if ($current_tab === 'spider'): ?>
            <?php $this->render_spider_batch_card(); ?>
        <?php endif; ?>
        <?php if ($current_tab === 'seo'): ?>
            <?php $this->render_seo_batch_card(); ?>
        <?php endif; ?>
        <?php if ($current_tab === 'oss'): ?>
            <?php $this->render_oss_failed_list(); ?>
            <?php $this->render_pending_delete_queue(); ?>
        <?php endif; ?>
        <?php
    }

    // L20/v2.2.3：Zero-Disk 待删队列。读 _sky_oss_pending_delete 元数据
    // （上传成功后登记，24 小时后由 WP-Cron 二次校验才删），列附件、
    // 登记时间、预计删除时间、待删文件、重试次数与操作。
    // v2.2.3（O-6/O-8）：重试次数取登记数组里的 retries（infra.php 的
    // reschedule_purge() 写的，满 3 次停）；满 3 次的标“已停止重试”，
    // 队列里长期挂着已过期的条目从此有人工出口——每行“重试校验”
    // （sky_oss_purge_retry）/“取消登记”（sky_oss_purge_cancel，
    // 先 confirm），卡片上“立即校验一次”（sky_oss_purge_now，
    // wp-cron 饿死时也能手动触发一次校验）。按钮点后禁用、结束恢复、
    // 结果写 #sky-purge-queue-status、失败带 HTTP 状态（见 skyOssPost）。
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
            <div class="sky-card-title">
                🗑️ Zero-Disk 待删队列
                <button type="button" class="button button-primary" id="sky-purge-now-btn" onclick="skyPurgeNow(this)">
                    ⚡ 立即校验一次
                </button>
            </div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                上传成功后登记，24 小时后由 WP-Cron 二次校验 COS 对象（大小/ETag）通过才删除本地文件。
                <?php if (!$zero_disk_on): ?>
                    当前 Zero-Disk 开关已关闭：待删登记作废，不会执行删除；重新开启后新上传才会重新登记。
                <?php endif; ?>
                重试满 3 次后自动停止重试、清单保留待人工处理，可点“重试校验”再跑一次，或点“取消登记”放弃删除、保留本地文件。
            </p>
            <div id="sky-purge-queue-status" style="margin: 8px 0; font-size: 13px;"></div>
            <?php if (empty($ids)): ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">当前没有已登记待删的文件。</p>
            <?php else: ?>
                <table class="widefat">
                    <thead><tr><th>附件</th><th>登记时间</th><th>预计删除时间</th><th>待删文件</th><th>重试次数</th><th>操作</th></tr></thead>
                    <tbody>
                    <?php foreach ($ids as $id):
                        $pending = get_post_meta($id, '_sky_oss_pending_delete', true);
                        if (!is_array($pending) || empty($pending['files'])) continue;
                        $reg_time = isset($pending['time']) ? (int)$pending['time'] : 0;
                        $retries = isset($pending['retries']) ? (int)$pending['retries'] : 0;
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
                            <td><?php echo esc_html('重试 ' . $retries . ' 次'); ?><?php if ($retries >= 3): ?> <span class="sky-status-error">已停止重试</span><?php endif; ?></td>
                            <td style="white-space: nowrap;">
                                <button type="button" class="button button-small" onclick="skyPurgeRetry(<?php echo (int)$id; ?>, this)">🔄 重试校验</button>
                                <button type="button" class="button button-small" onclick="skyPurgeCancel(<?php echo (int)$id; ?>, this)">✖️ 取消登记</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }

    // v2.2.3（O-7）：OSS 上传失败清单。读 _sky_oss_failed_sizes 元数据
    // （infra.php 的 upload_all_sizes() 写的，每项含 type/key/path；
    // 重试次数是独立 meta _sky_oss_retry_attempts，上限 3 次），上限
    // 100 条。“手动重试全部”调 sky_oss_retry_failed（后端重试全部
    // 失败尺寸；另有约 1 小时后的自动重试兜底）。无失败时给空状态。
    private function render_oss_failed_list() {
        $ids = function_exists('get_posts') ? get_posts([
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'meta_key' => '_sky_oss_failed_sizes',
            'posts_per_page' => 100,
            'fields' => 'ids',
        ]) : [];
        if (!is_array($ids)) $ids = [];
        $truncated = count($ids) >= 100;
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">
                ⚠️ OSS 上传失败清单
                <button type="button" class="button button-primary" id="sky-oss-retry-failed-btn" onclick="skyOssRetryFailed(this)">
                    🔄 手动重试全部
                </button>
            </div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                某个尺寸上传 COS 失败时会记在这里（约 1 小时后自动重试，最多 3 次）；点“手动重试全部”可立即再跑一次，不必等自动重试。
            </p>
            <div id="sky-oss-failed-status" style="margin: 8px 0; font-size: 13px;"></div>
            <?php if (empty($ids)): ?>
                <p style="color: var(--sky-text-muted); font-size: 13px;">暂无上传失败的附件。</p>
            <?php else: ?>
                <?php if ($truncated): ?>
                    <p style="color: var(--sky-text-muted); font-size: 13px;">仅显示前 100 条。</p>
                <?php endif; ?>
                <table class="widefat">
                    <thead><tr><th>附件</th><th>失败尺寸</th><th>重试次数</th></tr></thead>
                    <tbody>
                    <?php foreach ($ids as $id):
                        $failed = get_post_meta($id, '_sky_oss_failed_sizes', true);
                        if (!is_array($failed) || empty($failed)) continue;
                        $attempts = (int)get_post_meta($id, '_sky_oss_retry_attempts', true);
                        $post = function_exists('get_post') ? get_post($id) : null;
                        $title = ($post && !empty($post->post_title)) ? $post->post_title : '—';
                        $failed_desc = [];
                        foreach ((array)$failed as $entry) {
                            if (!is_array($entry)) continue;
                            $failed_desc[] = ($entry['type'] ?? '?') . '：' . basename($entry['key'] ?? '');
                        }
                    ?>
                        <tr>
                            <td>#<?php echo esc_html($id); ?> <?php echo esc_html($title); ?></td>
                            <td><?php echo esc_html(implode('、', $failed_desc)); ?></td>
                            <td><?php echo esc_html('重试 ' . $attempts . ' 次'); ?><?php if ($attempts >= 3): ?> <span class="sky-status-error">已停止重试</span><?php endif; ?></td>
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
        // v2.2.1：批量驱动抽到 assets/js/admin.js 的 SkyBatchRunner，这里
        // 只留卡片专属配置（扫描解析、结果计数与文案）+ 同名全局函数
        // （按钮 onclick 不变）。失败与跳过都逐条列原因（服务端已带 reason）。
        var skyWebpOk = 0, skyWebpFail = 0, skyWebpSkip = 0;
        var skyWebpRunner = SkyBatchRunner.create({
            status: 'sky-webp-status', progress: 'sky-webp-progress', bar: 'sky-webp-bar', failures: 'sky-webp-failures',
            scanBtn: 'sky-webp-scan-btn', startBtn: 'sky-webp-start-btn', stopBtn: 'sky-webp-stop-btn',
            scanAction: 'sky_webp_scan', batchAction: 'sky_webp_batch', batchFailLabel: '批量转换',
            scanText: '正在扫描…',
            // v2.2.3：扫描改分页循环（admin.js 的 SkyBatchRunner 实现）——
            // 单请求遍历全部约 53,000 个附件会撞 EdgeOne 60 秒回源超时
            // （HTTP 524），分页后每请求只扫一段、带 offset 直到 done。
            scanPaged: true,
            nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>',
            batchSize: function() { return 20; },
            resetCounts: function(st) { skyWebpOk = 0; skyWebpFail = 0; skyWebpSkip = 0; },
            summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 张，成功 ' + skyWebpOk + '，失败 ' + skyWebpFail + '，跳过 ' + skyWebpSkip + '。'; },
            parseScan: function(d) {
                if (!d.supported) return { ids: [], statusText: '❌ 当前服务器 GD 不支持 WebP，无法转换。' };
                var sk = d.skipped || {};
                return { ids: d.ids || [], statusText: '可转换 ' + (d.ids || []).length + ' 张（已跳过：已转过 ' + (sk.converted || 0) + '、本地缺失 ' + (sk.missing || 0) + '、超 10MB ' + (sk.toolarge || 0) + '、待删登记中 ' + (sk.pending_delete || 0) + '）。' };
            },
            onResult: function(it, st, addLine) {
                if (it.status === 'converted') { skyWebpOk++; }
                else if (it.status === 'failed') { skyWebpFail++; addLine('附件 #' + it.id + '：' + (it.reason || '转换失败')); }
                else if (it.status === 'skipped') { skyWebpSkip++; addLine('附件 #' + it.id + '：跳过（' + (it.reason || '已处理过') + '）'); }
            }
        });
        function skyWebpScan() { skyWebpRunner.scan(); }
        function skyWebpStart() { skyWebpRunner.start(); }
        function skyWebpStop() { skyWebpRunner.stop(); }
        </script>
        <?php
    }

    // v2.2.3（P-6）：手动清缓存卡片（性能体检页）。此前全仓只有自动
    // 清理（文章状态变化时 smart_flush 按前缀清页面缓存），后台没有
    // 任何手动入口，体检页发现缓存异常后无处操作。两个按钮都只调
    // sky_clear_cache（后端由 infra.php 实现，只清 Redis 层）：
    // scope=page 清页面缓存，scope=all 清全部 Skyline 缓存。文案写明
    // 不碰 EdgeOne CDN 缓存、不要与 EdgeOne 同时清理（2026-11-09
    // 三层同清曾拖垮 FPM）。按钮点后禁用、结束恢复、结果写状态区、
    // 失败带 HTTP 状态（见全局 skyClearCache / skyOssPost）。
    private function render_cache_clear_card() {
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">🧹 缓存清理</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                仅清理 Redis 缓存层，不会清理 EdgeOne CDN 缓存；请勿与 EdgeOne 缓存同时清理（2026-11-09 三层同清曾拖垮 FPM）。
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary" id="sky-cache-clear-page-btn" onclick="skyClearCache('page', this)">
                    🧹 清空页面缓存
                </button>
                <button type="button" class="button button-primary" id="sky-cache-clear-all-btn" onclick="skyClearCache('all', this)">
                    🧹 清空全部 Skyline 缓存
                </button>
            </div>
            <div id="sky-cache-clear-status" style="margin-top: 12px; font-size: 13px;"></div>
        </div>
        <?php
    }

    // C1：全站外链图片体检 + 批量同步卡片（内容同步页）。先只读扫描，
    // 列出含外链图的文章（标题点进编辑页、外链图张数、涉及域名），再一
    // 键分批同步（复用 auto_spider，每批 5 篇、可随时停）。前端轮询模式
    // 照抄 WebP 批量卡片（扫描拿 id 列表 → 每批 POST → 进度条 + 停止标记）。
    private function render_spider_batch_card() {
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">🕷️ 全站外链图片体检 + 批量同步</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                先扫描全站已发布文章，列出含外链图片的文章（标题、外链图张数、涉及域名），再一键分批同步。
                同步复用发布时自动同步的同一套逻辑（本站与 COS 图片、排除域名的图不计入外链），结果记在每篇文章的最近一次同步记录里（与看板同一口径）。
                每批 5 篇，可随时停止；已处理的不受影响。
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                <button type="button" class="button button-primary" id="sky-spider-scan-btn" onclick="skySpiderScan()">
                    🔍 扫描外链图片
                </button>
                <button type="button" class="button button-primary" id="sky-spider-start-btn" onclick="skySpiderStart()" disabled>
                    ▶️ 开始批量同步
                </button>
                <button type="button" class="button" id="sky-spider-stop-btn" onclick="skySpiderStop()" disabled>
                    ⏹️ 停止
                </button>
            </div>
            <div id="sky-spider-status" style="margin-top: 12px; font-size: 13px;"></div>
            <div id="sky-spider-progress" style="display: none; margin-top: 8px; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden;">
                <div id="sky-spider-bar" style="height: 100%; width: 0%; background: #6366f1;"></div>
            </div>
            <div id="sky-spider-list-wrap" style="display: none; margin-top: 12px; max-height: 320px; overflow-y: auto;">
                <table class="widefat striped">
                    <thead><tr><th>文章（点标题进编辑页）</th><th style="width: 90px;">外链图</th><th>涉及域名</th></tr></thead>
                    <tbody id="sky-spider-list"></tbody>
                </table>
            </div>
            <ul id="sky-spider-failures" style="margin-top: 10px; font-size: 13px; color: #b91c1c; list-style: disc inside;"></ul>
        </div>
        <script>
        // v2.2.1：批量驱动抽到 assets/js/admin.js 的 SkyBatchRunner，这里
        // 只留卡片专属配置（扫描解析、结果计数与文案）+ 同名全局函数
        // （按钮 onclick 不变）。失败与跳过都逐条列原因（服务端已带 reason）。
        var skySpiderOk = 0, skySpiderFail = 0, skySpiderSkip = 0, skySpiderOkImgs = 0, skySpiderFailImgs = 0;
        var skySpiderRunner = SkyBatchRunner.create({
            status: 'sky-spider-status', progress: 'sky-spider-progress', bar: 'sky-spider-bar', failures: 'sky-spider-failures',
            scanBtn: 'sky-spider-scan-btn', startBtn: 'sky-spider-start-btn', stopBtn: 'sky-spider-stop-btn',
            scanAction: 'sky_spider_scan', batchAction: 'sky_spider_batch', batchFailLabel: '批量同步',
            scanText: '正在扫描全站文章…',
            nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>',
            batchSize: function() { return 5; },
            resetCounts: function(st) { skySpiderOk = 0; skySpiderFail = 0; skySpiderSkip = 0; skySpiderOkImgs = 0; skySpiderFailImgs = 0; },
            summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 篇，同步成功 ' + skySpiderOk + ' 篇（成功 ' + skySpiderOkImgs + ' 张 / 失败 ' + skySpiderFailImgs + ' 张），失败 ' + skySpiderFail + ' 篇，跳过 ' + skySpiderSkip + ' 篇。'; },
            parseScan: function(d) {
                var tbody = document.getElementById('sky-spider-list');
                tbody.innerHTML = '';
                (d.items || []).forEach(function(it) {
                    var tr = document.createElement('tr');
                    var tdT = document.createElement('td');
                    var a = document.createElement('a');
                    a.href = 'post.php?post=' + it.id + '&action=edit';
                    a.textContent = it.title || ('文章 #' + it.id);
                    tdT.appendChild(a);
                    var tdC = document.createElement('td'); tdC.textContent = it.count + ' 张';
                    var tdD = document.createElement('td'); tdD.textContent = (it.domains || []).join(', ');
                    tr.appendChild(tdT); tr.appendChild(tdC); tr.appendChild(tdD);
                    tbody.appendChild(tr);
                });
                document.getElementById('sky-spider-list-wrap').style.display = (d.items || []).length ? 'block' : 'none';
                var extra = d.truncated ? '（仅列出前 ' + (d.items || []).length + ' 篇，处理完再重扫）' : '';
                return { ids: (d.items || []).map(function(it) { return it.id; }), statusText: '共扫描 ' + (d.total_scanned || 0) + ' 篇文章，发现 ' + (d.total_posts || 0) + ' 篇含外链图、共 ' + (d.total_images || 0) + ' 张。' + extra };
            },
            onResult: function(it, st, addLine) {
                var title = '《' + (it.title || ('文章 #' + it.id)) + '》';
                if (it.status === 'done') {
                    skySpiderOk++;
                    skySpiderOkImgs += (it.success || 0);
                    skySpiderFailImgs += (it.failed || 0);
                } else if (it.status === 'failed') {
                    skySpiderFail++;
                    addLine(title + '：' + (it.reason || '同步失败（未返回原因）'));
                } else if (it.status === 'skipped') {
                    skySpiderSkip++;
                    addLine(title + '：跳过（' + (it.reason || '正在同步中') + '）');
                }
            }
        });
        function skySpiderScan() { skySpiderRunner.scan(); }
        function skySpiderStart() { skySpiderRunner.start(); }
        function skySpiderStop() { skySpiderRunner.stop(); }
        </script>
        <?php
    }

    // S1：历史文章 SEO 批量补全卡片（搜索优化页）。先只读扫描，列出缺
    // 英文 Slug / 缺摘要 / 缺标签的文章（每篇标清缺哪几项），再分批调
    // auto_seo_meta 补全；已有项跳过不重算。成本写在卡片上：每篇最多
    // 3 次 AI 调用，全站篇数动态取数（wp_count_posts）、最大调用数 =
    // 篇数 × 3——默认只勾选前 20 篇试跑，每批篇数可调（默认 5，上限
    // 20），可随时停。前端轮询模式照抄 WebP 批量卡片。
    private function render_seo_batch_card() {
        // v2.2.1：全站篇数动态取数（之前写死的“3,634 篇”已过时，实际
        // 扫描是 3,638 篇）；最大调用数 = 篇数 × 3（每篇最多 3 次）。
        $seo_total_posts = 0;
        if (function_exists('wp_count_posts')) {
            $seo_counts = wp_count_posts('post');
            $seo_total_posts = (int)($seo_counts->publish ?? 0);
        }
        $seo_max_calls = $seo_total_posts * 3;
        ?>
        <div class="sky-card" style="margin-top: 20px;">
            <div class="sky-card-title">🚀 历史文章 SEO 批量补全</div>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 14px;">
                扫描缺英文 Slug / 缺摘要 / 缺标签的老文章，分批调自动 SEO 补全；已有项跳过、不重算，已手动设好的英文 Slug 不会被覆盖。
            </p>
            <p style="color: var(--sky-text-muted); line-height: 1.6; font-size: 13px;">
                💰 成本说明：每篇最多 3 次 AI 调用（标签、Slug、摘要各 1 次；缺哪项补哪项，不缺的不调用）。
                <?php if ($seo_total_posts > 0): ?>
                全站 <?php echo number_format($seo_total_posts); ?> 篇文章若全部补全，约需 <?php echo number_format($seo_max_calls); ?> 次调用——请先用默认勾选的 20 篇试效果，确认没问题再逐步放大；批量可随时停止，已处理的不受影响。
                <?php else: ?>
                请先用默认勾选的 20 篇试效果，确认没问题再逐步放大；批量可随时停止，已处理的不受影响。
                <?php endif; ?>
            </p>
            <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
                <button type="button" class="button button-primary" id="sky-seo-scan-btn" onclick="skySeoScan()">
                    🔍 扫描缺失 SEO 的文章
                </button>
                <button type="button" class="button button-primary" id="sky-seo-start-btn" onclick="skySeoStart()" disabled>
                    ▶️ 开始批量补全
                </button>
                <button type="button" class="button" id="sky-seo-stop-btn" onclick="skySeoStop()" disabled>
                    ⏹️ 停止
                </button>
                <label style="font-size: 13px; color: var(--sky-text-muted);">
                    每批 <input type="number" id="sky-seo-batch-size" value="5" min="1" max="20" step="1" style="width: 64px;"> 篇（1–20）
                </label>
            </div>
            <div id="sky-seo-status" style="margin-top: 12px; font-size: 13px;"></div>
            <div id="sky-seo-cost" style="margin-top: 4px; font-size: 13px; color: var(--sky-text-muted);"></div>
            <div id="sky-seo-progress" style="display: none; margin-top: 8px; height: 10px; background: #e5e7eb; border-radius: 999px; overflow: hidden;">
                <div id="sky-seo-bar" style="height: 100%; width: 0%; background: #6366f1;"></div>
            </div>
            <div id="sky-seo-list-wrap" style="display: none; margin-top: 12px; max-height: 320px; overflow-y: auto;">
                <table class="widefat striped">
                    <thead><tr><th style="width: 36px;"><input type="checkbox" id="sky-seo-check-all" onclick="skySeoToggleAll(this.checked)" title="全选 / 取消全选"></th><th>文章（点标题进编辑页）</th><th>缺失项</th></tr></thead>
                    <tbody id="sky-seo-list"></tbody>
                </table>
            </div>
            <ul id="sky-seo-failures" style="margin-top: 10px; font-size: 13px; color: #b91c1c; list-style: disc inside;"></ul>
        </div>
        <script>
        // v2.2.1：批量驱动抽到 assets/js/admin.js 的 SkyBatchRunner，这里
        // 只留卡片专属配置（扫描解析、结果计数与文案）+ 同名全局函数
        // （按钮 onclick 不变）。失败与跳过都逐条列原因（服务端已带 reason）。
        var skySeoOk = 0, skySeoPartial = 0, skySeoFail = 0, skySeoSkip = 0;
        var skySeoMissingLabel = {slug: '缺 Slug', excerpt: '缺摘要', tags: '缺标签'};
        function skySeoCheckedIds() {
            var ids = [];
            document.querySelectorAll('.sky-seo-check:checked').forEach(function(cb) { ids.push(parseInt(cb.value, 10)); });
            return ids;
        }
        function skySeoToggleAll(checked) {
            document.querySelectorAll('.sky-seo-check').forEach(function(cb) { cb.checked = checked; });
        }
        var skySeoRunner = SkyBatchRunner.create({
            status: 'sky-seo-status', cost: 'sky-seo-cost', progress: 'sky-seo-progress', bar: 'sky-seo-bar', failures: 'sky-seo-failures',
            scanBtn: 'sky-seo-scan-btn', startBtn: 'sky-seo-start-btn', stopBtn: 'sky-seo-stop-btn',
            scanAction: 'sky_seo_scan', batchAction: 'sky_seo_batch', batchFailLabel: '批量补全',
            scanText: '正在扫描全站文章…',
            nonce: '<?php echo wp_create_nonce('skyline_ajax_nonce'); ?>',
            batchSize: function() {
                var sizeInput = document.getElementById('sky-seo-batch-size');
                var size = sizeInput ? parseInt(sizeInput.value, 10) : 5;
                if (isNaN(size) || size < 1) size = 1;
                if (size > 20) size = 20;
                return size;
            },
            batchParams: function(batch, size) { return { batch_size: size }; },
            collectIds: function(st) { return skySeoCheckedIds(); },
            emptyStartText: '请先勾选要补全的文章（默认勾选前 20 篇）。',
            resetCounts: function(st) { skySeoOk = 0; skySeoPartial = 0; skySeoFail = 0; skySeoSkip = 0; },
            summaryText: function(st) { return '已处理 ' + st.done + ' / ' + st.total + ' 篇，全部补全 ' + skySeoOk + ' 篇，部分补全 ' + skySeoPartial + ' 篇，失败 ' + skySeoFail + ' 篇，跳过 ' + skySeoSkip + ' 篇。'; },
            parseScan: function(d) {
                var items = d.items || [];
                var tbody = document.getElementById('sky-seo-list');
                tbody.innerHTML = '';
                items.forEach(function(it, idx) {
                    var tr = document.createElement('tr');
                    var tdCheck = document.createElement('td');
                    var cb = document.createElement('input');
                    cb.type = 'checkbox'; cb.className = 'sky-seo-check'; cb.value = it.id;
                    // 默认只勾选前 20 篇试跑（与 Skyline_Content::SEO_TRIAL_COUNT 一致），不默认全选。
                    cb.checked = idx < 20;
                    tdCheck.appendChild(cb);
                    var tdT = document.createElement('td');
                    var a = document.createElement('a');
                    a.href = 'post.php?post=' + it.id + '&action=edit';
                    a.textContent = it.title || ('文章 #' + it.id);
                    tdT.appendChild(a);
                    var tdM = document.createElement('td');
                    tdM.textContent = (it.missing || []).map(function(m) { return skySeoMissingLabel[m] || m; }).join('、');
                    tr.appendChild(tdCheck); tr.appendChild(tdT); tr.appendChild(tdM);
                    tbody.appendChild(tr);
                });
                document.getElementById('sky-seo-list-wrap').style.display = items.length ? 'block' : 'none';
                var extra = d.truncated ? '（仅列出前 ' + items.length + ' 篇，处理完再重扫）' : '';
                var checkAll = document.getElementById('sky-seo-check-all');
                if (checkAll) checkAll.checked = false;
                return {
                    ids: items.map(function(it) { return it.id; }),
                    statusText: '共扫描 ' + (d.total_scanned || 0) + ' 篇文章，发现 ' + (d.total_posts || 0) + ' 篇缺 SEO 项。' + extra,
                    costText: '本次缺项 ' + (d.total_posts || 0) + ' 篇，全部补全约需最多 ' + ((d.total_posts || 0) * 3) + ' 次 AI 调用（每篇最多 3 次）；默认已勾选前 20 篇，先试效果。'
                };
            },
            onResult: function(it, st, addLine) {
                var title = '《' + (it.title || ('文章 #' + it.id)) + '》';
                if (it.status === 'done') { skySeoOk++; }
                else if (it.status === 'partial') {
                    skySeoPartial++;
                    addLine(title + '：部分补全，仍缺 ' + (it.missing || []).map(function(m) { return skySeoMissingLabel[m] || m; }).join('、') + (it.reason ? '（' + it.reason + '）' : ''));
                }
                else if (it.status === 'failed') {
                    skySeoFail++;
                    addLine(title + '：' + (it.reason || '补全失败（未返回原因）'));
                }
                else if (it.status === 'skipped') {
                    skySeoSkip++;
                    addLine(title + '：跳过（' + (it.reason || '已无缺失项') + '）');
                }
            }
        });
        function skySeoScan() { skySeoRunner.scan(); }
        function skySeoStart() { skySeoRunner.start(); }
        function skySeoStop() { skySeoRunner.stop(); }
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
                // v2.2.0 收口：① 加“显示/隐藏”切换（admin.js 的
                // .sky-password-toggle 处理，只改 input 的 type，不改提交
                // 值；因 value 恒为空，切换只用于核对刚键入的新值，已存值
                // 永不进 DOM）；② 已存值与否只用“已配置/未配置”角标表示，
                // 不把值写进页面（.sky-password-wrap 是 flex，角标与按钮
                // 纵向居中对齐）。
                // v2.2.1 防自动填充：① 输入框默认 disabled——浏览器不往禁
                // 用字段里填已保存的登录信息，禁用字段也不提交，只有用户
                // 主动勾选“更改”才启用、此时键入的值才会被保存（这是真正
                // 的防线，autocomplete 只是提示、浏览器常忽略）；②
                // autocomplete="new-password"（不让浏览器把已保存的登录密
                // 码往里填）；③ 生图密钥（api_img_key）留空时跟随主 API
                // Key，角标在主密钥已配时显示“跟随 API Key”，占位文案也
                // 相应改成“留空表示跟随主 API Key”。
                $pw_configured = trim((string)$val) !== '';
                $pw_label = $pw_configured ? '已配置' : '未配置';
                $pw_placeholder = '留空表示不改';
                if ($key === 'api_img_key') {
                    $pw_placeholder = '留空表示跟随主 API Key';
                    if (!$pw_configured && trim((string)$this->core->get_opt('api_key')) !== '') {
                        $pw_label = '跟随 API Key';
                        $pw_configured = true;
                    }
                }
                echo '<div class="sky-field-input sky-password-wrap" style="width:100%;">';
                echo '<label style="font-size: 12px; white-space: nowrap;"><input type="checkbox" onclick="var i=document.getElementById(\'sky-field-' . esc_attr($key) . '\');i.disabled=!this.checked;if(this.checked)i.focus();"> 更改</label>';
                echo '<input class="sky-password-input" type="password" id="sky-field-' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" value="" autocomplete="new-password" placeholder="' . esc_attr($pw_placeholder) . '" disabled>';
                echo '<button type="button" class="button sky-password-toggle" aria-label="显示密钥">👁 显示</button>';
                echo '<span class="sky-status-badge' . ($pw_configured ? ' active' : '') . '">' . esc_html($pw_label) . '</span>';
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
                break;

            case 'url':
                // L15：url 字段用 type="url"，浏览器做格式校验、移动端出
                // 网址键盘，不再走纯 text 输入框。
                // v2.2.0 收口：title 悬停显示完整地址——输入框再宽也难一次
                // 看全超长 URL，title 是兜底（值本身仍可横向滚动查看）。
                // v2.2.1：autocomplete="off"（浏览器不把已保存的用户名往
                // 地址框里填；服务端还有 handle_settings 的 url 守卫兜底）。
                // 生图根地址留空时，placeholder 显示真实生效值（与
                // handle_gen_img 同源的 get_effective_img_base()），而不
                // 是让用户对着空框猜。
                $url_extra = ' autocomplete="off"';
                if ($key === 'api_img_base' && trim((string)$val) === '' && method_exists($this->core, 'get_effective_img_base')) {
                    $effective = $this->core->get_effective_img_base();
                    $url_extra .= ' placeholder="' . esc_attr($effective !== '' ? ('当前生效：' . $effective . '（留空则自动推导）') : '留空：无法从 API Endpoint 推导，请填写') . '"';
                }
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<input type="url" id="sky-field-' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" title="' . esc_attr($val) . '"' . $url_extra . '>';
                echo '</div>';
                break;
                
            case 'textarea':
                // v2.2.3：textarea 补 id（与 url/select/datalist 等分支一致
                // 的 sky-field-<key> 约定），供 Prompt 模板库的“填入系统
                // 人设”按钮定位系统人设字段（sky-field-system_prompt）。
                echo '<div class="sky-field-input" style="width:100%;">';
                echo '<textarea id="sky-field-' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" rows="3">' . esc_textarea($val) . '</textarea>';
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
                echo '<select id="sky-field-' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']">';
                foreach ($cfg['options'] as $opt_val => $opt_label) {
                    echo '<option value="' . esc_attr($opt_val) . '" ' . selected($val, $opt_val, false) . '>';
                    echo esc_html($opt_label);
                    echo '</option>';
                }
                echo '</select>';
                echo '</div>';
                break;

            case 'datalist':
                // v2.2.0：可打字下拉（input + datalist）——既能点选预设模型，
                // 也能直接输入厂商新出的模型名，绝不做成死下拉。$cfg['options']
                // 是当前厂商的模型清单（render_ai_tab 按反推出的厂商注入）；
                // 清单为空时（该厂商无绘图模型）不挂 list 属性，就是纯自由
                // 输入。datalist 元素始终渲染（哪怕空），否则 JS 切厂商后
                // 找不到节点、重建不了下拉。
                $dl_opts = isset($cfg['options']) && is_array($cfg['options']) ? array_values($cfg['options']) : [];
                $list_id = 'sky-list-' . $key;
                echo '<div class="sky-field-input" style="width:100%;">';
                // v2.2.0 收口：title 悬停显示完整模型名（同 url 分支的兜底）。
                // v2.2.1：autocomplete="off"（模型名不走浏览器自动填充）。
                echo '<input type="text" id="sky-field-' . esc_attr($key) . '" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" title="' . esc_attr($val) . '" autocomplete="off"' . (!empty($dl_opts) ? ' list="' . esc_attr($list_id) . '"' : '') . '>';
                echo '<datalist id="' . esc_attr($list_id) . '">';
                foreach ($dl_opts as $o) {
                    echo '<option value="' . esc_attr($o) . '"></option>';
                }
                echo '</datalist>';
                echo '</div>';
                break;
                
            default:
                echo '<div class="sky-field-input" style="width:100%;">';
                // v2.2.0 收口：title 悬停显示完整值（同 url 分支的兜底）。
                // v2.2.1：autocomplete="off"（不走浏览器自动填充）。
                echo '<input type="text" name="skyline_settings[' . esc_attr($key) . ']" value="' . esc_attr($val) . '" title="' . esc_attr($val) . '" autocomplete="off">';
                echo '</div>';
                break;
        }
    }
}
