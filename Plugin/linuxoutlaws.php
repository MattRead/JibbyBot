<?php

/**
 */
class Phergie_Plugin_LinuxOutlaws extends Phergie_Plugin_Abstract_Command
{
    /**
     * @return void
     */
    public function onDoLatest($user = null)
    {
		$content = file_get_contents('http://feeds.feedburner.com/linuxoutlaws-ogg?format=xml');
        $xml = new SimpleXMLElement($content);
        $item = $xml->channel->item[0];
		$title = $item->title;
		$ogg = $item->enclosure['url'];
        $source = $this->event->getSource();
       	$this->doPrivmsg($source, sprintf("Latest Episode: %s -- %s", $title, $ogg));
    }

    public function onDoCrap()
    {
        $this->doPrivmsg($this->event->getSource(), 'Crap Alert! http://crapalert.org/');
    }

    public function onDoJamendo($term)
    {
        $content = file_get_contents('http://api.jamendo.com/get2/name+id+url/artist/json/?searchquery='.urlencode($term).'&order=searchweight_desc');
        $json = json_decode($content);
        if ( isset($json[0]) ) {
            $name = $json[0]->name;
            $url = $json[0]->url;
            $source = $this->event->getSource();
            $this->doPrivmsg($source, sprintf("Jamedo results for '%s': %s -- %s", $term, $name, $url));
        }
        else {
            $source = $this->event->getSource();
            $this->doPrivmsg($source, sprintf("nothing found for '%s'", $term));
        }
    }

	public function onDoBash() {
		$source = $this->event->getSource();
		$this->doPrivMsg($source, $this->grab_random_quote());
	}

	/* returns a random quote string from bash.org */
	function grab_random_quote() {
		if ( $html = file_get_contents("http://bash.org/?random") ) {
			$doc = new DOMDocument();
			$doc->strictErrorChecking = FALSE;
			@$doc->loadHTML($html);

			$xpath = new DOMXPath($doc);
			$matches = $xpath->query("//p[@class=\"qt\"][1]");

			if($matches->length > 0) {
				return $matches->item(0)->textContent;
			}
			else {
				return 'oops! I blame mtah!';
			}
		} else {
			return 'oops! I blame mtah!';
		}
	}
}
