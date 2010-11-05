<?php

/**
 * Responds to a request for a TLD (formatted as .tld where tld is the TLD to
 * be looked up) with its corresponding description.
 */
class Phergie_Plugin_Tld extends Phergie_Plugin_Abstract_Base
{
    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * PDO resource for a SQLite database containing the TLDs
     *
     * @var resource
     */
    protected $db = null;

    /**
     * Some fixed TLD values, keys must be lowercase
     *
     * @var array
     */
    protected static $fixedTlds;

    /**
     * Prepared statement to retrieve TLD records
     *
     * @var PDOStatement
     */
    protected $select;
    protected $selectAll;

    /**
     * Static instance of the Tld class
     *
     * @var Phergie_Plugin_Tld
     */
    protected static $instance;

    /**
     * Connects to the database and populates tables where needed.
     *
     * @return void
     */
    public function onInit()
    {
        self::$fixedTlds = array(
            'phergie' => 'You can find Phergie at http://www.phergie.org',
            'spoon' => 'Don\'t you know? There is no spoon!',
            'poo' => 'Do you really think that\'s funny?',
            'root' => 'Diagnostic marker to indicate a root zone load was not truncated.'
        );

        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'tld.db');
            if (!$this->db) {
                return;
            }

            // Check to see if the TLD table needs to be created
            $table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote('tld'))->fetchColumn();

            // Get the list of columns from the table
            $columns = array();
            if ($table) {
                $pragma = $this->db->query('PRAGMA table_info(' . $this->db->quote('tld') . ')')->fetchAll();

                foreach($pragma as $key => $column) {
                    $columns[] = trim(strtolower($column['name']));
                }
                unset($pragma);
            }

            // Create and populate the table if needed
            if (!$table || !in_array('type', $columns)) {
                if ($table && !in_array('type', $columns)) {
                    $this->debug('Removing the current database schema');
                    $this->db->exec('DROP TABLE tld');
                }
                $this->debug('Creating the database schema');
                $this->db->exec('CREATE TABLE tld (tld VARCHAR(20), type VARCHAR(20), description VARCHAR(255))');

                $insert = $this->db->prepare('INSERT INTO tld (tld, type, description) VALUES (:tld, :type, :description)');

                $contents = file_get_contents('http://www.iana.org/domains/root/db/');
                preg_match_all('{<tr class="iana-group[^>]*><td><a[^>]*>\s*\.?([^<]+)\s*(?:<br/><span[^>]*>[^<]*</span>)?</a></td><td>\s*([^<]+)\s*</td><td>\s*([^<]+)\s*}i',
                               $contents, $matches, PREG_SET_ORDER);

                foreach($matches as $match) {
                    list(, $tld, $type, $description) = array_pad($match, 4, null);
                    $type = trim(strtolower($type));
                    if ($type != 'test') {
                        $tld = trim(strtolower($tld));
                        $description = trim($description);

                        switch ($tld) {
                            case 'com':
                                $description = 'Commercial';
                            break;

                            case 'info':
                                $description = 'Information';
                            break;

                            case 'net':
                                $description = 'Network';
                            break;

                            case 'org':
                                $description = 'Organization';
                            break;

                            case 'edu':
                                $description = 'Educational';
                            break;

                            case 'name':
                                $description = 'Individuals, by name';
                            break;
                        }

                        if (empty($tld) || empty($description)) {
                            continue;
                        }

                        $description = preg_replace('{(^(?:Reserved|Restricted)\s*(?:exclusively\s*)?(?:for|to)\s*(?:members of\s*)?(?:the|support)?\s*|\s*as advised.*$)}i', '', $description);
                        $description = ucfirst(trim($description));

                        $data = array_map('html_entity_decode', array(
                            'tld' => $tld,
                            'type' => $type,
                            'description' => $description
                        ));

                        $insert->execute($data);
                        $this->debug('Inserted TLD: ' . $tld . ' = ' . '(' . $type . ') ' . $description);
                    }
                }
                unset($insert, $matches, $match, $tld, $type, $description, $data);
            }

            // Create a prepared statement for retrieving TLDs
            $this->select = $this->db->prepare('SELECT type, description FROM tld WHERE LOWER(tld) = LOWER(:tld)');
            $this->selectAll = $this->db->prepare('SELECT tld, type, description FROM tld');
        } catch (PDOException $e) { }

        // Create a static instance of the class
        self::$instance = $this;
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
     * Performs a lookup on the internal TLD table and sends the description
     * corresponding to a given TLD back to the sender if it exists.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        if (!$this->db) {
            return;
        }

        $target = $this->event->getNick();

        // Command prefix check
        $prefix = preg_quote(trim($this->getIni('command_prefix')));
        $bot = preg_quote($this->getIni('nick'));
        $exp = '(?:(?:' . $bot . '\s*[:,>]?\s+(?:' . $prefix . ')?)|(?:' . $prefix . '))';

        if (preg_match('#^(?:' . $exp . 'tld\s+\.?([a-z]{2,10})$|' . $exp . '?\.([a-z]{2,10}))$#i', $this->event->getArgument(1), $m) &&
            $description = self::getTld((!empty($m[1]) ? $m[1] : $m[2]))) {
            $this->doPrivmsg($this->event->getSource(),
            $target . ': .' . (!empty($m[1]) ? $m[1] : $m[2]) . ' -> ' . ($description ? ucfirst($description) : 'Unknown TLD'));
        }
    }

    /**
     * Retrieves the definition for a given TLD if it exists
     *
     * @param string $tld TLD to search for
     * @return string Defination of the given TLD
     */
    public static function getTld($tld)
    {
        $tld = trim(strtolower($tld));
        if (isset(self::$fixedTlds[$tld])) {
            return self::$fixedTlds[$tld];
        }
        else if (self::$instance->db && self::$instance->select->execute(array('tld' => $tld))) {
            $tlds = self::$instance->select->fetch();
            if (is_array($tlds)) {
                return '(' . $tlds['type'] . ') ' . $tlds['description'];
            }
        }
        return false;
    }

    /**
     * Retrieves a list of all the TLDs and their definations
     *
     * @return array Array of all the TLDs and their definations
     */
    public static function getTlds()
    {
        if (self::$instance->db && self::$instance->selectAll->execute()) {
            $tlds = self::$instance->selectAll->fetchAll();
            if (is_array($tlds)) {
                $tldinfo = array();
                foreach($tlds as $key => $tld) {
                    if (!empty($tld['tld'])) {
                        $tldinfo[$tld['tld']] = '(' . $tld['type'] . ') ' . $tld['description'];
                    }
                }
                unset($tlds);
                return $tldinfo;
            }
        }
        return false;
    }
}
