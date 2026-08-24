<?php

declare(strict_types=1);

namespace AudioImageEncoder\Application\Services;

use AudioImageEncoder\Application\Contracts\EncoderInterface;
use GdImage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Encodes MP3 files into a lossless WebP image using the DVD-style ring
 * format. The header and audio payload share one deterministic sampled ring;
 * XMP stores metadata and profile configuration beside the binary image data.
 */
class DvdStyleEncoder implements EncoderInterface
{
    /** Standard 600 DPI disc profile. */
    public const PROFILE_STANDARD = 'standard';
    /** 1200 DPI profile for larger digital payloads. */
    public const PROFILE_DIGITAL_MAX = 'digital_max';

    /** Binary header signature identifying this encoder format. */
    private const MAGIC = 'DVDMP3';
    /** Binary header version used for compatibility validation. */
    private const FORMAT_VERSION = 1;
    /** Physical output diameter in millimeters. */
    private const DISC_DIAMETER_MM = 120.0;
    /** Resolution used by the standard profile. */
    private const STANDARD_DPI = 600;
    /** Resolution used by the high-capacity digital profile. */
    private const DIGITAL_MAX_DPI = 1200;
    /** Inner radius of the sampled payload annulus in pixels. */
    private const INNER_RADIUS_PX = 80;
    /** Clear pixels from the outer image edge and marker area. */
    private const RING_OUTER_MARGIN_PX = 12;
    /** White clearance between each corner marker edge and the image border. */
    private const CORNER_MARKER_EDGE_CLEARANCE_PX = 32;
    /** Physical radius reserved for the payload before the marker area. */
    private const PAYLOAD_OUTER_RADIUS_MM = 58.0;
    /** Cardinal orientation marker diameter in millimeters. */
    private const MARKER_DIAMETER_MM = 0.5;
    /** White clearance between payload pixels and marker edges in pixels. */
    private const MARKER_CLEARANCE_PX = 3;
    /** Width of the black outline around the circular audio ring. */
    private const AUDIO_RING_BORDER_WIDTH_PX = 2;
    /** Fraction of each circular circumference sampled for payload. */
    private const RING_SAMPLE_FACTOR = 0.9;
    /** Fixed byte width allocated to each textual metadata field. */
    private const METADATA_FIELD_LENGTH = 128;
    /** Fixed binary header size in bytes. */
    private const FORMAT_HEADER_LENGTH_BYTES = 571;
    /** Number of source bytes stored in each RGB pixel. */
    private const PAYLOAD_BYTES_PER_PIXEL = 3;
    /** Absolute format limit applied before image capacity is calculated. */
    private const MAX_AUDIO_LENGTH = 1073741824;

    /** Source MP3 path during encoding or destination path during decoding. */
    private string $audioPath = '';
    /** Destination WebP path during encoding or source path during decoding. */
    private string $imagePath = '';
    /** Normalized profile selected for the current operation. */
    private string $profile = 'standard';

    /**
     * Active geometry and serialization settings for the selected profile.
     *
     * @var array<string, int|float|string>
     */
    private array $configuration = [];

    /**
     * Payload capacities cached by image dimension during size selection.
     *
     * @var array<int, int>
     */
    private array $capacityCache = [];

    /**
     * Audio tags, technical details, and persisted encoding settings.
     *
     * @var array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>}
     */
    private array $metadata = [
        'title' => '',
        'artist' => '',
        'album' => '',
        'year' => '',
        'technical' => [],
        'encoding' => [],
    ];

    /** Creates an encoder that logs the DVD-format workflow. */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Returns metadata recovered from or prepared for the current image.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** Reports whether the file exceeds standard-profile ring capacity. */
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
        for ($radius = self::INNER_RADIUS_PX; $radius <= self::payloadOuterRadiusForImageSize($size, self::RING_OUTER_MARGIN_PX); $radius++) {
            $candidatePixels += (int)floor(2 * M_PI * $radius * self::RING_SAMPLE_FACTOR);
        }

        $capacity = max(0, ($candidatePixels * self::PAYLOAD_BYTES_PER_PIXEL) - self::FORMAT_HEADER_LENGTH_BYTES);

        return $length > $capacity;
    }

    /** Stores paths, selects a supported profile, and resets capacity state. */
    public function prepare(string $audioPath, string $imagePath, string $profile = self::PROFILE_STANDARD): void
    {
        $this->audioPath = $audioPath;
        $this->imagePath = $imagePath;
        $this->profile = self::normalizeProfile($profile);
        $this->configuration = self::configurationForProfile($this->profile);
        $this->capacityCache = [];
        $this->metadata['encoding'] = $this->configuration;
    }

    /** Writes the header and MP3 bytes into a lossless WebP image. */
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

        if ($length > self::MAX_AUDIO_LENGTH) {
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

        assert($size > 0);
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
        self::drawAudioRingBorder($image, $size, $this->payloadOuterRadius($size));

        $written = imagewebp($image, $this->imagePath, IMG_WEBP_LOSSLESS);
        imagedestroy($image);

        if (!$written) {
            throw new RuntimeException("Unable to write lossless WebP file: $this->imagePath");
        }

        self::writeXmpMetadata($this->imagePath, $this->metadata);

        return true;
    }

    /** Reads, validates, hashes, and writes the MP3 bytes from a WebP image. */
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

        $xmpMetadata = self::readXmpMetadata($this->imagePath);
        foreach (['title', 'artist', 'album', 'year'] as $field) {
            if (is_string($xmpMetadata[$field] ?? null)) {
                $this->metadata[$field] = $xmpMetadata[$field];
            }
        }

        $this->configuration = self::validatedConfiguration(
            is_array($xmpMetadata['encoding'] ?? null) ? $xmpMetadata['encoding'] : [],
            $this->profile,
        );

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
        $header = $this->readBytes($image, $width, self::FORMAT_HEADER_LENGTH_BYTES);
        $headerData = $this->parseHeader($header, $width, $height);
        $length = $headerData['length'];

        if ($length > self::MAX_AUDIO_LENGTH) {
            imagedestroy($image);

            throw new RuntimeException('Stored file length exceeds the 1 GiB format limit.');
        }

        if ($length > $capacity) {
            imagedestroy($image);

            throw new RuntimeException('Stored file length exceeds WebP capacity.');
        }

        $payload = $this->readBytes($image, $width, self::FORMAT_HEADER_LENGTH_BYTES + $length);
        $data = substr($payload, self::FORMAT_HEADER_LENGTH_BYTES, $length);
        imagedestroy($image);

        if (!hash_equals($headerData['sha256'], hash('sha256', $data, true))) {
            throw new RuntimeException('SHA-256 mismatch.');
        }

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $this->metadata[$field] = $headerData['metadata'][$field];
        }

        if (file_put_contents($this->audioPath, $data) === false) {
            throw new RuntimeException("Unable to write decoded file: $this->audioPath");
        }

        return true;
    }

    /** Returns the number of angular samples assigned to one radius. */
    private function ringBitCount(int $radius): int
    {
        return (int)floor(2 * M_PI * $radius * (float)$this->configuration['fill_factor']);
    }

    /** Marks a sampled pixel in the compact duplicate-detection bitset. */
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

    /** Counts unique raster coordinates available to the header and payload. */
    private function coordinateCount(int $imageSize): int
    {
        $center = $imageSize / 2;
        $seen = str_repeat("\0", intdiv($imageSize * $imageSize + 7, 8));
        $count = 0;

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $this->payloadOuterRadius($imageSize); $radius++) {
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

    /** Returns cached payload capacity after reserving binary header space. */
    private function payloadCapacity(int $imageSize): int
    {
        if (array_key_exists($imageSize, $this->capacityCache)) {
            return $this->capacityCache[$imageSize];
        }

        $capacity = max(0, ($this->coordinateCount($imageSize) * self::PAYLOAD_BYTES_PER_PIXEL) - self::FORMAT_HEADER_LENGTH_BYTES);
        $this->capacityCache[$imageSize] = $capacity;

        return $capacity;
    }

    private function payloadOuterRadius(int $imageSize): int
    {
        return self::payloadOuterRadiusForImageSize($imageSize, (int)$this->configuration['margin_px']);
    }

    private static function payloadOuterRadiusForImageSize(int $imageSize, int $margin): int
    {
        $center = $imageSize / 2;
        $markerOffset = self::markerOffsetPixels($imageSize);
        $markerRadius = (int)ceil(self::markerDiameterPixels($imageSize) / 2);

        return (int)floor(min(
            $center - $margin,
            $markerOffset - $markerRadius - self::MARKER_CLEARANCE_PX,
        ));
    }

    private static function markerOffsetPixels(int $imageSize): int
    {
        return (int)round(self::PAYLOAD_OUTER_RADIUS_MM * $imageSize / self::DISC_DIAMETER_MM);
    }

    private static function markerDiameterPixels(int $imageSize): int
    {
        return max(1, (int)round(self::MARKER_DIAMETER_MM * $imageSize / self::DISC_DIAMETER_MM));
    }

    /** Finds the smallest profile-aligned image size that fits the payload. */
    private function requiredImageSize(int $payloadLength): int
    {
        $size = self::mmToPixels(self::DISC_DIAMETER_MM, (int)$this->configuration['dpi']);

        while ($size <= 8000 && $this->payloadCapacity($size) < $payloadLength) {
            $size += 200;
        }

        return $size;
    }

    /** Converts the physical disc diameter to a positive pixel dimension. */
    private static function mmToPixels(float $millimeters, int $dpi): int
    {
        return max(1, (int)round($millimeters / 25.4 * $dpi));
    }

    /** Falls back to the standard profile for unknown profile names. */
    private static function normalizeProfile(string $profile): string
    {
        return in_array($profile, [self::PROFILE_STANDARD, self::PROFILE_DIGITAL_MAX], true)
            ? $profile
            : self::PROFILE_STANDARD;
    }

    /**
     * Builds the geometry configuration persisted with an image.
     *
     * @return array<string, int|float|string>
     */
    private static function configurationForProfile(string $profile): array
    {
        $dpi = $profile === self::PROFILE_DIGITAL_MAX ? self::DIGITAL_MAX_DPI : self::STANDARD_DPI;

        return [
            'format_version' => self::FORMAT_VERSION,
            'profile' => $profile,
            'disc_diameter_mm' => self::DISC_DIAMETER_MM,
            'dpi' => $dpi,
            'inner_radius_px' => self::INNER_RADIUS_PX,
            'margin_px' => self::RING_OUTER_MARGIN_PX,
            'payload_outer_radius_mm' => self::PAYLOAD_OUTER_RADIUS_MM,
            'marker_diameter_mm' => self::MARKER_DIAMETER_MM,
            'marker_clearance_px' => self::MARKER_CLEARANCE_PX,
            'fill_factor' => self::RING_SAMPLE_FACTOR,
            'payload_bytes_per_pixel' => self::PAYLOAD_BYTES_PER_PIXEL,
            'metadata_field_length' => self::METADATA_FIELD_LENGTH,
        ];
    }

    /**
     * Merges trusted persisted settings with safe profile defaults.
     *
     * @param array<string, mixed> $configuration
        * @return array<string, int|float|string>
     */
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

    /**
     * Extracts optional ID3 and technical metadata using getID3.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>}
     */
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

    /**
     * Serializes fixed-width geometry, integrity, and metadata header fields.
     *
     * @param array<string, mixed> $metadata
     */
    private static function createHeader(int $length, string $sha256, int $imageSize, array $metadata): string
    {
        $encoding = $metadata['encoding'] ?? null;
        $dpi = is_array($encoding) && is_int($encoding['dpi'] ?? null)
            ? $encoding['dpi']
            : self::STANDARD_DPI;

        $header = self::MAGIC
            . chr(self::FORMAT_VERSION)
            . pack('N', $dpi)
            . pack('N', $imageSize)
            . pack('N', $imageSize)
            . self::packUint64($length)
            . $sha256;

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $value = is_string($metadata[$field] ?? null) ? substr($metadata[$field], 0, self::METADATA_FIELD_LENGTH) : '';
            $header .= str_pad($value, self::METADATA_FIELD_LENGTH, "\0");
        }

        return $header;
    }

    /**
     * Validates and decodes the fixed-size binary header from the image.
     *
     * @return array{length: int, sha256: string, metadata: array{title: string, artist: string, album: string, year: string}}
     */
    private function parseHeader(string $header, int $imageWidth, int $imageHeight): array
    {
        if (strlen($header) !== self::FORMAT_HEADER_LENGTH_BYTES) {
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

        $unpackedDpi = unpack('N', substr($header, $offset, 4));
        if ($unpackedDpi === false) {
            throw new RuntimeException('Unable to read stored DPI.');
        }
        $dpi = $unpackedDpi[1];
        $offset += 4;
        $unpackedWidth = unpack('N', substr($header, $offset, 4));
        if ($unpackedWidth === false) {
            throw new RuntimeException('Unable to read stored image width.');
        }
        $storedWidth = $unpackedWidth[1];
        $offset += 4;
        $unpackedHeight = unpack('N', substr($header, $offset, 4));
        if ($unpackedHeight === false) {
            throw new RuntimeException('Unable to read stored image height.');
        }
        $storedHeight = $unpackedHeight[1];
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

    /** Encodes the payload length in the format's two-word integer field. */
    private static function packUint64(int $value): string
    {
        return pack('N2', 0, $value);
    }

    /** Decodes the format's unsigned 64-bit payload length. */
    private static function unpackUint64(string $data): int
    {
        $parts = unpack('Nhigh/Nlow', $data);

        if ($parts === false || $parts['high'] !== 0) {
            throw new RuntimeException('WebP payload length is too large.');
        }

        return (int)$parts['low'];
    }

    /**
     * Appends metadata XMP to the WebP RIFF container and updates its size.
     *
     * @param array<string, mixed> $metadata
     */
    private static function writeXmpMetadata(string $imagePath, array $metadata): void
    {
        $technical = json_encode($metadata['technical'] ?? [], JSON_THROW_ON_ERROR);
        $encoding = json_encode($metadata['encoding'] ?? [], JSON_THROW_ON_ERROR);
        $xmp = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:cd="https://cdencoder.local/ns/1.0/"'
            . ' cd:title="' . self::xmlAttribute(is_string($metadata['title'] ?? null) ? $metadata['title'] : '') . '"'
            . ' cd:artist="' . self::xmlAttribute(is_string($metadata['artist'] ?? null) ? $metadata['artist'] : '') . '"'
            . ' cd:album="' . self::xmlAttribute(is_string($metadata['album'] ?? null) ? $metadata['album'] : '') . '"'
            . ' cd:year="' . self::xmlAttribute(is_string($metadata['year'] ?? null) ? $metadata['year'] : '') . '"'
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

    /**
     * Reads optional metadata XMP from the WebP RIFF container.
     *
     * @return array<string, mixed>
     */
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

    /** Escapes a metadata value for use as an XMP XML attribute. */
    private static function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** Draws four black corner orientation markers outside the circular payload. */
    private static function drawMarkers(GdImage $image, int $imageSize): void
    {
        $black = 0x000000;
        $markerDiameter = self::markerDiameterPixels($imageSize);
        $markerRadius = $markerDiameter / 2;
        $markerInset = self::CORNER_MARKER_EDGE_CLEARANCE_PX + $markerRadius;
        $markers = [
            [$markerInset, $markerInset],
            [$imageSize - $markerInset, $markerInset],
            [$markerInset, $imageSize - $markerInset],
            [$imageSize - $markerInset, $imageSize - $markerInset],
        ];

        foreach ($markers as [$x, $y]) {
            imagefilledellipse(
                $image,
                (int)round($x),
                (int)round($y),
                $markerDiameter,
                $markerDiameter,
                $black
            );
        }
    }

    private static function drawAudioRingBorder(GdImage $image, int $imageSize, int $payloadOuterRadius): void
    {
        $center = (int)round($imageSize / 2);
        $borderRadius = $payloadOuterRadius + (int)ceil(self::AUDIO_RING_BORDER_WIDTH_PX / 2) + 2;
        imagesetthickness($image, self::AUDIO_RING_BORDER_WIDTH_PX);

        imageellipse(
            $image,
            $center,
            $center,
            $borderRadius * 2,
            $borderRadius * 2,
            0x000000,
        );

        imagesetthickness($image, 1);
    }

    /** Streams an audio file into a binary SHA-256 digest. */
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

    /** Writes the header followed by audio RGB triplets along sampled rings. */
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

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $this->payloadOuterRadius($imageSize); $radius++) {
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

        if ($pixelIndex * self::PAYLOAD_BYTES_PER_PIXEL >= $payloadLength) {
            return;
        }

        throw new RuntimeException('WebP payload area is smaller than the declared audio payload.');
    }

    /** Reads a requested number of bytes from the same ring order used to write them. */
    private function readBytes(GdImage $image, int $imageSize, int $byteCount): string
    {
        $center = $imageSize / 2;
        $seen = str_repeat("\0", intdiv($imageSize * $imageSize + 7, 8));
        $bytes = '';

        for ($radius = (int)$this->configuration['inner_radius_px']; $radius <= $this->payloadOuterRadius($imageSize); $radius++) {
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
