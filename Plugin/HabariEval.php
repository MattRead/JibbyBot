<?php

/**
 */
class Phergie_Plugin_HabariEval extends Phergie_Plugin_Abstract_Command
{
    private static $trusted_nicks = array( 'BigJibby', 'MattRead', 'meller', 'mikelietz','mkieltiez', 'ringmaster', 'skippy', 'michaeltwofish', 'RandyWalker', 'Caius' );
	const PRIVATE_KEY = 'a5930cbc7a686b04311d024888a0ed75';
    /**
     * @return void
     */
    public function onDoHeval($code)
    {
        if ( ! in_array( $this->event->getNick(), self::$trusted_nicks ) ) {
            $this->doPrivmsg(
                $this->event->getSource(),
                'you are not trusted!'
            );
            return;
        }
		if (preg_match('#^-r (.+)#i', $code, $c)) {
	        $res = self::runHabariCode($c[1], $this->event->getNick(), true);
		}
		else {
			$res = self::runHabariCode($code, $this->event->getNick());
		}
        if (strlen($res) < 300) {
            $this->doPrivmsg($this->event->getSource(), str_replace("\n", '', $res));
        }
        else {
        	$this->doPrivmsg(
				$this->event->getSource(),
				sprintf("Results available at %s",
					self::pastoid($res, $this->event->getNick())
				)
			);
		}
    }

	public function onDoSh($code) {
		$this->onDoHeval("echo shell_exec('" . str_replace("'", "\'", $code) . "');");
	}

    /**
     * Reads last commit/ticket logs
     */
    public static function runHabariCode($code, $nick, $raw = false)
    {
		$public_key = md5($nick);
		$time = time();
		$post = http_build_query(array(
			'hmac' => md5(sprintf("%s%s%s", self::PRIVATE_KEY, $public_key, $time)),
			'public_key' => $public_key,
			'time' => $time,
			'eval' => $code
			));
		
		$url = "http://mattread.com/old/heval/1";
		$context = stream_context_create(array (
        'http' => array (
            'method' => 'POST',
            'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
                . "Content-Length: " . strlen($post) . "\r\n",
            'content' => $post
            )
        ));
		$res = file_get_contents($url, false, $context);
		unset($time, $post, $url, $context);
		if ($raw) {
			return $res;
		}
		else {
			return strip_tags($res);
		}
	}
	
function pastoid($paste, $name='JibbyBot', $api='SP43GEwFtvwOAEN9F1BdUZAkTXcEXmep')
{
	$post = http_build_query(array(
		'content' => $paste,
		'type' => '5',
		));
	$context = stream_context_create(array(
	'http' => array (
		'name'=>$name,
		'method' => 'POST',
		'header'=> "Content-type: application/x-www-form-urlencoded\r\n"
			. "Content-Length: " . strlen($post) . "\r\n",
		'content' => $post
		)
	));
 
	if ($page = fopen('http://pastebin.ca/quiet-paste.php?api=' . $api, 'r', false, $context)) {
		stream_set_timeout($page, 3.5);
		$contents = '';
		while (!feof($page)) {
			$contents .= fread($page, 8192);
		}
		if (preg_match('#SUCCESS:\s*(\d*)#is', $contents, $match)) {
			$url = 'http://pastebin.ca/'.$match[1];
		}
		else {
			$url = "Could not paste to pastebin. Sorry.";
		}
		
	}
	return $url;
}
}
