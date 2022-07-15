<?php

declare(strict_types=1);
require_once __DIR__ . '/vendor/autoload.php';
use Xielei\Swoole\Register;

$register = new Register('0.0.0.0', 1236);
$register->start();
