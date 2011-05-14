<?php

	class Phergie_Plugin_WolframAlpha extends Phergie_Plugin_Abstract {
		
		public function onCommandWa ( $question ) {
			
			$url = 'http://tumbolia.appspot.com/wa/' . urlencode( $question );
			
			$response = file_get_contents( $url );
			
			$this->doPrivmsg( $this->getEvent()->getSource(), $response );
			
		}
		
	}

?>