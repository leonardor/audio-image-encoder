<?php

declare(strict_types=1);

require_once(__DIR__ . '/../autoload.php');

if (isset($_GET['file'])) {
    CdEncoder\Application::image($_GET['file']);
    exit;
}

// stream.php - Serviciu securizat de streaming audio pentru playere HTML5
header("HTTP/1.1 404 Not Found");