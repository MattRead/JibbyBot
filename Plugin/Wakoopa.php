<?php

/**
 * 
 */
class Phergie_Plugin_Wakoopa extends Phergie_Plugin_Abstract_Command
{
	/**
	 * 
	 * @return void
	 */
	public function onDoWakoopa($user = null)
	{
		$user = $user ? $user : $this->event->getNick();
		if ($file = file_get_contents("http://api.wakoopa.com/{$user}/software.xml?limit=2&sort=last_active_at")) {
			$xml = new simplexmlelement($file);
            $software = $xml->software[0];
			$this->doPrivmsg(
                $this->event->getSource(),
                sprintf(
                    '%s last used %s: %s - %s',
                    $user, $software->name, substr($software->description, 0, 100), $software->url
                )
            );
        }
        else {
            $this->doPrivmsg($this->event->getSource(), "Can't find status for $user on Wakoopa");
        }
	}
}
