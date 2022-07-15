<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
use Xielei\Swoole\Gateway;
$gateway = new Gateway();

// 设置注册中心连接参数
$gateway->register_host = '172.19.15.10';
$gateway->register_port = 9327;

// 设置内部连接参数
$gateway->lan_host = '127.0.0.1';
$gateway->lan_port = 9108;

$gateway->listen('0.0.0.0', 8000, [
    'open_websocket_protocol' => true,
    'open_websocket_close_frame' => true,
    'heartbeat_idle_time' => 60,
    'heartbeat_check_interval' => 3,
]);

$gateway->start();
