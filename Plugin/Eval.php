<?php

/**
 * Handles requests from administrators to evaluate a string
 */
class Phergie_Plugin_Eval extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Evaluates the given string and outputs the evaluated statement while any
     * any dumped data gets dumped to the console.
     *
     * @return void
     */
    public function onDoEval($code)
    {
        $user = $this->event->getNick();

        // Check to see if the admin is a hostmask admin only and not an op
        if ($this->fromAdmin(true)) {
            $code = trim($code);
            if (empty($code)) {
                return;
            }

            // Check to see if the -r arg is set, if so, run the code as is, else prepend return
            if (substr(strtolower($code), 0, 2) == '-r') {
                $code = trim(substr($code, 2));
            } else {
                $code = trim((strtolower(substr($code, 0, 6)) != 'return' ? 'return ' : '') . $code);
            }

            if (!empty($code)) {
                if (substr($code, -1) != ';') {
                    $code .= ';';
                }

                // Use output buffering to catch any output from eval
                ob_start();
                $eval = eval($code);
                $contents = ob_get_contents();
                ob_end_clean();

                // Dump the content of the output buffering to the console
                if (!empty($contents)) {
                    $this->debug('OUTPUT:' . PHP_EOL . trim($contents));
                    unset($contents);
                }

                if (!empty($eval)) {
                    $this->doPrivmsg($this->event->getSource(), trim($eval));
                    unset($eval);
                }
            }
        } else {
            $this->doNotice($user, 'You do not have permission to use eval.');
        }
    }

    /**
     * Executes the given string and outputs the last line that exec returns
     * while the full exec request gets dumped to the console if the-c flag
     * is specified.
     *
     * @return void
     */
    public function onDoExec($code)
    {
        $user = $this->event->getNick();

        // Check to see if the admin is a hostmask admin only and not an op
        if ($this->fromAdmin(true)) {
            $code = trim($code);
            if (empty($code)) {
                return;
            }

            $console = false;
            // Check to see if the -c arg is set, if so, dump the entire exec result to console
            if (substr(strtolower($code), 0, 2) == '-c') {
                $code = trim(substr($code, 2));
                $console = true;
            }

            if (!empty($code)) {
                $exec = exec($code, $output);
                if (!empty($exec)) {
                    $this->doPrivmsg($this->event->getSource(), trim($exec));
                    unset($exec);
                }
                if (!empty($output)) {
                    if ($console) {
                        $this->debug('OUTPUT:' . PHP_EOL . implode(PHP_EOL, $output));
                    }
                    unset($output);
                }
            }
        } else {
            $this->doNotice($user, 'You do not have permission to use exec.');
        }
    }
}
