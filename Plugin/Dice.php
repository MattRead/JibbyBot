<?php

require dirname(__FILE__) . '/Dice/calc.php';

/**
 * Checks incoming requests for dice/roll requests and processes the message for
 * the dice arguments and reponds with a message containing the dice results.
 */
class Phergie_Plugin_Dice extends Phergie_Plugin_Abstract_Command
{

    /**
     * Forwards the dice/roll commands onto a central handler.
     *
     * @return void
     */
    public function onDoRoll($message)
    {
        $source = $this->event->getSource();
        $target = $this->event->getNick();

        $calc = new Calc($message);
        $this->doPrivmsg($source, $target . ': ' . $calc->infix() . ' => ' . $calc->calc());
    }

    /**
     * Forwards the dice/roll commands onto a central handler.
     *
     * @return void
     */
    public function onDoDice($message)
    {
        $source = $this->event->getSource();
        $target = $this->event->getNick();

        $calc = new Calc($message);
        $this->doPrivmsg($source, $target . ': ' . $calc->infix() . ' => ' . $calc->calc());
    }

    /**
     * Proccesses incoming CTCP request for the CTCP request DICE or ROLL and
     * returns the dice results.
     *
     * @return void
     */
    public function onCtcp()
    {
        $source = $this->event->getSource();
        $ctcp = strtoupper($this->event->getArgument(1));
        list($ctcp, $message) = array_pad(explode(' ', $ctcp, 2), 2, null);

        $calc = new Calc($message);
        if (($ctcp == 'DICE' || $ctcp == 'ROLL') and $result = $calc->calc()) {
            $this->doCtcpReply($source, $ctcp, $calc->infix() . ' => ' . $result);
        }
    }
}
