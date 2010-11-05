<?php

/**
 * Handles parsing and execution of commands by users via messages sent to
 * channels or directly to the bot.
 */
abstract class Phergie_Plugin_Abstract_Command extends Phergie_Plugin_Abstract_Base
{
    /**
     * Cache for command lookups used to confirm that corresponding methods
     * exist and parameter counts match
     *
     * @var array
     */
    private $methods;

    /**
     * Intercepts a message and processes any contained recognized commands.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $this->processCommand($this->event->getArgument(1));
    }

    /**
     * Parses a given message and, if its format corresponds to that of a
     * defined command, calls the handler method for that command with any
     * provided parameters.
     *
     * @param string $message Message to analyze
     * @param bool $ignorePrefix Whether or not to ignore the command prefix
     * @param bool $forceBotPrefix Whether or not to require the bot's name as a
     *                             prefix
     * @return void
     */
    protected final function processCommand($message, $ignorePrefix = false, $forceBotPrefix = false)
    {
        if (!$this->methods) {
            $class = new ReflectionClass(get_class($this));
            foreach($class->getMethods() as $method) {
                $name = $method->getName();
                if (strpos($name, 'onDo') === 0) {
                    $this->methods[strtolower(substr($name, 4))] = array(
                        $method->getNumberOfParameters(),
                        $method->getNumberOfRequiredParameters()
                    );
                }
            }
        }

        // Get the command prefix
        $commandPrefix = trim($this->getIni('command_prefix'));

        // Checks to see if the plugin is an admin plugin
        if ($this->needsAdmin && empty($commandPrefix)) {
            $forceBotPrefix = true;
        }

        // Checks to see if the command was prefixed with the bot's name
        $source = $this->event->getSource();
        $user = $this->event->getNick();
        $bot = $this->getIni('nick');
        // The Botprefix regex expression
        $exp = '(' . preg_quote($bot) . '\s*[:,>]?\s+)' . (!($source[0] == '#' && $forceBotPrefix) ? '?' : '');

        if (preg_match('/^' . $exp . '(\S+)(?:[\s' . chr(240) . ']+(.*))?$/i', $message, $match)) {
            $botPrefix = $match[1];
            $command = strtolower($match[2]);
            $params = isset($match[3]) ? $match[3] : array();

            // Checks the command for a prefix if one is specified in the config
            if (!empty($commandPrefix)) {
                if ($hasPrefix = (substr($command, 0, strlen($commandPrefix)) == $commandPrefix)) {
                    $command = substr($command, strlen($commandPrefix));
                }
                if ($botPrefix) {
                    $ignorePrefix = true;
                }
            }

            if ((empty($commandPrefix) || $hasPrefix || $ignorePrefix) && isset($this->methods[$command])) {
                if ($this->needsAdmin && !$this->fromAdmin()) {
                    $this->doNotice($user, 'You do not have permission to use the command "' . $command . '."');
                    return;
                }

                $method = 'onDo' . ucfirst($command);
                if (empty($params)) {
                    if (!$this->methods[$command][1]) {
                        $this->$method();
                    }
                } else {
                    $params = preg_split('/[\s' . chr(240) . ']+/', $params, $this->methods[$command][0]);
                    if ($this->methods[$command][1] <= count($params)) {
                        call_user_func_array(array($this, $method), $params);
                    }
                }
            }
        }
    }
}
