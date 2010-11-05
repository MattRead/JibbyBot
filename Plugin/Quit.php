<?php

/**
 * Handles requests from administrators for the bot to disconnect from the
 * server.
 */
class Phergie_Plugin_Quit extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Random reconnect messages used if a quit message isn't given
     *
     * @var array
     */
    protected $messages = array(
        'I\'ll be back.',
        'I shall return.',
        'Look to my coming at first light on the fifth day. At dawn look to the East.',
        'kthx, brb.',
        'Beam me up, Scotty!',
    );

    /**
     * Path to the PHP executable file
     *
     * @var string
     */
    protected $phpPath;

    /**
     * Attempts to auto-detect the path to the PHP executable if the path isn't
     * set in the setting file
     *
     * @return void
     */
    public function onInit()
    {
        $phpPath = trim($this->getPluginIni('php_path'));
        if (empty($phpPath) || !is_file($phpPath) ||
            strtolower($phpPath) == 'autodetect' || strtolower($phpPath) == 'auto-detect') {
            /**
             * Array of file names to check for when auto-detecting the path to PHP
             */
            $files = array(
                'php',
                'php.exe',
                'php-win.exe'
            );

            /**
             * Array of file paths to check for the PHP executable
             */
            $paths = array();
            $paths = array_merge($paths, explode(PATH_SEPARATOR, ini_get('include_path')));
            $paths = array_merge($paths, explode(PATH_SEPARATOR, ini_get('extension_dir')));

            if (!empty($phpPath) &&
                strtolower($phpPath) != 'autodetect' && strtolower($phpPath) != 'auto-detect') {
                $files[] = $phpPath;
                $paths[] = $phpPath;
            }

            /**
             * Attempt to auto-detect the path to the PHP executable from the list of file names and paths
             */
            $phpPath = null;
            foreach($paths as $path) {
                if (empty($path)) {
                    continue;
                }
                $path = trim($path) . (!in_array(substr($path, -1), array(DIRECTORY_SEPARATOR, '/', '\\')) ? DIRECTORY_SEPARATOR : '');
                foreach($files as $file) {
                    if (empty($file)) {
                        continue;
                    }
                    $file = trim($file);
                    if (@is_file($path . $file)) {
                        $phpPath = $path . $file;
                        break 2;
                    } else if (@is_file(dirname($path) . DIRECTORY_SEPARATOR . $file)) {
                        $phpPath = dirname($path) . DIRECTORY_SEPARATOR . $file;
                        break 2;
                    }
                }
            }

            /**
             * Try 'which php' if using a non-windows OS to get the path to the
             * PHP executable
             */
            if (strtolower(substr(PHP_OS, 0, 3)) !== 'win' && function_exists('exec')) {
            	$func = new ReflectionFunction('exec');
            	// skip this if exec is disabled by safe_mode or whatever
            	if (!$func->isDisabled()) {
	                $exec = trim(exec('which php'));
	                if (!empty($exec) && is_file($exec)) {
	                    $phpPath = $exec;
	                }
            	}
            	unset($func);
            }
            unset($files, $file, $paths, $path, $exec);
        }
        $this->phpPath = $phpPath;
        if (!defined('PHERGIE_PHP_PATH')) {
            define('PHERGIE_PHP_PATH', $phpPath);
        }
    }

    /**
     * Processes requests for the bot to disconnect from the server.
     *
     * Note: The Freenode IRC daemon will display "Client Quit" in place of
     * any provided reason for quitting if the bot has not been connected for
     * at least 5 minutes prior to disconnecting. This is meant to prevent
     * spamming via quit messages. It is possible that IRC daemons for other
     * networks have similar behavior.
     *
     * @return void
     */
    public function handleQuit($message = '', $reconnect = false)
    {
        $user = $this->event->getNick();
        $message = trim(!empty($message) || $reconnect ? $message : $this->getPluginIni('reason'));
        if (substr($message, 0, 1) === '(' && substr($message, -1) === ')') {
            $message = substr($message, 1, -1);
        }
        if (!$reconnect && empty($message)) {
            $message = 'by request of %nick%';
        }
        if (empty($message)) {
            $message = $this->messages[array_rand($this->messages, 1) ];
        }
        $message = str_replace('%nick%', $user, trim($message));
        $this->doQuit($message, $reconnect);
    }

    /**
     * Creates a new instance of Phergie and closes the original one
     *
     * @return void
     */
    public function handleReboot($message = '')
    {
        $user = $this->event->getNick();
        if (empty($this->phpPath) or !is_file($this->phpPath)) {
            trigger_error('Could not restart Phergie, make sure the setting "quit.php_path" is correct', E_USER_WARNING);
            $this->doNotice($user, 'Error: Couldn\'t restart Phergie.');
            return;
        }

        $exec = shell_exec(PHERGIE_PHP_PATH . ' -l ' . PHERGIE_DIR . 'Bot.php ' . PHERGIE_INI_PATH);
        if (stripos($exec, 'No syntax errors') === false) {
            trigger_error('Encountered an error while trying to restart.', E_USER_WARNING);
            $this->debug('Restart Error: ' . trim($exec));
            $this->doNotice($user, 'Error: Couldn\'t restart Phergie.');
            return;
        }

        if (strtolower(substr(PHP_OS, 0, 3)) === 'win') {
            $handle = popen('cmd /c start "' . $this->getIni('nick') . '" "' . PHERGIE_PHP_PATH . '" "' . PHERGIE_DIR . 'Bot.php" "' . PHERGIE_INI_PATH . '"', 'r');
        } else {
            exec(PHERGIE_PHP_PATH . ' -f ' . PHERGIE_DIR . 'Bot.php ' . PHERGIE_INI_PATH . '> /dev/null  &');
            $handle = true;
        }

        if ($handle) {
            if (is_resource($handle)) {
                pclose($handle);
            }
            $this->doNotice($user, 'Restarting ' . $this->getIni('nick') . '.');
            $this->handleQuit($message);
        } else {
            trigger_error('Could not create another instance of Phergie.', E_USER_WARNING);
            $this->doNotice($user, 'Error: Couldn\'t restart Phergie.');
        }
    }

    /**
     * Processes requests for the bot to disconnect from the server.
     *
     * @return void
     */
    public function onDoQuit($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin()) {
            $this->handleQuit($message);
        } else {
            $this->doNotice($user, 'You do not have permission to use quit.');
        }
    }

    /**
     * Processes requests for the bot to disconnect from the server.
     *
     * @return void
     */
    public function onDoDie($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin()) {
            $this->handleQuit($message);
        } else {
            $this->doNotice($user, 'You do not have permission to use die.');
        }
    }

    /**
     * Processes requests for the bot to disconnect from the server.
     *
     * @return void
     */
    public function onDoExit($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin()) {
            $this->handleQuit($message);
        } else {
            $this->doNotice($user, 'You do not have permission to use exit.');
        }
    }

    /**
     * Reconnects to the server and rehashes the ini data
     *
     * @return void
     */
    public function onDoReconnect($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            $this->handleQuit($message, true);
        } else {
            $this->doNotice($user, 'You do not have permission to use reconnect.');
        }
    }

    /**
     * Creates a new instance of Phergie and closes the original one
     *
     * @return void
     */
    public function onDoRestart($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            $this->handleReboot($message);
        } else {
            $this->doNotice($user, 'You do not have permission to use restart.');
        }
    }

    /**
     * Creates a new instance of Phergie and closes the original one
     *
     * @return void
     */
    public function onDoReboot($message = '')
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            $this->handleReboot($message);
        } else {
            $this->doNotice($user, 'You do not have permission to use reboot.');
        }
    }
}
