<?php

declare(strict_types=1);

require_once(__DIR__ . '/../autoload.php');

use CdEncoder\UI\Http\Controller\Index;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;

$logger = new Logger('cd-encoder');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/cd-encoder.log'));

$request = Request::createFromGlobals();
$response = (new Index($logger))->audio($request);
$response->send();