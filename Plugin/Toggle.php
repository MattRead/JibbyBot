<?php

/**
 * Handles requests from administrators for the bot to disable or silence plugins
 */
class Phergie_Plugin_Toggle extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * PDO instance for the database
     *
     * @var PDO
     */
    protected $db;

    /**
     * Prepared PDO statements
     *
     * @var PDOStatement
     */
    protected $fetchState;
    protected $inset;
    protected $update;
    protected $delete;

    /**
     * Creates the database if needed, connects to it, and sets up prepared
     * statements for common operations.
     *
     * @return void
     */
    public function onInit()
    {
        // Check to see if PDO and Sqlite is available
        if (!extension_loaded('PDO') || !extension_loaded('pdo_sqlite')) {
            return;
        }

        try {
            // Initialize the database connection
            $this->db = new PDO('sqlite:' . $this->dir . 'toggle.db');
            if (!$this->db) {
                return;
            }

            // Check to see if the table exists
            $table = $this->db->query('SELECT COUNT(*) FROM sqlite_master WHERE name = ' . $this->db->quote('toggle'))->fetchColumn();

            // Create database tables if necessary
            if (!$table) {
                $this->debug('Creating the database schema');
                $this->db->exec('
                    CREATE TABLE toggle (plugin VARCHAR(50), chan VARCHAR(50), state INTEGER(1));
                ');
            }
            unset($table);

            // Initialize prepared statements for common operations
            $this->fetchState = $this->db->prepare('
                SELECT state, ROWID id FROM toggle WHERE LOWER(plugin) = LOWER(:plugin) AND LOWER(chan) = LOWER(:chan) LIMIT 1
            ');
            $this->insert = $this->db->prepare('
                INSERT INTO toggle (plugin, chan, state) VALUES (:plugin, :chan, :state)
            ');
            $this->update = $this->db->prepare('
                UPDATE toggle SET state = :state WHERE LOWER(plugin) = LOWER(:plugin) AND LOWER(chan) = LOWER(:chan)
            ');
            $this->delete = $this->db->prepare('
                DELETE FROM toggle WHERE LOWER(plugin) = LOWER(:plugin) AND LOWER(chan) = LOWER(:chan)
            ');
        } catch (PDOException $e) { }
    }

    /**
     * All the plugins are loaded and we're connected to the server so load up
     * the toggle database cache and import the settings
     *
     * @return void
     */
    public function onConnect()
    {
        try {
            if (!$this->db) {
                return;
            }

            $states = $this->db->query('SELECT plugin, chan, state FROM toggle')->fetchAll();

            if (count($states) > 0) {
                foreach($states as $state) {
                    $plugin = trim(strtolower($state['plugin']));
                    $chan = trim(strtolower($state['chan']));
                    $status = intval($state['state']);

                    if ($plugin = $this->getPlugin($plugin)) {
                        if (empty($chan)) {
                            $plugin->enabled = $status;
                        } else {
                            $plugin->muted[$chan] = $status;
                        }
                    }
                }
            }
            unset($states, $chan, $status);
        } catch (PDOException $e) { }
    }

    /**
     * Disables a given plugin
     */
    public function onDoDisable($plugin)
    {
        // Check to see if the admin is a hostmask admin only and not an op
        if ($this->fromAdmin(true)) {
            $user = $this->event->getNick();
            if (($instance = $this->getPlugin($plugin)) && $instance != $this) {
                if ($instance->enabled) {
                    $instance->enabled = false;
                    $this->updateToggleState($plugin, null, false);
                    $this->doNotice($user, 'Disabled ' . $plugin . '.');
                } else {
                    $this->doNotice($user, $plugin . ' is already disabled.');
                }
            } else {
                $this->doNotice($user, 'Plugin ' . $plugin . ' is not loaded.');
            }
        } else {
            $this->doNotice($user, 'You do not have permission to disable plugins.');
        }
    }

    /**
     * Enables a given plugin or loads it if its not already loaded
     */
    public function onDoEnable($plugin)
    {
        // Check to see if the admin is a hostmask admin only and not an op
        if ($this->fromAdmin(true)) {
            $user = $this->event->getNick();
            // Plugin is loaded already
            if ($instance = $this->getPlugin($plugin)) {
                // Already enabled
                if ($instance->enabled) {
                    $this->doNotice($user, 'Plugin ' . $plugin . ' is already enabled.');
                    // Not yet enabled, enable

                } else {
                    $instance->enabled = true;
                    $this->updateToggleState($plugin, null, true);
                    $this->doNotice($user, 'Enabled ' . $plugin . '.');
                }
            // Plugin was not loaded, try to see if we can load it
            } else {
                // Build loaded plugin list to pass to the checkDependencies method
                $plugins = $this->getPluginList(true);
                // Plugin file was found, check if it can be loaded
                if (in_array($plugin, $plugins)) {
                    $class = 'Phergie_Plugin_' . $plugin;
                    $result = call_user_func(array($class, 'checkDependencies'), $this->client, $plugins);
                    if ($result === true) {
                        $instance = new $class($this->client);
                        $this->client->addPlugin($instance);
                        $this->debug('Loaded ' . $plugin);
                        $this->doNotice($user, 'Plugin ' . $plugin . ' loaded.');
                    } else {
                        if ($plugin === false) {
                            $this->doNotice($user, 'Plugin ' . $plugin . ' can not be loaded, missing dependencies.');
                        } else {
                            $this->doNotice($user, 'Plugin ' . $plugin . ' can not be loaded : '. implode(', ', (array) $plugin));
                        }
                    }
                // Plugin file not found
                } else {
                    $this->doNotice($user, 'Plugin ' . $plugin . ' not found, could not enable.');
                }
            }
        } else {
            $this->doNotice($user, 'You do not have permission to enable plugins.');
        }
    }

    /**
     * Mutes s given plugin for a given channel or the current channel if empty
     */
    public function onDoMute($plugin, $target = '')
    {
        $user = $this->event->getNick();
        if (substr(strtolower($target), 0, 3) == 'for') {
            $target = trim(substr($target, 3));
        }
        $target = strtolower(trim((!empty($target) && $this->fromAdmin(true)) ? $target : $this->event->getSource()));
        if (!empty($target)) {
            if ($instance = $this->getPlugin($plugin)) {
                $for = ($target == strtolower($this->event->getSource()) ? 'for this channel' : ($target == 'global' ? 'globally' : 'for ' . $target));
                if (empty($instance->muted[$target])) {
                    $instance->muted[$target] = true;
                    $this->updateToggleState($plugin, $target, true);
                    $this->doNotice($user, 'Muted ' . $plugin . ' ' . $for . '.');
                } else {
                    $this->doNotice($user, $plugin . ' is already muted ' . $for . '.');
                }
            } else {
                $this->doNotice($user, 'Plugin ' . $plugin . ' not found, could not mute.');
            }
        }
    }

    /**
     * Unmutes s given plugin for a given channel or the current channel if empty
     */
    public function onDoUnmute($plugin, $target = '')
    {
        $user = $this->event->getNick();
        if (substr(strtolower($target), 0, 3) == 'for') {
            $target = trim(substr($target, 3));
        }
        $target = strtolower(trim((!empty($target) && $this->fromAdmin(true)) ? $target : $this->event->getSource()));
        if (!empty($target)) {
            $for = ($target == strtolower($this->event->getSource()) ? 'for this channel' : ($target == 'global' ? 'globally' : 'for ' . $target));
            if ($instance = $this->getPlugin($plugin)) {
                if (!empty($instance->muted[$target])) {
                    $instance->muted[$target] = false;
                    $this->updateToggleState($plugin, $target, false);
                    $this->doNotice($user, 'Unmuted ' . $plugin . ' ' . $for . '.');
                } else {
                    $this->doNotice($user, $plugin . ' is already unmuted ' . $for . '.');
                }
            } else {
                $this->doNotice($user, 'Plugin ' . $plugin . ' not found, could not unmute.');
            }
        }
    }

    /**
     * Handle whether to delete, insert or update a row based on the toggle status
     */
    public function updateToggleState($plugin, $chan, $state)
    {
        if (!$this->db) {
            return;
        }

        $plugin = trim(strtolower($plugin));
        $chan = trim(strtolower($chan));
        $state = intval($state);

        // Delete the row if enabling or unmuting a plugin
        if ((empty($chan) && $state) || (!empty($chan) && !$state)) {
            $this->delete->execute(array(':plugin' => $plugin, ':chan' => $chan));
            $this->debug('Deleted toggle status for plugin "' . $plugin . '"' . (!empty($chan) ? ' and scope "' . $chan . '"' : ''));
        } else {
            $this->fetchState->execute(array(':plugin' => $plugin, ':chan' => $chan));
            $res = $this->fetchState->fetch(PDO::FETCH_ASSOC);
            // Insert a row if the given plugin and channel doesn't exist
            if ($res) {
                $this->update->execute(array(':plugin' => $plugin, ':chan' => $chan, ':state' => $state));
                $this->debug('Updated toggle status for plugin "' . $plugin . '"' . (!empty($chan) ? ' and scope "' . $chan . '"' : ''));
            // Update a row if the given plugin and channel exists
            } else {
                $this->insert->execute(array(':plugin' => $plugin, ':chan' => $chan, ':state' => $state));
                $this->debug('Inserted toggle status for plugin "' . $plugin . '"' . (!empty($chan) ? ' and scope "' . $chan . '"' : ''));
            }
        }
    }
}
