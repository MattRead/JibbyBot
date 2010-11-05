<?php

/**
 * Sporadically syndicates items from a given set of feeds to the channel.
 */
class Phergie_Plugin_FeedTicker extends Phergie_Plugin_Abstract_Cron
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Delay in seconds for syndicating feeds, set to 30 minutes
     *
     * @var int
     */
    protected $defaultDelay = 1800;

    /**
     * Delay in minutes between checking the queue, set to 5 minutes
     *
     * @var int
     */
    protected $postThrottle = 5;

    /**
     * Feed data
     *
     * @see run()
     * @var array
     */
    protected $feeds = null;

    /**
     * Filter feed title data
     *
     * @see run()
     * @var array
     */
    protected $filterTitle = null;

    /**
     * Filter feed url data
     *
     * @see run()
     * @var array
     */
    protected $filterUrl = null;

    /**
     * Filter article title data
     *
     * @see run()
     * @var array
     */
    protected $filterArticle = null;

    /**
     * Cache of the last update to check for new entries
     *
     * @see run()
     * @var array
     */
    protected $cache = null;

    /**
     * Queue of items to be dispatched
     *
     * @see checkQueue()
     * @see run()
     * @var array
     */
    protected $queue = array();

    /**
     * Time at which the checkQueue method will be allowed to dispatch
     * another item
     *
     * @see checkQueue()
     * @var int
     */
    protected $nextOutput = null;

    /**
     * Feed output format; can use the variables %title%, %link% and %feed% to
     * display article titles, links and feed titles
     *
     * @var string
     */
    protected $format = '%title% [ %link% ]';

    /**
	 * If true, the new feed items are buffered until something happens on
	 * the channel, indicating some kind of presence / readership is available
	 *
	 * @var bool
	 */
    protected $smartBuffer = true;

    /**
     * Processes necessary configuration setting values.
     *
     * @return void
     */
    public function onInit()
    {
        // Delay between feed syndications
        $fetchDelay = intval($this->getPluginIni('fetch'));
        if ($fetchDelay > 0) {
            $this->defaultDelay = $fetchDelay * 60;
        }

        // Post throttle
        $postThrottle = intval($this->getPluginIni('post'));
        if ($postThrottle > 0) {
            $this->postThrottle = $postThrottle;
        }

        // Global Feed Title, Feed URL and Article Title Filters
        $globalTitle = trim($this->getPluginIni('filter_title'));
        $globalUrl = trim($this->getPluginIni('filter_url'));
        $globalArticle = trim($this->getPluginIni('filter_article'));
        if ($this->getPluginIni('smart_buffer') !== null) {
	        $this->smartBuffer = (bool) $this->getPluginIni('smart_buffer');
        }

        $i = 0;
        $this->feeds = array();
        do {
            // Feed and Chan data
            $feed = $this->getPluginIni('feed' . $i);
            $chans = $this->getPluginIni('chans' . $i);
            // Feed Title, Feed URL and Article Title Filter Data
            $filterTitle = $this->getPluginIni('filter_title' . $i);
            $filterUrl = $this->getPluginIni('filter_url' . $i);
            $filterArticle = $this->getPluginIni('filter_article' . $i);

            if (!empty($feed) && !empty($chans)) {
                $this->feeds[] = array($feed, preg_split('#[\s\r\n,]+#', $chans));
                // Feed Title, Feed URL and Article Title Filters
                $filterTrim = "| \t\n\r\0\v\0xa0";
                $this->filterTitle[] = trim(implode('|', array($globalTitle, $filterTitle)), $filterTrim);
                $this->filterUrl[] = trim(implode('|', array($globalUrl, $filterUrl)), $filterTrim);
                $this->filterArticle[] = trim(implode('|', array($globalArticle, $filterArticle)), $filterTrim);
            }
        } while (++$i < 10);
        if ($this->getPluginIni('format') != null) {
            $this->format = $this->getPluginIni('format');
        }
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
    	$errors = array();

    	if (!extension_loaded('SimpleXML')) {
            $errors[] = 'SimpleXML php extension is required';
    	}
        if (!self::staticPluginLoaded('TinyUrl', $client, $plugins)) {
            $errors[] = 'TinyUrl plugin must be enabled';
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Whenever a user sends a message to a channel in which the bot is present,
     * the queue is checked. This behavior attempts to prevent the bot from
     * spamming the channel if no users are conversing and everything is retained
     * in the queue until channel activity is detected.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $this->checkQueue();
        parent::onPrivmsg();
    }

    /**
     * Checks the queue for new items and send out one if the necessary time
     * limit has elapsed since the last item was sent. Uses the format setting
     * to format new items.
     *
     * @return void
     */
    protected function checkQueue()
    {
        if (!empty($this->queue) && time() > $this->nextOutput) {
            list($title, $url, $chans, $feedTitle) = array_pad(array_shift($this->queue), 4, null);
            foreach($chans as $chan) {
                $this->doPrivmsg($chan, str_replace(array(
                    '%title%',
                    '%link%',
                    '%feed%'
                ), array(
                    $title,
                    $url,
                    $feedTitle
                ), $this->format));
            }
            $this->nextOutput = time() + ($this->postThrottle * 60);
        }
    }
    
    public function onDoQueueSize()
    {
    	$this->doPrivmsg($this->event->getSource(), sizeof($this->queue));
	}

    /**
     * Retrieves feeds and fills the queue with new items that were not
     * previously in the cache.
     *
     * Technical data if you want to extend this method to parse another type
     * of source differently :
     *
     * Feeds are arrays such as: array("feed url", array("chan1", "chan2"))
     *
     * Cache management is up to you and is only internally used by this
     * method.
     *
     * The queue is an array of items to be dispatched, these items are as
     * such :
     *   array("item title", "item url", array("chan1", "chan2"), "feed title")
     *
     * @return void
     */
    protected function run()
    {
        $retrieved = array();

        $context = stream_context_create(array(
            'http' => array(
                'timeout' => 5,
                'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.9b3) Gecko/2008020514 Firefox/3.0b3'
            )
        ));

        // Retrieve each feed
        foreach($this->feeds as $id => $feed) {
            list($url, $chans) = array_pad($feed, 2, null);

            $content = @file_get_contents($url, null, $context);
            if (empty($content)) {
                $this->debug('Feed empty: ' . $url);
                continue;
            }

            try {
                // RSS/RDF Feed
                if (preg_match('/<rss[^>]+version=/', $content) || stripos($content, '<rdf') !== false) {
                    $xml = new SimpleXMLElement($content);
                    $feedTitle = (string)$xml->channel->title;
                    foreach($xml->channel->item as $item) {
                        $retrieved[$id][] = array((string) $item->title, (string) $item->link, $chans, $feedTitle);
                    }
                } elseif (stripos($content, '/Atom') !== false) { // ATOM Feed
                    $xml = new SimpleXMLElement($content);
                    $feedTitle = (string)$xml->title;
                    foreach($xml->entry as $item) {
                        $retrieved[$id][] = array((string) $item->title, (string) $item->link[0]['href'], $chans, $feedTitle);
                    }
                } else { // Trouble
                    $this->debug('Feed format unrecognized: ' . $url);
                    continue;
                }
            } catch (Exception $e) {
                $this->debug('Caught exception: ', $e->getMessage());
                continue;
            }
        }
        unset($content, $xml);

        // First run, fill cache and don't output anything
        if ($this->cache === null) {
            $this->cache = $retrieved;
            return;
        }

        // Latter run, compare retrieved data to cache and queue new items
        foreach($retrieved as $id => $articles) {
            $articles = array_reverse($articles);
            foreach($articles as $article) {
                if ((!isset ($this->cache[$id]) ||
                    array_search($article, $this->cache[$id]) === false) &&
                    $this->filterCheck($id, $article[0], $article[1], $article[3])) {
                    // Decode and trim article title
                    $article[0] = $this->decode($article[0], 150);
                    // Convert link with TinyURL if required
                    $article[1] = Phergie_Plugin_TinyUrl::get($article[1]);
                    // Decode and trim feed title
                    $article[3] = $this->decode($article[3], 20);
                    $this->queue[] = $article;
                }
            }
            // Cache current data for next run
            $this->cache[$id] = $articles;
        }

        if (!$this->smartBuffer) {
            $this->checkQueue();
        }
    }

    // Checks the given feed Title and URL as well as article title against the feed filters
    protected function filterCheck($id, $title, $url, $article)
    {
        // Feed Title, Feed URL and Article Title Filters
        $filterTitle = $this->filterTitle[$id];
        $filterUrl = $this->filterUrl[$id];
        $filterArticle = $this->filterArticle[$id];

        // Check against the filters if any are set
        if (($filterTitle && preg_match('{'.$filterTitle.'}im', $title, $match)) ||
            ($filterUrl && preg_match('{'.$filterUrl.'}im', $url, $match)) ||
            ($filterArticle && preg_match('{'.$filterArticle.'}im', $article, $match))) {
            return false;
        }
        return true;
    }

    /**
     * Transliterates a UTF-8 string into corresponding ASCII characters and
     * truncates and appends an ellipsis to the string if it exceeds a given
     * length.
     *
     * @param string $str String to decode
     * @param int $trim Maximum string length, optional
     * @return string
     */
    protected function decode($str, $trim = null)
    {
        $out = $this->decodeTranslit($str);
        if ($trim > 0) {
            $out = substr($out, 0, $trim) . (strlen($out) > $trim ? '...' : '');
        }
        return $out;
    }
}
