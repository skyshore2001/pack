<?php

$pack = [
	"ac" => "arrive",
	"area" => "A",
	"cartonList" => [
		[
			"code" => "carton01",
			"type" => "t1",
			"qty" => 10
		],
		[
			"code" => "carton02",
			"type" => "t2",
			"qty" => 12
		]
	]
];

/*
static function decodeStr($s, &$pos) {
	$s1 = substr($s, $pos, 2);
	$o = unpack("ccap/clen", $s1);
	$s2 = substr($s, $pos+2, $o["len"]);
	$pos += $o["len"] + 2;
	return $s2;//unpack("a" . $o["len"] . "str", $s2)["str"];
}
*/

function readInt16($pack, &$pos)
{
	$len = 2;
	$o = unpack("na", substr($pack, $pos, $len));
	$pos += 2;
	return $o["a"];
}

function readS7Str($pack, &$pos)
{
	$o = unpack("ccap/clen", substr($pack, $pos, 2));
	$pos += 2;
	$str = substr($pack, $pos, $o["len"]);
	$pos += $o["len"];
	return $str;
}

function writeBin()
{
	$values = func_get_args();
	$cnt = count($values);
	if ($cnt < 2 || $cnt % 2 != 0)
		throw "bad arguments for writeBin";

	$fmt = '';
	$param = [null];
	for ($i=0; $i<$cnt; $i+=2) {
		$k = $values[$i];
		$v = $values[$i+1];
		if ($k == "S7Str") {
			$len = strlen($v);
			$fmt .= 'cca' . $len;
			array_push($param, $len, $len, $v);
		}
		else {
			$fmt .= $k;
			array_push($param, $v);
		}
	}
	$param[0] = $fmt;
	var_dump($param);
	return call_user_func_array("pack", $param);
}

class Carton
{
	static function encode($obj) {
		return writeBin(
			"S7Str", $obj["code"],
			"S7Str", $obj["type"],
			"n", $obj["qty"]
		);
	}
	static function decode($pack, &$pos=0) {
		return [
			"code" => readS7Str($pack, $pos),
			"type" => readS7Str($pack, $pos),
			"qty" => readInt16($pack, $pos)
		];
	}
}

class ArrivePackage
{
	static function encode($obj) {
		$val = writeBin(
			"S7Str", $obj["ac"],
			"S7Str", $obj["area"]
		);

		foreach ($obj["cartonList"] as $e) {
			$val .= Carton::encode($e);
		}
		return $val;
	}

	static function decode($pack, &$pos=0) {
		$val = [
			"ac" => readS7Str($pack, $pos),
			"area" => readS7Str($pack, $pos),
			"cartonList" => []
		];
		$len = strlen($pack);
		while ($pos < $len) {
			$e = Carton::decode($pack, $pos);
			$val["cartonList"][] = $e;
		}
		return $val;
	}
}

$x = Carton::encode($pack["cartonList"][1]);
$y = Carton::decode($x);
var_dump($y);

$x = ArrivePackage::encode($pack);
$y = ArrivePackage::decode($x);
var_dump($y);

/*
$Carton_FMT = "a50a2n";
$Pack_FMT = "a8c[Carton_FMT]";

$fmt = "a8c[Carton_FMT]";

pack($

# echo(pack("N", 0xffff3386));
# echo(pack("N", 0x99887766));
# exit();
$x = file_get_contents("1");
var_dump(unpack("Ccap/Cnum/a7str", $x));
*/
