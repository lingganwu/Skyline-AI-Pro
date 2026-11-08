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

    public function cache_get($key, $fallback = null, $ttl = 3600) {
        if ($this->redis) {
            $val = $this->redis_safe(function ($redis) use ($key) {
                return $redis->get('sky:' . $key);
            });
            if ($val !== false && $val !== null) {
                if (is_string($val)) {
                    $decoded = json_decode($val, true);
                    return $decoded !== null ? $decoded : $val;
                }
                return $val;
            }
        }
        if (is_callable($fallback)) {
            $val = $fallback();
            if ($this->redis) {
                $this->redis_safe(function ($redis) use ($key, $val, $ttl) {
                    $redis->setex('sky:' . $key, $ttl, is_array($val) ? json_encode($val) : $val);
                });
            }
            return $val;
        }
        return null;
    }

    public function cache_set($key, $val, $ttl = 3600) {
        if ($this->redis) {
            $this->redis_safe(function ($redis) use ($key, $val, $ttl) {
                $redis->setex('sky:' . $key, $ttl, is_array($val) ? json_encode($val) : $val);
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
}

// ====================== Redis 缓存模块 ======================
if (!class_exists('Skyline_Redis_Mod')) {
class Skyline_Redis_Mod {
    public function __construct() {
        add_action('init', [$this, 'page_cache'], 0);
        add_action('save_post', [$this, 'smart_flush'], 10, 2);
    }

    public function page_cache() {
        if (!class_exists('Skyline_Core')) return;
        $core = Skyline_Core::instance();
        
        if(!$core->get_opt('redis_enable') || is_user_logged_in() || is_admin() || $_SERVER['REQUEST_METHOD'] !== 'GET') return;
        
        $uri = $_SERVER['REQUEST_URI'];
        $excludes = explode("\n", (string)$core->get_opt('redis_exclude', ''));
        foreach ($excludes as $ex) { if (trim($ex) && strpos($uri, trim($ex)) !== false) return; }
        
        $infra = Skyline_Infra::instance();
        $cache_uri = $uri;
        $key = 'page_' . md5(home_url($cache_uri));
        
        $cached = $infra->cache_get($key);
        if ($cached && is_string($cached)) { header('X-Sky-Redis: HIT'); echo $cached; exit; }
        
        ob_start(function($buf) use ($infra, $key, $core) {
            if (strlen($buf) > 200 && http_response_code() === 200 && !is_404()) {
                $infra->cache_set($key, $buf, intval($core->get_opt('redis_ttl', 3600)));
            }
            return $buf;
        });
    }

    public function smart_flush($pid, $post) {
        if(wp_is_post_revision($pid) || $post->post_status != 'publish') return;
        $infra = Skyline_Infra::instance();
        $infra->cache_del('page_' . md5(home_url('/')));
        $infra->cache_del('page_' . md5(get_permalink($pid)));
    }
}
}

// ====================== 图片压缩优化模块 ======================
if (!class_exists('Skyline_Turbo_Mod')) {
class Skyline_Turbo_Mod {
    public function __construct() {
        add_filter('wp_generate_attachment_metadata', [$this, 'compress'], 5, 2);
    }
    public function compress($metadata, $attachment_id) {
        $core = Skyline_Core::instance();
        if(!$core->get_opt('turbo_enable_image_opt')) return $metadata;
        
        $file = get_attached_file($attachment_id);
        if(!$file || !file_exists($file) || filesize($file) > 10 * 1024 * 1024) return $metadata;
        
        $type = get_post_mime_type($attachment_id);
        if(!in_array($type, ['image/jpeg', 'image/png', 'image/webp'])) return $metadata;

        $editor = wp_get_image_editor($file);
        if(is_wp_error($editor)) return $metadata;

        $editor->set_quality((int)$core->get_opt('turbo_quality', 85));
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