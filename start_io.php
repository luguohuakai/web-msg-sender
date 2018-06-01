<?php

use Endroid\QrCode\QrCode;
use Workerman\Worker;
use Workerman\WebServer;
use Workerman\Lib\Timer;
use PHPSocketIO\SocketIO;

include __DIR__ . '/vendor/autoload.php';


//不同环境下获取真实的IP
function getRealClientIp()
{
    //判断服务器是否允许$_SERVER
    if (isset($_SERVER)) {
        if (isset($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $realip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (isset($_SERVER['HTTP_CLIENT_IP'])) {
            $realip = $_SERVER['HTTP_CLIENT_IP'];
        } else {
            $realip = $_SERVER['REMOTE_ADDR'];
        }
    } else {
        //不允许就使用getenv获取
        if (getenv("HTTP_X_FORWARDED_FOR")) {
            $realip = getenv("HTTP_X_FORWARDED_FOR");
        } elseif (getenv("HTTP_CLIENT_IP")) {
            $realip = getenv("HTTP_CLIENT_IP");
        } else {
            $realip = getenv("REMOTE_ADDR");
        }
    }

    return $realip;
}

// 全局数组保存uid在线数据
$uidConnectionMap = array();
// 记录最后一次广播的在线用户数
$last_online_count = 0;
// 记录最后一次广播的在线页面数
$last_online_page_count = 0;

// PHPSocketIO服务
$sender_io = new SocketIO(2120);
// 客户端发起连接事件时，设置连接socket的各种事件回调
$sender_io->on('connection', function ($socket) {
    // 当客户端发来登录事件时触发
    $socket->on('login', function ($uid) use ($socket) {
        global $uidConnectionMap, $last_online_count, $last_online_page_count;
        // 已经登录过了
        if (isset($socket->uid)) {
            return;
        }
        // 更新对应uid的在线数据
        $uid = (string)$uid;
        if (!isset($uidConnectionMap[$uid])) {
            $uidConnectionMap[$uid] = 0;
        }
        // 这个uid有++$uidConnectionMap[$uid]个socket连接
        ++$uidConnectionMap[$uid];
        // 将这个连接加入到uid分组，方便针对uid推送数据
        $socket->join($uid);
        $socket->uid = $uid;
        // 更新这个socket对应页面的在线数据
        $socket->emit('update_online_count', "当前<b>{$last_online_count}</b>人在线，共打开<b>{$last_online_page_count}</b>个页面");
    });

    // 返回客户端ip
    $socket->on('get_ip', function () use ($socket) {
        $ip = getRealClientIp();
        $socket->emit('get_ip_from_server', $ip);
    });

    // 当发来获取二维码事件时
    $socket->on('qr_code_generator', function ($msg) use ($socket) {
        // 触发所有客户端定义的 qr_code_generator_from_server 事件
        // 使用 $msg 生成二维码
        $qrCode = new QrCode();
        $qrCode
            ->setText($msg)
            ->setSize(300)
            ->setPadding(10)
            ->setErrorCorrection('high')
            ->setForegroundColor(['r' => 0, 'g' => 0, 'b' => 0, 'a' => 0])
            ->setBackgroundColor(['r' => 255, 'g' => 255, 'b' => 255, 'a' => 0])
            ->setLabel('Scan the code')
            ->setLabelFontSize(16)
            ->setImageType(QrCode::IMAGE_TYPE_PNG);

        // now we can directly output the qrcode
//        header('Content-Type: '.$qrCode->getContentType());
//        $qrCode->render();

        // save it to a file
//        $qrCode->save('qrcode.png');

        // or create a response object
//        $response = new Response($qrCode->get(), 200, ['Content-Type' => $qrCode->getContentType()]);

        $socket->emit('qr_code_generator_from_server', $qrCode->get());
    });

    // 当客户端断开连接时触发（一般是关闭网页或者跳转刷新导致）
    $socket->on('disconnect', function () use ($socket) {
        if (!isset($socket->uid)) {
            return;
        }
        global $uidConnectionMap, $sender_io;
        // 将uid的在线socket数减一
        if (--$uidConnectionMap[$socket->uid] <= 0) {
            unset($uidConnectionMap[$socket->uid]);
        }
    });
});

// 当$sender_io启动后监听一个http端口，通过这个端口可以给任意uid或者所有uid推送数据
$sender_io->on('workerStart', function () {
    // 监听一个http端口
    $inner_http_worker = new Worker('http://0.0.0.0:2121');
    // 当http客户端发来数据时触发
    $inner_http_worker->onMessage = function ($http_connection, $data) {
        global $uidConnectionMap;
        $_POST = $_POST ? $_POST : $_GET;
        global $sender_io;
        $type = @$_POST['type'];
        $to = @$_POST['to'];
        $content = htmlspecialchars(@$_POST['content']);
        switch ($type) {
            // 推送数据的url格式 type=publish&to=uid&content=xxxx
            case 'publish':
                // 有指定uid则向uid所在socket组发送数据
                if ($to) {
                    $sender_io->to($to)->emit('new_msg', $content);
                    // 否则向所有uid推送数据
                } else {
                    $sender_io->emit('new_msg', $content);
                }
                break;
            // 推送数据的url格式 type=qrcode_auth_success&to=uid&content=xxxx
            case 'qrcode_auth_success':
                if ($to) {
                    $sender_io->to($to)->emit('qrcode_auth_success', $content);
                } else {
                    return $http_connection->send('params_error');
                }
                break;
            default:
                return $http_connection->send('send_fail');

        }
        // http接口返回，如果用户离线socket返回fail
        if ($to && !isset($uidConnectionMap[$to])) {
            return $http_connection->send('you_are_offline');
        } else {
            return $http_connection->send('send_ok');
        }
    };
    // 执行监听
    $inner_http_worker->listen();

    // 一个定时器，定时向所有uid推送当前uid在线数及在线页面数
    Timer::add(1, function () {
        global $uidConnectionMap, $sender_io, $last_online_count, $last_online_page_count;
        $online_count_now = count($uidConnectionMap);
        $online_page_count_now = array_sum($uidConnectionMap);
        // 只有在客户端在线数变化了才广播，减少不必要的客户端通讯
        if ($last_online_count != $online_count_now || $last_online_page_count != $online_page_count_now) {
            $sender_io->emit('update_online_count', "当前<b>{$online_count_now}</b>人在线，共打开<b>{$online_page_count_now}</b>个页面");
            $last_online_count = $online_count_now;
            $last_online_page_count = $online_page_count_now;
        }
    });
});

if (!defined('GLOBAL_START')) {
    Worker::runAll();
}
