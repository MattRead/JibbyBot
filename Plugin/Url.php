<?php

/**
 * Monitors incoming messages for instances of URLs and responds with messages
 * containing relevant information about detected URLs.
 *
 * Has an utility method accessible through $this->getPlugin('Url')->getTitle('http://foo..')
 */
class Phergie_Plugin_Url extends Phergie_Plugin_Abstract_Base
{
    /**
     * Links output format
     *
     * Can use the variables %nick%, %title% and %link% in it to display page titles
     * and links
     *
     * @var string
     */
    protected $baseFormat = '%nick%: %message%';
    protected $messageFormat = '[ %link% ] %title%';

    /**
     * Merged link output
     *
     * If true, then multiple posted links will be merged into one line
     *
     * @var bool
     */
    protected $mergeLinks = true;

    /**
     * Max length of the fetched URL title
     *
     * @var int
     */
    protected $titleLength = 40;

    /**
     * Url cache to prevent spamming, especially with multiple bots on the same channel
     */
    protected $urlCache = array();
    protected $tinyCache = array();

    /**
     * The time in seconds to store the cached entries
     * Setting it to 0 or below disables the cache expiration
     */
    protected $expire = 1800;

    /**
     * The number of entries to keep in the cache at one time per channel
     * Setting it to 0 or below disables the cache limit
     */
    protected $limit = 10;

    /**
     * This setting determines if URL will use a fallback when trying to open
     * a https stream when OpenSSL isn't available, instead it will try opening
     * a http stream instead.
     */
    protected $sslFallback = true;

    /**
     * Set to true by the custom error handler if an HTTP error code has been received
     *
     * @var boolean
     */
    protected $errorStatus = false;
    protected $errorMessage = null;

    /**
     * Whether or not to display error messages as the title if a link posted
     * encounters an error.
     *
     * @var boolean
     */
    protected $showErrors = true;

    /**
     * Whether or not to detect schemeless urls (i.e. "example.com")
     *
     * @var boolean
     */
    protected $detectSchemeless = false;

    /**
     * List of HTTP errors to return when the requested URL returns an HTTP error
     *
     * @var array
     */
    protected $httpErrors = array(
        100 => '100 Continue',
        200 => '200 OK',
        201 => '201 Created',
        204 => '204 No Content',
        206 => '206 Partial Content',
        300 => '300 Multiple Choices',
        301 => '301 Moved Permanently',
        302 => '302 Found',
        303 => '303 See Other',
        304 => '304 Not Modified',
        307 => '307 Temporary Redirect',
        400 => '400 Bad Request',
        401 => '401 Unauthorized',
        403 => '403 Forbidden',
        404 => '404 Not Found',
        405 => '405 Method Not Allowed',
        406 => '406 Not Acceptable',
        408 => '408 Request Timeout',
        410 => '410 Gone',
        413 => '413 Request Entity Too Large',
        414 => '414 Request URI Too Long',
        415 => '415 Unsupported Media Type',
        416 => '416 Requested Range Not Satisfiable',
        417 => '417 Expectation Failed',
        500 => '500 Internal Server Error',
        501 => '501 Method Not Implemented',
        503 => '503 Service Unavailable',
        506 => '506 Variant Also Negotiates'
    );

    /**
     * An array containing a list of TLDs used for non-scheme matches
     *
     * @var array
     */
    protected $tldList = array();

    /**
     * Returns whether or not the plugin's dependencies are met.
     *
     * @param Phergie_Driver_Abstract $client Client instance
     * @param array $plugins List of short names for plugins that the
     *                       bootstrap file intends to instantiate
     * @see Phergie_Plugin_Abstract_Base::checkDependencies()
     * @return bool TRUE if dependencies are met, FALSE otherwise
     */
    public static function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
    {
    	if (!self::staticPluginLoaded('TinyUrl', $client, $plugins)) {
            return 'TinyUrl plugin must be enabled';
        }

        return true;
    }

    /**
     * Initializes settings
     *
     * @return void
     */
    public function onConnect()
    {
        // Get a list of valid TLDs
        if (!is_array($this->tldList) || count($this->tldList) <= 6) {
            if ($this->pluginLoaded('Tld')) {
                $this->tldList = Phergie_Plugin_Tld::getTlds();
                if (is_array($this->tldList)) {
                    $this->tldList = array_keys($this->tldList);
                }
            }
            if (!is_array($this->tldList) || count($this->tldList) <= 0) {
                $this->tldList = array('com', 'org', 'net', 'gov', 'us', 'uk');
            }
            rsort($this->tldList);
        }
    }

    /**
     * Checks an incoming message for the presence of a URL and, if one is
     * found, responds with its title if it is an HTML document and the
     * TinyURL equivalent of its original URL if it meets length requirements.
     *
     * @return void
     */
    public function onPrivmsg()
    {
    	return;
        $source = $this->event->getSource();
        $user = $this->event->getNick();

        // URL Match
        $this->updateSetting('detect_schemeless', 'detectSchemeless');
        if (preg_match_all('#'.($this->detectSchemeless ? '' : 'https?://').'(?:([0-9]{1,3}(?:\.[0-9]{1,3}){3})(?![^/]) |
                            ('.($this->detectSchemeless ? '(?<!http:/|https:/)[@/\\\]' : '').')?(?:(?:[a-z0-9_-]+\.?)+\.[a-z0-9]{1,6}))[^\s]*#xis',
                            $this->event->getArgument(1), $matches, PREG_SET_ORDER)) {

            // Update the settings on the fly to take into account any ini changes while the bot is running
            $this->updateSetting('base_format', 'baseFormat');
            $this->updateSetting('message_format', 'messageFormat');
            $this->updateSetting('merge_links', 'mergeLinks');
            $this->updateSetting('title_length', 'titleLength', true);
            $this->updateSetting('show_errors', 'showErrors');

            $responses = array();
            foreach($matches as $m) {
                $url = trim(rtrim($m[0], ', ].?!;'));

                // Check to see if the URL was from an email address, is a directory, etc
                if (!empty($m[2])) {
                    $this->debug('Invalid Url: URL is either an email or a directory path. (' . $url . ')');
                    continue;
                }

                // Parse the given URL
                if (!$parsed = $this->parseUrl($url)) {
                    $this->debug('Invalid Url: Could not parse the URL. (' . $url . ')');
                    continue;
                }

                // Check to see if the given IP/Host is valid
                if (!empty($m[1]) and !$this->checkValidIP($m[1])) {
                    $this->debug('Invalid Url: ' . $m[1] . ' is not a valid IP address. (' . $url . ')');
                    continue;
                }

                // Process TLD if it's not an IP
                if (empty($m[1])) {
	                // Get the TLD from the host
	                $pos = strrpos($parsed['host'], '.');
	                $parsed['tld'] = ($pos !== false ? substr($parsed['host'], ($pos+1)) : '');

	                // Check to see if the URL has a valid TLD
	                if (is_array($this->tldList) && !in_array(strtolower($parsed['tld']), $this->tldList)) {
	                    $this->debug('Invalid Url: ' . $parsed['tld'] . ' is not a supported TLD. (' . $url . ')');
	                    continue;
	                }
                }

                // Check to see if the URL is to a secured site or not and handle it accordingly
                if ($parsed['scheme'] == 'https' && !extension_loaded('openssl')) {
                    if (!$this->sslFallback) {
                        $this->debug('Invalid Url: HTTPS is an invalid scheme, OpenSSL isn\'t available. (' . $url . ')');
                        continue;
                    } else {
                        $parsed['scheme'] = 'http';
                    }
                }

                if (!in_array($parsed['scheme'], array('http', 'https'))) {
                    $this->debug('Invalid Url: ' . $parsed['scheme'] . ' is not a supported scheme. (' . $url . ')');
                    continue;
                }
                $url = $this->glueURL($parsed);
                unset($parsed);

                // Convert url
                $tinyUrl = Phergie_Plugin_TinyUrl::get($url);

                // Prevent spamfest
                if ($this->checkUrlCache($url, $tinyUrl)) {
                    $this->debug('Invalid Url: URL is in the cache. (' . $url . ')');
                    continue;
                }

                $title = self::getTitle($url);
                if (!empty($title)) {
                    $responses[] = str_replace(array(
                        '%title%',
                        '%link%',
                        '%nick%'
                    ), array(
                        $title,
                        $tinyUrl,
                        $user
                    ), $this->messageFormat);
                }

                // Update cache
                $this->updateUrlCache($url, $tinyUrl);
                unset($title, $tinyUrl, $title);
            }
            /**
             * Check to see if there were any URL responses, format them and handle if they
             * get merged into one message or not
             */
            if (count($responses) > 0) {
                if ($this->mergeLinks) {
                    $this->doPrivmsg($source, str_replace(array(
                        '%message%',
                        '%nick%'
                    ), array(
                        implode('; ', $responses),
                        $user
                    ), $this->baseFormat));
                } else {
                    foreach($responses as $response) {
                        $this->doPrivmsg($source, str_replace(array(
                            '%message%',
                            '%nick%'
                        ), array(
                            $response,
                            $user
                        ), $this->baseFormat));
                    }
                }
            }
        }
    }

    /**
     * Checks a given URL and TinyURL against the cache to verify if they were
     * previously posted on the channel.
     *
     * @param string $url The URL to check against
     * @param string $tiny The TinyURL to check against
     * @return bool
     */
    protected function checkUrlCache($url, $tiny)
    {
        $source = $this->event->getSource();

        /**
         * Transform the URL and TinyURL into a HEX CRC32 checksum to prevent potential problems
         * and minimize the size of the cache for less cache bloat.
         */
        $url = $this->getUrlChecksum($url);
        $tiny = $this->getUrlChecksum($tiny);

        $cache = array(
            'url' => isset($this->urlCache[$source][$url]) ? $this->urlCache[$source][$url] : null,
            'tiny' => isset($this->tinyCache[$source][$tiny]) ? $this->tinyCache[$source][$tiny] : null
        );

        $expire = $this->expire;
        /**
         * If cache expiration is enabled, check to see if the given url has expired in the cache
         * If expire is disabled, simply check to see if the url is listed
         */
        if (($expire > 0 && (($cache['url'] + $expire) > time() || ($cache['tiny'] + $expire) > time())) ||
            ($expire <= 0 && (isset($cache['url']) || isset($cache['tiny'])))) {
            unset($cache, $url, $tiny, $expire);
            return true;
        }
        unset($cache, $url, $tiny, $expire);
        return false;
    }

    /**
     * Updates the cache and adds the given URL and TinyURL to the cache. It
     * also handles cleaning the cache of old entries as well.
     *
     * @param string $url The URL to add to the cache
     * @param string $tiny The TinyURL to add to the cache
     * @return bool
     */
    protected function updateUrlCache($url, $tiny)
    {
        $source = $this->event->getSource();

        /**
         * Transform the URL and TinyURL into a HEX CRC32 checksum to prevent potential problems
         * and minimize the size of the cache for less cache bloat.
         */
        $url = $this->getUrlChecksum($url);
        $tiny = $this->getUrlChecksum($tiny);
        $time = time();

        // Handle the URL cache and remove old entries that surpass the limit if enabled
        $this->urlCache[$source][$url] = $time;
        if ($this->limit > 0 && count($this->urlCache[$source]) > $this->limit) {
            asort($this->urlCache[$source], SORT_NUMERIC);
            array_shift($this->urlCache[$source]);
        }

        // Handle the TinyURL cache and remove old entries that surpass the limit if enabled
        $this->tinyCache[$source][$tiny] = $time;
        if ($this->limit > 0 && count($this->tinyCache[$source]) > $this->limit) {
            asort($this->tinyCache[$source], SORT_NUMERIC);
            array_shift($this->tinyCache[$source]);
        }
        unset($url, $tiny, $time);
    }

    /**
     * Transliterates a UTF-8 string into corresponding ASCII characters and
     * truncates and appends an ellipsis to the string if it exceeds a given
     * length.
     *
     * @param string $str String to decode
     * @param int $trim Maximum string length, optional
     * @return string
     */
    protected function decode($str, $trim = null)
    {
        $out = $this->decodeTranslit($str);
        if ($trim > 0) {
            $out = substr($out, 0, $trim) . (strlen($out) > $trim ? '...' : '');
        }
        return $out;
    }

    /**
     * Custom error handler meant to handle 404 errors and such
     */
    public function onPhpError($errno, $errstr, $errfile, $errline)
    {
        if ($errno === E_WARNING) {
            // Check to see if there was HTTP warning while connecting to the site
            if (preg_match('{HTTP/1\.[01] ([0-9]{3})}i', $errstr, $m)) {
                $this->errorStatus = $m[1];
                $this->errorMessage = (isset($this->httpErrors[$m[1]]) ? $this->httpErrors[$m[1]] : $m[1]);
                $this->debug('PHP Warning:  ' . $errstr . 'in ' . $errfile . ' on line ' . $errline);
                return true;
            // Safely ignore these SSL warnings so they don't appear in the log
            } else if (stripos($errstr, 'SSL: fatal protocol error in') !== false ||
                       stripos($errstr, 'failed to open stream') !== false ||
                       stripos($errstr, 'HTTP request failed') !== false ||
                       stripos($errstr, 'SSL: An existing connection was forcibly closed by the remote host') !== false ||
                       stripos($errstr, 'Failed to enable crypto in') !== false ||
                       stripos($errstr, 'SSL: An established connection was aborted by the software in your host machine') !== false ||
                       stripos($errstr, 'SSL operation failed with code') !== false ||
                       stripos($errstr, 'unable to connect to') !== false) {
                $this->errorStatus = true;
                $this->debug('PHP Warning:  ' . $errstr . 'in ' . $errfile . ' on line ' . $errline);
                return true;
            }
        }
        return false;
    }

    /**
     * Takes a url, parses and cleans the URL without of all the junk
     * and then return the hex checksum of the url.
     */
    protected function getUrlChecksum($url)
    {
        $checksum = strtolower(urldecode($this->glueUrl($url, true)));
        $checksum = preg_replace('#\s#', '', $this->decodeTranslit($checksum));
        return dechex(crc32($checksum));
    }

    /*
    * Parses a given URI and procceses the output to remove redundant
    * or missing values.
    */
    protected function parseUrl($url)
    {
        if (is_array($url)) return $url;

        $url = trim(ltrim($url, ' /@\\'));
        if (!preg_match('&^(?:([a-z][-+.a-z0-9]*):)&xis', $url, $matches)) {
            $url = 'http://' . $url;
        }
        $parsed = parse_url($url);

        if (!isset($parsed['scheme'])) {
            $parsed['scheme'] = 'http';
        }
        $parsed['scheme'] = strtolower($parsed['scheme']);

        if (isset($parsed['path']) && !isset($parsed['host'])) {
            $host = $parsed['path'];
            $path = '';
            if (strpos($parsed['path'], '/') !== false) {
                list($host, $path) = array_pad(explode('/', $parsed['path'], 2), 2, null);
            }
            $parsed['host'] = $host;
            $parsed['path'] = $path;
        }

        return $parsed;
    }

    /*
    * Parses a given URI and then glues it back together in the proper format.
    * If base is set, then it chops off the scheme, user and pass and fragment
    * information to return a more unique base URI.
    */
    protected function glueUrl($uri, $base = false)
    {
        $parsed = $uri;
        if (!is_array($parsed)) {
            $parsed = $this->parseUrl($parsed);
        }

        if (is_array($parsed)) {
            $uri = '';
            if (!$base) {
                $uri .= (!empty($parsed['scheme']) ? $parsed['scheme'] . ':' .
                        ((strtolower($parsed['scheme']) == 'mailto') ? '' : '//') : '');
                $uri .= (!empty($parsed['user']) ? $parsed['user'] .
                        (!empty($parsed['pass']) ? ':' . $parsed['pass'] : '') . '@' : '');
            }
            if ($base && !empty($parsed['host'])) {
                $parsed['host'] = trim($parsed['host']);
                if (substr($parsed['host'], 0, 4) == 'www.') {
                    $parsed['host'] = substr($parsed['host'], 4);
                }
            }
            $uri .= (!empty($parsed['host']) ? $parsed['host'] : '');
            if (!empty($parsed['port']) &&
                (($parsed['scheme'] == 'http' && $parsed['port'] == 80) ||
                ($parsed['scheme'] == 'https' && $parsed['port'] == 443))) {
                unset($parsed['port']);
            }
            $uri .= (!empty($parsed['port']) ? ':' . $parsed['port'] : '');
            if(!empty($parsed['path']) && (!$base || $base && $parsed['path'] != '/'))
            {
                $uri .= (substr($parsed['path'], 0, 1) == '/') ? $parsed['path'] : ('/' . $parsed['path']);
            }
            $uri .= (!empty($parsed['query']) ? '?' . $parsed['query'] : '');
            if (!$base) {
                $uri .= (!empty($parsed['fragment']) ? '#' . $parsed['fragment'] : '');
            }
        }
        return $uri;
    }

    /*
    * Checks the given string to see if its a valid IP4 address
    */
    protected function checkValidIP($ip) {
        return long2ip(ip2long($ip)) === $ip;
    }

    /*
     * Updates the given variable with the setting
     *
     * @return void
     */
    protected function updateSetting($setting, $var, $integer = false)
    {
        $temp = $this->getPluginIni($setting);
        if (($integer && intval($temp) > 0) || (!$integer && isset($temp))) {
             $this->{$var} = $temp;
        }
        unset($temp);
    }

    /**
 	 * Returns the title of the given page
 	 *
 	 * @param string $url url to the page
 	 * @return string title
 	 */
    public function getTitle($url)
    {
		$opts = array(
			'http' => array(
				'timeout' => 3.5,
				'method' => 'GET',
				'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.12) Gecko/20080201 Firefox/2.0.0.12'
			)
		);
		$context = stream_context_create($opts);

		if ($page = fopen($url, 'r', false, $context)) {
			stream_set_timeout($page, 3.5);
			$data = stream_get_meta_data($page);
			foreach($data['wrapper_data'] as $header) {
				if (preg_match('/^Content-Type: ([^;]+)/', $header, $match) &&
					!preg_match('#^(text/x?html|application/xhtml+xml)$#', $match[1])) {
					$title = $match[1];
				}
			}
			if (!isset($title)) {
				$content = '';
				$tstamp = time() + 5;

				while ($chunk = fread($page, 64)) {
					$data = stream_get_meta_data($page);
					if ($data['timed_out']) {
						$this->debug('Url Timed Out: ' . $url);
						$this->errorStatus = true;
						break;
					}
					$content .= $chunk;
					// Check for timeout
					if (time() > $tstamp) break;
					// Try to read title
					if (preg_match('#<title[^>]*>(.*)#is', $content, $m)) {
						// Start another loop to grab some more data in order to be sure we have the complete title
						$content = $m[1];
						$loop = 2;
						while (($chunk = fread($page, 64)) && $loop-- && !strstr($content, '<')) {
							$content .= $chunk;
							// Check for timeout
							if (time() > $tstamp) break;
						}
						preg_match('#^([^<]*)#is', $content, $m);
						$title = preg_replace('#\s+#', ' ', $m[1]);
						$title = trim($this->decode($title, $this->titleLength));
						break;
					}
					// Title won't appear beyond that point so stop parsing
					if (preg_match('#</head>|<body#i', $content)) {
						break;
					}
				}
			}
			fclose($page);
		} else if (!$this->errorStatus) {
			$this->debug('Couldn\t Open Url: ' . $url);
		}

		if (empty($title)) {
			if ($this->errorStatus) {
				if (!$this->showErrors || empty($this->errorMessage)) {
					return;
				}
				$title = $this->errorMessage;
				$this->errorStatus = false;
				$this->errorMessage = null;
			} else {
				$title = 'No Title';
			}
		}

		return $title;
    }
}
