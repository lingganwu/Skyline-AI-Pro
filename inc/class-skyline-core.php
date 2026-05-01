<?php
if (!defined('ABSPATH')) exit;

class Skyline_Core {
    private static $instance = null;
    private static $memory_cache = [];
    private $options = [];
    private $stat_cache = [];
    private $log_file;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->setup();
        }
        return self::$instance;
    }

    public function __construct() {
        $log_dir = WP_CONTENT_DIR . '/logs/skyline';
        $this->log_file = $log_dir . '/skyline_ai.log';
        
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
            // 安全：防止目录列表
            file_put_contents($log_dir . '/index.php', '<?php // Silence is golden');
            // 安全：Apache + Nginx 兼容的访问限制
            $htaccess = "# Apache\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Order deny,allow\n    Deny from all\n</IfModule>\n\n# Nginx\nlocation ~ /logs/skyline/ {\n    deny all;\n    return 404;\n}";
            file_put_contents($log_dir . '/.htaccess', $htaccess);
        }
    }

    public function setup() {
        $this->load_modules();
        
        // AJAX 处理器 - 带安全验证
        $this->register_ajax_handlers();
    }

    private function register_ajax_handlers() {
        // 公开 AJAX（需要额外安全措施）
        add_action('wp_ajax_sky_chat_front', [$this, 'handle_chat_front']);
        add_action('wp_ajax_nopriv_sky_chat_front', [$this, 'handle_chat_front']);
        
        // 管理员 AJAX
        $admin_actions = [
            'sky_ai_task', 'sky_gen_img', 'sky_test_api', 'sky_test_redis',
            'sky_test_oss', 'sky_clear_logs', 'sky_save_prompt', 'sky_get_prompts',
            'sky_check_quality', 'skyline_health_check'
        ];
        
        foreach ($admin_actions as $action) {
            add_action('wp_ajax_' . $action, [$this, 'handle_admin_ajax']);
        }
        
        // 前台资源
        add_action('wp_enqueue_scripts', [$this, 'enqueue_waifu_assets']);
        add_action('wp_footer', [$this, 'render_waifu']);
    }

    /**
     * 统一管理员 AJAX 处理器
     */
    public function handle_admin_ajax() {
        $action = current_filter();
        
        // 1. Nonce 验证
        if (!skyline_verify_nonce()) {
            wp_send_json_error(__('安全验证失败，请刷新页面重试。', 'skyline-ai-pro'), 403);
        }
        
        // 2. 权限检查
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('权限不足', 'skyline-ai-pro'), 403);
        }
        
        // 3. 速率限制
        if (!skyline_check_rate_limit($action, 30, 60)) {
            wp_send_json_error(__('请求过于频繁，请稍后再试。', 'skyline-ai-pro'), 429);
        }
        
        // 4. 路由到具体处理方法
        $handler = 'handle_' . str_replace('sky_', '', $action);
        if (method_exists($this, $handler)) {
            $this->$handler();
        } else {
            wp_send_json_error(__('未知操作', 'skyline-ai-pro'), 400);
        }
    }

    /**
     * 前台聊天 AJAX 处理器（带速率限制）
     */
    public function handle_chat_front() {
        // 1. Nonce 验证
        if (!skyline_verify_nonce()) {
            wp_send_json_error(__('安全验证失败', 'skyline-ai-pro'), 403);
        }
        
        // 2. 速率限制（每分钟 5 次）
        if (!skyline_check_rate_limit('chat_front', 5, 60)) {
            wp_send_json_error(__('请求过于频繁，请稍后再试。', 'skyline-ai-pro'), 429);
        }
        
        // 3. 输入验证
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        if (empty($message)) {
            wp_send_json_error(__('消息不能为空', 'skyline-ai-pro'), 400);
        }
        
        if (mb_strlen($message) > 2000) {
            wp_send_json_error(__('消息过长，请限制在 2000 字以内。', 'skyline-ai-pro'), 400);
        }
        
        // 4. 处理请求
        $response = $this->call_api([
            ['role' => 'system', 'content' => $this->get_opt('system_prompt')],
            ['role' => 'user', 'content' => $message]
        ]);
        
        if (is_wp_error($response)) {
            wp_send_json_error($response->get_error_message(), 500);
        }
        
        wp_send_json_success(['reply' => $response]);
    }

    private function load_modules() {
        if (class_exists('Skyline_Content')) new Skyline_Content();
        if (class_exists('Skyline_Infra')) {
            try { 
                Skyline_Infra::instance(); 
            } catch (Throwable $e) { 
                $this->log('Infra 模块加载失败: ' . $e->getMessage(), 'error'); 
            }
        }
        if (class_exists('Skyline_API_Gateway')) new Skyline_API_Gateway();
        if (is_admin() && class_exists('Skyline_Admin')) new Skyline_Admin();
    }

    public function get_config_schema() {
        return [
            // AI 配置
            'api_base' => ['group'=>'ai', 'type'=>'url', 'label'=>__('API Endpoint', 'skyline-ai-pro'), 'default'=>'https://api.siliconflow.cn/v1/chat/completions'],
            'api_key' => ['group'=>'ai', 'type'=>'password', 'label'=>__('API Key', 'skyline-ai-pro'), 'desc'=>__('你的 API 密钥 (sk-...)', 'skyline-ai-pro'), 'required'=>true],
            'chat_model' => ['group'=>'ai', 'type'=>'text', 'label'=>__('对话模型', 'skyline-ai-pro'), 'default'=>'deepseek-ai/DeepSeek-V3'],
            'image_model' => ['group'=>'ai', 'type'=>'text', 'label'=>__('绘图模型', 'skyline-ai-pro'), 'default'=>'black-forest-labs/FLUX.1-schnell'],
            'system_prompt' => ['group'=>'ai', 'type'=>'textarea', 'label'=>__('系统人设', 'skyline-ai-pro'), 'default'=>__('你是由灵感屋(lgwu.net)训练的专业内容创作助手。', 'skyline-ai-pro')],
            'robot_enable' => ['group'=>'ai', 'type'=>'bool', 'label'=>__('启用前台悬浮助手', 'skyline-ai-pro'), 'default'=>true],
            'robot_only_logged' => ['group'=>'ai', 'type'=>'bool', 'label'=>__('仅登录用户可见', 'skyline-ai-pro'), 'default'=>false],
            'robot_img' => ['group'=>'ai', 'type'=>'url', 'label'=>__('自定义图标 URL', 'skyline-ai-pro')],
            
            // 同步配置
            'sync_enable' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('启用内容同步', 'skyline-ai-pro'), 'default'=>true],
            'sync_auto' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('发布时自动同步', 'skyline-ai-pro'), 'default'=>true],
            'sync_max_img' => ['group'=>'sync', 'type'=>'number', 'label'=>__('单篇最大同步数', 'skyline-ai-pro'), 'default'=>20],
            'sync_allow_wechat' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('允许微信图片', 'skyline-ai-pro'), 'default'=>true],
            'sync_ssl_verify' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('验证 SSL 证书', 'skyline-ai-pro'), 'default'=>true],
            'sync_domains' => ['group'=>'sync', 'type'=>'textarea', 'label'=>__('排除域名 (一行一个)', 'skyline-ai-pro')],
            'sync_wm_enable' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('启用添加水印', 'skyline-ai-pro'), 'default'=>false],
            'sync_rm_wm' => ['group'=>'sync', 'type'=>'bool', 'label'=>__('智能去除水印 (裁剪底部)', 'skyline-ai-pro'), 'default'=>false],
            'sync_wm_text' => ['group'=>'sync', 'type'=>'text', 'label'=>__('文字水印内容', 'skyline-ai-pro'), 'default'=>'@Skyline'],
            'sync_wm_img_url' => ['group'=>'sync', 'type'=>'text', 'label'=>__('图片水印 URL', 'skyline-ai-pro')],
            'sync_wm_pos' => ['group'=>'sync', 'type'=>'text', 'label'=>__('水印位置', 'skyline-ai-pro'), 'default'=>'bottom-right'],
            
            // OSS 配置
            'oss_enable' => ['group'=>'oss', 'type'=>'bool', 'label'=>__('启用 OSS 云存储', 'skyline-ai-pro'), 'default'=>false],
            'oss_endpoint' => ['group'=>'oss', 'type'=>'text', 'label'=>__('Endpoint', 'skyline-ai-pro')],
            'oss_bucket' => ['group'=>'oss', 'type'=>'text', 'label'=>__('Bucket 名称', 'skyline-ai-pro')],
            'oss_ak' => ['group'=>'oss', 'type'=>'text', 'label'=>__('Access Key', 'skyline-ai-pro')],
            'oss_sk' => ['group'=>'oss', 'type'=>'password', 'label'=>__('Secret Key', 'skyline-ai-pro')],
            'oss_domain' => ['group'=>'oss', 'type'=>'text', 'label'=>__('自定义域名', 'skyline-ai-pro')],
            'oss_ssl_verify' => ['group'=>'oss', 'type'=>'bool', 'label'=>__('验证 SSL', 'skyline-ai-pro'), 'default'=>true],
            'oss_delete_local' => ['group'=>'oss', 'type'=>'bool', 'label'=>__('Zero-Disk (同步后删除本地文件)', 'skyline-ai-pro'), 'default'=>false],
            
            // SEO 配置
            'auto_tags' => ['group'=>'seo', 'type'=>'bool', 'label'=>__('自动生成标签', 'skyline-ai-pro'), 'default'=>true],
            'auto_slug' => ['group'=>'seo', 'type'=>'bool', 'label'=>__('自动生成英文 Slug', 'skyline-ai-pro'), 'default'=>true],
            'auto_excerpt' => ['group'=>'seo', 'type'=>'bool', 'label'=>__('自动生成摘要', 'skyline-ai-pro'), 'default'=>true],
            'auto_polish' => ['group'=>'seo', 'type'=>'bool', 'label'=>__('发布前 AI 智能润色', 'skyline-ai-pro'), 'default'=>false],
            'link_enable' => ['group'=>'seo', 'type'=>'bool', 'label'=>__('自动内链', 'skyline-ai-pro'), 'default'=>false],
            'link_pairs' => ['group'=>'seo', 'type'=>'textarea', 'label'=>__('内链关键词 (词|链接)', 'skyline-ai-pro')],
            
            // 性能配置
            'redis_enable' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('启用 Redis 缓存', 'skyline-ai-pro'), 'default'=>false],
            'redis_smart_purge' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('智能脏数据清理', 'skyline-ai-pro'), 'default'=>true],
            'redis_host' => ['group'=>'speed', 'type'=>'text', 'label'=>__('Redis Host', 'skyline-ai-pro'), 'default'=>'127.0.0.1'],
            'redis_port' => ['group'=>'speed', 'type'=>'number', 'label'=>__('Redis Port', 'skyline-ai-pro'), 'default'=>6379],
            'redis_auth' => ['group'=>'speed', 'type'=>'password', 'label'=>__('Redis Password', 'skyline-ai-pro')],
            'redis_db' => ['group'=>'speed', 'type'=>'number', 'label'=>__('Database', 'skyline-ai-pro'), 'default'=>0],
            'redis_ttl' => ['group'=>'speed', 'type'=>'number', 'label'=>__('TTL (秒)', 'skyline-ai-pro'), 'default'=>3600],
            'redis_exclude' => ['group'=>'speed', 'type'=>'textarea', 'label'=>__('排除缓存路径', 'skyline-ai-pro'), 'default'=>"/wp-json/\n/cart/\n/checkout/"],
            'redis_serializer' => ['group'=>'speed', 'type'=>'select', 'label'=>__('序列化', 'skyline-ai-pro'), 'default'=>'php', 'options' => ['php'=>'PHP Default', 'igbinary'=>'Igbinary']],
            'redis_compression' => ['group'=>'speed', 'type'=>'select', 'label'=>__('压缩', 'skyline-ai-pro'), 'default'=>'none', 'options' => ['none'=>'None', 'zstd'=>'Zstd', 'lzf'=>'LZF']],
            'turbo_disable_emoji' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('禁用 Emoji', 'skyline-ai-pro'), 'default'=>false],
            'turbo_disable_embeds' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('禁用 oEmbeds', 'skyline-ai-pro'), 'default'=>false],
            'turbo_disable_xmlrpc' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('禁用 XML-RPC', 'skyline-ai-pro'), 'default'=>false],
            'turbo_sanitize_names' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('文件名哈希化', 'skyline-ai-pro'), 'default'=>false],
            'turbo_lazy_load' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('强制图片懒加载', 'skyline-ai-pro'), 'default'=>true],
            'turbo_enable_image_opt' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('上传图片压缩', 'skyline-ai-pro'), 'default'=>false],
            'turbo_quality' => ['group'=>'speed', 'type'=>'number', 'label'=>__('压缩质量', 'skyline-ai-pro'), 'default'=>85],
            'turbo_limit_5m' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('限制上传 5MB', 'skyline-ai-pro'), 'default'=>false],
            'turbo_allow_svg' => ['group'=>'speed', 'type'=>'bool', 'label'=>__('允许 SVG/WebP', 'skyline-ai-pro'), 'default'=>false],
        ];
    }

    /**
     * 获取配置项（带多层缓存）
     */
    public function get_opt($key, $default = null) {
        // 1. 内存缓存（最快）
        if (isset(self::$memory_cache[$key])) {
            return self::$memory_cache[$key];
        }
        
        // 2. 对象缓存
        if (isset($this->options[$key])) {
            self::$memory_cache[$key] = $this->options[$key];
            return $this->options[$key];
        }
        
        // 3. Redis/Object Cache
        $cache_key = 'sky_opt_' . md5($key);
        $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
        if ($infra && method_exists($infra, 'cache_get')) {
            $cached = $infra->cache_get($cache_key);
            if ($cached !== null) {
                self::$memory_cache[$key] = $cached;
                $this->options[$key] = $cached;
                return $cached;
            }
        }
        
        // 4. WordPress Transient
        $transient = get_transient($cache_key);
        if ($transient !== false) {
            self::$memory_cache[$key] = $transient;
            $this->options[$key] = $transient;
            return $transient;
        }
        
        // 5. 数据库查询
        $db_opts = get_option('skyline_ai_settings', []);
        $val = $db_opts[$key] ?? ($this->get_config_schema()[$key]['default'] ?? $default);
        
        // 写入缓存
        set_transient($cache_key, $val, 86400);
        if ($infra) $infra->cache_set($cache_key, $val, 86400);
        self::$memory_cache[$key] = $val;
        $this->options[$key] = $val;
        
        return $val;
    }

    /**
     * 清除配置缓存
     */
    public function clear_opt_cache($key = null) {
        if ($key) {
            unset(self::$memory_cache[$key]);
            unset($this->options[$key]);
            delete_transient('sky_opt_' . md5($key));
            $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
            if ($infra) $infra->cache_del('sky_opt_' . md5($key));
        } else {
            self::$memory_cache = [];
            $this->options = [];
        }
    }

    /**
     * 记录日志
     */
    public function log($msg, $type = 'info', $context = 'System') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] [%s] [%s] %s\n", $timestamp, strtoupper($type), $context, $msg);
        
        // 使用 WordPress 文件系统 API
        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }
        
        if ($wp_filesystem) {
            $wp_filesystem->put_contents($this->log_file, $log_entry, FILE_APPEND);
        } else {
            // Fallback
            @file_put_contents($this->log_file, $log_entry, FILE_APPEND);
        }
    }

    /**
     * 统计递增
     */
    public function stat_inc($key, $val = 1) {
        $stats = get_option('skyline_ai_stats', []);
        if (!is_array($stats)) $stats = [];
        $current = isset($stats[$key]) ? floatval($stats[$key]) : 0;
        $stats[$key] = $current + floatval($val);
        update_option('skyline_ai_stats', $stats);
        $this->stat_cache[$key] = $stats[$key];
    }
    
    /**
     * 获取统计
     */
    public function stat_get($key) {
        if (isset($this->stat_cache[$key])) return $this->stat_cache[$key];
        $stats = get_option('skyline_ai_stats', []);
        if (!is_array($stats)) $stats = [];
        $val = isset($stats[$key]) ? $stats[$key] : 0;
        $this->stat_cache[$key] = $val;
        return $val;
    }

    /**
     * API 调用（带指数退避重试）
     */
    public function call_api($messages, $temp = 0.7, $retry_count = 0) {
        $k = $this->get_opt('api_key');
        if (!$k) {
            return new WP_Error('api_key_missing', __('API Key 未配置', 'skyline-ai-pro'));
        }

        // 请求缓存
        $request_hash = md5(json_encode($messages) . $temp);
        $cache_key = 'sky_api_' . $request_hash;
        $cached = wp_cache_get($cache_key, 'skyline');
        if ($cached !== false) return $cached;

        $this->stat_inc('api_calls');
        
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $k,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => $this->get_opt('chat_model'),
                'messages' => $messages,
                'temperature' => $temp
            ]),
            'timeout' => 120,
            'sslverify' => true
        ];

        $r = wp_remote_post($this->get_opt('api_base'), $args);
        
        if (is_wp_error($r)) {
            $err = $r->get_error_message();
            $this->log("API 调用失败: $err", 'error', 'API');
            $this->stat_inc('api_errors');
            
            // 指数退避重试
            if ($retry_count < SKY_MAX_RETRIES) {
                $delay = pow(2, $retry_count);
                sleep($delay);
                return $this->call_api($messages, $temp, $retry_count + 1);
            }
            
            return new WP_Error('api_failed', __('API 调用失败: ', 'skyline-ai-pro') . $err);
        }

        $code = wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);
        $d = json_decode($body, true);
        
        if ($code === 200 && isset($d['choices'][0]['message']['content'])) {
            $result = $d['choices'][0]['message']['content'];
            wp_cache_set($cache_key, $result, 'skyline', 300);
            return $result;
        }
        
        // 处理可重试的状态码
        if (in_array($code, [502, 503, 504]) && $retry_count < SKY_MAX_RETRIES) {
            $delay = pow(2, $retry_count);
            sleep($delay);
            return $this->call_api($messages, $temp, $retry_count + 1);
        }
        
        $error_msg = $d['error']['message'] ?? "HTTP $code";
        $this->log("API 返回错误: $error_msg", 'error', 'API');
        $this->stat_inc('api_errors');
        
        return new WP_Error('api_error', $error_msg);
    }
}
