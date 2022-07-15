<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
use Xielei\Swoole\Gateway;

$gateway = new Gateway();
// 设置配置文件
$gateway->config_file = __DIR__ . '/config_gateway.php';
// 设置注册中心连接参数
$gateway->register_host = '192.168.91.132';
$gateway->register_port = 50100;

// 设置内部连接参数
$gateway->lan_host = '172.19.16.12'; //分布式部署时请设置成内网ip（非127.0.0.1）
$gateway->lan_port = 2300;

$gateway->listen('0.0.0.0', 7272, [
    'open_websocket_protocol' => true,
    'open_websocket_close_frame' => true,

    'heartbeat_idle_time' => 60,
    'heartbeat_check_interval' => 3,
]);

$gateway->start();
