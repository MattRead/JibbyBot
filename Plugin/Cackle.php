<?php
class Phergie_Plugin_Cackle extends Phergie_Plugin_Abstract_Command
{
    public function onDoCackle()
    {
        $this->doPrivmsg($this->event->getSource(), "MWAHAHAHAHAAA!!");
    }
}
