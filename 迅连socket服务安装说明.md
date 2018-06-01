迅连socket服务
==============

安装
======
复制安装包到 /srun3/www 目录下解压

后端服务启动停止(在安装目录下执行)
======
### 启动服务
php start.php start -d
### 停止服务
php start.php stop
### 服务状态
php start.php status


前端代码类似：
====
```javascript
// 引入前端文件
<script src='//cdn.bootcss.com/socket.io/1.3.7/socket.io.js'></script>
<script>
// 初始化io对象
var socket = io('http://'+document.domain+':2120');
// uid 可以为网站用户的uid，作为例子这里用session_id代替
var uid = '<?php echo session_id();?>';
// 当socket连接后发送登录请求
socket.on('connect', function(){socket.emit('login', uid);});
// 当服务端推送来消息时触发，这里简单的aler出来，用户可做成自己的展示效果
socket.on('new_msg', function(msg){alert(msg);});
</script>
```

后端调用api向任意用户推送数据
====
```php
<?php
// 指明给谁推送，为空表示向所有在线用户推送
$to_uid = '';
// 推送的url地址，上线时改成自己的服务器地址
$push_api_url = "http://workerman.net:2121/";
$post_data = array(
   'type' => 'publish',
   'content' => '这个是推送的测试数据',
   'to' => $to_uid, 
);
$ch = curl_init ();
curl_setopt ( $ch, CURLOPT_URL, $push_api_url );
curl_setopt ( $ch, CURLOPT_POST, 1 );
curl_setopt ( $ch, CURLOPT_HEADER, 0 );
curl_setopt ( $ch, CURLOPT_RETURNTRANSFER, 1 );
curl_setopt ( $ch, CURLOPT_POSTFIELDS, $post_data );
$return = curl_exec ( $ch );
curl_close ( $ch );
var_export($return);
```

常见问题：
====
如果通信不成功检查防火墙   
/sbin/iptables -I INPUT -p tcp --dport 2120 -j ACCEPT   
/sbin/iptables -I INPUT -p tcp --dport 2121 -j ACCEPT   
/sbin/iptables -I INPUT -p tcp --dport 2123 -j ACCEPT    
