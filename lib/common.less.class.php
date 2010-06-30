<?php	
	class LessDebug {
		public function output($text) {
			//printf("[debug] %s\n", $text);
		}
	}

	class LessScope {
		protected $parent = null;
		protected $declarations = array();
		protected $variables = array();
		
		public function &getParent() {
			return $this->parent;
		}
		
		public function variableExists($name) {
			return isset($this->variables[$name]);
		}
		
		public function getVariable($name) {
			return $this->variables[$name];
		}
		
		public function getDeclaration($name) {
			foreach ($this->declarations as $dec) {
				if ($dec->getName() == $name)
					return $dec;
			}
			return null;
		}
	}
?>
