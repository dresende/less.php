<?php
	require_once dirname(__FILE__) . '/common.less.class.php';
	require_once dirname(__FILE__) . '/functions.less.class.php';

	/**
	 * LessCode
	 *
	 * Main class. Use it to check LESS code and compile it.
	 * After initializing, the class will atempt to parse the
	 * code. If it sees anything it doesn't like, it will throw
	 * an exception. If not, you can call and echo output().
	 **/
	class LessCode extends LessScope {
		/**
		 * LessCode::__construct()
		 *
		 * @param	Mixed		$code		LESS code (optional)
		 **/
		public function __construct($code = false) {
			$this->debug = new LessDebug();
			
			if ($code !== false)
				$this->parse($code);
		}
		
		/**
		 * LessCode::output()
		 *
		 * @return	CSS code
		 **/
		public function output() {
			$output = "";

			foreach ($this->declarations as $declaration) {
				$output .= $declaration->output();
			}
			
			return $output;
		}
		
		/**
		 * LessCode::parseFile($path)
		 *
		 * @param	String		$path		Path to LESS file
		 * @return	Boolean				Success
		 **/
		public function parseFile($path) {
			if (!file_exists($path))
				return false;

			return $this->parse(file_get_contents($path));
		}
		
		/**
		 * LessCode::parse($data)
		 *
		 * @param	String		$data		LESS code
		 * @return	Boolean				Success
		 **/
		public function parse($data) {
			// strip comments
			$data = preg_replace('|\/\*.*?\*\/|s', '', $data);
			$data = preg_replace('|\s*\/\/.*|', '', $data);
			
			while (strlen($data) > 0) {
				if ($data{0} == "@") { // "Variables"
					if (($p = strpos($data, ':')) === false) {
						throw new Exception("Invalid variable set - invalid sintax");
					}

					$var_name = trim(substr($data, 1, $p - 1));
					$data = ltrim(substr($data, $p + 1));

					if (($p = strpos($data, ';')) === false) {
						throw new Exception("Invalid variable set - no value");
					}

					$var_value = rtrim(substr($data, 0, $p));
					$data = ltrim(substr($data, $p + 1));

					$this->debug->output("Adding variable '{$var_name}'='{$var_value}'");
					$this->variables[$var_name] = $var_value;
					continue;
				}

				if (($p = strpos($data, '{')) === false) {
					throw new Exception("Invalid declaration set - invalid sintax");
				}

				$dec_name = rtrim(substr($data, 0, $p));
				$data = substr($data, $p + 1);
				
				$brk_c = 0; $p = false;
				for ($i = 0; $i < strlen($data); $i++) {
					if ($data{$i} == "{")
						$brk_c++;
					elseif ($data{$i} == "}") {
						$brk_c--;
						if ($brk_c < 0) {
							$p = $i;
							break;
						}
					}
				}

				if ($p === false) {
					throw new Exception("Invalid declaration set - no ending");
				}

				$dec_value = trim(substr($data, 0, $p));
				$data = ltrim(substr($data, $p + 1));

				$this->debug->output("Adding declaration '".$dec_name."'");
				
				$this->declarations[] = new LessDeclaration($dec_name, $dec_value, $this, $this->debug);
			}
			
			return true;
		}
	}

	/**
	 * LessDeclaration
	 *
	 * This is used to store an element declaration or possibly a mixin.
	 * It can contain nested declarations.
	 **/
	class LessDeclaration extends LessScope {
		private $parameters = array();
		private $called_parameters = array();
		private $properties = array();
		private $mixins = array();
		private $names = array();
		private $is_mixin = false;

		public function __construct($names, $data, &$parent, &$debug) {
			$this->parent = $parent;
			$this->debug = $debug;

			if (preg_match('/^(\..+?)\s*\(\s*(.+)\s*\)\s*$/', $names, $m)) {
				$this->is_mixin = true;
				$this->names = array($m[1]);
				
				$params = preg_split('/\s*;\s*/', $m[2], -1, PREG_SPLIT_NO_EMPTY);
				for ($i = 0; $i < count($params); $i++) {
					if ($params[$i]{0} != '@') {
						throw new Exception("Invalid mixin declaration (missing '@' before '{$params[$i]}')");
					}
					$params[$i] = substr($params[$i], 1);
					if (($p = strpos($params[$i], ':')) !== false) {
						$param_name = rtrim(substr($params[$i], 0, $p));
						$param_val = ltrim(substr($params[$i], $p + 1));
					} else {
						$param_name = $params[$i];
						$param_val = null;
					}
					
					if (isset($this->parameters[$param_name])) {
						throw new Exception("Invalid mixin declaration (duplicated parameter '@{$param_name}')");
					}
					$this->parameters[$param_name] = $param_val;
				}
			} else {
				$this->names = preg_split('/\s*,\s*/', trim($names), -1, PREG_SPLIT_NO_EMPTY);
			}
			
			$this->parse($data);
		}
		
		public function parse($data) {
			while (strlen($data) > 0) {
				if ($data{0} == ".") {
					$p = strpos($data, ';');
					$p2 = strpos($data, '{');
					
					if ($p2 === false || ($p2 !== false && $p2 > $p)) {
						// Mixin?
						if ($p === false) {
							$prop_name = substr($data, 1);
							$data = "";
						} else {
							$prop_name = rtrim(substr($data, 1, $p - 1));
							$data = ltrim(substr($data, $p + 1));
						}
					
						if (($p = strpos($prop_name, '(')) !== false) {
							$prop_params = rtrim(ltrim(substr($prop_name, $p + 1)), ' )');
							$prop_name = rtrim(substr($prop_name, 0, $p));
						} else {
							$prop_params = "";
						}
					
						$this->debug->output("Included mixin '{$prop_name}'");
						$mixin = $this->findMixin($prop_name);
						if ($mixin !== null) {
							$this->mixins[] = $mixin;
							$mixin->setMixin();
							$mixin->setCalledParameters(preg_split('/\s*;\s*/', $prop_params));
						}
						continue;
					}
				}
				$p_prop = strpos($data, ':', 1);
				$p_decl = strpos($data, '{');
				$p_prop_end = strpos($data, ';', $p_prop);
				
				if ($p_prop === false && $p_decl === false)
					break;
				
				if ($p_prop !== false && $p_prop_end !== false && ($p_decl === false || ($p_decl !== false && $p_decl > $p_prop && $p_decl > $p_prop_end))) {
					// Property
					$prop_name = rtrim(substr($data, 0, $p_prop));
					$data = ltrim(substr($data, $p_prop + 1));
				
					if (($p = strpos($data, ';')) === false) {
						$prop_value = $data;
						$data = "";
					} else {
						$prop_value = rtrim(substr($data, 0, $p));
						$data = ltrim(substr($data, $p + 1));
					}
				
					if ($prop_name{0} == '@') {
						$this->variables[substr($prop_name, 1)] = $prop_value;
						$this->debug->output("Variable '".substr($prop_name, 1)."'='{$prop_value}'");
					} else {
						$this->properties[$prop_name] = $prop_value;
						$this->debug->output("Property '{$prop_name}'='{$prop_value}'");
					}
					continue;
				}

				// Sub-group
				$decl_name = rtrim(substr($data, 0, $p_decl));
				$data = ltrim(substr($data, $p_decl + 1));
				
				// insure that the last declaration of a group has ';' before '}'
				$data = preg_replace('/([^;])\s*}/', '$1; }', $data);
				
				$brk_c = 0; $p = false;
				for ($i = 0; $i < strlen($data); $i++) {
					if ($data{$i} == "{")
						$brk_c++;
					elseif ($data{$i} == "}") {
						$brk_c--;
						if ($brk_c < 0) {
							$p = $i;
							break;
						}
					}
				}

				if ($p === false) {
					throw new Exception("Invalid sub-declaration set - no ending");
				}

				$decl_value = trim(substr($data, 0, $p));
				$data = ltrim(substr($data, $p + 1));
				
				$this->debug->output("Sub-declaration '{$decl_name}'");

				$this->declarations[] = new LessDeclaration($this->buildSubDeclarationName($decl_name), $decl_value, $this, $this->debug);
			}
		}
		
		public function output() {
			if ($this->is_mixin)
				return "";

			$properties = $this->outputProperties();
			if (strlen($properties) > 0 || count($this->mixins) > 0) {
				$output = implode(", ", $this->names) . " {" . $properties;

				foreach ($this->mixins as $mixin)
					$output .= $mixin->outputProperties();
			
				$output .= " }\n";
			} else {
				$output = "";
			}
			
			foreach ($this->declarations as $dec)
				$output .= $dec->output();
			
			return $output;
		}
		
		public function outputProperties() {
			$output = "";
			
			foreach ($this->properties as $k => $v) {
				if (count($this->parameters) > 0) {
					$vars_saved = $this->variables;
					$this->variables = $this->parameters;
					foreach ($this->called_parameters as $k2 => $v2)
						if (strlen($v2))
							$this->variables[$k2] = $v2;
					
					$prop = new LessProperty($v, $this);
					$output .= " {$k}: " . $prop->output() . ";";
					
					$this->variables = $vars_saved;
				} else {
					$prop = new LessProperty($v, $this);
					$output .= " {$k}: " . $prop->output() . ";";
				}
			}
			
			return $output;
		}
		
		public function setMixin($is_mixin = true) {
			$this->is_mixin = $is_mixin;
		}
		
		public function setCalledParameters($params) {
			$n = 0;
			foreach ($this->parameters as $k => $v) {
				if (!isset($params[$n])) {
					throw new Exception("Invalid mixin call {$this->names[0]}. Missing parameter {$n}");
				}
				$this->called_parameters[$k] = $params[$n++];
			}
		}
		
		public function getName() {
			return $this->names[0];
		}
		
		private function buildSubDeclarationName($decl_name) {
			$out = array();
			$decl_name = preg_split('/\s*,\s*/', $decl_name, -1, PREG_SPLIT_NO_EMPTY);
			
			foreach ($this->names as $name) {
				foreach ($decl_name as $subname) {
					if ($subname{0} == '&') {
						$out[] = $name . substr($subname, 1);
					} else {
						$out[] = $name . (in_array($subname{0}, array(':', '>', '+')) ? '' : ' ') . $subname;
					}
				}
			}
			
			return implode(', ', $out);
		}
		
		private function findMixin($name) {
			$dec = $this;
			while ($dec !== null) {
				$mixin = $dec->getDeclaration('.'.$name);
				if ($mixin !== null) {
					return $mixin;
				}
				
				$dec = $dec->getParent();
			}
			return null;
		}
	}

	/**
	 * LessProperty
	 *
	 * This is used to convert a property value. It can match variables,
	 * units, percentage and colors (and numbers of course).
	 *
	 * It gives priority to * and / operators and also checks for nested
	 * operations (using parenthesis).
	 **/
	class LessProperty {
		public $part_expr = '(\#[a-f0-9]{3,6}|[0-9\.]+[a-z]{2}|[0-9\.]+\%?|)';
		public $units_expr = '(%|e(m|x)|p(x|t|c)|in|ft|(m|c)m|k?Hz|deg|g?rad|m?s)';

		private $value;
		private $scope;
		private $unit = false;
		private $percent = false;

		public function __construct($value, &$scope) {
			$this->value = $value;
			$this->scope = $scope;
		}
		
		public function output() {
			// replace variables
			$this->value = preg_replace_callback('/@([a-z\-0-9]+)/i', array($this, 'translateVariable'), $this->value);

			// find functions, like min(), max(), ..
			do {
				$new_value = preg_replace_callback('/([a-z]+)\(\s*([^\(\)]+)\s*\)/', array($this, 'translateFunctions'), $this->value, 1);
				if ($new_value == $this->value) break;
				
				$this->value = $new_value;
			} while (true);

			// find nested operations, the ones between ( and )
			do {
				$new_value = preg_replace_callback('/\(\s*(.+?)\s*\)/', array($this, 'translateNestedOperation'), $this->value, 1);
				if ($new_value == $this->value) break;
				
				$this->value = $new_value;
			} while (true);
			
			// replace operations * and /
			do {
				$this->value = preg_replace_callback('/'.$this->part_expr.'\s+([\*\/])\s+'.$this->part_expr.'/', array($this, 'checkExpression'), $this->value, 1, $replaced);
			} while ($replaced > 0);
			
			// replace operations + and -
			do {
				$this->value = preg_replace_callback('/'.$this->part_expr.'\s+([\+\-])\s+'.$this->part_expr.'/', array($this, 'checkExpression'), $this->value, 1, $replaced);
			} while ($replaced > 0);

			return $this->value;
		}
		
		public function checkExpression($match) {
			$part1 = $this->checkPart($match[1]);
			$part2 = $this->checkPart($match[3]);
			
			$op = $match[2];
			if (is_array($part1) && is_array($part2)) {
				return $this->buildColor($this->doOp($op, $part1[0], $part2[0]), $this->doOp($op, $part1[1], $part2[1]), $this->doOp($op, $part1[2], $part2[2]));
			} elseif (is_array($part1)) {
				return $this->buildColor($this->doOp($op, $part1[0], $part2), $this->doOp($op, $part1[1], $part2), $this->doOp($op, $part1[2], $part2));
			} elseif (is_array($part2)) {
				return $this->buildColor($this->doOp($op, $part1, $part2[0]), $this->doOp($op, $part1, $part2[1]), $this->doOp($op, $part1, $part2[2]));
			}
			$val = $this->doOp($match[2], $part1, $part2);
			if ($this->unit !== false)
				return $val . $this->unit;
			if ($this->percent !== false)
				return round($val * 100, 2).'%';
			return $val;
		}
		
		public function doOp($op, $p1, $p2) {
			//printf("[OP] '%s' %s '%s'\n", $p1, $op, $p2);
			switch ($op) {
				case '*': return $p1 * $p2;
				case '/': return $p1 / $p2;
				case '+': return $p1 + $p2;
				case '-': return $p1 - $p2;
			}
		}
		
		public function buildColor($c1, $c2, $c3) {
			$c = str_pad(dechex(max(min($c1, 255), 0)), 2, '0', STR_PAD_LEFT)
			   . str_pad(dechex(max(min($c2, 255), 0)), 2, '0', STR_PAD_LEFT)
			   . str_pad(dechex(max(min($c3, 255), 0)), 2, '0', STR_PAD_LEFT);

			return "#{$c}";
		}
		
		public function translateVariable($match) {
			$dec = $this->scope;
			while ($dec !== null) {
				if ($dec->variableExists($match[1])) {
					$prop = new LessProperty($dec->getVariable($match[1]), $this->scope);
					return $prop->output();
				}
				
				$dec = $dec->getParent();
			}
			return $match[0];
		}
		
		public function translateNestedOperation($match) {
			$before = $match[1];
			$op = new LessProperty($before, $this->scope);
			$after = $op->output();
			
			if ($before == $after)
				return $match[0];
			return $after;
		}
		
		public function translateFunctions($match) {
			$fun = $match[1];
			$fun_call = 'lessfunction_'.$fun;
			
			if (!function_exists($fun_call))
				return $match[0];

			$op = new LessProperty($match[2], $this->scope);
			$data = $op->output();
			
			$data = preg_split('/\s*,\s*/', trim($data));
			
			return $fun_call($data, $this);
		}
		
		public function checkPart($part) {
			if (substr($part, 0, 1) == '#') {
				// color
				if ($this->unit !== false && $this->unit != 'color') {
					throw new Exception("Mixing units inside property expressions ('{$this->unit}' and 'color')");
				}

				$this->unit = 'color';
				
				$part = substr($part, 1);
				switch (strlen($part)) {
					case 6:
						return array(hexdec(substr($part, 0, 2)), hexdec(substr($part, 2, 2)), hexdec(substr($part, -2)));
					case 3:
						return array(hexdec($part{0}.$part{0}), hexdec($part{1}.$part{1}), hexdec($part{2}.$part{2}));
				}
				throw new Exception("Invalid color format inside property expression");
			} elseif (preg_match('/^(\-?[0-9]+|\-?[0-9]*\.[0-9]+)'.$this->units_expr.'$/', $part, $m)) {
				if ($m[2] == '%') {
					$this->percent = true;
					return $m[1] / 100;
				}
				
				if ($this->unit !== false && $this->unit != $m[2]) {
					throw new Exception("Mixing units inside property expressions ('{$this->unit}' and '{$m[2]}')");
				}

				$this->unit = $m[2];
				return $m[1];
			} else {
				return $part;
			}
		}
	}
?>
