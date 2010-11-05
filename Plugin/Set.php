<?php

/**
 * Handles requests from administrators for the bot to set a .ini value.
 */
class Phergie_Plugin_Set extends Phergie_Plugin_Abstract_Command
{
    /**
     * Flag indicating whether or not the plugin is an admin plugin or not
     *
     * @var bool
     */
    public $needsAdmin = true;

    /**
     * Sets an ini value using <Botname>: set [-a|append] varname value
     */
    public function onDoSet($var, $value, $tmp = null)
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            $append = $var === '-a' || $var === 'append' || $var === '-append';
            // Got an append parameter, so we shift value/tmp down to var/value
            if ($append) {
                $var = $value;
                $value = $tmp;
            } elseif (!empty($tmp)) {
                $value .= ' ' . $tmp;
            }

            // Get ini file
            $contents = preg_replace("#\r\n|\r|\n#", PHP_EOL, file_get_contents(PHERGIE_INI_PATH));
            // Replace var/value
            if (preg_match('#^(' . str_replace('.', '\\.', $var) . '\s*= *)(.*)$#im', $contents, $m)) {
                $contents = preg_replace('#^' . str_replace('.', '\\.', $var) . '\s*=.*$#im', $var . ' = ' . str_replace('$', '\\$', $this->makeIniValue($value, $m[2], $append)), $contents);
                $this->setIni($var, $this->parseIniValue($this->makeIniValue($value, $m[2], $append, true)));
                $this->doNotice($user, 'Updated Setting: ' . $var . ' = ' . $this->makeIniValue($this->getIni($var)));
            // Insert it if not set
            } else {
                $contents .= PHP_EOL . $var . ' = ' . $this->makeIniValue($value);
                $this->setIni($var, $this->parseIniValue($value));
                $this->doNotice($user, 'Inserted Setting: ' . $var . ' = ' . $this->makeIniValue($this->getIni($var)));
            }
            // Save ini file
            file_put_contents(PHERGIE_INI_PATH, $contents);
        } else {
            $this->doNotice($user, 'You do not have permission to set any settings.');
        }
    }

    /**
     * Returns a ini setting
     */
    public function onDoGet($var)
    {
        $user = $this->event->getNick();
        if ($this->fromAdmin(true)) {
            $this->doNotice($user, $var . ' = ' . $this->makeIniValue($this->getIni($var)));
        } else {
            $this->doNotice($user, 'You do not have permission to get any settings.');
        }
    }

    /**
     * Builds a ini value
     */
    protected function makeIniValue($new, $old = "", $append = false, $noquotes = false)
    {
        $new = trim($new, "\" '");
        $old = trim($old, "\" \n\r");

        if ($append && !empty($old)) {
            return ($noquotes?'':'"') . $old . ', ' . $new . ($noquotes?'':'"');
        }

        if (is_numeric($new)) {
            return $new;
        }

        if ($this->parseIniValue($new) != $new) {
            return (strtolower($new) != 'null' ? $new : '');
        } else {
            return ($noquotes?'':'"') . $new . ($noquotes?'':'"');
        }
    }

    /**
     * Reads a ini value similarly to parse_ini_file()
     */
    protected function parseIniValue($value)
    {
        $value = trim($value);
        if (strpos($value, ':') !== false) {
            return $value;
        }

        $const = get_defined_constants();

        switch (strtolower($value)) {
            case 'true':
            case 'yes':
            case 'on':
            case '1':
                $return = '1';
            break;
            case 'false':
            case 'no':
            case 'off':
            case '0':
            case '':
                $return = '';
            break;
            case 'null':
                $return = null;
            break;
            default:
                $return = (isset($const[$value]) ? $const[$value] : $value);
            break;
        }
        unset($const, $value);

        return $return;
    }
}
