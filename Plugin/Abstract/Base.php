<?php

/**
 * Base class for handlers of events received from the IRC server to provide
 * empty handler functions for cases where no action should be taken.
 */
abstract class Phergie_Plugin_Abstract_Base
{
    /**
     * Flag indicating whether or not the plugin requires its own directory
     * for local storage
     *
     * @see $dir
     * @var bool
     */
    protected $needsDir = false;

    /**
     * Path to the directory for the plugin if $needsDir is enabled
     *
     * @see $needsDir
     */
    protected $dir;

    /**
     * Reference back to the client, used to initiate commands
     *
     * @var Phergie_Driver_Abstract
     */
    protected $client;

    /**
     * Short class name
     *
     * @var string
     */
    protected $name;

    /**
     * Last intercepted event
     *
     * @var Phergie_Event_Request|Phergie_Event_Response
     */
    protected $event;

    /**
     * List of administrator hostmasks
     *
     * @var array
     */
    protected $adminList = array();

    /**
     * List of channel ops
     *
     * @var array
     */
    protected $opList = array();

    /**
     * Flag indicating whether or not the plugin is enabled and receives events
     *
     * @var bool
     */
    public $enabled = true;

    /**
     * Flag indicating whether or not the plugin is muted and can output events
     *
     * @var array
     */
    public $muted = array();

    /**
     * Flag indicating whether or not the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = false;

    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = false;

    /**
     * Sets a reference to the client used to initiate commands.
     *
     * @param Phergie_Driver_Abstract $client
     * @return void
     */
    final public function __construct(Phergie_Driver_Abstract $client)
    {
        // Temp fix till a better one is added
        @set_error_handler(array(__CLASS__, 'onBaseError'));
        $this->client = $client;

        $name = get_class($this);
        $this->name = substr($name, strrpos($name, '_') + 1);

        if ($this->needsDir) {
            $class = new ReflectionClass($name);
            $dir = dirname($class->getFilename()) . DIRECTORY_SEPARATOR . $this->name . DIRECTORY_SEPARATOR;
            if (!file_exists($dir)) {
                mkdir($dir);
            }
            $this->dir = $dir;
        }
        $this->onInit();
    }

    /**
     * Base error handler for PHP errors. This functions called onPhpError for
     * any clas extending it to give each plugin its own error handler.
     *
     * @return bool
     */
    public final function onBaseError($errno, $errstr, $errfile, $errline)
    {
        if (!isset($this)) {
            return false;
        }

        return $this->onPhpError($errno, $errstr, $errfile, $errline);
    }

    /**
     * Returns the short name of the current plugin.
     *
     * @return string
     */
    public function getName()
    {
        return $this->name;
    }

    /**
     * Returns the value of a specified configuration setting.
     *
     * @param string $setting Full name of the setting including the plugin
     *                        name prefix (ex: pluginname.settingname)
     * @return mixed
     */
    public function getIni($setting)
    {
        return $this->client->getIni($setting);
    }

    /**
     * Returns the value of a specified configuration setting for the current
     * plugin.
     *
     * @param string $setting Name of the setting without the plugin name
     *                        prefix
     * @return mixed
     */
    public function getPluginIni($setting)
    {
        return $this->client->getIni($this->getName() . '.' . $setting);
    }

    /**
     * Sets the value of a specified configuration setting.
     *
     * @param string $setting Full name of the setting including the plugin
     *                        name prefix (ex: pluginname.settingname)
     * @param mixed $value New value for the setting
     * @return void
     */
    public function setIni($setting, $value)
    {
        $this->client->setIni($setting, $value);
    }

    /**
     * Sets the value of a specified configuration setting for the current
     * plugin.
     *
     * @param string $setting Name of the setting without the plugin name
     *                        prefix
     * @param mixed $value New value for the setting
     * @return void
     */
    public function setPluginIni($setting, $value)
    {
        $this->client->setIni($this->getName() . '.' . $setting, $value);
    }

    /**
     * Stores the last intercepted event. Should only be called by drivers.
     *
     * @param Phergie_Event_Request|Phergie_Event_Response $event
     * @return void
     */
    public function setEvent($event)
    {
        $this->event = $event;
    }

    /**
     * Shorthand for the underlying driver's debugging function.
     *
     * @param string $message Message to log
     * @param bool $displayDebug Toggle whether to display the message in the
     *                           console or not
     * @return void
     */
    public function debug($message, $displayDebug = true)
    {
        $this->client->debug('<' . strtolower($this->name) . '> ' . $message, $displayDebug);
    }

    /**
     * Decodes the entities of a given string and
     * transliterates the UTF-8 string into corresponding ASCII characters.
     *
     * @param string $text The text to decode.
     * @param string $charSetFrom The charset used as the base chrset to convert from
     * @param string $charSetTo The charset used to convert to
     * @return string
     */
    public function decodeTranslit($text, $charSetFrom = 'UTF-8', $charSetTo = 'ISO-8859-1')
    {
        $text = html_entity_decode($text, ENT_QUOTES, $charSetFrom);
        if (strpos($text, '&#') !== false) {
            $text = preg_replace('/&#0*([0-9]+);/me', '$this->codeToUtf(\\1)', $text);
            $text = preg_replace('/&#x0*([a-f0-9]+);/mei', '$this->codeToUtf(hexdec(\\1))', $text);
        }

        // Use the translit extension if installed else fallback on to basic transliteration
        if (extension_loaded('iconv')) {
            $text = iconv($charSetFrom, $charSetTo . '//TRANSLIT', $text);
        // Transliteration supprt via the translit extension is still experimental
        } else if (false && extension_loaded('translit')) {
            $text = transliterate($text, array('han_transliterate', 'diacritical_remove'), $charSetFrom, $charSetTo);
        } else {
            $text = strtr($text, 'ÀÁÂÃÄÅÆÇÈÉÊËÌÍÎÏÐÑÒÓÔÕÖØÙÚÛÜÝÞßàáâãäåæçèéêëìíîïðñòóôõöøùúûüýþÿ',
                                 'AAAAAAACEEEEIIIIDNOOOOOOUUUUYPYaaaaaaaceeeeiiiidnoooooouuuuypy');
            $text = preg_replace('{[^a-z0-9&|"#\'\{\}()§^!°\[\]$*¨µ£%´`~=+:/;.,?><\\ _-]}i', '', $text);
            $text = utf8_decode($text);
        }

        return $text;
    }

    /**
     * Converts a given unicode to its UTF-8 equivalent.
     *
     * @param int $code The code to decode to UTF-8.
     * @return string
     */
    public function codeToUtf($code)
    {
        $code = intval($code);
        switch ($code) {
            // 1 byte, 7 bits
            case 0:
                return chr(0);
            case ($code&0x7F):
                return chr($code);

            // 2 bytes, 11 bits
            case ($code&0x7FF):
                return chr(0xC0|(($code>>6) &0x1F)) .
                       chr(0x80|($code&0x3F));

            // 3 bytes, 16 bits
            case ($code&0xFFFF):
                return chr(0xE0|(($code>>12) &0x0F)) .
                       chr(0x80|(($code>>6) &0x3F)) .
                       chr(0x80|($code&0x3F));

            // 4 bytes, 21 bits
            case ($code&0x1FFFFF):
                return chr(0xF0|($code>>18)) .
                       chr(0x80|(($code>>12) &0x3F)) .
                       chr(0x80|(($code>>6) &0x3F)) .
                       chr(0x80|($code&0x3F));
        }
    }

    /**
     * Parses a string of command line like arguments and returns them as an array
     * Parses the following:
     *    -Strings: "" | "foo" | "foo bar" | "foo \" bar" | foo
     *    -Options: --opt | --opt foo | --opt= | --opt=foo | --opt="" | --opt="foo" | --opt="foo bar"
     *      @Note: If no parameter is given, the value returned by the option defaults to
     *            1 unless its an empty value such as --opt="" or --opt=
     *    -Flags: -flag +flag
     *      @Note: Flags are handled using bitwise. 1 = -flag, 2 = +flag, 3 = Both a +flag and -flag
     *
     * The results get stored in an array for the following structure:
     *     Array -> strings []                   -Array containing all the string matches
     *     Array -> commands [command] => value  -Array containing all the string matches
     *     Array -> flags [flag] => bitwise      -Array containing all the string matches
     *     Array -> all [strings|commands|flags] -Array containing a list of all the
     *                                            strings, commands and flags
     *
     * @param string $args String of arguments that will be parsed
     * @return array
     */
    public function parseArguments($args)
    {
        // Strip away multiple spaces
        $args = ' ' . preg_replace('#\s+#', ' ', trim($args)) . ' ';

        // Check the args string for agurments
        preg_match_all('{' .
        // String Regex: Matches "" | "foo" | "foo bar" | "foo \" bar" | foo
        '(".*?(?<!\\\)" | [^-+\s]+) | ' .
        // Option Regex: Matches --opt | --opt foo | --opt= | --opt=foo | --opt="" | --opt="foo" | --opt="foo bar"
        '(--\w+ (?:=".*?(?<!\\\)" | [=\s][^-+"\s]*)?) | ' .
        // Flag Regex: Matches -flag +flag
        '([-+]\w+)}xi', $args, $matches);
        // Shifts the first group matches element off of matches
        $matches = array_shift($matches);

        // Returned data structure
        $data = array(
            'strings'  => array(),
            'commands' => array(),
            'flags'    => array(),
            'all'      => array(
                'strings'  => '',
                'commands' => array(),
                'flags'    => array()
            )
        );

        // Loop through the results
        foreach($matches as $match) {
            $match = trim($match);
            // Check to see if the current match an an --option
            if (substr($match, 0, 2) === '--') {
                $value = preg_split('/[=\s]/', $match, 2);
                $com = substr(array_shift($value), 2);
                $value = trim(join($value));
                // Strip quotes from the option's value
                $realValue = (substr($value, 0, 1) === '"' && substr($value, -1) === '"' || substr($match, -1) === '=');
                if (substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
                    $value = trim(substr($value, 1, -1));
                }
                if (!in_array($com, $data['all']['commands'])) {
                    $data['all']['commands'][] = $com;
                }
                $data['commands'][$com] = (!empty($value) || $realValue ? str_replace('\"', '"', $value) : true);
                continue;
            }

            // Check to see if the current match is a -flag
            if (substr($match, 0, 1) === '-' || substr($match, 0, 1) === '+') {
                $flag = substr($match, 1);
                if (!in_array($flag, $data['all']['flags'])) {
                    $data['all']['flags'][] = $flag;
                }
                // Handle the flags using bitwise operations. 1=-flag, 2=+flag, 3=Both a plus and minus
                if (isset($data['flags'][$flag])) {
                    $data['flags'][$flag] |= (substr($match, 0, 1) === '-' ? 0x1 : 0x2);
                } else {
                    $data['flags'][$flag] = (substr($match, 0, 1) === '-' ? 0x1 : 0x2);
                }
                continue;
            }

            // Strip the quotes away from match
            if (substr($match, 0, 1) === '"' && substr($match, -1) === '"') {
                $match = trim(substr($match, 1, -1));
            }
            // The match value isn't a flag or an option so consider it a string
            if (!empty($match)) {
                $data['strings'][] = str_replace('\"', '"', $match);
            }
        }

        $data['all']['strings'] = trim(implode(' ', $data['strings']));
        return $data;
    }

    /**
     * Converts a given integer/timestamp into days, minutes and seconds
     *
     * @param int $time The time/integer to calulate the values from
     * @return string
     */
    public function getCountdown($time)
    {
        $return = array();

        $days = floor($time / 86400);
        if ($days > 0) {
            $return[] = $days . 'd';
            $time %= 86400;
        }

        $hours = floor($time / 3600);
        if ($hours > 0) {
            $return[] = $hours . 'h';
            $time %= 3600;
        }

        $minutes = floor($time / 60);
        if ($minutes > 0) {
            $return[] = $minutes . 'm';
            $time %= 60;
        }

        if ($time > 0 || count($return) <= 0) {
            $return[] = ($time > 0 ? $time : '0') . 's';
        }

        return implode(' ', $return);
    }

    /**
     * Checks if a specified plugin is loaded
     *
     * @param string $plugin Plugin to check
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @return bool
     */
    public function pluginLoaded($plugin, $plugins = null, $client = null)
    {
        $plugin = trim($plugin);
        if (!empty($plugin)) {
            if (!$client) {
                $client = $this->client;
            }
            if (!is_array($plugins) || count($plugins) <= 0) {
                $plugins = $this->getPluginList();
            }
            $plugins = array_map('strtolower', $plugins);
            if (in_array(strtolower($plugin), $plugins) && class_exists('Phergie_Plugin_' . $plugin) &&
                call_user_func(array('Phergie_Plugin_' . $plugin, 'checkDependencies'), $client, $plugins) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Static call to check if a specified plugin is loaded
     *
     * @param string $plugin Plugin to check
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @return bool
     */
    public static function staticPluginLoaded($plugin, $client, array $plugins)
    {
        $plugin = trim($plugin);
        if (!empty($plugin) && is_array($plugins)) {
            $plugins = array_map('strtolower', $plugins);
            if (in_array(strtolower($plugin), $plugins) && class_exists('Phergie_Plugin_' . $plugin) &&
                call_user_func(array('Phergie_Plugin_' . $plugin, 'checkDependencies'), $client, $plugins) === true) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns whether or not a message originated from an authorized admin or
     * op.
     *
     * @param bool $hostmaskAdminOnly Whether or not to allow just hostmask
     *                                admins or not
     * @return bool TRUE if the message originated from an authorized
     *              individual, FALSE otherwise
     */
    public function fromAdmin($hostmaskAdminOnly = false)
    {
        $class = $this->getName();
        // Check to see if the current class has any admin or ops settings specified
        $ini = $this->getPluginIni('ops');
        if (is_null($ini)) {
            $ini = $this->getIni('admincommand.ops');
        }
        $this->opList[$class] = ((strtolower($ini) === 'true' || $ini === '1'));

        // Handle the admin settings
        $ini = trim($this->getPluginIni('admins') . ' ' . $this->getIni('admincommand.admins'));
        $this->adminList[$class] = (!empty($ini) ? $this->hostmasksToRegex($ini) : null);
        unset($ini);

        // Try to match mask against admin masks
        if (!empty($this->adminList[$class]) &&
            preg_match($this->adminList[$class], $this->event->getHostmask())) {
            return true;
        }

        // Check if is op and ops are admins
        if (!$hostmaskAdminOnly && !empty($this->opList[$class]) && $this->pluginLoaded('ServerInfo')) {
            $nick = $this->event->getNick();
            $source = $this->event->getSource();
            if (Phergie_Plugin_ServerInfo::isOp($nick, $source)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Returns whether or not the current environment meets the requirements
     * of the plugin in order for it to be run, including the PHP version,
     * loaded PHP extensions, and other plugins intended to be loaded.
     * Plugins with such requirements should override this method.
     *
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @return bool TRUE if dependencies are met, FALSE otherwise
     */
    public static function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
    {
        return true;
    }

    /**
     * Initializes the plugin. Should it require initialization, just
     * override this method.
     *
     * @return void
     */
    public function onInit() { }

    /**
     * Shuts down the plugin, called just before the client exits. Should the
     * plugin require any cleanup actions, just override this method.
     *
     * @return void
     */
    public function onShutdown() { }

    /**
     * Handler for when the server prompts the client for a nick.
     *
     * @return void
     */
    public function onNick() { }

    /**
     * Handler for when a user obtains operator privileges.
     *
     * @return void
     */
    public function onOper() { }

    /**
     * Handler for when the client session is about to be terminated.
     *
     * @return void
     */
    public function onQuit() { }

    /**
     * Handler for when a user joins a channel.
     *
     * @return void
     */
    public function onJoin() { }

    /**
     * Handler for when a user leaves a channel.
     *
     * @return void
     */
    public function onPart() { }

    /**
     * Handler for when a user or channel mode is changed.
     *
     * @return void
     */
    public function onMode() { }

    /**
     * Handler for when a channel topic is viewed or changed.
     *
     * @return void
     */
    public function onTopic() { }

    /**
     * Handler for when a message is received from a channel or user.
     *
     * @return void
     */
    public function onPrivmsg() { }

    /**
     * Handler for when an action is received from a channel or user
     *
     * @return void
     */
    public function onAction() { }

    /**
     * Handler for when a notice is received.
     *
     * @return void
     */
    public function onNotice() { }

    /**
     * Handler for when a user is kicked from a channel.
     *
     * @return void
     */
    public function onKick() { }

    /**
     * Handler for when the server or a userchecks the client connection to
     * ensure activity.
     *
     * @return void
     */
    public function onPing() { }

    /**
     * Handler for when the server sends a CTCP Time request
     *
     * @return void
     */
    public function onTime() { }

    /**
     * Handler for when the server sends a CTCP Version request
     *
     * @return void
     */
    public function onVersion() { }

    /**
     * Handler for when the server sends a CTCP request
     *
     * @return void
     */
    public function onCtcp() { }

    /**
     * Handler for the reply received when a ping CTCP is sent
     *
     *
     * @return void
     */
    public function onPingReply() { }

    /**
     * Handler for the reply received when a time CTCP is sent
     *
     * @return void
     */
    public function onTimeReply() { }

    /**
     * Handler for the reply received when a version CTCP is sent
     *
     * @return void
     */
    public function onVersionReply() { }

    /**
     * Handler for the reply received when a CTCP is sent
     *
     * @return void
     */
    public function onCtcpReply() { }

    /**
     * Handler for raw requests from the server
     *
     * @return void
     */
    public function onRaw() { }

    /**
     * Handler for when an unhandled error occurs.
     *
     * @return void
     */
    public function onError() { }

    /**
     * Handler for when the server sends a kill request.
     *
     * @return void
     */
    public function onKill() { }

    /**
     * Handler for when a server response is received to a client-issued
     * command.
     *
     * @return void
     */
    public function onResponse() { }

    /**
     * Handler for when the bot connects to the server
     *
     * @return void
     */
    public function onConnect() { }

    /**
     * Handler for each iteration of the while loop while connected to the
     * server
     *
     * @return void
     */
    public function onTick() { }

    /**
     * Handler for when a user sends an invite request
     *
     * @return void
     */
    public function onInvite() { }

    /**
     * Handler for when PHP throws an error
     *
     * @return void
     */
    public function onPhpError($errno, $errstr, $errfile, $errline) { return false; }


    /**
     * Defers calls to command methods to the client, used to alleviate the
     * need to explicitly refer to the client instance for all command method
     * calls.
     *
     * @param string $method Name of the method called
     * @param array $arguments Arguments passed in the method call
     * @return mixed Return value of the method call
     */
    public function __call($method, $arguments)
    {
        // Silence output calls if the plugin is muted for that source or globally
        $source = null;
        if (isset($arguments[0])) {
            $source = trim(strtolower($arguments[0]));
        }
        if (((!empty($source) && isset($this->muted[$source]) && $this->muted[$source]) ||
            (isset($this->muted['global']) && $this->muted['global'])) &&
            substr($method, 0, 2) === 'do') {
            return false;
        }

        return call_user_func_array(array($this->client, $method), $arguments);
    }
}
