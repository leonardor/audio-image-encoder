<?php

declare(strict_types=1);

require_once(__DIR__ . '/../autoload.php');

use CdEncoder\UI\Http\Controller\Index;
use Symfony\Component\HttpFoundation\Request;

$request = Request::createFromGlobals();
$response = (new Index())->image($request);
$response->send();
