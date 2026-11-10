<?php
/**
 * Skyline AI Pro —— 卸载清理（v2.2.5）
 *
 * WordPress 在用户于后台删除本插件时加载本文件（插件本体不会被加载，
 * 因此本文件必须是自包含的：只用 WP 核心函数 + 最小内联逻辑，不 require
 * 插件主文件 skyline-ai-pro.php，也不依赖任何插件类已加载）。
 *
 * 只用 uninstall.php 这一种卸载机制，不与 register_uninstall_hook 并存
 *（主文件里只有 register_deactivation_hook，那是停用清理，与卸载无关）。
 *
 * 清理范围（逐项对照代码核对过，见下方各组注释）：
 *  1. Options：插件自有配置/统计/日志标记（共 6 个，见清单）。
 *  2. Transients：sky_opt_*（按 md5 生成）、限流、熔断、压缩警告、日志轮转。
 *  3. Post meta：插件写过的 14 个 _sky_* 键（逐个精确删除，不用 LIKE）。
 *  4. Cron：4 个单次事件 hook 的全部已排期实例。
 *  5. Redis：sky:* 命名空间下的全部键（页面缓存、配置缓存、限流桶、统计等）。
 * 多站点：is_multisite() 时循环每个子站逐站清理。
 *
 * 明确不删的东西（用户内容 / 用户决策项）：
 *  - 文章里已同步的图片文件、已生成的 SEO 内容（标题/slug/摘要/标签等）：
 *    那是用户内容，不是插件数据，卸载插件不应销毁用户网站内容。
 *  - skyline_backup_* option：插件的手动备份（设置/模板库/统计快照，
 *    见 inc/class-skyline-utils.php 的 create_backup()）。备份是用户
 *    的回滚手段，删不删应由用户自己决定，卸载时默认保留。
 *  - sky_ai_data option：代码里只有备份时读取（get_option 默认 []），
 *    全仓库没有任何一处 update_option 写它，不是本插件写入的数据，
 *    不碰（避免误删其他来源的数据）。
 *  - 日志文件（wp-content/uploads 下 skyline_ai-*.log）：7 天自动轮转
 *    过期删除，卸载时不主动删文件。
 */

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

// ---------------------------------------------------------------------------
// 清单（与代码逐项核对后的结果）
// ---------------------------------------------------------------------------

// Options：5 个来自合规清单 3.6，另加 1 个代码核对发现的遗漏——
// skyline_prompt_library（Prompt 模板库，core.php 的模板保存/删除入口
// 用 update_option 写入，是插件自有数据，卸载应一并清除）。
$skyline_uninstall_options = array(
    'skyline_ai_settings',                 // 主配置（含 API 密钥等，见 core.php）
    'skyline_ai_chat_log',                 // 前台最近聊天记录（core.php CHAT_LOG_OPTION）
    'skyline_ai_stats',                    // 统计累计值（core.php）
    'skyline_ai_settings_autoload_migrated', // L33 autoload 迁移标记（core.php）
    'skyline_ai_log_token',                // 日志文件名随机后缀（core.php）
    'skyline_prompt_library',              // Prompt 模板库（core.php 模板库入口）
    // 注意：skyline_backup_* 不删（见文件头说明）；sky_ai_data 不删（插件从未写入）。
);

// Post meta：代码里 get/update/delete_post_meta 与 meta_key 查询出现过的
// _sky_* 键恰好就是这 14 个（另有两个 _wp_attached_file /
// _wp_attachment_metadata 是 WP 核心的，不属于插件、绝不能删）。
// wp_ajax_sky_* 之类只是 AJAX 动作名，不是 post meta，不要混淆。
$skyline_uninstall_post_meta = array(
    '_sky_seo_done',            // SEO 已完成标记
    '_sky_seo_fail_count',      // SEO 失败次数
    '_sky_spider_last',         // 图片同步上次结果
    '_sky_spider_history',      // 图片同步历史
    '_sky_spider_processing',   // 图片同步进行中锁
    '_sky_oss_synced',          // OSS 已同步标记
    '_sky_oss_pending_delete',  // Zero-Disk 待删清单
    '_sky_oss_failed_sizes',    // OSS 上传失败的尺寸清单
    '_sky_oss_retry_attempts',  // OSS 失败重试次数
    '_sky_compressed',          // 图片已压缩标记
    '_sky_webp_done',           // WebP 已转换标记
    '_sky_webp_orig_pending',   // WebP 原图待删清单
    '_sky_source_hash',         // 外链图来源 hash（去重用）
    '_sky_source_url',          // 外链图来源 URL
);

// Cron：与主文件 register_deactivation_hook 清的 4 个 hook 完全一致。
// 卸载时仍要自己清一遍——卸载不一定走停用流程，且 uninstall.php 必须是自包含的。
$skyline_uninstall_cron_hooks = array(
    'skyline_async_spider',     // 图片同步单次事件
    'skyline_async_seo',        // 自动 SEO 单次事件
    'skyline_oss_purge_pending', // Zero-Disk 待删清理单次事件
    'skyline_oss_retry_failed',  // OSS 失败尺寸重试单次事件
);

// 固定名 transient（可直接 delete_transient 精确删除）。
$skyline_uninstall_transients = array(
    'sky_redis_circuit_open', // Redis 熔断标记（infra.php M12，60 秒）
    'sky_log_cleanup_day',    // 日志轮转“今天已清理”去重标记（core.php）
);

// 按前缀模式删除的 transient（键名含 md5 后缀，卸载时无法逐个拼出全名，
// 只能按前缀模式删；见 skyline_ai_uninstall_delete_transients_by_prefix()）。
$skyline_uninstall_transient_prefixes = array(
    'sky_opt_',                      // 配置缓存：sky_opt_<md5(配置键)>（core.php get_opt）
    'sky_chat_rate_',                // 前台聊天每分钟限流：sky_chat_rate_<md5(IP)>
    'sky_chat_rate_day_',            // 前台聊天每日限流：sky_chat_rate_day_<md5(IP)>
    'sky_redis_compression_warned_', // 压缩不可用警告去重：…_zstd / …_lzf（infra.php L2）
);

// ---------------------------------------------------------------------------
// Redis 清理：sky:* 命名空间
// ---------------------------------------------------------------------------

/**
 * 清掉 Redis 里 sky:* 下的全部键。能清则清，Redis 不可用时静默跳过、不报错。
 *
 * 优先复用插件现有 helper：Skyline_Infra::cache_del_prefix('')（SCAN + DEL
 * 分批删，绝不用 KEYS，见 infra.php P-6 注释）。但 WP 加载 uninstall.php
 * 时不会先加载插件本体，正常情况下这个类并不存在，所以这里只在类已加载
 * 时才用它（class_exists 判断，不存在就走回退分支）。
 *
 * 回退分支不 require 插件模块文件：直接 require infra 模块会触发
 * Skyline_Infra::__construct，进而实例化 Redis/Turbo/OSS 三个模块并注册
 * 一堆钩子/过滤器——那是运行态的副作用，不适合卸载时执行。回退分支只
 * 复用插件自己的连接要素：读 skyline_ai_settings 里存的同一套
 * host/port/auth/db，用同一个 phpredis Redis 类、同一个 2 秒连接超时、
 * 同一套 SCAN + DEL（每批 100 个键、绝不用 KEYS）模式清掉 sky:*。
 * 因此这里没有引入任何新的连接方式或客户端，只是把 init_redis() 的
 * 连接要素以内联最小逻辑复述了一遍；任何一步失败都直接跳过。
 *
 * 注意调用顺序：本函数必须在删除 skyline_ai_settings 之前执行，否则
 * 回退分支读不到 Redis 连接参数。
 */
function skyline_ai_uninstall_purge_redis() {
    // 路径一：插件 helper 已加载，直接复用（SCAN + DEL，模式 sky:*）。
    if (class_exists('Skyline_Infra') && method_exists('Skyline_Infra', 'cache_del_prefix')) {
        try {
            Skyline_Infra::instance()->cache_del_prefix('');
        } catch (Throwable $ignored) {
            // Redis 不可用或清理失败：跳过，不让卸载报错。
        }
        return;
    }

    // 路径二：回退——用插件配置里存的同一套连接参数直连一次。
    if (!class_exists('Redis')) {
        return; // 没装 phpredis 扩展：插件本身也连不上 Redis，无键可清。
    }
    $settings = function_exists('get_option') ? get_option('skyline_ai_settings', array()) : array();
    if (!is_array($settings) || empty($settings['redis_enable'])) {
        return; // Redis 未启用：插件没往 Redis 写过键。
    }
    try {
        $redis = new Redis();
        $connected = $redis->connect(
            isset($settings['redis_host']) ? $settings['redis_host'] : '127.0.0.1',
            isset($settings['redis_port']) ? (int) $settings['redis_port'] : 6379,
            2 // 与 infra.php init_redis() 同款 2 秒连接超时，避免卸载时长时间挂起。
        );
        if (!$connected) {
            return;
        }
        if (!empty($settings['redis_auth']) && !$redis->auth($settings['redis_auth'])) {
            return;
        }
        if (!empty($settings['redis_db']) && !$redis->select((int) $settings['redis_db'])) {
            return;
        }
        // 与 cache_del_prefix('') 同款：SCAN 游标分批 DEL，绝不用 KEYS
        //（KEYS 会阻塞整个 Redis，站上 2 核机扛不住）。
        $iterator = null;
        do {
            $keys = $redis->scan($iterator, 'sky:*', 100);
            if ($keys === false) {
                break;
            }
            if (!empty($keys)) {
                $redis->del($keys);
            }
        } while ($iterator !== 0 && $iterator !== null);
    } catch (Throwable $ignored) {
        // Redis 不可用或清理失败：跳过，不让卸载报错。
    }
}

/**
 * 按前缀模式删除 wp_options 表里的 transient 行。
 *
 * 选模式删除而不逐个 delete_transient 的理由：sky_opt_* 与
 * sky_chat_rate_* / sky_chat_rate_day_* 的键名里带 md5（配置键名 / 访客
 * IP 的 md5），卸载时不可能枚举出全部键名，逐个删无从下手。WP 的
 * transient（无持久对象缓存时）就存在 wp_options 表里，行名为
 * _transient_<键名> 与 _transient_timeout_<键名>，两类行都要删——只删
 * 数据行不删超时行会留下孤儿行，只删超时行则数据行残留。
 *
 * 局限（诚实说明）：如果站点用了持久对象缓存（Redis/Memcached 对象缓存），
 * transient 存在对象缓存里而不落 wp_options，本模式删除够不到它们；但
 * 那些副本都有 TTL（sky_opt_* 3600 秒、限流 60/86400 秒），且 Redis
 * 对象缓存里的 sky:sky_opt_* 副本已由同一次卸载的 Redis sky:* 清理清掉，
 * 残留最多随 TTL 自然过期，不会长期滞留。
 */
function skyline_ai_uninstall_delete_transients_by_prefix($prefix) {
    global $wpdb;
    if (!isset($wpdb) || !method_exists($wpdb, 'query') || !method_exists($wpdb, 'prepare')) {
        return;
    }
    $like = method_exists($wpdb, 'esc_like') ? $wpdb->esc_like($prefix) . '%' : $prefix . '%';
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_' . $like,
        '_transient_timeout_' . $like
    ));
}

// ---------------------------------------------------------------------------
// 单站清理
// ---------------------------------------------------------------------------

function skyline_ai_uninstall_cleanup_site() {
    global $wpdb, $skyline_uninstall_options, $skyline_uninstall_post_meta,
           $skyline_uninstall_cron_hooks, $skyline_uninstall_transients,
           $skyline_uninstall_transient_prefixes;

    // 1. Redis 先清：回退分支要读 skyline_ai_settings 里的连接参数，
    //    helper 分支的 init_redis() 也要读它，必须在删 option 之前做。
    skyline_ai_uninstall_purge_redis();

    // 2. Options（逐个精确删除；skyline_backup_* 故意不在清单里，见文件头）。
    foreach ($skyline_uninstall_options as $option) {
        delete_option($option);
    }

    // 3. Transients：固定名精确删 + md5 前缀模式删。
    foreach ($skyline_uninstall_transients as $transient) {
        delete_transient($transient);
    }
    foreach ($skyline_uninstall_transient_prefixes as $prefix) {
        skyline_ai_uninstall_delete_transients_by_prefix($prefix);
    }

    // 4. Post meta：14 个键逐个精确删除。故意不用 LIKE '_sky_%' 通配——
    //    通配会误删用户其他插件以 _sky_ 开头的 meta（如果存在）。
    if (isset($wpdb) && method_exists($wpdb, 'delete') && isset($wpdb->postmeta)) {
        foreach ($skyline_uninstall_post_meta as $meta_key) {
            $wpdb->delete($wpdb->postmeta, array('meta_key' => $meta_key));
        }
    }

    // 5. Cron：不带参数清掉每个 hook 的全部已排期实例（本站的单次事件
    //    都带文章/附件 id 参数，不带参数即全清，与主文件停用清理同款）。
    if (function_exists('wp_clear_scheduled_hook')) {
        foreach ($skyline_uninstall_cron_hooks as $hook) {
            wp_clear_scheduled_hook($hook);
        }
    }
}

// ---------------------------------------------------------------------------
// 入口：多站点循环各站清理，单站点直接清理当前站
// ---------------------------------------------------------------------------

if (function_exists('is_multisite') && is_multisite()) {
    // get_sites()（WP 4.6+）；插件要求 WP 5.8+，老版本回退已无必要。
    $sites = function_exists('get_sites') ? get_sites(array('number' => 0)) : array();
    if (!empty($sites)) {
        foreach ($sites as $site) {
            $blog_id = is_object($site) ? (int) $site->blog_id : (int) $site['blog_id'];
            if ($blog_id <= 0) {
                continue;
            }
            switch_to_blog($blog_id);
            try {
                skyline_ai_uninstall_cleanup_site();
            } catch (Throwable $ignored) {
                // 某子站清理失败不中断其他子站。
            }
            restore_current_blog();
        }
    } else {
        // 拿不到站点列表时至少清理当前站，不静默跳过。
        skyline_ai_uninstall_cleanup_site();
    }
} else {
    skyline_ai_uninstall_cleanup_site();
}
