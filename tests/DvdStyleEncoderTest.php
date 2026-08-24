<?php

declare(strict_types=1);

namespace AudioImageEncoder\Tests;

use AudioImageEncoder\Application\Services\DvdStyleEncoder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class DvdStyleEncoderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dvd-encoder-test-' . bin2hex(random_bytes(8));
        mkdir($this->temporaryDirectory, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [];

        foreach ($files as $file) {
            unlink($file);
        }

        rmdir($this->temporaryDirectory);

        parent::tearDown();
    }

    public function testItRoundTripsAnOddLengthPayloadAndPersistsConfiguration(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'input.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'output.webp';
        $decodedAudioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'decoded.mp3';
        $audioData = '';

        for ($byte = 0; $byte < 257; $byte++) {
            $audioData .= chr(($byte * 17) % 256);
        }

        file_put_contents($audioPath, $audioData);

        $encoder = new DvdStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);

        $this->assertTrue($encoder->encode());
        $imageSize = getimagesize($imagePath);
        $this->assertNotFalse($imageSize);
        $this->assertSame($imageSize[0], $imageSize[1]);
        $this->assertSame(2835, $imageSize[0]);

        $image = imagecreatefromwebp($imagePath);
        $this->assertNotFalse($image);
        $center = (int)round($imageSize[0] / 2);
        $pixelsPerMillimeter = $imageSize[0] / 120;
        $markerOffset = (int)round(58 * $pixelsPerMillimeter);
        $markerRadius = (int)ceil(round(0.5 * $pixelsPerMillimeter) / 2);
        $borderRadius = $markerOffset - $markerRadius - 3 + 3;
        $this->assertSame(0x000000, imagecolorat($image, $center, $center - $borderRadius));
        imagedestroy($image);

        $decoder = new DvdStyleEncoder(new NullLogger());
        $decoder->prepare($decodedAudioPath, $imagePath);

        $this->assertTrue($decoder->decode());
        $this->assertSame(hash_file('sha256', $audioPath), hash_file('sha256', $decodedAudioPath));
        $this->assertSame('standard', $decoder->getMetadata()['encoding']['profile']);
        $this->assertSame(600, $decoder->getMetadata()['encoding']['dpi']);
    }
}
