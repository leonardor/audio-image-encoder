<?php

declare(strict_types=1);

namespace AudioImageEncoder\Application\Exceptions;

use RuntimeException;

final class CorruptionException extends RuntimeException
{
    public static function invalidHeader(string $reason): self
    {
        return new self("Invalid or corrupted Blu-ray format header: $reason");
    }

    public static function invalidStructuralData(string $reason): self
    {
        return new self("Invalid or corrupted structural data: $reason");
    }

    public static function invalidFileMetadata(string $reason): self
    {
        return new self("Invalid or corrupted file metadata: $reason");
    }

    public static function audioIntegrityFailed(string $expected, string $actual): self
    {
        return new self("Audio integrity check failed. Expected SHA256: $expected, got: $actual");
    }

    public static function noRecoverableCornerCopy(): self
    {
        return new self('No recoverable corner copy found in any of the four corners.');
    }

    public static function allCornersCorrupted(): self
    {
        return new self('All four corner copies are corrupted or unreadable.');
    }

    public static function rotationDetectionFailed(): self
    {
        return new self('Unable to detect or validate image rotation angle.');
    }
}
