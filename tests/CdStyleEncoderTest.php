<?php

declare(strict_types=1);

namespace AudioImageEncoder\Tests;

use AudioImageEncoder\Application\Services\CdStyleEncoder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class CdStyleEncoderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'cd-encoder-test-' . bin2hex(random_bytes(8));
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

    public function testItEncodesAndDecodesAnOddLengthPayloadWithoutChangingItsHash(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'input.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'output.webp';
        $decodedAudioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'decoded.mp3';
        $audioData = '';

        for ($byte = 0; $byte < 257; $byte++) {
            $audioData .= chr($byte % 256);
        }

        file_put_contents($audioPath, $audioData);

        $encoder = new CdStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);
        $decoder = new CdStyleEncoder(new NullLogger());
        $decoder->prepare($decodedAudioPath, $imagePath);

        $this->assertTrue($encoder->encode());
        $this->assertFileExists($imagePath);

        $image = imagecreatefromwebp($imagePath);
        $this->assertNotFalse($image);
        $imageSize = imagesx($image);
        $center = (int)round($imageSize / 2);
        $borderRadius = (int)round(58 * $imageSize / 120);
        $diagonalX = (int)round($center + $borderRadius * cos(M_PI / 4));
        $diagonalY = (int)round($center + $borderRadius * sin(M_PI / 4));
        $borderPixelFound = false;

        for ($offsetX = -2; $offsetX <= 2; $offsetX++) {
            for ($offsetY = -2; $offsetY <= 2; $offsetY++) {
                if (imagecolorat($image, $diagonalX + $offsetX, $diagonalY + $offsetY) === 0x808080) {
                    $borderPixelFound = true;
                }
            }
        }

        $this->assertTrue($borderPixelFound);
        $markerRadius = (int)round(0.5 / 2 * $imageSize / 120);
        $markerInset = 32 + $markerRadius;
        foreach (
            [
                [$markerInset, $markerInset],
                [$imageSize - $markerInset, $markerInset],
                [$markerInset, $imageSize - $markerInset],
                [$imageSize - $markerInset, $imageSize - $markerInset],
            ] as [$markerX, $markerY]
        ) {
            $this->assertSame(0x0A0A0A, imagecolorat($image, $markerX, $markerY));
        }
        imagedestroy($image);

        $this->assertTrue($decoder->decode());
        $this->assertFileExists($decodedAudioPath);
        $this->assertSame(hash_file('sha256', $audioPath), hash_file('sha256', $decodedAudioPath));
    }

    public function testItReturnsEmptyMetadataForAnAudioFileWithoutTags(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'untagged.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'untagged.webp';

        file_put_contents($audioPath, random_bytes(64));

        $encoder = new CdStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);
        $decoder = new CdStyleEncoder(new NullLogger());
        $decoder->prepare($this->temporaryDirectory . DIRECTORY_SEPARATOR . 'decoded-untagged.mp3', $imagePath);

        $this->assertTrue($encoder->encode());
        $this->assertTrue($decoder->decode());
        $this->assertSame([
            'title' => '',
            'artist' => '',
            'album' => '',
            'year' => '',
            'technical' => [
                'bitrate_kbps' => null,
                'sample_rate_hz' => null,
                'channels' => null,
                'codec' => null,
                'duration_seconds' => null,
                'file_size_bytes' => 64,
            ],
            'encoding' => [
                'format_version' => 1,
                'default_dpi' => 600,
                'disc_diameter_mm' => 120,
                'center_x_mm' => 60,
                'center_y_mm' => 60,
                'hole_diameter_mm' => 8,
                'marker_diameter_mm' => 0.5,
                'data_radius_start_mm' => 9,
                'data_radius_start_header_mm' => 8.5,
                'data_radius_start_marker_mm' => 58,
                'data_radius_end_mm' => 100,
                'spiral_pitch_mm' => 0.06,
                'angle_step' => 0.007,
                'payload_bytes_per_pixel' => 3,
                'metadata_field_length' => 128,
                'profile' => 'standard',
            ],
        ], $decoder->getMetadata());
    }

}
