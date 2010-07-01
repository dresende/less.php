<?php
	function lessfunction_min(&$data, &$prop) {
		$unit = lesshelpfunction_normalizeparams($data, $prop);
		
		return lesshelpfunction_returnval(min($data), $unit);
	}

	function lessfunction_max(&$data, &$prop) {
		$unit = lesshelpfunction_normalizeparams($data, $prop);
		
		return lesshelpfunction_returnval(max($data), $unit);
	}

	function lessfunction_avg(&$data, &$prop) {
		$unit = lesshelpfunction_normalizeparams($data, $prop);
		
		return lesshelpfunction_returnval(array_sum($data) / count($data), $unit);
	}

	function lessfunction_ceil(&$data, &$prop) {
		$unit = false;
		lesshelpfunction_normalizeparam($data[0], $unit, $prop);
		
		return lesshelpfunction_returnval(ceil($data[0]), $unit);
	}

	function lessfunction_floor(&$data, &$prop) {
		$unit = false;
		lesshelpfunction_normalizeparam($data[0], $unit, $prop);
		
		return lesshelpfunction_returnval(floor($data[0]), $unit);
	}

	function lessfunction_round(&$data, &$prop) {
		$unit = false;
		lesshelpfunction_normalizeparam($data[0], $unit, $prop);
		
		return lesshelpfunction_returnval(round($data[0], isset($data[1]) ? (double) $data[1] : 0), $unit);
	}
	
	function lessfunction_lighten(&$data, &$prop) {
		$unit = 'color';
		lesshelpfunction_normalizeparam($data[0], $unit, $prop);
		$unit = '%';
		lesshelpfunction_normalizeparam($data[1], $unit, $prop);
		
		// convert to HSV
		$data[0] = lesshelpfunction_rgb2hsv($data[0][0] / 255, $data[0][1] / 255, $data[0][2] / 255);
		
		// add/subtract to VALUE (between 0 and 100)
		$data[0][2] = max(min($data[0][2] + $data[1], 100), 0);
		$data[0] = lesshelpfunction_hsv2rgb($data[0][0], $data[0][1], $data[0][2]);

		return 'rgb('.($data[0][0]*255).','.($data[0][1]*255).','.($data[0][2]*255).')';
	}
	
	function lessfunction_darken(&$data, &$prop) {
		$data[1] = '-'.$data[1];
		return lessfunction_lighten($data, $prop);
	}
	
	function lesshelpfunction_normalizeparams(&$data, &$prop) {
		$unit = false;

		for ($i = 0; $i < count($data); $i++) {
			lesshelpfunction_normalizeparam($data[$i], $unit, $prop);
		}
		
		return $unit;
	}
	
	function lesshelpfunction_normalizeparam(&$param, &$unit, &$prop) {
		if (substr($param, 0, 1) == '#') {
			// color
			if ($unit !== false && $unit != 'color') {
				throw new Exception("Mixing units inside property expressions ('{$unit}' and 'color')");
			}

			$unit = 'color';
			$param = substr($param, 1);
			switch (strlen($param)) {
				case 6:
					$param = array(hexdec(substr($param, 0, 2)), hexdec(substr($param, 2, 2)), hexdec(substr($param, -2)));
					return;
				case 3:
					$param = array(hexdec($param{0}.$param{0}), hexdec($param{1}.$param{1}), hexdec($param{2}.$param{2}));
					return;
			}
			throw new Exception("Invalid color format inside property expression");
		} elseif (preg_match('/^(\-?[0-9]+|\-?[0-9]*\.[0-9]+)'.$prop->units_expr.'$/', $param, $m)) {
			if ($unit !== false && $unit != $m[2]) {
				throw new Exception("Calling function with several diferent units ({$unit} and {$m[2]})");
			}
			$unit = $m[2];
			
			$param = $m[1];
		} else {
			$param = (double) $m[1];
		}
	}
	
	function lesshelpfunction_returnval(&$val, &$unit) {
		return $val . ($unit !== false ? $unit : '');
	}

	function lesshelpfunction_rgb2hsv($r, $g, $b) {
		$M = max($r, $g, $b);
		$m = min($r, $g, $b);
		$C = $M - $m;
	
		$v = $M;
		if ($C == 0) {
			$h = 0;
			$s = 0;
		} else {
			if ($M == $r)
				$h = ($g - $b) / $C;
			elseif ($M == $g)
				$h = ($b - $r) / $C + 2;
			else
				$h = ($r - $g) / $C + 4;
			$h *= 60;
			$s = $C / $v * 100;
		}

		$v *= 100;
	
		return array($h, $s, $v);
	}

	function lesshelpfunction_hsv2rgb($h, $s, $v) {
		if ($v == 0) {
			return array(0, 0, 0);
		}
		
		$v /= 100;

		if ($s == 0) {
			// grey tones
			return array($v, $v, $v);
		} else {
			$s /= 100;
			$h /= 60;
			
			$i = floor($h);
			$f = $h - $i;
			if ($i % 2 == 0) $f = 1 - $f;
			$m = $v * (1 - $s);
			$n = $v * (1 - $s * $f);

			switch ($i) {
				case 6:
				case 0: return array($v, $n, $m);
				case 1: return array($n, $v, $m);
				case 2: return array($m, $v, $n);
				case 3: return array($m, $n, $v);
				case 4: return array($n, $m, $v);
				case 5: return array($v, $m, $n);
			}
		}

		$r = $g = $b = 0;
		return array($r, $g, $b);
	}
?>
