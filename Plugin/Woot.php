<?php

/**
 */
class Phergie_Plugin_Woot extends Phergie_Plugin_Abstract_Command
{
    /**
     * @return void
     */
    public function onDoWoot()
    {
    	$html = file_get_contents('http://www.woot.com/DefaultMicrosummary.ashx');
	$dat = explode(' : ', $html);
	switch(count($dat)){
		case 2:
			list($price, $item) = $dat;
			$status = $sold = 'some';
			break;
		default:
			list($sold, $price, $item, $status) = array_pad($dat, 4, false);
			break;
		
	}
	$this->doPrivmsg(
		$this->event->getSource(),
		sprintf('Current Woot: %s, %s + $5 shipping (%s) -- http://www.woot.com/', $item, $price, (!$status?"$sold remaining":$status))
	);
	unset($item, $price, $shipping, $html);
    }
}
