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
		private $import_path = "./";
		private $base_path = "./";

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
		 * LessCode::setBasePath($path)
		 *
		 * Define the path for import paths defined between '<' and '>'
		 *
		 * @param	String		$path		Base path
		 **/
		public function setBasePath($path) {
			$this->base_path = $path;
		}
		
		/**
		 * LessCode::output()
		 *
		 * @return	CSS code
		 **/
		public function output() {
			$output = "";
			
			foreach ($this->imports as $import) {
				if (is_object($import)) {
					$output .= $import->output();
				} else {
					$output .= $import;
				}
			}

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

			$this->import_path = dirname($path) . '/';

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
				if (substr($data, 0, 8) == "@import ") {
					$data = ltrim(substr($data, 8));
					
					if (in_array($data{0}, array('"', "'", "<"))) {
						$use_base_path = ($data{0} == "<");
						$delim = ($use_base_path ? ">" : $data{0});

						if (($p = strpos($data, $delim, 1)) === false) {
							throw new Exception("Invalid import declaration - missing delimiter '{$delim}'");
						}
						
						$import = substr($data, 1, $p - 1);
						$data = ltrim(substr($data, $p + 1), ' ');
						if ($data{0} != ";" && $data{0} != "\n") {
							throw new Exception("Invalid import declaration - missing ';'");
						}
						$data = ltrim(substr($data, 1));
					} else {
						$use_base_path = false;
						$p1 = strpos($data, ';');
						$p2 = strpos($data, "\n");
						if ($p1 === false && $p2 === false) {
							$import = $data;
							$data = "";
						} elseif ($p1 !== false) {
							$import = rtrim(substr($data, 0, $p1));
							$data = ltrim(substr($data, $p1 + 1));
						} else {
							$import = rtrim(substr($data, 0, $p2));
							$data = ltrim(substr($data, $p2 + 1));
						}
					}
					
					if ($use_base_path) {
						$import = $this->base_path.$import;
					} else {
						$import = $this->import_path.$import;
					}
					if (!file_exists($import)) {
						continue;
					}
					if (substr($import, -4) == '.css') {
						// if it ends with .css, it's a CSS, everything else should be a .less
						$this->imports[] = file_get_contents($import);
					} else {
						if (!file_exists($import) && file_exists($import.'.less')) {
							// if the import path does not exist but there's a $import.less file, use it
							$import .= '.less';
						}
						
						$less = new LessCode();
						foreach ($this->variables as $k => $v)
							$less->setVariable($k, $v);
						$less->parseFile($import);
						
						$this->imports[] = $less;
					}
					continue;
				}
				if ($data{0} == "@") { // variables (maybe..)
					$should_be_variable = (($p = strpos($data, ':')) !== false && strpos(substr($data, 0, $p), '{') === false);

					if ($should_be_variable) {
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
		private $properties = array();
		private $mixins = array();
		private $names = array();
		private $is_mixin = false;
		private $last_if = false;
		private $last_if_success = true; // avoid @else alone to show up

		public function __construct($names, $data, &$parent, &$debug) {
			$this->parent = $parent;
			$this->debug = $debug;
			$names = ltrim($names);

			if (preg_match('/^(\.[^\:]+?)\s*\(\s*(.*)\s*\)\s*$/', $names, $m)) {
				$debug->output("This declaration is a mixin");
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
						if (($pp = strpos($data, '(')) !== false && $pp < $p) {
							$pp = strpos($data, ')', $pp);
							$p = strpos($data, ';', $pp);
						}
					}
					if ($p2 === false || ($p2 !== false && $p2 > $p)) {
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
					
						$this->debug->output("Included mixin '{$prop_name}' '{$prop_params}'");
						$mixin = $this->findMixin($prop_name);
						if ($mixin !== null) {
							$this->debug->output("Mixin found!");
							$mixin->setMixin();
							$this->mixins[] = array(
								'mixin'	=> $mixin,
								'params'=> preg_split('/\s*;\s*/', trim($prop_params))
							);
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
						$this->properties[] = array($prop_name, $prop_value);
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
				
				$this->debug->output("Sub-declaration '{$decl_name}' <".$this->buildSubDeclarationName($decl_name).">");

				$this->declarations[] = new LessDeclaration($this->buildSubDeclarationName($decl_name), $decl_value, $this, $this->debug);
			}
		}
		
		public function output() {
			if ($this->is_mixin)
				return "";
			
			// handle @if and @elseif (they are 99% the same)
			if (preg_match('/^(.+)\s*@(else)?if\((.+)\)$/i', $this->names[0], $m)) {
				$this->parent->setLastIf($m[3]);
				
				$if = array($m[3], true, false);
				if (!lessfunction_if($if, new LessProperty("", $this))) {
					$this->parent->setLastIfSuccess(false);
					return "";
				} else {
					$this->parent->setLastIfSuccess(true);
					$this->names[0] = rtrim($m[1]);
				}
			// handle @else
			} elseif (substr(strtolower($this->names[0]), -6) == ' @else') {
				if ($this->parent->getLastIfSuccess()) {
					return "";
				} else {
					$this->names[0] = rtrim(substr($this->names[0], 0, -5));
				}
			}

			$properties = $this->outputProperties();
			if (strlen($properties) > 0 || count($this->mixins) > 0) {
				$output = implode(", ", $this->names) . " {" . $properties;

				foreach ($this->mixins as $mixin)
					$output .= $mixin['mixin']->outputProperties($mixin['params']);
			
				$output .= " }\n";
			} else {
				$output = "";
			}
			
			//
			// Declaration examples that start with '@':
			//
			// CSS3 @keyframes ; @font-face ; ..
			// Webkit @-webkit-keyframes
			//
			if (count($this->declarations) && substr($this->names[0], 0, 1) == '@') {
				$output .= $this->names[0] . " {\n";
				foreach ($this->declarations as $dec)
					$output .= " ".$dec->output();
				$output .= "}\n";
			} else {
				foreach ($this->declarations as $dec)
					$output .= $dec->output();
			}
			
			return $output;
		}
		
		public function outputProperties($params = false) {
			$output = "";
			
			foreach ($this->properties as $prop) {	// $prop[0] = name ; $prop[1] = value
				if ($params !== false) {
					$vars_saved = $this->variables;
					$this->variables = $this->parameters;

					$n = 0;
					foreach ($this->parameters as $k => $v) {
						if (!isset($params[$n]) || strlen(trim($params[$n])) == 0) {
							if ($v === null) {
								throw new Exception("Invalid mixin call {$this->names[0]}. Missing parameter ".($n+1)." - {$k}");
							}
							$params[$n] = $v;
						}
						$this->variables[$k] = $params[$n];
						
						$n++;
					}
					
					$lprop = new LessProperty($prop[1], $this);
					$output .= " {$prop[0]}: " . $lprop->output() . ";";
					
					$this->variables = $vars_saved;
				} else {
					$lprop = new LessProperty($prop[1], $this);
					$output .= " {$prop[0]}: " . $lprop->output() . ";";
				}
			}
			
			return $output;
		}
		
		public function setMixin($is_mixin = true) {
			$this->is_mixin = $is_mixin;
		}
		
		public function getName() {
			return $this->names[0];
		}
		
		public function getLastIfSuccess() {
			return $this->last_if_success;
		}
		
		public function setLastIf($if) {
			$this->last_if = $if;
		}
		
		public function setLastIfSuccess($success) {
			printf("LAST IF %s SUCCESSFULL '%s'\n", $success ? "WAS" : "WAS NOT", $this->last_if);
			$this->last_if_success = $success;
		}
		
		private function buildSubDeclarationName($decl_name) {
			if (substr($this->names[0], 0, 1) == '@') {
				return $decl_name;
			}
			
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
				
				for ($i = 0; $i < $dec->totalImports(); $i++) {
					$import = $dec->getImport($i);
					if (is_object($import)) {
						$mixin = $import->getDeclaration('.'.$name);
						if ($mixin !== null) {
							return $mixin;
						}
					}
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
		public $part_expr = '(\#[a-f0-9]{3,6}|rgba?\(.+\)|[0-9\.]+[a-z]{2}|[0-9\.]+\%?|)';
		public $units_expr = '(%|e(m|x)|p(x|t|c)|in|ft|(m|c)m|k?Hz|deg|g?rad|gr|m?s)';

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
			$this->value = preg_replace_callback('/@([a-z0-9_\.\-]+)/i', array($this, 'translateVariable'), $this->value);
			
			for ($i = 0; $i < strlen($this->value); $i++) {
				if (substr($this->value, $i, 1) == "(") {
					if (!preg_match('/(\w[\w\d]+)$/', substr($this->value, 0, $i), $m)) {
						continue;
					}
					$fun = $m[1];
					$c = 1;
			
					for ($j = $i + 1; $j < strlen($this->value); $j++) {
						if (substr($this->value, $j, 1) == "(") {
							$c++;
						} elseif (substr($this->value, $j, 1) == ")") {
							$c--;
					
							if ($c == 0) break;
						}
					}
			
					if ($c == 0) {
						$len = $j - $i - 1;
						$lessfun = new LessFunction($fun, substr($this->value, $i + 1, $len), $this);
						$lessfun = $lessfun->parse();
						
						$this->value = substr($this->value, 0, $i - strlen($fun)) . $lessfun . substr($this->value, $j + 1);
						$i += strlen($lessfun) + 1;
					}
				}
			}

			// find nested operations, the ones between ( and )
			// 16 Aug 2010: I need to test this one...
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
			$p1 = $this->checkPart($match[1]);
			$p2 = $this->checkPart($match[3]);
			
			$op = $match[2];
			if (is_array($p1) && is_array($p2)) {
				// operationg between arrays
				if (!isset($p1[3])) $p1[3] = null;
				if (!isset($p2[3])) $p2[3] = null;
				return $this->buildColor($this->doOp($op, $p1[0], $p2[0]), $this->doOp($op, $p1[1], $p2[1]), $this->doOp($op, $p1[2], $p2[2]), $this->doOp($op, $p1[3], $p2[3]));
			} elseif (is_array($p1)) {
				// operationg between array and value
				if (!isset($p1[3])) $p1[3] = null;
				return $this->buildColor($this->doOp($op, $p1[0], $p2), $this->doOp($op, $p1[1], $p2), $this->doOp($op, $p1[2], $p2), $this->doOp($op, $p1[3], $p2));
			} elseif (is_array($p2)) {
				// operationg between value and array
				if (!isset($p2[3])) $p2[3] = null;
				return $this->buildColor($this->doOp($op, $p1, $p2[0]), $this->doOp($op, $p1, $p2[1]), $this->doOp($op, $p1, $p2[2]), $this->doOp($op, $p1, $p2[3]));
			}
			$val = $this->doOp($match[2], $p1, $p2);
			if ($this->unit !== false)
				return $val . $this->unit;
			if ($this->percent !== false)
				return round($val * 100, 2).'%';
			return $val;
		}
		
		public function doOp($op, $p1, $p2) {
			if ($p1 === null || $p2 === null) return null;
			switch ($op) {
				case '*': return $p1 * $p2;
				case '/': return $p1 / $p2;
				case '+': return $p1 + $p2;
				case '-': return $p1 - $p2;
			}
		}
		
		public function buildColor($c1, $c2, $c3, $alpha = null) {
			if ($alpha !== null) {
				// when alpha channel is involved, rgba() needs to be used
				return sprintf("rgba(%d, %d, %d, %d)", $c1, $c2, $c3, $alpha);
			}
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
				
				for ($i = 0; $i < $dec->totalImports(); $i++) {
					$import = $dec->getImport($i);
					if (is_object($import)) {
						if ($import->variableExists($match[1])) {
							$prop = new LessProperty($import->getVariable($match[1]), $this->scope);
							return $prop->output();
						}
					}
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
			} elseif (preg_match('/^rgb\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/', $part, $m)) {
				// color rgb(r, g, b)
				if ($this->unit !== false && $this->unit != 'color') {
					throw new Exception("Mixing units inside property expressions ('{$this->unit}' and 'color')");
				}
				return array($m[1], $m[2], $m[3]);
			} elseif (preg_match('/^rgba\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)$/', $part, $m)) {
				// color rgba(r, g, b, a)
				if ($this->unit !== false && $this->unit != 'color') {
					throw new Exception("Mixing units inside property expressions ('{$this->unit}' and 'color')");
				}
				return array($m[1], $m[2], $m[3], $m[4]);
			} else {
				return $part;
			}
		}
	}
	
	class LessFunction {
		public function __construct($name, $params, &$scope) {
			$this->name = $name;
			$this->params = $params;
			$this->scope = $scope;
			
			for ($i = 0; $i < strlen($this->params); $i++) {
				if (substr($this->params, $i, 1) == "(") {
					if (!preg_match('/(\w[\w\d]+)$/', substr($this->params, 0, $i), $m)) {
						continue;
					}
					$fun = $m[1];
					$c = 1;
			
					for ($j = $i + 1; $j < strlen($this->params); $j++) {
						if (substr($this->params, $j, 1) == "(") {
							$c++;
						} elseif (substr($this->params, $j, 1) == ")") {
							$c--;
					
							if ($c == 0) break;
						}
					}
			
					if ($c == 0) {
						$len = $j - $i - 1;
						$lessfun = new LessFunction($fun, substr($this->params, $i + 1, $len), $this->scope);
						$lessfun = $lessfun->parse();
						
						$this->params = substr($this->params, 0, $i - strlen($fun)) . $lessfun . substr($this->params, $j + 1);
						$i += strlen($lessfun) + 1;
					}
				}
			}
		}
		
		public function parse() {
			if (in_array($this->name, array('rgb', 'rgba'))) {
				return sprintf("%s(%s)", $this->name, $this->params);
			}
			
			$fun_call = 'lessfunction_'.$this->name;
			
			if (!function_exists($fun_call)) {
				return sprintf("%s(%s)", $this->name, $this->params);
			}

			$op = new LessProperty($this->params, $this->scope);
			$data = trim($op->output());
			
			$parenthesis = 0;
			$params = array();
			$s = 0;
			for ($i = 0; $i < strlen($data); $i++) {
				switch (substr($data, $i, 1)) {
					case ",":
						if ($parenthesis == 0) {
							$params[] = trim(substr($data, $s, $i - $s));
							$s = $i + 1;
						}
						break;
					case "(":
						$parenthesis++;
						break;
					case ")":
						$parenthesis--;
						break;
				}
			}
			$params[] = trim(substr($data, $s, $i - $s));

			return $fun_call($params, $this->scope);
		}
	}
?>
