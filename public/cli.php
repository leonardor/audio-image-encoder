<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use AudioImageEncoder\UI\Cli\Commands\AudioImageEncoderCommand;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Symfony\Component\Console\Application;

$application = new Application('Audio Image Encoder');
$logger = new Logger('audio-image-encoder');
$logger->pushHandler(new StreamHandler(__DIR__ . '/../logs/audio-image-encoder.log'));

$application->addCommand(new AudioImageEncoderCommand($logger));
$application->setDefaultCommand('audio-image-encoder');
$application->run();
