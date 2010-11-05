<?php

/**
 */
class Phergie_Plugin_Head extends Phergie_Plugin_Abstract_Command
{
	/**
	 * @return void
	 */
	public function onDoHead($url, $header = 'status')
	{
		$url = preg_match('@^(http|https|ftp|ftps|sftp)://.*@i', $url) ? $url : 'http://' . $url;
		$headers = get_headers($url, 1);
		$headers = array_change_key_case($headers, CASE_LOWER);
		$header = strtolower($header);
		// fake the "status" header
		$headers['status'] = $headers[0];

		if ( array_key_exists($header, $headers) ) {
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf('%s: %s: %s', $this->event->getNick(), is_array($header)?implode(' ' , $header):$header, $headers[$header])
			);
		}
		else {
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf('%s: %s header not found', $this->event->getNick(), $header)
			);
		}

	}
}
