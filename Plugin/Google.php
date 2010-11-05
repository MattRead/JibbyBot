<?php

/**
 * Returns the first result of a google search
 */
class Phergie_Plugin_Google extends Phergie_Plugin_Abstract_Command
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
        $errors = array();

        if (!self::staticPluginLoaded('TinyUrl', $client, $plugins)) {
            $errors[] = 'TinyUrl plugin must be enabled';
        }
        if (!self::staticPluginLoaded('Url', $client, $plugins)) {
            $errors[] = 'Url plugin must be enabled';
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Forwards the Google Searches to a central handler.
     *
     * @return void
     */
    public function onDoG($query)
    {
        // check if this is a definition serach
        if ( 'define:' == substr($query, 0, 7) ) {
            $this->onDoDefine(substr($query, 7, strlen($query)));
            return;
        }

        // get new url given by the "I'm feeling lucky" google search
        $opts = array(
            'http' => array(
                'timeout' => 3.5,
                'method' => 'GET',
                'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.12) Gecko/20080201 Firefox/2.0.0.12'
            )
        );
        $context = stream_context_create($opts);

        $url = 'http://www.google.com/search?hl=en&q='.urlencode($query).'&btnG';

        if ($page = fopen('http://www.google.com/search?hl=en&q='.urlencode($query).'&btnI', 'r', false, $context)) {
            stream_set_timeout($page, 3.5);
            $data = stream_get_meta_data($page);
            foreach ($data['wrapper_data'] as $header) {
                if (preg_match('#^Location:\s*(.*)$#', $header, $match)) {
                    $url = $match[1];
                    break;
                }
            }
        }

        // get title and short url
        $title = $this->getPlugin('Url')->getTitle('http://www.google.com/search?hl=en&q='.urlencode($query).'&btnI');
        $tinyUrl = Phergie_Plugin_TinyUrl::get($url);

        $this->doPrivmsg($this->event->getSource(), $this->event->getNick() . ': [ '.$tinyUrl.' ] '.$title);
    }
    
    /**
     * Do a "Google Count" for the given term.
     *
     * @todo use an HTML parser to parse HTML
     * @return void
     */
    public function onDoGc($term)
    {
        preg_match(
            '@<div id=resultStats>About (.+) results<nobr>@i',
            file_get_contents('http://www.google.ca/search?q=' . urlencode($term)),
            $m
        );
        $num = isset($m[1])?$m[1]:0;
        $this->doPrivmsg($this->event->getSource(), "$num results for $term");
    }
}
