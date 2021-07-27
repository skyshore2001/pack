<?php
$port = $argv[1] ?: 14001;
$file = $argv[2] ?: "toport.bin";

$client = new Swoole\Client(SWOOLE_SOCK_TCP);
if (! $client->connect('127.0.0.1', $port, 3)) {
	exit("connect failed. error: {$client->errCode}\n");
}
$data = file_get_contents($file);
$client->send($data);
echo $client->recv();
$client->close();
