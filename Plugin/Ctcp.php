<?php

/**
 * Responds to various CTCP requests sent by the server and the users
 */
class Phergie_Plugin_Ctcp extends Phergie_Plugin_Abstract_Base
{
    public function onTime()
    {
        $source = $this->event->getSource();

        // Send out the default time reply if the second argument is empty
        $this->doTimeReply($source);
    }

    public function onVersion()
    {
        $source = $this->event->getSource();

        // Send out the default version reply if the second argument is empty
        $this->doVersionReply($source);
    }

    public function onPing()
    {
        $source = $this->event->getSource();

        // Check for a handshake hash, otherwise its a server ping request
        $handshake = $this->event->getArgument(1);
        if ($handshake) {
            $this->doPingReply($source, $handshake);
        }
    }

    public function onCtcp()
    {
        $source = $this->event->getSource();
        // The CTCP Request
        $ctcp = strtoupper($this->event->getArgument(1));

        // Uncaught PING requests
        if ($ctcp == 'PING') {
            $this->doCtcpReply($source, 'pong');
        }
        // SOURCE Request, reply with the path to Phergie's SVN
        else if ($ctcp == 'SOURCE') {
            $this->doCtcpReply($source, 'source', 'svn2.assembla.com:/svn/phergie/trunk/Phergie/:');
        }
        //FINGER Request, reply with the not's real name
        else if ($ctcp == 'FINGER') {
            $name = $this->getIni('nick');
            $realname = $this->getIni('realname');
            $username = $this->getIni('username');

            $finger = (!empty($realname) ? $realname : $name) . ' (' . (!empty($username) ? $username : $name) . ')';
            $this->doCtcpReply($source, 'finger', $finger);
        }
        // UPTIME Request, reply with the bot's uptime
        else if ($ctcp == 'UPTIME') {
            $uptime = $this->getCountdown(time() -$this->getStartTime());
            $this->doCtcpReply($source, 'uptime', $uptime);
        }
    }
}
?>
