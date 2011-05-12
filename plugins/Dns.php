<?php

	class Phergie_Plugin_Dns extends Phergie_Plugin_Abstract {
		
		public function onCommandDns ( $target = null ) {
			
			$channel = $this->getEvent()->getSource();
			$nick = $this->getEvent()->getNick();
			
			// figure out what it is - an IP or a hostname
			if ( long2ip( ip2long( $target ) ) == $target ) {
				$response = gethostbyaddr( $target );
			}
			else {
				$response = gethostbyname( $target );
			}
			
			if ( $response == false ) {
				$this->doPrivmsg( $channel, $nick . ': ' . $target . ' cannot be resolved.' );
			}
			else {
				$this->doPrivmsg( $channel, $nick . ': ' . $target . ' resolved to ' . $response );
			}
			
			unset( $channel, $nick, $target, $resolved );
						
		}
		
		public function onCommandHostIp ( $host = null ) {
			
			$channel = $this->getEvent()->getSource();
			$nick = $this->getEvent()->getNick();
			
			$tmp = file_get_contents('http://api.hostip.info/get_html.php?position=true&ip=' . urlencode( trim( $host ) ) );
			
			if ( !empty( $tmp ) && stripos( $tmp, 'Private Address' ) === false ) {
				$contents = $host . ' -> ' . preg_replace( array( '/\s+/', '/[\r\n]+/' ), array( ' ', ' - ' ), trim ( $tmp ) );
				$this->doPrivmsg( $channel, $nick . ': ' . $contents );
			}
			else {
				$this->doPrivmsg( $channel, $nick . ': Unable to check host IPs.' );
			}
			
			unset( $host, $channel, $nick, $tmp, $contents );
			
		}
		
	}
	

?>
