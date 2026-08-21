<?php

declare(strict_types=1);

$composerAutoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

spl_autoload_register(function ($className) {
    $path = __DIR__ . '/' . str_replace('\\', '/', $className) . '.php';
    
    if (file_exists($path)) {
        require_once($path);
    }
});