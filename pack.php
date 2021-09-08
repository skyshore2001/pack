<?php
/*
每个TCP二进制报文以`T_S7Str[8] ac`开头，ac标识报文名。
对每个报文应定义一个类如 T_ToWcsPacket(必须T_开头), 并注册到PacketMap中，数据结构为map: `{ac=>报文类名}`
TCP报文转成HTTP时，使用JSON格式，形成一个JSON对象，其中包含ac字段

T_S7Str是专门适配西门子S7-1200 PLC的字符串类型。

报文类的写法

- 继承TBase，拥有encode和decode方法
- 每个类可以写一个onDecode方法，定义每个字段的类型，参考T_Carton;
- onEncode可以直接由onDecode生成，也可以自定义，参考T_S7Str
- 简单基础的类可以直接重写decode方法，参考T_S7Str
- 数组、字符串须定长; 数组前有个int16表示长度
- 支持null

onDecode应返回 
	[ 字段1, 类型1, 字段2, 类型2 ]

onDecode应返回 
	[ 类型1, 值1, 类型2, 值2]


类型定义：可以是基本类型，或是继承于TBase的扩展类型。扩展类型类名必须以"T_"开头。

基本类型如下所示：(参考php pack函数)

c - char 1B
n - uint16:2B (net-order)
N - uint32:4B (net-order)
f - float/real:4B
F - float/real:4B (net-order, PLC uses net-order but PHP does not support this)
a{N} - NUL-padded string
TODO: A{N} - SPACE-padded string
TODO: d - double:8B

- 类型后可加一个数字，表示Size
- 字符串: `T_S7Str,8`，逗号后的参数为最大长度；特别地，a[8]表示定长数组
- 数组，以`[size]`定义最大长度; 只支持一维数组

本文件可直接执行，进行bin和json格式转换测试：

	php pack.php json2bin < test/test_tcp.json > 1.bin
	php pack.php bin2json < test/test_http.bin > 1.http

NOTE: PLC S7-1200 int=16bit/net-order, real=32bit/net-order
*/
require_once("./jdcloud-php/common.php");

class TBase
{
	// string capacity for T_S7Str
	protected $p1 = 0;

	function encode($obj) {
		$fmt = '';
		$params = [null];
		$this->getEncodeParams($this, $obj, $fmt, $params);
		$params[0] = $fmt;
		// var_dump($params);
		return call_user_func_array("pack", $params);
	}
	function decode($pack, &$pos=0) {
		$val = $this->onDecode();
		foreach ($val as $k => &$v) {
			list ($arraySize, $p1) = self::parseDef($v);
			$tobj = null;
			if ($v == "n") {
				$v = self::readInt16($pack, $pos, $arraySize);
			}
			else if ($v == "N") {
				$v = self::readInt32($pack, $pos, $arraySize);
			}
			else if ($v == "f") {
				$v = self::readFloat($pack, $pos, $arraySize);
			}
			else if ($v == "F") {
				$v = self::readFloat($pack, $pos, $arraySize, true);
			}
			else if ($v == "a") {
				$v = self::readStr($pack, $pos, $arraySize);
			}
			else if ($v == "c") {
				$v = self::readChar($pack, $pos, $arraySize);
			}
			else if (substr($v, 0, 2) == "T_") {
				if (!class_exists($v))
					throw new Exception("bad class $v");
				$tobj = new $v;
				$tobj->p1 = $p1;
				if ($arraySize) { // isArray
					$v = [];
					for ($i=0; $i<$arraySize; ++$i) {
						$e = $tobj->decode($pack, $pos);
						$v[] = $e;
					}
				}
				else {
					$v = $tobj->decode($pack, $pos);
				}
			}
			else {
				throw new Exception("unsupported decode mark or class `$v`");
			}
		}
		return $val;
	}

	protected function onEncode($obj) {
		$def = $this->onDecode();
		$ret = [];
		foreach ($def as $k => $type) {
			@$v = $obj[$k];
			if ($type == "F") {
				$type = "f";
				if ($v) {
					self::hton4($v);
				}
			}
			$ret[] = $type;
			$ret[] = $v;
		}
		return $ret;
	}
	protected function onDecode() {
		jdRet(E_SERVER, "not implement");
		return [];
	}

	private static function parseDef(&$k) {
		$p1 = null;
		if (strpos($k, ',') !== false) {
			$k = preg_replace_callback('/,(\d+)/', function ($ms) use (&$p1) {
				$p1 = intval($ms[1]);
				return '';
			}, $k);
		}
		$arraySize = null;
		if (strpos($k, '[') !== false) {
			$k = preg_replace_callback('/\[(\d+)\]/', function ($ms) use (&$arraySize) {
				$arraySize = intval($ms[1]);
				return '';
			}, $k);
		}
		return [$arraySize, $p1];
	}

	// generate (fmt, param) for pack
	private function getEncodeParams($tobj, $obj, &$fmt, &$param)
	{
		$values = $tobj->onEncode($obj);
		$cnt = count($values);
		if ($cnt < 2 || $cnt % 2 != 0)
			throw new Exception("bad arguments for getEncodeParams");

		for ($idx=0; $idx<$cnt; $idx+=2) {
			$k = $values[$idx];
			$v = $values[$idx+1];

			list ($arraySize, $p1) = self::parseDef($k);
			$tobj1 = null;
			if (substr($k, 0, 2) == "T_") {
				if (!class_exists($k))
					throw new Exception("bad class $k");
				$tobj1 = new $k;
				$tobj1->p1 = $p1;
			}

			if (! $arraySize) {
				if ($tobj1) {
					$this->getEncodeParams($tobj1, $v, $fmt, $param);
				}
				else {
					$fmt .= $k;
					array_push($param, $v);
				}
			}
			// is array
			else {
				for ($i=0; $i<$arraySize; ++$i) {
					@$e = $v[$i];
					if ($tobj1) {
						$this->getEncodeParams($tobj1, $e, $fmt, $param);
					}
					else {
						$fmt .= $k;
						array_push($param, $e);
					}
				}
			}
		}
	}

	static function readInt16($pack, &$pos, $arraySize=0)
	{
		if ($arraySize) {
			$len = 2 * $arraySize;
			$o = unpack("n" . $arraySize, substr($pack, $pos, $len));
			$pos += $len;
			return array_values($o);
		}
		$len = 2;
		$o = unpack("na", substr($pack, $pos, $len));
		$pos += $len;
		return $o["a"];
	}
	static function readInt32($pack, &$pos, $arraySize=0)
	{
		if ($arraySize) {
			$len = 4 * $arraySize;
			$o = unpack("N" . $arraySize, substr($pack, $pos, $len));
			$pos += $len;
			return array_values($o);
		}
		$len = 4;
		$o = unpack("Na", substr($pack, $pos, $len));
		$pos += $len;
		return $o["a"];
	}
	static function readChar($pack, &$pos, $arraySize=0)
	{
		if ($arraySize) {
			$len = 1 * $arraySize;
			$o = unpack("c" . $arraySize, substr($pack, $pos, $len));
			$pos += $len;
			return array_values($o);
		}
		$len = 1;
		$o = unpack("ca", substr($pack, $pos, $len));
		$pos += $len;
		return $o["a"];
	}
	static function readFloat($pack, &$pos, $arraySize=0, $netOrder = false)
	{
		if ($arraySize) {
			$len = 4 * $arraySize;
			// TODO: handle netOrder
			$o = unpack("f" . $arraySize, substr($pack, $pos, $len));
			$pos += $len;
			return array_values($o);
		}
		$len = 4;
		$v = substr($pack, $pos, $len);
		if ($netOrder) {
			self::hton4($v);
		}
		$o = unpack("fa", $v);
		$pos += $len;
		return $o["a"];
	}
	static function readStr($pack, &$pos, $cnt=1)
	{
		if (!$cnt)
			$cnt = 1;
		$rv = substr($pack, $pos, $cnt);
		$pos += $cnt;
		return $rv;
	}

	static function bin2json($data) {
		$tobj = new T_BasePacket();
		$ac = $tobj->decode($data)["ac"];
		$packClass = $GLOBALS["PacketMap"][$ac];
		if (! $packClass)
			jdRet(E_PARAM, "unknown package $ac");
		$p = (new $packClass)->decode($data);
		return $p;
	}

	static function json2bin($p) {
		if (! @$p["ac"])
			jdRet(E_PARAM, "bad package. no ac");
		$packClass = $GLOBALS["PacketMap"][$p["ac"]];
		if (! $packClass)
			jdRet(E_PARAM, "unknown package {$p["ac"]}");
		$data = (new $packClass)->encode($p);
		logit("encode $packClass: " . $data);
		return $data;
	}

	static function hton4(&$v) {
		$t = $v[0]; $v[0] = $v[3]; $v[3] = $t;
		$t = $v[1]; $v[1] = $v[2]; $v[2] = $t;
	}
}

// 注意对null的处理：encode按size+2字节全写0, decode读到长度为0则返回null
class T_S7Str extends TBase
{
	protected function onEncode($obj) {
		$len = strlen($obj);
		$size = $this->p1 ?: $len;
		if ($obj === null)
			return ["a".($size+2), null];
		return [
			"C", $size,
			"C", $len,
			"a".$size, $obj
		];
	}

	function decode($pack, &$pos=0)
	{
		$o = unpack("Ccap/Clen", substr($pack, $pos, 2));
		if ($o["cap"] != $this->p1) {
			if ($o["cap"] == 0) {
				$pos += $this->p1 + 2;
				return null;
			}
			throw new Exception("bad S7Str len, expect " . $this->p1 . ", actual " . $o["cap"]);
		}
		$pos += 2;
		$str = substr($pack, $pos, $o["len"]);
		$pos += $o["cap"];
		return $str;
	}
}

class T_BasePacket extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
		];
	}
}

// ====================== 应用报文定义 =======================

// 支持编码解码的示例类的实现
class T_Carton extends TBase
{
	protected function onDecode() {
		return [
			"code" => "T_S7Str,20",
			"type" => "T_S7Str,2",
			"qty" => "n"
		];
	}
}

class T_ToWcsPacket extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			"area" => "a",
			"status" => "c",
			"portCode" => "n",
			"cartonList" => "T_Carton[20]"
		];
	}
}

class T_FinishedPacket extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			"boxCode" => "T_S7Str,32",
			"portCode" => "n",
			"weight" => "F"
		];
	}
}

class T_PalEmptyPacket extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			//"palletNo" => "T_S7Str,16",
			"toLocationNo" => "T_S7Str,32"
		];
	}
}

class T_FaultPacket extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			"dscr" => "T_S7Str,32"
		];
	}
}

$GLOBALS["PacketMap"] = [
	"arrived" => "T_ToWcsPacket",
	"clean" => "T_ToWcsPacket",
	"lock" => "T_ToWcsPacket",
	"finished" => "T_FinishedPacket",
	"palEmpty" => "T_PalEmptyPacket"
	"fault" => "T_FaultPacket"
];

if (! @$GLOBALS["noExecApi"]) {

// RUN:
// php pack.php json2bin < test/test_tcp.json > 1.bin
// php pack.php bin2json < test/test_http.bin > 1.http
@$ac = $argv[1];
if ($ac == "json2bin") {
	$json = jsonDecode(file_get_contents("php://stdin"));
	$data = TBase::json2bin($json);
	echo($data);
}
else if ($ac == "bin2json") {
	$data = file_get_contents("php://stdin");
	$json = jsonEncode(TBase::bin2json($data), true);
	echo($json);
}
else {
	echo("Usage php pack.php json2bin|bin2json <in >out\n");
}

/*
// 测试代码
$pack = [
	"ac" => "arrived",
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
	],
	"endTag" => "END"
];

$p = new T_Carton();
$x = $p->encode($pack["cartonList"][1]);
//var_dump($x);
$y = $p->decode($x);
var_dump($y);

$p = new T_ToWcsPacket();
$x = $p->encode($pack);
var_dump($x);
$y = $p->decode($x);
var_dump($y);
*/

}
