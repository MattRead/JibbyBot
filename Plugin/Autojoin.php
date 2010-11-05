<?php

/**
 * Automates the process of having the bot join one or more channels upon
 * connection to the server.
 *
 * The channels configuration setting should contain a comma-delimited list of
 * channels for the bot to join.
 */
class Phergie_Plugin_Autojoin extends Phergie_Plugin_Abstract_Base
{
    /**
     * Determines if the plugin is a passive plugin or not
     *
     * @var bool
     */
    public $passive = true;

    /**
     * Determines if the bot will autojoin a channel on an invite request
     *
     * @var bool
     */
    public $joinInvite = true;

    /**
     * Determines if the bot will rejoin the channel when kicked
     *
     * @var bool
     */
    public $joinKick = true;

    /**
     * Initializes settings
     *
     * @return void
     */
    public function onInit()
    {
        $joinInvite = $this->getPluginIni('invite');
        if (isset($joinInvite)) {
            $this->joinInvite = $joinInvite;
        }

        $joinKick = $this->getPluginIni('kick');
        if (isset($joinKick)) {
            $this->joinKick = $joinKick;
        }
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
        $channels = $client->getIni('autojoin.channels');

        if (empty($channels)) {
            return 'Ini setting autojoin.channels must be filled-in';
        }

        return true;
    }

    /**
     * Intercepts the end of the "message of the day" response and responds by
     * joining the channels specified in the configuration file.
     *
     * @return void
     */
    public function onResponse()
    {
        switch ($this->event->getCode()) {
            case Phergie_Event_Response::RPL_ENDOFMOTD:
            case Phergie_Event_Response::ERR_NOMOTD:
                $channels = $this->getPluginIni('channels');
                if (!empty($channels)) {
                    $spec = preg_split('/\s+/', preg_replace('/,\s+/', ',', trim($channels)), 2);
                    if (count($spec) > 1) {
                        $this->doJoin($spec[0], $spec[1]);
                    } else {
                        $this->doJoin($spec[0]);
                    }
                }
				break;
        }
    }

    /**
     * Intercepts invite requests and will have the bot join the channel if set
     * in the configuration.
     *
     * @return void
     */
    public function onInvite()
    {
        if ($this->joinInvite) {
            $this->doJoin($this->event->getArgument(1));
        }
    }

    /**
     * Intercepts kick requests and will have the bot rejoin the channel if set
     * in the configuration.
     *
     * @return void
     */
    public function onKick()
    {
        if ($this->joinKick &&
            $this->event->getArgument(1) == $this->getIni('nick')) {
            $this->doJoin($this->event->getArgument(0));
        }
    }
}
