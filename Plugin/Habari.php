<?php
/**
 */
class Phergie_Plugin_Habari extends Phergie_Plugin_Abstract_Command
{
	public function onDoWa($question)
	{
		$url = sprintf('http://tumbolia.appspot.com/wa/%s', urlencode($question));
		$this->doPrivmsg($this->event->getSource(), file_get_contents($url));
	}
	
	public function onDoTwss()
	{
		$this->doPrivmsg($this->event->getSource(), 'That\'s what she said!');
	}

	public function onDoT61($nick)
	{
		$dat = file_get_contents('http://www.thesixtyone.com/'. $nick .'/');
		preg_match("#<b>now playing</b><br/>\s*<div style=\"height:15px;overflow:hidden;\">(.+)</div>\s*<div style=\"height:15px;overflow:hidden;\">(.+)</div>#i",
			$dat, $a);
		if ( isset($a[1]) ) {
			preg_match("#<a href=\"([^\"]+)\"#i", $a[1], $b);
			$this->doPrivmsg($this->event->getSource(), strip_tags(html_entity_decode($a[1])). ' by '. strip_tags(html_entity_decode($a[2])). ' -- http://www.thesixtyone.com'. $b[1]);
		}
		else {
			$this->doPrivmsg($this->event->getSource(), 'not on');
		}
	}

    public function onDoYou($suck)
    {
        if (!preg_match('@.*(go to|suck|fuck|shit|dick).*@', $suck)) {
            return;
        }
        $source = $this->event->getSource();
        $nick = $this->event->getNick();
        $msg = "{$nick}, screw you";
        $this->doPrivmsg($source, $msg);
    }
    
    public function onDoBofh()
    {
    	$data = file_get_contents('http://pages.cs.wisc.edu/~ballard/bofh/bofhserver.pl');
    	
    	if ( preg_match("@<br><font size = \"\+2\">([^<]+)\n@", $data, $m) ) {
    		$this->doPrivmsg(
    			$this->event->getSource(),
    			"The cause of the problem is: {$m[1]}"
    		);
    	}
    }
    
    public function onDoTranslation($lang) {
        $source = $this->event->getSource();
        $nick = $this->event->getNick();
        $dat = file_get_contents('https://translations.launchpad.net/habari/trunk/+pots/habari/' . urlencode($lang) . '/+index');
        preg_match('!To do:</b>.*?(\d+)!s', $dat, $m);
        $todo = $m[1];
        if ($todo === false) {
            $msg = "{$nick}, Could not find language '{$lang}'";
        } else {
            $msg = "{$nick}, {$lang} still needs {$todo} strings translated. Go help https://translations.launchpad.net/habari/trunk/+pots/habari/{$lang}/+translate";
        }
        $this->doPrivmsg($source, $msg);
    }
    
    protected function wikiSearch($search) {
        $source = $this->event->getSource();
        $nick = $this->event->getNick();
		$dat = file_get_contents('http://wiki.habariproject.org/w/index.php?title=Special:Search&fulltext=Search&search=' . urlencode($search));
        preg_match('/<li><a href="(.*?)"/', $dat, $m);
        $link = $m[1];
        $msg =  "{$nick} wiki search for '{$search}': http://wiki.habariproject.org{$link}";
        $this->doPrivmsg($source, $msg);
    }
    
    public function onDoCs($term)
    {
    	$term = urlencode($term);
    	$result = file_get_contents("http://doc.habariproject.org/api//search.php?query={$term}");
    	if ( preg_match('@<a class="el" href="([^"]+)">@i', $result, $matches) ) {
			$this->doPrivmsg(
				$this->event->getSource(),
				sprintf(
					'%s: http://doc.habariproject.org/api/%s ... http://doc.habariproject.org/api/search.php?query=%s',
					$term,
					$matches[1],
					$term
				)
			);
		}
		else {
			$this->doPrivmsg( $this->event->getSource(), 'oops' );
		}
	}

    public function onDoCsExtras($term)
    {
        $term = urlencode($term);
        $result = file_get_contents("http://drunkenmonkey.org/habari/api/extras/html/search.php?query={$term}");
        if ( preg_match('@<a class="el" href="([^"]+)">@i', $result, $matches) ) {
            $this->doPrivmsg(
                $this->event->getSource(),
                sprintf(
                    '%s: http://drunkenmonkey.org/habari/api/extras/html/%s ... http://drunkenmonkey.org/habari/api/extras/html/search.php?query=%s',
                    $term,
                    $matches[1],
                    $term
                )
            );
        }
        else {
            $this->doPrivmsg( $this->event->getSource(), 'oops' );
        }
    }

    
    public function onDoWiki($search) { $this->wikiSearch($search); }
    public function onDoHwiki($search) { $this->wikiSearch($search); }
    public function onDoHw($search) { $this->wikiSearch($search); }
    public function onDoHpwiki($search) { $this->wikiSearch($search); }

    public function onDoGuid() {

	// this was all swiped from Habari's UUID class

	$guid = array();
	for ( $i = 0; $i < 16; $i++ ) {
		$guid[] = mt_rand( 0, 255 );
	}

	$guid[8] = ( $guid[8] & 0x3f ) | 0x80;

	$guid[6] = ( $guid[6] & 0x0f ) | 0x40;

	// convert to hex
	$hex = '';

	for ( $i = 0; $i < 16; $i++ ) {
		if ( $i == 4 || $i == 6 || $i == 8 || $i == 10 ) {
			$hex .= '-';
		}
		$hex .= sprintf( '%02x', $guid[$i] );
	}

	$this->doPrivmsg( $this->event->getSource(), $this->event->getNick() . ': ' . $hex );

    }

    public function onDoUuid() {
	$this->onDoGuid();
    }

	public function onDoRev()
	{
		preg_match('/Revision: (\d+)/i', shell_exec('svn info http://svn.habariproject.org/habari/'), $m);
		$this->doPrivmsg( $this->event->getSource(), $this->event->getNick() . ': Current Habari Revision: ' . $m[1] );
	}
}
