<?php
if (!defined('ABSPATH')) exit;

// Turbo 相关辅助函数（示例，可按需扩展）

// 根据内容长度自动调整 max_tokens 或 temperature 等参数
function skyline_turbo_adjust_params($content) {
    $len = mb_strlen($content, 'UTF-8');
    $params = [
        'temperature' => 0.7,
        'max_tokens'  => 2000,
    ];

    if ($len > 5000) {
        $params['temperature'] = 0.6;
        $params['max_tokens']  = 3000;
    } elseif ($len < 1000) {
        $params['temperature'] = 0.8;
    }

    return $params;
}

// 可以在此扩展更多和“提速”、“批量处理”相关的工具函数
