<?php

/**
 * Implements delaying of asynchronous task execution.
 *
 * The delay configuration setting should be set to the minimum amount
 * of time (in seconds) between consecutive executions of the task being
 * delayed.
 */
abstract class Phergie_Plugin_Abstract_Cron extends Phergie_Plugin_Abstract_Command
{
    /**
     * Default value (in seconds) for delay setting should it not be specified
     * in the configuration file, can be overriden by subclasses
     *
     * @var int
     */
    protected $defaultDelay = 60;

    /**
     * Time delay (in seconds) must pass between consecutive task executions
     *
     * @var int
     */
    private $delay = null;

    /**
     * UNIX timestamp representing the earliest time at which the occurrence of
     * an event (PING or PRIVMSG) will trigger the task execution
     *
     * @var int
     */
    private $nextCall = null;

    /**
     * Performs a single execution of the task being delayed.
     *
     * @return void
     */
    abstract protected function run();

    /**
     * Checks if the run method should be executed and executes it if needed.
     *
     * @return void
     */
    final protected function check()
    {
        if ($this->delay === null) {
            if ((($time = $this->getPluginIni('delay')) !== null) || $time = $this->defaultDelay) {
                $this->delay = $time;
            }
        }
        if (time() > $this->nextCall) {
            $this->run();
            $this->nextCall = time() + $this->delay;
        }
    }

    /**
     * Checks to see if the run method should be called when a raw event
     * occurs.
     *
     * @return void
     */
    public function onRaw()
    {
        $this->check();
    }
}
