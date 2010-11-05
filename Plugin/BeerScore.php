<?php
// komode: le=unix language=php codepage=utf8 tab=4 notabs indent=4
class Phergie_Plugin_BeerScore extends Phergie_Plugin_Abstract_Command
{
    const TYPE_SCORE = 'SCORE';
    const TYPE_SEARCH = 'SEARCH';
    const TYPE_REFINE = 'REFINE';
    
    const API_BASE_URL = 'http://caedmon.net/beerscore/';

    public function onDoBeerscore($searchstring)
    {
        $target = $this->event->getNick();
        $source = $this->event->getSource();
        
        $apiurl = self::API_BASE_URL . rawurlencode($searchstring) .'?shortenurls=0';
        $result = json_decode(file_get_contents($apiurl));
        
        if (!$result || !isset($result->type) || !is_array($result->beer)) {
            $this->doNotice($target, 'Score not found (or failed to contact API)');
            return;
        }
        
        switch ($result->type) {
            case self::TYPE_SCORE:
                // small enough number to get scores
                foreach ($result->beer as $beer) {
                    $url = Phergie_Plugin_TinyUrl::get($beer->url);
                    if ($beer->score === -1) {
                        $score = '(not rated)';
                    } else {
                        $score = $beer->score;
                    }
                    $str = "{$target}: rating for {$beer->name} = {$score} ({$url})";
                    $this->doPrivmsg($source, $str);
                }
                break;

            case self::TYPE_SEARCH:
                // only beer names, no scores
                $str = '';
                $found = 0;
                foreach ($result->beer as $beer) {
                    $url = Phergie_Plugin_TinyUrl::get($beer->url);
                    if (isset($beer->score)) {
                        ++$found;
                        if ($beer->score === -1) {
                            $score = '(not rated)';
                        } else {
                            $score = $beer->score;
                        }
                        $this->doPrivmsg($source, "{$target}: rating for {$beer->name} = {$score} ({$url})");
                    } else {
                        $str .= "({$beer->name} -> {$url}) ";
                    }
                }
                $foundnum = $result->num - $found;
                $more = $found ? 'more ' : '';
                $this->doPrivmsg($source, "{$target}: {$foundnum} {$more}results... {$str}");
                break;

            case self::TYPE_REFINE:
                // Too many results; only output search URL
                if ($result->num < 100) {
                    $num = $result->num;
                } else {
                    $num = 'at least 100';
                }
                $url = Phergie_Plugin_TinyUrl::get($result->searchurl);
                $resultsword = (($num > 1) ? 'results' : 'result');
                $this->doPrivmsg($source, "{$target}: {$num} {$resultsword}; {$url}");
                break;
        }
    }
}
