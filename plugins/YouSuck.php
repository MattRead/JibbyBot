<?php

	class Phergie_Plugin_YouSuck extends Phergie_Plugin_Abstract {
		
		public function onCommandYou ( $suck ) {
			
			if ( preg_match( '@.*(go to|suck|fuck|shit|dick).*@', $suck ) ) {
				$chan = $this->getEvent()->getSource();
				$nick = $this->getEvent()->getNick();
				$this->doPrivmsg( $chan, $nick . ', screw you!' );
			}
			
		}
		
	}

?>