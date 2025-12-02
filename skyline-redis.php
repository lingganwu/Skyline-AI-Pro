<?php
if (!defined('ABSPATH')) exit;

function skyline_redis_enabled() {
    return skyline_get_opt('redis_enable') ? true : false;
}

function skyline_redis_get_client() {
    if (!skyline_redis_enabled()) return null;
    if (!class_exists('Redis')) return null;

    static $client = null;
    if ($client) return $client;

    $host = skyline_get_opt('redis_host', '127.0.0.1');
    $port = intval(skyline_get_opt('redis_port', '6379'));
    $auth = skyline_get_opt('redis_auth', '');

    try {
        $client = new Redis();
        $client->connect($host, $port, 1.5);
        if ($auth) $client->auth($auth);
        return $client;
    } catch (Exception $e) {
        skyline_log('Redis 连接失败：' . $e->getMessage());
        return null;
    }
}

function skyline_redis_get($key) {
    $client = skyline_redis_get_client();
    if (!$client) return false;

    try {
        $val = $client->get($key);
        return $val === false ? false : $val;
    } catch (Exception $e) {
        skyline_log('Redis GET 失败：' . $e->getMessage());
        return false;
    }
}

function skyline_redis_set($key, $value, $ttl = 300) {
    $client = skyline_redis_get_client();
    if (!$client) return false;

    try {
        if ($ttl > 0) {
            return $client->setex($key, $ttl, $value);
        } else {
            return $client->set($key, $value);
        }
    } catch (Exception $e) {
        skyline_log('Redis SET 失败：' . $e->getMessage());
        return false;
    }
}
