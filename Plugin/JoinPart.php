<?php

/**
 * Handles administrator-issued requests for the bot to join or part from a
 * specified channel.
 */
class Phergie_Plugin_JoinPart extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Joins the specified channel.
     *
     * @param string $channel Name of the channel to join
     */
    public function onDoJoin($channel)
    {
        if (!empty($channel)) {
            $channel = preg_replace('/,\s+/', ',', trim($channel));
            $this->doJoin($channel);
        }
    }

    /**
     * Parts either the specified channel or the channel from which the
     * request originates if no channel is specified.
     *
     * @param string $channel Name of the channel to part (optional)
     * @param Phergie_Event_Request $event Intercepted event
     */
    public function onDoPart($channel = null)
    {
        $source = $this->event->getSource();
        $channel = trim($channel);

        // If the channel is empty, part the current channel where the command was used
        if (empty($channel) && $source[0] == '#') {
            $this->doPart($source);
        // Check whether a channel or multiple channels were given as well as a reason
        } else if (preg_match('/^([#&][^\s,]+ (?:\s*,+\s* [#&][^\s,]+)* | all | \#)?(?:[\s,]+)?(.*)?$/xis', $channel, $match)) {
            $channels = preg_replace(array('{\s}', '{,+}'), array('', ','), $match[1]);
            if (!empty($channels) || $source[0] == '#') {
                if (empty($channels) || $channels == '#') {
                    if ($source[0] != '#') return;
                    $channels = $source;
                } else if ($channels == 'all') {
                    //Check to see if the Users plugin is enabled  to retrieve a list of channels
                    if (!$this->pluginLoaded('ServerInfo')) {
                        // A fallback, most IRC servers cause the user to part all channels if they try to join 0
                        $this->doJoin('0');
                    } else {
                        $channels = implode(',', Phergie_Plugin_ServerInfo::getChannels());
                    }
                }

                $reason = trim($match[2]);
                if (substr($reason, 0, 1) === '(' && substr($reason, -1) === ')') {
                    $reason = trim(substr($reason, 1, -1));
                }

                $this->doPart($channels, $reason);
            }
        }
        unset($channel, $channels, $reason);
    }
}
