<?php
/*
可序列化类的写法

- 继承TBase，拥有encode和decode方法
- 每个类可以写一个onEncode和onDecode方法，参考T_Carton;
- 简单基础的类可以直接重写decode方法，参考T_S7Str
- TODO: 数组的实现是固化的，未考虑长度，可能不对
*/

class TBase
{
	function encode($obj) {
		$fmt = '';
		$params = [null];
		$values = $this->onEncode($obj);
		$this->getEncodeParams($values, $fmt, $params);
		$params[0] = $fmt;
		// var_dump($params);
		return call_user_func_array("pack", $params);
	}
	function decode($pack, &$pos=0) {
		$val = $this->onDecode();
		foreach ($val as $k => &$v) {
			if ($v == "n") {
				$v = self::readInt16($pack, $pos);
			}
			else if (substr($v, 0, 2) == "T_") {
				if (substr($v, -1) == "*") { // isArray
					$v = substr($v, 0, -1);
					if (!class_exists($v))
						throw new Exception("bad class $v");
					$cls = new $v;
					$len = strlen($pack) - $pos;
					$v = [];
					while ($pos < $len) {
						$e = $cls->decode($pack, $pos);
						$v[] = $e;
					}
				}
				else {
					if (!class_exists($v))
						throw new Exception("bad class $v");
					$v = (new $v)->decode($pack, $pos);
				}
			}
			else {
				throw new Exception("unsupported decode mark or class `$v`");
			}
		}
		return $val;
	}

	protected function onEncode($obj) {
	}
	protected function onDecode() {
	}

	private function getEncodeParams($values, &$fmt, &$param)
	{
		$cnt = count($values);
		if ($cnt < 2 || $cnt % 2 != 0)
			throw new Exception("bad arguments for writeBin");

		for ($i=0; $i<$cnt; $i+=2) {
			$k = $values[$i];
			$v = $values[$i+1];
			if (substr($k, 0, 2) == "T_") {
				$isArray = false;
				if (substr($k, -1) == "*") {
					$isArray = true;
					$k = substr($k, 0, -1);
				}
				if (!class_exists($k))
					throw new Exception("bad class $k");
				if ($isArray) {
					foreach ($v as $e) {
						$rv = (new $k)->onEncode($e);
						$this->getEncodeParams($rv, $fmt, $param);
					}
				}
				else {
					$rv = (new $k)->onEncode($v);
					$this->getEncodeParams($rv, $fmt, $param);
				}
			}
			else {
				$fmt .= $k;
				array_push($param, $v);
			}
		}
	}

	static function readInt16($pack, &$pos)
	{
		$len = 2;
		$o = unpack("na", substr($pack, $pos, $len));
		$pos += $len;
		return $o["a"];
	}
	static function readInt32($pack, &$pos)
	{
		$len = 4;
		$o = unpack("Na", substr($pack, $pos, $len));
		$pos += $len;
		return $o["a"];
	}
}

class T_S7Str extends TBase
{
	protected function onEncode($obj) {
		$len = strlen($obj);
		return [
			"c", $len,
			"c", $len,
			"a".$len, $obj
		];
	}

	function decode($pack, &$pos=0)
	{
		$o = unpack("ccap/clen", substr($pack, $pos, 2));
		$pos += 2;
		$str = substr($pack, $pos, $o["len"]);
		$pos += $o["len"];
		return $str;
	}
}

// 支持编码解码的示例类的实现
class T_Carton extends TBase
{
	protected function onEncode($obj) {
		return [
			"T_S7Str", $obj["code"],
			"T_S7Str", $obj["type"],
			"n", $obj["qty"]
		];
	}
	protected function onDecode() {
		return [
			"code" => "T_S7Str",
			"type" => "T_S7Str",
			"qty" => "n"
		];
	}
}

class T_ArrivePackage extends TBase
{
	protected function onEncode($obj) {
		return [
			"T_S7Str", $obj["ac"],
			"T_S7Str", $obj["area"],
			"T_Carton*", $obj["cartonList"]
		];
	}

	protected function onDecode() {
		return [
			"ac" => "T_S7Str",
			"area" => "T_S7Str",
			"cartonList" => "T_Carton*"
		];
	}
}

class T_ToPortPackage extends TBase
{
	protected function onEncode($obj) {
		return [
			"T_S7Str", $obj["ac"],
			"T_S7Str", $obj["boxCode"],
			"T_S7Str", $obj["portCode"],
			"n", $obj["weight"],
		];
	}

	protected function onDecode() {
		return [
			"ac" => "T_S7Str",
			"boxCode" => "T_S7Str",
			"portCode" => "T_S7Str",
			"weight" => "n"
		];
	}
}

$GLOBALS["PackageMap"] = [
	"arrive" => "T_ArrivePackage",
	"toport" => "T_ToPortPackage"
];



if (! $GLOBALS["noExecApi"]) {
// 测试代码
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

$p = new T_Carton();
$x = $p->encode($pack["cartonList"][1]);
$y = $p->decode($x);
var_dump($y);

$p = new T_ArrivePackage();
$x = $p->encode($pack);
$y = $p->decode($x);
var_dump($y);
}
