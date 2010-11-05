<?php

/**
 */
class Phergie_Plugin_DDate extends Phergie_Plugin_Abstract_Command
{
    /**
     * @return void
     */
    public function onDoDDate()
    {
    	try {
			$this->doPrivmsg(
				$this->event->getSource(),
				shell_exec('ddate')
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg(
				$this->event->getSource(),
				'Hail ARIS!'
			);
		}
    }
}
