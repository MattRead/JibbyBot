<?php

	class Phergie_Plugin_Twss extends Phergie_Plugin_Abstract {
		
		public function onCommandTwss ( ) {
			
			$this->doPrivmsg( $this->getEvent()->getSource(), 'That\'s what she said!' );
			
		}
		
	}

?>