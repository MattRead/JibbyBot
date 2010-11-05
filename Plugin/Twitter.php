<?php

require dirname(__FILE__) . '/Twitter/twitter.class.php';
require dirname(__FILE__) . '/Twitter/laconica.class.php';

function filterBadWords($str){

 // words to filter
 $badwords=array( "fuck", "shit", "ass", "mtah", "wordpress", "dick ");

 // replace filtered words with
 $replacements=array( "[naughty!]", "[how wude!]", "%*@#!^", "[censored]" );

 for($i=0;$i < sizeof($badwords);$i++){
  srand((double)microtime()*1000000); 
  $rand_key = (rand()%sizeof($replacements));
  $str=eregi_replace($badwords[$i], $replacements[$rand_key], $str);
 }
 return $str;
}

/**
 * Twitter plugin. Allows tweet (if configured) and twitter commands
 */
class Phergie_Plugin_Twitter extends Phergie_Plugin_Abstract_Command
{
    /**
     * Twitter object
     */
    public $twitter;
	protected $dent;

    /**
     * Allow only admins to tweet
     *
     * @phergieConfig twitter.tweetrequireadmin
     */
    static $TWEET_REQUIRE_ADMIN = false;

    /**
     * Initialize (set up configuration vars)
     *
     * @return void
     */
    public function onInit()
    {
        if (!$twitterClass = $this->getIni('twitter.class')) {
            $twitterClass = 'Twitter';
        }
        if ($url = $this->getIni('twitter.url')) {
            $this->twitter = new $twitterClass($this->getIni('twitter.user'), $this->getIni('twitter.password'), $url);
        } else {
            $this->twitter = new $twitterClass();//$this->getIni('twitter.user'), $this->getIni('twitter.password'));
        }
		$this->dent = new Twitter_Laconica($this->getIni('twitter.user'), $this->getIni('twitter.password'));
    }
    
    /**
     * Fetches the associated tweet and relays it to the channel
     *
     * @param string $tweeter if numeric the tweet number/id, otherwise the twitter user name (optionally prefixed with @)
     * @param int $num optional tweet number for this user (number of tweets ago)
     * @return void
     */
    public function onDoTwitter($tweeter = null, $num = 1)
    {
        $user = $this->getIni('twitter.user');
        $source = $this->event->getSource();
		if ( $tweeter == null ) {
			$tweeter = $this->event->getNick();
		}
        if (is_numeric($tweeter)) {
            $tweet = $this->twitter->getTweetByNum($tweeter);
        } else if (is_null($tweeter) && $user) {
            $tweet = $this->twitter->getLastTweet($user, 1);
        } else {
            $tweet = $this->twitter->getLastTweet(ltrim($tweeter, '@'), $num);
        }
        if ($tweet) {
            $this->doPrivmsg($source, $this->formatTweet($tweet));
        }
		else {
			 $this->doPrivmsg($source, "Couldn't find status for $tweeter");
		}
    }

    public function onDoDent($tweeter = null, $num = 1)
    {
        $source = $this->event->getSource();
        if ( $tweeter == null ) {
            $tweeter = $this->event->getNick();
        }
        if (is_numeric($tweeter)) {
            $tweet = $this->dent->getTweetByNum($tweeter);
        } else {
            $tweet = $this->dent->getLastTweet(ltrim($tweeter, '@'), $num);
        }
        if ($tweet) {
            $this->doPrivmsg($source, $this->formatDent($tweet));
        }
        else {
             $this->doPrivmsg($source, "Couldn't find status for $tweeter");
        }
    }

    
    /**
     * Sends a tweet to Twitter as the configured user
     *
     * @param string $txt the text to tweet
     * @return void
     */
    public function onDoTweet($txt) {
        $nick = $this->event->getNick();
        $txt = str_replace('/me ', "@".$nick." ", $txt);
        if (!$this->getIni('twitter.user')) {
            return;
        }
        if (self::$TWEET_REQUIRE_ADMIN && !$this->fromAdmin(true)) {
            return;
        }
        $source = $this->event->getSource();
        if ($tweet = $this->dent->sendTweet(filterBadWords($txt))) {
            $this->doPrivmsg($source, 'Tweeted: '. $this->dent->getUrlOutputStatus($tweet));
        } else {
            $this->doNotice($nick, 'Tweet failed');
        }
    }
    
    /**
     * Formats a Tweet into a message suitable for output
     *
     * @param object $tweet
     * @return string
     */
    protected function formatTweet(StdClass $tweet) {
        return '<@' . $tweet->user->screen_name .'> '. $this->twitter->decode($tweet->text)
            . ' - ' . $this->getCountdown(time() - strtotime($tweet->created_at)) . ' ago'
            . ' (' . $this->twitter->getUrlOutputStatus($tweet) . ')';
    }
    protected function formatDent(StdClass $tweet) {
		return '<@' . $tweet->user->screen_name .'> '. $tweet->text
            . ' - ' . $this->getCountdown(time() - strtotime($tweet->created_at)) . ' ago'
            . ' (' . $this->dent->getUrlOutputStatus($tweet) .')';
    }

}
