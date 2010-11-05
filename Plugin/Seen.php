<?php

/**
 * Handles requests for logged data pertaining to the ast actions taken or
 * messages sent by a given user or posts sent containing a given search phrase.
 */
class Phergie_Plugin_Seen extends Phergie_Plugin_Abstract_Command
{
    /**
     * Prepared statement for searching for the last logged action in which a
     * particular user was mentioned
     *
     * @var PDOStatement
     */
    protected $search;

    /**
     * Prepared statement for searching for the last logged action originating
     * from a particular user
     *
     * @var PDOStatement
     */
    protected $seen;

    /**
     * Prepared statement for searching for the last logged message originating
     * from a particular user
     *
     * @var PDOStatement
     */
    protected $heard;

    /**
     * Prepared statement for searching for one or more time ranges during
     * which a particular user is most likely to be present in a particular
     * channel
     *
     * @var PDOStatement
     */
    protected $willsee;

    /**
     * Prepared statement for searching for a random logged message originating
     * from a particular user
     *
     * @var PDOStatement
     */
    protected $quote;

    /**
     * Action descriptions corresponding to event constants
     *
     * @var array
     */
    protected $actions = array(
        Phergie_Plugin_Logging::JOIN    => 'joining this channel',
        Phergie_Plugin_Logging::PART    => 'leaving this channel because',
        Phergie_Plugin_Logging::QUIT    => 'quitting',
        Phergie_Plugin_Logging::PRIVMSG => 'saying',
        Phergie_Plugin_Logging::ACTION  => 'doing the action',
        Phergie_Plugin_Logging::NICK    => 'changing nick to',
        Phergie_Plugin_Logging::KICK    => 'being kicked off because',
        Phergie_Plugin_Logging::QUERY   => 'private messaging',
    );

    /**
     * Initializes the database.
     *
     * @return void
     */
    public function onConnect()
    {
        try {
            if (!Phergie_Plugin_Logging::databaseExists()) {
                return;
            }

            // Initialize prepared statements for common operations
            $this->search = Phergie_Plugin_Logging::prepare('
                SELECT tstamp, type, chan, nick, message
                FROM logs
                WHERE nick LIKE :phrase ESCAPE "\\"
                OR message LIKE :phrase ESCAPE "\\"
                AND type NOT IN (:type, ' . Phergie_Plugin_Logging::MODE . ', ' . Phergie_Plugin_Logging::TOPIC . ')
                ORDER BY tstamp DESC
                LIMIT 1,:limit
            ');

            $this->seen = Phergie_Plugin_Logging::prepare('
                SELECT tstamp, type, chan, message
                FROM logs
                WHERE LOWER(nick) = LOWER(:name)
                AND type NOT IN (' . Phergie_Plugin_Logging::QUERY . ', ' . Phergie_Plugin_Logging::MODE . ', ' . Phergie_Plugin_Logging::TOPIC . ')
                ORDER BY tstamp DESC
                LIMIT :offset,1
            ');

            $this->heard = Phergie_Plugin_Logging::prepare('
                SELECT tstamp, chan, message
                FROM logs
                WHERE type = ' . Phergie_Plugin_Logging::PRIVMSG . '
                AND LOWER(nick) = LOWER(:name)
                ORDER BY tstamp DESC
                LIMIT :offset,1
            ');

            $this->willsee = Phergie_Plugin_Logging::prepare('
                SELECT strftime("%H", tstamp) post_hour, COUNT(*) post_count
                FROM logs
                WHERE type IN (' . Phergie_Plugin_Logging::PRIVMSG . ', ' . Phergie_Plugin_Logging::ACTION . ')
                AND LOWER(nick) = LOWER(:nick)
                AND strftime("%w", tstamp) = strftime("%w", "now")
                GROUP BY strftime("%H", tstamp)
                ORDER BY 2 DESC, 1
                LIMIT 1
            ');

            $this->quote = Phergie_Plugin_Logging::prepare('
                SELECT tstamp, chan, message
                FROM logs
                WHERE type = ' . Phergie_Plugin_Logging::PRIVMSG . '
                AND LOWER(nick) = LOWER(:name)
                AND LOWER(chan) = LOWER(:chan)
                ORDER BY RANDOM()
                LIMIT :offset,1
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
        if (!self::staticPluginLoaded('Logging', $client, $plugins)) {
            return 'Logging plugin must be enabled';
        }
        return true;
    }

    /**
     * Formats a timestamp for display purposes.
     *
     * @param string $timestamp Timestamp to format
     * @return string Formatted timestamp
     */
    protected function formatTimestamp($timestamp)
    {
        return $this->getCountdown(time() - strtotime($timestamp));
    }

    /**
     * Responds to requests for logged messages containing a particular search
     * phrase.
     *
     * @param string $phrase Phrase to search for
     * @return void
     */
    public function onDoSearch($phrase)
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            return;
        }

        $searchAll = false;
        if (substr(strtolower($phrase), 0, 4) == '-all') {
            $phrase = trim(substr($phrase, 4));
            $searchAll = true;
        } else if (substr(strtolower($phrase), 0, 2) == '-a') {
            $phrase = trim(substr($phrase, 2));
            $searchAll = true;
        }
        $searchAll = (!$this->fromAdmin(true) ? false : $searchAll);

        $source = $this->event->getSource();
        $target = $this->event->getNick();

        $params = array(
            ':phrase' => '%' . str_replace(array('\\', '%'), array('\\\\', '\\%'), $phrase) . '%',
            ':limit' => ($source[0] == '#' ? 1 : 6),
            ':type' => ($searchAll ? '0' : Phergie_Plugin_Logging::QUERY)
        );

        try {
            $this->search->execute($params);
            $rows = $this->search->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { }

        if (count($rows) <= 0) {
            $this->doNotice($target, 'I have no records for "' . $phrase . '"');
            return;
        }

        foreach($rows as $row) {
            $this->doPrivmsg(
                $source,
                sprintf(
                    '%s%s was seen %s "%s" on %s (%s ago)',
                    ($source[0] == '#' ? $target . ': ' : ''),
                    $row['nick'],
                    $this->actions[$row['type']],
                    $row['message'],
                    $row['chan'],
                    $this->formatTimestamp($row['tstamp'])
                )
            );
        }
    }

    /**
     * Responds to requests for the last logged action originating from a
     * particular user.
     *
     * @param string $user Nick of the user to search for
     * @return void
     */
    public function onDoSeen($user)
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            return;
        }

        // Don't match if user has a space (obviously it's not a nick)
        if (strpos($user, ' ') !== false) {
            return;
        }

        $source = $this->event->getSource();
        $target = $this->event->getNick();

        // Handle cases where the bot is the subject
        if (strtolower($user) == strtolower($this->getIni('nick'))) {
            $this->doPrivmsg($source, $target . ': Are you blind? I\'m right here!');
            return;
        }

        // Handle 'me' alias
        if ($user == 'me') {
            $user = $target;
        }

        // Get the last event from the specified user
        $params = array(
            ':name' => $user,
            ':offset' => (strtolower($target) == strtolower($user) ? 1 : 0)
        );

        try {
            $this->seen->execute($params);
            $row = $this->seen->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { }

        // Send the last action if available
        if ($row) {
            $this->doPrivmsg(
                $source,
                sprintf(
                    '%s: %s was last seen %s "%s" on %s (%s ago)',
                    $target,
                    $user,
                    $this->actions[$row['type']],
                    $row['message'],
                    $row['chan'],
                    $this->formatTimestamp($row['tstamp'])
                )
            );
        } else {
            $this->doNotice($target, 'I have no records for ' . $user);
        }
    }

    /**
     * Responds to requests for the last logged message originating from a
     * particular user.
     *
     * @param string $user Nick of the user to search for
     * @return void
     */
    public function onDoHeard($user)
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            return;
        }

        // Don't match if user has a space (obviously it's not a nick)
        if (strpos($user, ' ') !== false) {
            return;
        }

        $source = $this->event->getSource();
        $target = $this->event->getNick();

        // Handle cases where the bot is the subject
        if (strtolower($user) == strtolower($this->getIni('nick'))) {
            $this->doPrivmsg($source, $target . ': Are you deaf? Can you not hear me?!');
            return;
        }

        // Handle 'me' alias
        if ($user == 'me') {
            $user = $target;
        }

        // Get the last event from the specified user
        $params = array(
            ':name' => $user,
            ':offset' => (strtolower($target) == strtolower($user) ? 1 : 0)
        );

        try {
            $this->heard->execute($params);
            $row = $this->heard->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { }

        // Send the last action if available
        if ($row) {
            $this->doPrivmsg(
                $source,
                sprintf(
                    '%s: %s was last seen %s "%s" on %s (%s ago)',
                    $target,
                    $user,
                    $this->actions[Phergie_Plugin_Logging::PRIVMSG],
                    $row['message'],
                    $row['chan'],
                    $this->formatTimestamp($row['tstamp'])
                )
            );
        } else {
            $this->doNotice($target, 'I have no records for ' . $user);
        }
    }

    /**
     * Responds to requests for a time range during which a particular user
     * is most likely to be present in the channel from which the request
     * originates.
     *
     * @param string $user Nick of the user to search for
     * @return void
     */
    public function onDoWillsee($user)
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            return;
        }

        $source = $this->event->getSource();
        $target = $this->event->getNick();

        // Check to make sure the request came from a channel
        if ($source[0] != '#') {
            return;
        }

        // Don't match if user has a space (obviously it's not a nick)
        if (strpos($user, ' ') !== false) {
            return;
        }

        // Handle cases where the bot is the subject
        if (strtolower($user) == strtolower($this->getIni('nick'))) {
            $this->doPrivmsg($source, $target . ': What are you talking about? I\'m always here!');
            return;
        }

        // Handle 'me' alias
        if ($user == 'me') {
            $user = $this->event->getNick();
        }

        // Perform the search
        $params = array(
            ':nick' => $user
        );

        try {
            $this->willsee->execute($params);
            $prediction = $this->willsee->fetchColumn();
        } catch (PDOException $e) { }

        // Return if no results are found
        if ($prediction === false) {
            $this->doNotice($target, 'I couldn\'t make a prediction for ' . $user);
            return;
        }

        // Calculate a predicted time of arrival
        $hour = date('H');
        if ($hour > $prediction) {
            $prediction = 24 - ($hour - $prediction);
        } else {
            $prediction = $prediction - $hour;
        }

        // Return with a message including the prediction
        $message = $target . ': ' . $user . ' is most likely to be online ';
        if ($prediction == 0) {
            $message .= 'now!';
        } elseif ($prediction == 1) {
            $message .= 'in 1 hour.';
        } else {
            $message .= 'in ' . $prediction . ' hours.';
        }
        $this->doPrivmsg($source, $message);
    }

    /**
     * Responds to requests for a random message originating from a particular
     * user.
     *
     * @param string $user Nick of the user to search for
     * @return void
     */
    public function onDoQuote($user)
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            return;
        }

        // Don't match if user has a space (obviously it's not a nick)
        if (strpos($user, ' ') !== false) {
            return;
        }

        $source = $this->event->getSource();
        $target = $this->event->getNick();

        // Handle cases where the bot is the subject
        if (strtolower($user) == strtolower($this->getIni('nick'))) {
            $this->doPrivmsg($source, $target . ': Everything I say is amazing, how can I choose just one?');
            return;
        }

        // Handle 'me' alias
        if ($user == 'me') {
            $user = $target;
        }

        // Get the last event from the specified user
        $params = array(
            ':name' => $user,
            ':chan' => $source,
            ':offset' => (strtolower($target) == strtolower($user) ? 1 : 0)
        );

        try {
            $this->quote->execute($params);
            $row = $this->quote->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { }

        // Send the last action if available
        if ($row) {
            $this->doPrivmsg(
                $source,
                sprintf(
                    '%s: %s was quoted saying "%s" on %s (%s ago)',
                    $target,
                    $user,
                    $row['message'],
                    $row['chan'],
                    $this->formatTimestamp($row['tstamp'])
                )
            );
        } else {
            $this->doNotice($target, 'I have no records for ' . $user);
        }
    }
}
