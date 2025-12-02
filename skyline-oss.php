<?php
if (!defined('ABSPATH')) exit;

function skyline_oss_enabled() {
    return skyline_get_opt('oss_enable') ? true : false;
}

function skyline_oss_validate_config() {
    $required = ['oss_key', 'oss_secret', 'oss_endpoint', 'oss_bucket'];
    $errors = [];
    foreach ($required as $key) {
        if (!skyline_get_opt($key)) {
            $errors[] = $key . ' 未配置';
        }
    }
    return $errors;
}

function skyline_oss_get_client() {
    if (!skyline_oss_enabled()) return null;

    static $client = null;
    if ($client) return $client;

    $errors = skyline_oss_validate_config();
    if (!empty($errors)) {
        skyline_log('OSS 配置缺失：' . implode('；', $errors));
        return null;
    }

    $key = skyline_get_opt('oss_key');
    $secret = skyline_get_opt('oss_secret');
    $endpoint = skyline_get_opt('oss_endpoint');

    // 优先使用阿里云 OSS SDK
    if (class_exists('OSS\OssClient')) {
        try {
            $client = new OSS\OssClient($key, $secret, $endpoint);
            return $client;
        } catch (Exception $e) {
            skyline_log('初始化 OSS 客户端失败：' . $e->getMessage());
            return null;
        }
    }

    // 兼容 AWS S3 风格 SDK
    if (class_exists('Aws\S3\S3Client')) {
        try {
            $client = new Aws\S3\S3Client([
                'version' => 'latest',
                'region'  => 'auto',
                'endpoint'=> $endpoint,
                'credentials' => [
                    'key'    => $key,
                    'secret' => $secret,
                ],
                'use_path_style_endpoint' => true,
            ]);
            return $client;
        } catch (Exception $e) {
            skyline_log('初始化 S3 客户端失败：' . $e->getMessage());
            return null;
        }
    }

    skyline_log('未找到可用的 OSS/S3 SDK，请确认已安装依赖。');
    return null;
}

function skyline_oss_build_key($file_name) {
    $prefix = apply_filters('skyline_oss_key_prefix', 'skyline/' . date('Y/m/d/'));
    $clean_name = sanitize_file_name($file_name ?: uniqid('ai-', true));
    $key = $prefix . $clean_name;
    return apply_filters('skyline_oss_upload_key', $key, $file_name);
}

function skyline_oss_upload_resource($file_name, $content, $mime = 'application/octet-stream') {
    if (!skyline_oss_enabled()) return false;

    $client = skyline_oss_get_client();
    if (!$client) return false;

    $bucket = skyline_get_opt('oss_bucket');
    $key = skyline_oss_build_key($file_name);

    try {
        if ($client instanceof OSS\OssClient) {
            $client->putObject($bucket, $key, $content, ['Content-Type' => $mime]);
            $url = skyline_oss_public_url($key);
            do_action('skyline_oss_upload_success', $key, $url, 'oss');
            return $url;
        }

        if ($client instanceof Aws\S3\S3Client) {
            $result = $client->putObject([
                'Bucket' => $bucket,
                'Key'    => $key,
                'Body'   => $content,
                'ContentType' => $mime,
                'ACL' => 'public-read',
            ]);
            $url = isset($result['ObjectURL']) ? (string)$result['ObjectURL'] : skyline_oss_public_url($key);
            do_action('skyline_oss_upload_success', $key, $url, 's3');
            return $url;
        }
    } catch (Exception $e) {
        skyline_log('上传到 OSS 失败：' . $e->getMessage());
        do_action('skyline_oss_upload_failed', $key, $e->getMessage());
        return false;
    }

    skyline_log('未知客户端类型，无法上传。');
    return false;
}

function skyline_oss_public_url($key) {
    $bucket = skyline_get_opt('oss_bucket');
    $endpoint = skyline_get_opt('oss_endpoint');
    $url = trailingslashit($endpoint);
    // 兼容类似 https://bucket.oss-xx.aliyuncs.com 或自建域
    if (strpos($endpoint, $bucket . '.') === false) {
        $url = trailingslashit($endpoint . '/' . $bucket);
    }
    $full = $url . ltrim($key, '/');
    return apply_filters('skyline_oss_public_url', $full, $key);
}

// 针对其他模块提供的简单动作钩子
add_action('skyline_upload_ai_asset', function($file_name, $content, $mime = 'application/octet-stream') {
    skyline_oss_upload_resource($file_name, $content, $mime);
}, 10, 3);

// 启用时进行一次配置校验
add_action('init', function(){
    if (!skyline_oss_enabled()) return;
    $errors = skyline_oss_validate_config();
    if (!empty($errors)) {
        skyline_log('OSS 配置校验失败：' . implode('；', $errors));
    }
});
