<?php

/**
 */
class Phergie_Plugin_Markov extends Phergie_Plugin_Abstract_Command
{
	public function onDoDrbacchus($dat) {
		$this->onDoAlice($dat);
	}
    /**
     * @return void
     */
    public function onDoAlice( $dat )
    {
    	try {
		$text = shell_exec('/home/matt/alice.py ' . escapeshellarg($dat) );
		$this->doPrivmsg(
			$this->event->getSource(),
			$text
		);
	}
	catch (Exception $e) {
		$this->doPrivmsg(
			$this->event->getSource(),
			'Hail ARIS!'
		);
	}
	unset($table, $text, $c);
    }
}

function generate_markov_table($text, $look_forward) {
    $table = array();

    // now walk through the text and make the index table
    for ($i = 0; $i < strlen($text); $i++) {
        $char = substr($text, $i, $look_forward);
        if (!isset($table[$char])) $table[$char] = array();
    }

    // walk the array again and count the numbers
    for ($i = 0; $i < (strlen($text) - $look_forward); $i++) {
        $char_index = substr($text, $i, $look_forward);
        $char_count = substr($text, $i+$look_forward, $look_forward);

        if (isset($table[$char_index][$char_count])) {
            $table[$char_index][$char_count]++;
        } else {
            $table[$char_index][$char_count] = 1;
        }
    }

    return $table;
}

function generate_markov_text($char, $length, $table, $look_forward) {
    // get first character
    // array_rand($table);
    $o = $char;

    for ($i = 0; $i < ($length / $look_forward); $i++) {
        $newchar = return_weighted_char($table[$char]);

        if ($newchar) {
            $char = $newchar;
            $o .= $newchar;
        } else {
            $char = array_rand($table);
        }
    }

    return $o;
}


function return_weighted_char($array) {
    if (!$array) return false;

    $total = array_sum($array);
    $rand  = mt_rand(1, $total);
    foreach ($array as $item => $weight) {
        if ($rand <= $weight) return $item;
        $rand -= $weight;
    }
}
