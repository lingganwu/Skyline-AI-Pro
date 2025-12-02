<?php
if (!defined('ABSPATH')) exit;

// 简单蜘蛛采集示例
function skyline_spider_fetch($url, $args = []) {
    $resp = wp_remote_get($url, [
        'timeout' => isset($args['timeout']) ? intval($args['timeout']) : 20,
        'headers' => isset($args['headers']) ? $args['headers'] : [],
    ]);
    if (is_wp_error($resp)) {
        skyline_log('Spider 抓取失败：' . $resp->get_error_message());
        return false;
    }
    $code = wp_remote_retrieve_response_code($resp);
    if ($code != 200) {
        skyline_log('Spider HTTP 状态异常：' . $code);
        return false;
    }
    return wp_remote_retrieve_body($resp);
}

// 根据选择器提取内容，简单示例（实际可使用 DOMDocument / QueryPath 等）
function skyline_spider_extract($html, $selector) {
    // 为保持轻量，此处仅做极简示例：直接返回原始 HTML
    // 你可以自行扩展为 DOM 解析、正则提取等
    return $html;
}
