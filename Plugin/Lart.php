<?php

/**
 * Accepts terms and corresponding definitions for storage to a local data
 * source and performs and returns the result of lookups for term definitions
 * as they are requested.
 */
class Phergie_Plugin_Lart extends Phergie_Plugin_Abstract_Base
{
    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * Date string indicating the last time the cache was emptied
     *
     * @var string
     */
    protected $flushed;

    /**
     * Maps terms to corresponding definitions to serve as an in-memory cache
     *
     * @var array
     */
    protected $cache;

    /**
     * PDO instance for the database
     *
     * @var PDO
     */
    protected $db;

    /**
     * Prepared statement for inserting a new definition into or updating an
     * existing definition in the database
     *
     * @var PDOStatement
     */
    protected $replace;

    /**
     * Prepared statement for selecting the definition of a given term
     *
     * @var PDOStatement
     */
    protected $defination;

    /**
     * Prepared statement for selecting the alias of a given definition
     *
     * @var PDOStatement
     */
    protected $alias;

    /**
     * Prepared statement for deleting the definition for a given term
     *
     * @var PDOStatement
     */
    protected $delete;

    /**
     * Creates the database if needed, connects to it, and sets up prepared
     * statements for common operations.
     *
     * @return void
     */
    public function onInit()
    {
        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'lart.db');
            if (!$this->db) {
                return;
            }

            // Check to see if the table exists
            //$table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote('lart'))->fetchColumn();

            // Create database tables if necessary
            if (!$table) {
                $this->debug('Creating the database schema');
                $this->db->exec('
                    CREATE TABLE lart (name VARCHAR(255), definition TEXT, hostmask VARCHAR(50), tstamp VARCHAR(19));
                    CREATE UNIQUE INDEX lart_name ON lart (name)
                ');
            }
            unset($table);

            // Get the list of columns from the table
            $table = $this->db->query('PRAGMA table_info(' . $this->db->quote('lart') . ')')->fetchAll();

            $columns = array();
            foreach($table as $key => $column) {
                $columns[] = trim(strtolower($column['name']));
            }
            unset($table);

            // Update table as neccessary
            if (!in_array('hostmask', $columns)) {
                $this->debug('Updating the database schema');
                $this->db->exec('
                    BEGIN TRANSACTION;
                    CREATE TEMPORARY TABLE lart_backup (name VARCHAR(255), definition TEXT);
                    INSERT INTO lart_backup SELECT name, definition FROM lart;
                    DROP TABLE lart;
                    CREATE TABLE lart (name VARCHAR(255), definition TEXT, hostmask VARCHAR(50), tstamp VARCHAR(19));
                    INSERT INTO lart SELECT name, definition, NULL, NULL FROM lart_backup;
                    DROP TABLE lart_backup;
                    COMMIT;
                    CREATE UNIQUE INDEX lart_name ON lart (name)
                ');
            }
            unset($table, $key, $column, $columns);

            // Initialize prepared statements for common operations
            $this->replace = $this->db->prepare('
                REPLACE INTO lart (name, definition, hostmask, tstamp) VALUES (:name, :definition, :hostmask, :tstamp)
            ');
            $this->defination = $this->db->prepare('
                SELECT definition FROM lart WHERE LOWER(name) = LOWER(:name)
            ');
            $this->alias = $this->db->prepare('
                SELECT name FROM lart WHERE LOWER(definition) = LOWER(:definition)
            ');
            $this->select = $this->db->prepare('
                SELECT * FROM lart WHERE LOWER(name) = LOWER(:name)
            ');
            $this->delete = $this->db->prepare('
                DELETE FROM lart WHERE LOWER(name) = LOWER(:name)
            ');
        } catch (PDOException $e) { }
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
     * Retrieves the definition for a given term if it exists, from the cache
     * or from the database into the cache if needed, and returns it.
     *
     * @param string $term Term to search for
     * @return mixed String containing the definition or FALSE if no definition
     *               exists
     */
    protected function getDefinition($term)
    {
        if (!isset($this->cache[trim(strtolower($term))])) {
            $this->defination->execute(array(':name' => trim($term)));
            $definition = $this->defination->fetchColumn();
            if ($definition) {
                $this->cache[trim(strtolower($term))] = $definition;
            } else {
                return false;
            }
        }
        return $this->cache[trim(strtolower($term))];
    }

    /**
     * Retrieves the alias for a given definition if it exists from the database
     * and returns it otherwise return FALSE.
     *
     * @param string $term Term to search for
     * @return mixed String containing the definition or FALSE if no alias exist
     */
    protected function getAlias($definition)
    {
        $this->alias->execute(array(':definition' => trim($definition)));
        $names = $this->alias->fetchAll();
        if ($names && count($names) > 0) {
            return $names;
        } else {
            return false;
        }
    }

    /**
     * Searches for a definition for a given term, resolves definition aliases,
     * handles cases of circular references, and sends the definition back to
     * the sender if it exists.
     *
     * @param string $message Message containing the term to search for
     * @return void
     */
    private function checkLart($message)
    {
        if (empty($message) || strlen($message) > 255) {
            return;
        }

        $definition = trim($this->getDefinition($message));
        $seen = array(trim(strtolower($message)));

        if (!empty($definition)) {
            do {
                if (trim(strtolower($message)) == trim(strtolower($definition))) {
                    break;
                }
                $redirect = trim($this->getDefinition($definition));
                if (!empty($redirect)) {
                    if (strtolower($definition) == strtolower($redirect)) {
                        $definition = $redirect;
                        break;
                    }
                    $seen[] = strtolower($definition);
                    if (in_array($redirect, $seen)) {
                        $this->debug('Alias redirection loop detected');
                        $this->deleteLart($message);
                        $mod = $this->getIni('gender') == 'F' ? 'her' : 'his';
                        $this->doAction(
                            $this->event->getSource(),
                            'puts ' . $mod . ' hands over ' . $mod . ' ears and cries, "Stop confusing me!"'
                        );
                        return;
                    }
                    $definition = $redirect;
                }
            } while(!empty($redirect));

            if (substr(strtolower($definition), 0, 3) == '/me') {
                $definition = trim(substr($definition, 3));
                $this->doAction($this->event->getSource(), $definition);
            } else {
                $this->doPrivmsg($this->event->getSource(), $definition);
            }
        }
    }

    /**
     * Recursively gathers an array of aliases linking to the given term and
     * for each term of that alias
     *
     * @param string $term Term to search for
     * @return array An array of aliases for the given term
     */
    public function getAliases($term, $recursive = true, $aliasCache = array())
    {
        if (!isset($aliasCache) || !is_array($aliasCache)) {
            $aliasCache = array();
        }

        $alias = $this->getAlias($term);
        if (is_array($alias)) {
            foreach($alias as $key => $value) {
                $name = $value['name'];
                if (empty($name)) continue;
                if (!in_array($name, $aliasCache)) {
                    $aliasCache[] = $name;
                    if ($recursive) {
                        $aliasCache = $this->getAliases($name, $recursive, $aliasCache);
                    }
                }
            }
        }
        return $aliasCache;
    }

    /**
     * Deletes the given lart and optionally delete all aliases for that lart
     * recursively.
     *
     * @param string $name Term to search for
     * @param bool $recursive Whethere or not to recursively delete aliases
     * @return void
     */
    function deleteLart($name, $recursive = true)
    {
        if (!$recursive) {
            $this->debug('Removing term: ' . $name);
            $this->delete->execute(array(':name' => $name));
            unset($this->cache[trim(strtolower($name))]);
        } else {
            $aliases = $this->getAliases($name);
            $aliases[] = $name;
            $aliases = array_unique($aliases);

            $this->debug('Removing terms: ' . implode(', ', $aliases));
            foreach($aliases as $key => $alias) {
                $this->delete->execute(array(':name' => $alias));
                unset($this->cache[trim(strtolower($alias))]);
            }
        }
    }

    /**
     * Performs a lookup for the contents of messages to the bot or a channel
     * in which the bot is present.
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
        $target = $this->event->getNick();
        $hostmask = $this->event->getHostmask();
        $timenow = time();
        $today = date('md');

        // Command prefix check
        $prefix = preg_quote(trim($this->getIni('command_prefix')));
        $bot = preg_quote($this->getIni('nick'));
        $exp = '(?:(?:' . $bot . '\s*[:,>]?\s+(?:' . $prefix . ')?)|(?:' . $prefix . '))';

        if ($this->flushed != $today) {
            unset($this->cache);
            $this->flushed = $today;
            $this->cache = array();
        }

        $adminOnly = $this->getPluginIni('admin_only');
        if (preg_match('/^' . $exp . 'lartinfo\s+(.*?)$/i', $message, $match)) {
            if ($this->fromAdmin()) {
                $name = trim($match[1]);
                if (!empty($name)) {
                    $this->select->execute(array(':name' => $name));
                    $info = $this->select->fetch();
                    if ($info && count($info) > 0) {
                        $name = $info['name'];
                        $desc = $info['definition'];
                        $host = (isset($info['hostmask']) ? substr($info['hostmask'], 0, strpos($info['hostmask'], '!')) : 'N/A');
                        $time = (isset($info['tstamp']) ? $this->getCountdown(time() -$info['tstamp']) : 'N/A');
                        $aliases = $this->getAliases($name);
                        $aliases = (count($aliases) > 0 ? implode(', ', $aliases) : 'N/A');

                        $this->doPrivmsg(
                            $this->event->getSource(),
                            $target . ': Lart Info -> Term: ' . $name . ' | Definition: ' . $desc . ' | User: ' . $host . ' | Added: ' . $time . ' ago | Aliases: ' . $aliases
                        );
                    } else {
                        $this->doNotice($target, 'Unknown Lart: ' . $name);
                    }
                    unset($info);
                }
            } else {
                $this->doNotice($target, 'You do not have permission to view the lart info.');
            }
        } else if (preg_match('/^(' . $bot . '\s*[:,>]?\s+)?"(.*?)"\s+is\s+"(.*)"$/i', $message, $match)) {
            list(, $address, $name, $definition) = array_pad($match, 4, null);
            if (!empty($name) && !empty($definition) && (empty($address) xor $source[0] == '#')) {
                if (!$adminOnly || $this->fromAdmin()) {
                    $name = trim($name);
                    $definition = trim($definition);

                    if (!empty($name) && !empty($definition)) {
                        $this->debug('Replacing term: ' . $name . ' = ' . $definition);
                        $this->replace->execute(array(':name' => $name, ':definition' => $definition, ':hostmask' => $hostmask, ':tstamp' => $timenow));
                        $this->cache[trim(strtolower($name))] = $definition;
                        $this->doNotice($target, 'Added lart "' . $name . '".');
                    }
                } else {
                    $this->doNotice($target, 'You do not have permission to add larts.');
                }
            }
        } else if (preg_match('/^(' . $exp . ')?forget\s+(.*)$/i', $message, $match)) {
            if (!$adminOnly || $this->fromAdmin()) {
                list(, $address, $name) = array_pad($match, 3, null);
                $name = trim($name);
                if (!empty($name) && (empty($address) xor $source[0] == '#')) {
                    $defination = $this->getDefinition($name);
                    if ($defination) {
                        $this->deleteLart($name);
                        $this->doNotice($target, 'Removed lart "' . $name . '" and all its aliases.');
                    } else {
                        $this->doNotice($target, 'Lart "' . $name . '" does not exist.');
                    }
                }
            } else {
                $this->doNotice($target, 'You do not have permission to remove larts.');
            }
        } else {
            $this->checkLart($message);
        }
    }

    /**
     * Performs a lookup for the contents of CTCP ACTION (/me) commands.
     *
     * @return void
     */
    public function onAction()
    {
        if (!$this->db) {
            return;
        }

        $this->checkLart('/me ' . $this->event->getArgument(1));
    }
}
