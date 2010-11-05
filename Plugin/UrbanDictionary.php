<?php

/**
 * Performs a lookup on request for a given term on the Urban Dictionary web
 * site and responds with a message containing the first result.
 */
class Phergie_Plugin_UrbanDictionary extends Phergie_Plugin_Abstract_Command
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
    	if (!self::staticPluginLoaded('TinyUrl', $client, $plugins)) {
            return 'TinyUrl plugin must be enabled';
        }

        return true;
    }

    /**
     * Handles Urban Dictionary definition requests.
     *
     * @param string $term Term to search for
     * @return void
     */
    public function onDoUd($term)
    {
        $target = $this->event->getNick();
        $source = $this->event->getSource();

        $url = 'http://www.urbandictionary.com/define.php?term=' . urlencode($term);
        $contents = @file_get_contents($url);

        if ($contents === false) {
            $this->doNotice($target, 'Urban Dictionary is currently inaccessible');
        } elseif (strpos($contents, 'isn\'t defined') !== false) {
            $url = Phergie_Plugin_TinyUrl::get('http://urbandictionary.com/insert.php?word=' . $term);
            $this->doNotice($target, $term . ' is not defined yet [ ' . $url . ' ]');
        } else {
            $start = strpos($contents, '<div class="definition">');
            $end = strpos($contents, '<div', $start + 1);
            if ($end === false) {
                $end = strpos($contents, '</div>', $start);
            }
            $contents = substr($contents, $start, $end - $start);
            $contents = html_entity_decode(strip_tags($contents));
            $contents = $term . ': ' . trim(preg_replace('/[\r\n\t ]+/', ' ', $contents));

            $url = '[ ' . Phergie_Plugin_TinyUrl::get($url) . ' ] ';

            /**
             * Not sure why, but this seems to be the magic number for
             * ensuring that the text isn't truncated. The hostmask isn't
             * included in what's sent to the server. The maximum message
             * length should be 510 characters according to the IRC RFC.
             */
            $max = 445 - strlen($source) - strlen($url);
            if (strlen($contents) > $max) {
                $contents = substr($contents, 0, $max);
                $end = strrpos($contents, ' ');
                if ($end === false) {
                    $end = $max;
                }
                $contents = substr($contents, 0, $end) . '...';
            }
            $contents = $url . $contents;

            $this->doPrivmsg($source, $contents);
        }
    }
}
