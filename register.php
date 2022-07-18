<?php

declare(strict_types=1);
require_once __DIR__.'/gatewayworker-swoole/Protocols/GatewayProtocol.php';
require_once __DIR__.'/gatewayworker-swoole/Register.php';

use GatewayWorker\Swoole\Register;

$server = new Register("0.0.0.0", 1236);
$server->set([
    'pid_file' => __DIR__.'/server_register.pid',
]);
$server->start();