<?php

class Phergie_Plugin_ServerInfo extends Phergie_Plugin_Abstract_Base
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    const OP = 8;
    const HALFOP = 4;
    const VOICE = 2;
    const REGULAR = 1;

    /**
     * An array containing all the user information for a given channel
     *
     * @var array
     */
    protected static $list = array();

    /**
     * Static instance of the Users class
     *
     * @var Phergie_Plugin_Users
     */
    protected static $instance;

    /**
     * Initializes instance variables.
     *
     * @return void
     */
    public function init()
    {
        self::$instance = $this;
    }

    /**
     * Tracks mode changes.
     *
     * @return void
     */
    public function onMode()
    {
        $args = $this->event->getArguments();
        if (count($args) != 3) {
            return;
        }
        list($chan, $modes, $nicks) = array_pad($args, 3, null);
        if (preg_match('/(?:\+|-)[hov+-]+/i', $modes)) {
            $chan = trim(strtolower($chan));
            $modes = str_split(trim(strtolower($modes)), 1);
            $nicks = explode(' ', trim(strtolower($nicks)));
            while ($char = array_shift($modes)) {
                switch ($char) {
                    case '+':
                        $mode = '+';
                    break;

                    case '-':
                        $mode = '-';
                    break;

                    case 'o':
                        $nick = array_shift($nicks);
                        if ($mode == '+') {
                            self::$list[$chan][$nick] |= self::OP;
                        } elseif ($mode == '-') {
                            self::$list[$chan][$nick] ^= self::OP;
                        }
                    break;

                    case 'h':
                        $nick = array_shift($nicks);
                        if ($mode == '+') {
                            self::$list[$chan][$nick] |= self::HALFOP;
                        } elseif ($mode == '-') {
                            self::$list[$chan][$nick] ^= self::HALFOP;
                        }
                    break;

                    case 'v':
                        $nick = array_shift($nicks);
                        if ($mode == '+') {
                            self::$list[$chan][$nick] |= self::VOICE;
                        } elseif ($mode == '-') {
                            self::$list[$chan][$nick] ^= self::VOICE;
                        }
                    break;
                }
            }
        }
    }

    /**
     * Debugging function
     *
     * @return void
     */
    public function onPrivmsg()
    {
        if ($this->getIni('debug') && $this->fromAdmin(true)) {
            list($target, $msg) = array_pad($this->event->getArguments(), 2, null);
            if (preg_match('#^ishere (\S+)$#', $msg, $m)) {
                $this->doPrivmsg($target, self::isIn($m[1], $target) ? 'true' : 'false');
            } elseif (preg_match('#^isop (\S+)$#', $msg, $m)) {
                $this->doPrivmsg($target, self::isOp($m[1], $target) ? 'true' : 'false');
            } elseif (preg_match('#^isvoice (\S+)$#', $msg, $m)) {
                $this->doPrivmsg($target, self::isVoice($m[1], $target) ? 'true' : 'false');
            }
        }
    }

    /**
     * Tracks users joining channels.
     *
     * @return void
     */
    public function onJoin()
    {
        $arg = trim(strtolower($this->event->getArgument(0)));
        $nick = trim(strtolower($this->event->getNick()));

        self::$list[$arg][$nick] = self::REGULAR;
    }

    /**
     * Tracks users parting channels.
     *
     * @return void
     */
    public function onPart()
    {
        $arg = trim(strtolower($this->event->getArgument(0)));
        $nick = trim(strtolower($this->event->getNick()));

        if (isset(self::$list[$arg][$nick])) {
            unset(self::$list[$arg][$nick]);
        }
    }

    /**
     * Tracks users quitting from the server.
     *
     * @return void
     */
    public function onQuit()
    {
        $nick = trim(strtolower($this->event->getNick()));

        foreach(self::$list as $channame => $chan) {
            if (isset($chan[$nick])) {
                unset(self::$list[$channame][$nick]);
            }
        }
    }

    /**
     * Tracks users changing nicks.
     *
     * @return void
     */
    public function onNick()
    {
        $nick = trim(strtolower($this->event->getNick()));
        $newNick = trim(strtolower($this->event->getArgument(0)));

        foreach(self::$list as $channame => $chan) {
            if (isset($chan[$nick])) {
                self::$list[$channame][$newNick] = $chan[$nick];
                unset(self::$list[$channame][$nick]);
            }
        }
    }

    /**
     * Populates the internal using listing for a channel when the bot joins
     * it.
     *
     * @return void
     */
    public function onResponse()
    {
        if ($this->event->getCode() == Phergie_Event_Response::RPL_NAMREPLY) {
            $desc = preg_split('/[@*=]\s*/', $this->event->getDescription(), 2);
            list($chan, $users) = array_pad(explode(' :', trim($desc[1])), 2, null);
            $users = explode(' ', trim($users));
            foreach($users as $user) {
                if (empty($user)) continue;
                $flag = self::REGULAR;
                if (substr($user, 0, 1) === '@') {
                    $user = substr($user, 1);
                    $flag |= self::OP;
                }
                if (substr($user, 0, 1) === '%') {
                    $user = substr($user, 1);
                    $flag |= self::HALFOP;
                }
                if (substr($user, 0, 1) === '+') {
                    $user = substr($user, 1);
                    $flag |= self::VOICE;
                }
                self::$list[trim(strtolower($chan))][trim(strtolower($user))] = $flag;
            }
        }
    }

    /**
     * Checks whether or not a given user has op (@) status.
     *
     * @param string $nick User nick to check
     * @param string $chan Channel to check in
     * @return bool
     */
    public static function isOp($nick, $chan)
    {
        $nick = trim(strtolower($nick));
        $chan = trim(strtolower($chan));

        return isset(self::$list[$chan][$nick]) && (self::$list[$chan][$nick] & self::OP) != 0;
    }

    /**
     * Checks whether or not a given user has voice (+) status.
     *
     * @param string $nick User nick to check
     * @param string $chan Channel to check in
     * @return bool
     */
    public static function isVoice($nick, $chan)
    {
        $nick = trim(strtolower($nick));
        $chan = trim(strtolower($chan));

        return isset(self::$list[$chan][$nick]) && (self::$list[$chan][$nick] & self::VOICE) != 0;
    }

    /**
     * Checks whether or not a given user has halfop (%) status.
     *
     * @param string $nick User nick to check
     * @param string $chan Channel to check in
     * @return bool
     */
    public static function isHalfop($nick, $chan)
    {
        $nick = trim(strtolower($nick));
        $chan = trim(strtolower($chan));

        return isset(self::$list[$chan][$nick]) && (self::$list[$chan][$nick] & self::HALFOP) != 0;
    }

    /**
     * Checks whether or not a particular user is in a particular channel.
     *
     * @param string $nick User nick to check
     * @param string $chan Channel to check in
     * @return bool
     */
    public static function isIn($nick, $chan)
    {
        $nick = trim(strtolower($nick));
        $chan = trim(strtolower($chan));

        return isset(self::$list[$chan][$nick]);
    }

    /**
     * Returns the entire user list for a channel or false if the bot is not
     * present in the channel.
     *
     * @param string $chan Channel name
     * @return array|bool
     */
    public static function getUsers($chan)
    {
        $chan = trim(strtolower($chan));
        if (isset(self::$list[$chan])) {
            return array_keys(self::$list[$chan]);
        }
        return false;
    }

    /**
     * Returns the nick of a random user present in a given channel or false
     * if the bot is not present in the channel.
     *
     * @param string $chan Channel name
     * @return string|bool
     */
    public static function getRandomUser($chan)
    {
        $chan = trim(strtolower($chan));
        if (isset(self::$list[$chan])) {
            while (array_search(($nick = array_rand(self::$list[$chan], 1)), array('chanserv', 'q', 'l', 's')) !== false) {}
            return $nick;
        }
        return false;
    }

    /**
     * Returns a list of channels in which a given user is present.
     *
     * @param string $user Nick of the user (optional, defaults to the bot's
     *                     nick)
     * @return array List of channels
     */
    public static function getChannels($nick = null)
    {
        if (empty($nick)) {
            $nick = trim(strtolower(self::$instance->getIni('nick')));
        }
        $out = array();
        foreach(self::$list as $channame => $chan) {
            if (isset($chan[$nick])) {
                $out[] = $channame;
            }
        }
        return $out;
    }
}
