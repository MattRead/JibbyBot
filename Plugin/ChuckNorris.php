<?php

/**
 * Parses incoming messages for the word "Chuck Norris" and respond with a
 * random Chuck Norris fact retrieved from the Chuck Norris fact page. The
 * plugin will also scrape the fact page and store all the facts it retrieves
 * into a database for quick access.
 */
class Phergie_Plugin_ChuckNorris extends Phergie_Plugin_Abstract_Base
{

    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * Stores the SQLite object
     *
     * @var resource
     */
    protected $db = null;

    /**
     * The host of the Chuck Norris facts page we use for our information
     *
     * @var string
     */
    protected $factHost = 'http://chucknorrisfacts.com/';

    /**
     * The starting page we use to scrape out information from
     *
     * @var string
     */
    protected $crawlPage = 'new.html';

    /**
     * The current page number when crawling
     *
     * @var int
     */
    protected $crawlNum = 0;

    /**
     * Stores all the Chuck Norris facts while the pages are being parsed
     *
     * @var array
     */
    protected $norrisFacts = array();

    /**
     * Flood check period in seconds
     * Set to 0 or below to disable.
     *
     * @var int
     */
    protected $floodCheck = 30;

    /**
     * The chance of responding with a random Chuck Norris fact. The chance can
     * bet set anywheres from 1 - 100 while a value like 50 is a 50% chance.
     * Set to 0 or below or a value of 100 or higher to disable.
     *
     * @var int
     */
    protected $chance = 50;

    /**
     * Stores the timestamp of the last use and is used in flood check comparisons
     *
     * @var int
     */
    protected $floodCache = array();

    /**
     * Connects to the database and populates the fact table when needed.
     *
     * @return void
     */
    public function onInit()
    {
        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'norris.db');
            if (!$this->db) {
                return;
            }

            // Populate the database if necessary
            // Checks to see if the table exists, if not create it
            if ($this->findChuckNorris()) {
                // Retrieves a list of Chuck Norris facts
                if ($this->getChuckNorris()) {
                    // Inserts all the retrieved facts into the database
                    $this->feedChuckNorris();
                }
            }
        } catch (PDOException $e) { }

        $this->chance = intval($this->chance);
        $this->floodCheck = intval($this->floodCheck);
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

    	if (!extension_loaded('PDO')) {
            $errors[] = 'PDO php extension is required';
    	}
    	if (!extension_loaded('pdo_sqlite')) {
            $errors[] = 'pdo_sqlite php extension is required';
    	}

        return empty($errors) ? true : $errors;
    }

    /**
     * Connects to the Chuck Norris facts page and scrapes the facts from it
     *
     * @return bool True is successful, else false
     */
    private function getChuckNorris($doCrawl = true)
    {
        // The current page to retrieve
        $page = trim($this->factHost . $this->crawlPage);

        $this->debug('Retrieving page ' . $this->crawlNum . ' => ' . $this->crawlPage);
        $contents = @file_get_contents($page);
        if ($contents !== false) {
            $this->debug('Parsing page ' . $this->crawlNum . ' => ' . $this->crawlPage);
            preg_match_all('#<li>\s*([^<]+)\s*(?:<br>\s*&nbsp;)?<\/li>#im', $contents, $matches);

            // Format the returned facts to be stored later in a database
            foreach($matches[1] as $key => $fact) {
                $fact = trim(preg_replace("/(&nbsp;|[\r\n\s])+/", ' ', html_entity_decode($fact, ENT_QUOTES)));
                $fact = str_replace(array('&#8217;','&#8220;','&#8221;','&#8230;'), array("'",'"','"','...'), $fact);
                if (!empty($fact)) {
                    $this->norrisFacts[] = $fact;
                }
            }

            // If crawling is set, crawl and scrape the remaining fact pages
            if ($doCrawl) {
                preg_match_all('#<a href="(page([0-9]+)\.html)">#im', $contents, $matches);

                // Make sure we don't have any duplicates
                $pages = array_unique(array_combine($matches[2], $matches[1]));
                ksort($pages, SORT_NUMERIC);

                // Start the crawling processs
                foreach($pages as $page => $url) {
                    if ($page > $this->crawlNum) {
                        $this->crawlNum = $page;
                        $this->crawlPage = $url;

                        // Return false if there was a problem with the crawling
                        if (!$this->getChuckNorris(false)) {
                            return false;
                        }
                    }
                }
            }
            unset($contents);
            return true;
        }

        $this->debug('Could not retrieve page ' . $this->crawlNum . ' => ' . $this->crawlPage);
        return false;
    }

    /**
     * Determines if the chuckfacts does not exist or empty
     *
     * @return bool TRUE if the table does not exist or is empty, FALSE
     *              otherwise
     */
    private function findChuckNorris()
    {
        if (!$this->db) {
            return false;
        }

        // Checks to see if the chuckfacts table exists
        $table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote('chuckfacts'))->fetchColumn();

        // If the table doesn't exist, create them and return true for the next step
        if (!$table) {
            $this->debug('Creating the database schema');
            $this->db->exec('CREATE TABLE chuckfacts (facts VARCHAR(255))');
            $this->db->exec('CREATE UNIQUE INDEX chuckfacts_name ON chuckfacts (facts)');
            return true;
        }

        // Checks to see if anything is stored in the chuckfacts table
        return !$this->db->query('SELECT COUNT(*) FROM chuckfacts')->fetchColumn();
    }

    /**
     * Populates the chuckfacts table with the given array of facts
     *
     * @return bool True is successful, else false
     */
    private function feedChuckNorris()
    {
        if (!$this->db) {
            return false;
        }

        // Check to see if there are any facts to insert
        if (count($this->norrisFacts) > 0) {
            $stmt = $this->db->prepare('INSERT INTO chuckfacts (facts) VALUES (:fact)');
            $this->db->beginTransaction();
            // Go through the facs and insert them if available
            foreach(array_unique($this->norrisFacts) as $fact) {
                if (!empty($fact)) {
                    $stmt->execute(array(':fact' => $fact));
                    $this->debug('Inserted fact: ' . $fact);
                }
            }
            $this->db->commit();
            // Unset the facts array to free up memory
            unset($this->norrisFacts);

            return true;
        }
        return false;
    }

    /**
     * Returns a random fact from the chuckfacts table.
     *
     * @return string A random fact
     */
    private function praiseChuckNorris()
    {
        if (!$this->db) {
            return;
        }

        return $this->db->query('SELECT facts FROM chuckfacts ORDER BY Random() LIMIT 1')->fetchColumn();
    }

    /**
     * Parses incoming messages for the word "Chuck Norris" and respond with a
     * random Chuck Norris fact retrieved from the Chuck Norris fact page.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        if (!$this->db) {
            return;
        }

        $source = $this->event->getSource();
        $message = $this->event->getArgument(1);

        // Check to see if the message includes the word Chuck Norris. If so, check to see if
        // it was a bot request by checking for Fact: at the begiining and also do a floodpro check
        if (preg_match('{^('.preg_quote($this->getIni('nick')).'\s*[:,>]?\s+)?\s*(chuck\s+norris)}ix', $message, $m) &&
            strtolower(substr($message, 0, 5)) != 'fact:' && ($source[0] != '#' ||
            (!empty($m[1]) || $this->chance <= 0 || $this->chance >= 100 || mt_rand(1, 100) < $this->chance) &&
            ($this->floodCheck <= 0 || !isset($this->floodCache[$source]) ||
            ($this->floodCache[$source] < (time() - $this->floodCheck))))) {
            $fact = $this->praiseChuckNorris();
            if (!empty($fact)) {
                $this->doPrivmsg($source, 'Fact: ' . $fact);
                if ($source[0] == '#') {
                    $this->floodCache[$source] = time();
                }
                unset($m, $fact);
            }
        }
    }

    /**
     * Parses incoming CTCP request for the word "Chuck Norris" and respond with
     * a random Chuck Norris fact retrieved from the Chuck Norris fact page.
     *
     * @return void
     */
    public function onCtcp()
    {
        $source = $this->event->getSource();
        $ctcp = $this->event->getArgument(1);

        if (!$this->db) {
            return;
        }

        if (preg_match('{chuck[\s_+-]*norris}ix', $ctcp, $m)) {
            $fact = $this->praiseChuckNorris();
            if (!empty($fact)) {
                $this->doCtcpReply($source, 'CHUCKNORRIS', $fact);
            }
        }
    }
}
