<?php

declare(strict_types=1);

namespace CdEncoder\Application\Services;

use CdEncoder\Application\Contracts\EncoderInterface;
use GdImage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

class DVDEncoder implements EncoderInterface
{
    public const PROFILE_STANDARD = 'standard';
    public const PROFILE_DIGITAL_MAX = 'digital_max';

    private const MAGIC = 'DVDMP3';
    private const FORMAT_VERSION = 1;
    private const DISC_DIAMETER_MM = 120.0;
    private const STANDARD_DPI = 600;
    private const DIGITAL_MAX_DPI = 1200;
    private const INNER_RADIUS = 80;
    private const MARGIN = 12;
    private const MARKER_OFFSET = 48;
    private const MARKER_RADIUS = 8;
    private const FILL_FACTOR = 0.9;
    private const METADATA_FIELD_LENGTH = 128;
    private const HEADER_LENGTH = 571;
    private const PAYLOAD_BYTES_PER_PIXEL = 3;
    private const MAX_PAYLOAD_LENGTH = 1073741824;

    private string $audioPath = '';
    private string $imagePath = '';
    private string $profile = 'standard';

    /** @var array<string, int|float|string> */
    private array $configuration = [];

    /** @var array<int, int> */
    private array $capacityCache = [];

    /** @var array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>} */
    private array $metadata = [
        'title' => '',
        'artist' => '',
        'album' => '',
        'year' => '',
        'technical' => [],
        'encoding' => [],
    ];

    public function __construct(private LoggerInterface $logger)
    {
    }

    /** @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>} */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function shouldTranscode(string $audioPath): bool
    {
        if (!is_readable($audioPath)) {
            throw new InvalidArgumentException("Cannot read audio file: $audioPath");
        }

        $length = filesize($audioPath);

        if ($length === false) {
            throw new RuntimeException("Unable to determine audio file size: $audioPath");
        }

        $size = self::mmToPixels(self::DISC_DIAMETER_MM, self::STANDARD_DPI);
        $candidatePixels = 0;
        $center = $size / 2;

        for ($radius = self::INNER_RADIUS; $radius <= $center - self::MARGIN; $radius++) {
            $candidatePixels += (int)floor(2 * M_PI * $radius * self::FILL_FACTOR);
        }

        $capacity = max(0, ($candidatePixels * self::PAYLOAD_BYTES_PER_PIXEL) - self::HEADER_LENGTH);

        return $length > $capacity;
    }

    public function prepare(string $audioPath, string $imagePath, string $profile = self::PROFILE_STANDARD): void
    {
        $this->audioPath = $audioPath;
        $this->imagePath = $imagePath;
        $this->profile = self::normalizeProfile($profile);
        $this->configuration = self::configurationForProfile($this->profile);
        $this->capacityCache = [];
        $this->metadata['encoding'] = $this->configuration;
    }

    public function encode(): bool
    {
        if (!is_readable($this->audioPath)) {
            throw new InvalidArgumentException("Cannot read audio file: $this->audioPath");
        }

        $this->logger->info('Encoding audio into lossless WebP.', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        clearstatcache(true, $this->audioPath);
        $length = filesize($this->audioPath);

        if ($length === false) {
            throw new RuntimeException('Unable to determine audio file size.');
        }

        $sha256 = $this->hashAudioFile($this->audioPath);

        $this->metadata = array_merge(self::readAudioMetadata($this->audioPath), [
            'encoding' => $this->metadata['encoding'],
        ]);

        if (!defined('IMG_WEBP_LOSSLESS')) {
            throw new RuntimeException('GD must support lossless WebP encoding.');
        }

        if ($length > self::MAX_PAYLOAD_LENGTH) {
            throw new InvalidArgumentException('The format supports files up to 1 GiB.');
        }

        $this->logger->info('Calculating size...', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        $size = $this->requiredImageSize($length);

        $this->logger->info('Calculating payload capacity...', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        $capacity = $this->payloadCapacity($size);

        if ($length > $capacity) {
            throw new RuntimeException("The file exceeds WebP capacity of $capacity bytes.");
        }

        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new RuntimeException('Unable to create the WebP canvas.');
        }

        $this->logger->info('Processing....', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        $white = 0xFFFFFF;
        imagefill($image, 0, 0, $white);

        $this->logger->info('Create header....', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        $header = self::createHeader($length, $sha256, $size, $this->metadata);

        $this->logger->info('Write payload....', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        $this->writePayload($image, $size, $header, $this->audioPath, $length);

        $this->logger->info('Draw markers....', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'profile' => $this->profile,
        ]);

        self::drawMarkers($image, $size);

        $written = imagewebp($image, $this->imagePath, IMG_WEBP_LOSSLESS);
        imagedestroy($image);

        if (!$written) {
            throw new RuntimeException("Unable to write lossless WebP file: $this->imagePath");
        }

        self::writeXmpMetadata($this->imagePath, $this->metadata);

        return true;
    }

    public function decode(): bool
    {
        if (!is_readable($this->imagePath)) {
            throw new InvalidArgumentException("Cannot read WebP file: $this->imagePath");
        }

        $this->logger->info('Decoding lossless WebP into audio.', [
            'imagePath' => $this->imagePath,
            'audioPath' => $this->audioPath,
            'profile' => $this->profile,
        ]);

        $this->metadata = array_merge($this->metadata, self::readXmpMetadata($this->imagePath));
        $this->configuration = self::validatedConfiguration($this->metadata['encoding'] ?? [], $this->profile);

        $image = @imagecreatefromwebp($this->imagePath);

        if ($image === false) {
            throw new RuntimeException("Unable to read WebP file: $this->imagePath");
        }

        $width = imagesx($image);
        $height = imagesy($image);

        if ($width !== $height) {
            imagedestroy($image);

            throw new InvalidArgumentException('The WebP image must be square.');
        }

        $capacity = $this->payloadCapacity($width);
        $header = $this->readBytes($image, $width, self::HEADER_LENGTH);
        $headerData = $this->parseHeader($header, $width, $height);
        $length = $headerData['length'];

        if ($length > self::MAX_PAYLOAD_LENGTH) {
            imagedestroy($image);

            throw new RuntimeException('Stored file length exceeds the 1 GiB format limit.');
        }

        if ($length > $capacity) {
            imagedestroy($image);

            throw new RuntimeException('Stored file length exceeds WebP capacity.');
        }

        $payload = $this->readBytes($image, $width, self::HEADER_LENGTH + $length);
        $data = substr($payload, self::HEADER_LENGTH, $length);
        imagedestroy($image);

        if (!hash_equals($headerData['sha256'], hash('sha256', $data, true))) {
            throw new RuntimeException('SHA-256 mismatch.');
        }

        $this->metadata = array_merge($this->metadata, $headerData['metadata']);

        if (file_put_contents($this->audioPath, $data) === false) {
            throw new RuntimeException("Unable to write decoded file: $this->audioPath");
        }

        return true;
    }

    private function ringBitCount(int $radius): int
    {
        return (int)floor(2 * M_PI * $radius * (float)$this->configuration['fill_factor']);
    }

    private function coordinateCount(int $imageSize): int
    {
        $center = $imageSize / 2;
        $seen = str_repeat("\0", intdiv($imageSize * $imageSize + 7, 8));
        $count = 0;

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $center - (int)$this->configuration['margin_px']; $radius++) {
            $ringBits = $this->ringBitCount($radius);

            for ($position = 0; $position < $ringBits; $position++) {
                $angle = ($position / $ringBits) * 2 * M_PI;
                $x = (int)round($center + $radius * cos($angle));
                $y = (int)round($center + $radius * sin($angle));

                if (self::markCoordinate($seen, $imageSize, $x, $y)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private static function markCoordinate(string &$seen, int $imageSize, int $x, int $y): bool
    {
        $pixelIndex = $y * $imageSize + $x;
        $byteIndex = intdiv($pixelIndex, 8);
        $mask = 1 << ($pixelIndex % 8);
        $byte = ord($seen[$byteIndex]);

        if (($byte & $mask) !== 0) {
            return false;
        }

        $seen[$byteIndex] = chr($byte | $mask);

        return true;
    }

    private function payloadCapacity(int $imageSize): int
    {
        if (array_key_exists($imageSize, $this->capacityCache)) {
            return $this->capacityCache[$imageSize];
        }

        $center = $imageSize / 2;
        $candidatePixels = 0;

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $center - (int)$this->configuration['margin_px']; $radius++) {
            $candidatePixels += $this->ringBitCount($radius);
        }

        $capacity = max(0, ($candidatePixels * self::PAYLOAD_BYTES_PER_PIXEL) - self::HEADER_LENGTH);
        $this->capacityCache[$imageSize] = $capacity;

        return $capacity;
    }

    private function requiredImageSize(int $payloadLength): int
    {
        $size = self::mmToPixels(self::DISC_DIAMETER_MM, (int)$this->configuration['dpi']);

        while ($size <= 8000 && $this->payloadCapacity($size) < $payloadLength) {
            $size += 200;
        }

        return $size;
    }

    private static function mmToPixels(float $millimeters, int $dpi): int
    {
        return max(1, (int)round($millimeters / 25.4 * $dpi));
    }

    private static function normalizeProfile(string $profile): string
    {
        return in_array($profile, [self::PROFILE_STANDARD, self::PROFILE_DIGITAL_MAX], true)
            ? $profile
            : self::PROFILE_STANDARD;
    }

    /** @return array<string, int|float|string> */
    private static function configurationForProfile(string $profile): array
    {
        $dpi = $profile === self::PROFILE_DIGITAL_MAX ? self::DIGITAL_MAX_DPI : self::STANDARD_DPI;

        return [
            'format_version' => self::FORMAT_VERSION,
            'profile' => $profile,
            'disc_diameter_mm' => self::DISC_DIAMETER_MM,
            'dpi' => $dpi,
            'inner_radius_px' => self::INNER_RADIUS,
            'margin_px' => self::MARGIN,
            'fill_factor' => self::FILL_FACTOR,
            'payload_bytes_per_pixel' => self::PAYLOAD_BYTES_PER_PIXEL,
            'metadata_field_length' => self::METADATA_FIELD_LENGTH,
        ];
    }

    /** @param array<string, mixed> $configuration */
    private static function validatedConfiguration(array $configuration, string $fallbackProfile): array
    {
        $defaults = self::configurationForProfile(self::normalizeProfile($fallbackProfile));

        foreach (array_keys($defaults) as $key) {
            if (!array_key_exists($key, $configuration)) {
                continue;
            }

            $value = $configuration[$key];

            if (($key === 'profile' && is_string($value))
                || ($key === 'format_version' && is_int($value))
                || ($key === 'dpi' && is_int($value) && $value > 0)
                || ($key === 'disc_diameter_mm' && is_numeric($value) && (float)$value > 0)
                || ($key === 'inner_radius_px' && is_int($value) && $value >= 0)
                || ($key === 'margin_px' && is_int($value) && $value >= 0)
                || ($key === 'fill_factor' && is_numeric($value) && (float)$value > 0 && (float)$value <= 1)
                || ($key === 'payload_bytes_per_pixel' && is_int($value) && $value === 3)
                || ($key === 'metadata_field_length' && is_int($value) && $value > 0)) {
                $defaults[$key] = $value;
            }
        }

        if ($defaults['format_version'] !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported encoding configuration version.');
        }

        return $defaults;
    }

    /** @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>} */
    private static function readAudioMetadata(string $audioPath): array
    {
        $metadata = [
            'title' => '',
            'artist' => '',
            'album' => '',
            'year' => '',
            'technical' => [],
        ];

        if (!class_exists('getID3')) {
            return $metadata;
        }

        $analysis = (new \getID3())->analyze($audioPath);
        $tags = $analysis['tags'] ?? [];
        $audio = $analysis['audio'] ?? [];
        $metadata['technical'] = [
            'bitrate_kbps' => isset($analysis['bitrate']) ? round((float)$analysis['bitrate'] / 1000, 1) : null,
            'sample_rate_hz' => $audio['sample_rate'] ?? null,
            'channels' => $audio['channels'] ?? null,
            'codec' => $audio['codec'] ?? ($analysis['codec'] ?? null),
            'duration_seconds' => isset($analysis['playtime_seconds']) ? round((float)$analysis['playtime_seconds'], 2) : null,
            'file_size_bytes' => $analysis['filesize'] ?? filesize($audioPath),
        ];

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            foreach (['id3v2', 'id3v1'] as $tagVersion) {
                $value = $tags[$tagVersion][$field][0] ?? '';

                if ($metadata[$field] === '' && is_string($value)) {
                    $metadata[$field] = trim($value, " \t\r\n\0");
                }
            }
        }

        return $metadata;
    }

    /** @param array<string, mixed> $metadata */
    private static function createHeader(int $length, string $sha256, int $imageSize, array $metadata): string
    {
        $header = self::MAGIC
            . chr(self::FORMAT_VERSION)
            . pack('N', (int)($metadata['encoding']['dpi'] ?? self::STANDARD_DPI))
            . pack('N', $imageSize)
            . pack('N', $imageSize)
            . self::packUint64($length)
            . $sha256;

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $value = substr((string)($metadata[$field] ?? ''), 0, self::METADATA_FIELD_LENGTH);
            $header .= str_pad($value, self::METADATA_FIELD_LENGTH, "\0");
        }

        return $header;
    }

    /** @return array{length: int, sha256: string, metadata: array{title: string, artist: string, album: string, year: string}} */
    private function parseHeader(string $header, int $imageWidth, int $imageHeight): array
    {
        if (strlen($header) !== self::HEADER_LENGTH) {
            throw new RuntimeException('Invalid WebP header length.');
        }

        $offset = 0;
        $magic = substr($header, $offset, strlen(self::MAGIC));
        $offset += strlen(self::MAGIC);

        if ($magic !== self::MAGIC) {
            throw new RuntimeException('Invalid WebP format magic.');
        }

        $version = ord($header[$offset++]);

        if ($version !== self::FORMAT_VERSION) {
            throw new RuntimeException('Unsupported WebP format version.');
        }

        $dpi = unpack('N', substr($header, $offset, 4))[1];
        $offset += 4;
        $storedWidth = unpack('N', substr($header, $offset, 4))[1];
        $offset += 4;
        $storedHeight = unpack('N', substr($header, $offset, 4))[1];
        $offset += 4;
        $length = self::unpackUint64(substr($header, $offset, 8));
        $offset += 8;
        $sha256 = substr($header, $offset, 32);
        $offset += 32;

        if ($storedWidth !== $imageWidth || $storedHeight !== $imageHeight || $dpi !== (int)$this->configuration['dpi']) {
            throw new RuntimeException('WebP geometry or DPI does not match the format.');
        }

        $metadata = [];

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $metadata[$field] = rtrim(substr($header, $offset, self::METADATA_FIELD_LENGTH), "\0");
            $offset += self::METADATA_FIELD_LENGTH;
        }

        return ['length' => $length, 'sha256' => $sha256, 'metadata' => $metadata];
    }

    private static function packUint64(int $value): string
    {
        return pack('N2', 0, $value);
    }

    private static function unpackUint64(string $data): int
    {
        $parts = unpack('Nhigh/Nlow', $data);

        if ($parts === false || $parts['high'] !== 0) {
            throw new RuntimeException('WebP payload length is too large.');
        }

        return (int)$parts['low'];
    }

    /** @param array<string, mixed> $metadata */
    private static function writeXmpMetadata(string $imagePath, array $metadata): void
    {
        $technical = json_encode($metadata['technical'] ?? [], JSON_THROW_ON_ERROR);
        $encoding = json_encode($metadata['encoding'] ?? [], JSON_THROW_ON_ERROR);
        $xmp = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:cd="https://cdencoder.local/ns/1.0/"'
            . ' cd:title="' . self::xmlAttribute((string)($metadata['title'] ?? '')) . '"'
            . ' cd:artist="' . self::xmlAttribute((string)($metadata['artist'] ?? '')) . '"'
            . ' cd:album="' . self::xmlAttribute((string)($metadata['album'] ?? '')) . '"'
            . ' cd:year="' . self::xmlAttribute((string)($metadata['year'] ?? '')) . '"'
            . ' cd:technical="' . self::xmlAttribute($technical) . '"'
            . ' cd:encoding="' . self::xmlAttribute($encoding) . '"'
            . '/></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

        $xmpLength = strlen($xmp);
        $chunk = 'XMP ' . pack('V', $xmpLength) . $xmp;

        if ($xmpLength % 2 !== 0) {
            $chunk .= "\0";
        }

        if (file_put_contents($imagePath, $chunk, FILE_APPEND) === false) {
            throw new RuntimeException('Unable to write WebP XMP metadata.');
        }

        $fileHandle = fopen($imagePath, 'r+b');

        if ($fileHandle === false) {
            throw new RuntimeException('Unable to update the WebP RIFF header.');
        }

        fseek($fileHandle, 4);
        fwrite($fileHandle, pack('V', filesize($imagePath) - 8));
        fclose($fileHandle);
    }

    /** @return array<string, mixed> */
    private static function readXmpMetadata(string $imagePath): array
    {
        $contents = file_get_contents($imagePath);

        if ($contents === false) {
            return [];
        }

        $chunkPosition = strpos($contents, 'XMP ');

        if ($chunkPosition === false) {
            return [];
        }

        $chunkLength = unpack('V', substr($contents, $chunkPosition + 4, 4))[1] ?? 0;
        $xmp = substr($contents, $chunkPosition + 8, $chunkLength);
        $metadata = [];

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            if (preg_match('/cd:' . $field . '="([^"]*)"/', $xmp, $matches) === 1) {
                $metadata[$field] = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        foreach (['technical', 'encoding'] as $field) {
            if (preg_match('/cd:' . $field . '="([^"]*)"/', $xmp, $matches) === 1) {
                $value = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $metadata[$field] = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        return $metadata;
    }

    private static function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private static function drawMarkers(GdImage $image, int $imageSize): void
    {
        $black = 0x000000;
        $center = $imageSize / 2;
        $markers = [
            [$center, $center - self::MARKER_OFFSET],
            [$center + self::MARKER_OFFSET, $center],
            [$center, $center + self::MARKER_OFFSET],
            [$center - self::MARKER_OFFSET, $center],
        ];

        foreach ($markers as [$x, $y]) {
            imagefilledellipse(
                $image,
                (int)round($x),
                (int)round($y),
                self::MARKER_RADIUS * 2,
                self::MARKER_RADIUS * 2,
                $black
            );
        }
    }

    private function hashAudioFile(string $audioPath): string
    {
        $handle = fopen($audioPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open audio file: $audioPath");
        }

        $context = hash_init('sha256');

        while (!feof($handle)) {
            $chunk = fread($handle, 1048576);

            if ($chunk === false) {
                fclose($handle);

                throw new RuntimeException("Unable to read audio file: $audioPath");
            }

            if ($chunk !== '') {
                hash_update($context, $chunk);
            }
        }

        fclose($handle);

        return hash_final($context, true);
    }

    private function writePayload(
        GdImage $image,
        int $imageSize,
        string $header,
        string $audioPath,
        int $audioLength,
    ): void
    {
        $center = $imageSize / 2;
        $seen = str_repeat("\0", intdiv($imageSize * $imageSize + 7, 8));
        $pixelIndex = 0;
        $payloadLength = strlen($header) + $audioLength;
        $payloadBuffer = $header;
        $payloadOffset = 0;
        $audioHandle = fopen($audioPath, 'rb');

        if ($audioHandle === false) {
            throw new RuntimeException("Unable to open audio file: $audioPath");
        }

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $center - (int)$this->configuration['margin_px']; $radius++) {
            $ringBits = $this->ringBitCount($radius);

            for ($position = 0; $position < $ringBits; $position++) {
                $angle = ($position / $ringBits) * 2 * M_PI;
                $x = (int)round($center + $radius * cos($angle));
                $y = (int)round($center + $radius * sin($angle));

                if (!self::markCoordinate($seen, $imageSize, $x, $y)) {
                    continue;
                }

                if ($pixelIndex * self::PAYLOAD_BYTES_PER_PIXEL >= $payloadLength) {
                    fclose($audioHandle);

                    return;
                }

                while (strlen($payloadBuffer) - $payloadOffset < self::PAYLOAD_BYTES_PER_PIXEL && !feof($audioHandle)) {
                    $chunk = fread($audioHandle, 1048576);

                    if ($chunk === false) {
                        fclose($audioHandle);

                        throw new RuntimeException("Unable to read audio file: $audioPath");
                    }

                    $payloadBuffer .= $chunk;
                }

                $pixel = substr($payloadBuffer, $payloadOffset, self::PAYLOAD_BYTES_PER_PIXEL);
                $payloadOffset += self::PAYLOAD_BYTES_PER_PIXEL;

                if ($payloadOffset >= 1048576) {
                    $payloadBuffer = substr($payloadBuffer, $payloadOffset);
                    $payloadOffset = 0;
                }

                $red = ord($pixel[0]);
                $green = isset($pixel[1]) ? ord($pixel[1]) : 255;
                $blue = isset($pixel[2]) ? ord($pixel[2]) : 255;
                $color = ($red << 16) | ($green << 8) | $blue;
                imagesetpixel($image, $x, $y, $color);
                $pixelIndex++;
            }
        }

        fclose($audioHandle);

        throw new RuntimeException('WebP payload area is smaller than the declared audio payload.');
    }

    private function readBytes(GdImage $image, int $imageSize, int $byteCount): string
    {
        $center = $imageSize / 2;
        $seen = str_repeat("\0", intdiv($imageSize * $imageSize + 7, 8));
        $bytes = '';

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $center - (int)$this->configuration['margin_px']; $radius++) {
            $ringBits = $this->ringBitCount($radius);

            for ($position = 0; $position < $ringBits; $position++) {
                $angle = ($position / $ringBits) * 2 * M_PI;
                $x = (int)round($center + $radius * cos($angle));
                $y = (int)round($center + $radius * sin($angle));

                if (!self::markCoordinate($seen, $imageSize, $x, $y)) {
                    continue;
                }

                $rgb = imagecolorat($image, $x, $y);
                $bytes .= chr(($rgb >> 16) & 0xFF);
                $bytes .= chr(($rgb >> 8) & 0xFF);
                $bytes .= chr($rgb & 0xFF);

                if (strlen($bytes) >= $byteCount) {
                    return substr($bytes, 0, $byteCount);
                }
            }
        }

        throw new RuntimeException('WebP does not contain enough payload pixels.');
    }
}
