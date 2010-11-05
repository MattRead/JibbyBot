<?php

/**
 * Handles requests for checking spelling of specified words and returning
 * either confirmation of correctly spelled words or potential correct
 * spellings for misspelled words.
 */
class Phergie_Plugin_SpellCheck extends Phergie_Plugin_Abstract_Base
{
    /**
     * Indicates that a local directory is required for this plugin
     *
     * @var bool
     */
    protected $needsDir = true;

    /**
     * Spell check dictionary handler
     *
     * @var resource
     */
    protected $pspell;

    /**
     * Limit on the number of potential correct spellings returned
     *
     * @var int
     */
    protected $limit;

    /**
     * Obtains configuration settings and initializes the spell check
     * dictionary handler.
     *
     * @return void
     */
    public function onInit()
    {
        $config = pspell_config_create($this->getPluginIni('lang'));
        pspell_config_personal($config, $this->dir . 'custom.pws');
        pspell_config_repl($config, $this->dir . 'custom.repl');
        $this->pspell = pspell_new_config($config);

        $this->limit = $this->getPluginIni('limit');
        if (!$this->limit) {
            $this->limit = 5;
        }
    }

    /**
     * Returns whether or not the plugin's dependencies are met.
     *
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @see Phergie_Plugin_Abstract_Base::checkDependencies()
     * @return bool TRUE if dependencies are met, FALSE otherwise
     */
    static public function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
    {
    	$errors = array();

    	if (!extension_loaded('pspell')) {
            $errors[] = 'pspell php extension is required';
    	}
    	if (!$client->getIni('spellcheck.lang')) {
            $errors[] = 'Ini setting spellcheck.lang must be filled-in';
        }

        return empty($errors) ? true : $errors;
    }

    /**
     * Intercepts and handles requests for spell checks.
     *
     * @return void
     */
    public function onPrivmsg()
    {
        $source = $this->event->getSource();
        $message = $this->event->getArgument(1);
        $target = $this->event->getNick();

        // Command prefix check
        $prefix = preg_quote(trim($this->getIni('command_prefix')));
        $bot = preg_quote($this->getIni('nick'));
        $exp = '(?:(?:' . $bot . '\s*[:,>]?\s+(?:' . $prefix . ')?)|(?:' . $prefix . '))';

        // Do spell checking of the given word
        if (preg_match('#(?:^' . $exp . 'spell(?:check)?\s+(\S+)|(\S+)\s*\(sp\??\))#i', $message, $m)) {
            $word = (!empty($m[1]) ? $m[1] : $m[2]);
            if (!pspell_check($this->pspell, $word)) {
                $suggestions = pspell_suggest($this->pspell, $word);
                if (empty($suggestions)) {
                    $this->doPrivmsg($source, 'I could not find any suggestions for ' . $word);
                } else {
                    $suggestions = array_splice($suggestions, 0, $this->limit);
                    $this->doPrivmsg($source, $target . ': Suggestions for \'' . $word . '\': ' . implode(', ', $suggestions));
                }
            } else {
                $this->doPrivmsg($source, $target . ': The word ' . $word . ' seems to be spelled correctly.');
            }
        // Check to see if if someone is trying to add a word or an replacement to the custom dictionary.
        } elseif (preg_match('#^' . $exp . 'add(word|repl(?:ace(?:ment)?)?)\s+(\S+)(?:\s+(\S+))?#i', $message, $m)) {
            if ($this->fromAdmin()) {
                $m = array_pad($m, 4, null);
                $addWord = (substr(strtolower($m[1]), 0, 4) == 'word');
                $correct = ($addWord ? $m[2] : $m[3]);
                $mispelled = ($addWord ? $m[3] : $m[2]);

                // Check to see if the correct word is empty or not, its required in both cases
                if (empty($correct)) {
                    return;
                }

                // pSpell doesn't like hyphenated words so check for them
                if (strpos($correct, '-') !== false || strpos($mispelled, '-') !== false) {
                    $this->doNotice($target, 'You can not add hyphenated words to the dictionary.');
                    return;
                }

                // Check to see if the given word is in the dictionary already
                if (!pspell_check($this->pspell, $m[2])) {
                    if ($addWord) {
                        if (pspell_add_to_personal($this->pspell, $correct)) {
                            pspell_save_wordlist($this->pspell);
                            $this->doNotice($target, 'Added the word "' . $correct . '" to the personal dictionary.');
                        } else {
                            $this->doNotice($target, 'Could not add the word "' . $correct . '" to the personal dictionary.');
                        }
                    } else {
                        // Check to see if both the mispelled and correct word start with the same letter
                        if (substr(strtolower($mispelled), 0, 1) != substr(strtolower($correct), 0, 1)) {
                            $this->doNotice($target, 'Both the correct and mispelled word of the replacement need to start with the same letter.');
                        }

                        if (pspell_store_replacement($this->pspell, $mispelled, $correct)) {
                            pspell_save_wordlist($this->pspell);
                            $this->doNotice($target, 'Added the replacement "' . $correct . '" for the word "' . $mispelled . '".');
                        } else {
                            $this->doNotice($target, 'Could not add the replacement "' . $correct . '" for the word "' . $mispelled . '".');
                        }
                    }
                } else {
                    $this->doNotice($target, 'The word "' . $m[2] . '" seems to be in the dictionary already.');
                }
            } else {
                $this->doNotice($target, 'You do not have permission to add words to the dictionary.');
            }
        }
    }

    /**
     * Parses incoming CTCP action requests for spelling suggestions.
     *
     * @return void
     */
    public function onAction()
    {
        $message = $this->event->getArgument(1);
        $target = $this->event->getNick();

        if (preg_match('#(\S+)\s*\(sp\??\)#i', $message, $m)) {
            $word = $m[1];
            if (!pspell_check($this->pspell, $word)) {
                $suggestions = pspell_suggest($this->pspell, $word);
                if (empty($suggestions)) {
                    $this->doPrivmsg($target, 'I could not find any suggestions for ' . $word);
                } else {
                    $suggestions = array_splice($suggestions, 0, $this->limit);
                    $this->doPrivmsg($target, $target . ': Suggestions for \'' . $word . '\': ' . implode(', ', $suggestions));
                }
            } else {
                $this->doPrivmsg($target, $target . ': The word ' . $word . ' seems to be spelled correctly.');
            }
        }
    }

    /**
     * Parses incoming CTCP request for spelling suggestions.
     *
     * @return void
     */
    public function onCtcp()
    {
        $source = $this->event->getSource();
        $ctcp = $this->event->getArgument(1);

        if (preg_match('{spell(?:[\s_+-]*check)?\s+(\S+)}ix', $ctcp, $m)) {
            $word = $m[1];
            if (!pspell_check($this->pspell, $word)) {
                $suggestions = pspell_suggest($this->pspell, $word);
                if (empty($suggestions)) {
                    $this->doPrivmsg($source, 'I could not find any suggestions for ' . $word);
                } else {
                    $suggestions = array_splice($suggestions, 0, $this->limit);
                    $this->doPrivmsg($source, $target . ': Suggestions for \'' . $word . '\': ' . implode(', ', $suggestions));
                }
            } else {
                $this->doPrivmsg($source, $target . ': The word ' . $word . ' seems to be spelled correctly.');
            }
        }
    }
}
