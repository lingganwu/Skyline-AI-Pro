<?php
if (!defined('ABSPATH')) exit;

class Skyline_Core {
    private static $instance = null;
    private $options = null;
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
        $this->log_file = plugin_dir_path(__FILE__) . '../logs/skyline_ai.log';
        if (!file_exists(dirname($this->log_file))) {
            wp_mkdir_p(dirname($this->log_file));
            file_put_contents(dirname($this->log_file) . '/.htaccess', 'Deny from all');
        }
    }

    public function setup() {
        $this->load_modules();
        
        add_action('wp_ajax_sky_chat_front', [$this, 'handle_chat_front']);
        add_action('wp_ajax_nopriv_sky_chat_front', [$this, 'handle_chat_front']);
        add_action('wp_ajax_sky_ai_task', [$this, 'handle_ai_task']);
        add_action('wp_ajax_sky_gen_img', [$this, 'handle_gen_img']);
        add_action('wp_ajax_sky_test_api', [$this, 'handle_api_test']);
        add_action('wp_ajax_sky_test_redis', [$this, 'handle_test_redis']);
        add_action('wp_ajax_sky_test_oss', [$this, 'handle_test_oss']);
        add_action('wp_ajax_sky_clear_logs', [$this, 'handle_clear_logs']);
        add_action('wp_ajax_sky_save_prompt', [$this, 'handle_save_prompt']);
        add_action('wp_ajax_sky_get_prompts', [$this, 'handle_get_prompts']);
        add_action('wp_ajax_sky_check_quality', [$this, 'handle_check_quality']);
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
            'api_base' => ['group'=>'ai', 'type'=>'url', 'label'=>'API Endpoint', 'default'=>'https://api.siliconflow.cn/v1/chat/completions'],
            'api_key' => ['group'=>'ai', 'type'=>'password', 'label'=>'API Key', 'desc'=>'你的 API 密钥 (sk-...)', 'required'=>true],
            'chat_model' => ['group'=>'ai', 'type'=>'text', 'label'=>'对话模型', 'default'=>'deepseek-ai/DeepSeek-V3'],
            'image_model' => ['group'=>'ai', 'type'=>'text', 'label'=>'绘图模型', 'default'=>'black-forest-labs/FLUX.1-schnell'],
            'system_prompt' => ['group'=>'ai', 'type'=>'textarea', 'label'=>'系统人设', 'default'=>"你是由灵感屋(lgwu.net)训练的专业内容创作助手。"],
            'robot_enable' => ['group'=>'ai', 'type'=>'bool', 'label'=>'启用前台悬浮助手', 'default'=>true],
            'robot_only_logged' => ['group'=>'ai', 'type'=>'bool', 'label'=>'仅登录用户可见', 'default'=>false],
            'robot_img' => ['group'=>'ai', 'type'=>'url', 'label'=>'自定义图标 URL'],
            'spider_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用内容同步', 'default'=>true],
            'spider_auto' => ['group'=>'spider', 'type'=>'bool', 'label'=>'发布时自动同步', 'default'=>true],
            'spider_max_img' => ['group'=>'spider', 'type'=>'number', 'label'=>'单篇最大同步数', 'default'=>20],
            'spider_allow_wechat' => ['group'=>'spider', 'type'=>'bool', 'label'=>'允许微信图片', 'default'=>true],
            'spider_ssl_verify' => ['group'=>'spider', 'type'=>'bool', 'label'=>'验证 SSL 证书', 'default'=>true],
            'spider_domains' => ['group'=>'spider', 'type'=>'textarea', 'label'=>'排除域名 (一行一个)'],
            'spider_wm_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用添加水印', 'default'=>false],
            'spider_rm_wm' => ['group'=>'spider', 'type'=>'bool', 'label'=>'智能去除水印 (裁剪底部)', 'default'=>false],
            'spider_wm_text' => ['group'=>'spider', 'type'=>'text', 'label'=>'文字水印内容', 'default'=>'@Skyline'],
            'spider_wm_img_url' => ['group'=>'spider', 'type'=>'text', 'label'=>'图片水印 URL'],
            'spider_wm_pos' => ['group'=>'spider', 'type'=>'text', 'label'=>'水印位置', 'default'=>'bottom-right'],
            'oss_enable' => ['group'=>'oss', 'type'=>'bool', 'label'=>'启用 OSS 云存储', 'default'=>false],
            'oss_endpoint' => ['group'=>'oss', 'type'=>'text', 'label'=>'Endpoint'],
            'oss_bucket' => ['group'=>'oss', 'type'=>'text', 'label'=>'Bucket 名称'],
            'oss_ak' => ['group'=>'oss', 'type'=>'text', 'label'=>'Access Key'],
            'oss_sk' => ['group'=>'oss', 'type'=>'password', 'label'=>'Secret Key'],
            'oss_domain' => ['group'=>'oss', 'type'=>'text', 'label'=>'自定义域名'],
            'oss_ssl_verify' => ['group'=>'oss', 'type'=>'bool', 'label'=>'验证 SSL', 'default'=>true],
            'auto_tags' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成标签', 'default'=>true],
            'auto_slug' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成英文 Slug', 'default'=>true],
            'auto_excerpt' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成摘要', 'default'=>true],
            'auto_polish' => ['group'=>'seo', 'type'=>'bool', 'label'=>'发布前 AI 智能润色', 'default'=>false],
            'link_enable' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动内链', 'default'=>false],
            'link_pairs' => ['group'=>'seo', 'type'=>'textarea', 'label'=>'内链关键词 (词|链接)'],
            'redis_enable' => ['group'=>'speed', 'type'=>'bool', 'label'=>'启用 Redis 缓存', 'default'=>false],
            'redis_smart_purge' => ['group'=>'speed', 'type'=>'bool', 'label'=>'智能脏数据清理', 'default'=>true],
            'redis_host' => ['group'=>'speed', 'type'=>'text', 'label'=>'Redis Host', 'default'=>'127.0.0.1'],
            'redis_port' => ['group'=>'speed', 'type'=>'number', 'label'=>'Redis Port', 'default'=>6379],
            'redis_auth' => ['group'=>'speed', 'type'=>'password', 'label'=>'Redis Password'],
            'redis_db' => ['group'=>'speed', 'type'=>'number', 'label'=>'Database', 'default'=>0],
            'redis_ttl' => ['group'=>'speed', 'type'=>'number', 'label'=>'TTL (秒)', 'default'=>3600],
            'redis_exclude' => ['group'=>'speed', 'type'=>'textarea', 'label'=>'排除缓存路径', 'default'=>"/wp-json/\n/cart/\n/checkout/"],
            'redis_serializer' => ['group'=>'speed', 'type'=>'select', 'label'=>'序列化', 'default'=>'php', 'options' => ['php'=>'PHP Default', 'igbinary'=>'Igbinary']],
            'redis_compression' => ['group'=>'speed', 'type'=>'select', 'label'=>'压缩', 'default'=>'none', 'options' => ['none'=>'None', 'zstd'=>'Zstd', 'lzf'=>'LZF']],
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
        if ($this->options === null) {
            $this->options = get_option('skyline_ai_settings', []);
        }
        return isset($this->options[$key]) ? $this->options[$key] : ($this->get_config_schema()[$key]['default'] ?? $default);
    }

    public function log($msg, $type = 'info', $context = 'System') {
        $timestamp = current_time('Y-m-d H:i:s');
        $log_entry = sprintf("[%s] [%s] [%s] %s\n", $timestamp, strtoupper($type), $context, $msg);
        @file_put_contents($this->log_file, $log_entry, FILE_APPEND);
    }

    public function stat_inc($key, $val=1) {
        $stats = get_option('skyline_ai_stats', []);
        $current = isset($stats[$key]) ? floatval($stats[$key]) : 0;
        $stats[$key] = $current + floatval($val);
        update_option('skyline_ai_stats', $stats);
        $this->stat_cache[$key] = $stats[$key];
    }
    
    public function stat_get($key) {
        if (isset($this->stat_cache[$key])) return $this->stat_cache[$key];
        $stats = get_option('skyline_ai_stats', []);
        $val = isset($stats[$key]) ? $stats[$key] : 0;
        $this->stat_cache[$key] = $val;
        return $val;
    }

    public function call_api($messages, $temp = 0.7, $retry_count = 0) {
        $k = $this->get_opt('api_key'); 
        if(!$k) return 'Error: API Key missing';

        $this->stat_inc('api_calls');
        
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => $this->get_opt('chat_model'), 
                'messages' => $messages, 
                'temperature' => $temp
            ]),
            'timeout' => 120, 
            'sslverify' => true
        ];

        $r = wp_remote_post($this->get_opt('api_base'), $args);
        
        if(is_wp_error($r)) {
            $err = $r->get_error_message();
            if ($retry_count < 2) {
                sleep(1 * ($retry_count + 1));
                return $this->call_api($messages, $temp, $retry_count + 1);
            }
            return "Network Error: " . $err;
        }

        $code = wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);
        $d = json_decode($body, true);

        if ($code !== 200) {
            $errMsg = $d['error']['message'] ?? 'Unknown API Error';
            if ($code >= 500 && $retry_count < 2) {
                sleep(1 * ($retry_count + 1));
                return $this->call_api($messages, $temp, $retry_count + 1);
            }
            return "API Error ({$code}): " . $errMsg;
        }

        return $d['choices'][0]['message']['content'] ?? 'API Error: Empty Response';
    }

    public function handle_api_test() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $res = $this->call_api([['role'=>'user', 'content'=>'Ping']]);
        if (strpos($res, 'Error:') === 0) {
            wp_send_json_error($res);
        }
        wp_send_json_success(['reply'=>$res]);
    }

    public function handle_test_redis() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        if (!$this->get_opt('redis_enable')) wp_send_json_error('Redis is disabled in settings');
        
        try {
            $redis = new Redis();
            $connected = $redis->connect($this->get_opt('redis_host'), (int)$this->get_opt('redis_port'), 2);
            if (!$connected) throw new Exception('Could not connect to Redis server');
            
            if ($this->get_opt('redis_auth')) {
                if (!$redis->auth($this->get_opt('redis_auth'))) throw new Exception('Redis authentication failed');
            }
            
            $redis->ping();
            wp_send_json_success('Redis connection verified');
        } catch(Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_test_oss() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        
        if (!$this->get_opt('oss_enable')) wp_send_json_error('OSS is disabled in settings');
        
        // Basic check: can we reach the endpoint?
        $endpoint = $this->get_opt('oss_endpoint');
        $response = wp_remote_get($endpoint, ['timeout' => 5]);
        if (is_wp_error($response)) {
            wp_send_json_error('Cannot reach OSS endpoint: ' . $response->get_error_message());
        }
        wp_send_json_success('OSS endpoint reachable');
    }

    public function handle_chat_front() {
        check_ajax_referer('sky_chat_nonce');
        $msg = sanitize_text_field($_POST['msg'] ?? '');
        wp_send_json_success($this->call_api([['role'=>'user', 'content'=>$msg]]));
    }

    public function handle_ai_task() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');
        $t = sanitize_key($_POST['task'] ?? '');
        $i = wp_kses_post($_POST['input'] ?? '');
        
        // --- 工业级 Prompt 矩阵：为每个任务定义极其严格的指令 ---
        $prompts = [
            'title'    => "你是一位资深的SEO内容专家。请根据以下文章内容，生成5个具有极高点击率、符合SEO原则且能精准概括核心价值的中文标题。要求：\n1. 提供5个不同风格（如：痛点驱动型、权威指南型、反直觉悬念型、结果导向型、极简概括型）。\n2. 严禁输出任何开场白或结束语（如 '为您生成的标题是' 或 '希望您满意'）。\n3. 每行输出一个标题，不要编号，不要引号。\n\n文章内容：\n$i",
            'outline'   => "请分析以下文章的逻辑结构，并生成一个专业的 Markdown 形式的大纲。要求：层级清晰（# ## ###），涵盖所有核心观点和论据，确保逻辑严密。\n\n文章内容：\n$i",
            'continue'  => "请阅读以下段落，并根据当前的语境、语气和逻辑，自然地续写接下来的内容。要求：无缝衔接，保持风格一致，直接输出续写部分，不要输出 '续写如下' 等提示词。\n\n内容：\n$i",
            'expand'    => "请对以下内容进行深度扩写。要求：在不改变原意的前提下，增加细节描述、专业论证或具体案例，使内容更丰满、更有说服力，提升专业感。直接输出扩写后的全文。\n\n内容：\n$i",
            'rewrite'   => "你是一位顶级的伪原创专家。请在保持原意绝对不变的前提下，彻底重写以下内容。要求：打破原有的句式结构，使用同义词替换，重新组织行文逻辑，确保在通过 AI 检测的同时，可读性极高。直接输出重写后的内容。\n\n内容：\n$i",
            'polish'    => "请对以下内容进行智能润色。要求：修正所有错别字和病句，提升词汇的专业度，使行文风格符合『现代专业技术文档』的审美（客观、精炼、流畅）。直接输出润色后的内容。\n\n内容：\n$i",
            'shorten'   => "请在保留所有核心结论和关键事实的前提下，将以下内容进行极简缩写。要求：删掉所有冗余修饰词，使表达像电报一样高效有力。直接输出缩写结果。\n\n内容：\n$i",
            'trans'     => "请将以下内容进行高质量的中英互译（中文 $\leftrightarrow$ 英文）。要求：翻译自然，符合目标语言的母语表达习惯，准确保留专业术语。直接输出翻译结果。\n\n内容：\n$i",
            'desc'      => "请为以下文章生成一段 120 字左右的 SEO 元描述（Meta Description）。要求：包含核心关键词，采用『痛点+解决方案』的结构，具有强烈的诱导点击效果。直接输出摘要内容。\n\n内容：\n$i",
            'tags'      => "请基于以下内容提取 5-8 个核心 SEO 标签。要求：包含 1-2 个行业大类词和 3-6 个具体核心词，用逗号分隔，严禁输出编号、前缀或任何解释文字。\n\n内容：\n$i",
            'slug_en'   => "Generate a concise, SEO-friendly English URL slug for this content. Requirements: strictly lowercase, use hyphens instead of spaces, max 6 words, NO leading/trailing hyphens. STRICTLY output ONLY the slug text, no explanation.\n\nContent:\n$i",
        ];

        $prompt = isset($prompts[$t]) ? $prompts[$t] : "AI Task: $t. Input: $i";
        
        $res = $this->call_api([['role'=>'user', 'content'=>$prompt]]);
        
        // 后处理：进一步确保没有 AI 的废话前缀
        if (is_string($res)) {
            $res = preg_replace('/^(为您生成的.*?是|这里是.*?：|以下是.*?：|Sure!|Okay!|Certainly!)\s*/iu', '', $res);
            $res = trim($res);
        }

        wp_send_json_success($res);
    }

    public function handle_save_prompt() {
        check_ajax_referer('sky_prompt_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $template = wp_kses_post($_POST['template'] ?? '');
        if(!$name || !$template) wp_send_json_error('Missing data');
        
        $lib = get_option('skyline_prompt_library', []);
        $id = uniqid();
        $lib[$id] = ['name' => $name, 'template' => $template];
        update_option('skyline_prompt_library', $lib);
        wp_send_json_success('Saved');
    }

    public function handle_get_prompts() {
        check_ajax_referer('sky_prompt_nonce');
        wp_send_json_success(get_option('skyline_prompt_library', []));
    }

    public function handle_check_quality() {
        check_ajax_referer('sky_quality_nonce');
        $content = wp_kses_post($_POST['content'] ?? '');
        wp_send_json_success(Skyline_Utils::assess_quality($content));
    }

    public function handle_gen_img() {
        check_ajax_referer('sky_ai_task_nonce');
        $prompt = sanitize_text_field($_POST['prompt'] ?? '');
        $k = $this->get_opt('api_key');
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            'body' => json_encode(['model' => $this->get_opt('image_model'), 'prompt' => $prompt]),
            'timeout' => 60
        ];
        $r = wp_remote_post('https://api.siliconflow.cn/v1/images/generations', $args);
        $d = json_decode(wp_remote_retrieve_body($r), true);
        wp_send_json_success($d['data'][0]['url'] ?? 'Fail');
    }

    public function handle_clear_logs() {
        check_ajax_referer('sky_clear_logs_nonce');
        if (file_exists($this->log_file)) {
            file_put_contents($this->log_file, '');
            wp_send_json_success('Logs cleared from file');
        } else {
            wp_send_json_error('Log file not found');
        }
    }

    public function enqueue_waifu_assets() {
        wp_enqueue_style('sky-waifu', SKY_URL . 'assets/css/waifu.css');
        wp_enqueue_script('sky-waifu', SKY_URL . 'assets/js/waifu.js', ['jquery'], SKY_VERSION, true);
        wp_localize_script('sky-waifu', 'sky_ajax', ['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('sky_chat_nonce')]);
    }

    public function render_waifu() {
        if(!$this->get_opt('robot_enable')) return;
        echo '<div id="sky-waifu-bot">...</div>';
    }
}
