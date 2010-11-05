<?php

/**
 * Intercepts and responds to messages from the NickServ agent requesting that
 * the bot authenticate its identify.
 *
 * The password configuration setting should contain the password registered
 * with NickServ for the nick used by the bot.
 */
class Phergie_Plugin_Nickserv extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Primary nick for the bot
     *
     * @var string
     */
    protected $nick;

    /**
     * The name of the nickserv bot
     *
     * @var string
     */
    protected $botNick;

    /**
     * Initializes instance variables.
     *
     * @return void
     */
    public function onInit()
    {
        parent::onInit();

        $this->nick = $this->getIni('nick');

        // Get the name of the NickServ bot, defaults to NickServ
        $this->botNick = $this->getIni('bot_nick');
        if (!$this->botNick) $this->botNick = 'NickServ';
    }

    /**
     * Checks for a notice from NickServ and responds accordingly if it is an
     * authentication request or a notice that a ghost connection has been
     * killed.
     *
     * @return void
     */
    public function onNotice()
    {
        if (strtolower($this->event->getNick()) == strtolower($this->botNick)) {
            $message = $this->event->getArgument(1);
            $identifyMessage = $this->getPluginIni('identify_message');
            if (strpos($message, $identifyMessage) !== false) {
                $password = $this->getPluginIni('password');
                if (!empty($password)) {
                    $this->doPrivmsg($this->botNick, 'IDENTIFY ' . $password);
                }
            } elseif (preg_match('/^.*' . $this->nick . '.* has been killed/', $message)) {
                $this->doNick($this->nick);
            }
        }
    }

    /**
     * Checks to see if the original Nick has quit, if so, take the name back
     *
     * @return void
     */
    public function onQuit()
    {
        $nick = $this->event->getNick();
        if ($this->event->getNick() == $this->nick) {
            $this->doNick($this->nick);
        }
    }

    /**
     * Changes the in-memory configuration setting for the bot nick if it is
     * successfully changed.
     *
     * @return void
     */
    public function onNick()
    {
        if ($this->event->getSource() == $this->getIni('nick')) {
            $this->setIni('nick', $this->event->getArgument(0));
        }
    }

    /**
     * Provides a command to terminate ghost connections.
     *
     * @return void
     */
    public function onDoGhostbust()
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            if ($this->nick != $this->getIni('nick')) {
                $password = $this->getPluginIni('password');
                if (!empty($password)) {
                    $this->doPrivmsg($this->event->getSource(), $user . ': Attempting to ghost ' . $this->nick .'.');
                    $this->doPrivmsg(
                        $this->botNick,
                        'GHOST ' . $this->nick . ' ' . $password,
                        true
                    );
                }
            }
        } else {
            $this->doNotice($user, 'You do not have permission to use Ghostbust.');
        }
    }

    /**
     * Automatically send the GHOST command if the Nickname is in use
     *
     * @return void
     */
    public function onResponse()
    {
        if ($this->event->getCode() == Phergie_Event_Response::ERR_NICKNAMEINUSE) {
            $password = $this->getPluginIni('password');
            if (!empty($password)) {
                $this->doPrivmsg(
                    $this->botNick,
                    'GHOST ' . $this->nick . ' ' . $password,
                    true
                );
            }
            unset($password);
        }
    }

    /**
     * The server sent a KILL request, so quit the server
     *
     * @return void
     */
    public function onKill()
    {
        $this->doQuit($this->event->getArgument(1));
    }
}
