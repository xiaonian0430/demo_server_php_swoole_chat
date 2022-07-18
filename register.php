<?php

declare(strict_types=1);
require_once __DIR__.'/Protocols/GatewayProtocol.php';
use Swoole\Server as SWServer;
use Swoole\Timer as SWTimer;
use Swoole\Server\Event as SWSEvent;
use GatewayWorker\Protocols\GatewayProtocol;

$server = new SWServer("0.0.0.0", 1236);
$server->set([
    'open_websocket_protocol' => false,
    'open_websocket_close_frame' => false,
    'open_http_protocol' => false,
    'heartbeat_idle_time' => 60,
    'heartbeat_check_interval' => 3,
    'open_length_check' => true,
    'package_length_type' => 'N',
    'package_length_offset' => 0,
    'package_body_offset' => 7,
    'package_length_func' => function ($buffer) {
        return GatewayProtocol::package_len($buffer);
    },
    'worker_num'=>1,
    'package_max_length'=>81920,
    'pid_file' => __DIR__.'/server_register.pid',

    'daemonize' => false,
    'event_object' => true,
    'task_object' => true,
    'reload_async' => true,
    'max_wait_time' => 60,
    'enable_coroutine' => true,
    'task_enable_coroutine' => true,
]);

//定时器存储表
$_timeout_timer_table=array();

/**
 * 所有 gateway 的地址存储表
 *
 * @var array
 */
$_gateway_address_table=array();

/**
 * 所有 worker 的连接集合
 *
 * @var array
 */
$_worker_fd_set = array();

/**
 * 连接回调，设置个定时器，将未及时发送验证的连接关闭
 */
$server->on('Connect', function (SWServer $server, SWSEvent $event){
    global $_timeout_timer_table;
    echo "fd:".$event->fd." Connected".PHP_EOL;
    $timeout_id=SWTimer::after(10000, function () use ($server,$event) {
        //Worker::log("Register auth timeout (".$fd_info['remote_ip']."). See http://doc2.workerman.net/register-auth-timeout.html");
        echo "Register auth timeout (".$event->fd.")".PHP_EOL;
        $exist=$server->exist($event->fd);
        if($exist){
            $server->close($event->fd);
        }
    });
    $_timeout_timer_table[$event->fd]=$timeout_id;
});

/**
 * 消息回调
 */
$server->on('Receive', function (SWServer $server, SWSEvent $event) {
    global $_timeout_timer_table;
    global $_gateway_address_table;
    global $_worker_fd_set;
    if(isset($_timeout_timer_table[$event->fd])){
        SWTimer::clear($_timeout_timer_table[$event->fd]);
        unset($_timeout_timer_table[$event->fd]);
    }
    $data = GatewayProtocol::decode($event->data);
    $cmd=$data['cmd'];
    $body=$data['body'];
    $secret_key = $body['secret_key'] ?? '';
    $secret_key_server='123456';
    switch ($cmd){
        // 是 gateway 连接
        case GatewayProtocol::CMD_GATEWAY_CONNECT:
            if (empty($body['address'])) {
                echo "address not found".PHP_EOL;
                $server->close($event->fd);
                return false;
            }
            if ($secret_key !== $secret_key_server) {
                //Worker::log("Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($this->secretKey, true));
                echo "Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($secret_key_server, true).PHP_EOL;
                $server->close($event->fd);
                return false;
            }
            $_gateway_address_table[$event->fd] = $body['address'];

            //向所有 Worker 广播 gateway 内部通讯地址
            $data_to_worker = array(
                'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
                'body'=>[
                    'addresses' => array_unique(array_values($_gateway_address_table)),
                ]
            );
            $buffer = GatewayProtocol::encode($data_to_worker);
            foreach ($_worker_fd_set as $worker_fd=>$_) {
                $server->send($worker_fd, $buffer);
            }
            break;
        // 是 worker 连接
        case GatewayProtocol::CMD_WORKER_CONNECT:
            if ($secret_key !== $secret_key_server) {
                //Worker::log("Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($secret_key_server, true));
                echo "Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($secret_key_server, true).PHP_EOL;
                $server->close($event->fd);
                return false;
            }
            $_worker_fd_set[$event->fd] = 1;
            $data_to_worker = array(
                'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
                'body'=>[
                    'addresses' => array_unique(array_values($_gateway_address_table))
                ]
            );
            $buffer = GatewayProtocol::encode($data_to_worker);
            $server->send($event->fd, $buffer);
            break;
        case GatewayProtocol::CMD_PING:
            $data_to_back = array(
                'cmd' => GatewayProtocol::CMD_PONG,
                'body'=>array()
            );
            $buffer = GatewayProtocol::encode($data_to_back);
            $server->send($event->fd, $buffer);
            break;
        default:
            //Worker::log("Register unknown event:$event IP: ".$client_info['remote_ip']." Buffer:$event->data. See http://doc2.workerman.net/register-auth-timeout.html");
            echo "Register unknown cmd:".$cmd." Buffer:$event->data. See http://doc2.workerman.net/register-auth-timeout.html".PHP_EOL;
            $server->close($event->fd);
            return true;
    }
    return true;
});

//监听连接关闭事件
$server->on('Close', function (SWServer $server, SWSEvent $event) {
    global $_gateway_address_table;
    global $_worker_fd_set;
    if (isset($_worker_fd_set[$event->fd])) {
        unset($_worker_fd_set[$event->fd]);
    }
    if (isset($_gateway_address_table[$event->fd])) {
        unset($_gateway_address_table[$event->fd]);

        //向所有 Worker 广播 gateway 内部通讯地址
        $data_to_worker = array(
            'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
            'body'=>[
                'addresses' => array_unique(array_values($_gateway_address_table))
            ]
        );
        $buffer = GatewayProtocol::encode($data_to_worker);
        foreach ($_worker_fd_set as $worker_fd=>$_) {
            $server->send($worker_fd, $buffer);
        }
    }
});

$server->start();