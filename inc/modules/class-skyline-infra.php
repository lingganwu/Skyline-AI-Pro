<?php
if (!defined('ABSPATH')) exit;

class Skyline_Infra {
    private static $instance = null;
    private $redis = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        $this->init_redis();
        if (class_exists('Skyline_Redis_Mod')) new Skyline_Redis_Mod();
        if (class_exists('Skyline_Turbo_Mod')) new Skyline_Turbo_Mod();
        if (class_exists('Skyline_OSS_Mod')) new Skyline_OSS_Mod();
    }

    private function init_redis() {
        if (!class_exists('Redis')) return;
        $opts = get_option('skyline_ai_settings', []);
        if (!is_array($opts)) $opts = [];
        if (empty($opts['redis_enable'])) return;

        try {
            $redis = new Redis();
            // connect() 必须传超时并检查返回值：phpredis 连接失败时通常返回 false
            // 而不是抛异常；不检查会留下一个未连接的 Redis 对象，后续调用直接抛
            // RedisException，导致整站白屏，且后台看板仍显示“Redis 已启用”。
            $connected = $redis->connect($opts['redis_host'] ?? '127.0.0.1', (int)($opts['redis_port'] ?? 6379), 2);
            if (!$connected) {
                $this->redis = null;
                error_log('Skyline Redis: connect() failed');
                $this->log_redis_error('Redis 连接失败（connect 返回 false）');
                return;
            }
            if (!empty($opts['redis_auth']) && !$redis->auth($opts['redis_auth'])) {
                $this->redis = null;
                error_log('Skyline Redis: auth() failed');
                $this->log_redis_error('Redis 认证失败');
                return;
            }
            if (!empty($opts['redis_db']) && !$redis->select((int)$opts['redis_db'])) {
                $this->redis = null;
                error_log('Skyline Redis: select() failed');
                $this->log_redis_error('Redis select() 失败');
                return;
            }

            if (isset($opts['redis_serializer']) && $opts['redis_serializer'] === 'igbinary' && defined('Redis::SERIALIZER_IGBINARY')) {
                $redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_IGBINARY);
            }
            if (isset($opts['redis_compression']) && $opts['redis_compression'] === 'zstd' && defined('Redis::COMPRESSION_ZSTD')) {
                $redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_ZSTD);
            }
            // LZF 分支：之前只有 zstd 被处理，站上选中的 LZF 等于没开压缩。
            // 只有 phpredis 编译进 LZF 支持时常量才存在；不存在时保持不压缩
            // （与修前行为一致），而不是静默假装已压缩。
            if (isset($opts['redis_compression']) && $opts['redis_compression'] === 'lzf' && defined('Redis::COMPRESSION_LZF')) {
                $redis->setOption(Redis::OPT_COMPRESSION, Redis::COMPRESSION_LZF);
            }
            $this->redis = $redis;
        } catch (Throwable $e) {
            $this->redis = null;
            error_log('Skyline Redis init failed: ' . $e->getMessage());
            $this->log_redis_error('Redis 初始化失败：' . $e->getMessage());
        }
    }

    // Redis 故障时写插件日志（init 阶段 Skyline_Core 可能尚未就绪，故包一层防护）。
    private function log_redis_error($msg) {
        if (!class_exists('Skyline_Core')) return;
        try { Skyline_Core::instance()->log($msg, 'error', 'Redis'); } catch (Throwable $ignored) {}
    }

    // Redis 操作统一防护：连接中途断开时抛 RedisException，捕获后降级为“无缓存”，
    // 绝不能让一次缓存读写拖垮整页请求。
    private function redis_safe(callable $fn) {
        if (!$this->redis) return null;
        try {
            return $fn($this->redis);
        } catch (Throwable $e) {
            $this->redis = null;
            error_log('Skyline Redis operation failed: ' . $e->getMessage());
            $this->log_redis_error('Redis 操作失败，已降级为无缓存：' . $e->getMessage());
            return null;
        }
    }

    // 锁操作不走 redis_safe：锁拿不到只意味着“本次不锁”，绝不能因为一次
    // SET 失败就把整站的 Redis 连接置 null（redis_safe 的降级语义只适用于缓存读写）。
    private function redis_try(callable $fn) {
        if (!$this->redis) return null;
        try {
            return $fn($this->redis);
        } catch (Throwable $e) {
            error_log('Skyline Redis lock op failed: ' . $e->getMessage());
            return null;
        }
    }

    // ============ 统计计数器（Redis INCR，替代 wp_options 写库）============
    // 看板读的 stat_get('cache_hits') 之前恒为 0：全仓库没人调用过
    // stat_inc('cache_hits')。直接调 stat_inc() 也不行——它每次都要
    // get_option + update_option 写 wp_options，在页面缓存命中这种热路径上
    // 调用比缓存本身还贵，且高并发下读-改-写会互相覆盖丢统计。
    // 改走 Redis INCR：一次往返、无锁竞争；首次使用时把旧 option 里的累计值
    // 迁移进来（setnx 保证只迁移一次），看板数字不会归零。
    private $stats_seeded = [];

    // 统计走 redis_try 而不是 redis_safe：INCR/GET 失败只丢弃本次统计，
    // 绝不能因此把整站 Redis 连接置 null（与锁操作同一规矩，P4）。
    public function stat_incr($key, $val = 1) {
        return $this->redis_try(function ($redis) use ($key, $val) {
            $rkey = 'sky:stats:' . $key;
            if (!isset($this->stats_seeded[$key])) {
                $this->stats_seeded[$key] = true;
                if (!$redis->exists($rkey)) {
                    $legacy = get_option('skyline_ai_stats', []);
                    $seed = (is_array($legacy) && isset($legacy[$key])) ? (int)$legacy[$key] : 0;
                    if ($seed > 0) $redis->setnx($rkey, $seed);
                }
            }
            return $redis->incrBy($rkey, (int)$val);
        });
    }

    public function stat_get_redis($key) {
        return $this->redis_try(function ($redis) use ($key) {
            $v = $redis->get('sky:stats:' . $key);
            return $v === false ? null : (int)$v;
        });
    }

    // ============ 分布式锁（防缓存惊群）============
    // 返回值：string token = 拿到锁；false = 锁正被其他请求持有；null = Redis
    // 不可用或锁操作失败（调用方应直接放行生成，不要等待）。
    public function lock_acquire($key, $ttl = 10) {
        $token = function_exists('random_bytes') ? bin2hex(random_bytes(8)) : substr(md5(uniqid('sky_lock_', true)), 0, 16);
        $ok = $this->redis_try(function ($redis) use ($key, $ttl, $token) {
            // SET NX EX 必须原子完成：分开 setnx + expire 一旦中间崩掉，
            // 锁将永不过期。options 数组形式需 phpredis >= 2.2.7。
            return $redis->set('sky:lock:' . $key, $token, ['nx', 'ex' => (int)$ttl]);
        });
        if ($ok === null) return null; // Redis 不可用 / 操作异常
        return $ok ? $token : false;
    }

    // 只有持有者才能释放（Lua 比对 token），防止误删他人刚拿到的锁。
    // 释放失败也无妨：锁本身带过期，最多 10 秒后自动失效。
    public function lock_release($key, $token) {
        $this->redis_try(function ($redis) use ($key, $token) {
            $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
            if (method_exists($redis, 'eval')) {
                return $redis->eval($script, ['sky:lock:' . $key, (string)$token], 1);
            }
            return null;
        });
    }

    // ============ 缓存值编码（带类型前缀，防 JSON 类型混淆）============
    // 旧格式：数组 json_encode、字符串原样存；取时只要是合法 JSON 的字符串
    // 都会被当成数组返回，存 null 又 fallback 回原字符串。
    // 新格式一律加 'sky:v1:<类型>:' 前缀：s 字符串 / j JSON 数组或对象 /
    // i 整数 / f 浮点 / b 布尔 / n null。读到无前缀的值走旧格式兼容分支
    // （升级前写入的 key 最长一个 TTL 后自然过期，不会报错）。
    private function cache_encode($val) {
        if (is_string($val)) return 'sky:v1:s:' . $val;
        if (is_array($val)) return 'sky:v1:j:' . json_encode($val);
        if (is_int($val)) return 'sky:v1:i:' . $val;
        if (is_float($val)) return 'sky:v1:f:' . $val;
        if (is_bool($val)) return 'sky:v1:b:' . ($val ? '1' : '0');
        if ($val === null) return 'sky:v1:n:';
        return 'sky:v1:j:' . json_encode($val);
    }

    private function cache_decode($raw) {
        if (!is_string($raw)) return $raw;
        if (strpos($raw, 'sky:v1:') === 0) {
            $type = substr($raw, 7, 1);
            $payload = substr($raw, 9);
            switch ($type) {
                case 's': return $payload;
                case 'j': $d = json_decode($payload, true); return $d === null ? $payload : $d;
                case 'i': return (int)$payload;
                case 'f': return (float)$payload;
                case 'b': return $payload === '1';
                case 'n': return null;
                default: return null; // 未知类型当未命中，不报错
            }
        }
        // 旧格式兼容：数组是 JSON、其他是原样字符串。
        $decoded = json_decode($raw, true);
        return $decoded !== null ? $decoded : $raw;
    }

    public function cache_get($key, $fallback = null, $ttl = 3600, $track = true) {
        if ($this->redis) {
            $val = $this->redis_safe(function ($redis) use ($key) {
                return $redis->get('sky:' . $key);
            });
            if ($val !== false && $val !== null) {
                // 命中埋点：页面缓存、call_api、get_opt 都走 cache_get，
                // 在这里统一计数一次即可覆盖三处，不会重复计数。
                // Redis INCR，不写 wp_options（见 stat_incr 注释）。
                if ($track) $this->stat_incr('cache_hits');
                return $this->cache_decode($val);
            }
            // $val === false 是 key 不存在（真未命中）；$val === null 是
            // Redis 操作异常（已降级），不计入未命中。
            if ($val === false && $track) $this->stat_incr('cache_miss');
        }
        if (is_callable($fallback)) {
            $val = $fallback();
            if ($this->redis) {
                $this->redis_safe(function ($redis) use ($key, $val, $ttl) {
                    $redis->setex('sky:' . $key, $ttl, $this->cache_encode($val));
                });
            }
            return $val;
        }
        return null;
    }

    public function cache_set($key, $val, $ttl = 3600) {
        if ($this->redis) {
            $this->redis_safe(function ($redis) use ($key, $val, $ttl) {
                $redis->setex('sky:' . $key, $ttl, $this->cache_encode($val));
            });
        }
    }

    public function cache_del($key) {
        if ($this->redis) {
            $this->redis_safe(function ($redis) use ($key) {
                $redis->del('sky:' . $key);
            });
        }
    }

    // 按前缀批量删（发文清理用）：Redis SCAN + DEL 分批删，绝不用 KEYS
    // （KEYS 会阻塞整个 Redis，站上 2 核机扛不住）。
    public function cache_del_prefix($prefix) {
        $this->redis_safe(function ($redis) use ($prefix) {
            $pattern = 'sky:' . $prefix . '*';
            $it = null;
            do {
                $keys = $redis->scan($it, $pattern, 100);
                if ($keys === false) break;
                if (!empty($keys)) $redis->del($keys);
            } while ($it !== 0 && $it !== null);
            return true;
        });
    }
}

// ====================== Redis 缓存模块 ======================
if (!class_exists('Skyline_Redis_Mod')) {
class Skyline_Redis_Mod {
    public function __construct() {
        add_action('init', [$this, 'page_cache'], 0);
        add_action('save_post', [$this, 'smart_flush'], 10, 2);
        add_action('deleted_post', [$this, 'flush_on_delete'], 10, 1);
    }

    public function page_cache() {
        if (!class_exists('Skyline_Core')) return;
        $core = Skyline_Core::instance();

        if(!$core->get_opt('redis_enable') || is_user_logged_in() || is_admin() || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

        $uri = $_SERVER['REQUEST_URI'] ?? '/';

        // 内置排除（不受用户配置影响，防 REST/RSS/搜索/预览/AMP 被当 HTML 缓存）：
        // 即使用户在 redis_exclude 里清空了配置，这些也不会被缓存。
        $builtin_excludes = ['/wp-json/', '/cart/', '/checkout/', 'rest_route', '/feed/'];
        $excludes = array_merge($builtin_excludes, explode("\n", (string)$core->get_opt('redis_exclude', '')));
        foreach ($excludes as $ex) { if (trim($ex) && strpos($uri, trim($ex)) !== false) return; }

        // 路径与查询参数级排除：/feed（无斜杠）、?s= 搜索、?preview= 预览、
        // ?amp、?rest_route=。注意不能只用 'amp' 子串匹配——/camp/ 这类
        // 正常路径里也有 amp。
        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '/';
        if (preg_match('#(^|/)feed/?$#', $path) || strpos($path, '/feed/') !== false) return;
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            if (isset($query['rest_route']) || isset($query['s']) || isset($query['preview']) || isset($query['amp'])) return;
        }

        $infra = Skyline_Infra::instance();
        $cache_uri = $uri;
        $key = 'page_' . md5(home_url($cache_uri));

        $cached = $infra->cache_get($key);
        if ($cached) $this->serve_page_cache($cached);

        // 缓存未命中：防惊群。只放一个请求回源生成，其他请求短暂等待
        // （最多 10 秒），等到了直接用新缓存；等不到也不阻塞访客，自己生成。
        // Redis 不可用时 lock_acquire() 返回 null，不等待、直接生成。
        // 锁 TTL 30 秒：本站页面生成要 12–14 秒，10 秒锁会在持有者还没
        // 生成完时过期，等于没锁（P2）；等待上限 10 秒仍短于 FPM 超时。
        $lock_token = $infra->lock_acquire($key, 30);
        if ($lock_token === false) {
            for ($i = 0; $i < 50; $i++) {
                usleep(200000);
                $cached = $infra->cache_get($key, null, 3600, false);
                if ($cached) {
                    // 等待期间命中的这次单独计一次命中（轮询本身不计数，
                    // 否则一次等待会刷出 10 次未命中）。
                    $infra->stat_incr('cache_hits');
                    $this->serve_page_cache($cached);
                }
            }
        }

        // 页面缓存 TTL 上限 600 秒：EdgeOne 的 HTML 缓存只有 2 分钟，
        // 源站 Redis 若还提供 1 小时前的旧页面，实际新鲜度以最长 TTL 为准。
        // redis_ttl 选项仍管 API 缓存等其他缓存；页面缓存取二者较小值。
        $page_ttl = (int)$core->get_opt('redis_ttl', 3600);
        $page_ttl = $page_ttl > 600 ? 600 : max($page_ttl, 60);

        ob_start(function($buf) use ($infra, $key, $page_ttl, $lock_token) {
            if (strlen($buf) > 200 && http_response_code() === 200 && !is_404()) {
                // 只缓存 HTML：万一排除规则漏掉 JSON/XML，也不进页面缓存。
                $ctype = 'text/html; charset=UTF-8';
                foreach (headers_list() as $h) {
                    if (stripos($h, 'Content-Type:') === 0) { $ctype = trim(substr($h, 13)); break; }
                }
                if (stripos($ctype, 'text/html') !== false) {
                    $infra->cache_set($key, ['status' => 200, 'ctype' => $ctype, 'body' => $buf], $page_ttl);
                }
            }
            if ($lock_token) $infra->lock_release($key, $lock_token);
            return $buf;
        });
    }

    // 命中时恢复当初缓存的状态码和 Content-Type（旧版只 echo、不恢复，
    // RSS 会以 text/html 被阅读器消费、REST JSON 会被当 HTML 返回）。
    // 升级前存的纯字符串旧缓存仍按原样输出（默认 text/html）。
    private function serve_page_cache($cached) {
        if (is_array($cached) && isset($cached['body'])) {
            http_response_code((int)($cached['status'] ?? 200));
            if (!empty($cached['ctype'])) header('Content-Type: ' . $cached['ctype']);
            header('X-Sky-Redis: HIT');
            echo $cached['body'];
            exit;
        }
        if (is_string($cached)) {
            header('X-Sky-Redis: HIT');
            echo $cached;
            exit;
        }
        return false;
    }

    public function smart_flush($pid, $post) {
        if(wp_is_post_revision($pid)) return;
        $core = Skyline_Core::instance();
        $infra = Skyline_Infra::instance();
        // redis_smart_purge 开启（默认 true）：按前缀批量清全部页面缓存，
        // 分类/标签/Feed/归档一起刷新；下架、草稿、移入回收站同样清理，
        // 已下架文章的缓存页不再存活到 TTL 结束。
        // 关闭时退回旧行为：只清首页和本篇。
        if ($core->get_opt('redis_smart_purge', true)) {
            $infra->cache_del_prefix('page_');
            return;
        }
        $infra->cache_del('page_' . md5(home_url('/')));
        $infra->cache_del('page_' . md5(get_permalink($pid)));
    }

    public function flush_on_delete($pid) {
        if (!Skyline_Core::instance()->get_opt('redis_smart_purge', true)) return;
        Skyline_Infra::instance()->cache_del_prefix('page_');
    }
}
}

// ====================== 图片压缩优化模块 ======================
if (!class_exists('Skyline_Turbo_Mod')) {
class Skyline_Turbo_Mod {
    public function __construct() {
        add_filter('wp_generate_attachment_metadata', [$this, 'compress'], 5, 2);
        // 以下三个开关之前只在配置表里存在、代码里没人读（死选项），现逐个实现。
        // 注意：这里只注册 filter，开关在回调里再读 get_opt——构造阶段读
        // get_opt 会触发 Skyline_Infra::instance() 重入（实例尚未赋值完成），
        // 造成无限递归；回调执行时实例早已就绪，是安全的。
        // 懒加载：只影响 WP 自身的 loading="lazy" 判定，不碰子比主题自带的
        // 懒加载实现（主题管缩略图/头像等，本 filter 只对文章内容图片生效
        // 的那部分起作用，二者不冲突）。
        add_filter('wp_lazy_loading_enabled', [$this, 'force_lazy_load'], 10, 3);
        add_filter('upload_mimes', [$this, 'allow_svg_mime']);
        add_filter('wp_check_filetype_and_ext', [$this, 'check_svg_filetype'], 10, 4);
        add_filter('wp_handle_upload_prefilter', [$this, 'sanitize_svg_upload']);
        add_filter('upload_size_limit', [$this, 'limit_upload_size']);
    }

    public function force_lazy_load($default, $tag_name, $context) {
        if (!Skyline_Core::instance()->get_opt('turbo_lazy_load')) return $default;
        return true;
    }

    public function allow_svg_mime($mimes) {
        if (!Skyline_Core::instance()->get_opt('turbo_allow_svg')) return $mimes;
        $mimes['svg'] = 'image/svg+xml';
        return $mimes;
    }

    // WP 的 getimagesize 识别不了 SVG，mime 会落空；扩展名是 svg 且开关
    // 开着时直接按 SVG 处理。
    public function check_svg_filetype($data, $file, $filename, $mimes) {
        if (!empty($data['ext']) && !empty($data['type'])) return $data;
        if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'svg') return $data;
        if (!Skyline_Core::instance()->get_opt('turbo_allow_svg')) return $data;
        $data['ext'] = 'svg';
        $data['type'] = 'image/svg+xml';
        return $data;
    }

    // SVG 安全消毒：SVG 是 XML，可能内嵌脚本、事件处理器、外部引用。
    // 这是正则级检查（非完整清洗器，见注释），能挡住常见攻击面；
    // 需要更严格清洗时请引入 enshrined/svg-sanitize 之类的专用库。
    public function sanitize_svg_upload($file) {
        if (!Skyline_Core::instance()->get_opt('turbo_allow_svg')) return $file;
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'svg') return $file;
        $content = @file_get_contents($file['tmp_name']);
        if ($content === false) {
            $file['error'] = '无法读取 SVG 文件';
            return $file;
        }
        $dangerous = [
            '/<\s*script/i',                                              // 内嵌脚本
            '/<\s*(iframe|object|embed|form|foreignObject|animate|set|handler)/i',
            '/\son\w+\s*=/i',                                             // 事件处理器
            '/javascript\s*:/i',                                          // JS 伪协议
            '/<\s*!\s*ENTITY/i',                                          // XXE 实体声明
            '/href\s*=\s*["\']\s*(javascript|data\s*:)/i',                // 危险 href
        ];
        foreach ($dangerous as $pattern) {
            if (preg_match($pattern, $content)) {
                $file['error'] = 'SVG 文件含有不安全内容，已拒绝上传';
                return $file;
            }
        }
        $xml = @simplexml_load_string($content);
        if ($xml === false || strtolower($xml->getName()) !== 'svg') {
            $file['error'] = 'SVG 文件格式无效';
            return $file;
        }
        return $file;
    }

    public function limit_upload_size($size) {
        if (!Skyline_Core::instance()->get_opt('turbo_limit_5m')) return $size;
        return min((int)$size, 5 * 1024 * 1024);
    }

    public function compress($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('turbo_enable_image_opt')) return $metadata;

        $file = get_attached_file($attachment_id);
        if(!$file || !file_exists($file) || filesize($file) > 10 * 1024 * 1024) return $metadata;

        $type = get_post_mime_type($attachment_id);
        if(!in_array($type, ['image/jpeg', 'image/png', 'image/webp'])) return $metadata;

        $quality = (int)$core->get_opt('turbo_quality', 85);

        // 已压记录：尺寸名 => 压缩后的文件 md5。重新生成缩略图时，只有内容
        // 变了的文件才重新压——既防止反复有损压缩，又不漏掉新生成的尺寸。
        // （只记文件名不够：缩略图被重新生成后文件名不变、内容变了。）
        $done = get_post_meta($attachment_id, '_sky_compressed', true);
        if (!is_array($done)) $done = [];

        // 1. 原图：save() 返回值必须检查（PR #3 已加），保存失败直接返回，
        // 不上传、不删；成功后回填元数据（resize 后旧的 width/height/filesize
        // 否则会让前台 srcset/sizes 和后台显示的尺寸全错）。
        if (!isset($done['__original__']) || $done['__original__'] !== md5_file($file)) {
            $editor = wp_get_image_editor($file);
            if (!is_wp_error($editor)) {
                $editor->set_quality($quality);
                $size = $editor->get_size();
                if($size && $size['width'] > 2560) $editor->resize(2560, null, false);

                // save() 返回值必须检查：保存失败（磁盘满/权限/内存不足）时原图可能已损坏，
                // 若继续走 OSS 上传 + Zero-Disk 删除，坏图进桶、本地原图丢失，一步到位。
                $saved = $editor->save($file);
                unset($editor);
                if (is_wp_error($saved)) {
                    $core->log("⚠️ [Turbo] 压缩保存失败，已跳过上传/删除：{$file} | " . $saved->get_error_message(), 'warn', 'Turbo');
                    return $metadata;
                }
                if (isset($saved['width'])) $metadata['width'] = (int)$saved['width'];
                if (isset($saved['height'])) $metadata['height'] = (int)$saved['height'];
                $metadata['filesize'] = filesize($file);
                $done['__original__'] = md5_file($file);
            }
        }

        // 2. 缩略图：WP 是先生成完所有缩略图、再跑 wp_generate_attachment_metadata
        // 这个 filter，所以旧代码只压得到原图——访客实际看的 medium/large/srcset
        // 全是未压缩的缩略图。这里在同一个钩子里把每个缩略图文件同样重压一遍，
        // 并同步更新元数据里的 filesize/width/height。
        // 注意顺序：本钩子优先级 5，OSS 上传钩子优先级 99，压完再上传，COS 上
        // 拿到的就是压过的文件。
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($file);
            foreach ($metadata['sizes'] as $size_name => $size_info) {
                if (empty($size_info['file'])) continue;
                $size_file = $base_dir . '/' . $size_info['file'];
                if (!file_exists($size_file)) continue;
                if (isset($done[$size_name]) && $done[$size_name] === md5_file($size_file)) continue;
                $ftype = wp_check_filetype($size_file);
                if (!in_array($ftype['type'], ['image/jpeg', 'image/png', 'image/webp'])) continue;

                $editor = wp_get_image_editor($size_file);
                if (is_wp_error($editor)) continue;
                $editor->set_quality($quality);
                $saved = $editor->save($size_file);
                unset($editor);
                if (is_wp_error($saved)) continue; // 单张缩略图失败不影响其他
                if (isset($saved['width'])) $metadata['sizes'][$size_name]['width'] = (int)$saved['width'];
                if (isset($saved['height'])) $metadata['sizes'][$size_name]['height'] = (int)$saved['height'];
                if (file_exists($size_file)) $metadata['sizes'][$size_name]['filesize'] = filesize($size_file);
                $done[$size_name] = md5_file($size_file);
            }
        }

        update_post_meta($attachment_id, '_sky_compressed', $done);
        return $metadata;
    }
}
}

// ====================== 旗舰版：安全生命周期的 OSS 模块 ======================
if (!class_exists('Skyline_OSS_Mod')) {
    class Skyline_OSS_Mod {
        // Zero-Disk 两阶段删除：上传成功后只写待删清单（_sky_oss_pending_delete），
        // 不立即删本地文件；24 小时后由 WP-Cron 二次校验 COS 对象（大小/ETag）通过才删。
        // 旧版在 shutdown 里直接 unlink：上传“成功”判定太弱（见 putFile/verifyFile），
        // 一旦 COS 上是坏对象、本地唯一副本又被删掉，就是永久丢图，且删后仍标记
        // _sky_oss_synced=1、再也不会重新上传。
        const PENDING_DELETE_META = '_sky_oss_pending_delete';
        const PURGE_DELAY = DAY_IN_SECONDS; // 24 小时

        public function __construct() {
            add_filter('wp_generate_attachment_metadata', [$this, 'upload_all_sizes'], 99, 2);
            add_filter('wp_get_attachment_url', [$this, 'replace_url'], 99, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 99, 4);
            add_action('skyline_oss_purge_pending', [$this, 'purge_pending_deletes']);
        }

        private function make_cos_client() {
            $core = Skyline_Core::instance();
            try {
                return new Sky_Official_COS_Client(
                    $core->get_opt('oss_ak'),
                    $core->get_opt('oss_sk'),
                    $core->get_opt('oss_bucket'),
                    $core->get_opt('oss_endpoint')
                );
            } catch (Throwable $e) {
                $core->log('💥 [OSS] 创建 COS 客户端失败：' . $e->getMessage(), 'error', 'OSS');
                return null;
            }
        }

        public function upload_all_sizes($metadata, $attachment_id) {
            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable')) return $metadata;

            $file = get_attached_file($attachment_id);
            if (!$file || !file_exists($file)) return $metadata;

            $core->log("🚀 [OSS] 开始处理附件 #{$attachment_id}: " . basename($file), 'info', 'OSS');

            $client = $this->make_cos_client();
            if (!$client) return $metadata;

            try {
                $upload_dir = wp_upload_dir();
                $base_url_path = trim(parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/') ?: 'wp-content/uploads';
                $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true) ?: basename($file);

                $object_key = $base_url_path . '/' . ltrim($attached_file, '/');
                $success_entries = [];
                $failed_files = [];

                // 1. 上传原图
                if ($client->putFile($object_key, $file)) {
                    $success_entries[] = ['path' => $file, 'key' => $object_key];
                } else {
                    $failed_files[] = ['type' => 'original', 'path' => $file, 'key' => $object_key];
                }

                // 2. 上传缩略图
                if (isset($metadata['sizes']) && is_array($metadata['sizes'])) {
                    $base_dir = dirname($file);
                    $rel_dir = dirname($attached_file);
                    if ($rel_dir === '.') $rel_dir = '';

                    foreach ($metadata['sizes'] as $size => $size_info) {
                        $size_file = $base_dir . '/' . $size_info['file'];
                        if (file_exists($size_file)) {
                            $size_rel_path = $rel_dir ? $rel_dir . '/' . $size_info['file'] : $size_info['file'];
                            $size_object_key = $base_url_path . '/' . ltrim($size_rel_path, '/');

                            if ($client->putFile($size_object_key, $size_file)) {
                                $success_entries[] = ['path' => $size_file, 'key' => $size_object_key];
                            } else {
                                $failed_files[] = ['type' => $size, 'path' => $size_file, 'key' => $size_object_key];
                            }
                        }
                    }
                }

                if (empty($failed_files)) {
                    update_post_meta($attachment_id, '_sky_oss_synced', 1);
                    $core->log("🎉 [OSS] 附件 #{$attachment_id} 全部上传成功！", 'info', 'OSS');

                    if ($core->get_opt('oss_delete_local')) {
                        // 两阶段删除：只登记待删清单并约 24 小时后二次校验，不在本请求内删文件。
                        update_post_meta($attachment_id, self::PENDING_DELETE_META, [
                            'time'  => time(),
                            'files' => $success_entries,
                        ]);
                        if (!wp_next_scheduled('skyline_oss_purge_pending', [$attachment_id])) {
                            wp_schedule_single_event(time() + self::PURGE_DELAY, 'skyline_oss_purge_pending', [$attachment_id]);
                        }
                    }
                } else {
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 存在失败项（共 " . count($failed_files) . " 个）", 'warn', 'OSS');
                }

            } catch (Exception $e) {
                $core->log("💥 [OSS] 处理异常: " . $e->getMessage(), 'error', 'OSS');
            }

            return $metadata;
        }

        // WP-Cron 回调：二次校验待删清单，全部通过才删本地文件。
        // 校验失败（COS 对象缺失/大小或 ETag 不一致）：清除 _sky_oss_synced
        // （让系统不再误以为已同步）、保留本地文件并写错误日志。
        // 注意：本地文件已不在 ≠ 校验失败——本地文件可能被其他途径删掉，
        // 此时只核查 COS 对象是否存在；COS 对象完好就视为已同步，只删待删
        // 清单、不清除 _sky_oss_synced。
        public function purge_pending_deletes($attachment_id) {
            $attachment_id = (int)$attachment_id;
            $pending = get_post_meta($attachment_id, self::PENDING_DELETE_META, true);
            if (!is_array($pending) || empty($pending['files']) || !is_array($pending['files'])) return;

            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable')) {
                // OSS 已关闭：不删文件，保留待删清单，等下次 OSS 开启且重新排期后再处理。
                $core->log("⚠️ [OSS-Zero-Disk] OSS 已关闭，附件 #{$attachment_id} 的本地文件暂不删除", 'warn', 'OSS');
                return;
            }

            try {
                $client = $this->make_cos_client();
                if (!$client) return; // 客户端建不起来：保留清单与本地文件，不删

                $all_ok = true;
                foreach ($pending['files'] as $entry) {
                    if (!is_array($entry) || empty($entry['path']) || empty($entry['key'])) { $all_ok = false; break; }
                    if (file_exists($entry['path'])) {
                        // 本地文件还在：用强校验（大小 + ETag）核对 COS 对象。
                        if (!$client->verifyFile($entry['key'], $entry['path'])) { $all_ok = false; break; }
                    } else {
                        // 本地文件已不在：verifyFile 需要本地文件做比对，用不上；
                        // 只核查 COS 对象是否存在。COS 对象完好即视为已同步，
                        // 这一项算通过（不清除 _sky_oss_synced），仅从待删清单里去掉。
                        if (!$client->remoteExists($entry['key'])) { $all_ok = false; break; }
                    }
                }

                if (!$all_ok) {
                    delete_post_meta($attachment_id, '_sky_oss_synced');
                    delete_post_meta($attachment_id, self::PENDING_DELETE_META);
                    $core->log("❌ [OSS-Zero-Disk] 二次校验失败，附件 #{$attachment_id} 已取消已同步标记，本地文件已保留", 'error', 'OSS');
                    return;
                }

                $deleted_count = 0;
                $seen = [];
                foreach ($pending['files'] as $entry) {
                    if (isset($seen[$entry['path']])) continue;
                    $seen[$entry['path']] = true;
                    if (file_exists($entry['path']) && @unlink($entry['path'])) $deleted_count++;
                }
                delete_post_meta($attachment_id, self::PENDING_DELETE_META);
                if ($deleted_count > 0) {
                    $core->log("🗑️ [OSS-Zero-Disk] 二次校验通过，安全清理了 {$deleted_count} 个本地文件", 'info', 'OSS');
                }
            } catch (Throwable $e) {
                // 校验过程异常：宁可不删。保留清单与本地文件，等下次人工或重新排期处理。
                $core->log("💥 [OSS-Zero-Disk] 二次清理异常，已保留本地文件：附件 #{$attachment_id} | " . $e->getMessage(), 'error', 'OSS');
            }
        }

        public function replace_url($url, $post_id) {
            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable') || !get_post_meta($post_id, '_sky_oss_synced', true)) return $url;
            $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
            return str_replace(rtrim(get_site_url(), '/'), $domain, $url);
        }

        public function replace_image_src($image, $attachment_id, $size, $icon) {
            if (!$image) return $image;
            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable') || !get_post_meta($attachment_id, '_sky_oss_synced', true)) return $image;
            $domain = rtrim($core->get_opt('oss_domain') ?: "https://{$core->get_opt('oss_bucket')}.{$core->get_opt('oss_endpoint')}", '/');
            $image[0] = str_replace(rtrim(get_site_url(), '/'), $domain, $image[0]);
            return $image;
        }
    }
}

if (!class_exists('Sky_Official_COS_Client')) {
    class Sky_Official_COS_Client {
        private $client;
        private $bucket;

        public function __construct($ak, $sk, $bucket, $endpoint) {
            $this->bucket = trim($bucket);
            $region = 'ap-beijing';
            if (preg_match('/cos[-.]([a-z0-9-]+)\.myqcloud/i', $endpoint, $m)) {
                $region = $m[1];
            }

            $autoload = SKY_PATH . 'vendor/autoload.php';
            if (file_exists($autoload)) require_once $autoload;

            $this->client = new \Qcloud\Cos\Client([
                'region'          => $region,
                'schema'          => 'https',
                'credentials'     => ['secretId' => trim($ak), 'secretKey' => trim($sk)],
            ]);
        }

        // 仅核查 COS 上对象是否存在（不依赖本地文件）。
        // 用于本地文件已缺失时的二次校验：COS 对象在，就说明同步是完好的，
        // 不能因此清除 _sky_oss_synced——否则前台 URL 会从能打开的 COS 地址
        // 翻回不存在的本地路径。
        public function remoteExists($key) {
            $key = ltrim($key, '/');
            try {
                $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key
                ]);
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // 强校验：COS 上的对象必须与本地文件逐字节一致才算上传成功。
        // 只调 headObject 查“对象存在”是不够的——网络抖动造成的不完整写入也可能不抛异常，
        // 坏对象一旦被当成成功、Zero-Disk 再删掉本地唯一副本，就是永久丢图。
        public function verifyFile($key, $file) {
            if (!file_exists($file)) return false;
            $key = ltrim($key, '/');
            try {
                $head = $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key
                ]);

                // 1. 大小必须一致
                $remote_size = isset($head['ContentLength']) ? (int)$head['ContentLength'] : null;
                if ($remote_size !== null && $remote_size !== (int)filesize($file)) return false;

                // 2. ETag 必须一致（仅单段上传时 ETag 即文件 MD5；分片上传的 ETag
                // 形如 "<md5>-<分片数>"，无法直接比对，跳过 ETag 校验，只比大小）。
                $etag = isset($head['ETag']) ? trim((string)$head['ETag'], '"') : '';
                if ($etag !== '' && strpos($etag, '-') === false && preg_match('/^[0-9a-f]{32}$/i', $etag)) {
                    $local_md5 = md5_file($file);
                    if ($local_md5 === false || strtolower($etag) !== strtolower($local_md5)) return false;
                }
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        public function putFile($key, $file) {
            if (!file_exists($file)) return false;

            $key = ltrim($key, '/');
            $core = Skyline_Core::instance();
            $size = filesize($file);

            // 大文件（>5MB）走分片上传：旧代码 file_get_contents() 全量读内存，
            // 2G 内存服务器 + 256M PHP 内存限制下，几十 MB 的原图即可能 OOM。
            // COS SDK 的 Upload() 是复合接口：小文件简单上传、大文件自动分片
            // （PartSize 5MB），body 传文件流而非整个字符串。
            // 分片上传的 ETag 形如 "<md5>-<片数>"，verifyFile() 里本来就跳过
            // 含 '-' 的 ETag、只比大小，强校验链条不受影响。
            // 说明：Upload() 的 $options 会按 SDK 惯例合并进 putObject /
            // createMultipartUpload（putObject 分支即如此），ACL 随 options 透传；
            // 若某版本 SDK 拒绝该参数，调用抛异常、这里捕获后返回 false，
            // 文件不标记已同步、本地不删，是安全失败。
            if ($size !== false && $size > 5 * 1024 * 1024) {
                try {
                    $stream = fopen($file, 'rb');
                    if ($stream === false) {
                        $core->log("❌ [OSS] 无法打开本地文件: {$file}", 'error', 'OSS');
                        return false;
                    }
                    try {
                        if (method_exists($this->client, 'upload')) {
                            $this->client->upload($this->bucket, $key, $stream, [
                                'ACL' => 'public-read',
                                'PartSize' => 5 * 1024 * 1024,
                            ]);
                        } else {
                            // 极旧 SDK 没有 Upload()：退回流式 putObject，
                            // 至少不把整个文件读进内存。
                            $this->client->putObject([
                                'Bucket' => $this->bucket,
                                'Key'    => $key,
                                'Body'   => $stream,
                                'ACL'    => 'public-read'
                            ]);
                        }
                    } finally {
                        if (is_resource($stream)) fclose($stream);
                    }
                } catch (\Throwable $e) {
                    // 新分支单独捕获 Throwable：SDK 抛出的 Error（如 TypeError）
                    // 不能穿透到媒体上传导致 fatal；这里只记日志并返回失败，
                    // 让 upload_all_sizes 走失败分支（不写已同步标记、不删本地）。
                    // 注意：这里只包新分支，旧的 catch (Exception) 原样不动（N4 不在本批）。
                    $core->log("❌ [OSS] 大文件分片上传失败: {$key} | 错误: " . $e->getMessage(), 'error', 'OSS');
                    return false;
                }

                if (!$this->verifyFile($key, $file)) {
                    $core->log("❌ [OSS] 上传后强校验失败（大小/ETag 不一致）: {$key}", 'error', 'OSS');
                    return false;
                }
                $core->log("✅ [OSS] 大文件分片上传并真实验证成功 : {$key}", 'info', 'OSS');
                return true;
            }

            try {
                $body = file_get_contents($file);
                if ($body === false) {
                    throw new Exception('无法读取本地文件: ' . $file);
                }

                $this->client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key,
                    'Body'   => $body,
                    'ACL'    => 'public-read'
                ]);

                // 上传后强校验（大小 + ETag），任一不一致即视为失败，不写已同步标记、不排队删除。
                if (!$this->verifyFile($key, $file)) {
                    $core->log("❌ [OSS] 上传后强校验失败（大小/ETag 不一致）: {$key}", 'error', 'OSS');
                    return false;
                }

                $core->log("✅ [OSS] 上传并真实验证成功 : {$key}", 'info', 'OSS');
                return true;

            } catch (\Exception $e) {
                $core->log("❌ [OSS] 上传失败: {$key} | 错误: " . $e->getMessage(), 'error', 'OSS');
                return false;
            }
        }
    }
}