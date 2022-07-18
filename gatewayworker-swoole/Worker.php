<?php
/**
 * worker
 */
namespace GatewayWorker\Swoole;

use GatewayWorker\Swoole\GatewayProtocol;
use Swoole\Coroutine\Client as SWCClient;
use Swoole\Process\Pool as SWProcessPool;
use Swoole\Coroutine as SWCoroutine;
use Swoole\Timer as SWTimer;

class Worker{

    public string $register_ip='127.0.0.1';
    public int $register_port=1236;
    public string $secret_key='';
    private array $config;
    private SWProcessPool $server;
    private array $client_register;
    private array $client_gateway;
    public function __construct($worker_num=1){
        $this->server = new SWProcessPool($worker_num);
        $this->config=[
            'pid_file' => __DIR__.'/server_worker.pid',

            'daemonize' => false,
            'event_object' => true,
            'task_object' => true,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'task_enable_coroutine' => true,
        ];
        $this->server->set($this->config);
        $this->server->on('WorkerStart',array($this, 'onWorkerStart'));
        $this->server->on('WorkerStop',array($this, 'onWorkerStop'));
    }

    public function set($config){
        $this->config=array_merge($this->config,$config);
        $this->server->set($this->config);
    }

    private function onWorkerStart(SWProcessPool $pool, int $worker_id){
        echo "[Worker #{$worker_id}] WorkerStart".PHP_EOL;

        //连接到注册中心
        $this->connectToRegister($worker_id);
    }
    private function onWorkerStop(SWProcessPool $pool, int $worker_id){
        echo "[Worker #{$worker_id}] WorkerStop".PHP_EOL;
        $pool->shutdown();
    }

    private function connectToRegister($worker_id){
        $this->client_register[$worker_id] = new SWCClient(SWOOLE_SOCK_TCP);
        if (!$this->client_register[$worker_id]->connect($this->register_ip, $this->register_port, 0.5)) {
            echo "connect failed. Error: {$this->client_register[$worker_id]->errCode}\n";
        }
        $worker_connect_data=[
            "cmd"=>GatewayProtocol::CMD_WORKER_CONNECT,
            "body"=>[
                "secret_key"=>$this->secret_key
            ]
        ];
        $buffer=GatewayProtocol::encode($worker_connect_data);
        $this->client_register[$worker_id]->send($buffer);
        $this->client_register[$worker_id]->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use($worker_id) {
            echo "ping to register timer_id #$timer_id, after 3000ms.".PHP_EOL;
            $ping_to_register = array(
                'cmd' => GatewayProtocol::CMD_PING,
                'body'=>array()
            );
            $buffer = GatewayProtocol::encode($ping_to_register);
            $this->client_register[$worker_id]->send($buffer);
        });
        while (true) {
            $buffer = $this->client_register[$worker_id]->recv();
            if ($buffer === false) {
                // 可以自行根据业务逻辑和错误码进行处理，例如：
                // 如果超时时则不关闭连接，其他情况直接关闭连接
                if ($this->client_register[$worker_id]->errCode !== SOCKET_ETIMEDOUT) {
                    echo "[Worker #{$worker_id}] register connection closed".PHP_EOL;
                    SWTimer::clear($this->client_register[$worker_id]->ping_timer_id);
                    $this->client_register[$worker_id]->close();
                    break;
                }
            }else if($buffer===''){
                echo "connect failed. Error: {$this->client_register[$worker_id]->errCode}".PHP_EOL;
                SWTimer::clear($this->client_register[$worker_id]->ping_timer_id);
                $this->client_register[$worker_id]->close();
                break;
            }else{
                $data=GatewayProtocol::decode($buffer);
                $cmd=$data['cmd'];
                $body=$data['body'];
                $this->dispatchRegisterCMD($cmd,$body);
            }
            SWCoroutine::sleep(1);
        }
    }

    private function dispatchRegisterCMD($cmd,$body){
        switch ($cmd){
            // 是 gateway addresses
            case GatewayProtocol::CMD_BROADCAST_ADDRESSES:
                $this->connectToGateway($body['addresses']);
                break;
            case GatewayProtocol::CMD_PONG:
                //pong
                break;
        }
    }

    private function connectToGateway($address_list){
        foreach ($address_list as $address) {
            if(isset($this->client_gateway[$address])){
                continue;
            }
            //链接到gateway
            $address_arr=explode(':',$address);
            $ip=$address_arr[0];
            $port=$address_arr[1];
            $this->client_gateway[$address] = new SWCClient(SWOOLE_SOCK_TCP);
            if (!$this->client_gateway[$address]->connect($ip, $port, 0.5)) {
                echo "connect failed. Error: {$this->client_gateway[$address]->errCode}\n";
                unset($this->client_gateway[$address]);
            }
            $worker_connect_data=[
                "cmd"=>GatewayProtocol::CMD_WORKER_CONNECT,
                "body"=>[
                    "secret_key"=>$this->secret_key
                ]
            ];
            $buffer=GatewayProtocol::encode($worker_connect_data);
            $this->client_gateway[$address]->send($buffer);
            $this->client_gateway[$address]->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use($address) {
                echo "ping to gateway timer_id #$timer_id, after 3000ms.".PHP_EOL;
                $ping_to_gateway = array(
                    'cmd' => GatewayProtocol::CMD_PING,
                    'body'=>array()
                );
                $buffer = GatewayProtocol::encode($ping_to_gateway);
                $this->client_gateway[$address]->send($buffer);
            });
            while (true) {
                $buffer = $this->client_gateway[$address]->recv();
                if ($buffer === false) {
                    // 可以自行根据业务逻辑和错误码进行处理，例如：
                    // 如果超时时则不关闭连接，其他情况直接关闭连接
                    if ($this->client_gateway[$address]->errCode !== SOCKET_ETIMEDOUT) {
                        SWTimer::clear($this->client_gateway[$address]->ping_timer_id);
                        $this->client_gateway[$address]->close();
                        unset($this->client_gateway[$address]);
                        break;
                    }
                }else if($buffer===''){
                    SWTimer::clear($this->client_gateway[$address]->ping_timer_id);
                    $this->client_gateway[$address]->close();
                    unset($this->client_gateway[$address]);
                    break;
                }else {
                    $data = GatewayProtocol::decode($buffer);
                    $cmd = $data['cmd'];
                    $body = $data['body'];
                    $this->dispatchGatewayCMD($cmd,$body);
                }
            }
        }
    }

    private function dispatchGatewayCMD($cmd,$body){
        switch ($cmd){
            case GatewayProtocol::CMD_ON_CONNECT:
                //CMD_ON_CONNECT
                break;
            case GatewayProtocol::CMD_ON_MESSAGE:
                //pong
                break;
            case GatewayProtocol::CMD_ON_CLOSE:
                //CMD_ON_CLOSE
                break;
            case GatewayProtocol::CMD_ON_WEBSOCKET_CONNECT:
                //CMD_ON_WEBSOCKET_CONNECT
                break;
            case GatewayProtocol::CMD_PONG:
                //CMD_PONG
                echo 12;
                break;
        }
    }

    public function start(){
        return $this->server->start();
    }
}

