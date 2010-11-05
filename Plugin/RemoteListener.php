<?php

require_once(dirname(__FILE__) . '/RemoteListener/RemoteListenerMessage.class.php');
/**
 * Phergie plugin that listens to a UDP port for messages to supply to a room.
 *
 * @author Evan Fribourg <evan@dotevan.com>
 * @version $Id: RemoteListener.php 32 2010-01-03 23:54:36Z evan $
 */
class Phergie_Plugin_RemoteListener extends Phergie_Plugin_Abstract_Base {

	/**
	 * Indicates that a local directory is required for this plugin
	 *
	 * @var bool
	 */
	protected $needsDir = true;

	/**
	 * Host/IP to bind to
	 *
	 * @var String
	 */
	protected $host   = '0.0.0.0';

	/**
	 * Port to bind to
	 * @var Integer (less than 65535)
	 */
	protected $port   = 30601;

	/**
	 * Channel to post the messages in.
	 *
	 * @var String
	 */
	protected $channel = '';

	/**
	 * Holds the server pointer that we're listening on.
	 *
	 * @var Resource
	 */
	protected $server = null; // holds the server pointer that we're listening on

	/**
	 * The Phergie system will call this method when we're successfully connected
	 * to the IRC server. (There's no point in creating the socket if we can't connect!)
	 *
	 * @return null
	 */
	public function onConnect() {
		// process channel
		$channel = $this->getPluginIni('channel');
		if(!empty($channel)) {
			$this->channel = $channel;
		} else {
			$this->debug('Invalid channel supplied in remotelistener.channel ini');
			$this->enabled = false;
		}

		// process host
		$host = $this->getPluginIni('host');
		if($host != $this->host) {
			$this->host = $host;
		}

		// process port
		$port = intval($this->getPluginIni('port'));
		if($port > 1024) {
			$this->port = $port;
		}

		// open the UDP port
		$this->server = stream_socket_server("udp://{$this->host}:{$this->port}", $errno, $errstr, STREAM_SERVER_BIND);
		if(!$this->server) {
			$this->debug("({$errno}) {$errstr}");
			// something went wrong. Disable ourselves.
			$this->enabled = false;
		}

		socket_set_blocking($this->server, false);
	}

	public function onTick() {
		// if we were disabled, then there's nothing to do
		if(!$this->enabled) return;

		// read data from the stream
		$buffer = @stream_socket_recvfrom($this->server, 512);

		// if we've received data, do something with it!
		if(!empty($buffer)) {
			$msg = @unserialize($buffer);
			if($msg instanceof RemoteListenerMessage) {
				$channel = $msg->channel ? $msg->channel : $this->channel;
				$this->doAction($channel, "{$msg}");
			} else {
				$this->debug("Received unknown message type: {$buffer}");
			}
		}
	}
}

