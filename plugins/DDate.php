<?php

	class Phergie_Plugin_DDate extends Phergie_Plugin_Abstract {
		
		public function onCommandDdate ( ) {
			
			$date = shell_exec('ddate');
			
			$this->doPrivmsg( $this->getEvent()->getSource(), $date );
			
		}
		
	}

?>