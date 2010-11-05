<?php

/**
 * Handles reception, transmission, and processing of data sent to and
 * from IRC server.
 */
abstract class Phergie_Driver_Abstract
{
    /**
     * Return codes for the run function, that tells the Bot script whether
     * it should re-run or not
     */
    const RETURN_RECONNECT = "reconnect";
    const RETURN_KEEPALIVE = "keepalive";
    const RETURN_END = "end";
    /**

     /**
     * Associative array mapping configuration setting names to their
     * respective values
     *
     * @var array
     */
    protected $config = array();

    /**
     * List of plugin instances
     *
     * @var array
     */
    protected $plugins;

    /**
     * Returns the value associated with a specified configuration setting.
     *
     * @param string $name Name of the setting
     * @return string Value of the setting, or NULL if the setting is not set
     */
    public final function getIni($name)
    {
        $name = strtolower($name);
        if (!isset($this->config[$name])) {
            return null;
        }
        return $this->config[$name];
    }

    /**
     * Sets the value of a specified configuration setting, overwriting any
     * existing value for that setting.
     *
     * @param string $name Name of the setting
     * @param string $value New value for the setting
     * @return void
     */
    public final function setIni($name, $value)
    {
        $this->config[strtolower($name)] = $value;
    }

    /**
     * Parses a IRC hostmask and sets nick, user and host bits.
     *
     * @param string $hostmask Hostmask to parse
     * @param string $nick Container for the nick
     * @param string $user Container for the username
     * @param string $host Container for the hostname
     * @return void
     */
    public function parseHostmask($hostmask, &$nick, &$user, &$host)
    {
        if (preg_match('/^([^!@]+)!([^@]+)@(.*)$/', $hostmask, $match) > 0) {
            list(, $nick, $user, $host) = array_pad($match, 4, null);
        } else {
            $host = $hostmask;
        }
    }

    /**
     * Converts a delimited string of hostmasks into a regular expression
     * that will match any hostmask in the original string.
     *
     * @param string $list Delimited string of hostmasks
     * @return string Regular expression
     */
    public function hostmasksToRegex($list)
    {
        $patterns = array();

        foreach(preg_split('#[\s\r\n,]+#', $list) as $hostmask) {
            // Find out which chars are present in the config mask and exclude them from the regex match
            $excluded = '';
            if (strpos($hostmask, '!') !== false) {
                $excluded .= '!';
            }
            if (strpos($hostmask, '@') !== false) {
                $excluded .= '@';
            }

            // Escape regex meta characters
            $hostmask = str_replace(
            array('\\',   '^',   '$',   '.',   '[',   ']',   '|',   '(',   ')',   '?',   '+',   '{',   '}'),
            array('\\\\', '\\^', '\\$', '\\.', '\\[', '\\]', '\\|', '\\(', '\\)', '\\?', '\\+', '\\{', '\\}'),
            $hostmask
            );

            // Replace * so that they match correctly in a regex
            $patterns[] = str_replace('*', ($excluded === '' ? '.*' : '[^' . $excluded . ']*'), $hostmask);
        }

        return ('#^' . implode('|', $patterns) . '$#i');
    }

    /**
     * Sends a debugging message to stdout if the debug configuration setting
     * is enabled.
     *
     * @param string $message Debugging message
     * @param bool $displayDebug Toggle whether to display the message in the
     *                           console or not
     * @return void
     */
    public function debug($message, $displayDebug = true)
    {
        if ($this->getIni('debug')) {
            $message = '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
            if ($displayDebug) {
                echo $message;
            }
            if ($log = $this->getIni('log') and !empty($log)) {
                file_put_contents($log, $message, FILE_APPEND);
            }
        }
    }

    /**
     * Adds a set of callbacks for events received from the server.
     *
     * @param Phergie_Plugin_Abstract_Base $plugin
     */
    public function addPlugin(Phergie_Plugin_Abstract_Base $plugin)
    {
        $this->plugins[strtolower($plugin->getName())] = $plugin;
    }

    /**
     * Returns all the plugins.
     *
     * @return Phergie_Plugin_Abstract_Base
     */
    public function getPlugins()
    {
        return $this->plugins;
    }

    /**
     * Returns a list of all the plugins either currently loaded or within the
     * plugin directory.
     *
     * @param $plugin $dirList If true, scan the Plugin directory for the list
     * @return array
     */
    public function getPluginList($dirList = false, $preserveExt = false)
    {
        if (!$dirList) {
            return array_keys($this->plugins);
        } else {
            $iterator = new DirectoryIterator(PHERGIE_PLUGIN_DIR);
            $plugins = array();

            foreach($iterator as $filename) {
                if ($iterator->isFile() && pathinfo($filename, PATHINFO_EXTENSION) == 'php') {
                    $plugins[] = ($preserveExt ? (string)$filename : basename($filename, '.php'));
                }
            }
            unset($iterator, $filename, $dirList);

            return $plugins;
        }
    }

    /**
     * Returns a plugin instance.
     *
     * @param $plugin The plugin class name (without the Phergie_Plugin_ prefix)
     * @return Phergie_Plugin_Abstract_Base
     */
    public function getPlugin($plugin)
    {
        $plugin = strtolower($plugin);
        if (isset($this->plugins[$plugin])) {
            return $this->plugins[$plugin];
        }
        return false;
    }

    /**
     * Executes a continuous loop in which the client listens for events from
     * the server and processes them until the connection is terminated.
     *
     * @return void
     */
    public abstract function run();

    /**
     * Terminates the connection with the server.
     *
     * @param string $reason Reason for connection termination (optional)
     * @param bool $reconnect if true, the bot will reconnect to the server
     * @return void
     */
    public abstract function doQuit($reason = null, $reconnect = false);

    /**
     * Joins a channel.
     *
     * @param string $channel Name of the channel to join
     * @param string $keys Channel key if needed (optional)
     * @return void
     */
    public abstract function doJoin($channel, $key = null);

    /**
     * Leaves a channel.
     *
     * @param string $channel Name of the channel to leave
     * @return void
     */
    public abstract function doPart($channel);

    /**
     * Invites a user to an invite-only channel.
     *
     * @param string $nick Nick of the user to invite
     * @param string $channel Name of the channel
     * @return void
     */
    public abstract function doInvite($nick, $channel);

    /**
     * Obtains a list of nicks of usrs in currently joined channels.
     *
     * @param string $channels Comma-delimited list of one or more channels
     * @return void
     */
    public abstract function doNames($channels);

    /**
     * Obtains a list of channel names and topics.
     *
     * @param string $channels Comma-delimited list of one or more channels
     *                         to which the response should be restricted
     *                         (optional)
     * @return void
     */
    public abstract function doList($channels = null);

    /**
     * Retrieves or changes a channel topic.
     *
     * @param string $channel Name of the channel
     * @param string $topic New topic to assign (optional)
     * @return void
     */
    public abstract function doTopic($channel, $topic = null);

    /**
     * Retrieves or changes a channel or user mode.
     *
     * @param string $target Channel name or user nick
     * @param string $mode New mode to assign (optional)
     * @return void
     */
    public abstract function doMode($target, $mode = null);

    /**
     * Changes the client nick.
     *
     * @param string $nick New nick to assign
     * @return void
     */
    public abstract function doNick($nick);

    /**
     * Retrieves information about a nick.
     *
     * @param string $nick
     * @return void
     */
    public abstract function doWhois($nick);

    /**
     * Sends a message to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the message to send
     * @return void
     */
    public abstract function doPrivmsg($target, $text);

    /**
     * Sends a notice to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the notice to send
     * @return void
     */
    public abstract function doNotice($target, $text);

    /**
     * Kicks a user from a channel.
     *
     * @param string $nick Nick of the user
     * @param string $channel Channel name
     * @param string $reason Reason for the kick (optional)
     * @return void
     */
    public abstract function doKick($nick, $channel, $reason = null);

    /**
     * Responds to a server test of client responsiveness.
     *
     * @param string $daemon Daemon from which the original request originates
     * @return void
     */
    public abstract function doPong($daemon);

    /**
     * Sends a CTCP ACTION (/me) command to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the action to perform
     * @return void
     */
    public abstract function doAction($target, $text);
}
