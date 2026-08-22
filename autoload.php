<?php

declare(strict_types=1);

$composerAutoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}
