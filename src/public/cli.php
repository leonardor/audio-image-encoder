<?php

declare(strict_types=1);

require_once(__DIR__ . '/../autoload.php');

use CdEncoder\CdEncoder;

// ============================================================
// CLI
// ============================================================

if ($argc < 2) {
    echo <<<TXT
MP3 DISC - LOSSLESS WEBP
Encode:
    php cli.php encode song.mp3 song.webp
Decode:
    php cli.php decode song.webp recovered.mp3
TXT;

    exit(1);
}

try {
    $command = $argv[1];

    if ($command === 'encode') {
        if ($argc < 4) {
            throw new RuntimeException("Usage: php cli.php encode input.mp3 output.webp");
        }

        $mp3 = __DIR__ . '/audio/' . $argv[2];
        $output = $argv[3];

        $cdEncoder = new CdEncoder($mp3, $output);

        $cdEncoder->encode();
    } elseif ($command === 'decode') {
        if ($argc < 4) {
            throw new RuntimeException("Usage: php cli.php decode input.webp recovered.mp3");
        }

        $image = __DIR__ . '/images/' . $argv[2];
        $output = $argv[3];

        $cdEncoder = new CdEncoder($output, $image);

        $cdEncoder->decode();
    } else {
        throw new RuntimeException("Comandă necunoscută.");
    }
} catch (Throwable $e) {
    fwrite(STDERR, "\nERROR: " . $e->getMessage() . "\n");

    exit(1);
}
