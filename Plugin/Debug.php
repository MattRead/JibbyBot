<?php

/**
 * Provides commands relevant for debugging and profiling, such as measurement
 * of memory consumption.
 */
class Phergie_Plugin_Debug extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Responds to requests for statistics related to the bot's memory
     * consumption.
     *
     * @return void
     */
    public function onDoMem()
    {
        if (function_exists('memory_get_usage')) {
            $target = $this->event->getNick();
            $text = 'Current : '.number_format(memory_get_usage() / 1024).'KB' .
                    (function_exists('memory_get_peak_usage') ? ' / Peak : '.number_format(memory_get_peak_usage() / 1024).'KB' : '');
            $this->doPrivmsg($this->event->getSource(), $target . ': ' . $text);
        }
    }

    /**
     * Returns the amount of time the bot has been up.
     *
     * @return void
     */
    public function onDoUptime()
    {
        $target = $this->event->getNick();

        $this->doPrivmsg($this->event->getSource(), $target . ': Uptime: ' . $this->getCountdown(time() -$this->getStartTime()));
    }

    /**
     * Returns a list of all the loaded extensions
     *
     * @return void
     */
    public function onDoExtensions()
    {
        $target = $this->event->getNick();

        $extensions = get_loaded_extensions();
        if (is_array($extensions)) {
            $extensions = array_map('ucfirst', $extensions);
            sort($extensions);
        }
        $this->doPrivmsg($this->event->getSource(), $target . ': Loaded Extensions: ' . (is_array($extensions) ? implode(', ', $extensions) : 'N/A'));
        unset($extensions);
    }

    /**
     * Returns the version for PHP or a loaded extension
     *
     * @return void
     */
    public function onDoGetversion($prog = null)
    {
        $target = $this->event->getNick();

        $prog = (isset($prog) ? trim(strtolower($prog)) : null);
        $extensions = get_loaded_extensions();
        if (is_array($extensions)) {
            $extensions = array_map('strtolower', $extensions);
        }

        if (empty($prog)) {
            $message = 'Phergie Version: ' . PHERGIE_VERSION;
        } else if ($prog == 'php') {
            $message = 'PHP Version: ' . phpversion();
        } else if (is_array($extensions) && in_array($prog, $extensions)) {
            $version = phpversion($prog);
            $message = ucfirst($prog) . ' Version: ' . ($version ? $version : 'N/A');
        } else {
            $message = 'Unknown Extension: ' . ucfirst($prog);
        }
        $this->doPrivmsg($this->event->getSource(), $target . ': ' . $message);
        unset($prog, $extensions, $version, $message);
    }
}
