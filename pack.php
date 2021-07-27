<?php
/*
可序列化类的写法

- 继承TBase，拥有encode和decode方法
- 每个类可以写一个onDecode方法，参考T_Carton;
- onEncode可以直接由onDecode生成，也可以自定义，参考T_S7Str
- 简单基础的类可以直接重写decode方法，参考T_S7Str
- 数组、字符串须定长; 数组前有个int16表示长度

类型写法

- 基础类型：n-int16, N-int32, f-float/real, a-char
- 类型后可加一个数字，表示Size
- 字符串: `T_S7Str,8`，逗号号的参数为最大长度；特别地，a[8]表示定长数组
- 自定义类型，以`T_`开头
- 数组，以`[size]`定义最大长度; 只支持一维数组
*/

class TBase
{
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
			else if ($v == "a") {
				$v = self::readStr($pack, $pos, $arraySize);
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
			$ret[] = $type;
			$ret[] = $obj[$k];
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
	static function readFloat($pack, &$pos, $arraySize=0)
	{
		if ($arraySize) {
			$len = 4 * $arraySize;
			$o = unpack("f" . $arraySize, substr($pack, $pos, $len));
			$pos += $len;
			return array_values($o);
		}
		$len = 4;
		$o = unpack("fa", substr($pack, $pos, $len));
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

class T_ArrivePackage extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			"area" => "a",
			"cartonList" => "T_Carton[20]",
			"endTag" => "a[4]" // for test
		];
	}
}

class T_ToPortPackage extends TBase
{
	protected function onDecode() {
		return [
			"ac" => "T_S7Str,8",
			"boxCode" => "T_S7Str,20",
			"portCode" => "T_S7Str,20",
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
	],
	"endTag" => "END"
];

/*
$p = new T_Carton();
$x = $p->encode($pack["cartonList"][1]);
//var_dump($x);
$y = $p->decode($x);
var_dump($y);
*/

$p = new T_ArrivePackage();
$x = $p->encode($pack);
var_dump($x);
$y = $p->decode($x);
var_dump($y);
}
