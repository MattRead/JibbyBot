<?php

	class Phergie_Plugin_Head extends Phergie_Plugin_Abstract {
		
		public function onCommandHead ( $url, $header = 'status' ) {
			
			$channel = $this->getEvent()->getSource();
			$nick = $this->getEvent()->getNick();
			
			// if no protocol is included, assume http://
			if ( strpos( $url, '://' ) === false ) {
				$url = 'http://' . $url;
			}
			
			$headers = get_headers( $url, true );
			$headers = array_change_key_case( $headers, CASE_LOWER );
			
			if ( $header == 'status' ) {
				$real_header = 0;
			}
			else {
				$real_header = $header;
			}
						
			if ( array_key_exists( strtolower( $real_header ), $headers ) ) {
				$message = $nick . ': ' . $header . ' == ' . $headers[ strtolower( $real_header ) ];
			}
			else {
				$message = $nick . ': Header ' . $header . ' not found.';
			}
			
			$this->doPrivmsg( $channel, $message );
			
		}
		
	}

?>