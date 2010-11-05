<?php

/**
 * Detects and responds to requests for current weather conditions in a
 * particular location using data from a web service. Requires registering
 * with weather.com to obtain authentication credentials, which must be
 * stored in the configuration settings partner_id and license_key for the
 * plugin to function.
 *
 * @see http://www.weather.com/services/xmloap.html
 */
class Phergie_Plugin_Weather extends Phergie_Plugin_Abstract_Command
{
    /**
     * Partner ID for web service authentication
     *
     * @var string
     */
    protected $partnerId;

    /**
     * License Key for web service authentication
     *
     * @var string
     */
    protected $licenseKey;

    /**
     * Obtains configuration settings used for web service authentication.
     *
     * @return void
     */
    public function onInit()
    {
        $this->partnerId = $this->getPluginIni('partner_id');
        $this->licenseKey = $this->getPluginIni('license_key');
    }

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
        $partnerId = $client->getIni('weather.partner_id');
        $licenseKey = $client->getIni('weather.license_key');

    	$errors = array();

    	if (!$licenseKey) {
            $errors[] = 'Ini setting weather.license_key must be filled-in';
    	}
    	if (!$partnerId) {
            $errors[] = 'Ini setting weather.partner_id must be filled-in';
        }

        return empty($errors) ? true : $errors;
    }

    public function onDoWeather($where)
    {
        $target = $this->event->getNick();

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 3
            )
        ));

        $feed = 'http://xoap.weather.com/search/search?where=' . urlencode($where);
        $contents = @file_get_contents($feed, null, $context);
        if (!$contents) {
            return;
        }

        $xml = new SimpleXMLElement($contents);
        if (count($xml->loc) != 0) {
            $where = $xml->loc[0]['id'];
            $feed = 'http://xoap.weather.com/weather/local/' . $where . '?cc=*&link=xoap&prod=xoap&par=' . $this->partnerId . '&key=' . $this->licenseKey;
            $contents = @file_get_contents($feed, null, $context);
            if (!$contents) {
                return;
            }
            $xml = new SimpleXMLElement($contents);
            $weather = 'Weather for ' . $xml->loc->dnam . ' :: ';
            $weather .= 'Current temperature: ' . $xml->cc->tmp . $xml->head->ut . '/';
            if ($xml->head->ut == 'F') {
                $celsius = round(((($xml->cc->tmp - 32) * 5) / 9));
                $weather .= $celsius . 'C/' . ($celsius + 273) . 'K, ';
            } else {
                $weather .= round(((($xml->cc->tmp * 9) / 5) + 32)) . 'F/';
                $weather .= ($xml->cc->tmp + 273) . 'K, ';
            }
            $weather .= 'Relative Humidity: ' . $xml->cc->hmid . '%, ';
            $weather .= 'Current conditions: ' . $xml->cc->t . ', ';
            $weather .= 'Last update: ' . $xml->cc->lsup;
        } else {
            $weather = 'No results for that location.';
        }

        $this->doPrivmsg($this->event->getSource(), $target . ': ' . $weather);
    }
}
