<?php

	class Phergie_Plugin_Cackle extends Phergie_Plugin_Abstract {
		
		public function onCommandCackle ( ) {
			
			$this->doPrivmsg($this->getEvent()->getSource(), "MWAHAHAHAHAAA!!");
			
		}
		
	}

?>