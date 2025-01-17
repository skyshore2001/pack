<?php

//require_once('../php/autoload.php');
$GLOBALS["noExecApi"] = true;
//require("../api.php");
//require_once("../php/jdcloud-php/api_fw.php");
require_once("./jdcloud-php/common.php");
require_once("./pack.php");

$workerNum = 1;

$g_conf = [
	"httpPort" => 14000,
	"tcpPort" => 14001,
	"targetHttp" => [
		"host" => "localhost",
		"port" => 80,
		"url" => "/wis/api/Wis.wcsCallback"
	],
	"targetTcp" => [
		"host" => "wcs",
		"port" => 2000,
	]
/* for test
	"targetHttp" => [
		"host" => "oliveche.com",
		"port" => 80,
		"url" => "/echo.php"
	],
	"targetTcp" => [
		"host" => "localhost",
		"port" => 14002,
	]
*/
];

#$server = new Swoole\WebSocket\Server("0.0.0.0", $port);
#$server = new Swoole\Server("0.0.0.0", $port);
$server = new Swoole\Http\Server("0.0.0.0", $g_conf["httpPort"]);
$server->set([
	'worker_num'=>$workerNum,
]);
logit("=== server: http port=" . $g_conf["httpPort"] . ", tcp port=" . $g_conf["tcpPort"] . ", workerCnt=$workerNum");

$port1 = $server->listen('0.0.0.0', $g_conf["tcpPort"], SWOOLE_SOCK_TCP);
$port1->set([]);
$port1->on("Receive", 'onReceive');

$server->on('WorkerStart', function ($server, $workerId) {
	echo("=== worker $workerId starts. master_pid={$server->master_pid}, manager_pid={$server->manager_pid}, worker_pid={$server->worker_pid}\n");
});

$port1->on("Receive", 'onReceive');

function onReceive($server, $fd, $reactorId, $data) {
	logit("receive tcp data $data");
	try {
		$p = TBase::bin2json($data);
		$json = jsonEncode($p);
		logit("decode $packClass: $json");

		$conf = $GLOBALS["g_conf"]["targetHttp"];
		if ($conf["host"]) {
			$cli = new Swoole\Coroutine\Http\Client($conf["host"], $conf["port"]);
			$cli->setHeaders([
				"Content-Type" => "application/json"
			]);
			$cli->post($conf["url"], $json);
			logit("send http to " . $conf["host"] . ":" . $conf["port"] . ", recv " . $cli->body);
			$cli->close();
		}

		$ret = "S:OK";
	}
	catch (Exception $e) {
		$ret = "F:" . $e->getMessage();
		logit("handle tcp fail: " . $e->getMessage());
	}
	$server->send($fd, $ret);
	$server->close($fd);
}
$server->on('request', 'handleRequest');

function handleRequest($req, $res)
{
	global $ERRINFO;
	$ok = false;
	$ret = null;
	try {
		$ct = $req->header["content-type"];
		$reqData = $req->rawContent();
		logit("receive http data (content-type=$ct): $reqData");
		if ($ct && stripos($ct, 'json') !== false) {
			$req->post = jsonDecode($reqData);
		}
		$p = $req->post;//"OK";
		$data = TBase::json2bin($p);
		// file_put_contents("1.data", $data);

		// tcp send
		$conf = $GLOBALS["g_conf"]["targetTcp"];
		if ($conf["host"]) {
			$cli = new Swoole\Coroutine\Client(SWOOLE_SOCK_TCP);
			if (! $cli->connect($conf["host"], $conf["port"], 3))
				jdRet(E_SERVER, "tcp connect fails");
			$cli->send($data);
			//$rv = $cli->recv();
			logit("send tcp to " . $conf["host"] . ":" . $conf["port"] . ", recv " . $rv);
			$cli->close();
		}

		$ret = [0, "OK"];
		$ok = true;
	}
	catch (DirectReturn $e) {
		$ret = [0, null];
		$ok = true;
	}
	catch (MyException $e) {
		$ret = [$e->getCode(), $e->getMessage(), $e->internalMsg];
	}
	catch (PDOException $e) {
		$ret = [E_DB, $ERRINFO[E_DB], $e->getMessage()];
	}
	catch (Exception $e) {
		$ret = [E_SERVER, $ERRINFO[E_SERVER], $e->getMessage()];
		logit("handle http fail: " . $e->getMessage());
	}

	$retStr = jsonEncode($ret);
	$res->end($retStr);
}

/*
swoole_timer_after(4000, function () {
	logit("4000");
});
*/
$server->start();


