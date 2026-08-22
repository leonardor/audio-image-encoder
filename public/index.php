<?php

declare(strict_types=1);

require_once(__DIR__ . '/../vendor/autoload.php');

use CdEncoder\Application\Services\{
    DVDEncoder,
    CdEncoder
};
use CdEncoder\UI\Http\Controller\Index;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\HttpFoundation\Request;

$logger = new Logger('cd-encoder');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../../logs/cd-encoder.log'));

$encoder = new DVDEncoder($logger);

$response = (new Index($logger, $encoder))->index(Request::createFromGlobals());
$response->send();
