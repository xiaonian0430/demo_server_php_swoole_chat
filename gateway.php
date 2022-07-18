<?php
declare(strict_types=1);
require_once __DIR__.'/Protocols/GatewayProtocol.php';
use Swoole\Server as SWServer;
use Swoole\WebSocket\Server as SWWSServer;
use Swoole\WebSocket\Frame as SWWSFrame;
use Swoole\Timer as SWTimer;
use GatewayWorker\Protocols\GatewayProtocol;

/**
 * 进程启动时间
 *
 * @var int
 */
$_start_time = 0;
/**
 * 本机 IP
 *  单机部署默认 127.0.0.1，如果是分布式部署，需要设置成本机 IP
 *
 * @var string
 */
$lan_ip = '127.0.0.1';

/**
 * 本机端口
 *
 * @var string
 */
$lan_port = 0;
/**
 * gateway 内部通讯起始端口，每个 gateway 实例应该都不同，步长1000
 *
 * @var int
 */
$start_port = 2000;
/**
 * 心跳时间间隔
 *
 * @var int
 */
$ping_interval = 3;

/**
 * $ping_not_responseLimit * $ping_interval 时间内，客户端未发送任何数据，断开客户端连接
 *
 * @var int
 */
$ping_not_responseLimit = 60;

/**
 * 服务端向客户端发送的心跳数据
 *
 * @var string
 */
$ping_data = '';

/**
 * gateway 内部监听 worker 内部连接的 worker
 *
 * @var Worker
 */
protected $_innerTcpWorker = null;

$server = new SWWSServer('0.0.0.0', 7272);
$server->set([
    'open_websocket_protocol' => true,
    'open_websocket_close_frame' => false,
    'open_http_protocol' => true,
    'heartbeat_idle_time' => 60,
    'heartbeat_check_interval' => 3,
    'open_length_check' => true,
    'package_length_type' => 'N',
    'package_length_offset' => 0,
    'package_body_offset' => 0,
    'worker_num'=>1,
    'buffer_output_size' => 32 * 1024 * 1024, //最大允许发送 32M 字节的数据
    'pid_file' => __DIR__.'/server_gateway.pid',

    'daemonize' => false,
    'event_object' => true,
    'task_object' => true,
    'reload_async' => true,
    'max_wait_time' => 60,
    'enable_coroutine' => true,
    'task_enable_coroutine' => true,
]);

/**
 * 当 Gateway 启动的时候触发的回调函数
 */
$server->on('WorkerStart', function (SWServer $server,int $worker_id){
    global $start_port;
    global $lan_port;
    global $lan_ip;
    global $ping_interval;
    global $ping_not_responseLimit;
    global $_innerTcpWorker;
    // 分配一个内部通讯端口
    $lan_port = $start_port + $worker_id;

    // 如果有设置心跳，则定时执行
    if ($this->pingInterval > 0) {
        $timer_interval = $ping_not_responseLimit > 0 ? $ping_interval / 2 : $ping_interval;
        SWTimer::tick($timer_interval*1000, function (){
            global $ping_data;
            $ping_data_temp = $ping_data ? (string)$ping_data : null;
            $raw = false;

            // 遍历所有客户端连接
            foreach ($_clientConnections as $connection) {
                // 上次发送的心跳还没有回复次数大于限定值就断开
                if ($this->pingNotResponseLimit > 0 &&
                    $connection->pingNotResponseCount >= $this->pingNotResponseLimit * 2
                ) {
                    $connection->destroy();
                    continue;
                }
                // $connection->pingNotResponseCount 为 -1 说明最近客户端有发来消息，则不给客户端发送心跳
                $connection->pingNotResponseCount++;
                if ($ping_data_temp) {
                    if ($connection->pingNotResponseCount === 0 ||
                        ($this->pingNotResponseLimit > 0 && $connection->pingNotResponseCount % 2 === 1)
                    ) {
                        continue;
                    }
                    $connection->send($ping_data_temp, $raw);
                }
            }
        });
    }

    // 如果Worker ip不是127.0.0.1，则需要加gateway到Worker的心跳
    if ($lan_ip !== '127.0.0.1') {
        SWTimer::tick(3000, function (){
            $gateway_data = GatewayProtocol::$empty;
            $gateway_data['cmd'] = GatewayProtocol::CMD_PING;
            foreach ($_workerConnections as $connection) {
                $connection->send($gateway_data);
            }
        });
    }

    if (!class_exists('\Protocols\GatewayProtocol')) {
        class_alias('GatewayWorker\Protocols\GatewayProtocol', 'Protocols\GatewayProtocol');
    }

    //如为公网IP监听，直接换成0.0.0.0 ，否则用内网IP
    $listen_ip=filter_var($lan_ip,FILTER_VALIDATE_IP,FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)?'0.0.0.0':$lan_ip;
    // 初始化 gateway 内部的监听，用于监听 worker 的连接已经连接上发来的数据
    $_innerTcpWorker = new Worker("GatewayProtocol://{$listen_ip}:{$this->lanPort}");
    $_innerTcpWorker->reusePort = false;
    $_innerTcpWorker->listen();
    $_innerTcpWorker->name = 'GatewayInnerWorker';

    // 重新设置自动加载根目录
    Autoloader::setRootPath($this->_autoloadRootPath);

    // 设置内部监听的相关回调
    $this->_innerTcpWorker->onMessage = array($this, 'onWorkerMessage');

    $this->_innerTcpWorker->onConnect = array($this, 'onWorkerConnect');
    $this->_innerTcpWorker->onClose   = array($this, 'onWorkerClose');

    // 注册 gateway 的内部通讯地址，worker 去连这个地址，以便 gateway 与 worker 之间建立起 TCP 长连接
    $this->registerAddress();

    if ($this->_onWorkerStart) {
        call_user_func($this->_onWorkerStart, $this);
    }
});

//监听WebSocket连接打开事件
$server->on('Open', function ($ws, $request) {
    $ws->push($request->fd, "hello, welcome\n");
});

/**
 * 当客户端发来数据时，转发给 worker 处理
 */
$server->on('Message', function (SWWSServer $server, SWWSFrame $frame) {
    $server->pingNotResponseCount = -1;
    sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $server, $frame);
});

//监听WebSocket连接关闭事件
$server->on('Close', function ($ws, $fd) {
    echo "client-{$fd} is closed\n";
});

$server->start();
$_start_time=time();

/**
 * 发送数据给 worker 进程
 */
function sendToWorker(int $cmd, SWWSServer $server, SWWSFrame $frame)
{
    global $_start_time;
    $gateway_data             = $connection->gatewayHeader;
    $gateway_data['cmd']      = $cmd;
    $gateway_data['body']     = $body;
    $gateway_data['ext_data'] = $connection->session;
    if ($this->_workerConnections) {
        // 调用路由函数，选择一个worker把请求转发给它
        /** @var TcpConnection $worker_connection */
        $worker_connection = call_user_func($this->router, $this->_workerConnections, $connection, $cmd, $body);
        if (false === $worker_connection->send($gateway_data)) {
            $msg = "SendBufferToWorker fail. May be the send buffer are overflow. See http://doc2.workerman.net/send-buffer-overflow.html";
            static::log($msg);
            return false;
        }
    } else {
        // 没有可用的 worker
        // gateway 启动后 1-2 秒内 SendBufferToWorker fail 是正常现象，因为与 worker 的连接还没建立起来，
        // 所以不记录日志，只是关闭连接
        $time_diff = 2;
        if (time() - $_start_time >= $time_diff) {
            $msg = 'SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready. See http://doc2.workerman.net/send-buffer-to-worker-fail.html';
            //static::log($msg);
            echo $msg.PHP_EOL;
        }
        $server->disconnect($frame->fd,SWOOLE_WEBSOCKET_CLOSE_NORMAL,'');
        return false;
    }
    return true;
}
