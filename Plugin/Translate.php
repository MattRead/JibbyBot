<?php

/**
 * Translates the given text using Google Translate into the lang supplied.
 * Also detects lang if none provided.
 * 
 * @example
 * from en to fr:
 * 	t "string to translate" en fr
 * auto detect to default lang:
 *  t "bonjour phergie"
 * auto detect to zh:
 *  t "bonjour phergie" zh
 */
class Phergie_Plugin_Translate extends Phergie_Plugin_Abstract_Command
{
	/**
	 * Google translate API entry point
	 *
	 * @var string
	 */
	private $url = 'http://ajax.googleapis.com/ajax/services/language/translate?v=1.0&q=%s&langpair=%s%%7C%s';
	
	/**
	 * Holder for the default Lang in ini
	 *
	 * @var string
	 */
	private $lang;
	
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
		$errors = array();

		if (!extension_loaded('JSON')) {
			$errors[] = 'JSON php extension is required';
		}

		return empty($errors) ? true : $errors;
	}
	
	/**
	 * Initializes the default lang.
	 *
	 * @return void
	 */
	public function onInit()
	{
		$lang = $this->getPluginIni('default_lang');
		if ($lang == null) {
            $this->lang = 'en';
        } else {
            $this->lang = strval($lang);
        }
	}
	
    /**
	 * translate given text using google translate service.
	 * 
     * @return void
     */
    public function onDoT($text)
    {
		// if it doesn't match the format, get out
		if (!preg_match('@"([^"]+)" ?([a-z\-_]*) ?([a-z\-_]*)@is', $text, $t)) return;
		
		$string = urlencode($t[1]);
		
		if (!$t[3]) {
			$to = $t[2] ? $t[2] : $this->lang;
			$from = '';
		}
		else {
			$to = $t[3];
			$from = $t[2];
		}
		
		$json = json_decode(file_get_contents(sprintf($this->url, $string, $from, $to)));
		
		if ($json->responseStatus == 200) {
			if (!$from) {
				$from = $json->responseData->detectedSourceLanguage;
			}
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf('%s: %s (%s -> %s)', $this->event->getNick(), $json->responseData->translatedText, $from, $to)
			);
		}
		elseif ($json->responseStatus == 400) {
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf('%s: %s', $this->event->getNick(), $json->responseDetails)
			);
		}
		// cleanup after we're done
		unset($t, $json);
    }
}
