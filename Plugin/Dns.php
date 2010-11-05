<?php

/**
 * Parses incoming messages for requests to perform a DNS or reverse DNS
 * lookup on a given host name or IP address, performs the lookup, and
 * responds with a message containing the lookup result.
 */
class Phergie_Plugin_Dns extends Phergie_Plugin_Abstract_Command
{
    /**
     * Processes a DNS or reverse DNS lookup request.
     *
     * @param string $arg Host or IP address to look up
     * @return void
     */
    protected function processRequest($arg)
    {
        $source = $this->event->getSource();
        $target = $this->event->getNick();

        if (preg_match('/^(?:[0-9]{1,3}\.){3}[0-9]{1,3}$/', $arg)) {
            $resolved = gethostbyaddr(long2ip(ip2long($arg)));
        } elseif (preg_match('/^(?:[a-z0-9]+\.)+[a-z]{2,6}$/', $arg)) {
            $resolved = gethostbyname($arg);
        } else {
        	return;
        }

        if (!isset($resolved)) {
            $this->doPrivmsg($source, $target . ': ' . $arg . ' cannot be resolved.');
        } else {
            $this->doPrivmsg($source, $target . ': ' . $arg . ' resolved to ' . $resolved);
        }
    }

    /**
     * Forwards DNS lookup requests onto a central handler.
     *
     * @param string $host Host to look up
     * @return void
     */
    public function onDoDns($host)
    {
        $this->processRequest($host);
    }

    /**
     * Forwards reverse DNS lookup requests onto a central handler.
     *
     * @param string $ip IP address to look up
     * @return void
     */
    public function onDoRevdns($ip)
    {
        $this->processRequest($ip);
    }

    /**
     * Intercepts, processes, and responds with the result of prototype lookup
     * requests.
     *
     * @param string $function Name of the function to look up
     * @return void
     */
    public function onDoHostIp($host)
    {
        $target = $this->event->getNick();

        $tmp = file_get_contents('http://api.hostip.info/get_html.php?position=true&ip=' . urlencode(trim($host)));
        if (!empty($tmp) && stripos($tmp, 'Private Address') === false) {
            $contents = $host . ' -> ' . preg_replace(array('/\s+/', '/[\r\n]+/'), array(' ', ' - '), trim($tmp));
            $this->doPrivmsg($this->event->getSource(), $target . ': ' . $contents);
        }
        unset($host, $tmp, $contents);
    }

}
