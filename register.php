<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
use SwooleGateway\Register;

$register = new Register('0.0.0.0', 1236);

// 设置配置文件
$register->config_file = __DIR__ . '/config_register.php';
$register->start();
