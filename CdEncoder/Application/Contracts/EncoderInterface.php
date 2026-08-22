<?php

declare(strict_types=1);

namespace CdEncoder\Application\Contracts;

interface EncoderInterface
{
    /** @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>} */
    public function getMetadata(): array;

    public function prepare(string $audioPath, string $imagePath, string $profile = 'standard'): void;

    public function encode(): bool;

    public function decode(): bool;

    public function shouldTranscode(string $audioPath): bool;
}