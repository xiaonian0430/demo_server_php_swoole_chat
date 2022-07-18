<?php
namespace GatewayWorker\Protocols;

/**
 * Gateway 与 Worker 间通讯的二进制协议
 *
 * struct GatewayProtocol
 * {
 *     unsigned int pack_len,
 *     unsigned short cmd,//命令字
 *     char[pack_length-HEAD_LEN] data//包体
 * }
 * Nn
 */
class GatewayProtocol
{
    // 发给worker，gateway有一个新的连接
    const CMD_ON_CONNECT = 1;

    // 发给worker的，客户端有消息
    const CMD_ON_MESSAGE = 3;

    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 4;

    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 5;

    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 6;

    // 发给gateway的踢出用户
    // 1、如果有待发消息，将在发送完后立即销毁用户连接
    // 2、如果无待发消息，将立即销毁用户连接
    const CMD_KICK = 7;

    // 发给gateway的立即销毁用户连接
    const CMD_DESTROY = 8;

    // 发给gateway，通知用户session更新
    const CMD_UPDATE_SESSION = 9;

    // 获取在线状态
    const CMD_GET_ALL_CLIENT_SESSIONS = 10;

    // 判断是否在线
    const CMD_IS_ONLINE = 11;

    // client_id绑定到uid
    const CMD_BIND_UID = 12;

    // 解绑
    const CMD_UNBIND_UID = 13;

    // 向uid发送数据
    const CMD_SEND_TO_UID = 14;

    // 根据uid获取绑定的clientid
    const CMD_GET_CLIENT_ID_BY_UID = 15;

    // 加入组
    const CMD_JOIN_GROUP = 20;

    // 离开组
    const CMD_LEAVE_GROUP = 21;

    // 向组成员发消息
    const CMD_SEND_TO_GROUP = 22;

    // 获取组成员
    const CMD_GET_CLIENT_SESSIONS_BY_GROUP = 23;

    // 获取组在线连接数
    const CMD_GET_CLIENT_COUNT_BY_GROUP = 24;

    // 按照条件查找
    const CMD_SELECT = 25;

    // 获取在线的群组ID
    const CMD_GET_GROUP_ID_LIST = 26;

    // 取消分组
    const CMD_UNGROUP = 27;

    // worker连接gateway事件
    const CMD_WORKER_CONNECT = 200;

    // 心跳
    const CMD_PING = 201;

    // GatewayClient连接gateway事件
    const CMD_GATEWAY_CLIENT_CONNECT = 202;

    // 根据client_id获取session
    const CMD_GET_SESSION_BY_CLIENT_ID = 203;

    // 发给gateway，覆盖session
    const CMD_SET_SESSION = 204;

    // 当websocket握手时触发，只有websocket协议支持此命令字
    const CMD_ON_WEBSOCKET_CONNECT = 205;

    const CMD_GATEWAY_CONNECT=206;
    const CMD_BROADCAST_ADDRESSES=207;
    const CMD_PONG=208;

    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;

    // 通知gateway在send时不调用协议encode方法，在广播组播时提升性能
    const FLAG_NOT_CALL_ENCODE = 0x02;

    /**
     * 包头长度
     *
     * @var int
     */
    const HEAD_LEN = 6;

    /**
     * 空包
     *
     * @var array
     */
    public static $empty = array(
        'cmd' => 0,
        'body' => array()
    );

    /**
     * 返回包长度
     *
     * @param string $buffer
     * @return int return current package length
     */
    public static function package_len(string $buffer): int {
        //长度数据不足，需要接收更多数据
        $buffer_len=strlen($buffer);
        if ($buffer_len < self::HEAD_LEN) {
            return 0;
        }

        //底层会自动将包拼好后返回给回调函数
        $data = unpack("Npackage_len", $buffer);
        return $data['package_len'];
    }

    /**
     * 获取整个包的 buffer
     *
     * @param array $data
     * @return string
     */
    public static function encode(array $data): string
    {
        $cmd=$data['cmd'];
        $body=serialize($data['body']);
        $package_len  = self::HEAD_LEN + strlen($body);
        $pack=pack("Nn",$package_len,$cmd);
        return $pack.$body;
    }

    /**
     * 从二进制数据转换为数组
     *
     * @param string $buffer
     * @return array
     */
    public static function decode(string $buffer): array
    {
        $data = unpack("Npackage_len/ncmd",$buffer);
        $body = substr($buffer, self::HEAD_LEN);
        $data['body'] = unserialize($body);
        unset($data['package_len']);
        return $data;
    }
}