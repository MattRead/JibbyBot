<?php

/**
 * Logs all channel events and stores them to the database and also provides
 * an API for other plugins to retrieve this information.
 */
class Phergie_Plugin_Logging extends Phergie_Plugin_Abstract_Command
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * Indicates a JOIN event in the type column of the logs table
     *
     * @const int
     */
    const JOIN = 1;

    /**
     * Indicates a PART event in the type column of the logs table
     *
     * @const int
     */
    const PART = 2;

    /**
     * Indicates a QUIT event in the type column of the logs table
     *
     * @const int
     */
    const QUIT = 3;

    /**
     * Indicates a PRIVMSG event in the type column of the logs table
     *
     * @const int
     */
    const PRIVMSG = 4;

    /**
     * Indicates a CTCP ACTION event in the type column of the logs table
     *
     * @const int
     */
    const ACTION = 5;

    /**
     * Indicates a NICK event in the type column of the logs table
     *
     * @const int
     */
    const NICK = 6;

    /**
     * Indicates a KICK event in the type column of the logs table
     *
     * @const int
     */
    const KICK = 7;

    /**
     * Indicates a MODE event in the type column of the logs table
     *
     * @const int
     */
    const MODE = 8;

    /**
     * Indicates a TOPIC event in the type column of the logs table
     *
     * @const int
     */
    const TOPIC = 9;

    /**
     * Indicates a QUERY event in the type column of the logs table
     * This indicates that the message type is a PM
     *
     * @const int
     */
    const QUERY = 10;

    /**
     * PDO instance for the database
     *
     * @var PDO
     */
    protected $db = null;

    /**
     * Prepared statement for inserting new log entries
     *
     * @var PDOStatement
     */
    protected $insert;

    /**
     * Static PDO instance for the database
     *
     * @var PDO
     */
    protected static $staticDB;

    /**
     * Initializes the database.
     *
     * @return void
     */
    public function onInit()
    {
        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'logging.db');
            if (!is_object($this->db)) {
                return;
            }

            // Check to see if the table exists
            $table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote('logs'))->fetchColumn();

            // Create database tables if necessary
            if (!$table) {
                $this->debug('Creating the database schema');
                $result = $this->db->exec('
                    CREATE TABLE logs (
                        tstamp VARCHAR(19),
                        type SHORTINT,
                        chan VARCHAR(45),
                        nick VARCHAR(25),
                        message VARCHAR(255)
                    );
                    CREATE INDEX channicktype ON logs (tstamp, type, chan, nick);
                    CREATE INDEX channick ON logs (tstamp, chan, nick);
                ');
            }

            $this->insert = $this->db->prepare('
                INSERT INTO logs (tstamp,type,chan,nick,message) VALUES (:tstamp,:type,:chan,:nick,:message)
            ');
        } catch (PDOException $e) { }
        self::$staticDB = $this->db;
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

    	if (!self::staticPluginLoaded('ServerInfo', $client, $plugins)) {
            $errors[] = 'ServerInfo plugin must be enabled';
        }
    	if (!extension_loaded('PDO')) {
            $errors[] = 'PDO php extension is required';
    	}
    	if (!extension_loaded('pdo_sqlite')) {
            $errors[] = 'pdo_sqlite php extension is required';
    	}

        return empty($errors) ? true : $errors;
    }

    /**
     * Inserts a new entry in the log database.
     *
     * @param int $type Class constant representing the event type
     * @param string $chan Name of the channel in which the event occurs
     * @param string $nick Nick of the user from which the event originates
     * @param string $message Message associated with the event if applicable
     *                        (optional)
     * @return void
     */
    protected function insertEvent($type, $chan, $nick, $message = null)
    {
        if (!is_object($this->db)) {
            return;
        }

        $params = array(
            ':tstamp' => date('Y-m-d H:i:s'),
            ':type' => $type,
            ':chan' => $chan,
            ':nick' => $nick,
            ':message' => trim($message)
        );

        $result = $this->insert->execute($params);
    }

    /**
     * Logs incoming messages.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        // Allow the Command plugin to process command calls
        parent::onPrivmsg();
        
		if ( stripos(trim($this->event->getArgument(1)), '[off]') === 0 ) {
			return;
		}
		
        $this->insertEvent(
            ($this->event->isInChannel() ? self::PRIVMSG : self::QUERY),
            $this->event->getSource(),
            $this->event->getNick(),
            $this->event->getArgument(1)
        );
    }

    /**
     * Logics incoming actions.
     *
     * @return void
     */
    public function onAction()
    {
        if ($this->event->isInChannel()) {
            $this->insertEvent(
                self::ACTION,
                $this->event->getSource(),
                $this->event->getNick(),
                $this->event->getArgument(1)
            );
        }
    }

    /**
     * Tracks users joining.
     *
     * @return void
     */
    public function onJoin()
    {
        $this->insertEvent(
            self::JOIN,
            $this->event->getSource(),
            $this->event->getNick(),
            NULL
        );
    }

    /**
     * Tracks users parting.
     *
     * @return void
     */
    public function onPart()
    {
        $this->insertEvent(
            self::PART,
            $this->event->getSource(),
            $this->event->getNick(),
            $this->event->getArgument(1)
        );
    }

    /**
     * Tracks users being kicked.
     *
     * @return void
     */
    public function onKick()
    {
        $this->insertEvent(
            self::KICK,
            $this->event->getSource(),
            $this->event->getNick(),
            $this->event->getArgument(1)
        );
    }

    /**
     * Tracks users changing modes.
     *
     * @return void
     */
    public function onMode()
    {
        $this->insertEvent(
            self::MODE,
            $this->event->getSource(),
            $this->event->getNick(),
            implode(' ', array_slice($this->event->getArguments(), 1))
        );
    }

    /**
     * Tracks channel topic changes.
     *
     * @return void
     */
    public function onTopic()
    {
        $this->insertEvent(
            self::TOPIC,
            $this->event->getSource(),
            $this->event->getNick(),
            implode(' ', array_slice($this->event->getArguments(), 1))
        );
    }

    /**
     * Tracks users quitting.
     *
     * @return void
     */
    public function onQuit()
    {
        $nick = $this->event->getNick();

        foreach(Phergie_Plugin_ServerInfo::getChannels($nick) as $chan) {
            $this->insertEvent(
                self::QUIT,
                $chan,
                $this->event->getNick(),
                $this->event->getArgument(0)
            );
        }
    }

    /**
     * Tracks users changing nicks.
     *
     * @return void
     */
    public function onNick()
    {
        $nick = $this->event->getNick();

        foreach(Phergie_Plugin_ServerInfo::getChannels($nick) as $chan) {
            $this->insertEvent(
                self::NICK,
                $chan,
                $this->event->getNick(),
                $this->event->getArgument(0)
            );
        }
    }
    
    public function onDoPointer()
    {
    	$time = date('Y-m-d#\TH-i-s', time());
    	$this->doPrivmsg(
			$this->event->getSource(),
			sprintf(
				'http://drunkenmonkey.org/irc/%s/%s',
				ltrim($this->event->getSource(), '#'),
				$time
			)
		);
		unset($time);
	}
	
	public function onDoWordCount($word)
	{
		$count = $this->db->prepare('SELECT COUNT(*) FROM logs WHERE message like ? AND chan = ?');
		$count->execute(array("%$word%", $this->event->getSource()));
		$c=$count->fetchColumn();
		$this->doPrivmsg(
            $this->event->getSource(),
            sprintf(
                '%s has been used %s times',
                $word,
                $c
            )
        );
	}

	public function onDoGrep($term)
    {
    	$this->doPrivmsg(
			$this->event->getSource(),
			sprintf(
				'http://drunkenmonkey.org/irc/%s/grep/%s',
				ltrim($this->event->getSource(), '#'),
				rawurlencode(str_replace('+', '%2B', $term))
			)
		);
	}

    public static function databaseExists()
    {
        return (is_object(self::$staticDB) ? true : false);
    }

    public static function prepare($query)
    {
        if (!is_object(self::$staticDB)) {
            return false;
        }

        return self::$staticDB->prepare($query);
    }
}
