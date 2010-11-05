<?php

/**
 * Autonomous event originating from a user or the server.
 *
 * @see http://www.irchelp.org/irchelp/rfc/chapter4.html
 */
class Phergie_Event_Request
{
    /**
     * Nick message
     *
     * @const string
     */
    const TYPE_NICK = 'nick';

    /**
     * Whois message
     *
     * @const string
     */
    const TYPE_WHOIS = 'whois';

    /**
     * Quit command
     *
     * @const string
     */
    const TYPE_QUIT = 'quit';

    /**
     * Join message
     *
     * @const string
     */
    const TYPE_JOIN = 'join';

    /**
     * Kick message
     *
     * @const string
     */
    const TYPE_KICK = 'kick';

    /**
     * Part message
     *
     * @const string
     */
    const TYPE_PART = 'part';

    /**
     * Mode message
     *
     * @const string
     */
    const TYPE_MODE = 'mode';

    /**
     * Topic message
     *
     * @const string
     */
    const TYPE_TOPIC = 'topic';

    /**
     * Private message command
     *
     * @const string
     */
    const TYPE_PRIVMSG = 'privmsg';

    /**
     * Notice message
     *
     * @const string
     */
    const TYPE_NOTICE = 'notice';

    /**
     * Pong message
     *
     * @const string
     */
    const TYPE_PONG = 'pong';

    /**
     * Names message
     *
     * @const string
     */
    const TYPE_NAMES = 'names';

    /**
     * CTCP ACTION command
     *
     * @const string
     */
    const TYPE_ACTION = 'action';

    /**
     * RAW message
     *
     * @const string
     */
    const TYPE_RAW = 'raw';

    /**
     * Mapping of event types to their named parameters
     *
     * @var array
     */
    protected static $map = array(
        self::TYPE_QUIT => array(
            'message' => 0
        ),

        self::TYPE_JOIN => array(
            'channel' => 0
        ),

        self::TYPE_KICK => array(
            'channel' => 0,
            'user'    => 1,
            'comment' => 2
        ),

        self::TYPE_PART => array(
            'channel' => 0,
            'message' => 1
        ),

        self::TYPE_MODE => array(
            'target'   => 0,
            'mode'     => 1,
            'limit'    => 2,
            'user'     => 3,
            'banmask' => 4
        ),

        self::TYPE_TOPIC => array(
            'channel' => 0,
            'topic'   => 1
        ),

        self::TYPE_PRIVMSG => array(
            'receiver' => 0,
            'text'     => 1
        ),

        self::TYPE_NOTICE => array(
            'nickname' => 0,
            'text'     => 1
        ),

        self::TYPE_ACTION => array(
            'target' => 0,
            'action' => 1
        ),

        self::TYPE_RAW => array(
            'message' => 0
        )
    );

    /**
     * Host name for the originating server or user
     *
     * @var string
     */
    protected $host;

    /**
     * Username of the user from which the event originates
     *
     * @var string
     */
    protected $username;

    /**
     * Nick of the user from which the event originates
     *
     * @var string
     */
    protected $nick;

    /**
     * Request type, which can be compared to the TYPE_* constants
     *
     * @var string
     */
    protected $type;

    /**
     * Arguments included with the message
     *
     * @var array
     */
    protected $arguments;

    /**
     * The raw buffer that was sent by the server
     *
     * @var string
     */
    protected $rawBuffer;

    /**
     * Returns the hostmask for the originating server or user.
     *
     * @return string
     */
    public function getHostmask()
    {
        return $this->nick . '!' . $this->username . '@' . $this->host;
    }

    /**
     * Sets the host name for the originating server or user.
     *
     * @param string $host
     * @return void
     */
    public function setHost($host)
    {
        $this->host = $host;
    }

    /**
     * Returns the host name for the originating server or user.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Sets the username of the user from which the event originates.
     *
     * @param string $username
     * @return void
     */
    public function setUsername($username)
    {
        $this->username = $username;
    }

    /**
     * Returns the username of the user from which the event originates.
     *
     * @return string
     * @return void
     */
    public function getUsername()
    {
        return $this->username;
    }

    /**
     * Sets the nick of the user from which the event originates.
     *
     * @param string $nick
     * @return void
     */
    public function setNick($nick)
    {
        $this->nick = $nick;
    }

    /**
     * Returns the nick of the user from which the event originates.
     *
     * @return string
     * @return void
     */
    public function getNick()
    {
        return $this->nick;
    }

    /**
     * Sets the request type.
     *
     * @param string $type
     * @return void
     */
    public function setType($type)
    {
        $this->type = strtolower($type);
    }

    /**
     * Returns the request type, which can be compared to the TYPE_*
     * constants.
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Sets the arguments for the request in the order they are to be sent.
     *
     * @param array $arguments
     * @return void
     */
    public function setArguments($arguments)
    {
        $this->arguments = $arguments;
    }

    /**
     * Returns the arguments for the request in the order they are to be sent.
     *
     * @return array
     */
    public function getArguments()
    {
        return $this->arguments;
    }

    /**
     * Returns a single specified argument for the request.
     *
     * @param int $argument Position of the argument in the list, starting
     *                      from 0
     * @return string
     */
    public function getArgument($argument)
    {
        if (isset($this->arguments[$argument])) {
            return $this->arguments[$argument];
        } else {
            $argument = strtolower($argument);
            if (isset(self::$map[$this->type][$argument]) &&
                isset($this->arguments[self::$map[$this->type][$argument]])) {
                return $this->arguments[self::$map[$this->type][$argument]];
            }
        }
        return null;
    }

    /**
     * Sets the raw buffer for the given event
     *
     * @param string $buffer
     * @return void
     */
    public function setRawBuffer($buffer)
    {
        $this->rawBuffer = $buffer;
    }

    /**
     * Returns the raw buffer that was sent from the server for that event
     *
     * @return string
     */
    public function getRawBuffer()
    {
        return $this->rawBuffer;
    }

    /**
     * Returns the channel name or user nick representing the source of the
     * event.
     *
     * @return string
     */
    public function getSource()
    {
        if ($this->arguments[0][0] == '#') {
            return $this->arguments[0];
        }
        return $this->nick;
    }

    /**
     * Returns whether or not the event occurred within a channel.
     *
     * @return TRUE if the event is in a channel, FALSE otherwise
     */
    public function isInChannel()
    {
        return (substr($this->getSource(), 0, 1) == '#');
    }

    /**
     * Returns whether or not the event originated from a user.
     *
     * @return TRUE if the event is from a user, FALSE otherwise
     */
    public function isFromUser()
    {
        return !empty($this->username);
    }

    /**
     * Returns whether or not the event originated from the server.
     *
     * @return TRUE if the event is from the server, FALSE otherwise
     */
    public function isFromServer()
    {
        return empty($this->username);
    }

    /**
     * Provides access to named parameters via virtual "getter" methods.
     *
     * @param string $name Name of the method called
     * @param array $arguments Arguments passed to the method (should always
     *                         be empty)
     * @return mixed
     */
    public function __call($name, array$arguments)
    {
        if (count($arguments) == 0 && substr($name, 0, 3) == 'get') {
            return $this->getArgument(substr($name, 3));
        }
    }
}
