<?php

/**
 * Handles requests from administrators to list currently running plugins.
 * By default, plugins returns a list of all the plugins running.
 * Optionally, you can pass arguments such as plugins -m to list all the
 * muted. Multiple arguments can bepassed such as plugins -m -d to get a
 * list of all the muted and disabled plugins.
 */
class Phergie_Plugin_ModuleList extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * An array containing a list of arguments as the array's keys that are
     * available and the value is what the argurment is mapped to.
     *
     * @var array
     */
    protected $argList = array(
        'v' => 'Verbose', // Extended/Verbose All Plugins List
        'e' => 'Enabled', // Enabled Plugins List
        'd' => 'Disabled', // Disabled Plugins List
        'm' => 'Muted', // Muted Plugins list
        'g' => 'Global_Muted', // Global Muted Plugins List
        'l' => 'Local_Muted', // Local/Current Channel Muted plugins list
        'u' => 'Unmuted', // Unmuted Plugins List
        'p' => 'Passive', // Passive Plugins List
        'a' => 'Admin', // Admin Plugins List
        'b' => 'Base', // Base/Core Plugins List
        'i' => 'Inactive', // Inactive Plugins List
    );

    /**
     * An array containing a list of states to display on the verbose plugin
     * list. The keys are the values from $argList and the values are the
     * letters to append to the verbose list.
     *
     * @var array
     */
    protected $verboseList = array(
        'Admin' => 'A',
        'Disabled' => 'D',
        'Local_Muted' => 'L',
        'Global_Muted' => 'G',
        'Passive' => 'P',
        'Inactive' => 'I'
    );

    public function onDoPlugins($rawArgs = '')
    {
        $source = $this->event->getSource();
        $rawArgs = $this->parseArguments($rawArgs);
        $target = trim(strtolower(!empty($rawArgs['strings'][0]) && $this->fromAdmin(true) ? $rawArgs['strings'][0] : $source));
        $pluginData = array();

        // Loop through the parsed args and formats them
        $args = array();
        foreach($rawArgs['flags'] as $arg => $value) {
            if ($arg == 'mg' || $arg == 'mutedglobal') $arg = 'g';
            else if ($arg == 'ml' || $arg == 'mutedlocal') $arg = 'l';
            $args[substr(strtolower($arg), 0, 1)] = $value;
        }
        unset($rawArgs);

        // Help command, return a list of all supprted commands
        if (isset($args['h'])) {
            $message = 'Help Info: ';
            foreach($this->argList as $arg => $info) {
                // Get the commands. Format: -c, -command = Info
                $message .= '-'.$arg.', -'.str_replace('_','',strtolower($info)).' = '.
                            str_replace('_',' ',ucfirst($info)).' '.($arg == 'v'?'mode':'plugins').' | ';
            }
            $this->doPrivmsg($source, trim($message, "| \t\n\r\0\v\0xa0"));
            return;
        }

        // Retrieve and loop through th plugins to gather a list of information about them.
        $plugins = $this->getPluginList(true);
        foreach($plugins as $plugin) {
            $plugin = ucfirst(trim($plugin));
            if (!empty($plugin)) {
                $data = $this->getPlugin($plugin);
                if ($data) {
                    // Checks to see if the plugin is an enabled plugin or not
                    if ($data->enabled) {
                        $pluginData['Enabled'][] = $plugin;
                    } else {
                        $pluginData['Disabled'][] = $plugin;
                    }

                    // Checks to see if the plugin is a muted plugin or not
                    if (!empty($data->muted[$target]) || !empty($data->muted['global'])) {
                        $pluginData['Muted'][] = $plugin . (!empty($data->muted['global']) ? '*' : '');
                        if (!empty($data->muted['global'])) {
                            $pluginData['Global_Muted'][] = $plugin;
                        }
                        if (!empty($data->muted[$target])) {
                            $pluginData['Local_Muted'][] = $plugin;
                        }
                    } else {
                        $pluginData['Unmuted'][] = $plugin;
                    }

                    // Checks to see if the plugin is an admin plugin or not
                    if ($data->needsAdmin) {
                        $pluginData['Admin'][] = $plugin;
                    } else {
                        $pluginData['Base'][] = $plugin;
                    }

                    // Checks to see if the plugin is a passive plugin or not
                    if ($data->passive) {
                        $pluginData['Passive'][] = $plugin;
                    }

                    // All plugins list
                    $pluginData['Plugins'][] = $plugin;
                }
                // Checks to see if the plugin is an inactive plugin or not
                else {
                    $pluginData['Inactive'][] = $plugin;
                }

                // Extended/Verbose plugins list that shows every plugin and their state
                $state = null;
                if (isset($args['v']) && is_array($pluginData)) {
                    foreach($this->verboseList as $key => $value) {
                        if (isset($pluginData[$key]) and is_array($pluginData[$key]) &&
                            in_array($plugin, $pluginData[$key])) {
                            $state .= $value;
                        }
                    }
                    // Format the append data if its set
                    $pluginData['Verbose'][] = $plugin . (!empty($state) ? '(' . trim($state) . ')' : '');
                }
            }
        }
        unset($plugins);

        // Go through the list of any passed arugments and generate the plugin list for them
        $message = null;
        if (!isset($args['v']) && is_array($args) && count($args) > 0) {
            foreach($args as $arg => $value) {
                $plugin = $this->argList[$arg];
                if (isset($plugin)) {
                    if (is_array($pluginData[$plugin]) && count($pluginData[$plugin]) > 0) {
                        sort($pluginData[$plugin]);
                    }
                    $plugins = trim(count($pluginData[$plugin]) > 0 ? implode(', ', $pluginData[$plugin]) : '');
                    $message .= ucfirst(str_replace('_', ' ', $plugin)) . ': ' . ($plugins ? $plugins : 'No Plugins') . ' :: ';
                }
            }
            unset($plugins);
        }

        // If the message is empty, aka no args were passed, return the full plugin list
        if (empty($message)) {
            $display = (isset($args['v']) ? 'Verbose' : 'Plugins');
            if (is_array($pluginData[$display])) {
                sort($pluginData[$display]);
                $message = $display . ': ' . implode(', ', $pluginData[$display]);
            }
        }

        $this->doPrivmsg($source, trim($message, ": \t\n\r\0\v\0xa0"));
        unset($message);
    }
}
