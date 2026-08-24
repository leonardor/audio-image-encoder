<?php

declare(strict_types=1);

$composerAutoloader = __DIR__ . '/vendor/autoload.php';

if (file_exists($composerAutoloader)) {
    require_once $composerAutoloader;
}

foreach ([
    'Application\\Contracts\\EncoderInterface',
    'Application\\Exceptions\\CorruptionException',
    'Application\\Services\\BluRayStyleEncoder',
    'Application\\Services\\CdStyleEncoder',
    'Application\\Services\\DvdStyleEncoder',
    'Application\\Services\\Transcoder',
    'UI\\Cli\\Commands\\CliCommand',
    'UI\\Http\\Controller\\Index',
] as $class) {
    $newClass = 'AudioImageEncoder\\' . $class;
    $legacyClass = match ($class) {
        'Application\\Services\\BluRayStyleEncoder' => 'CdEncoder\\Application\\Services\\BlurayEncoder',
        'Application\\Services\\CdStyleEncoder' => 'CdEncoder\\Application\\Services\\CdEncoder',
        'Application\\Services\\DvdStyleEncoder' => 'CdEncoder\\Application\\Services\\DVDEncoder',
        default => 'CdEncoder\\' . $class,
    };

    if (class_exists($newClass) || interface_exists($newClass)) {
        class_alias($newClass, $legacyClass);
    }
}
