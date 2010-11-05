<?php

/**
 */
class Phergie_Plugin_Trac extends Phergie_Plugin_Abstract_Command
{
	public static function checkDependencies(Phergie_Driver_Abstract $client, array $plugins)
	{
		$errors = array();
		
		if (!extension_loaded('SimpleXML')) {
			$errors[] = 'SimpleXML php extension is required';
		}
		return empty($errors) ? true : $errors;
	}
	
	/**
	 * @return void
	 */
	public function onDoStats($days_ago = 1)
	{
		$stats = self::getTracStats($days_ago, $this->getIni('trac.url'), $this->getIni('trac.name'));
		if ($stats) {
			$this->doPrivmsg($this->event->getSource(), $stats);
		}
	}

    /**
     * @return void
     */
    public function onDoStatsExtras($days_ago = 1)
    {
        $stats = self::getTracStats($days_ago, 'https://trac.habariproject.org/habari-extras', 'Habari Extras');
        if ($stats) {
            $this->doPrivmsg($this->event->getSource(), $stats);
        }
    }

	
	public function onPrivmsg()
	{
		if ( $this->event->getSource() !== '#habari' ) {
			return;
		}
		$message = $this->event->getArgument(1);
		
		if ( preg_match("@^#(\d+)\b@", $message, $m) ) {
			$this->onDoIssue($m[1]);
		}
		elseif ( preg_match("@^r(\d+)\b@", $message, $m) ) {
			$this->onDoChangeset($m[1]);
		}
		elseif ( preg_match("@^rex(\d+)\b@", $message, $m) ) {
                        $this->onDoExtraChangeset($m[1]);
                }
		$this->processCommand($this->event->getArgument(1));
		unset( $message, $m );
	}
	
	public function onDoBlame($file, $line)
	{
		try {
			$file = escapeshellcmd($file);
			$blame = shell_exec("svn blame http://svn.habariproject.org/habari/trunk/htdocs/{$file}");
			$lines = split("\n", $blame);
			if ( $line < 0 || $line > (count($lines)+1) ) {
				$this->doPrivmsg($this->event->getSource(), "No line number {$line} in {$file}");
			}
			else {
				$this->doPrivmsg(
					$this->event->getSource(),
					sprintf("%s line %d: r%s", $file, $line, trim($lines[$line-1]))
				);
			}
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "No file {$file}");
		}
	}
	
	public function onDoTicket($ticket)
	{
		$this->onDoIssue($ticket);
	}
	
	public function onDoIssue($ticket)
	{
		try {
			$rss = simplexml_load_string(
				self::getURL($this->getIni('trac.url')."/ticket/{$ticket}?format=rss")
			);
			$this->doPrivmsg($this->event->getSource(), sprintf( '%s -- %s', (string) $rss->channel->title, (string) $rss->channel->link ));
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Sorry, could not find ticket {$ticket}.");
		}
	}
	
	public function onDoRev($rev)
	{
		$this->onDoChangeset($rev);
	}
	
	public function onDoR($rev)
	{
		$this->onDoChangeset($rev);
	}
	
	public function onDoChangeset($rev)
	{
		try {
			$url = $this->getIni('trac.url')."/changeset/{$rev}";
			$html = self::getURL($url);
			$line = split("\n", $html);
			preg_match("@<dd class=\"message searchable\">\s*<p>(.+)</p>@Us", $html, $description);
			
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf( 'Changeset %s: %s ... %s', $rev, substr(trim(strip_tags($description[1])), 0, 100), $url)
			);
		}
		catch (Exception $e) {
			$this->doPrivmsg($this->event->getSource(), "Isuck".strlen($html)."Sorry, could not find changeset {$rev}.");
		}
	}

        public function onDoExtraChangeset($rev)
        {
                try {
                        $url = "https://trac.habariproject.org/habari-extras/changeset/{$rev}";
                        $html = self::getURL($url);
                        $line = split("\n", $html);
                        preg_match("@<dd class=\"message searchable\">\s*<p>(.+)</p>@Us", $html, $description);

                        $this->doPrivmsg(
                                $this->event->getSource(),
                                sprintf( 'Changeset %s: %s ... %s', $rev, substr(trim(strip_tags($description[1])), 0, 100), $url)
                        );
                }
                catch (Exception $e) {
                        $this->doPrivmsg($this->event->getSource(), "Isuck".strlen($html)."Sorry, could not find changeset {$rev}.");
                }
        }

	/**
	 * Reads last commit/ticket logs
	 */
	public static function getTracStats($days_ago, $url, $name)
	{
		switch ( $days_ago ) {
			case 'today':
			case 'day':
			case 1:
				$days_ago = 1;
				$verb = 'Today';
				break;
			case 'week':
				$days_ago = date('N');
				$verb = 'This week';
				break;
			case 'month':
				$days_ago = date('j');
				$verb = 'This month';
				break;
			case 'year':
				$days_ago = date('z');
				$verb = 'This year';
				break;
			case $days_ago > 0:
				$verb = sprintf('In the past %d days', $days_ago);
				break;
			default:
				return "I'm sorry, what the hell is a '{$days_ago}'?";
		}
		try {
			$logs = simplexml_load_string(
				self::getURL("{$url}/timeline?changeset=on&ticket=on&max=5000&daysback={$days_ago}&format=rss")
			);
		}
		catch (Exception $e) {
			return "Sorry, could not get stats.";
		}
		
		$commits = 0;
		$new = 0;
		$closed = 0;
		foreach ( $logs->channel->item as $item ) {
			switch ( (string) $item->category ) {
				case 'changeset':
					$commits++;
					break;
				case 'newticket':
					$new++;
					break;
				case 'closedticket':
					$closed++;
					break;
			}
		}
		
		$r = sprintf( '%s, %s has had %d commits, %d new tickets and %d closed tickets', $verb, $name, $commits, $new, $closed );
		unset($verb, $commits, $new, $closed, $logs);
		return $r;
	}
	
	public static function getURL($url)
	{
		$opts = array(
			'http' => array(
				'timeout' => 3.5,
				'method' => 'GET',
				'user_agent' => 'Mozilla/5.0 (Windows; U; Windows NT 5.1; en-US; rv:1.8.1.12) Gecko/20080201 Firefox/2.0.0.12'
			)
		);
		$context = stream_context_create($opts);
		if ( $r = file_get_contents($url, false, $context) ) {
			unset( $context );
			return $r;
		}
		else {
			throw new Exception('Could not fetch '.$url);
		}
	}
}

if ( !class_exists('Process') ) {
class Process
{
	public static function open ( $command )
	{
		$retval = '';
		$error = '';

		$descriptorspec = array(
			0 => array('pipe', 'r'),
			1 => array('pipe', 'w'),
			2 => array('pipe', 'r')
		);

		$resource = proc_open($command, $descriptorspec, $pipes, null, $_ENV);
		if (is_resource($resource)) {
			$stdin = $pipes[0];
			$stdout = $pipes[1];
			$stderr = $pipes[2];

			while (! feof($stdout)) {
				$retval .= fgets($stdout);
			}

			while (! feof($stderr)) {
				$error .= fgets($stderr);
			}

			fclose($stdin);
			fclose($stdout);
			fclose($stderr);

			$exit_code = proc_close($resource);
		}

		if (! empty($error)) {
			throw new Exception($error);
		}
		else {
			return $retval;
		}
	}
}
}
