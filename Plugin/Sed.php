<?php

/**
 * Handles Sed search and replace statements in messages and outputs the last
 * line the user said with the changes.
 */
class Phergie_Plugin_Sed extends Phergie_Plugin_Abstract_Base
{
    /**
     * Prepared statement for selecting the second to last line said by the user
     *
     * @var PDOStatement
     */
    protected $select;

    /**
     * The time limit in seconds that can be checked with the sed search and
     * replace statement, set to 0 or below to disable the check
     *
     * @var int
     */
    protected $timeLimit = 0;

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

            $this->select = Phergie_Plugin_Logging::prepare('
                SELECT message, tstamp
                FROM logs
                WHERE type IN (' . Phergie_Plugin_Logging::PRIVMSG . ', ' . Phergie_Plugin_Logging::ACTION . ')
                AND LOWER(nick) = LOWER(:name)
                AND LOWER(chan) = LOWER(:chan)
                ORDER BY tstamp DESC
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

    public function onPrivmsg()
    {
        if (!Phergie_Plugin_Logging::databaseExists()) {
            $this->doPrivmsg($this->event->getSource(), 'no DB');
		return;
        }

        $source = $this->event->getSource();
        $message = $this->event->getArgument(1);
        $target = $this->event->getNick();

        // The regex used to match the Sed search/replace syntax
        $regex = '{^(?:([^\s:]+)\s*[:,>]?\s+)?%?s(\d*)/(.*?)(?<!\\\)/(.*?)(?:(?<!\\\)/([gimsx]*)(\d*)([gimsx]*))?$}ix';
        if (preg_match($regex, $message, $matches)) {
            list($match, $user, $line, $pattern, $replacement, $flags1, $limit, $flags2) = array_pad($matches, 8, NULL);
            // Temp fix for very similar but invalid Sed statements
            if (strpos($replacement, '/') !== false and preg_match('{(?<!\\\)/}', $replacement, $m)) {
                return;
            }
            $replacement = str_replace('\/', '/', $replacement);
            $flags = str_replace('g', '', strtolower($flags1 . $flags2));
            $limit = (isset($limit) && $limit > 0 ? $limit : (stripos($flags1 . $flags2, 'g') !== false ? -1 : 1));
            $name = (!empty($user) ? $user : $target);
            $offset = ((!isset($line) || $line <= 0 ? 1 : $line) -(!empty($user) && strtolower($user) !== strtolower($target) ? 1 : 0));

            if (strtolower($name) == strtolower($this->getIni('nick'))) {
                $this->doPrivmsg($source, $target . ': Don\'t bother correcting me, I\'m always right!');
                return;
            }

            // Get the event for the specified line for the specified user
            $params = array(
                ':chan' => $source,
                ':name' => $name,
                ':offset' => $offset
            );

            try {
                $this->select->execute($params);
                $log = $this->select->fetch();
            } catch (PDOException $e) { $this->doPrivmsg($this->event->getSource(), 'bad DB'); }

            if ($log) {
                $subject = trim($log['message']);
                $tstamp = strtotime($log['tstamp']);

                /**
                 * Check to see if the last thing the the user said was said within the past
                 * 30 minutes if so, check to see if the last thing said wasn't a blank line
                 * and also not another Sed statement.
                 */
                if (($this->timeLimit <= 0 || isset($tstamp) && ((time() - $this->timeLimit) < $tstamp)) &&
                    !empty($subject) && !preg_match($regex, $subject, $m)) {
                    $output = trim(@preg_replace('/' . $pattern . '/' . $flags, $replacement, $subject, $limit));
                    if (!empty($output) && $subject != $output) {
                        $this->doPrivmsg($source,
                            $target . (!empty($user) && strtolower($user) != strtolower($target)? ' thinks ' . $user : '') . ' meant: ' . $output
                        );
                    }
                }
            } else {$this->doPrivmsg($source, 'db fail');}
        }
    }
}
