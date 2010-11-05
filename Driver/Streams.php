<?php
/**
 * Provides a socket-based client driver.
 */
class Phergie_Driver_Streams extends Phergie_Driver_Abstract
{
    /**
     * Names of commands that can be issued by callbacks ordered by
     * priority of execution
     *
     * @var array
     */
    protected $priority = array(
        'raw',
        'pass',
        'user',
        'pong',
        'notice',
        'join',
        'list',
        'names',
        'version',
        'stats',
        'links',
        'time',
        'trace',
        'admin',
        'info',
        'who',
        'whois',
        'whowas',
        'mode',
        'privmsg',
        'nick',
        'topic',
        'invite',
        'kill',
        'part'
    );

    /**
     * Names of destructive commands that get queued up last
     *
     * @var array
     */
    protected $destuctive = array(
        'nick',
        'kill',
        'part',
        'quit'
    );

    /**
     * Socket handler
     *
     * @var resource
     */
    protected $socket;

    /**
     * Flag to indicate whether or not the callbacks are currently being
     * queued for execution rather than executed outright
     *
     * @var bool
     */
    protected $queueing;

    /**
     * Associative array mapping command names to queued sets of arguments
     * from commands queued by callbacks
     *
     * @var array
     */
    protected $queue;

    /**
     * The time the bot started
     *
     * @var int
     */
    protected $startTime;

    /**
     * Constructor to initialize instance properties.
     */
    public function __construct()
    {
        $this->plugins = array();
        $this->queueing = false;
        $this->queue = array();
    }

    /**
     * Returns the start time.
     *
     * @return int
     */
    public function getStartTime()
    {
        return $this->startTime;
    }

    /**
     * Executes a continuous loop in which the client listens for events from
     * the server and processes them until the connection is terminated.
     *
     * @return void
     */
    public function run()
    {
        $this->startTime = time();
        $returnCode = $this->getIni('keepalive') ? self::RETURN_KEEPALIVE : self::RETURN_END;
        $server = $this->getIni('server');
        $port = $this->getIni('port');
        if (!$port) {
            $port = 6667;
        }

        $this->socket = @stream_socket_client('tcp://' . $server . ':' . $port, $errno, $errstr, 10);
        if (!$this->socket) {
            $this->debug(rtrim('Unable to connect to server: socket error ' . $errno . ' ' . $errstr));
            return self::RETURN_END;
        }
        unset($port, $errno, $errstr);

        stream_set_blocking($this->socket, false);

        if ($this->getIni('timeout')) {
            $timeout = $this->getIni('timeout') * 60;
        } elseif ($this->getIni('keepalive')) {
            $timeout = 600;
        } else {
            $timeout = false;
        }
        $lastPacket = time();

        $password = $this->getIni('password');
        if ($password) {
            $this->send('PASS', array($password));
        }
        unset($password);

        $params = array(
            $this->getIni('username'),
            $server,
            $server,
            $this->getIni('realname')
        );

        $this->send('USER', $params);
        $this->doNick($this->getIni('nick'));
        unset($server, $params);

        if ($this->getIni('invisible')) {
            $this->doMode($this->getIni('nick'), '+i');
        }

        // Run the onConnect handler since we successfully connected to the server
        foreach($this->plugins as $plugin) {
            $plugin->onConnect();
        }

        while (true) {
            $this->queue = array();
            $this->queueing = true;

            // Clear the old event handler for every plugin
            foreach($this->plugins as $plugin) {
                $plugin->setEvent(NULL);
            }

            $buffer = null;
            while (empty($buffer)) {
                $buffer = fgets($this->socket, 512);

                // Check if we timed out
                if ($timeout !== false) {
                    // Reset last packet timestamp if we received something
                    if (!empty($buffer)) {
                        $lastPacket = time();
                    }
                    // Timed out, exit
                    if ($lastPacket < (time() - $timeout)) {
                        $this->debug('Timed out');
                        foreach($this->plugins as $plugin) {
                            $plugin->onShutdown();
                        }
                        break 2;
                    }
                }
                // onTick Handler
                if (empty($buffer)) {
                    foreach($this->plugins as $plugin) {
                        $plugin->onTick();
                    }
                    usleep(10000);
                }
            }
            if (!isset($buffer) || empty($buffer)) {
                continue;
            }
            $buffer = rtrim($buffer);
            $this->debug('<- ' . $buffer);

            if (substr($buffer, 0, 1) == ':') {
                list($prefix, $cmd, $args) = array_pad(explode(' ', substr($buffer, 1), 3), 3, null);
                $this->parseHostmask($prefix, $nick, $user, $host);
            } else {
                list($cmd, $args) = array_pad(explode(' ', $buffer, 2), 2, null);
            }

            $cmd = strtolower($cmd);
            switch ($cmd) {
                case 'names':
                case 'nick':
                case 'quit':
                case 'ping':
                case 'join':
                case 'error':
                    $args = array(ltrim($args, ':'));
                break;

                case 'notice':
                    $temp = preg_split('/ :?/', trim($args), 2);
                    if (substr($temp[1], 0, 1) === chr(1) && substr($temp[1], -1) === chr(1)) {
                        $ctcp = trim(substr($temp[1], 1, -1));
                        list($cmd, $args) = array_pad(explode(' ', $ctcp, 2), 2, null);
                        $cmd = strtolower($cmd);

                        // Check for the type of Notice message and handle it accordingly
                        if ($cmd == 'action') {
                            // Return sender as source and the action as its first argument
                            $args = array($nick, trim($args));
                        } elseif ($cmd == 'ping' || $cmd == 'version' || $cmd == 'time') {
                            // Return sender as source and the rest of the args as its first argument
                            $cmd = $cmd . 'Reply';
                            $args = array($nick, trim($args));
                        } else {
                            // Return sender as source and the ctcp as its first argument
                            $cmd = 'ctcpReply';
                            $args = array($nick, $ctcp);
                        }
                    } else {
                        $args = preg_split('/ :?/', $args, 2);
                    }
                break;

                case 'oper':
                case 'topic':
                case 'mode':
                    $args = preg_split('/ :?/', $args);
                break;

                case 'part':
                case 'kill':
                case 'invite':
                    $args = preg_split('/ :?/', $args, 2);
                break;

                case 'kick':
                    $args = preg_split('/ :?/', $args, 3);
                break;

                case 'privmsg':
                    $temp = preg_split('/ :?/', trim($args), 2);
                    // Check to see if its a CTCP request, if so, handle it accordingly
                    if (substr($temp[1], 0, 1) === chr(1) && substr($temp[1], -1) === chr(1)) {
                        $ctcp = trim(substr($temp[1], 1, -1));
                        list($cmd, $args) = array_pad(explode(' ', $ctcp, 2), 2, null);
                        $cmd = strtolower($cmd);

                        // Check for the type of CTCP message and handle it accordingly
                        if ($cmd == 'action') {
                            $botnick = $this->getIni('nick');
                            // If sent within a channel, use the chanel as the source, else use the sender
                            $source = (strtolower($temp[0]) == strtolower($botnick) ? $nick : $temp[0]);
                            // Return channel/sender as source and the action as its first argument
                            $args = array($source, trim($args));
                        } elseif ($cmd == 'ping' && !empty($args)) {
                            // Return nick of sender as source and the handshake as its first argument
                            $args = array($nick, trim($args));
                        } elseif (($cmd == 'version' || $cmd == 'time') && empty($args)) {
                            // Return nick of sender as source and the ctcp as its first argument
                            $args = array($nick, $ctcp);
                        } else {
                            // Return nick of sender as source and the ctcp as its first argument
                            $cmd = 'ctcp';
                            $args = array($nick, $ctcp);
                        }
                    } else {
                        $args = preg_split('/ :?/', $args, 2);
                    }
                break;
            }

            if (preg_match('/^[0-9]+$/', $cmd) > 0) {
                $event = new Phergie_Event_Response();
                $event->setCode($cmd);
                $event->setDescription($args);
                $event->setRawBuffer($buffer);
            } else {
                $event = new Phergie_Event_Request();
                $event->setType($cmd);
                $event->setArguments($args);
                if (isset($user)) {
                    $event->setHost($host);
                    $event->setUsername($user);
                    $event->setNick($nick);
                }
                $event->setRawBuffer($buffer);
            }

            $ignore = $this->hostmasksToRegex($this->getIni('ignore'));
            $method = 'on' . ucfirst($cmd);
            foreach($this->plugins as $plugin) {
                // Skip disabled plugins
                if (!$plugin->enabled) {
                    continue;
                }
                $plugin->setEvent($event);
                // onRaw and onTick Handlers
                $plugin->onRaw();
                $plugin->onTick();
                if ($event instanceof Phergie_Event_Response) {
                    $plugin->onResponse();
                // Skip events from ignored users and malformed packets
                } elseif (!empty($cmd) && method_exists($plugin, $method) &&
                          !preg_match($ignore, $event->getHostmask())) {
                    $plugin->{$method}();
                }
            }

            $this->queueing = false;
            foreach($this->priority as $command) {
                if (isset($this->queue[$command])) {
                    foreach($this->queue[$command] as $arguments) {
                        $this->send($command, $arguments);
                    }
                }
            }
            if (isset($this->queue['quit'])) {
                if (count($this->queue['quit'][0]) > 0) {
                    $reason = $this->queue['quit'][0][0];
                } else {
                    $reason = null;
                }
                foreach($this->plugins as $plugin) {
                    $plugin->onShutdown();
                }
                if (isset($this->queue['quit'][0][1])) {
                    $returnCode = ($this->queue['quit'][0][1] === true ? self::RETURN_RECONNECT : self::RETURN_END);
                }
                $this->doQuit($reason);
                break;
            }

            unset($this->queue, $event, $command, $arguments);
        }

        fclose($this->socket);

        return $returnCode;
    }

    /**
     * Sends a client command with accompanying arguments to the server.
     *
     * @param string $command Command to send
     * @param array $arguments Ordered array of arguments to include
     *                         (optional)
     */
    public function send($command, array $arguments = array(), $priority = false)
    {
        $command = strtolower($command);
        if ($this->queueing && (in_array($command, $this->destuctive) xor $priority)) {
            if (!isset($this->queue[$command])) {
                $this->queue[$command] = array();
            }
            $this->queue[$command][] = $arguments;
        } else {
            if ($command == Phergie_Event_Request::TYPE_RAW) {
                $buffer = (count($arguments) > 0 ? implode(' ', $arguments) : '');
            } else {
                $buffer = strtoupper($command);
                if (count($arguments) > 0) {
                    $end = count($arguments) - 1;
                    $arguments[$end] = ':' . $arguments[$end];
                    $buffer .= ' ' . implode(' ', $arguments);
                }
            }
            if (!empty($buffer)) {
                fwrite($this->socket, $this->filterCommand($buffer) . "\r\n");
                $this->debug('-> ' . $buffer);
            }
        }
    }
    
    /**
     * Filters the low-ASCII characters from the command
     *
     * @param string $buffer the buffer to filter
     * @return string the filtered buffer
     */
    protected function filterCommand($buffer) {
        static $search;
        if (!$search) {
            $search = range(chr(2), chr(31));
			$search[] = chr(0);
        }
        return str_replace($search, '', $buffer);
    }

    /**
     * Terminates the connection with the server.
     *
     * @param string $reason Reason for connection termination (optional)
     * @param bool $reconnect if true, the bot will reconnect to the server
     */
    public function doQuit($reason = null, $reconnect = false, $priority = false)
    {
        if ($this->queueing) {
            $this->send(Phergie_Event_Request::TYPE_QUIT, array($reason, $reconnect), $priority);
        } else {
            $this->send(Phergie_Event_Request::TYPE_QUIT, array($reason), $priority);
        }
    }

    /**
     * Joins a channel.
     *
     * @param string $channel Name of the channel to join
     * @param string $keys Channel key if needed (optional)
     */
    public function doJoin($channel, $key = null, $priority = false)
    {
        $arguments = array($channel);

        if ($key !== null) {
            $arguments[] = $key;
        }

        $this->send(Phergie_Event_Request::TYPE_JOIN, $arguments, $priority);
    }

    /**
     * Leaves a channel.
     *
     * @param string $channel Name of the channel to leave
     */
    public function doPart($channel, $reason = null, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_PART, array($channel, $reason), $priority);
    }

    /**
     * Invites a user to an invite-only channel.
     *
     * @param string $nick Nick of the user to invite
     * @param string $channel Name of the channel
     */
    public function doInvite($nick, $channel, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_INVITE, array($nick, $channel), $priority);
    }

    /**
     * Obtains a list of nicks of usrs in currently joined channels.
     *
     * @param string $channels Comma-delimited list of one or more channels
     */
    public function doNames($channels, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_NAMES, array($channels), $priority);
    }

    /**
     * Obtains a list of channel names and topics.
     *
     * @param string $channels Comma-delimited list of one or more channels
     *                         to which the response should be restricted
     *                         (optional)
     */
    public function doList($channels = null, $priority = false)
    {
        $arguments = array();

        if ($channels !== null) {
            $arguments[] = $channels;
        }

        $this->send(Phergie_Event_Request::TYPE_LIST, $arguments, $priority);
    }

    /**
     * Retrieves or changes a channel topic.
     *
     * @param string $channel Name of the channel
     * @param string $topic New topic to assign (optional)
     */
    public function doTopic($channel, $topic = null, $priority = false)
    {
        $arguments = array($channel);

        if ($topic !== null) {
            $arguments[] = $topic;
        }

        $this->send(Phergie_Event_Request::TYPE_TOPIC, $arguments, $priority);
    }

    /**
     * Retrieves or changes a channel or user mode.
     *
     * @param string $target Channel name or user nick
     * @param string $mode New mode to assign (optional)
     */
    public function doMode($target, $mode = null, $priority = false)
    {
        $arguments = array($target);

        if ($mode !== null) {
            $arguments[] = $mode;
        }

        $this->send(Phergie_Event_Request::TYPE_MODE, $arguments, $priority);
    }

    /**
     * Changes the client nick.
     *
     * @param string $nick New nick to assign
     */
    public function doNick($nick, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_NICK, array($nick), $priority);
    }

    /**
     * Retrieves information about a nick.
     *
     * @param string $nick
     */
    public function doWhois($nick, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_WHOIS, array($nick), $priority);
    }

    /**
     * Sends a message to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the message to send
     */
    public function doPrivmsg($target, $text, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_PRIVMSG, array($target, $text), $priority);
    }

    /**
     * Sends a notice to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the notice to send
     */
    public function doNotice($target, $text, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_NOTICE, array($target, $text), $priority);
    }

    /**
     * Kicks a user from a channel.
     *
     * @param string $nick Nick of the user
     * @param string $channel Channel name
     * @param string $reason Reason for the kick (optional)
     */
    public function doKick($nick, $channel, $reason = null, $priority = false)
    {
        $arguments = array($nick, $channel);

        if ($reason !== null) {
            $arguments[] = $reason;
        }

        $this->send(Phergie_Event_Request::TYPE_KICK, $arguments, $priority);
    }

    /**
     * Responds to a server test of client responsiveness.
     *
     * @param string $daemon Daemon from which the original request originates
     */
    public function doPong($daemon, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_PONG, array($daemon), $priority);
    }

    /**
     * Sends a CTCP command to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $command Command to send
     * @param array $arguments Ordered array of arguments to include
     *                         (optional)
     */
    public function doCtcp($target, $command, $arguments = '', $priority = false)
    {
        if (is_array($arguments)) {
            $arguments = implode(' ', $arguments);
        }
        $this->doPrivmsg($target, chr(1) . trim(strtoupper($command) . ' ' . $arguments) . chr(1));
    }

    /**
     * Sends a /me action to a nick or channel.
     *
     * @param string $target Channel name or user nick
     * @param string $text Text of the action to perform
     */
    public function doAction($target, $text, $priority = false)
    {
        $this->doCtcp($target, Phergie_Event_Request::TYPE_ACTION, array($text), $priority);
    }

    /**
     * Sends a ping reply to a nick.
     *
     * @param string $target User nickname
     * @param string $hash The ping hash to use in the handshake
     */
    public function doPingReply($target, $hash, $priority = false)
    {
        $this->doCtcpReply($target, 'ping', $hash, $priority);
    }

    /**
     * Sends a version reply to a nick.
     *
     * @param string $target User nickname
     * @param string $version The version to send
     */
    public function doVersionReply($target, $version = '', $priority = false)
    {
        $version = trim($version);
        if (empty($version)) {
            $version = 'Phergie '.PHERGIE_VERSION.' - An IRC bot written in PHP (http://www.phergie.org)';
        }
        $this->doCtcpReply($target, 'version', $version, $priority);
    }

    /**
     * Sends a time reply to a nick
     *
     * @param string $target User nickname
     * @param string $time The time to send
     */
    public function doTimeReply($target, $time = '', $priority = false)
    {
        $time = trim($time);
        if (empty($time) || ctype_digit($time)) {
            if (empty($time)) $time = time();
            $time = date('D M d H:i:s o', $time);
        }
        $this->doCtcpReply($target, 'time', $time, $priority);
    }

    /**
     * Sends a ctcp reply to a nick
     *
     * @param string $target User nickname
     * @param string $reply The CTCP reply to send
     */
    public function doCtcpReply($target, $command, $reply = '', $priority = false)
    {
        $command = trim($command);
        if (empty($command))
            return;
        $this->send(Phergie_Event_Request::TYPE_NOTICE, array($target, chr(1).trim(strtoupper($command).' '.$reply).chr(1)), $priority);
    }

    /**
     * Sends a raw message to the server
     *
     * @param string $message The message to send to the server
     */
    public function doRaw($message, $priority = false)
    {
        $this->send(Phergie_Event_Request::TYPE_RAW, array($message), $priority);
    }
}
