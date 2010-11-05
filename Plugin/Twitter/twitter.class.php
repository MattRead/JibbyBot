<?php
/**
 * Sean's Simple Twitter Library
 *
 * Probably a little more or a little less than you need.
 *
 * Copyright 2008, Sean Coates
 * Usage of the works is permitted provided that this instrument is retained
 * with the works, so that any entity that uses the works is notified of this
 * instrument.
 * DISCLAIMER: THE WORKS ARE WITHOUT WARRANTY.
 * ( Fair License - http://www.opensource.org/licenses/fair.php )
 * Short license: do whatever you like with this.
 * 
 */
class Twitter {

    /**
     * Base URL for Twitter API
     *
     * Do not specify user/password in URL
     */
    protected $baseUrl = 'http://twitter.com/';
    
    /**
     * Full base URL (includes user/pass)
     *
     * (created in Init)
     */
    protected $baseUrlFull = null;
    
    /**
     * Twitter API user
     */
    protected $user;
    
    /**
     * Twitter API password
     */
    protected $pass;
    
    /**
     * Constructor; sets up configuration.
     * 
     * @param string $user Twitter user name; null for limited read-only access
     * @param string $pass Twitter password; null for limited read-only access
     */
    public function __construct($user=null, $pass=null) {
        $this->baseUrlFull = $this->baseUrl;
        if (null !== $user) {
            // user is defined, so use it in the URL
            $this->user = $user;
            $this->pass = $pass;
            $parsed = parse_url($this->baseUrl);
            $this->baseUrlFull = $parsed['scheme'] . '://' . $this->user . ':' .
                $this->pass . '@' . $parsed['host'];
            // port (optional)
            if (isset($parsed['port']) && is_numeric($parsed['port'])) {
                $this->baseUrlFull .= ':' . $parsed['port'];
            }
            // append path (default: /)
            if (isset($parsed['path'])) {
                $this->baseUrlFull .= $parsed['path'];
            } else {
                $this->baseUrlFull .= '/';
            }
        }
    }

    /**
     * Fetches a tweet by its number/id
     *
     * @param int $num the tweet id/number
     * @return string (false 
     */
    public function getTweetByNum($num) {
        if (!is_numeric($num)) {
            return;
        }
        $params = array(
            'http' => array(
                'method' => 'GET',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'timeout' => 3,
            )
        );
        $ctx = stream_context_create($params);
        $tweet = json_decode(file_get_contents($this->getUrlStatus($num), 0, $ctx));
        return $tweet;
    }

    /**
     * Reads [last] tweet from user
     *
     * @param string $tweeter the tweeter username
     * @param int $num this many tweets ago (1 = current tweet)
     * @return string (false on failure)
     */
    public function getLastTweet($tweeter, $num = 1)
    {
        $params = array(
            'http' => array(
                'method' => 'GET',
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'timeout' => 3,
            )
        );
        $ctx = stream_context_create($params);
        $source = json_decode(file_get_contents($this->getUrlUserTimeline($tweeter), 0, $ctx));
        if ($num > count($source)) {
            return false;
        }
        $tweet = $source[$num - 1];
        if (!isset($tweet->user->screen_name) || !$tweet->user->screen_name) {
            return false;
        }
        return $tweet;
    }

    /**
     * Sends a tweet
     *
     * @param string $txt the tweet text to send
     * @return string URL of tweet (or false on failure)
     */
    public function sendTweet($txt) {
        $data = 'status=' . urlencode($txt);
        $params = array(
            'http' => array(
                'method' => 'POST',
                'content' => $data,
                'header' => 'Content-type: application/x-www-form-urlencoded',
                'timeout' => 10,
            )
        );
        $ctx = stream_context_create($params);
        $fp = fopen($this->getUrlTweetPost(), 'rb', false, $ctx);
        if (!$fp) {
            return false;
        }
        $response = stream_get_contents($fp);
        if ($response === false) {
            return false;
        }
        $response = json_decode($response);
        return $response;
    }
    
    /**
     * Returns the base API URL
     */
    protected function getUrlApi() {
        return $this->baseUrlFull;
    }
    
    /**
     * Returns the status URL
     *
     * @param int $num the tweet number
     */
    protected function getUrlStatus($num) {
        return $this->getUrlApi() . 'statuses/show/'. urlencode($num) .'.json';
    }
    
    /**
     * Returns the user timeline URL
     */
    protected function getUrlUserTimeline($user) {
        return $this->getUrlApi() . 'statuses/user_timeline/'. urlencode($user) .'.json';
    }
    
    /**
     * Returns the tweet posting URL
     */
    protected function getUrlTweetPost() {
        return $this->getUrlApi() . 'statuses/update.json';
    }
    
    /**
     * Output URL: status
     */
    public function getUrlOutputStatus(StdClass $tweet) {
        return $this->baseUrl . urlencode($tweet->user->screen_name) . '/statuses/' . urlencode($tweet->id);
    }
    
    /**
     * Decoding because Twitter doesn't know how to context-encode properl
     */
    public function decode($str) {
        return html_entity_decode($str);
    }
}
