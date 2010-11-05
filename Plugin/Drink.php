<?php

/**
 * Parses incoming messages for issued commands indicating that the bot should
 * serve a specified user with a random drink of a specific type, locates a
 * drink of that type, and responds with an action indicating that the bot is
 * serving that drink to that user.
 *
 * The gender configuration setting should be set to either M or F to indicate
 * the gender of the bot so as to allow for substitution of the proper pronoun
 * when a user requests that the bot serve itself a drink.
 */
class Phergie_Plugin_Drink extends Phergie_Plugin_Abstract_Command
{
    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * PDO resource for a SQLite database containing the drinks
     *
     * @var resource
     */
    protected $db = null;

    /**
     * List of profane words to filter from imported drinks
     *
     * @var array
     */
    protected $filter = array(
        'shit',
        'shitkicker',
        'shitface',
        'shittin',
        'piss',
        'pissed',
        'pisser',
        'pissbomb',
        'fuck',
        'fucks',
        'motherfucker',
        'motherfuckers',
        'fucked',
        'fucker',
        'dumbfuck',
        'mindfuck',
        'stumblefuck',
        'cunt',
        'cock',
        'cockteaser',
        'cocksucker',
        'ass',
        'asshole',
        'bitch',
        'bitches',
        'bitchin',
        'tit',
        'tits',
        'titty',
        'titties',
        'penis',
        'penisbutter',
        'dick',
        'dickhead',
        'sex',
        'buttsex',
        'sexoholic',
        'sexual',
        'sexy',
        'triplesex',
        'panties',
        'urine'
    );

    /**
     * Connects to the database and populates tables where needed.
     *
     * @return void
     */
    public function onInit()
    {
        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'drink.db');
            if (!$this->db) {
                return;
            }

            // Populate the database if necessary
            if ($this->needTable('beer')) {
                $this->debug('Retrieving data for: Beer');
                $contents = @file_get_contents('http://beerme.com/beerlist.php');
                if ($contents !== false) {
                    $this->debug('Parsing data for: Beer');
                    preg_match_all('/brewery\.php\?[0-9]+#[0-9]+\'>([^<]+)/', $contents, $matches);
                    $names = array();
                    foreach($matches[1] as $key => $name) {
                        $name = $this->decodeTranslit($name);
                        if ($this->hasBadChars($name) || strpos($name, '(discontinued)') !== false) {
                            continue;
                        }
                        $name = explode('/', preg_replace('/\([^)]+\)/', '', $name));
                        $name = trim(array_shift($name));
                        if (!empty($name)) {
                            $name = html_entity_decode($name);
                            $names[] = $name;
                        }
                    }
                    $this->populateTable('beer', $names);
                    unset($names);
                }
            }

            if ($this->needTable('cocktail')) {
                $limit = 2;
                $names = array();
                $this->debug('Retrieving data for: Cocktail');
                for ($i = 1; $i <= $limit; $i += 150) {
                    $contents = @file_get_contents('http://www.webtender.com/db/browse?level=2&dir=drinks&char=%2A&start=' . $i);
                    if ($contents === false) {
                        break;
                    }
                    $this->debug('Parsing data for: Cocktail (' . $i . ' - ' . ($i + 150) . ')');
                    if ($i == 1) {
                        preg_match('/>([0-9]+) found\\.</', $contents, $match);
                        $limit = $match[1]+(150-($match[1]%150));
                    }
                    preg_match_all('/db\\/drink\\/[0-9]+">([^<]+)/', $contents, $matches);
                    foreach($matches[1] as $name) {
                        $name = $this->decodeTranslit($name);
                        if ($this->hasBadChars($name)) {
                            continue;
                        }
                        $name = html_entity_decode(preg_replace('/ The$|^The |\s*\([^)]+\)\s*| #[0-9]+$/', '', $name));
                        $names[] = $name;
                    }
                }
                if ($contents) {
                    $this->populateTable('cocktail', $names);
                }
                unset($names);
            }

            if ($this->needTable('coke')) {
                $this->debug('Retrieving data for: Coke');
                $contents = @file_get_contents('http://www.energyfiend.com/huge-caffeine-database/');
                if ($contents) {
                    $this->debug('Parsing data for: Coke');
                    // List of drinks to filter out
                    $filter = array(
                        'tea',
                        'coffee',
                        'starbucks'
                    );
                    $start = stripos($contents, 'id="caffeinedb"');
                    $end = stripos($contents, '</table>', $start);
                    $contents = substr($contents, $start, $end-$start);
                    preg_match_all('/<tr[^>]*><td>(<[^>]+>)?([^<]+)/is', $contents, $matches);
                    $names = array();
                    foreach($matches[2] as $name) {
                        $name = $this->decodeTranslit($name);
                        if ($this->hasBadChars($name)) {
                            continue;
                        }
                        $name = html_entity_decode(trim(preg_replace('/ \\([^)]+\\)| - .*$/', '', $name)));
                        if (!preg_match('/(?:^|\s+)(?:' . implode('|', $filter) . ')(?:\s+|$)/i', $name)) {
                            $names[] = $name;
                        }
                    }
                    $this->populateTable('coke', $names);
                    unset($names);
                }
            }

            if ($this->needTable('tea')) {
                $this->debug('Retrieving data for: Tea');
                $names = @file('http://www.midnight-labs.org/tea.txt');
                if ($names) {
                    $this->debug('Parsing data for: Tea');
                    foreach($names as $key => $value) {
                        $value = $this->decodeTranslit($value);
                        if ($this->hasBadChars($value)) {
                            continue;
                        }
                        $names[$key] = ucwords(trim($value));
                    }
                    $this->populateTable('tea', $names);
                    unset($names);
                }
            }
        } catch (PDOException $e) { }

        unset($this->filter);
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
     * Determines if a table does not exist or is empty.
     *
     * @param string $name Table name
     * @return bool TRUE if the table does not exist or is empty, FALSE
     *              otherwise
     */
    private function needTable($name)
    {
        $table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote($name))->fetchColumn();
        if (!$table) {
            return true;
        }

        return !$this->db->query('SELECT COUNT(*) FROM ' . $name)->fetchColumn();
    }

    /**
     * Populates a source database table with a given set of data.
     *
     * @param string $table Name of the table
     * @param array $names List of drink names
     * @return void
     */
    private function populateTable($table, $names)
    {
        $this->debug('Creating the database schema for: ' . ucfirst($table));
        $this->db->exec('CREATE TABLE ' . $table . ' (name VARCHAR(255))');
        $this->db->exec('CREATE UNIQUE INDEX ' . $table . '_name ON ' . $table . ' (name)');

        $stmt = $this->db->prepare('INSERT INTO ' . $table . ' (name) VALUES (:name)');
        $this->db->beginTransaction();
        foreach(array_unique($names) as $name) {
            if (preg_match('/(?:^|[^a-z])(' . implode('|', $this->filter) . ')(?:[^a-z]|$)/i', $name, $match)) {
                $this->debug('Filtered out ' . $name . ' because it contains ' . $match[1]);
                continue;
            }
            $this->debug('Inserted ' . ucfirst($table) . ': ' . $name);
            $stmt->execute(array('name' => $name));
        }
        $this->db->commit();
    }

    /**
     * Returns a random record value from a given table.
     *
     * @string $table Name of the table
     * @return string Value of the name column for the selected record
     */
    private function getRandomRecord($table)
    {
        return $this->db->query('SELECT name FROM ' . $table . ' ORDER BY RANDOM() LIMIT 1')->fetchColumn();
    }

    /**
     * Returns a random record value from a given search pattern.
     *
     * @string $table Name of the table
     * @return string Value of the name column for the selected record
     */
    private function getSearchRecord($table, $search)
    {
        $search = $this->db->quote('%' . str_replace(array('\\', '%'), array('\\\\', '\\%'), $search) .'%');
        return $this->db->query('SELECT name FROM ' . $table . ' WHERE name LIKE ' . $search . ' ESCAPE "\\" ORDER BY RANDOM() LIMIT 1')->fetchColumn();
    }

    /**
     * Returns whether or not a given value has characters that may not be
     * displayed correctly.
     *
     * @param string $name Value to check
     */
    private function hasBadChars($name)
    {
        return (max(array_map('ord', str_split($name))) > 126);
    }

    /**
     * Resolves a target to the appropriate nick or pronoun and returns the
     * result.
     *
     * @param string $target Original specified target
     * @return string Resolved target
     */
    protected function resolveTarget($target)
    {
        $target = rtrim(trim($target), '.?!');

        switch (trim(strtolower($target))) {
            case 'me':
                $target = $this->event->getNick();
            break;

            case 'you':
            case 'your self':
            case 'yourself':
            case strtolower($this->getIni('nick')):
                $gender = $this->getIni('gender');
                if (!$gender || $gender == 'F') {
                    $target = 'herself';
                } else {
                    $target = 'himself';
                }
            break;
        }

        return $target;
    }

    /**
     * Responds to a message requesting that the bot perform an action to
     * serve the source with a random drink of a specific type.
     *
     * @param string $type Type of drink
     * @param string $message Drink reuest message
     * @return void
     */
    protected function handleDrink($type, $message)
    {
        if (!$this->db) {
            return;
        }

        $message = preg_replace('/\s+/', ' ', trim($message));
        preg_match('/^(.+?)(?:\s+an?(?:\s+(?:cuppa|cup\s+of))?\s+(.+?))?(\s+(?:for|because)\s+.+)?$/', $message, $m);
        list(, $target, $drink, $action) = array_pad($m, 4, null);

        $drink = trim($drink);
        if ($type == 'tea' && strtolower(substr($drink, -3)) == 'tea') {
            $drink = trim(substr($drink, 0, -3));
        }

        $action = trim($action);
        $target = $this->resolveTarget($target);

        if (!Phergie_Plugin_ServerInfo::isIn($target, $this->event->getSource())) {
            return;
        }

        if (!empty($drink)) {
            $drink = implode(' ', array_map('ucfirst', explode(' ', $drink)));
            if ($search = $this->getSearchRecord($type, $drink)) {
                $drink = $search;
            }
        } else {
            $drink = $this->getRandomRecord($type);
        }

        if ($drink) {
            if ($type != 'tea') {
                $text = 'throws ' . $target . ' a';
                if (preg_match('/^[aeoiu]/i', $drink)) {
                    $text .= 'n';
                }
                $text .= ' ' . $drink . ($action ? ' ' . $action : '');
            } else {
                // One must be gentle with tea
                $text = 'pours ' . $target . ' a cup of ' . $drink . ' tea'  . ($action ? ' ' . $action : '');
            }
            $this->doAction($this->event->getSource(), $text . '.');
        }
    }

    /**
     * Handles beer requests.
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoBeer($message)
    {
        $this->handleDrink('beer', $message);
    }

    /**
     * Alias to Beer
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoBooze($message)
    {
        $this->handleDrink('beer', $message);
    }

    /**
     * Handles cocktail requests.
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoCocktail($message)
    {
        $this->handleDrink('cocktail', $message);
    }

    /**
     * Handles coke requests.
     *
     * @param string $target Source of the request
     * @return void
     */
    public function onDoCoke($message)
    {
        $this->handleDrink('coke', $message);
    }

    /**
     * Provides a soda alias for coke requests.
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoSoda($message)
    {
        $this->handleDrink('coke', $message);
    }

    /**
     * Handles tea requests.
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoTea($message)
    {
        $this->handleDrink('tea', $message);
    }

    /**
     * Handles pop requests.
     *
     * @param string $target Target for the request
     * @return void
     */
    public function onDoPop($target)
    {
        $target = $this->resolveTarget($target);

        $this->doAction($this->event->getSource(), 'lays ' . $target . ' out flat.');
    }
}
