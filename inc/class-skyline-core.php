<?php
if (!defined('ABSPATH')) exit;

class Skyline_Core {
    private static $instance = null;
    private $options = [];
    private $stat_cache = [];
    private $log_file;

    // M13：前台聊天限流阈值（默认值，暂无后台设置项，改这里即可）。
    const CHAT_RATE_PER_MINUTE = 20;
    const CHAT_RATE_PER_DAY = 500;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
            self::$instance->setup();
        }
        return self::$instance;
    }

    public function __construct() {
        // 使用 SKY_PATH 常量定义日志路径，更稳定
        $this->log_file = SKY_PATH . 'logs/skyline_ai.log';

        $log_dir = dirname($this->log_file);
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        // 日志目录防护（nginx 不解析 .htaccess，单靠它等于裸奔）：
        // 1) .htaccess 继续为 Apache 兜底；2) index.php / index.html 防目录浏览；
        // 3) 真正起作用的是日志文件名里的本站随机后缀（见 log_token()），不知道
        // 后缀就猜不到日志 URL。后台日志查看是服务端直接读文件、不拼 URL，所以
        // 改名不影响看板；旧版无后缀的日志仍按 7 天轮转清理。
        if (!file_exists($log_dir . '/.htaccess')) {
            @file_put_contents($log_dir . '/.htaccess', 'Deny from all');
        }
        if (!file_exists($log_dir . '/index.php')) {
            @file_put_contents($log_dir . '/index.php', "<?php\n// Silence is golden.\n");
        }
        if (!file_exists($log_dir . '/index.html')) {
            @file_put_contents($log_dir . '/index.html', '');
        }
        // L33：已装站点的 skyline_ai_settings 行仍是 autoload=yes——
        // admin.php 的 update_option(..., false) 只对新建行生效，已有行
        // 的 autoload 不会被改写。这里在 core 初始化时做一次版本化
        // 一次性迁移（见 maybe_migrate_settings_autoload），已迁移标记
        // 防重复；插件无现成的版本/迁移机制，故用独立标记，不另起版本表。
        $this->maybe_migrate_settings_autoload();
    }

    // L33：把已装站点现有的 'skyline_ai_settings' option 行的 autoload
    // 改成 no（密钥不再随每个请求读进内存）。只改 autoload 列，不动
    // option 值；可重复跑（幂等）；失败不致命（返回 false，下次请求再试）。
    // 实现：直接 $wpdb->update() 只写 autoload 列——update_option() 同值
    // 重写在部分 WP 版本下会因“值未变”提前返回、改不到 autoload，且会
    // 重写整个值；$wpdb 路径则与值无关。无行可迁（从未保存过配置）时也
    // 记标记：后续新建行由 admin.php 的 update_option(..., false) 直接
    // 写成 no。无 $wpdb 的极端环境退回 update_option() 同值 + false
    // 尽力而为，不记标记、不抛错。
    public function maybe_migrate_settings_autoload() {
        if (!function_exists('get_option') || !function_exists('update_option')) return false;
        if (get_option('skyline_ai_settings_autoload_migrated', false)) return false;
        try {
            $existing = get_option('skyline_ai_settings', null);
            if ($existing === null) {
                update_option('skyline_ai_settings_autoload_migrated', 1, false);
                return false;
            }
            global $wpdb;
            if (isset($wpdb) && is_object($wpdb) && method_exists($wpdb, 'update') && isset($wpdb->options)) {
                $wpdb->update($wpdb->options, ['autoload' => 'no'], ['option_name' => 'skyline_ai_settings']);
                update_option('skyline_ai_settings_autoload_migrated', 1, false);
                return true;
            }
            update_option('skyline_ai_settings', $existing, false);
        } catch (Throwable $e) {
            return false;
        }
        return false;
    }

    // 本站日志文件名随机后缀（16 位 hex，存于 skyline_ai_log_token）。
    private function log_token() {
        $token = get_option('skyline_ai_log_token', '');
        if (!is_string($token) || !preg_match('/^[0-9a-f]{16}$/', $token)) {
            $token = function_exists('random_bytes')
                ? bin2hex(random_bytes(8))
                : substr(md5(uniqid('sky_', true)), 0, 16);
            update_option('skyline_ai_log_token', $token, false);
        }
        return $token;
    }

    private function today_log_file() {
        return dirname($this->log_file) . '/skyline_ai-' . current_time('Ymd') . '-' . $this->log_token() . '.log';
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
        add_action('wp_ajax_sky_delete_prompt', [$this, 'handle_delete_prompt']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_waifu_assets']);
        add_action('wp_footer', [$this, 'render_waifu']);
    }

    private function load_modules() {
        if (class_exists('Skyline_Content')) new Skyline_Content();
        if (class_exists('Skyline_Infra')) {
            try { Skyline_Infra::instance(); } catch(Throwable $e) { error_log('Skyline Infra Load Error: '.$e->getMessage()); }
        }
        if (is_admin() && class_exists('Skyline_Admin')) new Skyline_Admin();
    }

    // v2.2.0：模型厂商预设表。对话端点 = 各厂商官方 base + /chat/completions，
    // 2026-11-09 逐家用 web 搜索核对过官方文档/文档引用：
    // 硅基流动 https://api.siliconflow.cn/v1、OpenRouter https://openrouter.ai/api/v1、
    // DeepSeek https://api.deepseek.com/v1、OpenAI https://api.openai.com/v1、
    // 智谱 https://open.bigmodel.cn/api/paas/v4、Moonshot https://api.moonshot.cn/v1、
    // 通义千问 https://dashscope.aliyuncs.com/compatible-mode/v1。
    // 生图根地址 = 同厂商 base（handle_gen_img() 拼 /images/generations）：
    // OpenRouter 生图走 POST /api/v1/images、DashScope 兼容模式只支持对话与
    // embedding（生图走原生异步接口），两者都不走 /images/generations，故留空；
    // DeepSeek / Moonshot 无生图模型，同样留空。
    // 模型清单只列各家稳定常见的（抽查过），模型字段允许手打新模型，不求全。
    public static function get_api_vendors() {
        return [
            'siliconflow' => [
                'label' => '硅基流动',
                'chat_endpoint' => 'https://api.siliconflow.cn/v1/chat/completions',
                'img_base' => 'https://api.siliconflow.cn/v1',
                'chat_models' => ['deepseek-ai/DeepSeek-V3', 'deepseek-ai/DeepSeek-V3.2', 'deepseek-ai/DeepSeek-R1', 'Qwen/Qwen3-8B', 'zai-org/GLM-5.3-Flash', 'moonshotai/Kimi-K2-Instruct'],
                'image_models' => ['black-forest-labs/FLUX.1-schnell', 'black-forest-labs/FLUX.1-dev'],
            ],
            'openrouter' => [
                'label' => 'OpenRouter',
                'chat_endpoint' => 'https://openrouter.ai/api/v1/chat/completions',
                'img_base' => '',
                'chat_models' => ['openai/gpt-5.2', 'openai/gpt-4o', 'openai/gpt-4o-mini', 'anthropic/claude-sonnet-4.6', 'google/gemini-2.5-pro', 'deepseek/deepseek-chat-v3.1'],
                'image_models' => [],
            ],
            'deepseek' => [
                'label' => 'DeepSeek',
                'chat_endpoint' => 'https://api.deepseek.com/v1/chat/completions',
                'img_base' => '',
                // 旧 id deepseek-chat / deepseek-reasoner 已于 2026-07-24 退役，
                // 不再列入；现役为 V4 系列（另有 deepseek-flash = V4.1-Flash 别名）。
                'chat_models' => ['deepseek-v4-flash', 'deepseek-v4-pro', 'deepseek-flash', 'deepseek-v3.2'],
                'image_models' => [],
            ],
            'openai' => [
                'label' => 'OpenAI',
                'chat_endpoint' => 'https://api.openai.com/v1/chat/completions',
                'img_base' => 'https://api.openai.com/v1',
                'chat_models' => ['gpt-5.2', 'gpt-5.2-chat', 'gpt-4o', 'gpt-4o-mini', 'gpt-4.1'],
                'image_models' => ['gpt-image-1', 'gpt-image-1-mini', 'dall-e-3'],
            ],
            'zhipu' => [
                'label' => '智谱AI',
                'chat_endpoint' => 'https://open.bigmodel.cn/api/paas/v4/chat/completions',
                'img_base' => 'https://open.bigmodel.cn/api/paas/v4',
                'chat_models' => ['glm-5.3', 'glm-5.3-flash', 'glm-5.2', 'glm-5.1', 'glm-5', 'glm-4.7'],
                'image_models' => ['cogview-4', 'glm-image'],
            ],
            'moonshot' => [
                'label' => 'Moonshot',
                'chat_endpoint' => 'https://api.moonshot.cn/v1/chat/completions',
                'img_base' => '',
                'chat_models' => ['kimi-k3', 'kimi-k3.1-pro', 'kimi-k2.6', 'moonshot-v1-128k', 'moonshot-v1-auto'],
                'image_models' => [],
            ],
            'qwen' => [
                'label' => '通义千问',
                'chat_endpoint' => 'https://dashscope.aliyuncs.com/compatible-mode/v1/chat/completions',
                'img_base' => '',
                'chat_models' => ['qwen3.7-max', 'qwen3.7-plus', 'qwen3-plus', 'qwen3-turbo', 'qwen-plus', 'qwen-turbo'],
                'image_models' => [],
            ],
            'custom' => [
                'label' => '自定义',
                'chat_endpoint' => '',
                'img_base' => '',
                'chat_models' => [],
                'image_models' => [],
            ],
        ];
    }

    // v2.2.0：按已存 api_base 的 host 反推厂商（向后兼容旧配置：旧配置
    // 没有 api_vendor 键）。host 对得上预设就返回该厂商，对不上、为空或
    // 解析不出时返回 'custom'，调用方保留原值、不丢配置。只比 host，不比
    // 完整路径（用户手改过路径/加过斜杠也不影响识别）。
    public static function detect_api_vendor($api_base) {
        $raw = strtolower(trim((string)$api_base));
        if ($raw === '') return 'custom';
        $host = parse_url($raw, PHP_URL_HOST);
        // 无 scheme 的裸地址 parse 不出 host，退回整串做子串匹配。
        if (!is_string($host) || $host === '') $host = $raw;
        $map = [
            'api.siliconflow.cn' => 'siliconflow',
            'openrouter.ai' => 'openrouter',
            'eu.openrouter.ai' => 'openrouter',
            'api.deepseek.com' => 'deepseek',
            'api.openai.com' => 'openai',
            'open.bigmodel.cn' => 'zhipu',
            'api.z.ai' => 'zhipu',
            'api.moonshot.cn' => 'moonshot',
            'api.moonshot.ai' => 'moonshot',
            'dashscope.aliyuncs.com' => 'qwen',
            'dashscope-intl.aliyuncs.com' => 'qwen',
        ];
        foreach ($map as $needle => $vendor) {
            if (strpos($host, $needle) !== false) return $vendor;
        }
        return 'custom';
    }

    public function get_config_schema() {
        // v2.2.0：厂商下拉选项与厂商表同源（label 取自 get_api_vendors()），
        // 顺序与厂商表一致：7 家预设在前，“自定义”最后一位。
        $vendor_options = [];
        foreach (self::get_api_vendors() as $k => $v) $vendor_options[$k] = $v['label'];
        return [
            'api_vendor' => ['group'=>'ai', 'type'=>'select', 'label'=>'模型厂商', 'desc'=>'切换厂商后，下方 API Endpoint、生图根地址与两个模型下拉即时更新（要点“保存本页配置”才生效）；选“自定义”可自由填写任意地址与模型。', 'default'=>'siliconflow', 'options'=>$vendor_options],
            'api_base' => ['group'=>'ai', 'type'=>'url', 'label'=>'API Endpoint', 'default'=>'https://api.siliconflow.cn/v1/chat/completions'],
            // 生图端点根地址（L8）：handle_gen_img() 优先用这里拼 /images/generations；
            // 留空时回退到从 api_base 推导（/chat/completions → /images/generations）并记日志。
            'api_img_base' => ['group'=>'ai', 'type'=>'url', 'label'=>'生图 API 根地址', 'desc'=>'例如 https://api.siliconflow.cn/v1；默认与上方 API Endpoint 同厂商（硅基流动）。留空时从 API Endpoint 推导（仅当它以 /chat/completions 结尾时）；推导不出请填写。', 'default'=>'https://api.siliconflow.cn/v1'],
            'api_key' => ['group'=>'ai', 'type'=>'password', 'label'=>'API Key', 'desc'=>'你的 API 密钥 (sk-...)', 'required'=>true],
            'chat_model' => ['group'=>'ai', 'type'=>'datalist', 'label'=>'对话模型', 'desc'=>'可点选预设，也可直接输入厂商新出的模型名；切换厂商后下拉随之更新。', 'default'=>'deepseek-ai/DeepSeek-V3'],
            'image_model' => ['group'=>'ai', 'type'=>'datalist', 'label'=>'绘图模型', 'desc'=>'可点选预设，也可直接输入新模型名；所选厂商无绘图模型时请留空或自由输入。', 'default'=>'black-forest-labs/FLUX.1-schnell'],
            'system_prompt' => ['group'=>'ai', 'type'=>'textarea', 'label'=>'系统人设', 'default'=>"你是灵感屋（lgwu.net）的 AI 助手，专注景观设计：园林方案、施工图与节点详图、植物配置、铺装与工程做法、设计软件与学习资源。用准确的景观专业术语，中文回答，简洁实用。"],
            'robot_enable' => ['group'=>'ai', 'type'=>'bool', 'label'=>'启用前台悬浮助手', 'default'=>true],
            'robot_only_logged' => ['group'=>'ai', 'type'=>'bool', 'label'=>'仅登录用户可见', 'default'=>false],
            'robot_img' => ['group'=>'ai', 'type'=>'url', 'label'=>'自定义图标 URL', 'desc'=>'请用方形图片（非方形图会被裁成圆形显示）。'],
            // 聊天限流用的可信代理清单（R6）：EdgeOne 回源时 REMOTE_ADDR 是边缘
            // 节点 IP，真实访客 IP 在 X-Forwarded-For 首位；但只有 REMOTE_ADDR
            // 落在下面配置的 EdgeOne 回源 IP 段内时才信任该头，否则任何人都能
            // 伪造 XFF 绕过限流。留空 = 永不信任 XFF（回退到 REMOTE_ADDR 的旧
            // 行为，安全但限流仍按边缘节点 IP 计）。EdgeOne 回源 IP 段见控制台
            // “回源配置 / 回源 IP 段”提供的列表，粘贴进来即可（每行一个 IP 或 CIDR）。
            'trusted_proxy_ips' => ['group'=>'ai', 'type'=>'textarea', 'label'=>'可信代理 IP（EdgeOne 回源段）', 'desc'=>'每行一个 IP 或 CIDR；仅当 REMOTE_ADDR 在此列表中时，才信任 X-Forwarded-For 首个 IP 作为访客真实 IP。留空则永不信任。警告：留空且站点接了 CDN/EdgeOne 时，全站访客共享一个限流桶，20 次/分钟后全站聊天会被限流，请填入回源 IP 段。'],
            
            'spider_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用内容同步', 'default'=>true],
            // 图片同步方式：用 spider_auto 这个已有键存值，不新增字段。
            // 存值语义与原来完全一致——true/'1'/1 = 自动，false/'0'/0 = 手动，
            // 线上数据库里现在存的是 b:0（手动）。类型从 bool 改成 spider_mode
            // 只是换控件（复选框→两个单选项），不是换含义：① 复选框太容易
            // 被忽略，用户明确要一个看得见的自动/手动开关；② bool 保存分支
            // 是 isset($posted[$key])，单选值 '0' 提交上来也会被存成 true，
            // 必须走 spider_mode 自己的保存分支才不会把“手动”存成“自动”。
            'spider_auto' => ['group'=>'spider', 'type'=>'spider_mode', 'label'=>'图片同步方式', 'desc'=>'自动：发布文章时自动把外链图片同步到本地/COS；手动：发布时不同步，只在编辑器里点“一键同步图片”按钮时同步。', 'default'=>true],
            'spider_max_img' => ['group'=>'spider', 'type'=>'number', 'label'=>'单篇最大同步数', 'default'=>20],
            'spider_allow_wechat' => ['group'=>'spider', 'type'=>'bool', 'label'=>'允许微信图片', 'default'=>true],
            'spider_ssl_verify' => ['group'=>'spider', 'type'=>'bool', 'label'=>'验证 SSL 证书', 'default'=>true],
            'spider_domains' => ['group'=>'spider', 'type'=>'textarea', 'label'=>'排除域名 (一行一个)'],
            'spider_wm_enable' => ['group'=>'spider', 'type'=>'bool', 'label'=>'启用添加水印', 'default'=>false],
            'spider_rm_wm' => ['group'=>'spider', 'type'=>'bool', 'label'=>'智能去除水印 (裁剪底部)', 'desc'=>'无条件裁掉图片底部 40px（矮于 150px 的图跳过），不是智能检测水印位置；没有水印的图片也会被裁掉底部内容。', 'default'=>false],
            'spider_wm_text' => ['group'=>'spider', 'type'=>'text', 'label'=>'文字水印内容', 'default'=>'@Skyline'],
            
            'oss_enable' => ['group'=>'oss', 'type'=>'bool', 'label'=>'启用 OSS 云存储', 'default'=>false],
            'oss_endpoint' => ['group'=>'oss', 'type'=>'text', 'label'=>'Endpoint'],
            'oss_bucket' => ['group'=>'oss', 'type'=>'text', 'label'=>'Bucket 名称'],
            // v2.2.0 收口：Access Key 与 Secret Key 同为凭据（备份排除清单
            // inc/modules/class-skyline-utils.php 里 oss_ak 已列为明文凭据），
            // 统一走 password 渲染：不回显原文、留空不改（render_field 的
            // password 分支 + handle_settings 的 password 分支）；已配置与
            // 否只看“已配置/未配置”角标，不把值写进页面。
            'oss_ak' => ['group'=>'oss', 'type'=>'password', 'label'=>'Access Key'],
            'oss_sk' => ['group'=>'oss', 'type'=>'password', 'label'=>'Secret Key'],
            'oss_domain' => ['group'=>'oss', 'type'=>'text', 'label'=>'自定义域名'],
            'oss_delete_local' => ['group'=>'oss', 'type'=>'bool', 'label'=>'Zero-Disk (同步后删除本地文件)', 'desc'=>'开启后，本地文件在同步成功 24 小时后、由 WP-Cron 二次校验通过才删除；关掉开关后，已登记的待删任务作废，本地文件保留。', 'default'=>false],
            
            'auto_tags' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成标签', 'desc'=>'开启后每次自动生成单独调用一次 API（计一次 api_calls）。', 'default'=>true],
            'auto_slug' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成英文 Slug', 'desc'=>'开启后每次自动生成单独调用一次 API（计一次 api_calls）。', 'default'=>true],
            'auto_excerpt' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动生成摘要', 'desc'=>'开启后每次自动生成单独调用一次 API（计一次 api_calls）。', 'default'=>true],
            'auto_polish' => ['group'=>'seo', 'type'=>'bool', 'label'=>'发布前 AI 智能润色', 'desc'=>'含 Gutenberg 区块标记的文章会跳过润色；长文分段润色后拼接，段落边界处可能有轻微不连贯。', 'default'=>false],
            'link_enable' => ['group'=>'seo', 'type'=>'bool', 'label'=>'自动内链', 'default'=>false],
            'link_pairs' => ['group'=>'seo', 'type'=>'textarea', 'label'=>'内链关键词 (词|链接)'],
            
            'redis_enable' => ['group'=>'speed', 'type'=>'bool', 'label'=>'启用 Redis 缓存', 'default'=>false],
            'redis_smart_purge' => ['group'=>'speed', 'type'=>'bool', 'label'=>'智能脏数据清理', 'default'=>true],
            'redis_host' => ['group'=>'speed', 'type'=>'text', 'label'=>'Redis Host', 'default'=>'127.0.0.1'],
            'redis_port' => ['group'=>'speed', 'type'=>'number', 'label'=>'Redis Port', 'default'=>6379],
            'redis_auth' => ['group'=>'speed', 'type'=>'password', 'label'=>'Redis Password'],
            'redis_db' => ['group'=>'speed', 'type'=>'number', 'label'=>'Database', 'default'=>0],
            'redis_ttl' => ['group'=>'speed', 'type'=>'number', 'label'=>'TTL (秒)', 'default'=>3600],
            // 注意：不要把 preview / s= / amp 写进默认值——它们是查询参数，
            // 精确排除在 page_cache() 里做；写成子串会误伤 /camp/、?status=、
            // ?tags= 这类正常 URL（P1）。路径级排除只保留下面这几行。
            'redis_exclude' => ['group'=>'speed', 'type'=>'textarea', 'label'=>'排除缓存路径', 'desc'=>'内置排除（/wp-json/、/cart/、/checkout/、rest_route、/feed/）已在代码里硬编码，此处只追加额外路径；填了也不影响内置排除。', 'default'=>"/wp-json/\n/cart/\n/checkout/\nrest_route\n/feed/"],
            'redis_serializer' => ['group'=>'speed', 'type'=>'select', 'label'=>'序列化', 'default'=>'php', 'options' => ['php'=>'PHP Default', 'igbinary'=>'Igbinary']],
            'redis_compression' => ['group'=>'speed', 'type'=>'select', 'label'=>'压缩', 'default'=>'none', 'options' => ['none'=>'None', 'zstd'=>'Zstd', 'lzf'=>'LZF']],
            'turbo_disable_emoji' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 Emoji', 'default'=>false],
            'turbo_disable_embeds' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 oEmbeds', 'default'=>false],
            'turbo_disable_xmlrpc' => ['group'=>'speed', 'type'=>'bool', 'label'=>'禁用 XML-RPC', 'default'=>false],
            'turbo_sanitize_names' => ['group'=>'speed', 'type'=>'bool', 'label'=>'文件名哈希化', 'default'=>false],
            'turbo_lazy_load' => ['group'=>'speed', 'type'=>'bool', 'label'=>'强制图片懒加载', 'desc'=>'开启后首屏大图也会被加 loading="lazy"，可能拖慢 LCP；默认关闭，需要时手动开启。', 'default'=>false],
            'turbo_enable_image_opt' => ['group'=>'speed', 'type'=>'bool', 'label'=>'上传图片压缩', 'desc'=>'>10MB 的文件整个跳过不压缩；原图与缩略图分别压缩。', 'default'=>false],
            // WebP 转换开关组：默认关闭（opt-in）。理由：① 转格式会改变新上传
            // 图片的文件扩展名与前台 URL，升级后静默开启等于改全站媒体管线
            // （P12 的教训：turbo_lazy_load 默认 true 曾在升级后强制生效）；
            // ② 服务器 GD 是否支持 WebP 未知——不支持时开关开了也没用，管理
            // 员应在性能体检页确认 GD 支持状态后再开；③ 开关只影响“之后”
            // 的上传，旧图用批量工具另行处理，不追溯。
            'turbo_webp_convert' => ['group'=>'speed', 'type'=>'bool', 'label'=>'上传图片转 WebP', 'desc'=>'JPG/PNG 上传时自动转成 WebP（在压缩流程里完成，只编一次码、不重复有损）。需服务器 GD 支持 WebP，不支持时自动跳过、不影响上传。', 'default'=>false],
            'turbo_webp_quality' => ['group'=>'speed', 'type'=>'number', 'label'=>'WebP 质量（0–100）', 'desc'=>'转 WebP 时的质量，默认 80；越高越清晰、文件越大。', 'default'=>80],
            // 原图保留默认 true：转完的 WebP 才是附件指向的文件，原 JPG/PNG
            // 只作本地备份留着；关掉它时，原图只有在 WebP 校验通过、且（如开
            // COS）同步成功后才删除——绝不在只有一份本地副本、远程还没确认
            // 时删东西（Zero-Disk 的教训）。
            'turbo_webp_keep_original' => ['group'=>'speed', 'type'=>'bool', 'label'=>'转 WebP 后保留原图', 'desc'=>'开启时原 JPG/PNG 保留在服务器上（仅作备份，不再被引用）。关闭时原图在 WebP 校验通过且同步成功后才删除。', 'default'=>true],
            'turbo_quality' => ['group'=>'speed', 'type'=>'number', 'label'=>'压缩质量', 'desc'=>'💡 取值 0–100，越高画质越好、文件越大。', 'default'=>85],
            // R14：原图改尺寸独立开关。0 = 不改尺寸（默认，只压质量）；
            // 设为正数（如 2560）时，宽于该值的原图才会被缩到该宽度。
            // 之前无条件缩到 2560px 是静默不可逆行为，与 Zero-Disk 叠加会
            // 永久丢失高清原图，故默认关闭、改尺寸前记日志。
            'turbo_max_width' => ['group'=>'speed', 'type'=>'number', 'label'=>'原图最大宽度（px，0 表示不改尺寸）', 'desc'=>'仅在“上传图片压缩”开启时生效；0 表示只压质量、不改尺寸。', 'default'=>0],
            'turbo_limit_5m' => ['group'=>'speed', 'type'=>'bool', 'label'=>'限制上传 5MB', 'default'=>false],
            'turbo_allow_svg' => ['group'=>'speed', 'type'=>'bool', 'label'=>'允许 SVG/WebP', 'default'=>false],
        ];
    }

    public function get_opt($key, $default = null) {
        if (isset($this->options[$key])) return $this->options[$key];

        $cache_key = 'sky_opt_' . md5($key);
        
        $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
        if ($infra && method_exists($infra, 'cache_get')) {
            // 配置读不计入缓存命中统计（track=false）：每请求数十次配置读
            // 若都算命中，cache_hits 会被撑高、失去健康信号意义（P3）。
            // 页面缓存命中、call_api() 缓存命中仍正常计数，不受影响。
            $cached = $infra->cache_get($cache_key, null, 3600, false);
            if ($cached !== null) { $this->options[$key] = $cached; return $cached; }
        }
        
        $transient = get_transient($cache_key);
        if ($transient !== false) { $this->options[$key] = $transient; return $transient; }
        
        $db_opts = get_option('skyline_ai_settings', []);
        $val = $db_opts[$key] ?? ($this->get_config_schema()[$key]['default'] ?? $default);

        // L33：注意——api_key / oss_sk / redis_auth 等密钥经 get_opt()
        // 会以明文进 Redis 缓存（下面 cache_set 的 sky_opt_*，TTL 3600 秒）
        // 与 transient：这是已知现状，不改行为；调用方与部署方须知晓——
        // Redis 须按内网服务对待、不要对外暴露，密钥轮换后须清掉 sky_opt_*
        // 缓存（后台保存配置时已清），否则旧密钥最长残留 1 小时。
        set_transient($cache_key, $val, 3600);
        if ($infra) $infra->cache_set($cache_key, $val, 3600);
        $this->options[$key] = $val;
        
        return $val;
    }

    // M20：清空内存里的配置缓存。handle_settings() 保存成功后调用，
    // 否则同一请求内渲染设置页时 get_opt() 仍返回保存前的值。
    // 只清内存缓存；Redis/transient 里的 sky_opt_* 由保存方另行清理。
    public function clear_opt_cache() {
        $this->options = [];
    }

    // L16：单日日志轮转标记——清理每天只做一次（静态标记 + transient 双保险，
    // 同一请求内多次 log() 不重复扫目录；跨请求靠 transient 记当天日期）。
    private static $log_cleanup_day = null;

    public function log($msg, $type = 'info', $context = 'System') {
        $timestamp = current_time('Y-m-d H:i:s');
        // 日志轮转：按天切分（skyline_ai-YYYYMMDD-<随机后缀>.log），只保留最近 7 天，防无限膨胀
        $dir = dirname($this->log_file);
        $today = $this->today_log_file();
        if (!file_exists($today) && file_exists($this->log_file)) {
            @rename($this->log_file, $dir . '/skyline_ai-' . gmdate('Ymd', filemtime($this->log_file)) . '-' . $this->log_token() . '.log');
        }
        // 单日日志超 10MB 时轮转：把今天的日志改名保留一份（-rotated.log，
        // 仍命中 7 天清理的 glob），新写入从空文件开始，避免单文件无限膨胀。
        // 注意：同一请求内 log() 多次调用时 PHP 的 stat 缓存会让 filesize()
        // 读到旧值，判断前先清掉该文件的缓存，否则阈值可能被漏过。
        clearstatcache(true, $today);
        if (file_exists($today) && filesize($today) > 10 * 1024 * 1024) {
            @rename($today, $dir . '/skyline_ai-' . current_time('Ymd') . '-' . $this->log_token() . '-rotated.log');
        }
        @file_put_contents($today, sprintf("[%s] [%s] [%s] %s\n", $timestamp, strtoupper($type), $context, $msg), FILE_APPEND | LOCK_EX);

        // 清理 7 天前的轮转日志，每天只做一次（不是每次写日志都 glob 扫目录）
        $today_key = current_time('Ymd');
        if (self::$log_cleanup_day !== $today_key) {
            self::$log_cleanup_day = $today_key;
            if (get_transient('sky_log_cleanup_day') !== $today_key) {
                set_transient('sky_log_cleanup_day', $today_key, 86400);
                $cutoff = time() - 7 * 86400;
                foreach (glob($dir . '/skyline_ai-*.log') ?: [] as $f) {
                    if (filemtime($f) < $cutoff) @unlink($f);
                }
            }
        }
    }

    public function stat_inc($key, $val=1) {
        // 统计改走 Redis INCR：旧版每次调用都 get_option + update_option
        // 写 wp_options，高并发下两个请求读到同一旧值、后写的覆盖先写的，
        // 统计会丢；而且每次缓存命中都写一次数据库太贵。
        // Redis 不可用时退回旧的 wp_options 写法（不丢功能）。
        $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
        if ($infra && method_exists($infra, 'stat_incr')) {
            $new = $infra->stat_incr($key, (float)$val);
            if ($new !== null) {
                $this->stat_cache[$key] = $new;
                return;
            }
        }
        $stats = get_option('skyline_ai_stats', []);
        if (!is_array($stats)) $stats = [];
        $current = isset($stats[$key]) ? floatval($stats[$key]) : 0;
        $stats[$key] = $current + floatval($val);
        update_option('skyline_ai_stats', $stats);
        $this->stat_cache[$key] = $stats[$key];
    }
    
    public function stat_get($key) {
        if (isset($this->stat_cache[$key])) return $this->stat_cache[$key];
        // 看板统计优先读 Redis 计数器（stat_incr 写入的位置），Redis 不可用
        // 或计数器不存在时退回 wp_options 的旧统计。注意：Redis 计数器是
        // 从旧 wp_options 值一次性播种的，升级后看板数字不会归零。
        $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
        if ($infra && method_exists($infra, 'stat_get_redis')) {
            $val = $infra->stat_get_redis($key);
            if ($val !== null) {
                $this->stat_cache[$key] = $val;
                return $val;
            }
        }
        $stats = get_option('skyline_ai_stats', []);
        if (!is_array($stats)) $stats = [];
        $val = isset($stats[$key]) ? $stats[$key] : 0;
        $this->stat_cache[$key] = $val;
        return $val;
    }

    // R2：错误不再用字符串协议。成功返回 string，失败返回 WP_Error
    // （调用方用 is_wp_error() 判定，不再用 strpos/stripos 猜字符串）。
    // 这样三类错误（Key 缺失 / 网络失败 / 非 200 / 空响应）都会被识别，
    // 而 AI 返回的合法内容即使含有 "Error" 一词也不会被误判为失败。
    // R7：去掉 sleep() 重试与 120 秒超时——单次请求、30 秒超时、失败即返，
    // 不再占着 PHP-FPM 工人重试；重试与否由调用方决定。stat_inc('api_calls')
    // 只在单次调用里计一次，不再因递归重试重复计数。
    // v2.1.1：接口错误体有多种形状——OpenAI 风格 error.message、硅基
    // 流动风格顶层 message、error 直接是字符串——逐个取，取不到才报
    // fallback。只取 message 字段，绝不原样倾倒响应体（体里可能含密钥）。
    // 2026-11-09 实测：09:28 与 12:32 的 401 在日志/弹窗里只显示
    // “Unknown API Error”，因为当时那次 401 的体不是 error.message 形状。
    private function extract_api_error_message($d, $fallback = 'Unknown API Error') {
        if (is_array($d)) {
            if (isset($d['error']['message']) && is_string($d['error']['message']) && $d['error']['message'] !== '') {
                return $d['error']['message'];
            }
            if (isset($d['error']) && is_string($d['error']) && $d['error'] !== '') {
                return $d['error'];
            }
            if (isset($d['message']) && is_string($d['message']) && $d['message'] !== '') {
                return $d['message'];
            }
        }
        return $fallback;
    }
    public function call_api($messages, $temp = 0.7, $with_system = true) {
        $k = $this->get_opt('api_key'); 
        if(!$k) return new WP_Error('sky_api_key_missing', 'Error: API Key missing');

        // M1：system_prompt 填了就作为 system 角色消息拼到最前面再发。
        // 注意：schema 默认值本身就是一句非空人设（见 system_prompt 的 default），
        // 所以默认情况下每次调用都会带上这句 system 消息；管理员清空该项后不拼。
        // N-D2：$with_system=false 时不拼——纯英文输出任务（如 slug_en）
        // 与人设里的“中文回答”冲突，拼了会让模型输出中文、再被 sanitize_title 清空。
        $sys = trim((string)$this->get_opt('system_prompt', ''));
        if ($with_system && $sys !== '') {
            $messages = array_merge([['role' => 'system', 'content' => $sys]], $messages);
        }

        // M4：缓存 key 拼入 api_base，换 API 端点后不再命中旧厂商的缓存响应。
        $hash_key = 'sky_api_' . md5(json_encode($messages) . $temp . $this->get_opt('chat_model') . $this->get_opt('api_base'));
        $infra = class_exists('Skyline_Infra') ? Skyline_Infra::instance() : null;
        if ($infra && method_exists($infra, 'cache_get')) {
            $cached = $infra->cache_get($hash_key);
            if ($cached) return $cached;
        }

        $this->stat_inc('api_calls');
        
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            'body' => json_encode([
                'model' => $this->get_opt('chat_model'), 
                'messages' => $messages, 
                'temperature' => $temp
            ]),
            'timeout' => 30, 
            'sslverify' => true
        ];

        $r = wp_remote_post($this->get_opt('api_base'), $args);
        
        if(is_wp_error($r)) {
            $err = $r->get_error_message();
            $this->log("API 网络失败: {$err}", 'error', 'API');
            // S3：api_errors 埋点（走 stat_inc → Infra::stat_incr 的 redis_try 路径，
            // 不用 redis_safe，见 P4 教训：统计失败绝不能把整站 Redis 置 null）。
            $this->stat_inc('api_errors');
            return new WP_Error('sky_api_network', 'Network Error: Request failed');
        }

        $code = wp_remote_retrieve_response_code($r);
        $body = wp_remote_retrieve_body($r);
        $d = json_decode($body, true);

        if ($code !== 200) {
            $errMsg = $this->extract_api_error_message($d);
            $this->log("API 返回 {$code}: {$errMsg}", 'error', 'API');
            $this->stat_inc('api_errors'); // S3
            return new WP_Error('sky_api_http', "API Error ({$code}): " . $errMsg);
        }

        // 响应体必须是含 choices 的对象才算成功；标量/异常体按空响应处理。
        $content = (is_array($d) && isset($d['choices'][0]['message']['content'])) ? $d['choices'][0]['message']['content'] : null;
        if ($content === null || $content === '') {
            $this->log('API 返回空响应', 'error', 'API');
            $this->stat_inc('api_errors'); // S3
            return new WP_Error('sky_api_empty', 'API Error: Empty Response');
        }

        if ($infra) {
            // API 缓存 TTL 统一走 redis_ttl，不再写死 3600 秒。
            $infra->cache_set($hash_key, $content, (int)$this->get_opt('redis_ttl', 3600));
        }
        return $content;
    }

    public function handle_api_test() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $res = $this->call_api([['role'=>'user', 'content'=>'Ping']]);
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
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
            // M2：之前只测连通，没测真正用的库。init_redis() 按 redis_db 做
            // select()，测试也必须 select 同一个库，否则配成非 0 库时测试
            // 通过、实际用的库却是错的。随后做一次 setex/get/del 读写验证，
            // 只 ping 通不等于能读写。
            $db = (int)$this->get_opt('redis_db', 0);
            if (!$redis->select($db)) throw new Exception('Redis select() 失败：数据库 ' . $db . ' 不可用');
            $tkey = 'sky:redis_test:' . bin2hex(function_exists('random_bytes') ? random_bytes(8) : substr(md5(uniqid('sky_', true)), 0, 16));
            $tval = (string)time() . '-' . $tkey;
            if (!$redis->setex($tkey, 60, $tval)) throw new Exception('Redis 写入测试失败（setex 返回 false）');
            if ($redis->get($tkey) !== $tval) throw new Exception('Redis 读写验证失败（get 到的值与写入不一致）');
            try { $redis->del($tkey); } catch (Throwable $ignored) { /* 测试 key 删不掉只留一条 60 秒过期的垃圾，不掩盖已通过的读写验证 */ }
            wp_send_json_success('Redis 连接验证通过（库 ' . $db . '，读写验证通过）');
        } catch(Throwable $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    public function handle_test_oss() {
        check_ajax_referer('sky_ai_test_nonce'); 
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!$this->get_opt('oss_enable')) wp_send_json_error('OSS is disabled in settings');
        // R12：只测 endpoint 连通性等于没测——COS 返回 403/404 的 XML 错误体
        // 也是正常 HTTP 响应，之前一律报成功。改成真正写一次 + 读一次 +
        // 删一次测试对象，bucket 不存在、AK/SK 错误、权限不足时都会报真实
        // 失败原因；测试对象用完即删，不留垃圾。
        if (!class_exists('Sky_Official_COS_Client')) {
            wp_send_json_error('COS 客户端类不可用');
        }
        try {
            $client = new Sky_Official_COS_Client(
                $this->get_opt('oss_ak'),
                $this->get_opt('oss_sk'),
                $this->get_opt('oss_bucket'),
                $this->get_opt('oss_endpoint')
            );
        } catch (Throwable $e) {
            wp_send_json_error('无法创建 COS 客户端：' . $e->getMessage());
        }
        $result = $client->test_bucket();
        if (!is_array($result) || empty($result['ok'])) {
            wp_send_json_error('OSS 测试失败：' . ($result['error'] ?? '未知错误'));
        }
        wp_send_json_success('OSS 写入/读取/删除测试通过');
    }

    // R6：取真实访客 IP（供聊天限流用）。EdgeOne 回源请求默认带
    // X-Forwarded-For，首个 IP 即真实访客 IP；但只有 REMOTE_ADDR 落在
    // trusted_proxy_ips（EdgeOne 回源 IP 段）内时才信任它——否则攻击者
    // 直连源站、每请求伪造一个 XFF 就能绕过限流。首个 IP 还必须是合法
    // 公网 IP，否则回退 REMOTE_ADDR。未配置可信代理时行为与旧版一致。
    public function get_real_visitor_ip() {
        $remote = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff === '') return $remote;
        $trusted = $this->get_opt('trusted_proxy_ips', '');
        if (!is_string($trusted) || trim($trusted) === '') return $remote;
        if (!$this->ip_in_list($remote, $trusted)) return $remote;
        $first = trim(explode(',', $xff)[0]);
        if ($first === '' || !filter_var($first, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return $remote;
        return $first;
    }

    // $ip 是否在 $list（每行一个 IP 或 CIDR）里。支持 IPv4/IPv6 CIDR。
    private function ip_in_list($ip, $list) {
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        foreach (explode("\n", $list) as $line) {
            $entry = trim($line);
            if ($entry === '') continue;
            if (strpos($entry, '/') !== false) {
                list($net, $bits) = explode('/', $entry, 2);
                $bits = (int)$bits;
                $net_bin = @inet_pton(trim($net));
                if ($net_bin === false || strlen($net_bin) !== strlen($bin)) continue;
                if ($bits < 0 || $bits > strlen($bin) * 8) continue;
                $bytes = (int)($bits / 8);
                $rem = $bits % 8;
                if ($bytes > 0 && strncmp($bin, $net_bin, $bytes) !== 0) continue;
                if ($rem > 0) {
                    $mask = (0xff << (8 - $rem)) & 0xff;
                    if ((ord($bin[$bytes]) & $mask) !== (ord($net_bin[$bytes]) & $mask)) continue;
                }
                return true;
            }
            if (strcasecmp($ip, $entry) === 0) return true;
        }
        return false;
    }

    // A1：前台聊天记录——最近 50 条，存 skyline_ai_chat_log option。
    // 每条：time（Unix 时间戳）、question（问题，截断 200 字）、reply
    // （回答摘要，截断 200 字；失败时为空）、ms（耗时毫秒）、status
    // （ok/error）、error（失败时的简短错误，如 API 401/超时/限流；
    // 成功时为空）、ip_hash（访客 IP 的 sha256，只存哈希、绝不存明文 IP）。
    // 新记录放最前面；写入时顺带清理超过 30 天的旧记录、上限 50 条。
    // 回答只存前 200 字摘要，不存全文——全文存 option 会随 50 条记录膨胀。
    const CHAT_LOG_OPTION = 'skyline_ai_chat_log';
    const CHAT_LOG_MAX = 50;
    const CHAT_LOG_KEEP_DAYS = 30;

    public function get_chat_log() {
        $log = get_option(self::CHAT_LOG_OPTION, []);
        return is_array($log) ? $log : [];
    }

    public function clear_chat_log() {
        delete_option(self::CHAT_LOG_OPTION);
        return true;
    }

    // A1：记一条聊天记录。日志绝不能拖垮聊天本身：读写失败一律静默
    // 跳过，不抛错、不影响本次聊天的返回。
    public function record_chat_log($question, $reply, $ms, $status, $error, $ip_hash) {
        try {
            $entry = [
                'time' => time(),
                'question' => mb_substr((string)$question, 0, 200),
                'reply' => mb_substr((string)$reply, 0, 200),
                'ms' => max(0, (int)$ms),
                'status' => $status === 'ok' ? 'ok' : 'error',
                'error' => mb_substr((string)$error, 0, 200),
                'ip_hash' => (string)$ip_hash,
            ];
            $log = $this->get_chat_log();
            array_unshift($log, $entry);
            // 清理超过 30 天的旧记录，再截到上限 50 条（缺 time 的脏行
            // 也一并清掉）。
            $cutoff = time() - self::CHAT_LOG_KEEP_DAYS * 86400;
            $log = array_values(array_filter($log, function ($e) use ($cutoff) {
                return is_array($e) && isset($e['time']) && (int)$e['time'] >= $cutoff;
            }));
            if (count($log) > self::CHAT_LOG_MAX) $log = array_slice($log, 0, self::CHAT_LOG_MAX);
            // autoload=false：聊天记录只在后台 AI 页读，不要随每个请求进内存。
            update_option(self::CHAT_LOG_OPTION, $log, false);
        } catch (Throwable $ignored) {
            // 记日志失败不影响聊天返回。
        }
        return true;
    }

    // A1：本次聊天已耗时（毫秒，整数）。
    private function chat_elapsed_ms($started) {
        return (int)round((microtime(true) - $started) * 1000);
    }

    public function handle_chat_front() {
        check_ajax_referer('sky_chat_nonce');
        // A1：计时起点与访客 IP 哈希（只存哈希，绝不存明文 IP）。
        // 注意：nonce 失败在 check_ajax_referer() 里直接 wp_die，到不了
        // 这里——nonce 失败记不进这条日志（见汇报的诚实边界）。
        $chat_started = microtime(true);

        // M13：限流优先走 Redis 原子 INCR（读-改-写不再互相覆盖），Redis 不可用
        // 时回退到旧的 transient 做法。异常 IP 封禁未做——需要后台 UI 管封禁
        // 名单，见审核报告 M13。
        $ip = $this->get_real_visitor_ip();
        $ip_hash = hash('sha256', $ip);
        $limit_err = $this->chat_rate_limit_redis($ip);
        if ($limit_err === false) $limit_err = $this->chat_rate_limit_transient($ip);
        if (is_string($limit_err)) {
            // A1：限流拦截也记一条失败（问题取 sanitize 后的原文；
            // record_chat_log 里会再截断到 200 字）。
            $this->record_chat_log(sanitize_text_field($_POST['msg'] ?? ''), '', $this->chat_elapsed_ms($chat_started), 'error', $limit_err, $ip_hash);
            wp_send_json_error($limit_err);
        }

        $msg = sanitize_text_field($_POST['msg'] ?? '');
        // 聊天输入加长度上限：超长输入直通 API 会拖慢调用、放大 token 消耗。
        if (mb_strlen($msg) > 2000) {
            $this->record_chat_log($msg, '', $this->chat_elapsed_ms($chat_started), 'error', '消息过长（最多 2000 字符）', $ip_hash);
            wp_send_json_error('消息过长（最多 2000 字符）');
        }
        $res = $this->call_api([['role'=>'user', 'content'=>$msg]]);
        if (is_wp_error($res)) {
            // A1：API 报错记失败类型 + 简短错误。call_api 返回的 message
            // 已是 extract_api_error_message() 提炼的短消息，不含密钥与
            // 完整响应体；record_chat_log 里再截断到 200 字。
            $this->record_chat_log($msg, '', $this->chat_elapsed_ms($chat_started), 'error', $res->get_error_message(), $ip_hash);
            wp_send_json_error($res->get_error_message());
        }
        // A1：成功记回答摘要（前 200 字，不存全文）。
        $this->record_chat_log($msg, $res, $this->chat_elapsed_ms($chat_started), 'ok', '', $ip_hash);
        wp_send_json_success($res);
    }

    // M13：Redis 原子限流。返回值：null = 允许；string = 超限提示；
    // false = Redis 不可用（调用方回退 transient）。INCR 是原子操作，并发
    // 请求不再互相覆盖；计数为 1 时才设过期，避免每次请求都续期。
    // 注意：这里直连插件配置的同一台 Redis（与 init_redis() 同一份
    // host/port/auth/db），因为 Infra 的连接是 private、没有对外暴露的
    // 原子计数方法；新建连接只发生在聊天请求上，失败即回退、不影响聊天。
    private function chat_rate_limit_redis($ip) {
        // M12：先查 Redis 熔断（与 Infra::init_redis() 同一个 transient，
        // 见 infra.php:28、:90-92，熔断 60 秒）。熔断期间不再 new Redis()
        // 直连，直接返回 false 走 transient 回退，避免每次聊天都等 2 秒
        // 连接超时拖垮 FPM 工人。管理员手动测试 handle_test_redis() 有意
        // 绕过熔断，不改。
        if (function_exists('get_transient') && get_transient('sky_redis_circuit_open')) return false;
        if (!class_exists('Redis') || !$this->get_opt('redis_enable')) return false;
        try {
            $redis = new Redis();
            $connected = $redis->connect($this->get_opt('redis_host', '127.0.0.1'), (int)$this->get_opt('redis_port', 6379), 2);
            if (!$connected) return false;
            if ($this->get_opt('redis_auth')) {
                if (!$redis->auth($this->get_opt('redis_auth'))) return false;
            }
            if (!$redis->select((int)$this->get_opt('redis_db', 0))) return false;

            $hash = md5($ip);
            $min_key = 'sky:chat_rate_min:' . $hash . ':' . gmdate('YmdHi');
            $n = $redis->incr($min_key);
            if ($n === false) return false;
            if ($n === 1) $redis->expire($min_key, 120);
            if ($n > self::CHAT_RATE_PER_MINUTE) return '请求过于频繁，请稍后再试';

            // N-D3：每日上限按本地天（站点时区）重置——gmdate 是 UTC 天，
            // 北京时间 08:00 才翻天，上午 8 点前仍算前一天的额度。
            // current_time() 走站点时区设置；分钟桶不受天边界影响，仍用 gmdate。
            $day = function_exists('current_time') ? (string)current_time('Ymd') : gmdate('Ymd');
            $day_key = 'sky:chat_rate_day:' . $hash . ':' . $day;
            $d = $redis->incr($day_key);
            if ($d === false) return false;
            if ($d === 1) $redis->expire($day_key, 86400);
            if ($d > self::CHAT_RATE_PER_DAY) return '今日聊天次数已达上限（500 次），请明天再试';

            return null;
        } catch (Throwable $e) {
            return false;
        }
    }

    // M13：transient 回退（Redis 不可用时用）。沿用旧的每分钟 20 次逻辑，
    // 另加每日 500 次上限；与 Redis 路径互斥，不会重复计数。
    private function chat_rate_limit_transient($ip) {
        $rate_key = 'sky_chat_rate_' . md5($ip);
        $rate_count = get_transient($rate_key);
        if ($rate_count === false) $rate_count = 0;
        if ((int)$rate_count >= self::CHAT_RATE_PER_MINUTE) {
            return '请求过于频繁，请稍后再试';
        }
        set_transient($rate_key, (int)$rate_count + 1, 60);

        $day_key = 'sky_chat_rate_day_' . md5($ip);
        $day_count = get_transient($day_key);
        if ($day_count === false) $day_count = 0;
        if ((int)$day_count >= self::CHAT_RATE_PER_DAY) {
            return '今日聊天次数已达上限（500 次），请明天再试';
        }
        set_transient($day_key, (int)$day_count + 1, 86400);

        return null;
    }

    public function handle_ai_task() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');
        $t = sanitize_key($_POST['task'] ?? '');
        $i = wp_kses_post($_POST['input'] ?? '');
        // 长度限制，防止超长输入拖垮 API 调用与请求超时
        $i = mb_substr($i, 0, 6000);
        
        $prompts = [
            'title'    => "你是一位资深的SEO内容专家。请根据以下文章内容，生成5个具有极高点击率、符合SEO原则且能精准概括核心价值的中文标题。要求：\n1. 提供5个不同风格（如：痛点驱动型、权威指南型、反直觉悬念型、结果导向型、极简概括型）。\n2. 严禁输出任何开场白或结束语（如 '为您生成的标题是' 或 '希望您满意'）。\n3. 每行输出一个标题，不要编号，不要引号。\n\n文章内容：\n$i",
            'outline'   => "请分析以下文章的逻辑结构，并生成一个专业的 Markdown 形式的大纲。要求：层级清晰（# ## ###），涵盖所有核心观点和论据，确保逻辑严密。\n\n文章内容：\n$i",
            'continue'  => "请阅读以下段落，并根据当前的语境、语气和逻辑，自然地续写接下来的内容。要求：无缝衔接，保持风格一致，直接输出续写部分，不要输出 '续写如下' 等提示词。\n\n内容：\n$i",
            'expand'    => "请对以下内容进行深度扩写。要求：在不改变原意的前提下，增加细节描述、专业论证或具体案例，使内容更丰满、更有说服力，提升专业感。直接输出扩写后的全文。\n\n内容：\n$i",
            'rewrite'   => "你是一位顶级的伪原创专家。请在保持原意绝对不变的前提下，彻底重写以下内容。要求：打破原有的句式结构，使用同义词替换，重新组织行文逻辑，确保在通过 AI 检测的同时，可读性极高。直接输出重写后的内容。\n\n内容：\n$i",
            'polish'    => "请对以下内容进行智能润色。要求：修正所有错别字和病句，提升词汇的专业度，使行文风格符合『现代专业技术文档』的审美（客观、精炼、流畅）。直接输出润色后的内容。\n\n内容：\n$i",
            'shorten'   => "请在保留所有核心结论和关键事实的前提下，将以下内容进行极简缩写。要求：删掉所有冗余修饰词，使表达像电报一样高效有力。直接输出缩写结果。\n\n内容：\n$i",
            'trans'     => "请将以下内容进行高质量的中英互译（中文 ↔ 英文）。要求：翻译自然，符合目标语言的母语表达习惯，准确保留专业术语。直接输出翻译结果。\n\n内容：\n$i",
            'desc'      => "请为以下文章生成一段 120 字左右的 SEO 元描述（Meta Description）。要求：包含核心关键词，采用『痛点+解决方案』的结构，具有强烈的诱导点击效果。直接输出摘要内容。\n\n内容：\n$i",
            'tags'      => "请基于以下内容提取 5-8 个核心 SEO 标签。要求：包含 1-2 个行业大类词和 3-6 个具体核心词，用逗号分隔，严禁输出编号、前缀或任何解释文字。\n\n内容：\n$i",
            'slug_en'   => "Generate a concise, SEO-friendly English URL slug for this content. Requirements: strictly lowercase, use hyphens instead of spaces, max 6 words, NO leading/trailing hyphens. STRICTLY output ONLY the slug text, no explanation.\n\nContent:\n$i",
        ];

        $prompt = isset($prompts[$t]) ? $prompts[$t] : "AI Task: $t. Input: $i";
        
        // N-D2：slug_en 要求只输出英文 slug，system 人设里的“中文回答”
        // 会与之冲突——该任务不拼 system 消息（call_api 第 3 参数）。
        $res = $this->call_api([['role'=>'user', 'content'=>$prompt]], 0.7, $t !== 'slug_en');
        
        // R2：call_api 失败返回 WP_Error，不再是含 "Error" 的字符串。
        if (is_wp_error($res)) {
            wp_send_json_error($res->get_error_message());
        }

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
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        wp_send_json_success(get_option('skyline_prompt_library', []));
    }

    public function handle_delete_prompt() {
        check_ajax_referer('sky_prompt_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $id = sanitize_text_field($_POST['id'] ?? '');
        if(!$id) wp_send_json_error('Missing id');
        $lib = get_option('skyline_prompt_library', []);
        if (!is_array($lib)) $lib = [];
        if (isset($lib[$id])) {
            unset($lib[$id]);
            update_option('skyline_prompt_library', $lib);
            wp_send_json_success('Deleted');
        }
        wp_send_json_error('Prompt not found');
    }

    public function handle_check_quality() {
        check_ajax_referer('sky_quality_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');
        $content = wp_kses_post($_POST['content'] ?? '');
        wp_send_json_success(Skyline_Utils::assess_quality($content));
    }

    public function handle_gen_img() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('Unauthorized');
        $prompt = sanitize_text_field($_POST['prompt'] ?? '');
        // L8：prompt 为空直接报错，不发请求。
        if ($prompt === '') wp_send_json_error('生图提示词为空');
        $k = $this->get_opt('api_key');
        if(!$k) wp_send_json_error('API Key 未配置');
        // L8：生图端点优先用独立配置项 api_img_base（根地址，schema 默认
        // 与 API Endpoint 同厂商：https://api.siliconflow.cn/v1）。
        // 留空时只从以 /chat/completions 结尾的 api_base 推导；推导不出
        // 时直接报错，不再静默回退 SiliconFlow 默认地址——api_base 换了
        // 厂商时，偷偷把提示词发给另一家既鉴权失败、也不符合用户预期。
        $img_base = trim((string)$this->get_opt('api_img_base', ''));
        if ($img_base !== '') {
            $img_url = rtrim($img_base, '/') . '/images/generations';
        } else {
            $base = trim((string)$this->get_opt('api_base', 'https://api.siliconflow.cn/v1/chat/completions'));
            if (substr($base, -17) === '/chat/completions') {
                $img_url = str_replace('/chat/completions', '/images/generations', $base);
                $this->log("生图端点未配置 api_img_base，从 api_base 推导：{$img_url}", 'warning', 'API');
            } else {
                wp_send_json_error('生图端点未配置：请在智能核心设置里填写“生图 API 根地址”，或把 API Endpoint 改为以 /chat/completions 结尾的地址');
            }
        }
        $args = [
            'headers' => ['Authorization' => 'Bearer '.$k, 'Content-Type' => 'application/json'],
            // L8：补尺寸参数，默认 1024x1024。
            'body' => json_encode(['model' => $this->get_opt('image_model'), 'prompt' => $prompt, 'size' => '1024x1024']),
            'timeout' => 120
        ];
        $r = wp_remote_post($img_url, $args);
        if (is_wp_error($r)) {
            $this->log("生图网络失败: {$r->get_error_message()}", 'error', 'API');
            wp_send_json_error('生图请求失败: ' . $r->get_error_message());
        }
        $code = wp_remote_retrieve_response_code($r);
        // L8：API 返回标量体时 $d 不是数组，旧代码 $d['error']['message'] 会
        // 抛 TypeError 导致 AJAX 500；先判 is_array 再取字段。
        $d = json_decode(wp_remote_retrieve_body($r), true);
        if ($code !== 200) {
            $errMsg = $this->extract_api_error_message($d, "HTTP {$code}");
            $this->log("生图失败 ({$code}): {$errMsg}", 'error', 'API');
            wp_send_json_error("生图失败 ({$code}): " . $errMsg);
        }
        // L8：校验返回 URL 为合法 http(s) 地址才返回；失败时返回 WP_Error
        // 语义的 wp_send_json_error，不再把字符串 'Fail' 当成功 URL 返回
        // （前端曾拿它拼 <img src="Fail">）。
        $url = (is_array($d) && isset($d['data'][0]['url'])) ? $d['data'][0]['url'] : null;
        if (!is_string($url) || filter_var($url, FILTER_VALIDATE_URL) === false || !preg_match('#^https?://#i', $url)) {
            $this->log('生图失败：未返回合法图片地址', 'error', 'API');
            wp_send_json_error('生图失败：未返回合法图片地址');
        }
        wp_send_json_success($url);
    }

    public function handle_clear_logs() {
        check_ajax_referer('sky_clear_logs_nonce');
        if(!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $dir = dirname($this->log_file);
        foreach (glob($dir . '/skyline_ai*.log') ?: [] as $f) {
            @unlink($f);
        }
        // 重建空的今日日志文件（带随机后缀，与 log() 保持一致）
        @file_put_contents($this->today_log_file(), '');
        wp_send_json_success('Logs cleared');
    }

    public function enqueue_waifu_assets() {
        // L12：与 render_waifu() 同样的门控——关掉悬浮助手时不加载
        // waifu.css/js/nonce，不再全站无条件加载。
        if(!$this->get_opt('robot_enable')) return;
        if ($this->get_opt('robot_only_logged') && !is_user_logged_in()) return;
        wp_enqueue_style('sky-waifu', SKY_URL . 'assets/css/waifu.css', [], SKY_VERSION);
        wp_enqueue_script('sky-waifu', SKY_URL . 'assets/js/waifu.js', ['jquery'], SKY_VERSION, true);
        wp_localize_script('sky-waifu', 'sky_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'), 
            'nonce' => wp_create_nonce('sky_chat_nonce')
        ]);
    }

    public function render_waifu() {
        if(!$this->get_opt('robot_enable')) return;
        // 补上缺失的登录可见逻辑！
        if ($this->get_opt('robot_only_logged') && !is_user_logged_in()) return;
        // 注意：插件自带的 assets/images/robot.png 本身就是坏文件（从 v2.0.1
        // 起 PNG 头就损坏，getimagesize() 失败），绝不能把它当头像 fallback。
        // robot_img 没设置时，浮动入口与标题栏都不输出 <img>，改用 emoji
        // 入口，避免前台出现破图图标。
        $img_url = $this->get_opt('robot_img');
        $has_img = is_string($img_url) && trim($img_url) !== '';
        $head_avatar = $has_img
            ? '<img class="sky-head-avatar" src="' . esc_url($img_url) . '" alt="">'
            : '';
        echo '
        <div id="sky-waifu" onclick="toggleSkyChat()">'
            . ($has_img
                ? '<img src="' . esc_url($img_url) . '" alt="AI Assistant">'
                : '<span class="sky-waifu-fallback" role="img" aria-label="AI Assistant">💬</span>')
            . '<div id="sky-waifu-tips" class="show">你好！我是灵感屋 AI 助手，有什么可以帮您？</div>
        </div>
        <div id="sky-chat-box">
            <div class="sky-head">'
                . $head_avatar .
                '<span class="sky-head-title">✨ Skyline Copilot</span>
                <span style="cursor:pointer;" onclick="toggleSkyChat()">✖</span>
            </div>
            <div id="sky-chat-msgs"></div>
            <div class="sky-foot">
                <input type="text" id="sky-chat-in" placeholder="输入您的问题..." onkeypress="if(event.keyCode==13) skySend()">
                <button onclick="skySend()" style="background:#6366f1;color:#fff;border:none;border-radius:20px;padding:0 15px;cursor:pointer;">发送</button>
            </div>
        </div>
        ';
    }
}
