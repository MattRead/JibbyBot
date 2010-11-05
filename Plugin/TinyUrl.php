<?php

/**
 * Returns shortened versions of URLs
 *
 * Has an utility method accessible through Phergie_Plugin_TinyUrl::get('http://foo..')
 */
class Phergie_Plugin_TinyUrl extends Phergie_Plugin_Abstract_Cron
{
    /**
     * Url cache
     */
    protected static $cache = array();

    /**
     * Override cron delay
     */
    protected $defaultDelay = 86400;

    /**
     * Cron runner
     */
    protected function run()
    {
        // cleanup urls that haven't been used in the last 24h
        foreach(self::$cache as $url => $data) {
            if ($data['lastUsage'] < (time() - 86400)) {
                unset(self::$cache[$url]);
            }
        }
    }

    public function onDoTinyurl($url)
    {
        if (!$this->getPlugin('Url') || !$this->getPlugin('Url')->enabled) {
            $this->doPrivmsg($this->event->getSource(), $this->event->getNick() .': '.self::get($url, true));
        }
    }

    /**
     * Returns a TinyURL version of a given URL.
     *
     * @param string $url URL to convert
     * @return string
     */
    public static function get($url, $force = false)
    {
        if (!$force && strlen($url) <= 30) {
            return $url;
        }

        if (isset(self::$cache[$url])) {
            self::$cache[$url]['lastUsage'] = time();
            return self::$cache[$url]['tinyUrl'];
        }

        $tiny = $url;
        $opts = array(
            'http' => array(
                'timeout' => 4,
                'method' => 'POST',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'content' => http_build_query(array(
                    'url' => $url
                ))
            )
        );

        $context = stream_context_create($opts);
        $tiny = @file_get_contents('http://tinyurl.com/api-create.php', false, $context);
        if (empty($tiny)) {
            $tiny = $url;
        }

        self::$cache[$url] = array
        (
            'tinyUrl' => $tiny,
            'lastUsage' => time()
        );

        return $tiny;
    }
}
