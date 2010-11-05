<?php

/**
 * Handles switching to alternate nicks in cases where the primary nick is not
 * available for use.
 */
class Phergie_Plugin_Altnick extends Phergie_Plugin_Abstract_Base
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Index of the last alternate nick that the bot attempted to use
     *
     * @var int
     */
    protected $index;

    /**
     * Initializes instance variables.
     *
     * @return void
     */
    public function onInit()
    {
        $this->index = -1;
    }

    /**
     * Switches to alternate nicks as needed when nick collisions occur.
     *
     * @return void
     */
    public function onResponse()
    {
        if ($this->event->getCode() == Phergie_Event_Response::ERR_NICKNAMEINUSE) {
            $this->index++;
            $altnick = $this->getPluginIni('altnick' . $this->index);
            if ($altnick) {
                $this->doNick($altnick, true);
                $this->setIni('nick', $altnick);
            } else {
                $this->doQuit('All specified nicks are in use');
            }
        }
    }
}
