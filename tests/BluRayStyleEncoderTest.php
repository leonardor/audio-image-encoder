<?php

declare(strict_types=1);

namespace AudioImageEncoder\Tests;

use AudioImageEncoder\Application\Services\BluRayStyleEncoder;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class BluRayStyleEncoderTest extends TestCase
{
    private string $temporaryDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bluray-encoder-test-' . bin2hex(random_bytes(8));
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

    public function testItEncodesAndDecodesTheRingWithTheCorrectBackgroundColors(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'input.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'output.webp';
        $decodedAudioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'decoded.mp3';
        $audioData = str_repeat("\x00\x01\x02\x03\x04\x05\x06\x07", 32768);

        file_put_contents($audioPath, $audioData);

        $encoder = new BluRayStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);
        $this->assertTrue($encoder->encode());

        $image = imagecreatefromwebp($imagePath);
        $this->assertNotFalse($image);
        $this->assertSame(2835, imagesx($image));
        $this->assertSame(2835, imagesy($image));
        $this->assertSame(0xFFFFFF, imagecolorat($image, 0, 0));
        $this->assertSame(0x007BA7, imagecolorat($image, 1325, 35));
        $this->assertSame(0xFFFFFF, imagecolorat($image, 2805, 1418));
        $this->assertSame(0xFFFFFF, imagecolorat($image, 2806, 1418));
        $this->assertSame(0xFFFFFF, imagecolorat($image, 2807, 1418));
        imagedestroy($image);

        $decoder = new BluRayStyleEncoder(new NullLogger());
        $decoder->prepare($decodedAudioPath, $imagePath);
        $this->assertTrue($decoder->decode());
        $this->assertSame(hash_file('sha256', $audioPath), hash_file('sha256', $decodedAudioPath));
    }

    public function testItValidatesTheCompleteEncodedFormat(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'format-input.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'format-output.webp';
        $decodedAudioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'format-decoded.mp3';
        $audioData = '';

        for ($index = 0; $index < 16384; $index++) {
            $audioData .= pack('N', (int)((sin($index * 0.001) * 1000000) & 0xFFFFFFFF));
        }

        file_put_contents($audioPath, $audioData);

        $encoder = new BluRayStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);
        $this->assertTrue($encoder->encode());

        $this->assertFileExists($imagePath);
        $imageSize = getimagesize($imagePath);
        $this->assertNotFalse($imageSize);
        $this->assertSame($imageSize[0], $imageSize[1]);
        $this->assertGreaterThanOrEqual(512, $imageSize[0]);
        $this->assertLessThanOrEqual(8192, $imageSize[0]);

        $decoder = new BluRayStyleEncoder(new NullLogger());
        $decoder->prepare($decodedAudioPath, $imagePath);
        $this->assertTrue($decoder->decode());
        $this->assertSame(hash_file('sha256', $audioPath), hash_file('sha256', $decodedAudioPath));
        $this->assertSame(filesize($audioPath), filesize($decodedAudioPath));
    }

    public function testItRecoversFromCorruptedPrimaryHeaderUsingCornerCopies(): void
    {
        $audioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'fallback-input.mp3';
        $imagePath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'fallback-output.webp';
        $decodedAudioPath = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'fallback-decoded.mp3';
        $audioData = str_repeat("\x10\x20\x30\x40\x50", 1024);

        file_put_contents($audioPath, $audioData);

        $encoder = new BluRayStyleEncoder(new NullLogger());
        $encoder->prepare($audioPath, $imagePath);
        $this->assertTrue($encoder->encode());

        $image = imagecreatefromwebp($imagePath);
        $this->assertNotFalse($image);
        $imageSize = imagesx($image);
        $headerRadius = 10;
        $headerPoints = max(12, (int)round(2 * M_PI * $headerRadius * 0.9));
        $headerAngle = ((1.5 / $headerPoints) * 2 * M_PI) + (M_PI / 4);
        $headerX = (int)round($imageSize / 2 + $headerRadius * cos($headerAngle));
        $headerY = (int)round($imageSize / 2 + $headerRadius * sin($headerAngle));

        // Replace the "gic" bytes of the magic value with printable bytes so
        // the JSON remains valid and the decoder enters corruption fallback.
        imagesetpixel($image, $headerX, $headerY, 0x787878);
        $this->assertTrue(imagewebp($image, $imagePath, IMG_WEBP_LOSSLESS));
        imagedestroy($image);

        $decoder = new BluRayStyleEncoder(new NullLogger());
        $decoder->prepare($decodedAudioPath, $imagePath);
        $this->assertTrue($decoder->decode());
        $this->assertSame(hash_file('sha256', $audioPath), hash_file('sha256', $decodedAudioPath));
    }
}
