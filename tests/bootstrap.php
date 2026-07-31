<?php

$autoload = dirname(__DIR__).'/vendor/autoload.php';

if (! file_exists($autoload)) {
    $autoload = dirname(__DIR__, 3).'/vendor/autoload.php';
}

require_once $autoload;
