<?php
/**
 * 注册中心
 */
namespace GatewayWorker\Swoole;

use Swoole\Server as SWServer;
use Swoole\Timer as SWTimer;
use Swoole\Server\Event as SWSEvent;
use GatewayWorker\Swoole\GatewayProtocol;

/**
 * 注册中心，用于注册 Gateway 和 Worker
 */
class Register
{
    private array $timeout_timer_id_map;
    private array $gateway_address_map;
    private array $worker_fd_set;
    private string $secret_key='';

    private SWServer $server;
    private array $config;
    public function __construct(string $ip='127.0.0.1',int $port=1236){
        $this->config=[
            'open_websocket_protocol' => false,
            'open_websocket_close_frame' => false,
            'open_http_protocol' => false,
            'heartbeat_idle_time' => 60,
            'heartbeat_check_interval' => 3,
            'open_length_check' => true,
            'package_length_type' => 'N',
            'package_length_offset' => 0,
            'package_body_offset' => 7,
            'package_max_length'=>81920,
            'package_length_func' => function ($buffer) {
                return GatewayProtocol::package_len($buffer);
            },
            'worker_num'=>1,
            'pid_file' => __DIR__.'/server_register.pid',

            'daemonize' => false,
            'event_object' => true,
            'task_object' => true,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'task_enable_coroutine' => true
        ];
        $this->server = new SWServer($ip, $port);
        $this->server->set($this->config);
        $this->server->on('Connect',array($this, 'onConnect'));
        $this->server->on('Receive',array($this, 'onReceive'));
        $this->server->on('Close',array($this, 'onClose'));
    }

    public function set($config){
        $this->config=array_merge($this->config,$config);
        $this->server->set($this->config);
    }

    private function onConnect(SWServer $server, SWSEvent $event): bool
    {
        echo "fd:".$event->fd." Connected".PHP_EOL;
        $timer_id=SWTimer::after(10000, function () use ($server, $event) {
            //Worker::log("Register auth timeout (".$fd_info['remote_ip']."). See http://doc2.workerman.net/register-auth-timeout.html");
            echo "Register auth timeout (".$event->fd.")".PHP_EOL;
            $exist=$server->exist($event->fd);
            if($exist){
                $server->close($event->fd);
            }
        });
        $this->timeout_timer_id_map[$event->fd]=$timer_id;
        return true;
    }

    private function onReceive(SWServer $server, SWSEvent $event): bool
    {
        if(isset($this->timeout_timer_id_map[$event->fd])){
            SWTimer::clear($this->timeout_timer_id_map[$event->fd]);
            unset($this->timeout_timer_id_map[$event->fd]);
        }
        $data = GatewayProtocol::decode($event->data);
        $cmd=$data['cmd'];
        $body=$data['body'];
        $secret_key = $body['secret_key'] ?? '';
        switch ($cmd){
            // 是 gateway 连接
            case GatewayProtocol::CMD_GATEWAY_CONNECT:
                if (empty($body['address'])) {
                    echo "address not found".PHP_EOL;
                    $server->close($event->fd);
                    return false;
                }
                if ($secret_key !== $this->secret_key) {
                    //Worker::log("Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($this->secretKey, true));
                    echo "Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($this->secret_key, true).PHP_EOL;
                    $server->close($event->fd);
                    return false;
                }
                $this->gateway_address_map[$event->fd] = $body['address'];

                //向所有 Worker 广播 gateway 内部通讯地址
                $data_to_worker = array(
                    'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
                    'body'=>[
                        'addresses' => array_unique(array_values($this->gateway_address_map)),
                    ]
                );
                $buffer = GatewayProtocol::encode($data_to_worker);
                foreach ($this->worker_fd_set as $worker_fd=>$_) {
                    $server->send($worker_fd, $buffer);
                }
                break;
            // 是 worker 连接
            case GatewayProtocol::CMD_WORKER_CONNECT:
                if ($secret_key !== $this->secret_key) {
                    //Worker::log("Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($secret_key_server, true));
                    echo "Register: Key does not match ".var_export($secret_key, true)." !== ".var_export($this->secret_key, true).PHP_EOL;
                    $server->close($event->fd);
                    return false;
                }
                $this->worker_fd_set[$event->fd] = 1;
                $data_to_worker = array(
                    'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
                    'body'=>[
                        'addresses' => array_unique(array_values($this->gateway_address_map))
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
    }

    private function onClose(SWServer $server, SWSEvent $event){
        if (isset($this->worker_fd_set[$event->fd])) {
            unset($this->worker_fd_set[$event->fd]);
        }
        if (isset($this->gateway_address_map[$event->fd])) {
            unset($this->gateway_address_map[$event->fd]);

            //向所有 Worker 广播 gateway 内部通讯地址
            $data_to_worker = array(
                'cmd' => GatewayProtocol::CMD_BROADCAST_ADDRESSES,
                'body'=>[
                    'addresses' => array_unique(array_values($this->gateway_address_map))
                ]
            );
            $buffer = GatewayProtocol::encode($data_to_worker);
            foreach ($this->worker_fd_set as $worker_fd=>$_) {
                $server->send($worker_fd, $buffer);
            }
        }
    }

    public function start(){
        return $this->server->start();
    }
}