#!/usr/bin/env php
<?php

/**
 * This file functions as a daemon process used to bootstrap and execute the
 * client by loading its configuration file, instantiating it and event
 * handlers for it, configuring it, and executing its event handling loop.
 */

/**
 * Check to see if the version of PHP meets the minimum requirement
 */
if (version_compare('5.1.2', PHP_VERSION, '>')) {
    trigger_error('Fatal error: PHP 5.1.2+ is required, current version: ' . PHP_VERSION, E_USER_ERROR);

/**
 * Backwards compatibility check to see if the PHP version is lower than 5.2
 */
} elseif (version_compare('5.2', PHP_VERSION, '>')) {
    trigger_error('PHP 5.2+ is recommended, current version: ' . PHP_VERSION, E_USER_WARNING);
}

/**
 * Code base version
 *
 * @const string
 */
define('PHERGIE_VERSION', '1.0.3');

/**
 * Path to the configuration file used by default when one is not specified or
 * register_argc_argv is disabled in php.ini
 *
 * @const string
 */
define('PHERGIE_DEFAULT_INI', 'phergie.ini');

/**
 * Path to the directory containing the Phergie directory
 *
 * @const string
 */
define('PHERGIE_BASE_DIR', realpath('..') . DIRECTORY_SEPARATOR);

/**
 * Path to the directory containing the actual Phergie files
 *
 * @const string
 */
define('PHERGIE_DIR', realpath('.') . DIRECTORY_SEPARATOR);

/**
 * Path to the directory containing the plugins
 *
 * @const string
 */
define('PHERGIE_PLUGIN_DIR', PHERGIE_DIR . 'Plugin' . DIRECTORY_SEPARATOR);

/**
 * Add the Phergie directory to the include path
 */
set_include_path(get_include_path() . PATH_SEPARATOR . PHERGIE_DIR . PATH_SEPARATOR. PHERGIE_BASE_DIR);

/**
 * Check to make sure the CLI SAPI is being used
 */
if (strtolower(PHP_SAPI) != 'cli') {
    trigger_error('Phergie requires the CLI SAPI in order to run', E_USER_ERROR);
}

/**
 * Check to see if date.timezone is empty in the PHP.ini, if so, set the
 * default timezone to prevent strict errors.
 */
if (!ini_get('date.timezone')) {
    date_default_timezone_set(date_default_timezone_get());
}

/**
 * Allow the bot to run indefinitely
 */
set_time_limit(0);

/**
 * Determine what configuration file should be used
 */
if (!ini_get('register_argc_argv')) {
    echo 'The register_argc_argv setting in php.ini is disabled, defaulting to ' . PHERGIE_DEFAULT_INI . PHP_EOL;
    $ini = PHERGIE_DEFAULT_INI;
} else if ($argc == 1) {
    echo 'No configuration file specified, defaulting to ' . PHERGIE_DEFAULT_INI . PHP_EOL;
    $ini = PHERGIE_DEFAULT_INI;
} else if (!empty($_SERVER['argv'][1]) && is_file($_SERVER['argv'][1]) && is_readable($_SERVER['argv'][1])) {
    echo 'Using specified configuration file ' . $_SERVER['argv'][1] . PHP_EOL;
    $ini = $_SERVER['argv'][1];
} else {
    echo 'Invalid or no configuration file specified, defaulting to ' . PHERGIE_DEFAULT_INI . PHP_EOL;
    $ini = PHERGIE_DEFAULT_INI;
}

/**
 * Name of the configuration file currently in use
 *
 * @const string
 */
define('PHERGIE_INI', basename($ini));

/**
 * Path to the configuration file
 *
 * @const string
 */
define('PHERGIE_INI_PATH', realpath($ini));

/**
 * Loader to automate inclusion of classes based on directory structure and
 * class naming conventions.
 *
 * @param string $class Class name to check and attempt to load
 * @return void
 */
function phergieAutoLoader($class)
{
    $file = $class;
    if (stripos($file, 'phergie_') === 0) {
        $file = substr($file, 8);
    }
    if (is_file($file = str_replace('_', DIRECTORY_SEPARATOR, $file) . '.php')) {
        require $file;
    }
}

spl_autoload_register('phergieAutoLoader');

/**
 * Start a runtime loop that will reload all settings from the configuration
 * file if the bot disconnects and reconnects, allowing for flushing of the
 * configuration without a full shutdown of the bot
 */
while (true) {
    /**
     * Obtain and validate the contents of the configuration file
     */
    $required = array('server', 'username', 'realname', 'nick');
    $config = parse_ini_file(PHERGIE_INI_PATH);

    if (empty($config)) {
        trigger_error('Configuration file inaccessible or empty: ' . $ini, E_USER_ERROR);
    }

    $missing = array();
    foreach($required as $value) {
        if (empty($config[$value])) {
            $missing[] = $value;
        }
    }
    if (!empty($missing)) {
        trigger_error('Fatal error: Required configuration settings missing: ' . implode(', ', $missing), E_USER_ERROR);
    }
    unset($required, $missing, $value);

    /**
     * Set error reporting to display errors if debug mode is enabled
     */
    if ($config['debug']) {
        error_reporting(E_ALL | E_STRICT);
        ini_set('display_errors', true);
        ini_set('ignore_repeated_errors', true);
    }

    /**
     * Configure the client
     */
    if (isset($config['driver'])) {
        $driver = ucfirst(strtolower($config['driver']));
    }
    if (!isset($driver) || !file_exists(PHERGIE_DIR . 'Driver' . DIRECTORY_SEPARATOR . $driver . '.php')) {
        trigger_error('Driver not specified or not found, defaulting to Streams', E_USER_NOTICE);
        $driver = 'Streams';
    }
    $class = 'Phergie_Driver_' . $driver;
    $client = new $class();

    foreach($config as $setting => $value) {
        $client->setIni($setting, $value);
    }
    unset($setting, $value, $driver, $class);

    /**
     * Determine which plugins should be loaded
     */
    $all = true;
    $include = array();
    if (!empty($config['plugins']) &&
        preg_match('/(all|none)(?:\s*except\s*(.+))?/ADi', $config['plugins'], $match)) {
        $all = strtolower(trim($match[1])) != 'none';
        if (!empty($match[2])) {
            $include = array_map('strtolower', preg_split('/[, ]+/', trim($match[2])));
        }
    }
    unset($config, $match);

    /**
     * Set up plugins
     */
    $iterator = new DirectoryIterator(PHERGIE_PLUGIN_DIR);
    $plugins = array();
    foreach($iterator as $entry) {
        if ($iterator->isFile() && pathinfo($entry, PATHINFO_EXTENSION) == 'php') {
            $name = basename($entry, '.php');
            if ($all xor in_array(strtolower($name), $include)) {
                $plugins[] = $name;
            }
        }
    }
    ksort($plugins);

    unset($iterator, $entry, $name, $all, $include);

    foreach($plugins as $plugin) {
        $class = 'Phergie_Plugin_' . $plugin;
        /**
         * @todo When PHP 5.3 is a stable release, change this to
         *       $class::checkDependencies($client, $plugins);
         */
        $result = call_user_func(array($class, 'checkDependencies'), $client, $plugins);
        if ($result === true) {
            $instance = new $class($client);
            $client->addPlugin($instance);
            $client->debug('Loaded ' . $plugin);
        } else {
            // handle bc
            if ($plugin === false) {
                $client->debug('Unable to load ' . $plugin);
            } else {
	            $client->debug('Unable to load ' . $plugin . ":\r\n  " . implode("\r\n  ", (array) $result));
            }
        }
    }
    unset($plugins, $plugin, $class, $instance);

    /**
     * Execute the event handling loop for the client
     */
    $state = $client->run();
    unset($client);

    switch ($state) {
        case Phergie_Driver_Abstract::RETURN_RECONNECT:
            sleep(1);
        break;
        case Phergie_Driver_Abstract::RETURN_KEEPALIVE:
            sleep(15);
        break;
        case Phergie_Driver_Abstract::RETURN_END:
        break 2;
    }
}
