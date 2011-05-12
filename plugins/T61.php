<?php

	class Phergie_Plugin_T61 extends Phergie_Plugin_Abstract {
		
		public function onCommandT61($user = null) {
			
			$source = $this->getEvent()->getSource();
	        $user = $user ? $user : $this->getEvent()->getNick();
	
	        $lines = file_get_contents("http://www.thesixtyone.com/{$user}/");
	        if ( $lines ) {
	        	
				// we use DOM because its parser is loads better than SimpleXML, which chokes on the HTML
				$dom = new DOMDocument('1.0', 'utf-8');
				$dom->validateOnParse = true;
				$dom->loadHTML( $lines );
				
				$xpath = new DOMXPath($dom);
				$time = $xpath->query('//div[@id="listener_last_played"]/div[@class="label"]');
				$song_and_artist = $xpath->query('//div[@id="listener_last_played"]/div[contains(@class, "song")]/a');		// 2 A's - song and artist
				
				// make sure we got the values we expected
				if ( $time->length != 1 || $song_and_artist->length != 2 ) {
					$this->doPrivmsg( $source, sprintf( "Can't find status for %s on t61", $user ) );
				}
				else {
					// pull out our values
					$time = trim( $time->item(0)->nodeValue );
					$song = $song_and_artist->item(0)->nodeValue;
					$artist = $song_and_artist->item(1)->nodeValue;
					
					$message = "%s, %s %s %s by %s";
					
					$this->doPrivmsg( $source, sprintf( "%s %s %s by %s", $user, $time, $song, $artist ) );
				}
				
			}
	        else {
	        	$this->doPrivmsg($source, sprintf("Can't find status for %s on t61", $user));
			}
			
		}
		
	}

?>