<?php

$client = new Swoole\Client(SWOOLE_SOCK_TCP);
if (! $client->connect('127.0.0.1', 8082, 3)) {
	exit("connect failed. error: {$client->errCode}\n");
}
$data = file_get_contents("1.data");
$client->send($data);
echo $client->recv();
$client->close();
