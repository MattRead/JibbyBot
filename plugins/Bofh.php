<?php

	class Phergie_Plugin_Bofh extends Phergie_Plugin_Abstract {
		
		public function onCommandBofh ( ) {
			
			$data = file_get_contents( 'http://pages.cs.wisc.edu/~ballard/bofh/bofhserver.pl' );
			
			if ( preg_match( '@<br><font size = "\+2">([^<]+)\n@', $data, $m ) ) {
				$this->doPrivmsg( $this->getEvent()->getSource(), 'The cause of the problem is: ' . $m[1] );
			}
			
		}
		
	}

?>