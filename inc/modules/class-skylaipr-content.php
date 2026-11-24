<?php
if (!defined('ABSPATH')) exit;

class Skylaipr_Content {
    // M5：爬虫下载单张图片的大小上限（10MB）。超过直接拒绝，不读进内存。
    private const SPIDER_MAX_IMAGE_BYTES = 10485760;

    // C1/S1 批量：每批篇数与单次 AJAX 请求的墙钟预算（与 WebP 批量同款：
    // 数量由服务端定死、不信任客户端，超时前停下、剩下的 ID 客户端下一
    // 批重发）。C1 每批 5 篇（单篇最多同步 spider_max_img 张图，耗时比
    // WebP 单张转换长）；S1 每篇最多 3 次 AI 调用，默认每批 5 篇、上限
    // 20 篇（由批量卡片上的输入框调，服务端 clamp）。
    public const SPIDER_BATCH_SIZE = 5;
    public const SPIDER_BATCH_TIME_BUDGET = 20;
    public const SEO_BATCH_SIZE_DEFAULT = 5;
    public const SEO_BATCH_SIZE_MAX = 20;
    public const SEO_BATCH_TIME_BUDGET = 20;
    public const SEO_TRIAL_COUNT = 20; // S1 扫描后默认勾选前 20 篇试跑
    public const SCAN_MAX_ITEMS = 1000; // C1/S1 扫描清单最多列出的篇数
    // v2.2.8：C1/S1 扫描分页（WebP scanPaged 同款）：每页篇数与单次
    // 扫描请求的墙钟预算；页内超时即停，返回 done=false、offset=下一
    // 未处理位置，客户端按 offset 续扫。预算 20 秒，留足余量不撞
    // EdgeOne 60 秒回源超时。
    public const SCAN_PAGE_SIZE = 500;
    public const SCAN_TIME_BUDGET = 20;

    // 后台批量入口（admin.php 的 AJAX 处理）要调本类的引擎方法：经此
    // 取单例，不直接 new（构造器会重复注册钩子）。与 Skylaipr_OSS_Mod::
    // instance() 同款模式。
    private static $instance = null;
    // F-11(b)：flush_page_cache_for_post() 用的 Redis 模块实例缓存——
    // new 一次 Skylaipr_Redis_Mod 会多挂一个 transition_post_status
    // 回调（幂等删缓存，无害），故只建一次。
    private static $redis_mod = null;
    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    public function __construct() {
        self::$instance = $this;
        add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        // X-3：skylaipr_async_spider / skylaipr_async_seo 两个 cron 回调
        // 不再在此注册——改由插件主文件 skyline-ai-pro.php 在加载期
        // 用懒加载闭包直接注册（与类实例化解耦），此处只删、不另注册。
        
        add_filter('the_content', [$this, 'auto_internal_links']);
        // F-10：插件侧 SEO meta 输出（output_seo_meta 内再查
        // seo_output_meta 开关，默认关）。
        add_action('wp_head', [$this, 'output_seo_meta']);
        
        add_action('wp_ajax_skylaipr_seo_score', [$this, 'ajax_seo_score']);
        add_action('wp_ajax_skylaipr_spider_single', [$this, 'ajax_spider_single']); 
        add_action('wp_ajax_skylaipr_link_now', [$this, 'ajax_link_now']); 
        
        add_action('add_meta_boxes', [$this, 'add_copilot_box']);
        // v2.2.9（WP.org 审核）：编辑器 meta box 的样式与脚本原来直接
        // 输出 style/script 标签，改走 wp_enqueue_style/wp_enqueue_script；
        // ajax 地址与 nonce 由 wp_localize_script() 传入（skylaiprEditorData）。
        // 只在文章编辑页（post.php/post-new.php）加载。
        add_action('admin_enqueue_scripts', function($hook) {
            if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
            // 注意：__FILE__ 在 inc/modules/ 下，plugins_url() 的第二个参数
            // 必须是插件根文件，否则算出的 URL 会多一层 inc/ 而 404。
            // 直接用主文件定义的 SKYLAIPR_URL（plugin_dir_url，插件根目录）。
            wp_enqueue_style('skylaipr-editor-css', SKYLAIPR_URL . 'assets/css/editor.css', [], SKYLAIPR_VERSION);
            wp_enqueue_script('skylaipr-editor-js', SKYLAIPR_URL . 'assets/js/editor.js', ['jquery'], SKYLAIPR_VERSION, true);
            wp_localize_script('skylaipr-editor-js', 'skylaiprEditorData', [
                'ajaxUrl'   => admin_url('admin-ajax.php'),
                'taskNonce' => wp_create_nonce('skylaipr_ai_task_nonce'),
                'seoNonce'  => wp_create_nonce('skylaipr_seo_nonce'),
            ]);
        });
    }

    public function trigger_async_tasks($pid, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || $post->post_status != 'publish') return;

        $core = Skylaipr_Core::instance();
        
        if ($core->get_opt('spider_enable') && $core->get_opt('spider_auto')) {
            if (!wp_next_scheduled('skylaipr_async_spider', [$pid])) {
                wp_schedule_single_event(time() + 2, 'skylaipr_async_spider', [$pid]);
            }
        }

        $do_tags = $core->get_opt('auto_tags');
        $do_slug = $core->get_opt('auto_slug');
        $do_excerpt = $core->get_opt('auto_excerpt');
        $do_polish = $core->get_opt('auto_polish');
        
        if ($do_tags || $do_slug || $do_excerpt || $do_polish) {
            // v2.1.1：失败满 3 次后 _skylaipr_seo_done 被置 1、自动重试链停止；
            // 但手动点“更新”保存时应给一次复活机会——否则日志里“下次保存
            // 时重试”的承诺永远兑现不了（2026-11-09 实测：70753/70754 三次
            // 401 后被标记完成，API 恢复后重新保存也不再触发 SEO）。
            // 区分“成功完成”和“放弃”：成功时 _skylaipr_seo_fail_count 会被删掉，
            // 放弃时它留在 >=3；只有后者才在保存时清掉重排。
            $seo_done = get_post_meta($pid, '_skylaipr_seo_done', true);
            if ($seo_done && (int)get_post_meta($pid, '_skylaipr_seo_fail_count', true) >= 3) {
                delete_post_meta($pid, '_skylaipr_seo_done');
                delete_post_meta($pid, '_skylaipr_seo_fail_count');
                $seo_done = false;
                $core->log("自动 SEO 曾因连续失败满 3 次停止重试，本次保存已重置、重新排期 [$pid]", 'info', 'SEO');
            }
            if (!$seo_done && !wp_next_scheduled('skylaipr_async_seo', [$pid])) {
                wp_schedule_single_event(time() + 5, 'skylaipr_async_seo', [$pid]);
            }
        }
    }

    // M7/M14：内链替换的公共方法，auto_internal_links()（前台 the_content）
    // 与 ajax_link_now()（编辑器“自动内链（写回）”按钮）两处共用。
    // 切分后替换：只在标签外的纯文本段里替换，且跳过已在 <a>…</a> 内的
    // 文本，避免产出 <a><a> 嵌套链接（旧 lookahead 正则的毛病）。
    private function link_keyword_in_parts(array $parts, $kw, $url) {
        $in_a = false;
        foreach ($parts as $i => $part) {
            if ($part === '') continue;
            if ($part[0] === '<') {
                if (preg_match('#^<\s*a[\s>]#i', $part)) $in_a = true;
                elseif (preg_match('#^<\s*/\s*a\s*>#i', $part)) $in_a = false;
                continue;
            }
            if ($in_a) continue;
            if (mb_stripos($part, $kw) === false) continue;
            // M14①：/i 大小写不敏感查找 + preg_replace_callback 保留原文
            // 大小写（链接文字用原文匹配到的写法，不用关键词的写法）；
            // M7：title 用 esc_attr() 转义，关键词里的引号不再破坏属性。
            // M14②：补 rel="noopener"（与 target="_blank" 一起）。
            $new = preg_replace_callback(
                '/' . preg_quote($kw, '/') . '/iu',
                function ($m) use ($url) {
                    return '<a href="' . esc_url($url) . '" title="' . esc_attr($m[0]) . '" target="_blank" rel="noopener" class="sky-link">' . $m[0] . '</a>';
                },
                $part,
                1
            );
            if ($new !== null && $new !== $part) {
                $parts[$i] = $new;
                return [$parts, 1];
            }
            break; // 每个关键词最多替换 1 处（与旧行为一致）
        }
        return [$parts, 0];
    }

    public function auto_internal_links($content) {
            $core = Skylaipr_Core::instance();
            if(!$core->get_opt('link_enable') || is_admin()) return $content;
            $links_str = $core->get_opt('link_pairs');
            if(!$links_str) return $content;

            // M14③：全文只切分一次，循环关键词复用同一份切分结果——
            // 旧写法每个关键词都对全文做一次 preg_split，长文渲染变慢。
            $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
            if (!is_array($parts)) return $content;
            $pairs = explode("\n", $links_str);
            foreach($pairs as $pair) {
                $p = explode('|', trim($pair));
                if(count($p) < 2) continue;
                $kw = trim($p[0]); $url = trim($p[1]);
                if(!$kw || !$url) continue;
                list($parts) = $this->link_keyword_in_parts($parts, $kw, $url);
            }
            return implode('', $parts);
        }

    // F-10：插件侧 SEO meta 输出层。开关 seo_output_meta 的 schema
    // 定义在 core.php（group seo，bool，默认 false；由协调人补进
    // schema），此处用 get_opt('seo_output_meta', false) 读，未配置
    // 按 false 处理。默认关闭的原因：子比主题已输出 description /
    // OG，默认开启会在现网造成重复 meta。仅 is_singular('post')
    // 且摘要非空时输出 description（截 160 字）+ OG 标签。
    public function output_seo_meta() {
        $core = Skylaipr_Core::instance();
        if (!$core->get_opt('seo_output_meta', false)) return;
        if (!function_exists('is_singular') || !is_singular('post')) return;
        global $post;
        if (!is_object($post) || empty($post->post_excerpt)) return;
        $desc = trim(mb_substr(wp_strip_all_tags((string)$post->post_excerpt), 0, 160));
        if ($desc === '') return;
        $title = (string)($post->post_title ?? '');
        $url = function_exists('get_permalink') ? (string)get_permalink((int)$post->ID) : '';
        echo '<meta name="description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:title" content="' . esc_attr($title) . '">' . "\n";
        echo '<meta property="og:description" content="' . esc_attr($desc) . '">' . "\n";
        echo '<meta property="og:type" content="article">' . "\n";
        if ($url !== '') echo '<meta property="og:url" content="' . esc_url($url) . '">' . "\n";
    }

    // 校验一个 URL 的目标主机是否可公开访问：解析全部 A/AAAA 记录，任一 IP
    // 是内网/保留地址即拒绝。返回 null 表示通过，返回字符串表示错误原因。
    // 注意：这只能防“解析时就是内网”的情况，检查与实际请求之间仍存在 DNS
    // 重绑定（TOCTOU）窗口，无法仅靠应用层彻底消除。
    private function validate_remote_host($url) {
        $parsed = wp_parse_url($url);
        if (!$parsed || empty($parsed['host'])) return '无效的URL格式';
        $host = strtolower($parsed['host']);

        // 目标本身就是 IP 字面量时直接校验
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return $this->is_public_ip($host) ? null : '不允许访问内部网络地址';
        }

        $ips = [];
        if (function_exists('gethostbynamel')) {
            $v4 = gethostbynamel($host);
            if (is_array($v4)) $ips = array_merge($ips, $v4);
        }
        // L32⑤：AAAA 走 CNAME 链解析（见 resolve_aaaa_chain）：$host 本身
        // 只有 CNAME 记录时，AAAA 在 CNAME 目标上，直接查 $host 的 AAAA
        // 查不到，会漏判目标的内网 AAAA。链上每一跳的 AAAA 都收集到 $ips，
        // 下面统一经 is_public_ip() 校验，任一内网地址即拒绝。
        $aaaa = $this->resolve_aaaa_chain($host);
        if ($aaaa === null) return '域名解析失败'; // CNAME 循环
        $ips = array_merge($ips, $aaaa);
        if (empty($ips)) return '域名解析失败';
        foreach ($ips as $ip) {
            if (!$this->is_public_ip($ip)) return '不允许访问内部网络地址';
        }
        return null;
    }

    // DNS 查询的可替身入口：生产走 dns_get_record；测试用子类覆盖本
    // 方法注入脚本化记录——全局命名空间的 dns_get_record 是内置函数，
    // 无法在测试里重定义，故走这层间接调用。返回 dns_get_record 的
    // 原样结果（记录数组 / false），调用方按 is_array 判断。
    protected function dns_query($host, $type) {
        if (!function_exists('dns_get_record')) return false;
        return @dns_get_record($host, $type);
    }

    // L32⑤：沿 CNAME 链查 AAAA。dns_get_record($host, DNS_AAAA) 不跟
    // CNAME：$host 本身只有 CNAME 记录时，AAAA 在 CNAME 目标上，直接
    // 查 $host 的 AAAA 查不到目标的内网 AAAA，会漏判。逐跳查 AAAA 再
    // 查 CNAME、跳到目标主机继续查（上限 5 跳，含 $host 本身；目标
    // 主机名统一小写、去尾点再比对，防循环）。返回收集到的全部 IPv6
    // 地址数组（调用方逐个经 is_public_ip() 校验）；检测到 CNAME 循环
    // 时返回 null（拒绝，不死循环）。
    // 注意：A 记录不用走这条链——gethostbynamel() 由系统解析器跟 CNAME，
    // 本来就能拿到目标的 A 记录，只有 AAAA 有这个缺口。
    // 诚实记录：本机无法验证真 DNS（本机 dns_get_record /
    // gethostbynamel 均返回 false），该路径只用测试替身验证过，真 DNS
    // 行为须在服务器上核对。
    private function resolve_aaaa_chain($host) {
        $ips = [];
        $seen = [];
        $current = $host;
        for ($hop = 0; $hop < 5; $hop++) {
            $current = strtolower(rtrim($current, '.'));
            if (isset($seen[$current])) return null; // CNAME 循环
            $seen[$current] = true;
            $aaaa = $this->dns_query($current, DNS_AAAA);
            if (is_array($aaaa)) {
                foreach ($aaaa as $rec) {
                    if (!empty($rec['ipv6'])) $ips[] = $rec['ipv6'];
                }
            }
            $cname = $this->dns_query($current, DNS_CNAME);
            $target = null;
            if (is_array($cname)) {
                foreach ($cname as $rec) {
                    if (!empty($rec['target'])) { $target = (string)$rec['target']; break; }
                }
            }
            if ($target === null || $target === '') break;
            $current = $target;
        }
        return $ips;
    }

    // IPv4 公网校验（主分支与 ::ffff: 映射 / ::/96 兼容分支共用）：
    // L29：CGNAT 100.64.0.0/10 显式拒绝（FILTER_FLAG_NO_PRIV_RANGE |
    // NO_RES_RANGE 不覆盖该段）；L32③：TEST-NET 文档段 192.0.2.0/24、
    // 198.51.100.0/24、203.0.113.0/24 与基准测试段 198.18.0.0/15 显式
    // 拒绝（同样不在两 flag 覆盖范围内，公网不可路由，仅当宿主内网恰好
    // 使用这些段时才可达——拒绝是安全方向）。
    private function is_public_ipv4($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        $ip_long = ip2long($ip);
        if ($ip_long === false) return false;
        if (($ip_long & 0xffc00000) === 0x64400000) return false; // 100.64.0.0/10 CGNAT
        if (($ip_long & 0xffffff00) === 0xc0000200) return false; // 192.0.2.0/24 TEST-NET-1
        if (($ip_long & 0xffffff00) === 0xc6336400) return false; // 198.51.100.0/24 TEST-NET-2
        if (($ip_long & 0xffffff00) === 0xcb007100) return false; // 203.0.113.0/24 TEST-NET-3
        if (($ip_long & 0xfffe0000) === 0xc6120000) return false; // 198.18.0.0/15 基准测试
        return (bool)filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
    }

    private function is_public_ip($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return $this->is_public_ipv4($ip);
        }
        // IPv6：显式拒绝回环、未指定、链路本地、唯一本地、文档地址
        $ip_l = strtolower($ip);
        if ($ip_l === '::1' || $ip_l === '::') return false;
        $bin = @inet_pton($ip);
        if ($bin === false) return false;
        // IPv4 内嵌地址：后 32 位就是内嵌的 IPv4，必须按 IPv4 重校验，
        // 否则 cURL 连接时仍走到内网 IPv4。覆盖三种写法（前 96 位只含
        // 0 与一段 ffff）：::/96 兼容地址（如 ::127.0.0.1）、::ffff:/96
        // 映射地址（如 ::ffff:127.0.0.1）、::ffff:0:/96 SIIT 翻译地址
        // （如 ::ffff:0:127.0.0.1，ffff 在第 8–9 字节，旧的两分支都
        // 够不着它，会误判为公网——L32⑤核查清单里的用例）。
        // L29：重校验走 is_public_ipv4()，CGNAT 与 TEST-NET 段同样拒绝。
        $prefix = substr($bin, 0, 12);
        $nonzero = str_replace("\x00", '', $prefix);
        if ($nonzero === '' || $nonzero === "\xff\xff") {
            $v4 = @inet_ntop(substr($bin, 12, 4));
            return $v4 !== false && $this->is_public_ipv4($v4);
        }
        // R1：NAT64 前缀 64:ff9b::/32——公认前缀 64:ff9b::/96 与本地部署前缀
        // 64:ff9b:1::/48 等都在此段内，目标实际由内嵌 IPv4 决定，一律拒绝。
        if (substr($bin, 0, 4) === "\x00\x64\xff\x9b") return false; // 64:ff9b::/32 NAT64
        if (substr($bin, 0, 2) === "\x20\x02") return false; // 2002::/16 6to4
        if (substr($bin, 0, 4) === "\x20\x01\x00\x00") return false; // 2001::/32 Teredo
        if ((ord($bin[0]) & 0xfe) === 0xfc) return false; // fc00::/7 唯一本地
        if (ord($bin[0]) === 0xfe && (ord($bin[1]) & 0xc0) === 0x80) return false; // fe80::/10
        if (substr($bin, 0, 4) === "\x20\x01\x0d\xb8") return false; // 2001:db8::/32 文档
        return true;
    }

    // M5：用 HEAD 取 Content-Length。返回 int（已知长度）或 null
    // （HEAD 失败 / 无该头 / 服务器不支持 HEAD——调用方走流式路径）。
    private function remote_content_length($url, $args) {
        if (!function_exists('wp_remote_head')) return null;
        $head = wp_remote_head($url, $args);
        if (is_wp_error($head)) return null;
        $len = wp_remote_retrieve_header($head, 'content-length');
        if ($len === '' || $len === null || !is_numeric($len)) return null;
        return (int)$len;
    }

    // L32①②：按 RFC 3986 合并重定向 Location。返回完整 http(s) URL；
    // 目标带非 http(s) scheme（如 ftp:）时返回 null（调用方直接拒绝，
    // 不拼成 https://host/ftp://...）。相对路径与当前 URL 的目录合并
    // （不是拼到 host 根），并做 dot-segment 归一化；协议相对 //host/path
    // 只补 scheme；?query / #fragment 只换查询/片段、保留原路径；端口保留。
    private function resolve_redirect_url($current_url, $location) {
        if (preg_match('#^([a-z][a-z0-9+.-]*):#i', $location, $m)) {
            $scheme = strtolower($m[1]);
            if ($scheme !== 'http' && $scheme !== 'https') return null;
            return $location;
        }
        $base = wp_parse_url($current_url);
        $scheme = $base['scheme'] ?? 'https';
        $host = $base['host'] ?? '';
        $port = isset($base['port']) ? ':' . (int)$base['port'] : '';
        if (strpos($location, '//') === 0) return $scheme . ':' . $location;
        $base_path = $base['path'] ?? '/';
        if (strpos($location, '/') === 0) return $scheme . '://' . $host . $port . $location;
        if (strpos($location, '?') === 0 || strpos($location, '#') === 0) {
            return $scheme . '://' . $host . $port . $base_path . $location;
        }
        $dir = substr($base_path, 0, strrpos($base_path, '/') + 1);
        $merged = $dir . $location;
        $suffix = '';
        if (($q = strpos($merged, '?')) !== false || ($f = strpos($merged, '#')) !== false) {
            $cut = min($q !== false ? $q : PHP_INT_MAX, $f !== false ? $f : PHP_INT_MAX);
            $suffix = substr($merged, $cut);
            $merged = substr($merged, 0, $cut);
        }
        $out = [];
        foreach (explode('/', $merged) as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if ($seg === '..') { array_pop($out); continue; }
            $out[] = $seg;
        }
        return $scheme . '://' . $host . $port . '/' . implode('/', $out) . $suffix;
    }

    private function download_image($url, $pid) {
        if (!function_exists('wp_generate_attachment_metadata')) {
            require_once(ABSPATH . 'wp-admin/includes/image.php');
            require_once(ABSPATH . 'wp-admin/includes/file.php');
            // v2.2.9（WP.org 审核）：删掉 media.php 的 require——
            // download_image() 只用 wp_insert_attachment（post.php）与
            // wp_generate_attachment_metadata（image.php），不用 media.php
            // 的任何函数，该加载是不必要的。
        }

        $core = Skylaipr_Core::instance();
        $norm_url = $url;
        $img_hash = md5($norm_url);

        $spider_history = get_post_meta($pid, '_skylaipr_spider_history', true);
        if (!is_array($spider_history)) $spider_history = [];

        if (isset($spider_history[$img_hash])) {
            $existing_id = intval($spider_history[$img_hash]);
            if (get_post($existing_id)) return ['id' => $existing_id, 'status' => 'cached_post'];
        }

        global $wpdb;
        $global_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_skylaipr_source_hash' AND meta_value = %s LIMIT 1", $img_hash));

        if ($global_id && get_post($global_id)) {
            $spider_history[$img_hash] = $global_id;
            update_post_meta($pid, '_skylaipr_spider_history', $spider_history);
            return ['id' => $global_id, 'status' => 'cached_global'];
        }

        // SSRF 防护：每次请求前都校验目标主机解析出的全部 IP（A + AAAA 记录），
        // 拒绝内网/保留地址。之前只用 gethostbyname() 查一个 IPv4，且 wp_remote_get
        // 默认跟随重定向——外站回 302 跳到 169.254.169.254 或内网地址时检查完全失效。
        $err = $this->validate_remote_host($url);
        if ($err) return ['error' => $err];

        $args = [
            'timeout' => 25,
            'sslverify' => $core->get_opt('spider_ssl_verify', true),
            'redirection' => 0, // 不自动跟随重定向，改为下面手动处理，每跳都重新校验目标 IP
            'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36'
        ];

        if($core->get_opt('spider_allow_wechat') && (strpos($url,'qpic.cn')!==false || strpos($url,'mmbiz')!==false)) {
            $args['headers']['Referer'] = 'https://mp.weixin.qq.com/';
        } else {
            $args['headers']['Referer'] = $url;
        }

        // 手动跟随重定向（最多 3 跳），每一跳都重新校验目标主机，防止 302 跳内网。
        // M5：每跳先 HEAD 取 Content-Length，超 10MB 直接拒绝；拿不到长度
        // 时该跳走流式（stream 到临时文件、按文件大小计，超限即中断），
        // 不把未知大小的响应全量读进内存。
        $res = null;
        $current_url = $url;
        $file_data = null;
        for ($hop = 0; $hop <= 3; $hop++) {
            $known_len = $this->remote_content_length($current_url, $args);
            if ($known_len !== null && $known_len > self::SPIDER_MAX_IMAGE_BYTES) {
                return ['error' => '文件过大(>10MB)'];
            }
            $tmp = null;
            $is_stream = ($known_len === null);
            if ($is_stream) {
                $tmp = function_exists('wp_tempnam') ? wp_tempnam('skylaipr_spider_') : tempnam(sys_get_temp_dir(), 'skylaipr_spider_');
                $stream_args = $args;
                $stream_args['stream'] = true;
                if ($tmp) $stream_args['filename'] = $tmp;
                $res = wp_remote_get($current_url, $stream_args);
            } else {
                $res = wp_remote_get($current_url, $args);
            }
            if(is_wp_error($res)) { if ($tmp) wp_delete_file($tmp); return ['error' => '网络错误: ' . $res->get_error_message()]; }
            $code = wp_remote_retrieve_response_code($res);
            if ($code >= 300 && $code < 400) {
                $location = trim((string)wp_remote_retrieve_header($res, 'location'));
                if ($tmp) wp_delete_file($tmp);
                if ($location === '' || $hop === 3) return ['error' => "HTTP状态 $code"];
                $resolved = $this->resolve_redirect_url($current_url, $location);
                if ($resolved === null) return ['error' => '重定向目标不是 http(s) 地址'];
                $err = $this->validate_remote_host($resolved);
                if ($err) return ['error' => $err];
                $current_url = $resolved;
                continue;
            }
            if ($code != 200) { if ($tmp) wp_delete_file($tmp); return ['error' => "HTTP状态 $code"]; }
            if ($is_stream && $tmp && file_exists($tmp)) {
                $sz = filesize($tmp);
                if ($sz !== false && $sz > self::SPIDER_MAX_IMAGE_BYTES) { wp_delete_file($tmp); return ['error' => '文件过大(>10MB)']; }
                if ($sz !== false && $sz < 500) { wp_delete_file($tmp); return ['error' => '文件太小(<500B)']; }
                $file_data = file_get_contents($tmp);
                wp_delete_file($tmp);
                if ($file_data === false) return ['error' => '读取下载文件失败'];
            }
            break;
        }
        if ($file_data === null) {
            // 正常 GET 路径：再按响应头 Content-Length 兜底一次（防 HEAD
            // 与 GET 不一致），超限不取 body；取到 body 后仍按实际长度
            // 验一道（传输层未走流式时的最后一道闸）。
            $clen = wp_remote_retrieve_header($res, 'content-length');
            if (is_numeric($clen) && (int)$clen > self::SPIDER_MAX_IMAGE_BYTES) {
                return ['error' => '文件过大(>10MB)'];
            }
            $file_data = wp_remote_retrieve_body($res);
            if (strlen($file_data) > self::SPIDER_MAX_IMAGE_BYTES) {
                return ['error' => '文件过大(>10MB)'];
            }
        }
        if(strlen($file_data) < 500) return ['error' => '文件太小(<500B)'];

        $filename = 'sp_' . wp_date('YmdHis') . '_' . substr($img_hash, 0, 6) . '.jpg'; 
        $content_type = wp_remote_retrieve_header($res, 'content-type');
        
        if($content_type) {
            if(strpos($content_type, 'png')!==false) $filename = str_replace('.jpg', '.png', $filename);
            elseif(strpos($content_type, 'gif')!==false) $filename = str_replace('.jpg', '.gif', $filename);
            elseif(strpos($content_type, 'webp')!==false) $filename = str_replace('.jpg', '.webp', $filename);
        }

        // v2.2.9（WP.org 审核）：文件名哈希过滤器只在插件自己的图片
        // 本地化流程内生效（Skylaipr_Turbo_Mod::enable/disable_filename_hashing），
        // 不再全局注册（见 class-skylaipr-infra.php）。
        if (class_exists('Skylaipr_Turbo_Mod')) Skylaipr_Turbo_Mod::enable_filename_hashing();
        try {
            $upload = wp_upload_bits($filename, null, $file_data);
        } finally {
            if (class_exists('Skylaipr_Turbo_Mod')) Skylaipr_Turbo_Mod::disable_filename_hashing();
        }
        if($upload['error']) return ['error' => '写入失败: ' . $upload['error']];

        $file_path = $upload['file'];
        $mime = $content_type ?: 'image/jpeg';

        if($core->get_opt('spider_wm_enable')) $this->watermark($file_path);

        $aid = wp_insert_attachment(['post_mime_type'=>$mime, 'post_title'=>'Spider Img'], $file_path, $pid);
        if (!is_wp_error($aid)) {
            $meta = wp_generate_attachment_metadata($aid, $file_path);
            wp_update_attachment_metadata($aid, $meta);
            
            update_post_meta($aid, '_skylaipr_source_hash', $img_hash);
            update_post_meta($aid, '_skylaipr_source_url', $url);
            $core->stat_inc('spider_count');
            $core->log("同步图片成功: $filename", 'info', 'Spider');
            $spider_history[$img_hash] = $aid;
            update_post_meta($pid, '_skylaipr_spider_history', $spider_history);
            return ['id' => $aid, 'status' => 'downloaded'];
        }
        return ['error' => '数据库插入失败'];
    }

    // N-A2：spider_domains（排除域名，一行一个）的解析与匹配抽成公共
    // 方法，自动同步与手动单张同步共用——之前只自动同步查，手动入口
    // ajax_spider_single 直接调 download_image，可绕过排除。
    private function get_spider_excluded_domains() {
        $core = Skylaipr_Core::instance();
        $excluded_domains = [];
        $domains_raw = trim((string)$core->get_opt('spider_domains', ''));
        if ($domains_raw !== '') {
            foreach (explode("\n", $domains_raw) as $line) {
                $d = strtolower(trim($line));
                if ($d === '') continue;
                if (strpos($d, '://') !== false) $d = (string)wp_parse_url($d, PHP_URL_HOST);
                $d = strtolower(trim(explode('/', $d)[0]));
                $d = ltrim($d, '*.');
                $d = ltrim($d, '.');
                if (strpos($d, ':') !== false) $d = explode(':', $d)[0];
                if ($d !== '') $excluded_domains[] = $d;
            }
            $excluded_domains = array_values(array_unique($excluded_domains));
        }
        return $excluded_domains;
    }

    private function is_host_spider_excluded($host, $excluded_domains) {
        if ($host === '' || empty($excluded_domains)) return false;
        foreach ($excluded_domains as $ed) {
            if ($host === $ed || substr($host, -strlen($ed) - 1) === '.' . $ed) return true;
        }
        return false;
    }

    // 手动单张同步的入口检查：URL 的 host 精确命中或为排除域名的子域名时跳过。
    public function is_spider_excluded_url($url) {
        $host = strtolower((string)(wp_parse_url((string)$url, PHP_URL_HOST) ?: ''));
        return $this->is_host_spider_excluded($host, $this->get_spider_excluded_domains());
    }

    // C1：正文里图片候选 URL 的提取（src / data-src / srcset / video
    // poster），去重。auto_spider 与 find_external_images 共用同一份
    // 提取，扫描看到的与自动同步处理的是同一批 URL。
    private function collect_image_candidates($content) {
        $candidates = [];
        preg_match_all('/(?:src|data-src)=[\'"]([^\'"]+)[\'"]/i', $content, $m);
        if (!empty($m[1])) $candidates = array_merge($candidates, $m[1]);
        preg_match_all('/srcset=[\'"]([^\'"]+)[\'"]/i', $content, $ms);
        if (!empty($ms[1])) {
            foreach ($ms[1] as $srcset) {
                foreach (explode(',', $srcset) as $cand) {
                    $url = trim(explode(' ', trim($cand))[0]);
                    if ($url !== '') $candidates[] = $url;
                }
            }
        }
        preg_match_all('/poster=[\'"]([^\'"]+)[\'"]/i', $content, $mp);
        if (!empty($mp[1])) $candidates = array_merge($candidates, $mp[1]);
        return array_values(array_unique($candidates));
    }

    // C1：已同步到 COS 的图片 host 列表（自定义域名与 bucket.endpoint），
    // 与 auto_spider 原来的内联逻辑一致，抽出来供扫描共用。
    private function get_oss_hosts() {
        $core = Skylaipr_Core::instance();
        $oss_hosts = [];
        if ($core->get_opt('oss_enable')) {
            $oss_domain = trim((string)$core->get_opt('oss_domain'));
            if ($oss_domain !== '') {
                $h = wp_parse_url($oss_domain, PHP_URL_HOST);
                if (!$h) $h = preg_replace('#^https?://#i', '', $oss_domain);
                $h = strtolower(trim(explode('/', $h)[0]));
                if ($h !== '') $oss_hosts[] = $h;
            }
            $bucket = trim((string)$core->get_opt('oss_bucket'));
            $endpoint = trim((string)$core->get_opt('oss_endpoint'));
            if ($bucket !== '' && $endpoint !== '') $oss_hosts[] = strtolower($bucket . '.' . $endpoint);
        }
        return $oss_hosts;
    }

    // C1：判断一个 URL 是否为需同步的外链图。复用 auto_spider 的同一套
    // 排除逻辑（本站域名子串、data URI、COS host、spider_domains 排除域
    // 名），不另写一套 host 判断——扫描与自动同步的口径必须一致。
    private function is_external_image_url($url, $site_domain, $oss_hosts, $excluded_domains) {
        if (strpos($url, $site_domain) !== false || strpos($url, 'data:image') !== false) return false;
        $url_host = strtolower(wp_parse_url($url, PHP_URL_HOST) ?: '');
        if ($url_host !== '' && in_array($url_host, $oss_hosts, true)) return false;
        if ($this->is_host_spider_excluded($url_host, $excluded_domains)) return false;
        return true;
    }

    // C1：返回正文里全部外链图片 URL（去重）。本站图、data URI、COS 图、
    // 排除域名的图都不算外链。
    public function find_external_images($content) {
        $candidates = $this->collect_image_candidates($content);
        if (empty($candidates)) return [];
        $site_domain = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $oss_hosts = $this->get_oss_hosts();
        $excluded_domains = $this->get_spider_excluded_domains();
        $out = [];
        foreach ($candidates as $url_raw) {
            $url = html_entity_decode($url_raw);
            if (!$this->is_external_image_url($url, $site_domain, $oss_hosts, $excluded_domains)) continue;
            $out[] = $url;
        }
        return array_values(array_unique($out));
    }

    // C1：只读扫描全站已发布文章，列出含外链图的文章（标题、外链图张
    // 数、涉及域名）。只读：不下载、不改正文、不写任何 meta。清单最多
    // 列 SCAN_MAX_ITEMS 篇，超出时 truncated=true（先处理完这批再重扫，
    // 已同步的文章下次扫描即掉出清单）。
    // C1：只读扫描全站已发布文章，列出含外链图的文章（标题、外链图张
    // 数、涉及域名）。只读：不下载、不改正文、不写任何 meta。清单最多
    // 列 SCAN_MAX_ITEMS 篇，超出时 truncated=true（先处理完这批再重扫，
    // 已同步的文章下次扫描即掉出清单）。
    // v2.2.8：分页扫描（WebP scanPaged 同款）：每次只取一页
    // （offset/page_size），页内超 SCAN_TIME_BUDGET（20 秒）即停，返回
    // done=false、offset=下一未处理位置，客户端按 offset 续扫。旧单次
    // 扫描全站 3640 篇、无预算，超 EdgeOne 60 秒回源超时即“网络错误”。
    // $already 是客户端已累积的清单篇数，本页只补足 SCAN_MAX_ITEMS 的
    // 剩余名额（计数仍全量，清单封顶）。
    public function scan_external_images($offset = 0, $page_size = self::SCAN_PAGE_SIZE, $already = 0) {
        $offset = max(0, (int)$offset);
        $page_size = min(2000, max(1, (int)$page_size));
        $already = max(0, (int)$already);
        // total：一次廉价 COUNT，不拉全表（WebP 扫描同款），供客户端
        // 显示“已扫 X/Y 篇”与判断 done。
        global $wpdb;
        $total_known = isset($wpdb) && method_exists($wpdb, 'get_var');
        $total = 0;
        if ($total_known) {
            $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
        }
        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $page_size,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) $ids = [];
        $items = [];
        $affected = 0;
        $total_images = 0;
        $truncated = false;
        $start = microtime(true);
        $scanned = 0;
        $room = max(0, self::SCAN_MAX_ITEMS - $already);
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下，剩下的文章客户端按 offset 续扫，不丢。
            if (microtime(true) - $start > self::SCAN_TIME_BUDGET) break;
            $scanned++;
            $post = get_post((int)$id);
            if (!$post) continue;
            $urls = $this->find_external_images($post->post_content ?? '');
            if (empty($urls)) continue;
            $affected++;
            $total_images += count($urls);
            if (count($items) >= $room) { $truncated = true; continue; }
            $domains = [];
            foreach ($urls as $u) {
                $h = strtolower(wp_parse_url($u, PHP_URL_HOST) ?: '');
                if ($h !== '') $domains[] = $h;
            }
            $items[] = [
                'id' => (int)$id,
                'title' => $post->post_title,
                'count' => count($urls),
                'domains' => array_values(array_unique($domains)),
            ];
        }
        $next_offset = $offset + $scanned;
        if ($scanned < count($ids)) {
            // 页内超时：本页没扫完，一定还有下一页。
            $done = false;
        } elseif ($total_known) {
            $done = $next_offset >= $total;
        } else {
            // 无 COUNT 可用（仅测试替身会出现）：页未满即最后一页；页满
            // 则假设还有下一页，客户端下次拿到空页即结束。total 取下界。
            $done = count($ids) < $page_size;
            $total = $next_offset;
        }
        return [
            'total' => $total,
            'total_scanned' => $next_offset,
            'scanned' => $scanned,
            'total_posts' => $affected,
            'total_images' => $total_images,
            'truncated' => $truncated,
            'items' => $items,
            'done' => $done,
            'offset' => $next_offset,
        ];
    }

    // C-5：spider_enable 关闭时的统一提示（批量与编辑器单张共用）。
    private function spider_disabled_message($action) {
        return '内容同步已关闭，请在内容同步页开启‘启用内容同步’后再' . $action . '。';
    }

    // F-11(b)：直接写库（$wpdb->update）绕过 wp_update_post，
    // Skylaipr_Redis_Mod::smart_flush() 收不到，Redis 页面缓存里的
    // 旧 slug/摘要/正文要等 TTL 才过期——写库后显式清一次本篇。
    // smart_flush($new_status, $old_status, $post) 的全清分支只在
    // 新/旧任一为 publish 且 redis_smart_purge 开时触发（清全部
    // page_ 前缀）；此处只想清本篇、不全清，故传非 publish 状态走
    // 其退化路径（只清首页 + 本篇）。Redis 模块不存在时跳过（如
    // 单元测试未加载 infra.php）。
    private function flush_page_cache_for_post($pid) {
        if (!class_exists('Skylaipr_Redis_Mod') || !method_exists('Skylaipr_Redis_Mod', 'smart_flush')) return;
        $post = get_post((int)$pid);
        if (!is_object($post)) $post = (object)['ID' => (int)$pid, 'post_type' => 'post'];
        if (self::$redis_mod === null) self::$redis_mod = new Skylaipr_Redis_Mod();
        self::$redis_mod->smart_flush('draft', 'draft', $post);
    }

    // C1：分批同步外链图片（每批 SPIDER_BATCH_SIZE 篇，服务端定死）。
    // 复用 auto_spider()（含下载、写库、_skylaipr_spider_last 结果 meta，
    // 与 D2 看板同一口径），不重写下载逻辑。返回每篇结果；锁被占的篇
    // 返回 skipped（不覆盖上次结果），不存在的篇返回 failed。
    public function batch_spider_posts(array $ids) {
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, self::SPIDER_BATCH_SIZE);
        if (empty($ids)) return ['results' => [], 'stopped_early' => false];
        // C-5：spider_enable 是主开关——关时批量不跑，逐篇返回明确
        // 原因（经 ajax_spider_batch 的 wp_send_json_success 原样回
        // 前台，界面逐条展示），不要静默空跑。
        if (!Skylaipr_Core::instance()->get_opt('spider_enable', true)) {
            $msg = $this->spider_disabled_message('批量同步');
            $results = [];
            foreach ($ids as $id) {
                $row = ['id' => $id, 'status' => 'failed', 'reason' => $msg];
                $post = get_post($id);
                if ($post) $row['title'] = $post->post_title;
                $results[] = $row;
            }
            return ['results' => $results, 'stopped_early' => false];
        }
        $results = [];
        $stopped_early = false;
        $start = microtime(true);
        // v2.2.8：单篇截止点 = 本批开始时间 + 预算；传给 auto_spider，
        // 单篇下载超时前停下（见 auto_spider 的 $deadline），避免单篇
        // 慢图把整批拖过 EdgeOne 60 秒回源超时。
        $deadline = $start + self::SPIDER_BATCH_TIME_BUDGET;
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下，剩下的 ID 客户端下一批重发（与 WebP
            // 批量同款）。
            if (microtime(true) - $start > self::SPIDER_BATCH_TIME_BUDGET) { $stopped_early = true; break; }
            $post = get_post($id);
            if (!$post) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => '文章不存在'];
                continue;
            }
            // auto_spider() 返回 null = 锁被占（10 分钟内已有任务在跑），
            // 按跳过记，不覆盖上次的 _skylaipr_spider_last。批量是手动路径，
            // 传 $manual=true：只查 spider_enable，不查 spider_auto
            // （手动模式下批量本来就要能跑）。
            $res = $this->auto_spider($id, true, $deadline);
            if ($res === null) {
                $results[] = ['id' => $id, 'status' => 'skipped', 'title' => $post->post_title, 'reason' => '正在同步中，稍后再试'];
                continue;
            }
            // v2.2.8：单篇撞到截止点（partial）：已同步的部分已写库，
            // 但本篇不算做完——不放进 results，客户端下一批重发本篇
            // 续跑（results 长度即客户端前进的步数，见 admin.js）。
            if (!empty($res['partial'])) {
                $stopped_early = true;
                break;
            }
            // v2.2.1：一张都没同步成功时记 failed 而不是 done，并带首个
            // 失败原因（如“HTTP状态 404”）；否则失败的篇在界面上看不出。
            $all_failed = (int)$res['success'] === 0 && (int)$res['failed'] > 0;
            $row = [
                'id' => $id,
                'status' => $all_failed ? 'failed' : 'done',
                'title' => $post->post_title,
                'success' => (int)$res['success'],
                'failed' => (int)$res['failed'],
                'skipped' => (int)$res['skipped'],
            ];
            if ($all_failed) {
                $err = (string)($res['first_error'] ?? '');
                $row['reason'] = $err !== '' ? ((int)$res['failed'] . ' 张图片下载失败（' . $err . '）') : ((int)$res['failed'] . ' 张图片下载失败');
            }
            $results[] = $row;
        }
        return ['results' => $results, 'stopped_early' => $stopped_early];
    }

    // $manual=true 时是用户在后台点的批量同步（手动路径）：只查
    // spider_enable，不查 spider_auto——手动模式（spider_auto 关）
    // 下批量同步本来就要能跑（批量复用本方法；线上当前就是手动
    // 模式）。cron/发布触发的自动路径默认 $manual=false，两个开关
    // 任一关即不跑。
    // v2.2.8：$deadline（批量路径传入，微秒时间戳）：单篇下载的墙钟
    // 截止点。到点即停，剩下的图由批量客户端重发本篇续跑，返回
    // partial=true；到点前已同步的图照常写库。自动路径（cron/发布）
    // 不传 deadline，不受影响。
    public function auto_spider($pid, $manual = false, $deadline = null) {
        // C-5/F-12：主开关语义——spider_enable 关，或自动路径下
        // spider_auto 关，直接返回，不做任何事（不抢锁、不下载、
        // 不写 meta）、不重排续跑。已排期的事件在管理员关开关后
        // 触发时也到此为止。
        $core = Skylaipr_Core::instance();
        if (!$core->get_opt('spider_enable', true)) return null;
        if (!$manual && !$core->get_opt('spider_auto', true)) return null;

        $post = get_post($pid);
        if (!$post) return null;

        // R4：锁改存时间戳、10 分钟过期——fatal/超时/OOM 后锁不会永久锁死
        // 该文章，过期锁可被下一次任务抢占。try/finally 保证任何返回路径
        // （含 download_image 内抛 Error）都会清锁。旧版锁值是 1（无时间戳），
        // 无法判断新旧，一律视为过期、可抢占，避免升级前一次崩溃留下的锁
        // 永久锁死该文章。
        $lock = get_post_meta($pid, '_skylaipr_spider_processing', true);
        if ($lock !== '' && $lock !== false && $lock !== null
            && is_numeric($lock) && (int)$lock > 1000000000 && time() - (int)$lock < 600) {
            return null;
        }
        update_post_meta($pid, '_skylaipr_spider_processing', time());

        try {
        $content = $post->post_content;
        // C1：提取与外链判断抽成公共方法（collect_image_candidates /
        // is_external_image_url），扫描与自动同步共用同一套口径；下面
        // 的计数与写库逻辑与原来完全一致。
        $candidates = $this->collect_image_candidates($content);
        // D2：无外链图也记一次结果（成功/失败/跳过均为 0），否则看板
        // 永远显示“未同步”，分不清“没跑过”和“跑过但无图可同步”。
        if(empty($candidates)) {
            update_post_meta($pid, '_skylaipr_spider_last', ['success' => 0, 'failed' => 0, 'skipped' => 0, 'time' => time()]);
            return ['success' => 0, 'failed' => 0, 'skipped' => 0, 'first_error' => '', 'partial' => false];
        }

        $site_domain = wp_parse_url(get_site_url(), PHP_URL_HOST);
        $success = 0;
        // D2：失败/跳过计数，与 $success 一起写进 _skylaipr_spider_last，
        // 供看板最近文章处理状态表直接读。
        $failed = 0;
        $skipped = 0;
        // v2.2.1：首个下载失败原因（download_image 失败时恒带 error），
        // 供 batch_spider_posts 在“全部失败”时展示。
        $first_error = '';

        $max_img = intval($core->get_opt('spider_max_img', 20));
        $processed_count = 0;

        // 已同步到 COS 的图片不再当作外站图：否则每次保存文章都会把 COS 图重新
        // 下载回本地、重新上传 COS，白白再走一遍“上传→删本地”循环。
        $oss_hosts = $this->get_oss_hosts();

        $has_more = false;
        // v2.2.8：批量路径的墙钟截止点是否撞到（见下面循环内的检查）。
        $deadline_hit = false;
        // M1：spider_domains（排除域名，一行一个）真正生效：按行解析，
        // 图片 URL 的 host 精确命中或为其子域名时跳过、不同步。
        // 解析与匹配见 get_spider_excluded_domains() / is_host_spider_excluded()，
        // 与手动单张同步共用同一份逻辑（N-A2）。
        $excluded_domains = $this->get_spider_excluded_domains();
        foreach($candidates as $url_raw) {
            if ($processed_count >= $max_img) { $has_more = true; break; }
            // v2.2.8：批量路径的墙钟截止点：到点即停，剩下的图由批量
            // 客户端重发本篇续跑（返回 partial，见 batch_spider_posts）。
            // 这里不置 $has_more，避免同时重排异步任务导致重复跑。
            if ($deadline !== null && microtime(true) > $deadline) { $deadline_hit = true; break; }

            $url_real = html_entity_decode($url_raw);
            // D2：本站图/data URI、COS 图、排除域名的图都不用同步，记跳过
            // （不占单篇上限名额）。判断走 is_external_image_url，与 C1
            // 扫描同一套口径。
            if (!$this->is_external_image_url($url_real, $site_domain, $oss_hosts, $excluded_domains)) { $skipped++; continue; }
            
            $res = $this->download_image($url_real, $pid);
            if(isset($res['id'])) {
                $local_url = wp_get_attachment_url($res['id']);
                $content = str_replace($url_raw, $local_url, $content);
                $success++;
            } else {
                // D2：download_image 没返回 id 即本次下载失败（网络错误 /
                // HTTP 状态 / 文件过大过小等，原因见日志），记失败数。
                $failed++;
                if ($first_error === '' && is_array($res) && isset($res['error'])) $first_error = (string)$res['error'];
            }
            $processed_count++;
        }
        
        if($success > 0 && $content !== $post->post_content) {
            remove_action('save_post', [$this, 'trigger_async_tasks'], 20);
            global $wpdb;
            // M6：直接写库替换正文前先留一个修订版，同步改坏正文时可恢复。
            if (function_exists('wp_save_post_revision')) {
                $rev = wp_save_post_revision((int)$pid);
                if ($rev === false) $core->log("图片同步写库前留修订版失败 [$pid]，本次替换没有修订版备份", 'warn', 'Spider');
            }
            $wpdb->update($wpdb->posts, ['post_content'=>$content], ['ID'=>(int)$pid]);
            clean_post_cache($pid);
            // F-11(b)：直接写库后显式清一次本篇页面缓存（见 flush_page_cache_for_post）。
            $this->flush_page_cache_for_post($pid);
            add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        }

        // R16：超上限的剩余图片重排一次异步任务续跑——下次运行时已同步的
        // URL 已是本地/COS 地址会被跳过，只处理剩下的，不再永久漏掉。
        if ($has_more && !wp_next_scheduled('skylaipr_async_spider', [$pid])) {
            wp_schedule_single_event(time() + 30, 'skylaipr_async_spider', [$pid]);
            $core->log("图片同步：单篇超过 {$max_img} 张上限，剩余图片已重排续跑 [$pid]", 'info', 'Spider');
        }

        // D2：记最近一次同步结果（成功/失败/跳过数 + 时间），供看板
        // 最近文章处理状态表直接读，不再翻日志核对。超上限续跑时本次
        // 只记已跑完的部分，下次跑完会覆盖成本次完整结果。
        update_post_meta($pid, '_skylaipr_spider_last', ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'time' => time()]);
        return ['success' => $success, 'failed' => $failed, 'skipped' => $skipped, 'first_error' => $first_error, 'partial' => $deadline_hit];
        } finally {
            delete_post_meta($pid, '_skylaipr_spider_processing');
        }
    }

    // 资源类判定（v2.2.11，未发布）：与 mu-plugin lingganwu-miniapp.php 的
    // lingganwu_resource_field() 同口径——子比付费资源（post meta
    // posts_zibpay，付费阅读 pay_type=1 不算）且带下载链接（pay_download
    // 非空，兼容新版数组与旧版“链接|名称|更多”换行文本）才算资源。
    // 供 auto_seo_meta() 在资源类文章上换用资源版摘要/标签提示词。
    private function is_resource_post($pid) {
        $mate = get_post_meta((int)$pid, 'posts_zibpay', true);
        if (!is_array($mate)) return false;
        if ((string)($mate['pay_type'] ?? '') === '1') return false;
        $raw = $mate['pay_download'] ?? null;
        $items = [];
        if (is_array($raw)) {
            $items = $raw;
        } elseif (is_string($raw) && trim($raw) !== '') {
            foreach (preg_split('/\r\n|\n/', $raw) as $line) {
                $parts = explode('|', $line);
                if (!empty($parts[0])) $items[] = ['link' => trim($parts[0])];
            }
        }
        foreach ($items as $it) {
            if (is_array($it) && !empty($it['link'])) return true;
            if (is_string($it) && trim($it) !== '') return true;
        }
        return false;
    }

    // S1：批量补全只跑缺的项（$only 如 ['slug','excerpt']），已有项
    // 跳过不重算——手动设好的英文 slug、已有摘要/标签一律不动（S4 的
    // 保护就落在这里：slug 只在缺失时才进 $only）。$only 为 null 时
    // 保持老行为（全部开关项都跑，供保存时异步任务调用）。
    // 返回 null = 未跑（文章不存在 / 已标记完成 / 对应开关全关）；
    // 否则返回 ['attempted'=>[...], 'succeeded'=>[...], 'failed'=>[项=>原因]]，
    // 供批量引擎按项判定 done/partial/failed，并把失败原因展示出来。
    // $reschedule_on_fail：全部失败时是否按 S4 附录约 1 小时后重试。
    // 保存/定时触发的调用保持默认 true；S1 批量调用传 false——批量
    // 是只跑缺项的，失败后约一次“全量”重试会把已有好 slug 也重算掉，
    // 违背“已有项不重算”；失败的篇留在批量结果里由用户决定是否重跑。
    public function auto_seo_meta($pid, $only = null, $reschedule_on_fail = true) {
        $post = get_post($pid);
        if (!$post) return null;
        
        if (get_post_meta($pid, '_skylaipr_seo_done', true)) return null;

        $core = Skylaipr_Core::instance();
        $do_tags = $core->get_opt('auto_tags');
        $do_slug = $core->get_opt('auto_slug');
        $do_excerpt = $core->get_opt('auto_excerpt');
        $do_polish = $core->get_opt('auto_polish');

        if (!$do_tags && !$do_slug && !$do_excerpt && !$do_polish) return null;
        if ($only !== null) {
            $only = array_values(array_intersect((array)$only, ['tags', 'slug', 'excerpt', 'polish']));
            // 只要缺的项、对应开关却全关着：直接返回，不跑、也不标记完成
            // （标记完成会让批量误以为这篇已处理完）。
            $want = ['tags' => $do_tags, 'slug' => $do_slug, 'excerpt' => $do_excerpt, 'polish' => $do_polish];
            $any_wanted = false;
            foreach ($only as $it) { if (!empty($want[$it])) { $any_wanted = true; break; } }
            if (!$any_wanted) return null;
        }
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $updates = [];
        $any_success = false;
        $any_attempted = false;
        $attempted = [];
        $succeeded = [];
        // v2.2.1：逐项失败原因（S1 批量要在界面上逐篇说明失败/跳过原因，
        // 不能只写“补全失败”）。WP_Error 的 message 经 call_api 里的
        // extract_api_error_message() 清洗过，不含密钥与完整响应体，
        // 可直接展示；非 WP_Error 的失败（如 Slug 清洗后为空）给短说明。
        $failed = [];
        $failed_reason = function ($res, $fallback) {
            if (is_wp_error($res)) return $res->get_error_message();
            return $fallback;
        };
        global $wpdb;

        // 资源类判定（v2.2.11）：与 mu-plugin 的 lingganwu_resource_field()
        // 同口径；普通文章 $is_resource 为 false，沿用原提示词。
        $is_resource = $this->is_resource_post($pid);

        if ($do_polish && ($only === null || in_array('polish', $only, true))) {
            $attempted[] = 'polish';
            // 之前只把前 3000 字发给 AI，却用返回结果替换全文，3000 字以后的正文
            // 会被直接丢掉，且写库绕过 wp_update_post、不产生修订版，丢了找不回来。
            $full_content = $post->post_content;
            $content_len = mb_strlen($full_content);
            $polished = false;

            // R9：含 Gutenberg 区块标记（<!-- wp: -->）的文章跳过全文润色——
            // AI 返回的纯 Markdown 不含区块注释，直接写库会抹掉区块结构，
            // 文章退化为经典内容。跳过并记日志，不动正文。
            $skip_polish_blocks = strpos($full_content, '<!-- wp:') !== false;
            if ($skip_polish_blocks) {
                $core->log("AI 润色已跳过 [$pid]：正文含 Gutenberg 区块标记，全文替换会抹掉区块结构", 'info', 'SEO');
            }

            if (!$skip_polish_blocks && $content_len <= 3000) {
                $prompt = "请修正以下文章内容的错别字、标点符号错误和语病，保持原意不变，直接返回修正后的 Markdown 内容：\n\n" . $full_content;
                $any_attempted = true;
                $res = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
                // R2：失败的标志是 WP_Error，不再全文搜 "Error"——含 Error 一词
                // 的合法润色文不再被误判丢弃。
                if ($res && !is_wp_error($res) && strlen($res) > 100) $polished = $res;
                else $failed['polish'] = $failed_reason($res, 'AI 返回的润色结果过短或为空');
            } elseif (!$skip_polish_blocks) {
                // 全文超过 3000 字：按 3000 字分段润色，全部成功才拼接替换；
                // 任一段失败则不替换正文（只记日志），避免用半截结果覆盖全文。
                $chunks = [];
                for ($offset = 0; $offset < $content_len; $offset += 3000) {
                    $chunks[] = mb_substr($full_content, $offset, 3000);
                }
                $polished_parts = [];
                $polish_failed = false;
                foreach ($chunks as $chunk) {
                    $prompt = "请修正以下文章内容的错别字、标点符号错误和语病，保持原意不变，直接返回修正后的 Markdown 内容：\n\n" . $chunk;
                    $any_attempted = true;
                    $part = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
                    if (!$part || is_wp_error($part) || strlen($part) < 10) { $polish_failed = true; $failed['polish'] = $failed_reason($part, 'AI 返回的分段润色结果过短或为空'); break; }
                    $polished_parts[] = $part;
                }
                if (!$polish_failed && !empty($polished_parts)) {
                    $polished = implode('', $polished_parts);
                } else {
                    $core->log("AI 润色分段失败 [$pid]，已跳过正文替换", 'warn', 'SEO');
                }
            }

            if ($polished !== false) {
                // 替换前先留一个修订版，润色结果异常时可从修订版恢复。
                // L4：检查返回值，返回 false 时记 warn 日志——说明本次替换
                // 没有修订版备份（站点禁用修订版或文章类型不支持时）。
                if (function_exists('wp_save_post_revision')) {
                    $rev = wp_save_post_revision((int)$pid);
                    if ($rev === false) $core->log("AI 润色替换前留修订版失败 [$pid]，本次替换没有修订版备份", 'warn', 'SEO');
                }
                $updates['post_content'] = $polished;
                $any_success = true;
                $succeeded[] = 'polish';
                $core->log("AI 润色成功 [$pid]", 'info', 'SEO');
            }
        }
        if ($do_tags && ($only === null || in_array('tags', $only, true))) {
            $attempted[] = 'tags';
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            // 资源类：提资源主题词（资源类型、设计主题、适用场景、文件类型），
            // 禁用“zip下载、大文件、文件压缩、下载、资源下载”这类通用词。
            $prompt = $is_resource
                ? "以下是一篇景观设计资源下载文章（标题：" . $post->post_title . "）。请基于内容提取5个资源主题词作为SEO标签，如资源类型、设计主题、适用场景、文件类型，用逗号分隔，不要其他文字和编号。不要用“zip下载、大文件、文件压缩、下载、资源下载”这类通用词：\n\n" . mb_substr(wp_strip_all_tags($text), 0, 1500)
                : "请基于以下内容提取5个核心SEO标签，用逗号分隔，不要其他文字和编号：\n\n" . mb_substr(wp_strip_all_tags($text), 0, 1500);
            $any_attempted = true;
            $tags = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
            if ($tags && !is_wp_error($tags)) { wp_set_post_tags($pid, str_replace(['，', '、'], ',', $tags), true); $any_success = true; $succeeded[] = 'tags'; }
            else { $failed['tags'] = $failed_reason($tags, 'API 未返回标签'); }
        }
        if ($do_slug && ($only === null || in_array('slug', $only, true))) {
            $attempted[] = 'slug';
            $prompt = "Generate a concise English URL slug for this title (max 5-8 words). STRICTLY OUTPUT ONLY THE SLUG (lowercase, hyphens). Title: " . $post->post_title;
            $any_attempted = true;
            // N-D2：slug 要求只输出英文，system 人设里的“中文回答”会与之
            // 冲突——这类纯英文输出任务不拼 system 消息（call_api 第 3 参数）。
            $slug = $core->call_api([['role'=>'user', 'content'=>$prompt]], 0.7, false);
            if ($slug && !is_wp_error($slug)) {
                $slug = sanitize_title(wp_strip_all_tags(preg_replace('/^(Here.*?:\s*)/i', '', $slug)));
                if ($slug) { $updates['post_name'] = substr(trim(preg_replace('/-+/', '-', $slug), '-'), 0, 60); $any_success = true; $succeeded[] = 'slug'; }
                else { $failed['slug'] = 'AI 返回的 Slug 为空或无效'; }
            } else {
                $failed['slug'] = $failed_reason($slug, 'API 未返回 Slug');
            }
        }
        if ($do_excerpt && ($only === null || in_array('excerpt', $only, true)) && empty($post->post_excerpt)) {
            $attempted[] = 'excerpt';
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            // 资源类：按“资源名称 + 内容亮点 + 适用人群 + 规模大小”写摘要，
            // 不要写成通用下载说明（如文件压缩、解压、传输、节省空间）。
            $prompt = $is_resource
                ? "以下是一篇景观设计资源下载文章（标题：" . $post->post_title . "）。请按“资源名称 + 内容亮点 + 适用人群 + 规模大小”写一段120字左右的中文SEO摘要，直接输出摘要，不要其他文字。不要写成通用下载说明（如文件压缩、解压、传输、节省空间）：\n\n" . mb_substr(wp_strip_all_tags($text), 0, 1500)
                : "请为以下内容生成一段 120 字左右的中文 SEO 摘要，直接输出摘要，不要其他文字：\n\n" . mb_substr(wp_strip_all_tags($text), 0, 1500);
            $any_attempted = true;
            $excerpt = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
           if ($excerpt && !is_wp_error($excerpt)) {
                // F-11(c)：提示词“120 字左右”与截断上限一致（150 字，
                // 允许模型略超；旧写法提示词按英文词数、截断按字符数，
                // 中英文单位混用）。
                $updates['post_excerpt'] = trim(mb_substr(wp_strip_all_tags($excerpt), 0, 150));
                $any_success = true;
                $succeeded[] = 'excerpt';
            } else {
                $failed['excerpt'] = $failed_reason($excerpt, 'API 未返回摘要');
            }
        }

        if (!empty($updates)) {
            $wpdb->update($wpdb->posts, $updates, ['ID' => (int)$pid]);
            clean_post_cache($pid);
            // F-11(b)：直接写库后显式清一次本篇页面缓存（见 flush_page_cache_for_post）。
            $this->flush_page_cache_for_post($pid);
        }

        // R5（v2.2.3 F-11）：只有本次请求的所有尝试项都成功才标记
        // _skylaipr_seo_done；部分成功时不置——失败项留待下次保存重试
        // （旧写法任一项成功即置完成，失败项永久不再重试）。全部失败
        // 时沿用旧规则：失败计数 +1，满 3 次标记完成、停止重试，否
        // 则 1 小时后重排。无任何尝试（如只要摘要但摘要已存在）时直
        // 接标记完成，不算失败。
        $all_succeeded = $any_attempted && empty($failed);
        if ($all_succeeded || !$any_attempted) {
            update_post_meta($pid, '_skylaipr_seo_done', 1);
            delete_post_meta($pid, '_skylaipr_seo_fail_count');
        } elseif (!empty($succeeded)) {
            // 部分成功：不标记完成、不计失败次数，失败项下次保存重试。
            $core->log("自动 SEO 部分成功 [$pid]（成功：" . implode('、', $succeeded) . "；失败：" . implode('、', array_keys($failed)) . "），未标记完成，下次保存时重试失败项", 'info', 'SEO');
        } else {
            $fails = (int)get_post_meta($pid, '_skylaipr_seo_fail_count', true) + 1;
            update_post_meta($pid, '_skylaipr_seo_fail_count', $fails);
            $core->log("自动 SEO 全部失败 [$pid]（第 {$fails} 次），未标记完成，下次保存时重试", 'warn', 'SEO');
            if ($fails >= 3) {
                update_post_meta($pid, '_skylaipr_seo_done', 1);
                $core->log("自动 SEO 连续失败满 3 次 [$pid]，已停止重试", 'warn', 'SEO');
            } else {
                // S4 附录：失败后 1 小时重排期重试，与 Zero-Disk 的
                // reschedule_purge() 同款模式（上限 3 次——_skylaipr_seo_fail_count
                // 满 3 次即标记完成、上面分支不再排期）。只靠“下次保存再
                // 触发”时，定时任务发布的文章发布后无人再保存，一次瞬时
                // API 失败就等于永久停摆。批量调用传 $reschedule_on_fail=false，
                // 不约全量重试（见方法头注释）。
                if ($reschedule_on_fail && !wp_next_scheduled('skylaipr_async_seo', [(int)$pid])) {
                    wp_schedule_single_event(time() + 3600, 'skylaipr_async_seo', [(int)$pid]);
                    $core->log("自动 SEO 全部失败 [$pid]（第 {$fails} 次），已重排 1 小时后重试", 'warn', 'SEO');
                }
            }
        }

        return ['attempted' => array_values(array_unique($attempted)), 'succeeded' => array_values(array_unique($succeeded)), 'failed' => $failed];
    }

    // v2.2.1：SEO 缺项的中文名（批量结果的原因文案用）。
    public static function seo_item_label($item) {
        $labels = ['slug' => 'Slug', 'excerpt' => '摘要', 'tags' => '标签', 'polish' => '润色'];
        return $labels[$item] ?? $item;
    }

    // v2.2.1：把 auto_seo_meta() 的 failed 明细拼成一行原因文案，
    // 如“摘要：API Error (401)：…；标签：API 未返回标签”。没有明细
    // 时给带原因的兜底，界面永不只显示裸的“补全失败”。
    private function seo_failed_reason(array $failed_map, array $items) {
        $parts = [];
        foreach ($items as $it) {
            $parts[] = self::seo_item_label($it) . '：' . ($failed_map[$it] ?? '补全失败（未返回原因）');
        }
        return implode('；', $parts);
    }

    // v2.2.1：跳过原因拆细——auto_seo_meta() 返回 null 时，逐项看对应
    // 开关：缺的项里开关关着的列出来（如“标签、摘要开关未开启”），
    // 而不是笼统的“对应开关未开启或已完成”（批量调用前已清 _skylaipr_seo_done，
    // “已完成”基本不会发生，只作兜底）。
    private function seo_skipped_reason(array $missing) {
        $core = Skylaipr_Core::instance();
        $switch = ['tags' => 'auto_tags', 'slug' => 'auto_slug', 'excerpt' => 'auto_excerpt'];
        $off = [];
        foreach ($missing as $it) {
            $opt = $switch[$it] ?? null;
            if ($opt !== null && !$core->get_opt($opt)) $off[] = self::seo_item_label($it);
        }
        if (!empty($off)) return implode('、', $off) . '开关未开启';
        return '已标记完成或对应开关未开启';
    }

    // S1：slug 是否缺英文版。空、含中文等非 ASCII 字符、纯数字（自动生
    // 成的 ID 回退）都算缺；已手动设好的英文 slug 不算缺，批量不重写。
    public function is_slug_missing($slug) {
        $slug = trim((string)$slug);
        if ($slug === '') return true;
        if (preg_match('/[^\x00-\x7F]/', $slug)) return true;
        if (preg_match('/^\d+$/', $slug)) return true;
        return false;
    }

    // S1：这篇文章缺哪几项 SEO（缺其中任一项即列入批量清单）。返回
    // ['slug','excerpt','tags'] 的子集；三项全有返回 []。
    public function seo_missing_items($post) {
        $missing = [];
        if ($this->is_slug_missing($post->post_name ?? '')) $missing[] = 'slug';
        if (empty(trim((string)($post->post_excerpt ?? '')))) $missing[] = 'excerpt';
        $tags = function_exists('wp_get_post_tags') ? wp_get_post_tags((int)$post->ID) : [];
        if (empty($tags)) $missing[] = 'tags';
        return $missing;
    }

    // S1：只读扫描全站已发布文章，列出缺英文 slug / 缺摘要 / 缺标签的
    // 文章，清单上标清每篇缺哪几项。只读：不调 API、不改文章、不写 meta。
    // 清单最多列 SCAN_MAX_ITEMS 篇，超出时 truncated=true。
    // S1：只读扫描全站已发布文章，列出缺英文 slug / 缺摘要 / 缺标签的
    // 文章，清单上标清每篇缺哪几项。只读：不调 API、不改文章、不写 meta。
    // 清单最多列 SCAN_MAX_ITEMS 篇，超出时 truncated=true。
    // v2.2.8：分页扫描（与 scan_external_images 同款）：每次只取一页，
    // 页内超 SCAN_TIME_BUDGET（20 秒）即停，返回 done=false、offset=
    // 下一未处理位置，客户端按 offset 续扫。旧单次扫描全站 3640 篇、
    // 无预算，超 EdgeOne 60 秒回源超时即“网络错误”。
    public function scan_seo_missing($offset = 0, $page_size = self::SCAN_PAGE_SIZE, $already = 0) {
        $offset = max(0, (int)$offset);
        $page_size = min(2000, max(1, (int)$page_size));
        $already = max(0, (int)$already);
        global $wpdb;
        $total_known = isset($wpdb) && method_exists($wpdb, 'get_var');
        $total = 0;
        if ($total_known) {
            $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'post' AND post_status = 'publish'");
        }
        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => 'publish',
            'posts_per_page' => $page_size,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) $ids = [];
        // WebP 扫描同款：先预热 meta 缓存，避免每篇一次查库。
        if (function_exists('update_postmeta_cache')) update_postmeta_cache($ids);
        if (function_exists('update_object_term_cache')) update_object_term_cache($ids, 'post');
        $items = [];
        $affected = 0;
        $truncated = false;
        $start = microtime(true);
        $scanned = 0;
        $room = max(0, self::SCAN_MAX_ITEMS - $already);
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下，剩下的文章客户端按 offset 续扫，不丢。
            if (microtime(true) - $start > self::SCAN_TIME_BUDGET) break;
            $scanned++;
            $post = get_post((int)$id);
            if (!$post) continue;
            $missing = $this->seo_missing_items($post);
            if (empty($missing)) continue;
            $affected++;
            if (count($items) >= $room) { $truncated = true; continue; }
            $items[] = [
                'id' => (int)$id,
                'title' => $post->post_title,
                'missing' => $missing,
            ];
        }
        $next_offset = $offset + $scanned;
        if ($scanned < count($ids)) {
            $done = false;
        } elseif ($total_known) {
            $done = $next_offset >= $total;
        } else {
            $done = count($ids) < $page_size;
            $total = $next_offset;
        }
        return [
            'total' => $total,
            'total_scanned' => $next_offset,
            'scanned' => $scanned,
            'total_posts' => $affected,
            'truncated' => $truncated,
            'items' => $items,
            'done' => $done,
            'offset' => $next_offset,
        ];
    }

    // S1：分批补全历史文章 SEO（每批 $batch_size 篇，默认
    // SEO_BATCH_SIZE_DEFAULT、上限 SEO_BATCH_SIZE_MAX，服务端 clamp）。
    // 每篇只跑它缺的项（auto_seo_meta($pid, $missing)），已有项不重算；
    // _skylaipr_seo_done 会挡住重跑，缺项仍在时先清掉完成标记再跑（与 v2.1.1
    // 复活逻辑同款），否则以前处理过的文章永远补不上缺的项。返回每篇
    // 结果：done（缺项全补上）/ partial / failed / skipped。
    public function batch_seo_posts(array $ids, $batch_size = self::SEO_BATCH_SIZE_DEFAULT) {
        $batch_size = max(1, min((int)$batch_size, self::SEO_BATCH_SIZE_MAX));
        $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, $batch_size);
        if (empty($ids)) return ['results' => [], 'stopped_early' => false];
        $results = [];
        $stopped_early = false;
        $start = microtime(true);
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下，剩下的 ID 客户端下一批重发（与 WebP
            // 批量同款）。
            if (microtime(true) - $start > self::SEO_BATCH_TIME_BUDGET) { $stopped_early = true; break; }
            $post = get_post($id);
            if (!$post) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => '文章不存在'];
                continue;
            }
            $missing = $this->seo_missing_items($post);
            if (empty($missing)) {
                $results[] = ['id' => $id, 'status' => 'skipped', 'title' => $post->post_title, 'reason' => '已无缺失项'];
                continue;
            }
            if (get_post_meta($id, '_skylaipr_seo_done', true)) {
                delete_post_meta($id, '_skylaipr_seo_done');
                delete_post_meta($id, '_skylaipr_seo_fail_count');
            }
            $res = $this->auto_seo_meta($id, $missing, false);
            if ($res === null) {
                $results[] = ['id' => $id, 'status' => 'skipped', 'title' => $post->post_title, 'reason' => $this->seo_skipped_reason($missing)];
                continue;
            }
            $done_items = array_values(array_intersect($missing, $res['succeeded']));
            $still = array_values(array_diff($missing, $done_items));
            $status = empty($still) ? 'done' : (empty($done_items) ? 'failed' : 'partial');
            $row = [
                'id' => $id,
                'status' => $status,
                'title' => $post->post_title,
                'done' => $done_items,
                'missing' => $still,
            ];
            if ($status !== 'done') {
                $row['reason'] = $this->seo_failed_reason($res['failed'] ?? [], $still);
            }
            $results[] = $row;
        }
        return ['results' => $results, 'stopped_early' => $stopped_early];
    }

    private function watermark($path) {
        if(!extension_loaded('gd')) return;
        $i=@getimagesize($path); if(!$i) return;
        $im = ($i['mime']=='image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if(!$im) return;
        $txt = Skylaipr_Core::instance()->get_opt('spider_wm_text', '@Skyline');
        $color = imagecolorallocatealpha($im, 255, 255, 255, 60);
        $font_paths = [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/TTF/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ];
        $font_file = '';
        foreach ($font_paths as $fp) {
            if (file_exists($fp)) { $font_file = $fp; break; }
        }
        if ($font_file && function_exists('imagettftext')) {
            $font_size = 16;
            $bbox = imagettfbbox($font_size, 0, $font_file, $txt);
            $text_width = abs($bbox[4] - $bbox[0]);
            $x = imagesx($im) - $text_width - 10;
            $y = imagesy($im) - 10;
            imagettftext($im, $font_size, 0, max(10, $x), max(10, $y), $color, $font_file, $txt);
        } else {
            $font = 5;
            $x = imagesx($im) - (strlen($txt)*9) - 10;
            $y = imagesy($im) - 20;
            imagestring($im, $font, max(10,$x), max(10,$y), $txt, $color);
        }
        // L13：水印质量跟随 turbo_quality（旧写法写死 90，无视配置的 85）；
        // PNG 保存前 imagesavealpha(true)，透明通道不丢失。
        $quality = max(0, min(100, (int)Skylaipr_Core::instance()->get_opt('turbo_quality', 85)));
        if($i['mime']=='image/jpeg') imagejpeg($im, $path, $quality);
        else { imagesavealpha($im, true); imagepng($im, $path, 9); }
        imagedestroy($im);
    }

    public function ajax_link_now() {
        check_ajax_referer('skylaipr_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $content = wp_kses_post($content);
        if(!$content) wp_send_json_error('内容为空');
        $links_str = Skylaipr_Core::instance()->get_opt('link_pairs');
        if(!$links_str) wp_send_json_error('未配置内链关键词，请去设置页添加');
        $pairs = explode("\n", $links_str);
        $total_replaced = 0;
        // M7：不再用旧 lookahead 正则，复用 auto_internal_links() 的切分后
        // 替换逻辑（link_keyword_in_parts，两处共用），不再产出 <a><a>
        // 嵌套链接；替换串里的 title 由公共方法经 esc_attr() 转义。
        // M14③：全文只切分一次，循环关键词复用同一份切分结果。
        $parts = preg_split('/(<[^>]+>)/u', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        if (!is_array($parts)) wp_send_json_error('内容解析失败');
        foreach($pairs as $pair) {
            $p = explode('|', trim($pair));
            if(count($p) < 2) continue;
            $kw = trim($p[0]); $url = trim($p[1]);
            if(!$kw || !$url) continue;
            list($parts, $count) = $this->link_keyword_in_parts($parts, $kw, $url);
            $total_replaced += $count;
        }
        $content = implode('', $parts);
        if($total_replaced > 0) wp_send_json_success(['content' => $content, 'msg' => "成功添加 {$total_replaced} 个内链"]);
        else wp_send_json_error('没有找到可替换的关键词，或关键词已存在链接');
    }

    public function ajax_spider_single() {
        check_ajax_referer('skylaipr_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        // C-5：主开关关时编辑器单张同步也拒绝，并给明确原因。
        if (!Skylaipr_Core::instance()->get_opt('spider_enable', true)) wp_send_json_error($this->spider_disabled_message('同步图片'));
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $pid = isset($_POST['pid']) ? intval( wp_unslash( $_POST['pid'] ) ) : 0;
        if(!$url) wp_send_json_error('URL为空');
        // N-A2：手动单张同步同样遵守 spider_domains 排除（与自动同步一致）。
        if ($this->is_spider_excluded_url($url)) wp_send_json_error('该域名在排除列表中，不同步');
        $result = $this->download_image($url, $pid);
        if(isset($result['id'])) {
            $local_url = wp_get_attachment_url($result['id']);
            wp_send_json_success(['url' => $local_url, 'msg' => ($result['status'] === 'downloaded') ? '下载成功' : '秒传(复用旧图)', 'status' => $result['status']]);
        } else wp_send_json_error($result['error']);
    }

    public function ajax_seo_score() {
        check_ajax_referer('skylaipr_seo_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        $score = 100; $advice = [];
        $title = sanitize_text_field( wp_unslash( $_POST['title'] ?? '' ) );
        $content = wp_kses_post( wp_unslash( $_POST['content'] ?? '' ) );
        if(mb_strlen($title)<10) { $score-=10; $advice[]='❌ 标题过短'; }
        if(mb_strlen(wp_strip_all_tags($content))<300) { $score-=20; $advice[]='❌ 内容稀薄'; }
        if($score>=90) $advice[]='🎉 完美！';
        Skylaipr_Core::instance()->log("SEO 诊断得分: $score", 'info', 'SEO');
        wp_send_json_success(['score'=>$score, 'advice'=>$advice]);
    }

    public function add_copilot_box() {
        add_meta_box('skylaipr_copilot', '🔮 Skyline Copilot', function($post) {
            // v2.2.9：ajax 地址与 nonce 已改由 wp_localize_script() 传入
            // （skylaiprEditorData，见 __construct 里的 admin_enqueue_scripts），
            // meta box 里不再需要这几个变量。
            ?>

            <div class="sky-cp-wrap">
                <button type="button" class="sky-btn-cp primary" id="btn-spider-start" onclick="SkySpider.init()"><i>🕷️</i> 一键同步图片 (可视版)</button>
                <div id="sky-spider-status">
                    <div class="sky-sp-header">
                        <span>进度: <span id="sp-cur">0</span>/<span id="sp-total">0</span></span>
                        <span id="sp-msg" style="color:#6366f1;">就绪</span>
                    </div>
                    <div class="sky-sp-progress-bg"><div class="sky-sp-progress-bar" id="sky-sp-bar"></div></div>
                    <div id="sky-sp-preview"></div>
                    <div id="sky-sp-log"></div>
                </div>
                
                <div class="sky-cp-tabs">
                    <div class="sky-cp-tab active" onclick="skylaiprCpTab('create', this)">创作</div>
                    <div class="sky-cp-tab" onclick="skylaiprCpTab('rewrite', this)">润色</div>
                    <div class="sky-cp-tab" onclick="skylaiprCpTab('seo', this)">SEO</div>
                    <div class="sky-cp-tab" onclick="skylaiprCpTab('tools', this)">工具</div>
                </div>
                
                <div id="cp-p-create" class="sky-cp-pane active">
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('title')"><i>📖</i> 优化标题</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('outline')"><i>📑</i> 生成大纲</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('continue')"><i>✍️</i> 续写段落</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('expand')"><i>➕</i> 扩写内容</button>
                </div>
                <div id="cp-p-rewrite" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('rewrite')"><i>♻️</i> 伪原创重写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('polish')"><i>✨</i> 智能润色</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('shorten')"><i>➖</i> 精简缩写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('trans')"><i>🌐</i> 中英互译</button>
                </div>
                <div id="cp-p-seo" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('desc')"><i>📝</i> 生成摘要</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('tags')"><i>🏷️</i> 提取标签</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.linkNow()"><i>🔗</i> 自动内链 (写回)</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.task('slug_en')"><i>🔤</i> 英文 Slug</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.seo()"><i>🩺</i> SEO 诊断</button>
                </div>
                <div id="cp-p-tools" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp purple" onclick="SkylaiprEditor.genImg()"><i>🎨</i> AI 生成配图</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylaiprEditor.insert()"><i>📥</i> 插入生成结果</button>
                </div>
                <div id="sky-loading" style="display:none; color:#666; margin:10px 0;font-size:12px;text-align:center;"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> <span id="sky-loading-txt">AI 思考中...</span></div>
                
                <div id="sky-res-box">
                    <div id="sky-res-content"></div>
                    <div id="sky-res-actions" class="sky-res-actions" style="display:none;"></div>
                </div>
            </div>

            <?php
        }, 'post', 'side', 'high');
    }
}
