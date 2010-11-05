<?php

/**
 * Monitors incoming messages for requests to perform a prototype lookup for
 * a given PHP function, performs the lookup, and responds with a message
 * containing the retrieved prototype.
 *
 * @todo Add garbage collection for the cache
 */
class Phergie_Plugin_Php extends Phergie_Plugin_Abstract_Command
{
    /**
     * Mapping of function names to corresponding prototypes to serve as an
     * in-memory cache
     *
     * @var array
     */
    protected $cache;

    /**
     * Set to true by the custom error handler if an HTTP error code has been received
     *
     * @var boolean
     */
    protected $errorStatus = false;

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
        if (!self::staticPluginLoaded('TinyUrl', $client, $plugins)) {
            return 'TinyUrl plugin must be enabled';
        }

        return true;
    }

    /**
     * Initializes an internal cache used to store prototypes obtained from
     * lookups so as to eliminate the need to perform repeat lookups.
     *
     * @return void
     */
    public function onInit()
    {
        $this->cache = array();
    }

    /**
     * Cleans up the given string and decodes and transliterates a UTF-8 string
     * into corresponding ASCII characters
     *
     * @return string
     */
    private function decode($str)
    {
        $str = str_replace('—', '-', $str);
        $str = trim(preg_replace(array('/<[^>]+>/ms', '/\s+/ms'), array( '', ' ' ), $str));
        return $this->decodeTranslit($str);
    }

    /**
     * Intercepts, processes, and responds with the result of prototype lookup
     * requests.
     *
     * @param string $function Name of the function to look up
     * @return void
     */
    public function onDoPhp($function)
    {
        $name = preg_replace(array( '/[\s\(\);]*$/', '/\s+/', '/_/', '/\s/'), array('', ' ', '-', '-'), $function);
        $name = trim(strtolower($name));
        if (!isset($this->cache[$name])) {
            $tmp = file_get_contents('http://php.net/manual/en/function.' . $name . '.php');
            if (!$this->errorStatus && strpos($tmp, '<p class="refpurpose">') !== false) {
               $contents = '[ ' . Phergie_Plugin_TinyUrl::get('http://php.net/' . $name) . ' ] ';
               if (preg_match('/<div class="methodsynopsis dc-description">(.*?)<\/div>/mis', $tmp, $m)) {
                  $contents .= $this->decode($m[1]);
               }
               if (preg_match('/<p class="refpurpose">(.*?)<\/p>/mis', $tmp, $m)) {
                  $m = explode('-', $this->decode($m[1]), 2);
                  $contents .= ' ' . trim($m[1]);
               }
               $contents = trim(str_replace(' ,', ',', $contents));
               unset($tmp, $m);
            } else {
                $this->errorStatus = false;
                $contents = false;
            }
            $this->cache[$name] = $contents;
        }
        if (!empty($this->cache[$name])) {
            $this->doPrivmsg($this->event->getSource(), $this->cache[$name]);
        }
    }

    /**
     * Custom error handler meant to handle 404 errors and such
     */
    public function onPhpError($errno, $errstr, $errfile, $errline)
    {
        if ($errno === E_WARNING) {
            // Check to see if there was HTTP warning while connecting to the site
            if (preg_match('{HTTP/1\.[01] ([0-9]{3})}i', $errstr, $m)) {
                $this->errorStatus = true;
                $this->debug('PHP Warning:  ' . $errstr . 'in ' . $errfile . ' on line ' . $errline);
                return true;
            // Safely ignore these SSL warnings so they don't appear in the log
            } else if (stripos($errstr, 'failed to open stream') !== false ||
                       stripos($errstr, 'HTTP request failed') !== false ||
                       stripos($errstr, 'unable to connect to') !== false) {
                $this->errorStatus = true;
                $this->debug('PHP Warning:  ' . $errstr . 'in ' . $errfile . ' on line ' . $errline);
                return true;
            }
        }
        return false;
    }
}
