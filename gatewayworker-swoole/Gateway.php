<?php
/**
 * worker
 */
namespace GatewayWorker\Swoole;
use Swoole\Coroutine\Client as SWCClient;
use Swoole\Process\Pool as SWProcessPool;
use Swoole\Coroutine as SWCoroutine;
use Swoole\Server as SWServer;
use Swoole\Timer as SWTimer;
use function Swoole\Coroutine\go as SWCgo;

class Worker{

    public string $register_ip='127.0.0.1';
    public int $register_port=1236;
    public string $secret_key='';
    private array $config;
    private ?SWServer $server=null;

    /**
     * 结构初始化
     * @param string $ip
     * @param int $port
     */
    public function __construct(string $ip='127.0.0.1',int $port=7272){
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
            'pid_file' => __DIR__.'/server_gateway.pid',

            'daemonize' => false,
            'event_object' => true,
            'task_object' => true,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'task_enable_coroutine' => true
        ];
        $this->createTcpServer($ip, $port);
    }

    /**
     * 创建tcp服务
     * @param string $ip
     * @param int $port
     */
    private function createTCPServer(string $ip, int $port){
        $this->server = new SWServer($ip, $port);
        $this->server->set($this->config);
        $this->server->on('Connect',function ($server,$event){
            $this->onConnect($server, $event);
        });
        $this->server->on('Receive',function ($server,$event){
            $this->onReceive($server, $event);
        });
        $this->server->on('Close',function ($server,$event){
            $this->onClose($server, $event);
        });
    }

    /**
     * 设置配置文件
     * @param array $config
     */
    public function set(array $config){
        $this->config=array_merge($this->config,$config);
        $this->server->set($this->config);
    }

    /**
     * 进程启动后触发的事件
     * @param SWProcessPool $pool
     * @param int $worker_id
     */
    private function onWorkerStart(SWProcessPool $pool, int $worker_id){
        echo "[Worker #{$worker_id}] WorkerStart".PHP_EOL;

        //连接到注册中心
        $this->connectToRegister();
    }

    /**
     * 进程关闭后触发的事件
     * @param SWProcessPool $pool
     * @param int $worker_id
     */
    private function onWorkerStop(SWProcessPool $pool, int $worker_id){
        echo "[Worker #{$worker_id}] WorkerStop".PHP_EOL;
        $pool->shutdown();
    }

    /**
     * 连接到注册中心，通过tcp连接
     */
    private function connectToRegister(){
        $client = new SWCClient(SWOOLE_SOCK_TCP);
        $client->ping_timer_id=0;
        $connect=$client->connect($this->register_ip, $this->register_port, 0.5);
        if (!$connect) {
            $this->onRegisterClose($client);
        }else{
            SWCgo(function () use(&$client){
                while (true) {
                    $buffer = $client->recv();
                    if ($buffer === false) {
                        // 可以自行根据业务逻辑和错误码进行处理，例如：
                        // 如果超时时则不关闭连接，其他情况直接关闭连接
                        if ($client->errCode !== SOCKET_ETIMEDOUT) {
                            $this->onRegisterClose($client);
                            break;
                        }
                    }else if($buffer===''){
                        $this->onRegisterClose($client);
                        break;
                    }else{
                        $this->onRegisterReceive($client,$buffer);
                    }
                    SWCoroutine::sleep(1);
                }
            });
            $this->onRegisterConnect($client);
        }
    }

    private function onRegisterConnect(SWCClient &$client){
        //心跳定时器，发送ping 包
        $client->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use(&$client) {
            $ping_data = array(
                'cmd' => GatewayProtocol::CMD_PING,
                'body'=>array()
            );
            $buffer = GatewayProtocol::encode($ping_data);
            $client->send($buffer);
        });

        //发送初始连接数据，用于校验身份
        $connect_data=[
            "cmd"=>GatewayProtocol::CMD_WORKER_CONNECT,
            "body"=>[
                "secret_key"=>$this->secret_key_register
            ]
        ];
        $buffer=GatewayProtocol::encode($connect_data);
        $client->send($buffer);
    }
    private function onRegisterReceive(SWCClient &$client,string $buffer){
        $data=GatewayProtocol::decode($buffer);
        $cmd=$data['cmd'];
        $body=$data['body'];
        switch ($cmd){
            // 是 gateway addresses
            case GatewayProtocol::CMD_BROADCAST_ADDRESSES:
                $this->connectToGatewayList($body['addresses']);
                break;
            case GatewayProtocol::CMD_PONG:
                //pong
                break;
        }
    }

    private function onRegisterClose(SWCClient &$client){
        echo 'connect register failed.'.PHP_EOL;
        SWTimer::clear($client->ping_timer_id);
        $client->close();
        unset($client);
        SWTimer::after(3000,function (){
            $this->connectToRegister();
        });
    }

    private function connectToGatewayList($address_list){
        foreach ($address_list as $address) {
            if(!isset($this->connect_gateway[$address])){
                $this->connect_gateway[$address]=0;
                $this->connectToGateway($address);
            }
        }
    }

    private function connectToGateway(string $address){
        //链接到gateway
        $address_arr=explode(':',$address);
        $ip=$address_arr[0];
        $port=$address_arr[1];
        $client = new SWCClient(SWOOLE_SOCK_TCP);
        $client->ping_timer_id=0;
        $connect=$client->connect($ip, $port, 0.5);
        if (!$connect) {
            $this->onGatewayClose($client);
            unset($this->connect_gateway[$address]);
        }else{
            SWCgo(function () use(&$client,$address){
                while (true) {
                    $buffer = $client->recv();
                    if ($buffer === false) {
                        // 可以自行根据业务逻辑和错误码进行处理，例如：
                        // 如果超时时则不关闭连接，其他情况直接关闭连接
                        if ($client->errCode !== SOCKET_ETIMEDOUT) {
                            $this->onGatewayClose($client);
                            unset($this->connect_gateway[$address]);
                            break;
                        }
                    }else if($buffer===''){
                        $this->onGatewayClose($client);
                        unset($this->connect_gateway[$address]);
                        break;
                    }else{
                        $this->onGatewayReceive($client,$buffer);
                    }
                    SWCoroutine::sleep(1);
                }
            });
            $this->onGatewayConnect($client);
            $this->connect_gateway[$address]=1;
        }
    }

    private function onGatewayConnect(SWCClient &$client){
        //心跳定时器，发送ping 包
        $client->ping_timer_id=SWTimer::tick(3000, function (int $timer_id) use(&$client) {
            echo "ping to gateway.".PHP_EOL;
            $ping_data = array(
                'cmd' => GatewayProtocol::CMD_PING,
                'body'=>array()
            );
            $buffer = GatewayProtocol::encode($ping_data);
            $client->send($buffer);
        });

        //发送初始连接数据，用于校验身份
        $connect_data=[
            "cmd"=>GatewayProtocol::CMD_WORKER_CONNECT,
            "body"=>[
                "secret_key"=>$this->secret_key_gateway
            ]
        ];
        $buffer=GatewayProtocol::encode($connect_data);
        $client->send($buffer);
    }
    private function onGatewayReceive(SWCClient &$client,string $buffer){
        $data=GatewayProtocol::decode($buffer);
        $cmd=$data['cmd'];
        $body=$data['body'];
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
    private function onGatewayClose(SWCClient &$client){
        echo 'connect gateway failed.'.PHP_EOL;
        SWTimer::clear($client->ping_timer_id);
        $client->close();
        unset($client);
    }

    public function start(){
        return $this->server->start();
    }
}

