<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CdEncoder\UI\Cli\Commands\Cli;
use Symfony\Component\Console\Application;

$application = new Application('CD Encoder');
$application->addCommand(new Cli());
$application->setDefaultCommand('cd-encoder');
$application->run();
