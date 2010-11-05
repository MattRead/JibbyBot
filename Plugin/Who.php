<?php

/**
 */
class Phergie_Plugin_Who extends Phergie_Plugin_Abstract_Command
{
    /**
     * @return void
     */
    public function onDoWho( $nick )
    {
    	$xml = trim(file_get_contents('http://www.habariproject.org/en/upx/'.$nick));
		$xml = new simpleXMLElement($xml);
		if ( $xml->getName() == 'error' ) {
			$this->doPrivmsg(
           	 	$this->event->getSource(),
           		 sprintf('%s', $xml)
        	);
		}
		else {
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf('%s is %s from %s', $nick, $xml->name, $xml->blog)
			);
		}
		unset($xml);
    }
}
