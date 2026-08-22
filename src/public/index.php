<?php

declare(strict_types=1);

require_once(__DIR__ . '/../vendor/autoload.php');

use CdEncoder\UI\Http\Controller\Index;
use Symfony\Component\HttpFoundation\Request;

$response = (new Index())->index(Request::createFromGlobals());
$response->send();
