<?php

	class Phergie_Plugin_Habari extends Phergie_Plugin_Abstract {
		
		public function onCommandGuid ( ) {
			
			$guid = array();
			for ( $i = 0; $i < 16; $i++ ) {
				$guid[] = mt_rand( 0, 255 );
			}
			
			$guid[8] = ( $guid[8] & 0x3f ) | 0x80;
			$guid[6] = ( $guid[6] & 0x0f ) | 0x40;
			
			// convert to hex
			$hex = '';
			
			for ( $i = 0; $i < 16; $i++ ) {
				if ( $i == 4 || $i == 6 || $i == 8 || $i == 10 ) {
					$hex .= '-';
				}
				$hex .= sprintf( '%02x', $guid[ $i ] );
			}
			
			
			$this->doPrivmsg( $this->getEvent()->getSource(), $this->getEvent()->getNick() . ': ' . $hex );
		}
		
		public function onCommandUuid ( ) {
			$this->onCommandGuid();
		}
		
		public function onCommandRev ( $extras = '' ) {
			
			if ( $extras == 'extras' ) {
				$url = 'habari-extras';
				$name = 'Extras';
			}
			else {
				$url = 'habari';
				$name = 'Core';
			}
			
			$url = 'http://svn.habariproject.org/' . $url;
			
			$info = shell_exec( 'svn info ' . $url );
			
			preg_match( '/Revision: (\d+)/i', $info, $m );
			
			$this->doPrivmsg( $this->getEvent()->getSource(), $this->getEvent()->getNick() . ': Current ' . $name . ' Revision: ' . $m[1] );
			
		}
		
		public function onCommandTranslation ( $language ) {
			
			$chan = $this->getEvent()->getSource();
			$nick = $this->getEvent()->getNick();
			
			$data = file_get_contents( 'https://translations.launchpad.net/habari/trunk/+pots/habari' . urlencode( $language ) . '/+index' );
			
			preg_match( '#To do:</b>.*?(\d+)#s', $data, $m );
			
			if ( !isset( $m[1] ) ) {
				$msg = "$nick, Could not find language '$language'";
			}
			else {
				$todo = $m[1];
				$msg = "$nick, $language still needs $todo strings translated. Go help https://translations.launchpad.net/habari/trunk/+pots/habari/$language/+translate";
			}
			
			$this->doPrivmsg( $chan, $msg );
			
		}
		
	}

?>