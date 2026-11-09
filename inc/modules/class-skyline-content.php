<?php
if (!defined('ABSPATH')) exit;

class Skyline_Content {
    // M5：爬虫下载单张图片的大小上限（10MB）。超过直接拒绝，不读进内存。
    private const SPIDER_MAX_IMAGE_BYTES = 10485760;

    public function __construct() {
        add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        add_action('skyline_async_spider', [$this, 'auto_spider']);
        add_action('skyline_async_seo', [$this, 'auto_seo_meta']); 
        
        add_filter('the_content', [$this, 'auto_internal_links']);
        
        add_action('wp_ajax_sky_seo_score', [$this, 'ajax_seo_score']);
        add_action('wp_ajax_sky_spider_single', [$this, 'ajax_spider_single']); 
        add_action('wp_ajax_sky_link_now', [$this, 'ajax_link_now']); 
        
        add_action('add_meta_boxes', [$this, 'add_copilot_box']);
    }

    public function trigger_async_tasks($pid, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (wp_is_post_revision($pid) || $post->post_status != 'publish') return;

        $core = Skyline_Core::instance();
        
        if ($core->get_opt('spider_enable') && $core->get_opt('spider_auto')) {
            if (!wp_next_scheduled('skyline_async_spider', [$pid])) {
                wp_schedule_single_event(time() + 2, 'skyline_async_spider', [$pid]);
            }
        }

        $do_tags = $core->get_opt('auto_tags');
        $do_slug = $core->get_opt('auto_slug');
        $do_excerpt = $core->get_opt('auto_excerpt');
        $do_polish = $core->get_opt('auto_polish');
        
        if ($do_tags || $do_slug || $do_excerpt || $do_polish) {
            // v2.1.1：失败满 3 次后 _sky_seo_done 被置 1、自动重试链停止；
            // 但手动点“更新”保存时应给一次复活机会——否则日志里“下次保存
            // 时重试”的承诺永远兑现不了（2026-11-09 实测：70753/70754 三次
            // 401 后被标记完成，API 恢复后重新保存也不再触发 SEO）。
            // 区分“成功完成”和“放弃”：成功时 _sky_seo_fail_count 会被删掉，
            // 放弃时它留在 >=3；只有后者才在保存时清掉重排。
            $seo_done = get_post_meta($pid, '_sky_seo_done', true);
            if ($seo_done && (int)get_post_meta($pid, '_sky_seo_fail_count', true) >= 3) {
                delete_post_meta($pid, '_sky_seo_done');
                delete_post_meta($pid, '_sky_seo_fail_count');
                $seo_done = false;
                $core->log("自动 SEO 曾因连续失败满 3 次停止重试，本次保存已重置、重新排期 [$pid]", 'info', 'SEO');
            }
            if (!$seo_done && !wp_next_scheduled('skyline_async_seo', [$pid])) {
                wp_schedule_single_event(time() + 5, 'skyline_async_seo', [$pid]);
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
            $core = Skyline_Core::instance();
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
            require_once(ABSPATH . 'wp-admin/includes/media.php');
        }

        $core = Skyline_Core::instance();
        $norm_url = $url;
        $img_hash = md5($norm_url);

        $spider_history = get_post_meta($pid, '_sky_spider_history', true);
        if (!is_array($spider_history)) $spider_history = [];

        if (isset($spider_history[$img_hash])) {
            $existing_id = intval($spider_history[$img_hash]);
            if (get_post($existing_id)) return ['id' => $existing_id, 'status' => 'cached_post'];
        }

        global $wpdb;
        $global_id = $wpdb->get_var($wpdb->prepare("SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_sky_source_hash' AND meta_value = %s LIMIT 1", $img_hash));

        if ($global_id && get_post($global_id)) {
            $spider_history[$img_hash] = $global_id;
            update_post_meta($pid, '_sky_spider_history', $spider_history);
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
                $tmp = function_exists('wp_tempnam') ? wp_tempnam('sky_spider_') : tempnam(sys_get_temp_dir(), 'sky_spider_');
                $stream_args = $args;
                $stream_args['stream'] = true;
                if ($tmp) $stream_args['filename'] = $tmp;
                $res = wp_remote_get($current_url, $stream_args);
            } else {
                $res = wp_remote_get($current_url, $args);
            }
            if(is_wp_error($res)) { if ($tmp) @unlink($tmp); return ['error' => '网络错误: ' . $res->get_error_message()]; }
            $code = wp_remote_retrieve_response_code($res);
            if ($code >= 300 && $code < 400) {
                $location = trim((string)wp_remote_retrieve_header($res, 'location'));
                if ($tmp) @unlink($tmp);
                if ($location === '' || $hop === 3) return ['error' => "HTTP状态 $code"];
                $resolved = $this->resolve_redirect_url($current_url, $location);
                if ($resolved === null) return ['error' => '重定向目标不是 http(s) 地址'];
                $err = $this->validate_remote_host($resolved);
                if ($err) return ['error' => $err];
                $current_url = $resolved;
                continue;
            }
            if ($code != 200) { if ($tmp) @unlink($tmp); return ['error' => "HTTP状态 $code"]; }
            if ($is_stream && $tmp && file_exists($tmp)) {
                $sz = filesize($tmp);
                if ($sz !== false && $sz > self::SPIDER_MAX_IMAGE_BYTES) { @unlink($tmp); return ['error' => '文件过大(>10MB)']; }
                if ($sz !== false && $sz < 500) { @unlink($tmp); return ['error' => '文件太小(<500B)']; }
                $file_data = file_get_contents($tmp);
                @unlink($tmp);
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

        $filename = 'sp_' . date('YmdHis') . '_' . substr($img_hash, 0, 6) . '.jpg'; 
        $content_type = wp_remote_retrieve_header($res, 'content-type');
        
        if($content_type) {
            if(strpos($content_type, 'png')!==false) $filename = str_replace('.jpg', '.png', $filename);
            elseif(strpos($content_type, 'gif')!==false) $filename = str_replace('.jpg', '.gif', $filename);
            elseif(strpos($content_type, 'webp')!==false) $filename = str_replace('.jpg', '.webp', $filename);
        }

        $upload = wp_upload_bits($filename, null, $file_data);
        if($upload['error']) return ['error' => '写入失败: ' . $upload['error']];

        $file_path = $upload['file'];
        $mime = $content_type ?: 'image/jpeg';

        if($core->get_opt('spider_rm_wm')) $this->remove_watermark_crop($file_path, $mime);
        if($core->get_opt('spider_wm_enable')) $this->watermark($file_path);

        $aid = wp_insert_attachment(['post_mime_type'=>$mime, 'post_title'=>'Spider Img'], $file_path, $pid);
        if (!is_wp_error($aid)) {
            $meta = wp_generate_attachment_metadata($aid, $file_path);
            wp_update_attachment_metadata($aid, $meta);
            
            update_post_meta($aid, '_sky_source_hash', $img_hash);
            update_post_meta($aid, '_sky_source_url', $url);
            $core->stat_inc('spider_count');
            $core->log("同步图片成功: $filename", 'info', 'Spider');
            $spider_history[$img_hash] = $aid;
            update_post_meta($pid, '_sky_spider_history', $spider_history);
            return ['id' => $aid, 'status' => 'downloaded'];
        }
        return ['error' => '数据库插入失败'];
    }

    // N-A2：spider_domains（排除域名，一行一个）的解析与匹配抽成公共
    // 方法，自动同步与手动单张同步共用——之前只自动同步查，手动入口
    // ajax_spider_single 直接调 download_image，可绕过排除。
    private function get_spider_excluded_domains() {
        $core = Skyline_Core::instance();
        $excluded_domains = [];
        $domains_raw = trim((string)$core->get_opt('spider_domains', ''));
        if ($domains_raw !== '') {
            foreach (explode("\n", $domains_raw) as $line) {
                $d = strtolower(trim($line));
                if ($d === '') continue;
                if (strpos($d, '://') !== false) $d = (string)parse_url($d, PHP_URL_HOST);
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
        $host = strtolower((string)(parse_url((string)$url, PHP_URL_HOST) ?: ''));
        return $this->is_host_spider_excluded($host, $this->get_spider_excluded_domains());
    }

    public function auto_spider($pid) {
        $post = get_post($pid);
        if (!$post) return;

        // R4：锁改存时间戳、10 分钟过期——fatal/超时/OOM 后锁不会永久锁死
        // 该文章，过期锁可被下一次任务抢占。try/finally 保证任何返回路径
        // （含 download_image 内抛 Error）都会清锁。旧版锁值是 1（无时间戳），
        // 无法判断新旧，一律视为过期、可抢占，避免升级前一次崩溃留下的锁
        // 永久锁死该文章。
        $lock = get_post_meta($pid, '_sky_spider_processing', true);
        if ($lock !== '' && $lock !== false && $lock !== null
            && is_numeric($lock) && (int)$lock > 1000000000 && time() - (int)$lock < 600) {
            return;
        }
        update_post_meta($pid, '_sky_spider_processing', time());

        try {
        $content = $post->post_content;
        // R16：除 src/data-src 外再认 srcset（多倍图 URL 组）与 <video poster>；
        // 同一 URL 同时出现在 src 与 srcset 时去重，只下载一次。
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
        $candidates = array_values(array_unique($candidates));
        if(empty($candidates)) return;

        $site_domain = parse_url(get_site_url(), PHP_URL_HOST);
        $success = 0;

        $core = Skyline_Core::instance();
        $max_img = intval($core->get_opt('spider_max_img', 20));
        $processed_count = 0;

        // 已同步到 COS 的图片不再当作外站图：否则每次保存文章都会把 COS 图重新
        // 下载回本地、重新上传 COS，白白再走一遍“上传→删本地”循环。
        $oss_hosts = [];
        if ($core->get_opt('oss_enable')) {
            $oss_domain = trim((string)$core->get_opt('oss_domain'));
            if ($oss_domain !== '') {
                $h = parse_url($oss_domain, PHP_URL_HOST);
                if (!$h) $h = preg_replace('#^https?://#i', '', $oss_domain);
                $h = strtolower(trim(explode('/', $h)[0]));
                if ($h !== '') $oss_hosts[] = $h;
            }
            $bucket = trim((string)$core->get_opt('oss_bucket'));
            $endpoint = trim((string)$core->get_opt('oss_endpoint'));
            if ($bucket !== '' && $endpoint !== '') $oss_hosts[] = strtolower($bucket . '.' . $endpoint);
        }

        $has_more = false;
        // M1：spider_domains（排除域名，一行一个）真正生效：按行解析，
        // 图片 URL 的 host 精确命中或为其子域名时跳过、不同步。
        // 解析与匹配见 get_spider_excluded_domains() / is_host_spider_excluded()，
        // 与手动单张同步共用同一份逻辑（N-A2）。
        $excluded_domains = $this->get_spider_excluded_domains();
        foreach($candidates as $url_raw) {
            if ($processed_count >= $max_img) { $has_more = true; break; }

            $url_real = html_entity_decode($url_raw);
            if(strpos($url_real, $site_domain)!==false || strpos($url_real, 'data:image')!==false) continue;
            $url_host = strtolower(parse_url($url_real, PHP_URL_HOST) ?: '');
            if ($url_host !== '' && in_array($url_host, $oss_hosts, true)) continue;
            // M1：命中排除域名（精确或后缀匹配）就跳过不同步、不占名额。
            if ($this->is_host_spider_excluded($url_host, $excluded_domains)) continue;
            
            $res = $this->download_image($url_real, $pid);
            if(isset($res['id'])) {
                $local_url = wp_get_attachment_url($res['id']);
                $content = str_replace($url_raw, $local_url, $content);
                $success++;
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
            add_action('save_post', [$this, 'trigger_async_tasks'], 20, 2);
        }

        // R16：超上限的剩余图片重排一次异步任务续跑——下次运行时已同步的
        // URL 已是本地/COS 地址会被跳过，只处理剩下的，不再永久漏掉。
        if ($has_more && !wp_next_scheduled('skyline_async_spider', [$pid])) {
            wp_schedule_single_event(time() + 30, 'skyline_async_spider', [$pid]);
            $core->log("图片同步：单篇超过 {$max_img} 张上限，剩余图片已重排续跑 [$pid]", 'info', 'Spider');
        }
        } finally {
            delete_post_meta($pid, '_sky_spider_processing');
        }
    }

    public function auto_seo_meta($pid) {
        $post = get_post($pid);
        if (!$post) return;
        
        if (get_post_meta($pid, '_sky_seo_done', true)) return;

        $core = Skyline_Core::instance();
        $do_tags = $core->get_opt('auto_tags');
        $do_slug = $core->get_opt('auto_slug');
        $do_excerpt = $core->get_opt('auto_excerpt');
        $do_polish = $core->get_opt('auto_polish');

        if (!$do_tags && !$do_slug && !$do_excerpt && !$do_polish) return;
        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

        $updates = [];
        $any_success = false;
        $any_attempted = false;
        global $wpdb;

        if ($do_polish) {
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
                    if (!$part || is_wp_error($part) || strlen($part) < 10) { $polish_failed = true; break; }
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
                $core->log("AI 润色成功 [$pid]", 'info', 'SEO');
            }
        }
        if ($do_tags) {
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            $prompt = "请基于以下内容提取5个核心SEO标签，用逗号分隔，不要其他文字和编号：\n\n" . mb_substr(strip_tags($text), 0, 1500);
            $any_attempted = true;
            $tags = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
            if ($tags && !is_wp_error($tags)) { wp_set_post_tags($pid, str_replace(['，', '、'], ',', $tags), true); $any_success = true; }
        }
        if ($do_slug) {
            $prompt = "Generate a concise English URL slug for this title (max 5-8 words). STRICTLY OUTPUT ONLY THE SLUG (lowercase, hyphens). Title: " . $post->post_title;
            $any_attempted = true;
            // N-D2：slug 要求只输出英文，system 人设里的“中文回答”会与之
            // 冲突——这类纯英文输出任务不拼 system 消息（call_api 第 3 参数）。
            $slug = $core->call_api([['role'=>'user', 'content'=>$prompt]], 0.7, false);
            if ($slug && !is_wp_error($slug)) {
                $slug = sanitize_title(strip_tags(preg_replace('/^(Here.*?:\s*)/i', '', $slug)));
                if ($slug) { $updates['post_name'] = substr(trim(preg_replace('/-+/', '-', $slug), '-'), 0, 60); $any_success = true; }
            }
        }
        if ($do_excerpt && empty($post->post_excerpt)) {
            $text = isset($updates['post_content']) ? $updates['post_content'] : $post->post_content;
            $prompt = "Generate a 120-word SEO summary for this content (in Chinese): \n\n" . mb_substr(strip_tags($text), 0, 1500);
            $any_attempted = true;
            $excerpt = $core->call_api([['role'=>'user', 'content'=>$prompt]]);
           if ($excerpt && !is_wp_error($excerpt)) {
                $updates['post_excerpt'] = trim(mb_substr(wp_strip_all_tags($excerpt), 0, 260));
                $any_success = true;
            }
        }

        if (!empty($updates)) {
            $wpdb->update($wpdb->posts, $updates, ['ID' => (int)$pid]);
            clean_post_cache($pid);
        }

        // R5：只有至少一项成功才标记 _sky_seo_done；全部失败时不标记，
        // 允许下次保存时重试。连续失败满 3 次才标记完成、停止重试，
        // 避免 API 长期故障时每次保存都重复触发失败任务。无任何尝试
        // （如只要摘要但摘要已存在）时直接标记完成，不算失败。
        if ($any_success || !$any_attempted) {
            update_post_meta($pid, '_sky_seo_done', 1);
            delete_post_meta($pid, '_sky_seo_fail_count');
        } else {
            $fails = (int)get_post_meta($pid, '_sky_seo_fail_count', true) + 1;
            update_post_meta($pid, '_sky_seo_fail_count', $fails);
            $core->log("自动 SEO 全部失败 [$pid]（第 {$fails} 次），未标记完成，下次保存时重试", 'warn', 'SEO');
            if ($fails >= 3) {
                update_post_meta($pid, '_sky_seo_done', 1);
                $core->log("自动 SEO 连续失败满 3 次 [$pid]，已停止重试", 'warn', 'SEO');
            } else {
                // S4 附录：失败后 1 小时重排期重试，与 Zero-Disk 的
                // reschedule_purge() 同款模式（上限 3 次——_sky_seo_fail_count
                // 满 3 次即标记完成、上面分支不再排期）。只靠“下次保存再
                // 触发”时，定时任务发布的文章发布后无人再保存，一次瞬时
                // API 失败就等于永久停摆。
                if (!wp_next_scheduled('skyline_async_seo', [(int)$pid])) {
                    wp_schedule_single_event(time() + 3600, 'skyline_async_seo', [(int)$pid]);
                    $core->log("自动 SEO 全部失败 [$pid]（第 {$fails} 次），已重排 1 小时后重试", 'warn', 'SEO');
                }
            }
        }
    }

    // L13：检测底部 40px 是否像一条水印带（底色近乎均匀的色带）。
    // 采样统计：底部色带的主导色（按 16 级量化）占比 >=85%，且其上方
    // 40px 色带主导色占比 <85% 或平均亮度相差 >25，才判定为水印带。
    // 正常照片底部也是内容、主导色占比低——检测不到时返回 0、不裁。
    // 保守方向：判不准时不裁，宁可留水印、不误删正文底部内容。
    private function detect_watermark_band_height($src, $w, $h) {
        $band = 40;
        if ($h < 150 || $w < 10) return 0;
        $sample = function ($y0, $y1) use ($src, $w) {
            $counts = [];
            $total = 0;
            $bright = 0;
            $step_x = max(1, (int)($w / 50));
            for ($y = $y0; $y < $y1; $y += 4) {
                for ($x = 0; $x < $w; $x += $step_x) {
                    $rgb = imagecolorat($src, $x, $y);
                    $r = ($rgb >> 16) & 0xff;
                    $g = ($rgb >> 8) & 0xff;
                    $b = $rgb & 0xff;
                    $key = (($r >> 4) << 8) | (($g >> 4) << 4) | ($b >> 4);
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                    $bright += ($r + $g + $b) / 3;
                    $total++;
                }
            }
            if ($total === 0) return ['dominant' => 0, 'mean' => 0];
            return ['dominant' => max($counts) / $total, 'mean' => $bright / $total];
        };
        $bottom = $sample($h - $band, $h);
        $above = $sample($h - 2 * $band, $h - $band);
        if ($bottom['dominant'] >= 0.85 && ($above['dominant'] < 0.85 || abs($bottom['mean'] - $above['mean']) > 25)) {
            return $band;
        }
        return 0;
    }

    private function remove_watermark_crop($path, $mime) {
        if(!extension_loaded('gd')) return;
        $info = @getimagesize($path); if(!$info) return;
        $w = $info[0]; $h = $info[1];
        if($h < 150) return;
        $src = ($mime == 'image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if(!$src) return;
        // L13：先检测底部水印区域再裁，检测不到水印时不裁（旧写法无条件裁 40px，
        // 会误删正文底部内容；“智能去除水印”名不副实）。
        $crop_h = $this->detect_watermark_band_height($src, $w, $h);
        if ($crop_h <= 0) { imagedestroy($src); return; }
        if($h - $crop_h < 50) $crop_h = 20;
        $dst = imagecreatetruecolor($w, $h - $crop_h);
        // L13：alpha 处理——透明 PNG 不变黑底。
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        $transparent = imagecolorallocatealpha($dst, 0, 0, 0, 127);
        imagefill($dst, 0, 0, $transparent);
        imagecopy($dst, $src, 0, 0, 0, 0, $w, $h - $crop_h);
        $quality = max(0, min(100, (int)Skyline_Core::instance()->get_opt('turbo_quality', 85)));
        if($mime == 'image/jpeg') imagejpeg($dst, $path, $quality); else imagepng($dst, $path, 9);
        imagedestroy($src); imagedestroy($dst);
    }

    private function watermark($path) {
        if(!extension_loaded('gd')) return;
        $i=@getimagesize($path); if(!$i) return;
        $im = ($i['mime']=='image/jpeg') ? @imagecreatefromjpeg($path) : @imagecreatefrompng($path);
        if(!$im) return;
        $txt = Skyline_Core::instance()->get_opt('spider_wm_text', '@Skyline');
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
        $quality = max(0, min(100, (int)Skyline_Core::instance()->get_opt('turbo_quality', 85)));
        if($i['mime']=='image/jpeg') imagejpeg($im, $path, $quality);
        else { imagesavealpha($im, true); imagepng($im, $path, 9); }
        imagedestroy($im);
    }

    public function ajax_link_now() {
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        $content = isset($_POST['content']) ? wp_unslash($_POST['content']) : '';
        $content = wp_kses_post($content);
        if(!$content) wp_send_json_error('内容为空');
        $links_str = Skyline_Core::instance()->get_opt('link_pairs');
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
        check_ajax_referer('sky_ai_task_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        $url = isset($_POST['url']) ? esc_url_raw(wp_unslash($_POST['url'])) : '';
        $pid = isset($_POST['pid']) ? intval($_POST['pid']) : 0;
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
        check_ajax_referer('sky_seo_nonce');
        if(!current_user_can('edit_posts')) wp_send_json_error('权限不足');
        $score = 100; $advice = [];
        $title = sanitize_text_field($_POST['title'] ?? ''); 
        $content = wp_kses_post($_POST['content'] ?? '');
        if(mb_strlen($title)<10) { $score-=10; $advice[]='❌ 标题过短'; }
        if(mb_strlen(strip_tags($content))<300) { $score-=20; $advice[]='❌ 内容稀薄'; }
        if($score>=90) $advice[]='🎉 完美！';
        Skyline_Core::instance()->log("SEO 诊断得分: $score", 'info', 'SEO');
        wp_send_json_success(['score'=>$score, 'advice'=>$advice]);
    }

    public function add_copilot_box() {
        add_meta_box('sky_copilot', '🔮 Skyline Copilot', function($post) {
            $ajax_url = admin_url('admin-ajax.php');
            $nonce = wp_create_nonce('sky_ai_task_nonce');
            $seo_nonce = wp_create_nonce('sky_seo_nonce');
            $esc_nonce = esc_js($nonce);
            $esc_seo_nonce = esc_js($seo_nonce);
            ?>
            <style>
            .sky-cp-wrap { font-family: sans-serif; position: relative; }
            .sky-btn-cp { width:100%; margin-top:6px; padding:8px; border:1px solid #ddd; background:#fff; border-radius:4px; cursor:pointer; text-align:left; display:flex; align-items:center; color:#333; transition:all .2s; }
            .sky-btn-cp:hover { background:#f5f5f5; border-color:#6366f1; color:#6366f1; }
            .sky-btn-cp i { margin-right:8px; width:16px; text-align:center; }
            .sky-btn-cp.primary { background:linear-gradient(135deg, #6366f1, #4f46e5); color:#fff; border:none; justify-content:center; text-align:center; font-weight:bold; }
            .sky-btn-cp.primary:disabled { opacity:0.7; cursor:not-allowed; }
            .sky-cp-tabs{display:flex; background:#f1f5f9; padding:3px; border-radius:6px; gap:2px; margin-bottom:10px;}
            .sky-cp-tab{flex:1; text-align:center; padding:6px 0; cursor:pointer; font-size:11px; color:#64748b; border-radius:4px; font-weight:600; transition:all .2s;}
            .sky-cp-tab.active{color:#6366f1; background:#fff; box-shadow:0 1px 3px rgba(0,0,0,0.05);}
            .sky-cp-pane{display:none; animation:fadeIn .2s;}.sky-cp-pane.active{display:block}
            #sky-spider-status { display:none; margin-bottom:10px; background:#f8fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px; }
            .sky-sp-header { display:flex; justify-content:space-between; font-size:12px; font-weight:bold; color:#334155; margin-bottom:5px; }
            .sky-sp-progress-bg { height:6px; background:#e2e8f0; border-radius:3px; overflow:hidden; margin-bottom:8px; }
            .sky-sp-progress-bar { height:100%; background:#10b981; width:0%; transition:width .3s; }
            #sky-sp-log { height:120px; overflow-y:auto; border:1px solid #e2e8f0; background:#fff; padding:5px; font-size:11px; line-height:1.6; color:#64748b; border-radius:4px; font-family:monospace; }
            .sp-log-ok { color:#10b981; }
            .sp-log-err { color:#ef4444; }
            #sky-sp-preview { display:grid; grid-template-columns: repeat(4, 1fr); gap:4px; margin-top:8px; max-height:100px; overflow-y:auto; }
            .sky-sp-thumb { width:100%; aspect-ratio:1/1; object-fit:cover; border-radius:4px; border:1px solid #e2e8f0; opacity:0.6; }
            .sky-sp-thumb.done { opacity:1; border-color:#10b981; border-width:2px; }
            #sky-res-box { display:none; border:1px solid #e2e8f0; background:#fff; border-radius:6px; margin-top:10px; overflow:hidden; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.05); }
            #sky-res-content { max-height: 250px; overflow-y: auto; padding: 12px; white-space: pre-wrap; font-size: 13px; line-height: 1.6; color: #334155; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
            #sky-res-content::-webkit-scrollbar { width: 5px; }
            #sky-res-content::-webkit-scrollbar-track { background: transparent; }
            #sky-res-content::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 4px; }
            #sky-res-content::-webkit-scrollbar-thumb:hover { background: #94a3b8; }
            .sky-res-actions { padding: 8px; background: #fff; display: flex; flex-direction: column; gap: 5px; }
            </style>

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
                    <div class="sky-cp-tab active" onclick="sTab('create', this)">创作</div>
                    <div class="sky-cp-tab" onclick="sTab('rewrite', this)">润色</div>
                    <div class="sky-cp-tab" onclick="sTab('seo', this)">SEO</div>
                    <div class="sky-cp-tab" onclick="sTab('tools', this)">工具</div>
                </div>
                
                <div id="cp-p-create" class="sky-cp-pane active">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('title')"><i>📖</i> 优化标题</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('outline')"><i>📑</i> 生成大纲</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('continue')"><i>✍️</i> 续写段落</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('expand')"><i>➕</i> 扩写内容</button>
                </div>
                <div id="cp-p-rewrite" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('rewrite')"><i>♻️</i> 伪原创重写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('polish')"><i>✨</i> 智能润色</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('shorten')"><i>➖</i> 精简缩写</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('trans')"><i>🌐</i> 中英互译</button>
                </div>
                <div id="cp-p-seo" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('desc')"><i>📝</i> 生成摘要</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('tags')"><i>🏷️</i> 提取标签</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.linkNow()"><i>🔗</i> 自动内链 (写回)</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.task('slug_en')"><i>🔤</i> 英文 Slug</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.seo()"><i>🩺</i> SEO 诊断</button>
                </div>
                <div id="cp-p-tools" class="sky-cp-pane">
                    <button type="button" class="sky-btn-cp purple" onclick="SkylineEditor.genImg()"><i>🎨</i> AI 生成配图</button>
                    <button type="button" class="sky-btn-cp" onclick="SkylineEditor.insert()"><i>📥</i> 插入生成结果</button>
                </div>
                <div id="sky-loading" style="display:none; color:#666; margin:10px 0;font-size:12px;text-align:center;"><span class="spinner is-active" style="float:none; margin:0 5px 0 0;"></span> <span id="sky-loading-txt">AI 思考中...</span></div>
                
                <div id="sky-res-box">
                    <div id="sky-res-content"></div>
                    <div id="sky-res-actions" class="sky-res-actions" style="display:none;"></div>
                </div>
            </div>

            <script>
            var skyAjaxUrl = '<?php echo esc_url($ajax_url); ?>';
            
            (function($){
                window.sTab = function(id, elem){ 
                    try {
                        $('.sky-cp-tab').removeClass('active'); 
                        if(elem) $(elem).addClass('active'); 
                        $('.sky-cp-pane').hide(); 
                        $('#cp-p-'+id).show(); 
                    } catch(e) { console.error(e); }
                };

                window.SkySpider = {
                    init: function() {
                        try {
                            var content = SkylineEditor.getContent();
                            if(!content) return alert('请先输入或粘贴内容');
                            
                            var regex = /<img[^>]+(?:data-src|src)=['"]([^'"]+)['"]/g;
                            var matches = [], found;
                            var seen = new Set();
                            // N-A1：预览缩略图用 DOM 建节点（.attr('src', …)），
                            // 不拼 '<img src="'+url+'">' 字符串插进 .html()——
                            // real 是经 HTML 实体解码后的 URL，可含引号，拼串
                            // 会跳出 src 属性造成后台 XSS（与 M8 同款修法）。
                            var previewNodes = [];
                            var tempContent = content;
                            var counter = 0;
                            
                            while ((found = regex.exec(content)) !== null) {
                                var raw = found[1];
                                if(raw.indexOf(window.location.hostname) === -1 && raw.indexOf('data:image') === -1 && raw.indexOf('sky-pending-') === -1) {
                                    var d = document.createElement('div'); d.innerHTML = raw; var real = d.textContent || raw;
                                    var key = real; 
                                    if (!seen.has(key)) { 
                                        var placeholder = 'sky-pending-' + counter + '-' + Math.floor(Math.random()*1000);
                                        matches.push({ raw: raw, real: real, holder: placeholder }); 
                                        seen.add(key);
                                        previewNodes.push($('<img>').attr('src', real).addClass('sky-sp-thumb').attr('id', 'sp-thumb-'+counter));
                                        counter++;
                                    }
                                }
                            }

                            if(matches.length === 0) return alert('✅ 未发现外部图片！');

                            for(var i=0; i<matches.length; i++) {
                                var m = matches[i];
                                tempContent = tempContent.split(m.raw).join(m.holder);
                                if(m.real !== m.raw) tempContent = tempContent.split(m.real).join(m.holder);
                            }
                            
                            SkylineEditor.setContent(tempContent);

                            $('#sky-spider-status').slideDown();
                            $('#sky-sp-preview').empty().append(previewNodes);
                            $('#btn-spider-start').prop('disabled', true).text('同步进行中...');
                            $('#sp-total').text(matches.length);
                            $('#sp-cur').text(0);
                            $('#sky-sp-bar').css('width', '0%');
                            $('#sky-sp-log').html('<div>🚀 锁定 ' + matches.length + ' 张图片，开始下载...</div>');
                            
                            this.processQueue(matches, 0);
                        } catch(e) { alert('同步启动失败: ' + e.message); }
                    },

                    log: function(msg, type='normal') {
                        // M8：msg 含服务器返回的错误串（不可信输入），用
                        // textContent 插入，不再用 innerHTML 拼串。
                        var color = type==='ok'?'#10b981':(type==='err'?'#ef4444':'#64748b');
                        var d = document.createElement('div');
                        d.textContent = msg;
                        d.style.color = color;
                        var box = document.getElementById('sky-sp-log');
                        if(box) { box.appendChild(d); box.scrollTop = box.scrollHeight; }
                    },

                    processQueue: function(queue, idx, retryCount) {
                        if (typeof retryCount === 'undefined') retryCount = 0;
                        if (idx >= queue.length) {
                            $('#sp-msg').text('全部完成').css('color', '#10b981');
                            $('#btn-spider-start').prop('disabled', false).html('<i>🕷️</i> 一键同步图片 (可视版)');
                            this.log('🏁 队列结束，编辑器内容已更新', 'ok');
                            return;
                        }

                        var item = queue[idx];
                        var pid = $('#post_ID').val() || 0;
                        var self = this;
                        var label = (retryCount > 0) ? '重试 ' + retryCount + '...' : '正在下载...';
                        $('#sp-msg').text('第 ' + (idx+1) + ' 张: ' + label);
                        
                        $.post(skyAjaxUrl, {
                            action: 'sky_spider_single', url: item.real, pid: pid, _ajax_nonce: '<?php echo $esc_nonce; ?>'
                        }).done(function(res) {
                            if(res.success) {
                                var localUrl = res.data.url;
                                self.log('[' + (idx+1) + '] ✅ ' + (res.data.msg||'成功'), 'ok');
                                $('#sp-thumb-'+idx).addClass('done').attr('src', localUrl);

                                try {
                                    var currentContent = SkylineEditor.getContent();
                                    currentContent = currentContent.split(item.holder).join(localUrl);
                                    currentContent = currentContent.replace(/src=["']data:image\/[^;]+;base64,[^"']*["']/g, '');
                                    currentContent = currentContent.replace(/src=["']\s*["']/g, '');
                                    function escReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }
                                    var dsReg = new RegExp('data-src=["\']' + escReg(localUrl) + '["\']', 'g');
                                    currentContent = currentContent.replace(dsReg, 'src="' + localUrl + '"');
                                    SkylineEditor.setContent(currentContent);
                                } catch(e) { console.log('Update content error:', e); }

                                var pct = Math.round(((idx + 1) / queue.length) * 100);
                                $('#sp-cur').text(idx + 1);
                                $('#sky-sp-bar').css('width', pct + '%');
                                self.processQueue(queue, idx + 1, 0); 
                            } else { 
                                if (retryCount < 2) {
                                    self.log('[' + (idx+1) + '] ⚠️ 失败: ' + res.data + '，3秒后重试...', 'warn');
                                    setTimeout(function(){ self.processQueue(queue, idx, retryCount + 1); }, 3000);
                                } else {
                                    self.log('[' + (idx+1) + '] ❌ 放弃: ' + res.data, 'err');
                                    var currentContent = SkylineEditor.getContent();
                                    currentContent = currentContent.split(item.holder).join(item.raw);
                                    SkylineEditor.setContent(currentContent);
                                    self.processQueue(queue, idx + 1, 0);
                                }
                            }
                        }).fail(function(xhr, status, error){ 
                            if (retryCount < 2) { setTimeout(function(){ self.processQueue(queue, idx, retryCount + 1); }, 3000); } 
                            else { self.log('[' + (idx+1) + '] ❌ 网络错误: ' + error, 'err'); self.processQueue(queue, idx + 1, 0); }
                        });
                    }
                };

                window.SkylineEditor = {
                    isGutenberg: function() { return document.body.classList.contains('block-editor-page') && typeof wp !== 'undefined' && wp.data && wp.data.select; },
                    isClassicVisual: function() { return typeof tinyMCE !== 'undefined' && tinyMCE.activeEditor && !tinyMCE.activeEditor.isHidden(); },
                    getContent: function() { 
                        try {
                            if (this.isGutenberg()) return wp.data.select("core/editor").getEditedPostAttribute("content");
                            else if (this.isClassicVisual()) return tinyMCE.activeEditor.getContent();
                            else return $('#content').val();
                        } catch(e) { return $('#content').val() || ''; }
                    },
                    setContent: function(html) { 
                        try {
                            if (this.isGutenberg()) { 
                                var blocks = wp.blocks.parse(html); 
                                wp.data.dispatch('core/editor').resetBlocks(blocks); 
                                setTimeout(function() {
                                    if(wp.data && wp.data.dispatch && wp.data.dispatch('core/editor')) {
                                        wp.data.dispatch('core/editor').savePost();
                                    }
                                }, 500);
                            } 
                            else if (this.isClassicVisual()) {
                                tinyMCE.activeEditor.setContent(html);
                                tinyMCE.activeEditor.setDirty(false); 
                            }
                            else { 
                                $('#content').val(html).trigger('change'); 
                            }
                        } catch(e) {}
                    },
                    setTitle: function(newTitle) {
                        if (this.isGutenberg()) { wp.data.dispatch("core/editor").editPost({title:newTitle}); } 
                        else { $('#title').val(newTitle); $('#title-prompt-text').addClass('screen-reader-text'); }
                    },
                    linkNow: function() {
                        var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
                        $('#sky-loading').show();
                        $.post(skyAjaxUrl, { action: 'sky_link_now', content: i, _ajax_nonce: '<?php echo $esc_nonce; ?>' }, function(r){
                            $('#sky-loading').hide();
                            if(r.success) { SkylineEditor.setContent(r.data.content); alert(r.data.msg); } 
                            else { alert(r.data); }
                        }).fail(function() { $('#sky-loading').hide(); alert('网络请求失败'); });
                    },
                    applyReplace: function() {
                        var raw = $('#sky-res-content').data('raw');
                        if(raw) { if(confirm('⚠️ 确定要用生成的内容覆盖当前编辑器中的所有内容吗？')) this.setContent(raw); } 
                        else alert('没有可替换的内容');
                    },
                    task: function(t) {
                        try {
                            var i = this.getContent(); if(!i) return alert('请先输入或粘贴内容');
                            $('#sky-loading').show();
                            $.ajax({
                                url: skyAjaxUrl, type: 'POST',
                                data: { action: 'sky_ai_task', task: t, input: i, _ajax_nonce: '<?php echo $esc_nonce; ?>' },
                                success: function(r) {
                                    $('#sky-loading').hide();
                                    if(r.success) { 
                                        var res = r.data.trim();
                                        if(t==='title') { 
                                            var lines = res.split('\n').filter(function(l){ return l.trim().length > 0; });
                                            if (lines.length > 1) {
                                                var html = '<p><b>🤖 AI 提供了多个标题，请选择一个替换：</b></p>';
                                                lines.forEach(function(line){
                                                    var clean = line.replace(/^\d+[\.\、]\s*/, '').replace(/^["']|["']$/g, '').trim();
                                                    // M8：AI 标题先转义再拼 HTML（按钮文字与
                                                    // data-title 都转义）；不再把标题拼进
                                                    // onclick='...' JS 字符串（引号可逃逸注入），
                                                    // 改用 data-title + 事件绑定取值。
                                                    var esc = SkylineEditor.escapeHtml(clean);
                                                    html += '<button type="button" class="sky-btn-cp sky-title-choice" style="margin-bottom:5px;text-align:left" data-title="'+esc+'">👉 '+esc+'</button>';
                                                });
                                                html += '<button type="button" class="sky-btn-cp" style="margin-top:5px;color:#666" onclick="jQuery(\'#sky-res-box\').hide()">❌ 取消</button>';
                                                SkylineEditor.showResult(html);
                                                $('#sky-res-content').find('.sky-title-choice').on('click', function(){ SkylineEditor.setTitle($(this).attr('data-title')); });
                                                return;
                                            }
                                            var cleanRes = res.replace(/^["']|["']$/g, '');
                                            if(confirm('建议标题：\n'+cleanRes+'\n\n是否替换？')) SkylineEditor.setTitle(cleanRes);
                                        } 
                                        else if(t==='slug_en') { 
                                            res = res.replace(/[^a-z0-9-]/g, '-').toLowerCase();
                                            if(SkylineEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({slug:res}); 
                                            else { $('#post_name').val(res); $('#edit-slug-box').html(res); }
                                            alert('Slug 已更新: '+res); 
                                        }
                                        else if(t==='rewrite' || t==='polish' || t==='continue' || t==='expand') {
                                            var btns = '<button type="button" class="sky-btn-cp primary" onclick="SkylineEditor.applyReplace()">🔄 立即替换全文</button>';
                                            btns += '<button type="button" class="sky-btn-cp" onclick="SkylineEditor.insert()">📥 插入光标处</button>';
                                            SkylineEditor.showResult(null, res, btns);
                                        }
                                        // M8：AI 返回的标签走 rawText 分支（.text() 插入），
                                        // 不再拼进 '<b>…</b><br>'+res 的 .html() 串里。
                                        else if(t==='tags') { SkylineEditor.showResult(null, '建议标签：\n' + res); }
                                        else if(t==='desc') {
                                            if(SkylineEditor.isGutenberg()) wp.data.dispatch("core/editor").editPost({excerpt:res});
                                            else { $('#excerpt').val(res); }
                                            alert('摘要已更新');
                                        }
                                        else if(t==='rewrite_full') { if(confirm('确认覆盖全文？')) SkylineEditor.setContent(res); }
                                        // M8：其余 AI 任务的原文走 rawText 分支（.text() 插入）。
                                        else { SkylineEditor.showResult(null, res); } 
                                    } else alert('Error: ' + r.data); 
                                },
                                error: function(e) { $('#sky-loading').hide(); alert('Request Failed: ' + e.statusText); }
                            });
                        } catch(e) { $('#sky-loading').hide(); alert('执行错误: ' + e.message); }
                    },
                    genImg: function() {
                        var p = prompt("请输入图片描述:"); if(!p) return;
                        $('#sky-loading').show();
                        $.post(skyAjaxUrl, { action: 'sky_gen_img', prompt: p, _ajax_nonce: '<?php echo $esc_nonce; ?>' }, function(r){
                            $('#sky-loading').hide();
                            // M8：返回 URL 先校验为 http(s) 且不含引号/尖括号/
                            // 空白，再用 DOM 建 <img>（.attr('src', …)），不拼
                            // '<img src="'+url+'">' 字符串插进 .html()。
                            if(r.success) {
                                var imgUrl = String(r.data || '');
                                if (!/^https?:\/\/[^"'<>\s]+$/i.test(imgUrl)) { alert('生图返回了无效的图片地址'); return; }
                                var $box = $('#sky-res-content');
                                $box.empty();
                                $('<img>').attr('src', imgUrl).css('max-width', '100%').appendTo($box);
                                $box.removeData('raw');
                                $('#sky-res-actions').hide().empty();
                                $('#sky-res-box').show();
                            } else alert(r.data);
                        });
                    },
                    insert: function() {
                        try {
                            var h = $('#sky-res-content').html();
                            var raw = $('#sky-res-content').data('raw');
                            if(raw) h = raw;
                            if(!h) return;
                            if(this.isGutenberg()) { 
                                var b = wp.data.select('core/editor').getBlocks(); 
                                var nb = wp.blocks.createBlock('core/html', {content: h}); 
                                wp.data.dispatch('core/editor').insertBlocks(nb, b.length); 
                            } else { 
                                if(this.isClassicVisual()) tinyMCE.activeEditor.execCommand('mceInsertContent', false, h);
                                else { var ta = document.getElementById('content'); if(ta) ta.value += "\n\n" + h; }
                            }
                        } catch(e) { alert('插入失败: ' + e.message); }
                    },
                    seo: function() {
                        var t = $('#title').val(); 
                        if(this.isGutenberg()) t = wp.data.select("core/editor").getEditedPostAttribute("title");
                        var c = this.getContent();
                        $.post(skyAjaxUrl, { action:'sky_seo_score', title:t, content:c, _ajax_nonce:'<?php echo $esc_seo_nonce; ?>' }, function(r){
                             if(r.success) SkylineEditor.showResult('<b>SEO 得分: '+r.data.score+'</b><br>'+r.data.advice.join('<br>'));
                        });
                    },
                    // M8：AI 派生片段拼 HTML 前的转义（& < > " ' 全转）。
                    escapeHtml: function(s) {
                        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
                    },
                    showResult: function(html, rawText, buttonsHtml) {
                        // M8：AI 原文一律走 rawText 分支，用 .text() 插入
                        // （#sky-res-content 已是 white-space:pre-wrap，换行
                        // 直接保留，不用再转 <br>）。html 分支只接受本文件
                        // 内部拼接的可信标记（标题选择按钮/SEO 得分/操作按钮），
                        // 其中所有 AI 派生片段在拼接前已转义；直接插 AI 原文
                        // 的调用已全部改为传 rawText。buttonsHtml 同样只接受
                        // 内部静态按钮串。
                        var $box = $('#sky-res-content');
                        if (rawText !== undefined && rawText !== null) {
                            $box.text(rawText);
                            $box.data('raw', rawText);
                        } else {
                            $box.html(html || '');
                            $box.removeData('raw');
                        }
                        if(buttonsHtml) $('#sky-res-actions').html(buttonsHtml).show(); else $('#sky-res-actions').hide().empty();
                        $('#sky-res-box').show();
                    }
                };
            })(jQuery);
            </script>
            <?php
        }, 'post', 'side', 'high');
    }
}
