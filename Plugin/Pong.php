<?php

/**
 * Responds to tests from the server to ensure the client connection is still
 * active.
 */
class Phergie_Plugin_Pong extends Phergie_Plugin_Abstract_Base
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Responds to PING requests from the server to indicate that the client
     * connection is still active.
     *
     * @return void
     */
    public function onPing()
    {
        // Check for a handshake hash, otherwise its a server ping request
        if (!$this->event->getArgument(1)) {
            $this->doPong($this->event->getArgument(0));
        }
    }
}
