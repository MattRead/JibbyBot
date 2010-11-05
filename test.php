<?php
 $term = 'ottawa to toronto';
preg_match(
            '@<b>\d+&#160;(mi|km)</b> &#8211; about <b>([^<]+)</b>@i',
            file_get_contents("http://maps.google.com/?output=html&q=".urlencode($term)),
            $m
        );
var_dump($m);
