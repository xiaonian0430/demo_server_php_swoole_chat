<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
use SwooleGateway\Worker;

$worker = new Worker();
$worker->config_file = __DIR__ . '/config_worker.php';
$worker->worker_file = __DIR__ . '/event_worker.php';

// 设置注册中心连接参数
$worker->register_host = '192.168.91.132';
$worker->register_port = 50100;
$worker->start();
