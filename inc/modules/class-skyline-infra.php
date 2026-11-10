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
        // M12：短期熔断——上次连接失败后 60 秒内不再尝试连接。标记存在
        // transient（落 wp_options，不依赖 Redis 自身）；到期自动恢复，
        // 避免 Redis 宕机时每个请求都等 2 秒连接超时、拖垮 FPM 工人。
        if (function_exists('get_transient') && get_transient('sky_redis_circuit_open')) return;

        try {
            $redis = new Redis();
            // connect() 必须传超时并检查返回值：phpredis 连接失败时通常返回 false
            // 而不是抛异常；不检查会留下一个未连接的 Redis 对象，后续调用直接抛
            // RedisException，导致整站白屏，且后台看板仍显示“Redis 已启用”。
            $connected = $redis->connect($opts['redis_host'] ?? '127.0.0.1', (int)($opts['redis_port'] ?? 6379), 2);
            if (!$connected) {
                $this->redis = null;
                $this->open_redis_circuit();
                error_log('Skyline Redis: connect() failed');
                $this->log_redis_error('Redis 连接失败（connect 返回 false）');
                return;
            }
            if (!empty($opts['redis_auth']) && !$redis->auth($opts['redis_auth'])) {
                $this->redis = null;
                $this->open_redis_circuit();
                error_log('Skyline Redis: auth() failed');
                $this->log_redis_error('Redis 认证失败');
                return;
            }
            if (!empty($opts['redis_db']) && !$redis->select((int)$opts['redis_db'])) {
                $this->redis = null;
                $this->open_redis_circuit();
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
            // L2：选了压缩但常量不存在时记一条 warn 日志，不要静默失效——
            // phpredis 6.x 起不再定义 COMPRESSION_LZF，升级后站上选中的
            // LZF 会悄悄回退为不压缩，后台应有提示。但 init_redis 每个请
            // 求都跑，同一句警告不能每请求写一行：每天只记一次（transient
            // 去重，键里带压缩名、换了选项会重新提示），其余请求静默回退。
            if (isset($opts['redis_compression']) && in_array($opts['redis_compression'], ['zstd', 'lzf'], true)) {
                $const = $opts['redis_compression'] === 'zstd' ? 'Redis::COMPRESSION_ZSTD' : 'Redis::COMPRESSION_LZF';
                if (!defined($const)) {
                    $warn_key = 'sky_redis_compression_warned_' . $opts['redis_compression'];
                    $warned = function_exists('get_transient') ? get_transient($warn_key) : false;
                    if (!$warned) {
                        if (function_exists('set_transient')) set_transient($warn_key, 1, DAY_IN_SECONDS);
                        error_log('Skyline Redis: compression ' . $opts['redis_compression'] . ' unavailable (constant ' . $const . ' not defined), falling back to no compression');
                        $this->log_redis_error('Redis 压缩 ' . $opts['redis_compression'] . ' 不可用（phpredis 未提供该常量），已回退为不压缩');
                    }
                }
            }
            $this->redis = $redis;
        } catch (Throwable $e) {
            $this->redis = null;
            $this->open_redis_circuit();
            error_log('Skyline Redis init failed: ' . $e->getMessage());
            $this->log_redis_error('Redis 初始化失败：' . $e->getMessage());
        }
    }

    // M12：打开 Redis 熔断——60 秒内不再尝试连接（见 init_redis 注释）。
    private function open_redis_circuit() {
        if (function_exists('set_transient')) set_transient('sky_redis_circuit_open', 1, 60);
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
    // 释放失败也无妨：锁本身带过期，最多 30 秒后自动失效（与 page_cache()
    // 里 lock_acquire($key, 30) 的 TTL 一致；lock_acquire() 的默认参数
    // 10 秒只是形参默认值，实际调用方传 30 秒）。
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
            // L27 同款守卫：ttl<=0 时不写，setex(key, 0) 会抛异常并经
            // redis_safe() 把整站 Redis 连接置 null（见 cache_set 注释）。
            if ($this->redis && (int)$ttl > 0) {
                $this->redis_safe(function ($redis) use ($key, $val, $ttl) {
                    $redis->setex('sky:' . $key, $ttl, $this->cache_encode($val));
                });
            }
            return $val;
        }
        return null;
    }

    // L27：TTL 钳制——ttl<=0 时直接不写。redis_ttl=0 时 setex(key, 0)
    // 会被 Redis 拒收、抛异常，redis_safe() 随即将整站 Redis 连接置 null，
    // 当次请求后续缓存全跳过。注意：page_ttl 下限 60 秒的 max() 仍在
    // page_cache() 里保留（管理员填更短的页面 TTL 时以 60 秒为准）。
    public function cache_set($key, $val, $ttl = 3600) {
        $ttl = (int)$ttl;
        if ($ttl <= 0) return;
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
    // v2.2.3（P-6）：返回 redis_safe() 的结果——true 表示已执行清理，
    // null 表示 Redis 不可用（未连接或中途断开），供手动清缓存入口
    // 区分“已清”与“未清”，不静默成功。空前缀对应模式 'sky:*'（清
    // 全部 Skyline 键），调用方只传 '' 或 'page_' 两种前缀。
    public function cache_del_prefix($prefix) {
        return $this->redis_safe(function ($redis) use ($prefix) {
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
        // S4：init 优先级 0 → 20。WP 核心把 wp_cron() 挂在 init 默认优先级
        // 10（wp-includes/default-filters.php），wp_cron() 只负责把
        // _wp_cron() 注册到 shutdown 钩子（WP 6.9+；6.9 之前是注册到
        // wp_loaded，同样在 init:10 之后）。page_cache() 命中时直接
        // exit，而 exit 会触发已注册的 shutdown 钩子——优先级 0 时 exit
        // 发生在 wp_cron() 注册之前，缓存命中请求永远不触发 cron spawn，
        // 爬虫/自动 SEO/Zero-Disk 删除在低流量时段系统性延后；改到 20
        // 后 wp_cron() 已在 10 注册，exit 仍会走到 shutdown、触发 _wp_cron。
        // 仓库里另一处 init 注册是 Turbo 的 apply_turbo_switches（默认优
        // 先级 10，只做 emoji/embeds/xmlrpc 的移除，不读页面缓存、不依赖
        // page_cache 在 0 运行）；也不要同时加服务器 crontab（与本修复二选一）。
        add_action('init', [$this, 'page_cache'], 20);
        add_action('transition_post_status', [$this, 'smart_flush'], 10, 3);
        add_action('deleted_post', [$this, 'flush_on_delete'], 10, 2);
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
        $key = $this->page_cache_key($uri);

        $cached = $infra->cache_get($key);
        if ($cached) $this->serve_page_cache($cached);

        // 缓存未命中：防惊群。只放一个请求回源生成，其他请求短暂等待
        // （M11：最多 3 秒，15×200ms）。持有者生成要 12–14 秒，等满 3 秒
        // 还没好就自己生成——等待更久只会白占 FPM 工人（2 核小服务器工人
        // 本就不多，10 秒等待曾把工人占用放大 5 倍、且仍等不到 12 秒的
        // 生成结果）。等到了直接用新缓存；等不到也不阻塞访客，自己生成。
        // Redis 不可用时 lock_acquire() 返回 null，不等待、直接生成。
        // 锁 TTL 30 秒：本站页面生成要 12–14 秒，10 秒锁会在持有者还没
        // 生成完时过期，等于没锁（P2）。
        $lock_token = $infra->lock_acquire($key, 30);
        if ($lock_token === false) {
            for ($i = 0; $i < 15; $i++) {
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

        // 页面缓存 TTL 上限 600 秒：EdgeOne 的 HTML 缓存只有 30 分钟，
        // 源站 Redis 若还提供 1 小时前的旧页面，实际新鲜度以最长 TTL 为准。
        // redis_ttl 选项仍管 API 缓存等其他缓存；页面缓存取二者较小值。
        $page_ttl = (int)$core->get_opt('redis_ttl', 3600);
        $page_ttl = $page_ttl > 600 ? 600 : max($page_ttl, 60);

        ob_start(function($buf) use ($infra, $key, $page_ttl, $lock_token) {
            if (strlen($buf) > 200 && http_response_code() === 200 && !is_404()) {
                // 只缓存 HTML：万一排除规则漏掉 JSON/XML，也不进页面缓存。
                // L10：响应没有 Content-Type 头时不写缓存——不要用 text/html
                // 兜底，否则非 HTML 响应会被写进页面缓存。
                $ctype = '';
                foreach (headers_list() as $h) {
                    if (stripos($h, 'Content-Type:') === 0) { $ctype = trim(substr($h, 13)); break; }
                }
                if ($ctype !== '' && stripos($ctype, 'text/html') !== false) {
                    $infra->cache_set($key, ['status' => 200, 'ctype' => $ctype, 'body' => $buf], $page_ttl);
                }
            }
            if ($lock_token) $infra->lock_release($key, $lock_token);
            return $buf;
        });
    }

    // L25：页面缓存 key 白名单——只保留影响内容的查询参数（分页、
    // 文章 ID、分类/标签/作者/日期等 WP 核心查询变量），其余查询串一律
    // 剥离；参数按字母序重排（顺序不同也命中同一 key）。修前任意查询串
    // 都生成独立 key（?a=1、?a=2… 各一个），600 秒 TTL 内可被随机串
    // 刷出大量无用 key 挤占 Redis。?s=、?preview=、?amp、rest_route
    // 本就被排除，不受影响。
    public function page_cache_key($uri) {
        $parsed = parse_url($uri);
        $path = $parsed['path'] ?? '/';
        $query = [];
        if (!empty($parsed['query'])) {
            parse_str($parsed['query'], $query);
            $allowed = ['paged', 'page', 'p', 'cpage', 'post_type', 'cat', 'category_name', 'tag', 'author', 'year', 'monthnum', 'day'];
            foreach ($query as $k => $v) {
                if (!in_array(strtolower($k), $allowed, true)) unset($query[$k]);
            }
            ksort($query);
        }
        $cache_uri = $path . (!empty($query) ? '?' . http_build_query($query) : '');
        return 'page_' . md5(home_url($cache_uri));
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
        // L26：升级前存的纯字符串旧缓存没有存过 Content-Type/状态码，
        // 只能恢复成它当初唯一的合法形态——text/html + 200（页面缓存写
        // 入时只收 text/html 响应）。不要原样输出：不显式设置时响应头
        // 全靠 PHP 默认值，且状态码可能是上一个请求的残留。
        if (is_string($cached)) {
            http_response_code(200);
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Sky-Redis: HIT');
            echo $cached;
            exit;
        }
        return false;
    }

    // M3（补修）：save_post 每次保存都全清页面缓存，写草稿/改草稿也让
    // 全站冷启动。改挂 transition_post_status($new_status, $old_status,
    // $post)，只在“公开状态变化”时全清：
    //  - 新/旧任一为 publish：发布、已发布文章更新、下架/移入回收站/
    //    设为私密/定时发布，公开页面确实变了；
    //  - 其余转换（草稿→草稿、草稿→待审核、待审核→草稿…）只清首页+
    //    本篇（下面的退化路径），未公开内容不影响任何公开页面。
    // 跳过逻辑按 transition_post_status 语义适配：修订版（transition
    // 对修订版也会触发，用 $post->ID 查）、自动保存、附件、auto-draft
    // （新建文章页创建草稿：auto-draft→draft，还没有任何公开内容）。
    // 注意：$post->post_status 即新状态，auto-draft 须同时查新/旧两个
    // 状态——auto-draft→draft 时 $post->post_status 已是 draft。
    public function smart_flush($new_status, $old_status, $post) {
        if (!is_object($post) || !isset($post->ID)) return;
        if (wp_is_post_revision($post->ID)) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (isset($post->post_type) && $post->post_type === 'attachment') return;
        if ($new_status === 'auto-draft' || $old_status === 'auto-draft') return;
        $core = Skyline_Core::instance();
        $infra = Skyline_Infra::instance();
        // redis_smart_purge 开启（默认 true）：公开状态变化时按前缀批量
        // 清全部页面缓存，分类/标签/Feed/归档一起刷新；已下架文章的缓存
        // 页不再存活到 TTL 结束。
        // 关闭时退回旧行为：只清首页和本篇。草稿→草稿等非公开转换无论
        // 开关如何，都只清首页+本篇。
        if (($new_status === 'publish' || $old_status === 'publish')
            && $core->get_opt('redis_smart_purge', true)) {
            $infra->cache_del_prefix('page_');
            return;
        }
        $infra->cache_del('page_' . md5(home_url('/')));
        $infra->cache_del('page_' . md5(get_permalink($post->ID)));
    }

    // M3：删修订版时不全清。注意 deleted_post 触发时行已删，wp_is_post_revision()
    // 查不到类型，故用钩子传进来的 $post 对象判 post_type（WP 在删除前
    // 抓到的对象会原样传给 deleted_post）。
    public function flush_on_delete($pid, $post = null) {
        if (is_object($post) && isset($post->post_type) && $post->post_type === 'revision') return;
        if ($post === null && wp_is_post_revision($pid)) return;
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
        // M1：四个 Turbo 摆设开关逐个实现（之前只在配置表里、代码里没人读）。
        // 与上面三个死选项同一规矩：这里只注册钩子，开关在回调里再读
        // get_opt——构造阶段读 get_opt 会触发 Skyline_Infra::instance()
        // 重入（实例尚未赋值完成），造成无限递归；回调执行时实例早已就绪。
        // emoji/embeds/xmlrpc 的移除必须在 init 时做（WP 核心在 default-filters
        // 里先加好这些钩子，init 时移除才赶得上 wp_head/rest_api_init）。
        add_action('init', [$this, 'apply_turbo_switches']);
        add_filter('sanitize_file_name', [$this, 'hash_sanitize_filename'], 10, 2);
        // 批量转 WebP 工具（后台性能体检页）：扫描 + 分批转换。
        add_action('wp_ajax_sky_webp_scan', [$this, 'ajax_webp_scan']);
        add_action('wp_ajax_sky_webp_batch', [$this, 'ajax_webp_batch']);
        // v2.2.3（P-6）：手动清缓存入口（后台性能体检页“缓存清理”卡片）。
        add_action('wp_ajax_sky_clear_cache', [$this, 'ajax_clear_cache']);
    }

    public function force_lazy_load($default, $tag_name, $context) {
        if (!Skyline_Core::instance()->get_opt('turbo_lazy_load')) return $default;
        return true;
    }

    // M1：四个 Turbo 开关的实际生效点（init 时统一应用）。
    public function apply_turbo_switches() {
        $core = Skyline_Core::instance();
        // turbo_disable_emoji：去掉前后台 emoji 检测脚本/样式与 feed/邮件
        // 里的 emoji 静态化（WP 核心标准做法）。
        if ($core->get_opt('turbo_disable_emoji')) {
            remove_action('wp_head', 'print_emoji_detection_script', 7);
            remove_action('admin_print_scripts', 'print_emoji_detection_script');
            remove_action('wp_print_styles', 'print_emoji_styles');
            remove_action('admin_print_styles', 'print_emoji_styles');
            remove_filter('the_content_feed', 'wp_staticize_emoji');
            remove_filter('comment_text_rss', 'wp_staticize_emoji');
            remove_filter('wp_mail', 'wp_staticize_emoji_for_email');
            add_filter('tiny_mce_plugins', [$this, 'disable_emoji_tinymce_plugin']);
        }
        // turbo_disable_embeds：去掉 oEmbed 发现链接/宿主 JS、不再自动发现
        // oEmbed、不注册 oEmbed 的 REST 路由。
        if ($core->get_opt('turbo_disable_embeds')) {
            remove_action('wp_head', 'wp_oembed_add_discovery_links');
            remove_action('wp_head', 'wp_oembed_add_host_js');
            remove_action('rest_api_init', 'wp_oembed_register_route');
            add_filter('embed_oembed_discover', '__return_false');
            remove_filter('pre_oembed_result', 'wp_filter_pre_oembed_result', 10);
        }
        // turbo_disable_xmlrpc：禁 XML-RPC（安全类开关）+ 去掉 RSD 发现链接。
        if ($core->get_opt('turbo_disable_xmlrpc')) {
            add_filter('xmlrpc_enabled', '__return_false');
            add_filter('xmlrpc_methods', '__return_empty_array');
            remove_action('wp_head', 'rsd_link');
        }
    }

    // tiny_mce_plugins 回调：把 wpemoji 从编辑器插件里摘掉（disable_emoji
    // 的一部分，单独成方法以便 add_filter 挂上去）。
    public function disable_emoji_tinymce_plugin($plugins) {
        if (!is_array($plugins)) return $plugins;
        return array_values(array_diff($plugins, ['wpemoji']));
    }

    // M1：turbo_sanitize_names——文件名哈希化（保留扩展名）。用原文件名
    // 的 md5 前 16 位作新名，同一原名总得到同一哈希；重名时 WP 的
    // wp_unique_filename 会再加 -1/-2 后缀，不会互相覆盖。
    public function hash_sanitize_filename($filename, $filename_raw = '') {
        if (!Skyline_Core::instance()->get_opt('turbo_sanitize_names')) return $filename;
        $ext = pathinfo($filename, PATHINFO_EXTENSION);
        $hash = substr(md5($filename_raw !== '' ? $filename_raw : $filename), 0, 16);
        return $ext !== '' ? $hash . '.' . $ext : $hash;
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
        // R26：先做实体解码再匹配——否则 &#106;avascript:（实体编码的 j）
        // 会绕过正则，浏览器解析后仍还原为 javascript:。循环解码最多 3 遍，
        // 防双重编码；原始内容与解码后内容都要过一遍正则。
        $decoded = $content;
        for ($i = 0; $i < 3; $i++) {
            $next = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($next === $decoded) break;
            $decoded = $next;
        }
        // L31：再去掉 Tab/换行/回车做一份“去空白版”一起校验——浏览器按
        // WHATWG URL 规范会去掉 URL 中的 Tab/换行后还原，java<TAB>script:
        // 这类写法否则会绕过下面的 javascript: 正则。
        $scrubbed = str_replace(["\t", "\n", "\r"], '', $content);
        $scrubbed_decoded = str_replace(["\t", "\n", "\r"], '', $decoded);
        $dangerous = [
            '/<\s*script/i',                                              // 内嵌脚本
            '/<\s*(iframe|object|embed|form|foreignObject|animate|set|handler)/i',
            '/\son\w+\s*=/i',                                             // 事件处理器
            '/javascript\s*:/i',                                          // JS 伪协议
            '/<\s*!\s*ENTITY/i',                                          // XXE 实体声明
            '/href\s*=\s*["\']\s*(javascript|data\s*:)/i',                // 危险 href
            '/<\s*style/i',                                               // <style> 可 @import 外部样式表
            '/@import/i',                                                 // CSS @import 外部资源
            // L31：<use>/<image> 的外部 href（http(s) 与协议相对 URL）会加载
            // 外链资源/被追踪；内部片段引用（#id）与 xlink:href 同理检查。
            // 只禁外部地址，不禁 # 开头的内部引用。
            '/<\s*(use|image)\b[^>]*?(?:xlink:)?href\s*=\s*["\']\s*(?:https?:)?\/\//i',
            // L31：style 属性里的 url(...) 同样可拉外部资源。
            '/\bstyle\s*=\s*["\'][^"\']*url\s*\(/i',
        ];
        foreach ($dangerous as $pattern) {
            if (preg_match($pattern, $content) || preg_match($pattern, $decoded)
                || preg_match($pattern, $scrubbed) || preg_match($pattern, $scrubbed_decoded)) {
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

    // ================= WebP 转换 =================
    const WEBP_MAX_FILESIZE = 10 * 1024 * 1024; // 与 compress() 同一上限：超大图不转
    const WEBP_BATCH_SIZE = 20;                 // 批量工具每批张数（防超时）
    const WEBP_BATCH_TIME_BUDGET = 25;          // 单次 AJAX 请求的墙钟预算（秒）
    // v2.2.3（P0）：单次扫描请求的墙钟预算（秒）。旧扫描单请求遍历全部
    // 约 53,000 个附件、逐个走 COS FUSE 挂载做 file_exists/filesize，
    // 超 EdgeOne 60 秒回源超时报 524；20 秒远低于该超时，页内超时即停、
    // 客户端按 offset 续扫。
    const WEBP_SCAN_TIME_BUDGET = 20;

    // 墙钟预算取值点（protected，便于测试用子类覆盖为极小值验证“超时
    // 提前返回”；生产值就是 WEBP_SCAN_TIME_BUDGET）。与 load_image /
    // encode_webp 的“可被测试替身覆盖”同款模式。
    protected static function webp_scan_time_budget() { return self::WEBP_SCAN_TIME_BUDGET; }

    // 能力检测结果缓存（类属性而非函数内 static，便于测试用反射重置 /
    // 模拟 GD 支持 WebP 的服务器环境；生产行为不变）。
    private static $webp_supported_cache = null;

    // 能力检测（请求内缓存）：GD 扩展 + imagewebp + 读图函数 +
    // gd_info()['WebP Support'] 四项缺一不可。服务器没装 imagick 不影响
    // ——转 WebP 只用 GD。GD 不支持时整个功能静默跳过，上传流程不受影响。
    public static function webp_supported() {
        if (self::$webp_supported_cache !== null) return self::$webp_supported_cache;
        $supported = false;
        if (extension_loaded('gd') && function_exists('imagewebp')
            && function_exists('imagecreatefromjpeg') && function_exists('imagecreatefrompng')) {
            $info = function_exists('gd_info') ? @gd_info() : [];
            $supported = !empty($info['WebP Support']);
        }
        self::$webp_supported_cache = $supported;
        return $supported;
    }

    // WebP 文件魔数校验：RIFF....WEBP。不依赖 GD 的 WebP 解码能力，
    // 任何 PHP 版本都能验。
    public static function is_webp_file($file) {
        $head = @file_get_contents($file, false, null, 0, 12);
        return $head !== false && strlen($head) === 12
            && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP';
    }

    // 读图（可被测试替身覆盖）：JPG/PNG 读失败返回 false，不抛错。
    protected function load_image($file, $mime) {
        try {
            if ($mime === 'image/jpeg' && function_exists('imagecreatefromjpeg')) {
                $img = @imagecreatefromjpeg($file);
            } elseif ($mime === 'image/png' && function_exists('imagecreatefrompng')) {
                $img = @imagecreatefrompng($file);
            } else {
                return false;
            }
            return $img === false ? false : $img;
        } catch (Throwable $e) {
            return false;
        }
    }

    // 编码（可被测试替身覆盖）：生产路径就是一行 imagewebp，与 WP 核心
    // WP_Image_Editor_GD 存 WebP 的写法一致。
    protected function encode_webp($img, $tmp, $quality) {
        try {
            return function_exists('imagewebp') ? (bool)@imagewebp($img, $tmp, (int)$quality) : false;
        } catch (Throwable $e) {
            return false;
        }
    }

    // 单文件转 WebP。成功返回新文件绝对路径，失败返回 false（调用方保留原图）。
    // 先写临时文件、校验通过后再 rename，中途失败不留半成品、不动原文件。
    // $max_width > 0 且原图更宽时先缩到该宽度再编码（一次有损编码完成
    // 改尺寸 + 转格式，不单独再编一次）。
    public function convert_file_to_webp($file, $quality = 80, $max_width = 0) {
        if (!is_string($file) || $file === '' || !file_exists($file)) return false;
        if (filesize($file) > self::WEBP_MAX_FILESIZE) return false;
        if (!self::webp_supported()) return false;
        $info = @getimagesize($file);
        if ($info === false || !in_array($info['mime'], ['image/jpeg', 'image/png'], true)) return false;

        $new = preg_replace('/\.(jpe?g|png)$/i', '.webp', $file);
        if ($new === $file) return false; // 扩展名不在预期内
        // 目标已存在时换唯一名，绝不覆盖已有文件。
        if (file_exists($new)) {
            $unique = function_exists('wp_unique_filename')
                ? wp_unique_filename(dirname($file), basename($new))
                : basename($new, '.webp') . '-1.webp';
            $new = dirname($file) . '/' . $unique;
        }
        $quality = min(100, max(0, (int)$quality));

        $img = $this->load_image($file, $info['mime']);
        if ($img === false) return false;
        $tmp = false;
        try {
            // PNG 透明通道（R15 的教训）：调色板图先转真彩色，再保留 alpha，
            // 否则透明 PNG 转完会变黑底。
            if ($info['mime'] === 'image/png') {
                if (function_exists('imageistruecolor') && !imageistruecolor($img) && function_exists('imagepalettetotruecolor')) {
                    imagepalettetotruecolor($img);
                }
                if (function_exists('imagesavealpha')) imagesavealpha($img, true);
                if (function_exists('imagealphablending')) imagealphablending($img, false);
            }
            // 改尺寸（只缩不放）：在编码前一次完成。
            if ($max_width > 0 && function_exists('imagescale')) {
                $w = imagesx($img);
                if ($w && $w > $max_width) {
                    $scaled = @imagescale($img, $max_width);
                    if ($scaled !== false) { imagedestroy($img); $img = $scaled; }
                }
            }
            $tmp = tempnam(dirname($new), 'skywebp');
            if ($tmp === false) { imagedestroy($img); return false; }
            $ok = $this->encode_webp($img, $tmp, $quality);
            imagedestroy($img);
            $img = null;
            if (!$ok) { @unlink($tmp); return false; }
            // 二次校验：必须是真实有效的 WebP（魔数 + 非空），否则不认。
            if (!file_exists($tmp) || filesize($tmp) <= 0 || !self::is_webp_file($tmp)) {
                @unlink($tmp);
                return false;
            }
            if (!@rename($tmp, $new)) { @unlink($tmp); return false; }
            // 临时文件默认 0600，改回与原文件一致的权限，避免 Web 服务器读不到。
            @chmod($new, fileperms($file) & 0777);
            return $new;
        } catch (Throwable $e) {
            if ($img !== null) { try { imagedestroy($img); } catch (Throwable $ignored) {} }
            if ($tmp !== false) @unlink($tmp);
            return false;
        }
    }

    // 该附件是否还有可转的文件（批量工具扫描用）。返回
    // ['eligible'=>bool, 'code'=>原因码, 'reason'=>中文原因]。
    // 排除规则：本地文件缺失（已被 Zero-Disk 删掉）、>10MB、已有 Zero-Disk
    // 待删登记（正等着删的文件不值得转，转完还会干扰二次校验）、已转过。
    public static function webp_eligibility($attachment_id) {
        $attachment_id = (int)$attachment_id;
        if ($attachment_id <= 0) return ['eligible' => false, 'code' => 'invalid', 'reason' => '无效附件'];
        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return ['eligible' => false, 'code' => 'missing', 'reason' => '本地文件不存在'];
        if (filesize($file) > self::WEBP_MAX_FILESIZE) return ['eligible' => false, 'code' => 'toolarge', 'reason' => '文件超过 10MB'];
        // Zero-Disk 待删登记中的附件不转（见上）。
        if (get_post_meta($attachment_id, '_sky_oss_pending_delete', true)) {
            return ['eligible' => false, 'code' => 'pending_delete', 'reason' => 'Zero-Disk 待删登记中'];
        }
        $type = get_post_mime_type($attachment_id);
        if (in_array($type, ['image/jpeg', 'image/png'], true)) return ['eligible' => true, 'code' => '', 'reason' => ''];
        // 原图已是 WebP：检查是否还有 JPG/PNG 缩略图没转（新增尺寸的场景）。
        $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
        if (is_array($metadata) && !empty($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_info) {
                if (empty($size_info['file'])) continue;
                // WebP：拼路径前过 basename()，防元数据里的 ../ 穿越（与 L30 同款）。
                $size_file = dirname($file) . '/' . basename($size_info['file']);
                if (!file_exists($size_file)) continue;
                $ftype = wp_check_filetype($size_file);
                if (in_array($ftype['type'], ['image/jpeg', 'image/png'], true)) {
                    return ['eligible' => true, 'code' => '', 'reason' => ''];
                }
            }
        }
        return ['eligible' => false, 'code' => 'converted', 'reason' => '已转过'];
    }

    // 附件级转换：原图 + 全部尺寸缩略图。至少转成一个文件时返回更新后的
    // $metadata；原图转换失败时返回 false，调用方回退旧压缩流程（静默
    // 回退，上传不受影响）；无文件可转时也返回 false（调用方走原有流程）。
    // 单张缩略图失败只跳过该张、记日志，不影响其他（R28 的教训）。
    public function convert_attachment_to_webp($attachment_id, $metadata) {
        $core = Skyline_Core::instance();
        if (!self::webp_supported()) return false;
        if (!is_array($metadata)) return false;
        $attachment_id = (int)$attachment_id;
        if ($attachment_id <= 0) return false;

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file) || filesize($file) > self::WEBP_MAX_FILESIZE) return false;

        $quality = min(100, max(0, (int)$core->get_opt('turbo_webp_quality', 80)));
        $keep_original = (bool)$core->get_opt('turbo_webp_keep_original', true);
        $max_width = (int)$core->get_opt('turbo_max_width', 0);
        $changed = false;

        // 已转记录：尺寸名 => 转完的 WebP 文件 md5。重新生成缩略图时只有
        // 内容变了才重转，防止反复有损编码（与 _sky_compressed 同一规矩）。
        $done = get_post_meta($attachment_id, '_sky_webp_done', true);
        if (!is_array($done)) $done = [];
        $orig_to_delete = [];

        // 1. 原图（还是 JPG/PNG 时才转；已是 WebP 则只处理缩略图）。
        $type = get_post_mime_type($attachment_id);
        if (in_array($type, ['image/jpeg', 'image/png'], true)) {
            if (!isset($done['__original__']) || $done['__original__'] !== md5_file($file)) {
                $new = $this->convert_file_to_webp($file, $quality, $max_width);
                if ($new === false) return false; // 原图失败：整体回退旧流程
                // 回填元数据与附件引用（不回填的话前台 srcset 与 OSS 上传
                // 拿到的还是旧 JPG）。以磁盘实际文件名为准（重名时已换唯一名）。
                $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : ['basedir' => dirname($file)];
                $base_dir = rtrim($upload_dir['basedir'], '/');
                $rel_new = (strpos($new, $base_dir . '/') === 0)
                    ? substr($new, strlen($base_dir) + 1)
                    : basename($new);
                $metadata['file'] = $rel_new;
                $dim = @getimagesize($new);
                if ($dim !== false) {
                    $metadata['width'] = (int)$dim[0];
                    $metadata['height'] = (int)$dim[1];
                }
                $metadata['filesize'] = filesize($new);
                update_post_meta($attachment_id, '_wp_attached_file', $rel_new);
                // 直接写库改 mime，不走 wp_update_post：后者会触发 save_post，
                // 而 save_post 会整库清页面缓存（P5），一张图触发一次全清太贵；
                // 附件不参与修订版，写库后清缓存即可。
                global $wpdb;
                if (isset($wpdb) && method_exists($wpdb, 'update')) {
                    $wpdb->update($wpdb->posts, ['post_mime_type' => 'image/webp'], ['ID' => $attachment_id]);
                    if (function_exists('clean_post_cache')) clean_post_cache($attachment_id);
                }
                $done['__original__'] = md5_file($new);
                $changed = true;
                if (!$keep_original) $orig_to_delete[] = $file;
            }
        }

        // 2. 缩略图：WP 是先生成完所有缩略图、再跑元数据钩子，所以这里
        // 能拿到全部尺寸。注意顺序：本钩子优先级 5，OSS 上传钩子优先级 99，
        // 压完再上传，COS 上拿到的就是转完的 WebP。
        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            $base_dir = dirname($file);
            foreach ($metadata['sizes'] as $size_name => $size_info) {
                if (empty($size_info['file'])) continue;
                // WebP：拼路径前过 basename()，防元数据里的 ../ 穿越（与 L30 同款）。
                $size_file = $base_dir . '/' . basename($size_info['file']);
                if (!file_exists($size_file)) continue;
                if (isset($done[$size_name]) && $done[$size_name] === md5_file($size_file)) continue;
                $ftype = wp_check_filetype($size_file);
                if (!in_array($ftype['type'], ['image/jpeg', 'image/png'], true)) continue;
                $new_size = $this->convert_file_to_webp($size_file, $quality, 0);
                if ($new_size === false) {
                    $core->log("⚠️ [Turbo] 缩略图转 WebP 失败，已保留原格式：{$size_file}", 'warn', 'Turbo');
                    continue;
                }
                $metadata['sizes'][$size_name]['file'] = basename($new_size);
                $dim = @getimagesize($new_size);
                if ($dim !== false) {
                    $metadata['sizes'][$size_name]['width'] = (int)$dim[0];
                    $metadata['sizes'][$size_name]['height'] = (int)$dim[1];
                }
                $metadata['sizes'][$size_name]['mime-type'] = 'image/webp';
                $metadata['sizes'][$size_name]['filesize'] = filesize($new_size);
                $done[$size_name] = md5_file($new_size);
                $changed = true;
                if (!$keep_original) $orig_to_delete[] = $size_file;
            }
        }

        if (!$changed) return false;
        update_post_meta($attachment_id, '_sky_webp_done', $done);
        if (!empty($orig_to_delete)) {
            // 原图删除走两阶段：OSS 开着时先登记，等 OSS 上传成功后再删
            // （upload_all_sizes 成功分支里删）；OSS 没开时 WebP 已校验有效，
            // 直接删（本地唯一副本就是刚校验过的 WebP）。
            if ($core->get_opt('oss_enable')) {
                update_post_meta($attachment_id, '_sky_webp_orig_pending', array_values(array_unique($orig_to_delete)));
            } else {
                foreach ($orig_to_delete as $old) {
                    if (file_exists($old)) @unlink($old);
                }
            }
        }
        return $metadata;
    }

    // ---------- 批量转 WebP（后台 AJAX）----------
    // 两个端点都只给管理员：nonce + manage_options（N5/R18 的教训：
    // 只有 nonce 不够，必须验权限）。
    // v2.2.3（P-6）：手动清缓存入口（后台性能体检页“缓存清理”卡片调）。
    // 只清 Skyline 自己的 sky: 键：scope=page 清 page_ 前缀，scope=all
    // 清全部 sky: 键（cache_del_prefix('') 对应模式 'sky:*'，见该方法）。
    // 只清 Redis 层，不碰 EdgeOne 缓存（2026-11-09 三层同清拖垮 FPM 的
    // 教训，message 里写明）；Redis 不可用时明确报错，不静默成功。
    public function ajax_clear_cache() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        $scope = isset($_POST['scope']) ? (string)$_POST['scope'] : 'page';
        if ($scope !== 'page' && $scope !== 'all') wp_send_json_error('scope 参数无效');
        $cleared = Skyline_Infra::instance()->cache_del_prefix($scope === 'all' ? '' : 'page_');
        if ($cleared === null) wp_send_json_error('Redis 不可用，缓存未清理');
        $message = $scope === 'all'
            ? '已清空全部 Skyline 缓存（仅 Redis 层，未动 EdgeOne 缓存）'
            : '已清空页面缓存（仅 Redis 层，未动 EdgeOne 缓存）';
        wp_send_json_success(['scope' => $scope, 'message' => $message]);
    }

    // v2.2.3（P0）：分页扫描。旧实现单请求 get_posts(posts_per_page=-1)
    // 取全部约 53,000 个附件 ID 再逐个 webp_eligibility()（每附件多次
    // file_exists/filesize，走 COS FUSE 挂载 /lhcos-data）+ 读库，无分
    // 页、无墙钟预算，超 EdgeOne 60 秒回源超时 → HTTP 524。
    // 新实现：每次只取一页（offset/page_size，服务端钳制 100–5000，
    // 默认 2000），页内仍先 update_postmeta_cache($ids) 再逐个分类；
    // 页内超 WEBP_SCAN_TIME_BUDGET（20 秒）即停，返回 done=false、
    // offset=下一未处理位置，客户端按 offset 续扫。
    // total 是“符合查询条件的附件总数”（$wpdb 一次廉价 COUNT，不拉全
    // 表），供客户端显示“已扫 X/Y 张”与判断 done；最终交给 parseScan
    // 的合并结果里 total 改为累积可转 ID 数（与旧单次扫描语义相同，
    // 见 admin.js 的 scanPaged 合并逻辑）。
    // 注意：offset 分页在扫描中途有附件增删时会漂移（新增的附件可能被
    // 跳过或重复扫到）——可接受，扫描是管理操作，重扫一次即纠正。
    public function ajax_webp_scan() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!self::webp_supported()) {
            wp_send_json_success(['supported' => false, 'total' => 0, 'ids' => [], 'skipped' => [], 'done' => true, 'offset' => 0, 'scanned' => 0]);
        }
        $offset = isset($_POST['offset']) ? max(0, (int)$_POST['offset']) : 0;
        $page_size = isset($_POST['page_size']) ? (int)$_POST['page_size'] : 2000;
        $page_size = min(5000, max(100, $page_size));
        // total：一次廉价 COUNT，不拉全表。$wpdb 在 WP 内恒存在；取不到
        // 时退化为“本页即最后一页”（见下面的 done 推导），不静默多算。
        global $wpdb;
        $total_known = isset($wpdb) && method_exists($wpdb, 'get_var');
        $total = 0;
        if ($total_known) {
            $total = (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ('image/jpeg', 'image/png', 'image/webp') AND post_status = 'inherit'");
        }
        $ids = get_posts([
            'post_type' => 'attachment',
            'post_mime_type' => ['image/jpeg', 'image/png', 'image/webp'],
            'post_status' => 'inherit',
            'posts_per_page' => $page_size,
            'offset' => $offset,
            'fields' => 'ids',
            'orderby' => 'ID',
            'order' => 'ASC',
        ]);
        if (!is_array($ids)) $ids = [];
        if (function_exists('update_postmeta_cache')) update_postmeta_cache($ids);
        $start = microtime(true);
        $eligible = [];
        $skipped = ['converted' => 0, 'missing' => 0, 'toolarge' => 0, 'pending_delete' => 0, 'invalid' => 0];
        $scanned = 0;
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下（与 ajax_webp_batch 同款），剩下的
            // 附件客户端按 offset 续扫，不丢。
            if (microtime(true) - $start > static::webp_scan_time_budget()) break;
            $scanned++;
            $e = self::webp_eligibility((int)$id);
            if ($e['eligible']) { $eligible[] = (int)$id; continue; }
            if (isset($skipped[$e['code']])) $skipped[$e['code']]++;
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
        wp_send_json_success(['supported' => true, 'total' => $total, 'ids' => $eligible, 'skipped' => $skipped, 'done' => $done, 'offset' => $next_offset, 'scanned' => $scanned]);
    }

    public function ajax_webp_batch() {
        check_ajax_referer('skyline_ajax_nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
        if (!self::webp_supported()) wp_send_json_error('服务器 GD 不支持 WebP');
        $ids = isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('absint', $_POST['ids']) : [];
        // 每批上限 20 张由服务端定死，不信任客户端传的数量。
        $ids = array_slice(array_values(array_unique(array_filter($ids))), 0, self::WEBP_BATCH_SIZE);
        if (empty($ids)) wp_send_json_success(['results' => [], 'stopped_early' => false]);

        $core = Skyline_Core::instance();
        $oss = ($core->get_opt('oss_enable') && class_exists('Skyline_OSS_Mod')) ? Skyline_OSS_Mod::instance() : null;
        $start = microtime(true);
        $results = [];
        $stopped_early = false;
        foreach ($ids as $id) {
            // 墙钟预算：超时前停下，剩下的 ID 客户端下一批重发。
            if (microtime(true) - $start > self::WEBP_BATCH_TIME_BUDGET) { $stopped_early = true; break; }
            // 客户端只发扫描出的可转 ID；这里再核一次，防过期 ID 被当失败
            // （例如两次扫描之间文件已被删或已转过）。
            $elig = self::webp_eligibility($id);
            if (!$elig['eligible']) {
                $results[] = ['id' => $id, 'status' => 'skipped', 'reason' => $elig['reason']];
                continue;
            }
            $metadata = get_post_meta($id, '_wp_attachment_metadata', true);
            if (!is_array($metadata) || empty($metadata)) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => '无附件元数据（可能上传未完成）'];
                continue;
            }
            $new_meta = $this->convert_attachment_to_webp($id, $metadata);
            if ($new_meta === false) {
                $results[] = ['id' => $id, 'status' => 'failed', 'reason' => '转换失败（原图已保留）'];
                continue;
            }
            update_post_meta($id, '_wp_attachment_metadata', $new_meta);
            $cos = 'off';
            if ($oss) {
                // 转完立即重新同步到 COS，否则前台 URL（已重写为 COS 域名）
                // 会指向不存在的 .webp 对象。上传失败时原图按 keep 设置
                // 保留（见 delete_webp_originals：只在成功分支删）。
                $oss->upload_all_sizes($new_meta, $id);
                $cos = get_post_meta($id, '_sky_oss_synced', true) ? 'ok' : 'failed';
            }
            $results[] = ['id' => $id, 'status' => 'converted', 'cos' => $cos];
        }
        wp_send_json_success(['results' => $results, 'stopped_early' => $stopped_early]);
    }

    public function compress($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('turbo_enable_image_opt')) return $metadata;

        $file = get_attached_file($attachment_id);
        if (!$file || !file_exists($file)) return $metadata;
        // L11：>10MB 只跳过原图压缩，不跳过缩略图——修前整个函数直接返回，
        // 超大原图的缩略图也不压。
        $oversize = filesize($file) > 10 * 1024 * 1024;

        $type = get_post_mime_type($attachment_id);
        // L11：GIF 加入压缩类型白名单（修前只认 jpeg/png/webp，GIF 缩略图不压缩）。
        if (!in_array($type, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) return $metadata;

        // WebP 转换（在旧压缩之前）：JPG/PNG 先转 WebP，一次有损编码同时
        // 完成质量压缩与格式转换；转完直接返回，不再走下面的 JPEG 重编码，
        // 避免同张图被两次有损压缩。转失败返回 false 时静默回退旧流程
        // （上传不受影响）。
        if ($core->get_opt('turbo_webp_convert') && in_array($type, ['image/jpeg', 'image/png'], true)) {
            $converted = $this->convert_attachment_to_webp($attachment_id, $metadata);
            if (is_array($converted)) return $converted;
        }

        $quality = (int)$core->get_opt('turbo_quality', 85);

        // 已压记录：尺寸名 => 压缩后的文件 md5。重新生成缩略图时，只有内容
        // 变了的文件才重新压——既防止反复有损压缩，又不漏掉新生成的尺寸。
        // （只记文件名不够：缩略图被重新生成后文件名不变、内容变了。）
        $done = get_post_meta($attachment_id, '_sky_compressed', true);
        if (!is_array($done)) $done = [];

        // 1. 原图：save() 返回值必须检查（PR #3 已加），保存失败直接返回，
        // 不上传、不删；成功后回填元数据（resize 后旧的 width/height/filesize
        // 否则会让前台 srcset/sizes 和后台显示的尺寸全错）。
        // L11：原图 md5 只算一次并复用（$orig_md5 既作“已压过？”的比对；
        // 保存成功后文件内容变了，记录时需重算一次新文件的 md5——那是
        // 不同内容，不是一次重复计算）。>10MB 时不算（原图本就不压）。
        if ($oversize) {
            $core->log("原图超过 10MB，跳过原图压缩，仍压缩缩略图：{$file}", 'info', 'Turbo');
        }
        $orig_md5 = $oversize ? null : md5_file($file);
        if (!$oversize && (!isset($done['__original__']) || $done['__original__'] !== $orig_md5)) {
            $editor = wp_get_image_editor($file);
            if (!is_wp_error($editor)) {
                $editor->set_quality($quality);
                $size = $editor->get_size();
                // R14：改尺寸是独立行为，默认不做——turbo_max_width 为 0 时只压
                // 质量、不改尺寸；设为正数时才把宽于该值的原图缩到该宽度，缩
                // 之前记日志。之前无条件缩到 2560px 是静默不可逆行为，与
                // Zero-Disk 叠加会永久丢失高清原图。
                $max_width = (int)$core->get_opt('turbo_max_width', 0);
                if ($max_width > 0 && $size && $size['width'] > $max_width) {
                    $core->log("原图宽 {$size['width']}px 超过上限 {$max_width}px，将缩到 {$max_width}px：{$file}", 'info', 'Turbo');
                    $editor->resize($max_width, null, false);
                }

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
                // L30：拼路径前过 basename()，防元数据里的 ../ 穿越。
                $size_file = $base_dir . '/' . basename($size_info['file']);
                if (!file_exists($size_file)) continue;
                // L11：同一文件只算一次 md5 并复用（比对用；保存成功后内容
                // 变了，记录时重算一次新文件的 md5，见原图处注释）。
                $size_md5 = md5_file($size_file);
                if (isset($done[$size_name]) && $done[$size_name] === $size_md5) continue;
                $ftype = wp_check_filetype($size_file);
                if (!in_array($ftype['type'], ['image/jpeg', 'image/png', 'image/webp', 'image/gif'])) continue;

                $editor = wp_get_image_editor($size_file);
                if (is_wp_error($editor)) continue;
                $editor->set_quality($quality);
                $saved = $editor->save($size_file);
                unset($editor);
                // L9：单张缩略图 save() 失败记 warn 日志（带尺寸名和错误信息），
                // 再 continue——与原图失败记日志保持一致，不再静默跳过。
                if (is_wp_error($saved)) {
                    $core->log("⚠️ [Turbo] 缩略图压缩保存失败，已跳过：{$size_name} | {$size_file} | " . $saved->get_error_message(), 'warn', 'Turbo');
                    continue;
                }
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
        // L28（补修）：失败清单重试——WP-Cron 单次事件 skyline_oss_retry_failed。
        // 间隔与上限与 Zero-Disk 的 purge 保持一致（1 小时、上限 3 次）。
        const RETRY_DELAY = 3600;
        const RETRY_MAX = 3;
        const RETRY_ATTEMPTS_META = '_sky_oss_retry_attempts';
        // M22：Zero-Disk 回收站保留期——删前移入 uploads/.trash/ 保留 7 天，
        // 到期由后续 purge 运行时清理、真正删除。
        const TRASH_TTL = 7 * DAY_IN_SECONDS;

        private static $instance = null;

        // 批量转 WebP 工具需要同步调用 upload_all_sizes；经此取单例，
        // 不直接 new（构造器会重复注册钩子）。
        public static function instance() {
            if (self::$instance === null) self::$instance = new self();
            return self::$instance;
        }

        public function __construct() {
            self::$instance = $this;
            add_filter('wp_generate_attachment_metadata', [$this, 'upload_all_sizes'], 99, 2);
            add_filter('wp_get_attachment_url', [$this, 'replace_url'], 99, 2);
            add_filter('wp_get_attachment_image_src', [$this, 'replace_image_src'], 99, 4);
            // v2.2.3（X-3）：skyline_oss_purge_pending / skyline_oss_retry_failed
            // 的 cron 回调改到插件主文件（skyline-ai-pro.php）加载期直接注
            // 册，不再依赖本构造器执行（Infra 初始化若抛错被 try/catch 吞
            // 掉，回调会全程不注册、已排期事件被 WP 静默消费）。
            // v2.2.3（O-7/O-8）：OSS 手动操作 AJAX 入口（后台 OSS 页按钮调，
            // 全部 nonce + manage_options，与 sky_webp_scan/batch 同款）。
            add_action('wp_ajax_sky_oss_retry_failed', [$this, 'ajax_oss_retry_failed']);
            add_action('wp_ajax_sky_oss_purge_now', [$this, 'ajax_oss_purge_now']);
            add_action('wp_ajax_sky_oss_purge_retry', [$this, 'ajax_oss_purge_retry']);
            add_action('wp_ajax_sky_oss_purge_cancel', [$this, 'ajax_oss_purge_cancel']);
        }

        // D3：Skyline 定时任务健康统计（静态方法，供数据看板调用）。
        // 覆盖四类 hook：自动 SEO（skyline_async_seo）、图片同步
        // （skyline_async_spider）、Zero-Disk 待删清理
        // （skyline_oss_purge_pending）、失败尺寸重试
        // （skyline_oss_retry_failed）。
        // 注意不用 wp_next_scheduled($hook) 取下次触发：插件的单次事件
        // 都带参数（文章/附件 id），不带参数调它匹配不到任何事件、恒返
        // 回 false；直接读 _get_cron_array()（WP 自己的 cron 表），一次
        // 扫描同时拿到下次触发时间、待处理数与逾期数。
        // 逾期 = 计划时间已过当前时间——wp-cron 饿死时事件会一直逾期，
        // S4 的自动 SEO 静默失败就是这么来的，看板标红就是要让它当天可见。
        // 返回：['hooks'=>四类 hook, 'pending'=>待处理事件数,
        // 'overdue'=>逾期事件数, 'next_ts'=>最早计划时间戳|null,
        // 'by_hook'=>[hook=>事件数]]。无 _get_cron_array 或无事件时
        // 返回空统计，不让看板 fatal。
        public static function cron_health() {
            $hooks = ['skyline_async_seo', 'skyline_async_spider', 'skyline_oss_purge_pending', 'skyline_oss_retry_failed'];
            $result = ['hooks' => $hooks, 'pending' => 0, 'overdue' => 0, 'next_ts' => null, 'by_hook' => array_fill_keys($hooks, 0)];
            if (!function_exists('_get_cron_array')) return $result;
            $crons = _get_cron_array();
            if (!is_array($crons) || empty($crons)) return $result;
            $now = time();
            foreach ($crons as $ts => $events) {
                if (!is_array($events)) continue;
                foreach ($events as $hook => $instances) {
                    if (!in_array($hook, $hooks, true) || !is_array($instances)) continue;
                    foreach ($instances as $instance) {
                        $result['pending']++;
                        $result['by_hook'][$hook]++;
                        if ($result['next_ts'] === null || (int)$ts < $result['next_ts']) $result['next_ts'] = (int)$ts;
                        if ((int)$ts < $now) $result['overdue']++;
                    }
                }
            }
            return $result;
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
            // 对象 key 提前算出来：文件缺失分支也要用它核查 COS 上是否还有副本。
            $upload_dir = wp_upload_dir();
            $base_url_path = trim(parse_url($upload_dir['baseurl'], PHP_URL_PATH), '/') ?: 'wp-content/uploads';
            $attached_file = get_post_meta($attachment_id, '_wp_attached_file', true) ?: ($file ? basename($file) : '');
            $object_key = $base_url_path . '/' . ltrim($attached_file, '/');
            // L28(a) 补修：文件不存在时不再只清标记等下次——先核查 COS 上
            // 是否还有副本（remoteExists 的既定语义：COS 对象在就说明同步
            // 是完好的，不能清标记，否则前台 URL 会从能打开的 COS 地址翻
            // 回不存在的本地路径）。COS 有副本：标记保留、前台继续走 COS；
            // COS 也没有：按旧行为清标记。两种情况都约 1 小时后的自动复查
            // （skyline_oss_retry_failed），不再被动等下次重新生成/手动重传。
            if (!$file || !file_exists($file)) {
                $client = $this->make_cos_client();
                $cos_has_it = $client ? $client->remoteExists($object_key) : false;
                if ($cos_has_it) {
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 本地文件缺失，但 COS 副本完好，已同步标记保留、前台继续走 COS；已约 1 小时后自动复查", 'warn', 'OSS');
                } else {
                    delete_post_meta($attachment_id, '_sky_oss_synced');
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 本地文件缺失且 COS 上无副本，已清除已同步标记；已约 1 小时后自动复查", 'warn', 'OSS');
                }
                $this->schedule_retry($attachment_id);
                return $metadata;
            }

            $core->log("🚀 [OSS] 开始处理附件 #{$attachment_id}: " . basename($file), 'info', 'OSS');

            $client = $this->make_cos_client();
            if (!$client) return $metadata;

            try {
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
                        // L30：拼路径前过 basename()，防元数据里的 ../ 穿越。
                        $size_file = $base_dir . '/' . basename($size_info['file']);
                        if (file_exists($size_file)) {
                            $size_rel_path = $rel_dir ? $rel_dir . '/' . basename($size_info['file']) : basename($size_info['file']);
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
                    // L28(c)：全成功时清掉旧的失败清单，不留过期重试项。
                    delete_post_meta($attachment_id, '_sky_oss_failed_sizes');
                    $core->log("🎉 [OSS] 附件 #{$attachment_id} 全部上传成功！", 'info', 'OSS');

                    // 转 WebP 后原图的第二阶段删除（keep_original=false 时才有
                    // 登记）：WebP 已校验、COS 已同步，至此原 JPG/PNG 才可以删。
                    // 上传失败时不走到这里，原图与登记都保留（安全方向）。
                    $this->delete_webp_originals($attachment_id);

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
                    // L28(c)：单张图失败时把失败尺寸记入日志与重试清单
                    // _sky_oss_failed_sizes，不要静默全有或全无——已同步标记
                    // 的整体语义不变（仍只有全成功才写 _sky_oss_synced），
                    // 但失败项从此可见、可重试，而不是只留一句“存在失败项”。
                    update_post_meta($attachment_id, '_sky_oss_failed_sizes', $failed_files);
                    // L28（补修）：新一轮失败即新一轮重试周期——清掉旧的重试
                    // 计数（否则上轮用满 3 次后本轮一次也约不上），并约 1 小时
                    // 后的自动重试（skyline_oss_retry_failed）；清单从此有消费
                    // 方，不再只记不重试。
                    delete_post_meta($attachment_id, self::RETRY_ATTEMPTS_META);
                    $this->schedule_retry($attachment_id);
                    $failed_desc = implode(', ', array_map(function ($f) {
                        return ($f['type'] ?? '?') . ':' . ($f['key'] ?? '');
                    }, $failed_files));
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 存在失败项（共 " . count($failed_files) . " 个）：{$failed_desc}，已记入 _sky_oss_failed_sizes 待重试，已约 1 小时后自动重试", 'warn', 'OSS');
                }

            } catch (Throwable $e) {
                $core->log("💥 [OSS] 处理异常: " . $e->getMessage(), 'error', 'OSS');
            }

            return $metadata;
        }

        // L28（补修）：失败清单的重试消费方（WP-Cron 回调，见 schedule_retry）。
        // 选 WP-Cron 而不是后台 AJAX 入口，两个理由：
        //  ① 失败发生在媒体上传流程里，重试必须无人值守自动发生——AJAX 要管理
        //     员打开后台点一下才跑，等于“待重试”仍依赖人工；
        //  ② 插件已有 skyline_oss_purge_pending 的同款模式（单次事件 +
        //     wp_next_scheduled 守卫 + 1 小时重排 + 上限 3 次），复用零新概
        //     念；且 S4 刚修好 wp-cron 饿死，低流量时段 cron 也能触发。
        // 语义：逐项重试清单里的失败尺寸——本地文件还在就重新 putFile
        // （putFile 自带上传后强校验）；本地文件已不在时核查 COS：对象在即
        // 视为已同步、直接从清单移除（自愈），对象也不在则保留清单记日志
        // （缺本地文件无法上传，等重新生成）。清单清空后写 _sky_oss_synced=1
        // （此前成功项早已在 COS 上，清单空即全量已同步）；仍有剩余时更新
        // 清单、1 小时后重排（上限 3 次）。
        public function retry_failed_sizes($attachment_id) {
            $attachment_id = (int)$attachment_id;
            $failed = get_post_meta($attachment_id, '_sky_oss_failed_sizes', true);
            if (!is_array($failed) || empty($failed)) return;

            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable')) {
                $core->log("⚠️ [OSS] OSS 已关闭，附件 #{$attachment_id} 的失败清单暂不重试", 'warn', 'OSS');
                $this->reschedule_retry($attachment_id, 'OSS 已关闭');
                return;
            }

            $client = $this->make_cos_client();
            if (!$client) {
                $this->reschedule_retry($attachment_id, 'COS 客户端创建失败');
                return;
            }

            $remaining = [];
            foreach ($failed as $entry) {
                if (!is_array($entry) || empty($entry['path']) || empty($entry['key'])) {
                    $remaining[] = $entry;
                    continue;
                }
                if (!file_exists($entry['path'])) {
                    // 本地文件缺失：COS 上有对象即视为已同步（自愈）、从清单移除；
                    // COS 上也没有则保留、记日志（无法凭空上传，等重新生成）。
                    if ($client->remoteExists($entry['key'])) {
                        $core->log("✅ [OSS] 附件 #{$attachment_id} 重试：本地文件已缺失但 COS 上已有 {$entry['key']}，视为已同步", 'info', 'OSS');
                        continue;
                    }
                    $core->log("⚠️ [OSS] 附件 #{$attachment_id} 重试：本地文件缺失且 COS 上无对象，无法重试 {$entry['key']}，保留清单", 'warn', 'OSS');
                    $remaining[] = $entry;
                    continue;
                }
                if ($client->putFile($entry['key'], $entry['path'])) {
                    $core->log("✅ [OSS] 附件 #{$attachment_id} 重试成功：{$entry['key']}", 'info', 'OSS');
                    continue;
                }
                $core->log("⚠️ [OSS] 附件 #{$attachment_id} 重试仍失败：{$entry['key']}，保留清单", 'warn', 'OSS');
                $remaining[] = $entry;
            }

            if (empty($remaining)) {
                delete_post_meta($attachment_id, '_sky_oss_failed_sizes');
                delete_post_meta($attachment_id, self::RETRY_ATTEMPTS_META);
                update_post_meta($attachment_id, '_sky_oss_synced', 1);
                $core->log("🎉 [OSS] 附件 #{$attachment_id} 失败清单已全部重试成功，已写已同步标记", 'info', 'OSS');
                return;
            }

            update_post_meta($attachment_id, '_sky_oss_failed_sizes', $remaining);
            $this->reschedule_retry($attachment_id, '仍有 ' . count($remaining) . ' 项未成功');
        }

        // L28（补修）：重试未完成时 1 小时后重排，复用 purge 的上限 3 次规则；
        // 超限记错误日志并保留清单待人工处理，不再重排。
        private function reschedule_retry($attachment_id, $reason = '') {
            $retries = (int)get_post_meta($attachment_id, self::RETRY_ATTEMPTS_META, true);
            if ($retries >= self::RETRY_MAX) {
                $core = Skyline_Core::instance();
                $core->log("❌ [OSS] 附件 #{$attachment_id} 失败清单重试已满 3 次，停止重试，清单已保留待人工处理" . ($reason !== '' ? "（{$reason}）" : ''), 'error', 'OSS');
                return false;
            }
            update_post_meta($attachment_id, self::RETRY_ATTEMPTS_META, $retries + 1);
            $this->schedule_retry($attachment_id);
            return true;
        }

        // L28（补修）：约一次失败清单重试（WP-Cron 单次事件，1 小时后）。
        // 已有排期时不重复约（wp_next_scheduled 守卫，与 purge 的写法一致）。
        private function schedule_retry($attachment_id) {
            if (!wp_next_scheduled('skyline_oss_retry_failed', [$attachment_id])) {
                wp_schedule_single_event(time() + self::RETRY_DELAY, 'skyline_oss_retry_failed', [$attachment_id]);
            }
        }

        // v2.2.3（O-7/O-8）：OSS 手动操作 AJAX 入口（后台 OSS 页按钮调）。
        // 全部 nonce + manage_options；只复用现有引擎方法，不改 cron 语义。
        // sky_oss_retry_failed：手动重试全部失败尺寸（无额外参数）。
        public function ajax_oss_retry_failed() {
            check_ajax_referer('skyline_ajax_nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
            $count = $this->retry_all_failed_sizes();
            if ($count === 0) {
                wp_send_json_success(['count' => 0, 'message' => '没有待重试的失败尺寸']);
            }
            wp_send_json_success(['count' => $count, 'message' => "已重试 {$count} 个附件的失败尺寸"]);
        }

        // sky_oss_purge_now：手动跑一次待删清理（无额外参数）。
        public function ajax_oss_purge_now() {
            check_ajax_referer('skyline_ajax_nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
            $count = $this->purge_all_pending();
            if ($count === 0) {
                wp_send_json_success(['count' => 0, 'message' => '待删队列为空，无需清理']);
            }
            wp_send_json_success(['count' => $count, 'message' => "已对 {$count} 个附件跑了一次待删校验"]);
        }

        // sky_oss_purge_retry：单个附件重跑一次待删校验。purge_pending_deletes()
        // 本身就是单附件入口（cron 与手动共用，行为一字不变），无需另抽方法；
        // 跑完后看登记还在不在，判断本次是否已结案（done）还是仍在队列（pending，
        // 如未满 24 小时或二次校验未通过、已自动重排）。
        public function ajax_oss_purge_retry() {
            check_ajax_referer('skyline_ajax_nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
            $id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            if ($id <= 0) wp_send_json_error('attachment_id 参数无效');
            if (get_post_meta($id, self::PENDING_DELETE_META, true) === '') wp_send_json_error('该附件没有待删登记');
            $this->purge_pending_deletes($id);
            if (get_post_meta($id, self::PENDING_DELETE_META, true) === '') {
                wp_send_json_success(['status' => 'done', 'message' => "附件 #{$id} 的待删登记已清除"]);
            }
            wp_send_json_success(['status' => 'pending', 'message' => "附件 #{$id} 仍在待删队列中（未满 24 小时或二次校验未通过，已自动重排）"]);
        }

        // sky_oss_purge_cancel：删除该附件的待删登记，本地文件保留。
        public function ajax_oss_purge_cancel() {
            check_ajax_referer('skyline_ajax_nonce');
            if (!current_user_can('manage_options')) wp_send_json_error('Unauthorized');
            $id = isset($_POST['attachment_id']) ? absint($_POST['attachment_id']) : 0;
            if ($id <= 0) wp_send_json_error('attachment_id 参数无效');
            if (get_post_meta($id, self::PENDING_DELETE_META, true) === '') wp_send_json_error('该附件没有待删登记');
            delete_post_meta($id, self::PENDING_DELETE_META);
            wp_send_json_success(['message' => "已取消附件 #{$id} 的待删登记，本地文件保留"]);
        }

        // 手动重试全部失败尺寸：逐个附件调现有 retry_failed_sizes()（单附件
        // 签名，cron 回调同款；重试次数存在 _sky_oss_retry_attempts 里，
        // 满 3 次后不再自动重排、清单保留待人工处理）。返回处理附件数。
        // 失败清单本身是小集合，上限 500 足够（超限的极端情况留给下次手动点）。
        public function retry_all_failed_sizes() {
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'meta_key' => '_sky_oss_failed_sizes',
                'posts_per_page' => 500,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            if (!is_array($ids)) return 0;
            $count = 0;
            foreach ($ids as $id) {
                $this->retry_failed_sizes((int)$id);
                $count++;
            }
            return $count;
        }

        // 手动跑一次待删清理：逐个附件调现有 purge_pending_deletes()（单附件
        // 签名，cron 回调同款；24 小时未满、OSS 关闭等分支行为与 cron 一致）。
        // 返回处理附件数。
        public function purge_all_pending() {
            $ids = get_posts([
                'post_type' => 'attachment',
                'post_status' => 'inherit',
                'meta_key' => self::PENDING_DELETE_META,
                'posts_per_page' => 500,
                'fields' => 'ids',
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            if (!is_array($ids)) return 0;
            $count = 0;
            foreach ($ids as $id) {
                $this->purge_pending_deletes((int)$id);
                $count++;
            }
            return $count;
        }

        // M22：把本地文件移入回收站（wp-content/uploads/.trash/），返回
        // 移入后的路径，失败返回 false。回收站就在 uploads 目录内，与原
        // 文件同一文件系统，rename 是同盘移动、不走跨盘复制。
        // N-D1 安全：uploads/ 是 web 直服目录，回收站文件名必须不可猜——
        // 每次移入都加 16 位随机 token（不知道 token 就拼不出 URL）；再按
        // 日志目录的做法补 .htaccess + index.php + index.html 三件套
        // （nginx 不解析 .htaccess，真正起作用的是 token，三件套只防
        // Apache 与目录浏览）。cleanup_trash() 会跳过这三个保护文件。
        private function trash_local_file($path) {
            $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
            $basedir = (is_array($upload_dir) && !empty($upload_dir['basedir'])) ? $upload_dir['basedir'] : dirname($path);
            $trash_dir = rtrim($basedir, '/') . '/.trash';
            if (!is_dir($trash_dir)) {
                if (function_exists('wp_mkdir_p')) {
                    wp_mkdir_p($trash_dir);
                } elseif (!@mkdir($trash_dir, 0755, true) && !is_dir($trash_dir)) {
                    return false;
                }
            }
            if (!is_dir($trash_dir)) return false;
            // 回收站目录防护文件（缺失时补写；cleanup_trash 会跳过它们）。
            if (!file_exists($trash_dir . '/.htaccess')) {
                @file_put_contents($trash_dir . '/.htaccess', 'Deny from all');
            }
            if (!file_exists($trash_dir . '/index.php')) {
                @file_put_contents($trash_dir . '/index.php', "<?php\n// Silence is golden.\n");
            }
            if (!file_exists($trash_dir . '/index.html')) {
                @file_put_contents($trash_dir . '/index.html', '');
            }
            // 文件名恒加随机 token：原名直接可用是 N-D1 的根因。
            // 64 位随机空间下重名可忽略；万一 rename 失败返回 false，
            // 调用方把该项留入待重试清单，文件不会丢。
            $base = basename($path);
            $stem = pathinfo($base, PATHINFO_FILENAME);
            $ext = pathinfo($base, PATHINFO_EXTENSION);
            try {
                $token = bin2hex(random_bytes(8));
            } catch (Throwable $e) {
                $token = substr(md5($path . microtime(true)), 0, 16);
            }
            $dest = $trash_dir . '/' . $stem . '-' . $token
                . ($ext !== '' ? '.' . $ext : '');
            if (!@rename($path, $dest)) return false;
            return $dest;
        }

        // M22：清理回收站里超过 TRASH_TTL（7 天）的文件，返回删除数。
        // 只清普通文件；purge 运行时顺手调用（见 purge_pending_deletes）。
        public function cleanup_trash() {
            $upload_dir = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
            if (!is_array($upload_dir) || empty($upload_dir['basedir'])) return 0;
            $trash_dir = rtrim($upload_dir['basedir'], '/') . '/.trash';
            if (!is_dir($trash_dir)) return 0;
            $cutoff = time() - self::TRASH_TTL;
            $removed = 0;
            foreach ((array)glob($trash_dir . '/*') as $f) {
                if (!is_file($f)) continue;
                // N-D1：跳过回收站自身的保护文件（index.php/index.html；
                // .htaccess 是点文件，本来就不会被 glob('*') 命中）。
                $bn = basename($f);
                if ($bn === 'index.php' || $bn === 'index.html') continue;
                $mtime = filemtime($f);
                if ($mtime !== false && $mtime < $cutoff && @unlink($f)) $removed++;
            }
            return $removed;
        }

        // WP-Cron 回调：二次校验待删清单，全部通过才删本地文件。
        // 校验失败分两种：COS 对象确实不存在（同步声明为假）——清除
        // _sky_oss_synced、本地文件保留、该项从待删清单移除（不再删）；
        // 对象在但大小/ETag 不符（可能是 COS 尚未一致）——保留清单、
        // 1 小时后重试（上限 3 次），不清已同步标记。
        // 注意：本地文件已不在 ≠ 校验失败——本地文件可能被其他途径删掉，
        // 此时只核查 COS 对象是否存在；COS 对象完好就视为已同步，只删待删
        // 清单、不清除 _sky_oss_synced。
        // R3：回调开头检查 oss_delete_local（关了就不删、登记作废）；读
        // $pending['time'] 做 24 小时判断（未满按剩余时间重排期）；失败/
        // 暂缓路径 1 小时后重排期重试（上限 3 次）；只有真正删成功（或本地
        // 已不在、或 COS 已确认不存在）时才从清单移除，@unlink 失败的项
        // 保留清单、重排重试。
        public function purge_pending_deletes($attachment_id) {
            $attachment_id = (int)$attachment_id;
            $pending = get_post_meta($attachment_id, self::PENDING_DELETE_META, true);
            if (!is_array($pending) || empty($pending['files']) || !is_array($pending['files'])) return;

            $core = Skyline_Core::instance();
            // M22：每次 purge 运行时顺手清理回收站里超过 7 天的文件。
            $swept = $this->cleanup_trash();
            if ($swept > 0) {
                $core->log("🗑️ [OSS-Zero-Disk] 回收站清理：已删除 {$swept} 个超过 7 天的文件", 'info', 'OSS');
            }
            if (!$core->get_opt('oss_enable')) {
                // OSS 已关闭：不删文件，保留待删清单，1 小时后重试（上限 3 次）。
                $core->log("⚠️ [OSS-Zero-Disk] OSS 已关闭，附件 #{$attachment_id} 的本地文件暂不删除", 'warn', 'OSS');
                $this->reschedule_purge($attachment_id, $pending, 'OSS 已关闭');
                return;
            }

            // Zero-Disk 开关已关闭时不再删文件——上传成功后、Cron 触发前关掉
            // 开关，本地文件必须保留；待删登记直接作废。
            if (!$core->get_opt('oss_delete_local')) {
                delete_post_meta($attachment_id, self::PENDING_DELETE_META);
                $core->log("⚠️ [OSS-Zero-Disk] Zero-Disk 已关闭，附件 #{$attachment_id} 的待删登记已作废，本地文件保留", 'warn', 'OSS');
                return;
            }

            // $pending['time'] 之前从未被读，24 小时延迟只靠 Cron 排期保证；
            // 这里直接校验——未满 24 小时不删，按剩余时间重排期（不计入重试）。
            $registered = isset($pending['time']) ? (int)$pending['time'] : 0;
            if ($registered > 0 && time() - $registered < self::PURGE_DELAY) {
                $wait = self::PURGE_DELAY - (time() - $registered);
                if (!wp_next_scheduled('skyline_oss_purge_pending', [$attachment_id])) {
                    wp_schedule_single_event(time() + $wait, 'skyline_oss_purge_pending', [$attachment_id]);
                }
                return;
            }

            try {
                $client = $this->make_cos_client();
                if (!$client) {
                    // 客户端建不起来：保留清单与本地文件，1 小时后重试（上限 3 次）。
                    $this->reschedule_purge($attachment_id, $pending, 'COS 客户端创建失败');
                    return;
                }

                $remaining = []; // 待重试的项（校验未通过但对象还在、或格式异常）
                $to_delete = []; // 校验通过、本地还在的项
                $cos_missing = false; // COS 对象确实不存在（同步声明为假）
                foreach ($pending['files'] as $entry) {
                    if (!is_array($entry) || empty($entry['path']) || empty($entry['key'])) {
                        $remaining[] = $entry;
                        continue;
                    }
                    if (file_exists($entry['path'])) {
                        // 本地文件还在：用强校验（大小 + ETag）核对 COS 对象。
                        if ($client->verifyFile($entry['key'], $entry['path'])) {
                            $to_delete[] = $entry;
                            continue;
                        }
                        // 校验不通过：区分“对象不存在”与“对象在但内容不符”。
                        if (!$client->remoteExists($entry['key'])) {
                            $cos_missing = true;
                            continue; // 对象不存在：本地是唯一副本，保留、不删，该项丢弃。
                        }
                        $remaining[] = $entry; // 对象在但大小/ETag 不符：先重试，不清标记。
                    } else {
                        // 本地文件已不在：只核查 COS 对象是否存在（N2）。
                        // COS 对象完好即视为已同步，该项完成；COS 也无则同步声明为假。
                        if (!$client->remoteExists($entry['key'])) $cos_missing = true;
                    }
                }

                if ($cos_missing) {
                    delete_post_meta($attachment_id, '_sky_oss_synced');
                    $core->log("❌ [OSS-Zero-Disk] COS 对象缺失，附件 #{$attachment_id} 已取消已同步标记，本地文件已保留", 'error', 'OSS');
                }

                // M22：先移入回收站再删——校验通过的本地文件先 rename 到
                // wp-content/uploads/.trash/（同盘、同文件系统），保留 7 天
                // 再由后续 purge 的回收站清理真正删除；COS 侧事后损坏/误删
                // 时本地仍有副本可恢复。移入失败的项并入待重试清单。
                $deleted_count = 0;
                $trashed = [];
                $seen = [];
                foreach ($to_delete as $entry) {
                    if (isset($seen[$entry['path']])) continue;
                    $seen[$entry['path']] = true;
                    if (file_exists($entry['path'])) {
                        $dest = $this->trash_local_file($entry['path']);
                        if ($dest !== false) { $deleted_count++; $trashed[] = $entry['path'] . ' -> ' . $dest; continue; }
                        $remaining[] = $entry;
                    }
                }
                // 删除清单写日志（即使还有待重试项、提前返回，也要先记下已
                // 移入回收站的文件，否则这批删除无据可查）。
                if ($deleted_count > 0) {
                    $core->log("🗑️ [OSS-Zero-Disk] 二次校验通过，{$deleted_count} 个本地文件已移入回收站（保留 7 天）：\n" . implode("\n", $trashed), 'info', 'OSS');
                }

                if (!empty($remaining)) {
                    $pending['files'] = $remaining;
                    $this->reschedule_purge($attachment_id, $pending, '二次校验未通过或删除失败');
                    return;
                }

                delete_post_meta($attachment_id, self::PENDING_DELETE_META);
            } catch (Throwable $e) {
                // 校验过程异常：宁可不删。保留清单与本地文件，1 小时后重试（上限 3 次）。
                $core->log("💥 [OSS-Zero-Disk] 二次清理异常，已保留本地文件：附件 #{$attachment_id} | " . $e->getMessage(), 'error', 'OSS');
                $this->reschedule_purge($attachment_id, $pending, '二次清理异常：' . $e->getMessage());
            }
        }

        // R3：失败/暂缓时 1 小时后重排期重试，上限 3 次；超限记错误日志
        // 并保留清单待人工处理，不再重排。
        private function reschedule_purge($attachment_id, $pending, $reason = '') {
            $retries = isset($pending['retries']) ? (int)$pending['retries'] : 0;
            if ($retries >= 3) {
                $core = Skyline_Core::instance();
                $core->log("❌ [OSS-Zero-Disk] 附件 #{$attachment_id} 二次清理重试已满 3 次，停止重试，待删清单已保留待人工处理" . ($reason !== '' ? "（{$reason}）" : ''), 'error', 'OSS');
                return false;
            }
            $pending['retries'] = $retries + 1;
            update_post_meta($attachment_id, self::PENDING_DELETE_META, $pending);
            if (!wp_next_scheduled('skyline_oss_purge_pending', [$attachment_id])) {
                wp_schedule_single_event(time() + 3600, 'skyline_oss_purge_pending', [$attachment_id]);
            }
            return true;
        }

        // 转 WebP 后原图的两阶段删除（keep_original=false 时）：
        // 只有 COS 全部上传成功（走到这里）才删；删之前再核对一次——
        // 待删路径必须是 JPG/PNG、必须真实存在、且不能是当前附件指向的
        // 文件或当前尺寸文件（防过期清单误删现役文件）。删失败不报错，
        // 只记日志、文件保留（安全方向）。
        private function delete_webp_originals($attachment_id) {
            $pending = get_post_meta($attachment_id, '_sky_webp_orig_pending', true);
            if (!is_array($pending) || empty($pending)) return;
            // N-D5：原来先删登记再删文件，删失败就丢了重试记录。改为逐个删：
            // 成功的从清单里去掉，失败的留在清单里（下次 upload_all_sizes
            // 成功分支再调本方法时重试；清单本身就是重试记录）。
            $current = get_attached_file($attachment_id);
            $metadata = get_post_meta($attachment_id, '_wp_attachment_metadata', true);
            $live = [];
            if (is_string($current) && $current !== '') $live[$current] = true;
            if (is_array($metadata) && !empty($metadata['sizes']) && is_string($current) && $current !== '') {
                $base = dirname($current);
                foreach ($metadata['sizes'] as $s) {
                    if (!empty($s['file'])) $live[$base . '/' . $s['file']] = true;
                }
            }
            $core = Skyline_Core::instance();
            $remaining = [];
            foreach ($pending as $path) {
                if (!is_string($path) || $path === '' || isset($live[$path])) continue;
                $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
                if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) continue;
                if (!file_exists($path)) continue;
                if (@unlink($path)) {
                    $core->log("🗑️ [WebP] 原图已删除（WebP 已校验 + COS 已同步）：{$path}", 'info', 'Turbo');
                } else {
                    $core->log("⚠️ [WebP] 原图删除失败，已保留待重试：{$path}", 'warn', 'Turbo');
                    $remaining[] = $path;
                }
            }
            if (empty($remaining)) {
                delete_post_meta($attachment_id, '_sky_webp_orig_pending');
            } else {
                update_post_meta($attachment_id, '_sky_webp_orig_pending', array_values(array_unique($remaining)));
            }
        }

        public function replace_url($url, $post_id) {
            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable') || !get_post_meta($post_id, '_sky_oss_synced', true)) return $url;
            // L17：OSS 三项配置全空时直接返回原 URL——否则拼出 https://.
            // （bucket 与 endpoint 都空时 "https://{$bucket}.{$endpoint}"
            // 得 "https://."），再 str_replace 会产出坏 URL。
            $bucket = $core->get_opt('oss_bucket');
            $endpoint = $core->get_opt('oss_endpoint');
            $domain = $core->get_opt('oss_domain');
            if (empty($bucket) && empty($endpoint) && empty($domain)) return $url;
            $domain = rtrim($domain ?: "https://{$bucket}.{$endpoint}", '/');
            return str_replace(rtrim(get_site_url(), '/'), $domain, $url);
        }

        public function replace_image_src($image, $attachment_id, $size, $icon) {
            if (!$image) return $image;
            $core = Skyline_Core::instance();
            if (!$core->get_opt('oss_enable') || !get_post_meta($attachment_id, '_sky_oss_synced', true)) return $image;
            // L17：同 replace_url，三项全空时不拼 https://.。
            $bucket = $core->get_opt('oss_bucket');
            $endpoint = $core->get_opt('oss_endpoint');
            $domain = $core->get_opt('oss_domain');
            if (empty($bucket) && empty($endpoint) && empty($domain)) return $image;
            $domain = rtrim($domain ?: "https://{$bucket}.{$endpoint}", '/');
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
            // L18：region 正则补 cos-website 形态——
            // lingganwu-1253189685.cos-website.ap-beijing.myqcloud.com
            // 要能抓到 ap-beijing（修前只认 cos[-.]<region>.myqcloud，
            // 在 cos-website 后抓到的是 website，匹配失败回落 ap-beijing；
            // 北京地域碰巧正确，非北京地域的桶会因 region 错误签名失败）。
            if (preg_match('/cos(?:-website)?[-.]([a-z0-9-]+)\.myqcloud/i', $endpoint, $m)) {
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

        // R12：真实连通性测试——写一个小测试对象、读回核对、再删掉。
        // 返回 ['ok'=>bool, 'error'=>string]；任何一步失败都说明 bucket
        // 不存在、凭证错误或权限不足，而不是“能连通 endpoint 就算成功”。
        public function test_bucket() {
            $key = 'skyline-test-' . substr(md5(uniqid('sky_test_', true)), 0, 12) . '.txt';
            $body = 'skyline-oss-test';
            try {
                $this->client->putObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key,
                    'Body'   => $body,
                    'ACL'    => 'private',
                ]);
                $head = $this->client->headObject([
                    'Bucket' => $this->bucket,
                    'Key'    => $key
                ]);
                $size = isset($head['ContentLength']) ? (int)$head['ContentLength'] : null;
                if ($size !== null && $size !== strlen($body)) {
                    $this->delete_test_object($key);
                    return ['ok' => false, 'error' => 'OSS 写入后读回的大小不一致'];
                }
                $this->delete_test_object($key);
                return ['ok' => true, 'error' => ''];
            } catch (\Throwable $e) {
                // 尽力清理测试对象，清理失败也不掩盖原始错误。
                $this->delete_test_object($key);
                return ['ok' => false, 'error' => $e->getMessage()];
            }
        }

        private function delete_test_object($key) {
            try {
                $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key]);
            } catch (\Throwable $ignored) {}
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
                // R8：两项全缺时 fail-closed——headObject 既不返回 ContentLength
                // 也不返回 ETag，等于零校验，不能算通过。至少一项能比对且通过才算成功。
                if ($remote_size === null && $etag === '') {
                    if (class_exists('Skyline_Core')) {
                        try { Skyline_Core::instance()->log("⚠️ [OSS] 强校验无据可依（COS 未返回 ContentLength 与 ETag），按失败处理: {$key}", 'warn', 'OSS'); } catch (Throwable $ignored) {}
                    }
                    return false;
                }
                return true;
            } catch (\Throwable $e) {
                return false;
            }
        }

        // L28(b)：abort 指定 key 下未完成的分片上传。Upload() 复合接口
        // 内部管理 uploadId，调用方拿不到；这里用 listMultipartUploads
        // 按 key 前缀找回未完成的上传再逐个 abort。SDK 不支持这两个
        // 方法时记一条 warn 日志（残留分片需靠桶的生命周期规则清理），
        // 不抛错。任何一步异常都静默吞掉——清理是尽力而为，不能掩盖
        // 原始上传错误。
        private function abort_incomplete_uploads($key) {
            try {
                if (!method_exists($this->client, 'listMultipartUploads')
                    || !method_exists($this->client, 'abortMultipartUpload')) {
                    if (class_exists('Skyline_Core')) {
                        try { Skyline_Core::instance()->log("⚠️ [OSS] SDK 不支持 abort 未完成分片，未完成的分片可能残留（需桶生命周期规则清理）：{$key}", 'warn', 'OSS'); } catch (Throwable $ignored) {}
                    }
                    return;
                }
                $list = $this->client->listMultipartUploads(['Bucket' => $this->bucket, 'Prefix' => $key]);
                $uploads = (is_array($list) && isset($list['Uploads']) && is_array($list['Uploads'])) ? $list['Uploads'] : [];
                foreach ($uploads as $u) {
                    if (!is_array($u) || ($u['Key'] ?? '') !== $key || empty($u['UploadId'])) continue;
                    try {
                        $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $u['UploadId']]);
                    } catch (\Throwable $ignored) {}
                }
            } catch (\Throwable $ignored) {}
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
                    // L28(b)：分片中途异常时尝试 abort 未完成的分片上传，
                    // 否则 COS 上残留未完成的分片占桶空间（尽力清理，清理
                    // 失败也不掩盖原始错误）。
                    $this->abort_incomplete_uploads($key);
                    // 新分支单独捕获 Throwable：SDK 抛出的 Error（如 TypeError）
                    // 不能穿透到媒体上传导致 fatal；这里只记日志并返回失败，
                    // 让 upload_all_sizes 走失败分支（不写已同步标记、不删本地）。
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

            } catch (\Throwable $e) {
                $core->log("❌ [OSS] 上传失败: {$key} | 错误: " . $e->getMessage(), 'error', 'OSS');
                return false;
            }
        }
    }
}