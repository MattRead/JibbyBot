<?php

/**
 * Parses and logs commands messages that should be relayed to other users the
 * next time the recipient is active on the same channel
 *
 * (adapted from the Drink plugin)
 */
class Phergie_Plugin_Remind extends Phergie_Plugin_Abstract_Command
{
    /**
     * Number of reminders to show in public
     */
    const PUBLIC_REMINDERS = 3;
    
    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * PDO resource for a SQLite database containing the reminders
     *
     * @var resource
     */
    protected $db = null;

    /**
     * Flag that indicates whether or not to use and in-memory reminder list
     *
     * @var bool
     */
    protected $keepListInMemory = false;

    /**
     * In-memory store for pending reminders
     *
     * Form: $msgStore[channel][recipient] set if a pending reminder exists
     */
    protected $msgStore = array();

    /**
     * Connects to the database and populates tables where needed.
     *
     * @return void
     */
    public function onInit()
    {
        // Initialize the database connection
        $this->db = new PDO('sqlite:' . $this->dir . 'remind.db');
        if (!$this->db) {
            return;
        }
        $this->createTables();
        $this->populateMemory();
       // demo change
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
     * Determines if a table exists
     *
     * @param string $name Table name
     * @return bool
     */
    protected function haveTable($name)
    {
        return (bool) $this->db->query(
            'SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote($name)
            )->fetchColumn();
    }

    /**
     * Creates the database table(s) (if they don't exist)
     *
     * @return void
     */
    protected function createTables()
    {
        if (!$this->haveTable('remind')) {
            $this->debug('Creating the database schema for: remind');
            $this->db->exec('
                CREATE TABLE
                    remind
                    (
                        time INTEGER,
                        channel TEXT,
                        recipient TEXT,
                        sender TEXT,
                        message TEXT
                    )
            ');
        }
    }

    /**
     * Populates the in-memory cache of pending reminders
     *
     * @return void
     */
    protected function populateMemory()
    {
        if (!$this->keepListInMemory) {
            return;
        }
        $storeCounter = 0;
        foreach ($this->fetchMessages() as $msg) {
            $this->msgStore[$msg['channel']][$msg['recipient']] = $msg['rowid'];
            ++$storeCounter;
        }
        $this->debug("Found {$storeCounter} messages", true);
    }

    /**
     * Gets pending messages (for a specific channel/recipient)
     *
     * @param string $channel   channel on which to check pending messages
     * @param string $recipient user for which to check pending messages
     * @return array of records
     */
    protected function fetchMessages($channel = null, $recipient = null)
    {
        if ($channel) {
            $qClause = 'WHERE channel = :channel AND recipient = :recipient';
            $params = array(
                'channel' => $channel,
                'recipient' => strtolower($recipient)
            );
        } else {
            $qClause = '';
            $params = array();
        }
        $q = $this->db->prepare(
            'SELECT rowid, channel, sender, recipient, time, message
            FROM remind ' . $qClause
        );
        $q->execute($params);
        return $q->fetchAll();
    }

    /**
     * Deletes a delivered message
     *
     * @param int    $rowid     ID of the message to delete
     * @param string $channel   message's channel
     * @param string $recipient message's recipient
     * @return void
     */
    protected function deleteMessage($rowid, $channel, $nick)
    {
        $q = $this->db->prepare('DELETE FROM remind WHERE rowid = :rowid');
        $q->execute(array('rowid' => $rowid));

        if ($this->keepListInMemory) {
            if (isset($this->msgStore[$channel][$nick])
                && $this->msgStore[$channel][$nick] == $rowid) {
                unset($this->msgStore[$channel][$nick]);
            }
        }
    }

    /**
     * Get data for a specific message
     *
     * @param int $rowid row ID of the message to get
     */
    protected function getMessage($rowid)
    {
        $q = $this->db->prepare('
            SELECT rowid, time, channel, recipient, sender, message
            FROM remind
            WHERE rowid = :rowid
        ');
        $q->execute(array('rowid' => $rowid));
        return $q->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Handles the tell/remind command (stores the message)
     *
     * @param string $recipient name of the recipient
     * @param string $essage    message to store
     * @return void
     */
    protected function handleRemind($recipient, $message)
    {
        $source = $this->event->getSource();
        $nick = $this->event->getNick();
        if (!$this->event->isInChannel()) {
            $this->doPrivmsg($source, 'Reminders must be requested in-channel.');
            return;
        }
        $q = $this->db->prepare('
            INSERT INTO remind
                (
                    time,
                    channel,
                    recipient,
                    sender,
                    message
                )
            VALUES
                (
                    :time,
                    :channel,
                    :recipient,
                    :sender,
                    :message
                )
        ');
        try {
            $q->execute(array(
                'time' => date(DATE_RFC822),
                'channel' => $source,
                'recipient' => strtolower($recipient),
                'sender' => strtolower($nick),
                'message' => $message
            ));
        } catch (PDOException $e) { }

        if ($rowid = $this->db->lastInsertId()) {
            $this->doPrivmsg($source, "ok, $nick, message stored");
        } else {
            $this->doPrivmsg($source, "$nick: bad things happened. Message not saved.");
            return;
        }

        if ($this->keepListInMemory) {
            $this->msgStore[$source][strtolower($recipient)] = $rowid;
        }
    }

    /**
     * Determines if the user has pending reminders, and if so, delivers them
     *
     * @param string $channel   channel to check
     * @param string $recipient recipient to check
     * @return Bool
     */
    protected function deliverReminders($channel, $nick)
    {
        if ($channel[0] != '#') {
            // private message, not a channel, so don't check
            return;
        }

        // short circuit if there's no message in memory (if allowed)
        if ($this->keepListInMemory
            && !isset($this->msgStore[$channel][strtolower($nick)])) {
            return;
        }
        
        // fetch and deliver messages
        $reminders = $this->fetchMessages($channel, $nick);
        if (count($reminders) > self::PUBLIC_REMINDERS) {
            $msgs = array_slice($reminders, 0, self::PUBLIC_REMINDERS);
            $privmsgs = array_slice($reminders, self::PUBLIC_REMINDERS);
        } else {
            $msgs = $reminders;
            $privmsgs = false;
        }
        foreach ($msgs as $msg) {
            $this->doPrivmsg(
                $channel,
                "{$nick}: (from {$msg['sender']}, " . $this->getCountdown(time() - strtotime($msg['time'])) . " ago) " .
                    $msg['message']
            );
            $this->deleteMessage($msg['rowid'], $channel, $nick);
        }
        if ($privmsgs) {
            foreach ($privmsgs as $msg) {
                $this->doPrivmsg(
                    $nick,
                    "{$nick}: (from {$msg['sender']}, " . $this->getCountdown(time() - strtotime($msg['time'])) ." ago) " .
                        $msg['message']
                );
                $this->deleteMessage($msg['rowid'], $channel, $nick);
            }
            $this->doPrivmsg(
                $channel,
                "{$nick}: (" . count($privmsgs) . " more messages sent in private.)"
            );
        }
    }

    /**
     * Intercepts a message and processes any contained recognized commands.
     *
     * Overrides parent to force bot nick prefix (3rd param of processCommand())
     * Also checks to see if the message's nick has any pending message and
     * delivers them if so
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $this->deliverReminders($this->event->getSource(), $this->event->getNick());
        $this->processCommand($this->event->getArgument(1), false, true);
    }

    /**
     * Handles reminder requests
     *
     * @param string $message
     * @return void
     */
    public function onDoTell($recipient, $message)
    {
        $this->handleRemind($recipient, $message);
    }
    
    /**
     * Handles reminder requests
     *
     * @param string $message
     * @return void
     */
    public function onDoAsk($recipient, $message)
    {
        $this->handleRemind($recipient, $message);
    }

    /**
     * Handles reminder requests
     *
     * @param string $message
     * @return void
     */
    public function onDoRemind($recipient, $message)
    {
        $this->handleRemind($recipient, $message);
    }

public function onDoRemindOut()
{
	$url = Phergie_Plugin_HabariEval::pastoid(print_r($this->msgStore, true));
	$this->doPrivmsg($this->event->getSource(), 'dump: ' . $url);	
}

}
