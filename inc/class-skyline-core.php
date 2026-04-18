<?php
if (!defined('ABSPATH')) exit;

class Skyline_Core {
    private static $instance = null;
    private $options = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->setup();
        }
        return self::$instance;
    }

    public function __construct() {}

    public function setup() {
        $this->load_modules();
        
        add_action('wp_ajax_sky_chat_front', [$this, 'handle_chat_front']);
        add_action('wp_ajax_nopriv_sky_chat_front', [$this, 'handle_chat_front']);
        add_action('wp_ajax_sky_ai_task', [$this, 'handle_ai_task']);
        add_action('wp_ajax_sky_gen_img', [$this, 'handle_gen_img']);
        add_action('wp_ajax_sky_test_api', [$this, 'handle_api_test']);
        add_action('wp_ajax_sky_clear_logs', [$this, 'handle_clear_logs']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_waifu_assets']);
        add_action('wp_footer', [$this, 'render_waifu']);
    }

    private function load_modules() {
        if (class_exists('Skyline_Content')) new Skyline_Content();
        if (class_exists('Skyline_Infra')) {
            try { new Skyline_Infra(); } catch(Throwable $e) { error_log('Skyline Infra Load Error: '.$e->getMessage()); }
        }
        if (is_admin() && class_exists('Skyline_Admin')) new Skyline_Admin();
    }

    public function get_config_schema() {
        return [
            // AI 核心
            'api_base' => ['group'=>'ai', 'type'=>'url', 'label'=>'API Endpoint', 'default'=>'https://api.siliconflow.cn/v1/chat/completions'],
            'api_key' => ['group'=>'ai', 'type'=>'password', 'label'=>'API Key', 'desc'=>'你的 API 密钥 (sk-...)', 'required'=>true],
            'chat_model' => ['group'=>'ai', 'type'=>'text', 'label'=>'对话模型', 'default'=>'deepseek-ai/DeepSeek-V3'],
            'image_model' => ['group'=>'ai', 'type'=>'text', 'label'=>'绘图模型', 'default'=>'black-forest-labs/FLUX.1-schnell'],
            'system_prompt' => ['group'=>'ai', 'type'=>'textarea', 'label'=>'系统人设', 'default'=>"你是由灵感屋(lgwu.net)训练的专业内容创作助手。\n1. 内容风格：专业、客观、有深度。\n2. SEO优化：自然融入关键词。\n3. 语气：亲切、乐于助人。"],
            
            // 看板娘
            'robot_enable' => ['group'=>'ai', 'type'=>'bool', 'label'=>'启用前台悬浮助手', 'default'=>true],
            'robot_only_logged' => ['group'=>'ai', 'type'=>'bool', 'label'=>'仅登录用户可见', 'default'=>false],
            'robot_img' => ['group'=>'ai', 'type'=>'url', 'label'=>'自定义图标 URL'],

            // 蜘蛛采集
            'spider_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用采集模块', 'default'=>true],
            'spider_auto' => ['group'=>'spider', 'type'=>'bool', 'label'=>'发布时自动采集', 'default'=>true],
            'spider_max_img' => ['group'=>'spider', 'type'=>'number', 'label'=>'单篇最大抓取数', 'default'=>20],
            'spider_allow_wechat' => ['group'=>'spider', 'type'=>'bool', 'label'=>'允许微信图片', 'default'=>true],
            'spider_ssl_verify' => ['group'=>'spider', 'type'=>'bool', 'label'=>'验证 SSL 证书', 'default'=>true, 'desc'=>'默认开启以保障安全。如果遇到采集失败(cURL error 60)，可尝试关闭。'],
            'spider_domains' => ['group'=>'spider', 'type'=>'textarea', 'label'=>'排除域名 (一行一个)'],
            'spider_wm_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用添加水印', 'default'=>false],
            'spider_rm_wm' => ['group'=>'spider', 'type'=>'bool', 'label'=>'智能去除水印 (裁剪底部)', 'default'=>false, 'desc'=>'自动裁剪图片底部 40px，去除常见的网站水印条。'],
            'spider_wm_text' => ['group'=>'spider', 'type'=>'text', 'label'=>'文字水印内容', 'default'=>'@Skyline'],
            'spider_wm_img_url' => ['group'=>'spider', 'type'=>'text', 'label'=>'图片水印 URL'],
            'spider_wm_pos' => ['group'=>'spider', 'type'=>'text', 'label'=>'水印位置', 'default'=>'bottom-right'],

            // OSS
            'oss_enable' => ['group'=>'oss', 'type'=>'bool', 'label'=>'启用 OSS 云存储', 'default'=>false],
            'oss_endpoint' => ['group'=>'oss', 'type'=>'text', 'label'=>'Endpoint', 'placeholder'=>'oss-cn-hangzhou.aliyuncs.com'],
            'oss_bucket' => ['group'=>'oss', 'type'=>'text', 'label'=>'Bucket 名称'],
            'oss_ak' => ['group'=>'oss', 'type'=>'text', 'label'=>'Access Key'],
            'oss_sk' => ['group'=>'oss', 'type'=>'password', 'label'=>'Secret Key'],
            'oss_domain' => ['group'=>'oss', 'type'=>'text', 'label'=>'自定义域名'],
            'oss_ssl_verify' => ['group'=>'oss', 'type'=>'bool', 'label'=>'验证 SSL', 'default'=>true, 'desc'=>'连接 OSS 时验证证书。'],

            // SEO 自动化
            'auto_tags' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成标签', 'default'=>true],
            'auto_slug' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成英文 Slug', 'default'=>true],
            'auto_excerpt' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成摘要', 'default'=>true],
            'auto_polish' => ['group'=>'seo', 'type'=>'bool', 'label'=>'发布前 AI 智能润色', 'default'=>false, 'desc'=>'发布时自动调用 AI 修正错别字和语病。'],
            'link_enable' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动内链', 'default'=>false],
            'link_pairs' => ['group'=>'seo', 'type'=>'textarea', 'label'=>'内链关键词 (词|链接)'],

            // Redis & Turbo
            'redis_enable' => ['group'=>'speed', 'type'=>'bool', 'label'=>'启用 Redis 缓存', 'default'=>false],
            'redis_smart_purge' => ['group'=>'speed', 'type'=>'bool', 'label'=>'智能脏数据清理', 'default'=>true, 'desc'=>'仅清理文章相关缓存，减少服务器负载。'],
            'redis_host' => ['group'=>'speed', 'type'=>'text', 'label'=>'Redis Host', 'default'=>'127.0.0.1'],
            'redis_port' => ['group'=>'speed', 'type'=>'number', 'label'=>'Redis Port', 'default'=>6379],
            'redis_auth' => ['group'=>'speed', 'type'=>'password', 'label'=>'Redis Password'],
            'redis_db' => ['group'=>'speed', 'type'=>'number', 'label'=>'Database', 'default'=>0],
            'redis_ttl' => ['group'=>'speed', 'type'=>'number', 'label'=>'TTL (秒)', 'default'=>3600],
            'redis_exclude' => ['group'=>'speed', 'type'=>'textarea', 'label'=>'排除缓存路径', 'default'=>"/wp-json/\n/cart/\n/checkout/"],
            
            'redis_serializer' => [
                'group'=>'speed', 'type'=>'select', 'label'=>'序列化 (Serializer)', 'default'=>'php',
                'options' => ['php'=>'PHP Default (标准)', 'igbinary'=>'Igbinary (高性能/推荐)']
            ],
            'redis_compression' => [
                'group'=>'speed', 'type'=>'select', 'label'=>'压缩算法 (Compression)', 'default'=>'none',
                'options' => ['none'=>'不压缩', 'zstd'=>'Zstd (高压缩率/推荐)', 'lzf'=>'LZF (极速)']
            ],

            // Turbo
            'turbo_disable_emoji' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 Emoji', 'default'=>false],
            'turbo_disable_embeds' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 oEmbeds', 'default'=>false],
            'turbo_disable_xmlrpc' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 XML-RPC', 'default'=>false],
            'turbo_sanitize_names' => ['group'=>'speed', 'type'=>'bool', 'label'=>'文件名哈希化', 'default'=>false],
            'turbo_lazy_load' => ['group'=>'speed', 'type'=>'bool', 'label'=>'强制图片懒加载', 'default'=>true],
            'turbo_enable_image_opt' => ['group'=>'speed', 'type'=>'bool', 'label'=>'上传图片压缩', 'default'=>false],
            'turbo_quality' => ['group'=>'speed', 'type'=>'number', 'label'=>'压缩质量', 'default'=>85],
            'turbo_limit_5m' => ['group'=>'speed', 'type'=>'bool', 'label'=>'限制上传 5MB', 'default'=>false],
            'turbo_allow_svg' => ['group'=>'speed', 'type'=>'bool', 'label'=>'允许 SVG/WebP', 'default'=>false],
        ];
    }

    public function get_opt($key, $default = null) {
        if ($this->options === null) $this->options = get_option('skyline_ai_settings', []);
        if (isset($this->options[$key])) return $this->options[$key];
        $schema = $this->get_config_schema();
        return isset($schema[$key]['default']) ? $schema[$key]['default'] : $default;
    }

    public function log($msg, $type = 'info', $context = 'System') {
        $logs = get_option('skyline_ai_logs', []);
        if(!is_array($logs)) $logs = [];
        
        if (is_string($msg)) {
            $msg = preg_replace('/sk-[a-zA-Z0-9]{20,}/', 'sk-***', $msg);
        }
        
        $entry = ['time' => current_time('m-d H:i:s'), 'type' => $type, 'ctx' => $context, 'msg' => $msg];
        array_unshift($logs, $entry);
        if(count($logs) > 200) array_splice($logs, 200);
        update_option('skyline_ai_logs', $logs, 'no');
    }

    public function handle_clear_logs() {
        check_ajax_referer('sky_clear_logs_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('权限不足');
        update_option('skyline_ai_logs', []);
        wp_send_json_success('已清空');
    }

    public function stat_inc($key, $val=1) {
        $stats = get_option('skyline_ai_stats', []);
        if(!is_array($stats)) $stats = [];
        $current = isset($stats[$key]) ? floatval($stats[$key]) : 0;
        $stats[$key] = $current + floatval($val);
        update_option('skyline_ai_stats', $stats);
    }
    
    public function stat_get($key) {
        $stats = get_option('skyline_ai_stats', []);
        return isset($stats[$key]) ? $stats[$key] : 0;
    }

    public function call_api($messages, $temp = 0.7) {
        if(function_exists('set_time_limit')) @set_time_limit(300);
        $k = $this->get_opt('api_key'); 
        if(!$k) return 'Error: 请在后台配置 API Key';
        
        $this->stat_inc('api_calls');
        
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model'=>$this->get_opt('chat_model'), 'messages'=>$messages, 'temperature'=>$temp, 'max_tokens'=>4096
            ]),
            'timeout' => 120, 'sslverify' => true
        ];
        
        $r = wp_remote_post($this->get_opt('api_base'), $args);
        
        if(is_wp_error($r)) {
            $this->log('API Error: '.$r->get_error_message(), 'error', 'AI');
            return "网络错误: " . $r->get_error_message();
        }
        
        $code = wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);
        
        if($code >= 200 && $code < 300) {
            $d = json_decode($body, true);
            if(is_array($d) && isset($d['choices'][0]['message']['content'])) {
                $this->log('AI 响应成功 (Tokens: '. ($d['usage']['total_tokens']??'N/A') .')', 'info', 'AI');
                return trim((string)$d['choices'][0]['message']['content']);
            }
            $this->log('API 格式异常: '.substr($body, 0, 50), 'error', 'AI');
            return 'API 返回为空或格式异常';
        }
        $this->log("API Error $code", 'error', 'AI');
        return "API Error $code: " . substr($body, 0, 100);
    }

    public function handle_api_test() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('权限不足');
        
        $t1 = microtime(true);
        $res = $this->call_api([['role'=>'user', 'content'=>'Ping']], 0.1);
        $t2 = microtime(true);
        
        if(strpos($res, 'Error') === false) {
            wp_send_json_success(['time'=>round(($t2-$t1)*1000).'ms', 'reply'=>$res]);
        } else {
            wp_send_json_error($res);
        }
    }

    public function handle_chat_front() {
        check_ajax_referer('sky_chat_nonce');
        if($this->get_opt('robot_only_logged') && !is_user_logged_in()) wp_send_json_error('请登录');
        
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'sky_lim_' . md5((string)$ip);
        if(get_transient($key) > 10) wp_send_json_error('Too many requests');
        set_transient($key, (get_transient($key)?:0)+1, 60);

        $msg = sanitize_text_field($_POST['msg'] ?? '');
        if(!$msg) wp_send_json_error('Empty');

        wp_send_json_success($this->call_api([
            ['role'=>'system', 'content'=>'你是灵感屋(lgwu.net)的智能客服。'],
            ['role'=>'user', 'content'=>$msg]
        ]));
    }

    public function handle_ai_task() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');

        $t = sanitize_key($_POST['task'] ?? '');
        $i = wp_kses_post($_POST['input'] ?? '');
        if(!$i) wp_send_json_error('内容为空');

        $p = ''; 
        switch($t) {
            case 'title': $p = '请生成一个吸引人的中文SEO标题，不要引号：'; break;
            case 'outline': $p = '请生成文章大纲，Markdown格式：'; break;
            case 'continue': $p = '请续写这段文字：'; break;
            case 'rewrite': $p = '请伪原创改写，保持原意：'; break;
            case 'rewrite_full': $p = '请伪原创改写全文，保留HTML结构，仅修改文字使其通顺差异化：'; break;
            case 'polish': $p = '请润色这段文字，使其更专业：'; break;
            case 'expand': $p = '请扩写这段文字，增加细节：'; break;
            case 'shorten': $p = '请精简这段文字：'; break;
            case 'trans': $p = '翻译成中文或英文：'; break;
            case 'tags': $p = '提取5个核心SEO标签，逗号分隔，不要编号：'; break;
            case 'desc': $p = '生成120字SEO摘要，包含核心关键词：'; break;
            case 'slug_en': $p = 'Generate a concise English URL slug (max 5 words) based on the title. STRICTLY OUTPUT ONLY THE SLUG (lowercase, hyphens only). No explanation. Title:'; break; 
        }
        
        wp_send_json_success($this->call_api([['role'=>'user', 'content'=>$p."\n\n".$i]]));
    }

    public function handle_gen_img() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('upload_files')) wp_send_json_error('权限不足');

        $prompt = sanitize_text_field($_POST['prompt'] ?? '');
        if(!$prompt) wp_send_json_error('提示词为空');

        $k = $this->get_opt('api_key');
        $model = $this->get_opt('image_model', 'black-forest-labs/FLUX.1-schnell');
        
        $url = 'https://api.siliconflow.cn/v1/images/generations';
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $model, 'prompt' => $prompt, 'image_size' => '1024x1024']),
            'timeout' => 60
        ];

        $r = wp_remote_post($url, $args);
        if(is_wp_error($r)) wp_send_json_error($r->get_error_message());
        
        $d = json_decode(wp_remote_retrieve_body($r), true);
        if(!empty($d['data'][0]['url'])) {
            $this->log("AI 生图成功", 'info', 'Img');
            wp_send_json_success($d['data'][0]['url']);
        } else {
            $this->log("AI 生图失败", 'error', 'Img');
            wp_send_json_error('生成失败: ' . json_encode($d));
        }
    }

    
    public function enqueue_waifu_assets() {
        if(!$this->get_opt('robot_enable')) return;
        if($this->get_opt('robot_only_logged') && !is_user_logged_in()) return;

        wp_enqueue_style('skyline-waifu-css', plugins_url('assets/css/waifu.css', SKY_PATH));
        wp_enqueue_script('skyline-waifu-js', plugins_url('assets/js/waifu.js', SKY_PATH), [], SKY_VERSION, true);
        
        wp_localize_script('skyline-waifu-js', 'skyline_vars', [
            'nonce' => wp_create_nonce('sky_chat_nonce'),
            'ajax_url' => admin_url('admin-ajax.php')
        ]);
    }


    public function render_waifu() {
        if(!$this->get_opt('robot_enable')) return;
        if($this->get_opt('robot_only_logged') && !is_user_logged_in()) return;
        $img = $this->get_opt('robot_img') ?: 'https://api.iconify.design/fluent-emoji:robot.svg';
        ?>
        
        <div id="sky-waifu" onclick="toggleSkyChat()">
            <img src="<?php echo esc_url($img); ?>">
            <div id="sky-waifu-tips" class="show">👋 嗨！我是智能助手，有问题随时点我哦~</div>
        </div>
        <div id="sky-chat-box">
            <div class="sky-head">Skyline Assistant <span onclick="toggleSkyChat();event.stopPropagation()" style="cursor:pointer">×</span></div>
            <div id="sky-chat-msgs"><div class="sky-msg ai">👋 你好！我是灵感屋 AI 助手，有什么可以帮你的吗？</div></div>
            <div class="sky-foot">
                <input id="sky-chat-in" placeholder="输入消息..." onkeydown="if(event.keyCode==13) skySend()">
                <button onclick="skySend()" style="background:#6366f1;color:#fff;border:none;border-radius:50%;width:32px;height:32px;cursor:pointer;">➤</button>
            </div>
        </div>
        
        <?php
    }
}