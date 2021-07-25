<?php
/*
可序列化类的写法

- 继承TBase，拥有encode和decode方法
- 每个类可以写一个onDecode方法，参考T_Carton;
- onEncode可以直接由onDecode生成，也可以自定义，参考T_S7Str
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
		$size = null;
		foreach ($val as $k => &$v) {
			if (strpos($v, '[') !== false) {
				$v = preg_replace_callback('/\[(\d+)\]/', function ($ms) use (&$size) {
					$size = intval($ms[1]);
					return '';
				}, $v);
			}
			if ($v == "n") {
				$v = self::readInt16($pack, $pos);
			}
			else if ($v == "f") {
				$v = self::readFloat($pack, $pos);
			}
			else if (substr($v, 0, 2) == "T_") {
				if (!class_exists($v))
					throw new Exception("bad class $v");
				$obj = new $v;
				if ($size) { // isArray
					$v = [];
					for ($i=0; $i<$size; ++$i) {
						$e = $obj->decode($pack, $pos);
						$v[] = $e;
					}
				}
				else {
					$v = $obj->decode($pack, $pos);
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
			$ret[] = $type;
			$ret[] = $obj[$k];
		}
		return $ret;
	}
	protected function onDecode() {
		jdRet(E_SERVER, "not implement");
	}

	private function getEncodeParams($values, &$fmt, &$param)
	{
		$cnt = count($values);
		if ($cnt < 2 || $cnt % 2 != 0)
			throw new Exception("bad arguments for getEncodeParams");

		for ($i=0; $i<$cnt; $i+=2) {
			$k = $values[$i];
			$v = $values[$i+1];
			$size = null;
			if (strpos($k, '[') !== false) {
				$k = preg_replace_callback('/\[(\d+)\]/', function ($ms) use (&$size) {
					$size = intval($ms[1]);
					return '';
				}, $k);
			}
			if (substr($k, 0, 2) == "T_") {
				if (!class_exists($k))
					throw new Exception("bad class $k");
				if ($size) {
					for ($i=0; $i<$size; ++$i) {
						@$e = $v[$i];
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
	static function readFloat($pack, &$pos)
	{
		$len = 4;
		$o = unpack("fa", substr($pack, $pos, $len));
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
	protected function onDecode() {
		return [
			"code" => "T_S7Str[20]",
			"type" => "T_S7Str[2]",
			"qty" => "n"
		];
	}
}

class T_ArrivePackage extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str[8]",
			"area" => "a",
			"cartonList" => "T_Carton[20]"
		];
	}
}

class T_ToPortPackage extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str[8]",
			"boxCode" => "T_S7Str[20]",
			"portCode" => "T_S7Str[20]",
			"weight" => "f"
		];
	}
}

$GLOBALS["PackageMap"] = [
	"arrive" => "T_ArrivePackage",
	"toport" => "T_ToPortPackage"
];



if (! @$GLOBALS["noExecApi"]) {
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
