<?php

/**
 */
class Phergie_Plugin_Ruby extends Phergie_Plugin_Abstract_Command
{
    /**
     * @return void
     */
    public function onDoRuby($cmd)
    {
    	$cmd = 'ruby -e ' . escapeshellarg($cmd);
		$this->doPrivmsg(
			$this->event->getSource(),
			shell_exec($cmd)
		);
    }
    public function onDoPy($cmd)
    {
        $cmd = 'python -c ' . escapeshellarg($cmd) . ' 2>&1';
        $this->doPrivmsg(
            $this->event->getSource(),
            shell_exec($cmd)
        );
    }

}
