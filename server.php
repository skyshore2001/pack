<?php

//require_once('../php/autoload.php');
$GLOBALS["noExecApi"] = true;
//require("../api.php");
//require_once("../php/jdcloud-php/api_fw.php");
require_once("./jdcloud-php/app_fw.php");
require_once("./pack.php");

$port = 8081;
$workerNum = 1;

#$server = new Swoole\WebSocket\Server("0.0.0.0", $port);
#$server = new Swoole\Server("0.0.0.0", $port);
$server = new Swoole\Http\Server("0.0.0.0", $port);
$server->set([
	'worker_num'=>$workerNum,
]);
logit("=== server: port=$port, workers=$workerNum");

$server->on('open', function ($ws, $req) {
	logit("open: fd=" . $req->fd);
});

$port1 = $server->listen('0.0.0.0', $port+1, SWOOLE_SOCK_TCP);
$port1->set([]);
$port1->on("Receive", 'onReceive');


$server->on('message', function ($ws, $frame) {
	logit("onmessage: fd=" . $frame->fd);
	$req = json_decode($frame->data, true);
	if (@$req["ac"] == "init") {
		global $clientMap, $clientMapR;
		$id = $req["id"];
		$clientMap[$id] = $frame->fd;
		$clientMapR[$frame->fd] = $id;
	}
	$ws->push($frame->fd, 'OK');
});
$server->on('WorkerStart', function ($server, $workerId) {
	echo("=== worker $workerId starts. master_pid={$server->master_pid}, manager_pid={$server->manager_pid}, worker_pid={$server->worker_pid}\n");
});

$server->on("Receive", 'onReceive');

function onReceive($server, $fd, $reactorId, $data) {
	$server->send($fd, "server: $data");
}

$server->on('close', function ($ws, $fd) {
	// NOTE: http request comes here too
	logit("close: fd=" . $fd);
});

$server->on('request', 'handleRequest');

function handleRequest($req, $res)
{
	global $ERRINFO;
	$ok = false;
	$ret = null;
	try {
		$ct = $req->header["content-type"];
		if ($ct && stripos($ct, 'json') !== false) {
			$req->post = jsonDecode($req->rawContent());
		}
		$p = $req->post;//"OK";
		logit("receive http " . jsonEncode($p));
		if (! @$p["ac"])
			jdRet(E_PARAM, "bad package. no ac");
		$packClass = $GLOBALS["PackageMap"][$p["ac"]];
		if (! $packClass)
			jdRet(E_PARAM, "unknown package {$p["ac"]}");
		$data = (new $packClass)->encode($p);
		logit("encode $packClass: " . $data);
		// file_put_contents("1.data", $data);
		// TODO: tcp send

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
	}

	$retStr = jsonEncode($ret);
	$res->end($retStr);
}

/*
Swoole\Timer::after(13, function () {
	echo(">>2000<<\n\n");
});
swoole_timer_after(4000, function () {
	logit("4000");
});
*/
$server->start();


