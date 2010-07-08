<?php
	/**
	 * Less Debug Utility
	 *
	 * Not done yet, for now your just (un)comment the printf
	 * if you want debug or not
	 *
	 **/
	class LessDebug {
		public function output($text) {
			//printf("[debug] %s\n", $text);
		}
	}

	/**
	 * LessScope
	 *
	 * The base of any scope for now. It has methods used on the root
	 * elements or on nested elements.
	 *
	 **/
	class LessScope {
		protected $parent = null;
		protected $declarations = array();
		protected $variables = array();
		protected $imports = array();
		
		public function &getParent() {
			return $this->parent;
		}
		
		public function variableExists($name) {
			return isset($this->variables[$name]);
		}
		
		public function getVariable($name) {
			return $this->variables[$name];
		}
		
		public function setVariable($name, $value) {
			$this->variables[$name] = $value;
		}
		
		public function getDeclaration($name) {
			foreach ($this->declarations as $dec) {
				if ($dec->getName() == $name)
					return $dec;
			}
			return null;
		}
		
		public function totalImports() {
			return count($this->imports);
		}
		
		public function &getImport($n) {
			return $this->imports[$n];
		}
	}
?>
