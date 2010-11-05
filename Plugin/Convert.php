<?php

/**
 * Performs and responds with messages containing the results of conversions
 * between different units.
 */
class Phergie_Plugin_Convert extends Phergie_Plugin_Abstract_Command
{
    /**
     * Returns whether or not the plugin's dependencies are met.
     *
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @see Phergie_Plugin_Abstract_Base::checkDependencies()
     * @return bool TRUE if dependencies are met, FALSE otherwise
     */
    public static function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
    {
        if (!extension_loaded('Dom')) {
            return 'Dom php extension is required';
        }

        return true;
    }
	
	public function onDoCalc($calc)
	{
		$this->onDoConvert($calc);
	}
	
    /**
     * Performs a unit conversion and returns the result in a message.
     *
     * @param string $convert Conversion to perform
     * @return void
     */
    public function onDoConvert($convert)
    {
        $target = $this->event->getNick();

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9b3) Gecko/2008020514 Firefox/3.0b3'
            )
        ));

        $url = 'http://www.google.ca/search?q=' . urlencode($convert) . '&oe=utf-8';
        $contents = @file_get_contents($url, null, $context);
        if (empty($contents)) {
            $this->debug('Empty response');
            return;
        }

        $doc = new DomDocument();
        @$doc->loadHTML($contents);
        $xpath = new DomXPath($doc);
        $result = $xpath->query("//h2[@class='r']/b");
		if ($result->length) {
			$children = $result->item(0)->childNodes;
			$text = '';
				foreach( $children as $child) {
				if ( $child->tagName == 'sup' ) {
					$text.='^';
				}
				$text .= str_replace(array(chr(195), chr(151), chr(194)), array('', '', ''), $child->nodeValue);
			}
			$this->doPrivmsg($this->event->getSource(), $target . ': ' . $text);
        } else {
            $this->doNotice($target, 'Computation error, nothing was returned');
        }
    }
}

