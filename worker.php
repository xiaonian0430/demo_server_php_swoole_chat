<?php

declare(strict_types=1);
require_once __DIR__.'/gatewayworker-swoole/Protocols/GatewayProtocol.php';
require_once __DIR__.'/gatewayworker-swoole/Register.php';

use GatewayWorker\Swoole\Worker;
$server = new Worker(2);
$server->set([
    'pid_file' => __DIR__.'/server_worker.pid',
]);
$server->register_ip='192.168.91.139';
$server->register_port=1236;
$server->secret_key='123456';
$server->start();