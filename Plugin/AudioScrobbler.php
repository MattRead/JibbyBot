<?php

/**
 */
class Phergie_Plugin_AudioScrobbler extends Phergie_Plugin_Abstract_Command
{
	/**
	 * Holder for the Last.FM API key ini setting
	 *
	 * @var string
	 */
	private $lastfm_api_key;
	
	/**
	 * Last.FM API entry point
	 *
	 * @var string
	 */
	private $lastfm_url = 'http://ws.audioscrobbler.com/2.0/';
	
	/**
	 * Holder for the Libre.FM API key ini setting
	 *
	 * @var string
	 */
	private $librefm_api_key;
	
	/**
	 * Libre.FM API entry point
	 *
	 * @var string
	 */
	private $librefm_url = 'http://alpha.dev.libre.fm/2.0/';
	
	/**
	 * Scrobbler query string for user.getRecentTracks
	 *
	 * @var string
	 */
	private $query = '?method=user.getrecenttracks&user=%s&api_key=%s';
	
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

		if (!extension_loaded('simplexml')) {
			$errors[] = 'SimpleXML php extension is required';
		}

		return empty($errors) ? true : $errors;
	}
	
	/**
	 * Initializes the API keys.
	 *
	 * @return void
	 */
	public function onInit()
	{
		$this->lastfm_api_key = $this->getPluginIni('lastfm_api_key');
		$this->librefm_api_key = $this->getPluginIni('librefm_api_key');
	}
	
	/**
	 * Command function to get users status on last.fm
	 * 
	 * @return void
	 */
	public function onDoLastFM($user = null)
	{
		if ($this->lastfm_api_key) {
			$scrobbled = $this->getScrobbled($user, $this->lastfm_url, $this->lastfm_api_key);
			$this->doPrivmsg($this->event->getSource(), $scrobbled);
		}
	}

	/**
	 * Command function to get users status on libre.fm
	 * 
	 * @return void
	 */
	public function onDoLibreFM($user = null)
	{
		if ($this->librefm_api_key) {
			$scrobbled = $this->getScrobbled($user, $this->librefm_url, $this->librefm_api_key);
			$this->doPrivmsg($this->event->getSource(), $scrobbled);
		}
	}

	/**
	 * Simple Scrobbler API function to get recent track formatted in string
	 * 
	 * @param string $user The user name to lookup
	 * @param string $url The base URL of the scrobbler service
	 * @param string $api_key The scrobbler service api key
	 * @return string A formatted string of the most recent track played.
	 */
	public function getScrobbled($user, $url, $api_key)
	{
		$user = $user ? $user : $this->event->getNick();
		$url = sprintf($url . $this->query, urlencode($user), urlencode($api_key));
		
		$response = file_get_contents($url);
		try {
			$xml = new SimpleXMLElement($response);
		}
		catch (Exception $e) {
			return 'Can\'t find status for ' . $user;
		}
		
		if ($xml->error) {
			return 'Can\'t find status for ' . $user;
		}
		
		$recenttracks = $xml->recenttracks;
		$track = $recenttracks->track[0];
		if (isset($track['nowplaying'])) {
			$msg = sprintf("%s is listening to %s by %s", $recenttracks['user'], $track->name, $track->artist);
		}
		else {
			$msg = sprintf("%s, %s was listening to %s by %s",  $track->date, $recenttracks['user'], $track->name, $track->artist);
		}
		if ($track->streamable == 1) {
			$msg .= ' - ' . $track->url;
		}
		return $msg;
	}
}
