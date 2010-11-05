<?php

$term = 'test';
preg_match(
            '@<div id=resultStats>About (.+) results<nobr>@i',
            file_get_contents('http://www.google.ca/search?q=' . urlencode($term)),
            $m
        );
var_dump($m);

