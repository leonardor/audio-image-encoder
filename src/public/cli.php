<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use CdEncoder\UI\Cli\Commands\CliCommand;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;

$application = new Application('CD Encoder');
$logger = new Logger('cd-encoder');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/cd-encoder.log'));

$application->addCommand(new CliCommand($logger));
$application->setDefaultCommand('cd-encoder');
$application->run();
