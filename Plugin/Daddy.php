<?php

/**
 * Simply responds to messages addressed to the bot that contain the phrase
 * "Who's your daddy?"
 */
class Phergie_Plugin_Daddy extends Phergie_Plugin_Abstract_Base
{
    /**
     * Checks messages for the question to which it should respond and sends a
     * response when appropriate
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $bot = $this->getIni('nick');
        $text = $this->event->getArgument(1);
        $target = $this->event->getNick();
        if (preg_match('/' . preg_quote($bot) . '\s*[:,>]?\s+?who\'?s y(?:our|a) ([^?]+)\??/iAD', $text, $m)) {
            if ($this->getIni('curses') && mt_rand(0, 5) === 5) {
                $this->doPrivmsg($this->event->getSource(), $target . ': I am your ' . $m[1] . ', bitch!');
            } else {
                $this->doPrivmsg($this->event->getSource(), 'You\'re my ' . $m[1] . ', ' . $target . '!');
            }
        }
    }
}
