<?php

declare(strict_types=1);

require_once(__DIR__ . '/../autoload.php');

if (isset($_GET['file'])) {
    CdEncoder\Application::audio($_GET['file']);
    exit;
}

header("HTTP/1.1 404 Not Found");