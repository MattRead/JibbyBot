<?php

/**
 * Searches received messages for consecutive sequences of two or more capital
 * letters followed by a question mark, performs an acronym lookup for each
 * sequence, and either returns a limited number of possible meanings for the
 * acronym or performs a random action should no results be returned.
 *
 * The limit configuration setting should be set to the maximum number of
 * potential meanings to return for any single given acronym.
 *
 * @todo Add a cache to avoid exceeding the acronymfinder.com daily lookup
 *       limit. Cache should be flushed daily.
 */
class Phergie_Plugin_Acronym extends Phergie_Plugin_Abstract_Base
{
    /**
     * Maximum number of meanings to return for a single acronym
     *
     * @var int
     */
    protected $limit;

    /**
     * List of acronyms for which responses should not be sent
     *
     * @var array
     */
    protected $filter;

    /**
     * Caches the returned acronyms
     *
     * @var array
     */
    protected $cache;
    protected $flushed;

    /**
     * Possible reactions to return when no result is returned
     *
     * @var array
     */
    protected $reactions = array(
        'shrugs',
        'blinks',
        'giggles',
        'sighs',
        'yawns',
        'hides behind %randomuser%'
    );

    /**
     * Initializes the limit of meanings to return per acronym.
     *
     * @return void
     */
    public function onInit()
    {
        $limit = $this->getPluginIni('limit');
        if ($limit < 0 || $limit === null) {
            $this->limit = 5;
        } else {
            $this->limit = (int)$limit;
        }

        $this->filter = array_filter(preg_split('/[ ,]/', $this->getPluginIni('filter')), 'strlen');
    }

    /**
     * Returns a random action, meant for cases where an acronym lookup
     * returns no results.
     *
     * @param string $target Channel name or user nick to receive the action
     * @return void
     */
    protected function randomAction($target)
    {
        do {
            $reaction = $this->reactions[mt_rand(0, count($this->reactions) - 1)];
            $randomUser = (strpos($reaction, '%randomuser%') !== false);
        } while ($target[0] != '#' && $randomUser);
        if ($randomUser) {
            if ($this->pluginLoaded('ServerInfo')) {
                $nick = $this->getIni('nick');
                do {
                    $user = Phergie_Plugin_ServerInfo::getRandomUser($target);
                } while ($user == $nick);
            } else {
                $user = $this->event->getNick();
            }
            $reaction = str_replace('%randomuser%', $user, $reaction);
        }
        $this->doAction($target, $reaction . '.');
    }

    /**
     * Processes acronym lookups and returns results when available, or
     * returns a random action when a lookup returns no results.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $source = $this->event->getSource();
        $message = $this->event->getArgument(1);
        $target = $this->event->getNick();
        $today = date('md');

        // Matches an optional "Nick: " followed by an acronym formatted as A.B.C. or ABC
        if (!preg_match('/^(?:[A-Za-z0-9\[\]`|{}_-]+: )?((?:[A-Z]\.?){2,})\?$/', $message, $acronym)) {
            return;
        }

        $acronym = str_replace('.', '', $acronym[1]);

        if (in_array($acronym, $this->filter)) {
            return;
        }

        if (in_array($acronym, array('WHO', 'WHAT', 'WHERE', 'WHEN', 'WHY', 'HOW'))) {
            $this->doAction($source, 'shrugs.');
            return;
        }

        if ($this->flushed != $today) {
            unset($this->cache);
            $this->flushed = $today;
            $this->cache = array();
        }

        if (!isset($this->cache[$acronym])) {
            $opts = array(
                'http' => array(
                    'timeout' => 10,
                    'method' => 'GET',
                    'header' => "User-Agent: Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.12) Gecko/20080201 Firefox/2.0.0.12\r\n".
            					"Accept: text/xml,application/xml,application/xhtml+xml,text/html;q=0.9,text/plain;q=0.8,image/png,*/*;q=0.5\r\n".
            					"Accept-Language: en-us,en;q=0.8\r\n".
            					"Accept-Charset: ISO-8859-1,utf-8;q=0.7,*;q=0.7\r\n".
            					"Referer: http://acronyms.thefreedictionary.com/\r\n",
                )
            );
            $context = stream_context_create($opts);
            $url = 'http://acronyms.thefreedictionary.com/' . urlencode($acronym);
            $contents = @file_get_contents($url, false, $context);

			if (empty ($contents) ||
                strpos($contents, 'Word not found') !== false) {
                $this->randomAction($source);
                return;
            } else {
                $matches = array();
                $offset = 0;

                do {
                    $count = preg_match('/<td>([^<]+)</i', $contents, $match, PREG_OFFSET_CAPTURE, $offset);
                    if ($count == 1) {
                        $matches[] = html_entity_decode($match[1][0]);
                        $offset = $match[1][1];
                    }
                } while (($this->limit == 0 || count($matches) < $this->limit) && $count == 1);
            }
        }

        if (isset($this->cache[$acronym])) {
            $matches = $this->cache[$acronym];
        }

        if (count($matches) > 0) {
            $this->cache[$acronym] = $matches;

            $text = 'Possible matches for ' . $acronym . ': ' . implode('; ', $matches);
            $this->doPrivmsg($source, $target . ': ' . $text);
        }
    }
}
