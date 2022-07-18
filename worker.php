<?php

declare(strict_types=1);
require_once __DIR__.'/Protocols/GatewayProtocol.php';
use Swoole\Coroutine\Client as SWCClient;
use Swoole\Process\Pool as SWProcessPool;
use Swoole\Coroutine as SWCoroutine;
use Swoole\Timer as SWTimer;
use GatewayWorker\Protocols\GatewayProtocol;
$processPool = new SWProcessPool(2);
$processPool->set([
    'open_length_check' => true,
    'package_length_type' => 'N',
    'package_length_offset' => 0,
    'package_body_offset' => 0,
    'buffer_output_size' => 32 * 1024 * 1024, //最大允许发送 32M 字节的数据
    'pid_file' => __DIR__.'/server_worker.pid',

    'daemonize' => false,
    'event_object' => true,
    'task_object' => true,
    'reload_async' => true,
    'max_wait_time' => 60,
    'enable_coroutine' => true,
    'task_enable_coroutine' => true,
]);
$processPool->on('WorkerStart', function (SWProcessPool $pool, int $worker_id) {
    echo "[Worker #{$worker_id}] WorkerStart".PHP_EOL;

    //连接到注册中心
    $client = new SWCClient(SWOOLE_SOCK_TCP);
    if (!$client->connect('127.0.0.1', 1236, 0.5)) {
        echo "connect failed. Error: {$client->errCode}\n";
    }
    $secret_key='123456';
    $worker_connect_data=[
        "cmd"=>GatewayProtocol::CMD_WORKER_CONNECT,
        "body"=>[
            "secret_key"=>$secret_key
        ]
    ];
    $buffer=GatewayProtocol::encode($worker_connect_data);
    $client->send($buffer);
    $client->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use($client) {
        echo "ping to register timer_id #$timer_id, after 3000ms.".PHP_EOL;
        $ping_to_register = array(
            'cmd' => GatewayProtocol::CMD_PING,
            'body'=>array()
        );
        $buffer = GatewayProtocol::encode($ping_to_register);
        $client->send($buffer);
    });
    while (true) {
        $buffer = $client->recv();
        if ($buffer === false) {
            // 可以自行根据业务逻辑和错误码进行处理，例如：
            // 如果超时时则不关闭连接，其他情况直接关闭连接
            if ($client->errCode !== SOCKET_ETIMEDOUT) {
                echo "[Worker #{$worker_id}] register connection closed".PHP_EOL;
                SWTimer::clear($client->ping_timer_id);
                $client->close();
                break;
            }
        }else if($buffer===''){
            echo "connect failed. Error: {$client->errCode}".PHP_EOL;
            SWTimer::clear($client->ping_timer_id);
            $client->close();
            break;
        }else{
            $data=GatewayProtocol::decode($buffer);
            $cmd=$data['cmd'];
            $body=$data['body'];
            switch ($cmd){
                // 是 gateway addresses
                case GatewayProtocol::CMD_BROADCAST_ADDRESSES:
                    $addresses=$body['addresses'];
                    foreach ($addresses as $address) {
                        $addresses=explode(':',$body['addresses']);
                        $address_ip=$addresses[0];
                        $address_port=$addresses[1];
                        //链接到gateway
                        $client_gateway = new SWCClient(SWOOLE_SOCK_TCP);
                        if (!$client_gateway->connect($address_ip, $address_port, 0.5)) {
                            echo "connect failed. Error: {$client_gateway->errCode}\n";
                        }
                        $client_gateway->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use($client_gateway) {
                            echo "ping to gateway timer_id #$timer_id, after 3000ms.".PHP_EOL;
                            $ping_to_gateway = array(
                                'cmd' => GatewayProtocol::CMD_PING,
                                'body'=>array()
                            );
                            $buffer = GatewayProtocol::encode($ping_to_gateway);
                            $client_gateway->send($buffer);
                        });
                        while (true) {
                            $buffer = $client_gateway->recv();
                            if ($buffer === false) {
                                // 可以自行根据业务逻辑和错误码进行处理，例如：
                                // 如果超时时则不关闭连接，其他情况直接关闭连接
                                if ($client_gateway->errCode !== SOCKET_ETIMEDOUT) {
                                    echo "[Worker #{$worker_id}] register connection closed".PHP_EOL;
                                    SWTimer::clear($client_gateway->ping_timer_id);
                                    $client_gateway->close();
                                    break;
                                }
                            }else if($buffer===''){
                                echo "connect failed. Error: {$client_gateway->errCode}".PHP_EOL;
                                SWTimer::clear($client_gateway->ping_timer_id);
                                $client_gateway->close();
                                break;
                            }else {
                                $data = GatewayProtocol::decode($buffer);
                                $cmd = $data['cmd'];
                                $body = $data['body'];
                                switch ($cmd){
                                    case
                                }
                            }
                        }
                    }
                    break;
                case GatewayProtocol::CMD_PONG:
                    //pong
                    break;
            }
        }
        SWCoroutine::sleep(1);
    }
});

$processPool->on('WorkerStop', function (SWProcessPool $pool, int $worker_id) {
    echo "[Worker #{$worker_id}] WorkerStop".PHP_EOL;
    SWTimer::clearAll();
    $pool->shutdown();
});
$processPool->start();