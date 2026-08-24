<?php

declare(strict_types=1);

namespace AudioImageEncoder\Application\Services;

use AudioImageEncoder\Application\Contracts\EncoderInterface;
use AudioImageEncoder\Application\Exceptions\CorruptionException;
use GdImage;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Encodes an MP3 file into a lossless WebP image using a Blu-ray-style layout.
 *
 * The image is a square pixel container. Its center contains small metadata
 * rings, the annulus from the file-metadata ring to the outer margin contains
 * the audio bytes, and the four outer corner blocks contain redundant copies
 * of the metadata. The image is intentionally lossless: each payload pixel
 * stores three bytes in its red, green, and blue channels.
 *
 * Corner markers provide both orientation evidence and a copy identifier.
 * They allow decoding to compensate for image rotation and to fall back to a
 * majority-voted metadata copy when the primary rings are damaged.
 */
final class BluRayStyleEncoder implements EncoderInterface
{
    /** Standard fixed 600 DPI Blu-ray-style profile. */
    public const PROFILE_STANDARD = 'standard';

    /** Binary header signature identifying this encoder format. */
    private const MAGIC = 'BLURAYMP3';
    /** Binary header version used for compatibility validation. */
    private const FORMAT_VERSION = 1;
    /** Fixed print resolution of the standard profile. */
    private const STANDARD_DPI = 600;
    /** Side length of the standard 120mm Blu-ray profile at 600 DPI. */
    private const STANDARD_IMAGE_SIZE_PX = 2835;
    /** Fraction of each circular circumference sampled for payload. */
    private const RING_SAMPLE_FACTOR = 0.9;
    /** Number of source bytes stored in each RGB pixel. */
    private const PAYLOAD_BYTES_PER_PIXEL = 3;
    /** Buffered chunk size used while streaming audio bytes into pixels. */
    private const AUDIO_READ_BUFFER_BYTES = 1048576;
    /** Number of payload pixels between progress heartbeats. */
    private const AUDIO_PROGRESS_EVERY_PIXELS = 250000;
    /** Number of decoded bytes between write flushes to the output file. */
    private const DECODE_FLUSH_EVERY_BYTES = 1048576;
    /** Absolute format limit applied before ring capacity is calculated. */
    private const MAX_AUDIO_LENGTH = 1073741824;
    /** Cerulean base color for the audio annulus and its unused pixels. */
    private const AUDIO_RING_BACKGROUND_COLOR = 0x007BA7;
    /** Fixed padded format-header length in bytes. */
    private const FORMAT_HEADER_LENGTH = 512;
    /** Exact structural ring capacity at the standard image size. */
    private const STANDARD_STRUCTURAL_CAPACITY = 816;
    /** Exact metadata ring capacity at the standard image size. */
    private const STANDARD_METADATA_CAPACITY = 1635;
    /** Exact audio ring capacity at the standard image size. */
    private const STANDARD_AUDIO_CAPACITY = 15576180;
    /** Radius of the black center reference mark in pixels. */
    private const CENTER_MARK_RADIUS_PX = 8;
    /** Inner radius of the format-header ring. */
    private const FORMAT_HEADER_INNER_RADIUS_PX = 10;
    /** Outer boundary of the format-header ring. */
    private const FORMAT_HEADER_OUTER_RADIUS_PX = 16;
    /** Outer boundary of the structural-data ring. */
    private const STRUCTURAL_DATA_OUTER_RADIUS_PX = 18;
    /** Outer boundary of the file-metadata ring and start of audio. */
    private const FILE_METADATA_OUTER_RADIUS_PX = 22;
    /** Clear pixels from the image edge and corner structures. */
    private const AUDIO_RING_OUTER_MARGIN_PX = 32;
    /** White clearance between the audio ring and the outer ID3 L-shapes. */
    private const AUDIO_RING_L_SHAPE_CLEARANCE_PX = 3;
    /** Square side length of each redundant corner block. */
    private const CORNER_BLOCK_SIZE_PX = 56;
    /** Smallest accepted square image dimension. */
    private const MIN_IMAGE_SIZE_PX = 512;
    /** Largest accepted square image dimension. */
    private const MAX_IMAGE_SIZE_PX = 8192;
    /** Minimum score accepted as useful rotation evidence. */
    private const MIN_ROTATION_SCORE = 20;
    /** Required vote advantage over the second-best corner group. */
    private const MIN_CORNER_VOTE_MARGIN = 1;
    /** Distance from the image edge to each corner block. */
    private const CORNER_BLOCK_INSET_PX = 28;
    /** Thickness of each black corner-marker border. */
    private const CORNER_MARKER_BORDER_PX = 5;
    /** Reserved corner-block margin outside the payload area. */
    private const CORNER_DATA_MARGIN_PX = 8;
    /** Prefix length containing corner identity and payload lengths. */
    private const CORNER_PREFIX_LENGTH_BYTES = 17;

    /** Source MP3 path during encoding or destination path during decoding. */
    private string $audioPath = '';
    /** Destination WebP path during encoding or source path during decoding. */
    private string $imagePath = '';
    /** Detected image rotation applied to all decoder coordinate sampling. */
    private float $rotationAngle = 0.0;

    /**
     * Runtime values recorded in the decoded image metadata.
     *
     * @var array<string, int|float|string>
     */
    private array $configuration = [];

    /**
     * Audio tags, technical details, and persisted Blu-ray encoding settings.
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

    /** Creates an encoder that logs format and corruption-recovery events. */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Returns metadata and the active Blu-ray layout configuration.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** Stores the source and destination paths for a standard-profile operation. */
    public function prepare(string $audioPath, string $imagePath, string $profile = self::PROFILE_STANDARD): void
    {
        $this->audioPath = $audioPath;
        $this->imagePath = $imagePath;
        $this->configuration = self::configuration();
        $this->metadata['encoding'] = $this->configuration;
    }

    /** Reports that this lossless container does not require audio transcoding. */
    public function shouldTranscode(string $audioPath): bool
    {
        if (!is_readable($audioPath)) {
            throw new InvalidArgumentException("Cannot read audio file: $audioPath");
        }

        return false;
    }

    /** Encodes audio, metadata, redundancy, and markers into lossless WebP. */
    public function encode(): bool
    {
        $startedAt = microtime(true);

        if (!is_readable($this->audioPath)) {
            throw new InvalidArgumentException("Cannot read audio file: $this->audioPath");
        }

        if (!defined('IMG_WEBP_LOSSLESS')) {
            throw new RuntimeException('GD must support lossless WebP encoding.');
        }

        // The header stores the exact byte length, so the image can contain a
        // final pixel with only one or two meaningful color channels.
        $length = filesize($this->audioPath);

        if ($length === false) {
            throw new RuntimeException('Unable to determine audio file size.');
        }

        if ($length > self::MAX_AUDIO_LENGTH) {
            throw new InvalidArgumentException('The format supports files up to 1 GiB.');
        }

        $this->logger->info('Blu-ray encode started.', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'audioLength' => $length,
        ]);

        $metadataStartedAt = microtime(true);
        $this->metadata = array_merge(self::readAudioMetadata($this->audioPath), [
            'encoding' => $this->configuration,
        ]);
        $sha256 = $this->hashAudioFile($this->audioPath);
        $rawId3Tag = $this->rawId3Tag();
        $structuralData = self::structuralData($this->audioPath, $length);
        $fileMetadata = json_encode([
            'title' => $this->metadata['title'],
            'artist' => $this->metadata['artist'],
            'album' => $this->metadata['album'],
            'year' => $this->metadata['year'],
            'technical' => $this->metadata['technical'],
        ], JSON_THROW_ON_ERROR);
        $this->logger->info('Blu-ray encode metadata stage completed.', [
            'duration_ms' => (int) round((microtime(true) - $metadataStartedAt) * 1000),
        ]);

        // Image dimensions are selected before the header is built because
        // the header must authenticate the dimensions of its containing image.
        $sizeStartedAt = microtime(true);
        $size = max(1, $this->requiredImageSize($length, strlen($structuralData), strlen($fileMetadata), strlen($rawId3Tag)));
        $this->logger->info('Blu-ray encode image sizing completed.', [
            'imageSize' => $size,
            'duration_ms' => (int) round((microtime(true) - $sizeStartedAt) * 1000),
        ]);

        $header = self::formatHeader($size, $length, strlen($structuralData), strlen($fileMetadata), $sha256);

        $image = imagecreatetruecolor($size, $size);

        if ($image === false) {
            throw new RuntimeException('Unable to create the WebP canvas.');
        }

        $drawStartedAt = microtime(true);
        imagefill($image, 0, 0, 0xFFFFFF);
        $this->logger->info('Blu-ray encode draw checkpoint: canvas filled.');
        self::fillAudioRingBackground($image, $size);
        $this->logger->info('Blu-ray encode draw checkpoint: audio ring background filled.');
        $this->writeBytes($image, $size, self::FORMAT_HEADER_INNER_RADIUS_PX, self::FORMAT_HEADER_OUTER_RADIUS_PX, $header);
        $this->logger->info('Blu-ray encode draw checkpoint: format header written.');
        $this->writeBytes($image, $size, self::FORMAT_HEADER_OUTER_RADIUS_PX, self::STRUCTURAL_DATA_OUTER_RADIUS_PX, $structuralData);
        $this->logger->info('Blu-ray encode draw checkpoint: structural data written.');
        $this->writeBytes($image, $size, self::STRUCTURAL_DATA_OUTER_RADIUS_PX, self::FILE_METADATA_OUTER_RADIUS_PX, $fileMetadata);
        $this->logger->info('Blu-ray encode draw checkpoint: file metadata written.');
        $this->writeAudioBytes($image, $size, $length);
        $this->logger->info('Blu-ray encode draw checkpoint: audio payload written.');
        self::writeCornerCopies($image, $size, $header, $structuralData, $fileMetadata);
        $this->logger->info('Blu-ray encode draw checkpoint: corner copies written.');
        self::writeRawId3LShapes($image, $size, $rawId3Tag);
        $this->logger->info('Blu-ray encode draw checkpoint: raw ID3 L-shapes written.');
        self::drawCenterMark($image, $size);
        self::drawCornerMarkers($image, $size);
        $this->logger->info('Blu-ray encode draw checkpoint: markers drawn.');
        $this->logger->info('Blu-ray encode drawing stage completed.', [
            'duration_ms' => (int) round((microtime(true) - $drawStartedAt) * 1000),
        ]);

        $writeStartedAt = microtime(true);
        $written = imagewebp($image, $this->imagePath, IMG_WEBP_LOSSLESS);
        imagedestroy($image);
        $this->logger->info('Blu-ray encode WebP write stage completed.', [
            'duration_ms' => (int) round((microtime(true) - $writeStartedAt) * 1000),
        ]);

        if (!$written) {
            throw new RuntimeException("Unable to write lossless WebP file: $this->imagePath");
        }

        $this->logger->info('Encoded audio into a Blu-ray ring layout.', [
            'audioPath' => $this->audioPath,
            'imagePath' => $this->imagePath,
            'imageSize' => $size,
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return true;
    }

    /** Decodes metadata and audio, using validated corner redundancy when needed. */
    public function decode(): bool
    {
        if (!is_readable($this->imagePath)) {
            throw new InvalidArgumentException("Cannot read WebP file: $this->imagePath");
        }

        $image = @imagecreatefromwebp($this->imagePath);

        if ($image === false) {
            throw new RuntimeException("Unable to read WebP file: $this->imagePath");
        }

        $size = imagesx($image);

        if ($size < self::MIN_IMAGE_SIZE_PX || $size > self::MAX_IMAGE_SIZE_PX) {
            imagedestroy($image);
            throw new InvalidArgumentException(sprintf('Image size %dÃ—%d is outside valid range [%d, %d].', $size, $size, self::MIN_IMAGE_SIZE_PX, self::MAX_IMAGE_SIZE_PX));
        }

        if ($size !== imagesy($image)) {
            imagedestroy($image);
            throw new InvalidArgumentException('The WebP image must be square.');
        }

        // All coordinate readers use this angle. Detect it once so primary
        // rings and corner backup data are sampled in the same orientation.
        $this->rotationAngle = $this->detectRotationAngle($image, $size);

        $headerData = null;
        $structuralData = null;
        $fileMetadata = null;

        try {
            // The primary rings are authoritative when intact. A failed
            // structural read is handled separately from generic I/O errors
            // so only corruption enters the redundant-corner recovery path.
            $header = $this->readBytes($image, $size, self::FORMAT_HEADER_INNER_RADIUS_PX, self::FORMAT_HEADER_OUTER_RADIUS_PX, self::FORMAT_HEADER_LENGTH);
            $headerData = self::parseFormatHeader($header, $size);
            $structuralData = $this->readBytes($image, $size, self::FORMAT_HEADER_OUTER_RADIUS_PX, self::STRUCTURAL_DATA_OUTER_RADIUS_PX, $headerData['structural_length']);
            $fileMetadata = $this->readBytes($image, $size, self::STRUCTURAL_DATA_OUTER_RADIUS_PX, self::FILE_METADATA_OUTER_RADIUS_PX, $headerData['metadata_length']);
            $this->logger->info('Decoded format header and metadata from primary ring.');
        } catch (CorruptionException $e) {
            $this->logger->warning('Primary ring corrupted, attempting corner fallback.', ['error' => $e->getMessage()]);
            $cornerCopy = $this->readMostCommonCornerCopy($image, $size);

            if ($cornerCopy === null) {
                imagedestroy($image);
                throw CorruptionException::allCornersCorrupted();
            }

            // Corner data is still validated against the actual image size;
            // redundancy must not bypass the format's structural checks.
            $headerData = self::parseFormatHeader($cornerCopy['header'], $size);
            $structuralData = $cornerCopy['structural'];
            $fileMetadata = $cornerCopy['metadata'];
            $this->logger->info('Decoded format header and metadata from corner backup.');
        } catch (RuntimeException $e) {
            imagedestroy($image);
            throw new InvalidArgumentException("Unable to decode Blu-ray metadata: {$e->getMessage()}");
        } catch (\Throwable $e) {
            imagedestroy($image);
            throw new RuntimeException("Unexpected error during Blu-ray decode: {$e->getMessage()}", previous: $e);
        }

        $this->logger->info('Blu-ray decode audio extraction started.', [
            'audioLength' => $headerData['audio_length'],
        ]);
        $decodeAudioStartedAt = microtime(true);

        try {
            $audioHash = $this->decodeAudioToFile($image, $size, $headerData['audio_length']);
        } finally {
            imagedestroy($image);
        }

        $this->logger->info('Blu-ray decode audio extraction completed.', [
            'duration_ms' => (int) round((microtime(true) - $decodeAudioStartedAt) * 1000),
        ]);

        try {
            $metadata = json_decode($fileMetadata, true, 512, JSON_THROW_ON_ERROR);

            if (!is_array($metadata)) {
                throw CorruptionException::invalidFileMetadata('Metadata JSON must decode to an object.');
            }
        } catch (\JsonException $e) {
            throw CorruptionException::invalidFileMetadata("JSON decode error: {$e->getMessage()}");
        }

        $this->metadata = array_merge($this->metadata, [
            'title' => is_string($metadata['title'] ?? null) ? $metadata['title'] : '',
            'artist' => is_string($metadata['artist'] ?? null) ? $metadata['artist'] : '',
            'album' => is_string($metadata['album'] ?? null) ? $metadata['album'] : '',
            'year' => is_string($metadata['year'] ?? null) ? $metadata['year'] : '',
            'technical' => is_array($metadata['technical'] ?? null) ? $metadata['technical'] : [],
            'encoding' => $headerData['encoding'],
        ]);

        if (!hash_equals($headerData['sha256'], $audioHash)) {
            $this->logger->warning('Decoded audio failed SHA-256 verification.', [
                'imagePath' => $this->imagePath,
                'audioPath' => $this->audioPath,
                'expectedSha256' => bin2hex($headerData['sha256']),
                'actualSha256' => bin2hex($audioHash),
            ]);
        }

        return true;
    }

    /**
     * Returns the fixed Blu-ray geometry and serialization settings.
     *
     * @return array<string, int|float|string>
     */
    private static function configuration(): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'profile' => self::PROFILE_STANDARD,
            'dpi' => self::STANDARD_DPI,
            'center_mark_radius' => self::CENTER_MARK_RADIUS_PX,
            'format_header_start' => self::FORMAT_HEADER_INNER_RADIUS_PX,
            'format_header_end' => self::FORMAT_HEADER_OUTER_RADIUS_PX,
            'structural_data_end' => self::STRUCTURAL_DATA_OUTER_RADIUS_PX,
            'file_metadata_end' => self::FILE_METADATA_OUTER_RADIUS_PX,
            'outer_margin' => self::AUDIO_RING_OUTER_MARGIN_PX,
            'audio_ring_l_shape_clearance' => self::AUDIO_RING_L_SHAPE_CLEARANCE_PX,
            'corner_marker_border' => self::CORNER_MARKER_BORDER_PX,
            'corner_data_margin' => self::CORNER_DATA_MARGIN_PX,
            'corner_block_size' => self::CORNER_BLOCK_SIZE_PX,
            'corner_block_inset' => self::CORNER_BLOCK_INSET_PX,
            'fill_factor' => self::RING_SAMPLE_FACTOR,
            'payload_bytes_per_pixel' => self::PAYLOAD_BYTES_PER_PIXEL,
        ];
    }

    /** Finds the smallest supported square image that fits all format regions. */
    private function requiredImageSize(int $audioLength, int $structuralLength, int $metadataLength, int $rawId3Length): int
    {
        if ($audioLength <= 0 || $audioLength > self::MAX_AUDIO_LENGTH) {
            throw new InvalidArgumentException('Audio length must be positive and not exceed maximum payload.');
        }

        if ($structuralLength <= 0 || $metadataLength <= 0 || $rawId3Length < 0) {
            throw new InvalidArgumentException('Structural and metadata lengths must be positive, and raw ID3 length must not be negative.');
        }

        if ($structuralLength <= self::STANDARD_STRUCTURAL_CAPACITY
            && $metadataLength <= self::STANDARD_METADATA_CAPACITY
            && $audioLength <= self::STANDARD_AUDIO_CAPACITY
            && self::rawId3FitsInLShape(self::STANDARD_IMAGE_SIZE_PX, $rawId3Length)) {
            return self::STANDARD_IMAGE_SIZE_PX;
        }

        // 2835 px is 120 mm at 600 dpi. Larger canvases are considered in
        // 200 px increments because the active profile is print-oriented.
        $size = max(self::MIN_IMAGE_SIZE_PX, self::STANDARD_IMAGE_SIZE_PX);

        while ($size <= self::MAX_IMAGE_SIZE_PX) {
            $headerCapacity = $this->regionCapacity($size, self::FORMAT_HEADER_INNER_RADIUS_PX, self::FORMAT_HEADER_OUTER_RADIUS_PX);
            $structuralCapacity = $this->regionCapacity($size, self::FORMAT_HEADER_OUTER_RADIUS_PX, self::STRUCTURAL_DATA_OUTER_RADIUS_PX);
            $metadataCapacity = $this->regionCapacity($size, self::STRUCTURAL_DATA_OUTER_RADIUS_PX, self::FILE_METADATA_OUTER_RADIUS_PX);
            $audioCapacity = $this->audioCapacity($size);

            if (self::FORMAT_HEADER_LENGTH <= $headerCapacity
                && $structuralLength <= $structuralCapacity
                && $metadataLength <= $metadataCapacity
                && $audioLength <= $audioCapacity
                && self::rawId3FitsInLShape($size, $rawId3Length)) {
                return $size;
            }

            $size += 200;
        }

        throw new RuntimeException(sprintf('The Blu-ray WebP payload (audio: %d bytes, structural: %d bytes, metadata: %d bytes) exceeds the maximum image size of %dÃ—%d.', $audioLength, $structuralLength, $metadataLength, self::MAX_IMAGE_SIZE_PX, self::MAX_IMAGE_SIZE_PX));
    }

    private static function rawId3FitsInLShape(int $size, int $length): bool
    {
        if ($length === 0) {
            return true;
        }

        try {
            self::lArmThicknessForDataLength($size, $length);
        } catch (RuntimeException) {
            return false;
        }

        return true;
    }

    /** Returns the number of audio bytes available in the outer annulus. */
    private function audioCapacity(int $size): int
    {
        return $this->regionCapacity($size, self::FILE_METADATA_OUTER_RADIUS_PX, (int)floor($size / 2) - self::AUDIO_RING_OUTER_MARGIN_PX);
    }

    /** Converts a sampled ring coordinate count into its byte capacity. */
    private function regionCapacity(int $size, int $innerRadius, int $outerRadius): int
    {
        return $this->coordinateCount($size, $innerRadius, $outerRadius) * self::PAYLOAD_BYTES_PER_PIXEL;
    }

    /** Counts unique coordinates generated for a metadata or audio ring. */
    private function coordinateCount(int $size, int $innerRadius, int $outerRadius): int
    {
        $count = 0;

        foreach ($this->coordinates($size, $innerRadius, $outerRadius) as $_) {
            $count++;
        }

        return $count;
    }

    /** @return iterable<array{0: int, 1: int}> */
    private function coordinates(int $size, int $innerRadius, int $outerRadius): iterable
    {
        // Each radius is sampled at roughly 90% of its circumference. The
        // alternating phase avoids repeatedly selecting radial pixel columns,
        // while the bitset removes collisions caused by integer rounding.
        $center = $size / 2;
        $seen = str_repeat("\0", intdiv($size * $size + 7, 8));
        $applyRotation = abs($this->rotationAngle) > 1e-12;

        for ($radius = $innerRadius; $radius <= $outerRadius; $radius++) {
            $points = max(12, (int)round(2 * M_PI * $radius * self::RING_SAMPLE_FACTOR));
            $phase = (($radius % 2) === 0) ? (M_PI / 4) : (M_PI / 8);

            for ($position = 0; $position < $points; $position++) {
                $angle = ((($position + 0.5) / max(1, $points)) * 2 * M_PI) + $phase;
                $x = (int)round($center + $radius * cos($angle));
                $y = (int)round($center + $radius * sin($angle));

                if (!self::markCoordinate($seen, $size, $x, $y)) {
                    continue;
                }

                if ($applyRotation) {
                    yield $this->transformCoordinate($size, $x, $y);
                    continue;
                }

                yield [$x, $y];
            }
        }
    }

    private static function markCoordinate(string &$seen, int $size, int $x, int $y): bool
    {
        // A compact bitset keeps duplicate detection proportional to the
        // canvas rather than allocating one PHP value per sampled pixel.
        $pixelIndex = $y * $size + $x;
        $byteIndex = intdiv($pixelIndex, 8);
        $mask = 1 << ($pixelIndex % 8);
        $byte = ord($seen[$byteIndex]);

        if (($byte & $mask) !== 0) {
            return false;
        }

        $seen[$byteIndex] = chr($byte | $mask);

        return true;
    }

    /** Writes arbitrary binary data to a deterministic RGB-sampled ring. */
    private function writeBytes(GdImage $image, int $size, int $innerRadius, int $outerRadius, string $data): void
    {
        // Metadata is written in RGB triplets. Padding is only used by the
        // fixed-size format header; length fields determine what is read back.
        $offset = 0;
        $length = strlen($data);

        foreach ($this->coordinates($size, $innerRadius, $outerRadius) as [$x, $y]) {
            if ($offset >= $length) {
                return;
            }

            $red = ord($data[$offset++] ?? "\0");
            $green = ord($data[$offset++] ?? "\0");
            $blue = ord($data[$offset++] ?? "\0");
            imagesetpixel($image, $x, $y, ($red << 16) | ($green << 8) | $blue);
        }

        if ($offset < $length) {
            throw new RuntimeException('A Blu-ray information ring is too small.');
        }
    }

    /** Streams the source MP3 into the outer audio annulus as RGB triplets. */
    private function writeAudioBytes(GdImage $image, int $size, int $length): void
    {
        $handle = fopen($this->audioPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open audio file: $this->audioPath");
        }

        $remaining = $length;
        $buffer = '';
        $bufferOffset = 0;
        $totalLength = $length;
        $writtenBytes = 0;
        $processedPixels = 0;
        $center = $size / 2;
        $seen = str_repeat("\0", intdiv($size * $size + 7, 8));
        $outerRadius = (int)floor($size / 2) - self::AUDIO_RING_OUTER_MARGIN_PX;

        // Audio uses deterministic coordinates. This loop is inlined to avoid
        // generator/array overhead on millions of payload pixels.
        for ($radius = self::FILE_METADATA_OUTER_RADIUS_PX; $radius <= $outerRadius; $radius++) {
            $points = max(12, (int)round(2 * M_PI * $radius * self::RING_SAMPLE_FACTOR));
            $phase = (($radius % 2) === 0) ? (M_PI / 4) : (M_PI / 8);

            for ($position = 0; $position < $points; $position++) {
                if ($remaining === 0) {
                    fclose($handle);
                    return;
                }

                $angle = ((($position + 0.5) / max(1, $points)) * 2 * M_PI) + $phase;
                $x = (int)round($center + $radius * cos($angle));
                $y = (int)round($center + $radius * sin($angle));

                if (!self::markCoordinate($seen, $size, $x, $y)) {
                    continue;
                }

                $needed = min(3, $remaining);

                while ((strlen($buffer) - $bufferOffset) < $needed) {
                    $chunk = fread($handle, max(1, min(self::AUDIO_READ_BUFFER_BYTES, $remaining)));

                    if ($chunk === false || $chunk === '') {
                        fclose($handle);
                        throw new RuntimeException('Unable to read audio payload.');
                    }

                    if ($bufferOffset > 0) {
                        $buffer = substr($buffer, $bufferOffset);
                        $bufferOffset = 0;
                    }

                    $buffer .= $chunk;
                }

                $red = ord($buffer[$bufferOffset]);
                $green = $needed > 1 ? ord($buffer[$bufferOffset + 1]) : 0;
                $blue = $needed > 2 ? ord($buffer[$bufferOffset + 2]) : 0;
                $bufferOffset += $needed;
                $remaining -= $needed;
                $writtenBytes += $needed;
                $processedPixels++;
                imagesetpixel($image, $x, $y, ($red << 16) | ($green << 8) | $blue);

                if (($processedPixels % self::AUDIO_PROGRESS_EVERY_PIXELS) === 0) {
                    if (function_exists('set_time_limit')) {
                        set_time_limit(120);
                    }

                    $this->logger->info('Blu-ray encode audio payload progress.', [
                        'processedPixels' => $processedPixels,
                        'writtenBytes' => $writtenBytes,
                        'totalBytes' => $totalLength,
                        'progressPercent' => round(($writtenBytes / max(1, $totalLength)) * 100, 2),
                    ]);
                }
            }
        }

        fclose($handle);

        if ($remaining > 0) {
            throw new RuntimeException('The Blu-ray audio ring is too small.');
        }
    }

    /** Reads a bounded binary value from the deterministic ring coordinate sequence. */
    private function readBytes(GdImage $image, int $size, int $innerRadius, int $outerRadius, int $length): string
    {
        $data = '';

        foreach ($this->coordinates($size, $innerRadius, $outerRadius) as [$x, $y]) {
            $rgb = imagecolorat($image, $x, $y);
            $data .= chr(($rgb >> 16) & 0xFF);
            $data .= chr(($rgb >> 8) & 0xFF);
            $data .= chr($rgb & 0xFF);

            if (strlen($data) >= $length) {
                return substr($data, 0, $length);
            }
        }

        throw new RuntimeException('A Blu-ray information ring is incomplete.');
    }

    /** Streams audio pixels to disk and returns the digest of the recovered bytes. */
    private function decodeAudioToFile(GdImage $image, int $size, int $length): string
    {
        $handle = fopen($this->audioPath, 'wb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open decoded file: $this->audioPath");
        }

        // Hash while streaming the payload to avoid loading a potentially
        // large MP3 into memory and to retain an integrity check for callers.
        $hashContext = hash_init('sha256');
        $remaining = $length;
        $totalLength = $length;
        $processedPixels = 0;
        $writeBuffer = '';

        try {
            if (abs($this->rotationAngle) <= 1e-12) {
                $center = $size / 2;
                $seen = str_repeat("\0", intdiv($size * $size + 7, 8));
                $outerRadius = (int)floor($size / 2) - self::AUDIO_RING_OUTER_MARGIN_PX;

                for ($radius = self::FILE_METADATA_OUTER_RADIUS_PX; $radius <= $outerRadius; $radius++) {
                    $points = max(12, (int)round(2 * M_PI * $radius * self::RING_SAMPLE_FACTOR));
                    $phase = (($radius % 2) === 0) ? (M_PI / 4) : (M_PI / 8);

                    for ($position = 0; $position < $points; $position++) {
                        if ($remaining === 0) {
                            break 2;
                        }

                        $angle = ((($position + 0.5) / max(1, $points)) * 2 * M_PI) + $phase;
                        $x = (int)round($center + $radius * cos($angle));
                        $y = (int)round($center + $radius * sin($angle));

                        if (!self::markCoordinate($seen, $size, $x, $y)) {
                            continue;
                        }

                        $rgb = imagecolorat($image, $x, $y);
                        $byteCount = min(3, $remaining);

                        if ($byteCount >= 1) {
                            $writeBuffer .= chr(($rgb >> 16) & 0xFF);
                        }

                        if ($byteCount >= 2) {
                            $writeBuffer .= chr(($rgb >> 8) & 0xFF);
                        }

                        if ($byteCount >= 3) {
                            $writeBuffer .= chr($rgb & 0xFF);
                        }

                        $remaining -= $byteCount;
                        $processedPixels++;

                        if (strlen($writeBuffer) >= self::DECODE_FLUSH_EVERY_BYTES) {
                            if (fwrite($handle, $writeBuffer) !== strlen($writeBuffer)) {
                                throw new RuntimeException("Unable to write decoded file: $this->audioPath");
                            }

                            hash_update($hashContext, $writeBuffer);
                            $writeBuffer = '';
                        }

                        if (($processedPixels % self::AUDIO_PROGRESS_EVERY_PIXELS) === 0) {
                            if (function_exists('set_time_limit')) {
                                set_time_limit(120);
                            }

                            $writtenBytes = $totalLength - $remaining;
                            $this->logger->info('Blu-ray decode audio payload progress.', [
                                'processedPixels' => $processedPixels,
                                'writtenBytes' => $writtenBytes,
                                'totalBytes' => $totalLength,
                                'progressPercent' => round(($writtenBytes / max(1, $totalLength)) * 100, 2),
                            ]);
                        }
                    }
                }
            } else {
                foreach ($this->coordinates($size, self::FILE_METADATA_OUTER_RADIUS_PX, (int)floor($size / 2) - self::AUDIO_RING_OUTER_MARGIN_PX) as [$x, $y]) {
                    if ($remaining === 0) {
                        break;
                    }

                    $rgb = imagecolorat($image, $x, $y);
                    $byteCount = min(3, $remaining);

                    if ($byteCount >= 1) {
                        $writeBuffer .= chr(($rgb >> 16) & 0xFF);
                    }

                    if ($byteCount >= 2) {
                        $writeBuffer .= chr(($rgb >> 8) & 0xFF);
                    }

                    if ($byteCount >= 3) {
                        $writeBuffer .= chr($rgb & 0xFF);
                    }

                    $remaining -= $byteCount;
                    $processedPixels++;

                    if (strlen($writeBuffer) >= self::DECODE_FLUSH_EVERY_BYTES) {
                        if (fwrite($handle, $writeBuffer) !== strlen($writeBuffer)) {
                            throw new RuntimeException("Unable to write decoded file: $this->audioPath");
                        }

                        hash_update($hashContext, $writeBuffer);
                        $writeBuffer = '';
                    }

                    if (($processedPixels % self::AUDIO_PROGRESS_EVERY_PIXELS) === 0) {
                        if (function_exists('set_time_limit')) {
                            set_time_limit(120);
                        }

                        $writtenBytes = $totalLength - $remaining;
                        $this->logger->info('Blu-ray decode audio payload progress.', [
                            'processedPixels' => $processedPixels,
                            'writtenBytes' => $writtenBytes,
                            'totalBytes' => $totalLength,
                            'progressPercent' => round(($writtenBytes / max(1, $totalLength)) * 100, 2),
                        ]);
                    }
                }
            }

            if ($writeBuffer !== '') {
                if (fwrite($handle, $writeBuffer) !== strlen($writeBuffer)) {
                    throw new RuntimeException("Unable to write decoded file: $this->audioPath");
                }

                hash_update($hashContext, $writeBuffer);
            }
        } finally {
            fclose($handle);
        }

        if ($remaining > 0) {
            throw new RuntimeException('The Blu-ray audio ring is incomplete.');
        }

        return hash_final($hashContext, true);
    }

    /** @return array{audio_length: int, structural_length: int, metadata_length: int, sha256: string, encoding: array<string, int|float|string>} */
    private static function parseFormatHeader(string $header, int $imageSize): array
    {
        // Header fields are trusted only after all identity, dimension,
        // length, checksum, and required layout fields have been checked.
        $header = rtrim($header, "\0");
        $data = json_decode($header, true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($data)) {
            throw CorruptionException::invalidHeader('header JSON must decode to an object');
        }

        $magic = $data['magic'] ?? null;
        $formatVersion = $data['format_version'] ?? null;
        $storedImageSize = $data['image_size'] ?? null;
        $audioLength = $data['audio_length'] ?? null;
        $structuralLength = $data['structural_length'] ?? null;
        $metadataLength = $data['metadata_length'] ?? null;
        $sha256Hex = $data['sha256'] ?? null;

        if (!is_string($magic) || $magic !== self::MAGIC) {
            throw CorruptionException::invalidHeader('magic mismatch');
        }

        if (!is_int($formatVersion) || $formatVersion !== self::FORMAT_VERSION) {
            throw CorruptionException::invalidHeader(sprintf('format version %s not supported', is_scalar($formatVersion) ? (string)$formatVersion : 'unknown'));
        }

        if (!is_int($storedImageSize)) {
            throw CorruptionException::invalidHeader('image size is invalid');
        }

        if ($storedImageSize !== $imageSize) {
            throw CorruptionException::invalidHeader(sprintf('image size mismatch: header says %d, actual is %d', $storedImageSize, $imageSize));
        }

        if (!is_int($audioLength)) {
            throw CorruptionException::invalidHeader('audio length is not an integer');
        }

        if ($audioLength <= 0 || $audioLength > self::MAX_AUDIO_LENGTH) {
            throw CorruptionException::invalidHeader(sprintf('audio length %d is invalid', $audioLength));
        }

        if (!is_int($structuralLength)) {
            throw CorruptionException::invalidHeader('structural length is not an integer');
        }

        if ($structuralLength <= 0) {
            throw CorruptionException::invalidHeader(sprintf('structural length %d is invalid', $structuralLength));
        }

        if (!is_int($metadataLength)) {
            throw CorruptionException::invalidHeader('metadata length is not an integer');
        }

        if ($metadataLength <= 0) {
            throw CorruptionException::invalidHeader(sprintf('metadata length %d is invalid', $metadataLength));
        }

        if (!is_string($sha256Hex) || strlen($sha256Hex) !== 64 || !ctype_xdigit($sha256Hex)) {
            throw CorruptionException::invalidHeader('invalid SHA256 checksum format');
        }

        $sha256 = hex2bin($sha256Hex);
        if ($sha256 === false) {
            throw CorruptionException::invalidHeader('unable to decode SHA256 checksum');
        }

        if (!isset($data['rings']) || !is_array($data['rings'])) {
            throw CorruptionException::invalidHeader('rings array missing or invalid');
        }

        if (!isset($data['markers']) || !is_string($data['markers'])) {
            throw CorruptionException::invalidHeader('markers field missing or invalid');
        }

        return [
            'audio_length' => $audioLength,
            'structural_length' => $structuralLength,
            'metadata_length' => $metadataLength,
            'sha256' => $sha256,
            'encoding' => self::configuration(),
        ];
    }

    /** Serializes and zero-pads the authenticated format header. */
    private static function formatHeader(int $size, int $audioLength, int $structuralLength, int $metadataLength, string $sha256): string
    {
        $header = json_encode([
            'magic' => self::MAGIC,
            'format_version' => self::FORMAT_VERSION,
            'image_size' => $size,
            'audio_length' => $audioLength,
            'structural_length' => $structuralLength,
            'metadata_length' => $metadataLength,
            'sha256' => bin2hex($sha256),
            'rings' => ['center_mark', 'format_header', 'structural_data', 'file_metadata', 'audio_frames'],
            'markers' => 'four_corners',
        ], JSON_THROW_ON_ERROR);

        if (strlen($header) > self::FORMAT_HEADER_LENGTH) {
            throw new RuntimeException('The Blu-ray format header is too large.');
        }

        return str_pad($header, self::FORMAT_HEADER_LENGTH, "\0");
    }

    /** Draws the fixed black center reference mark. */
    private static function drawCenterMark(GdImage $image, int $size): void
    {
        $center = (int)round($size / 2);
        imagefilledellipse($image, $center, $center, self::CENTER_MARK_RADIUS_PX * 2, self::CENTER_MARK_RADIUS_PX * 2, 0x000000);
    }

    private static function fillAudioRingBackground(GdImage $image, int $size): void
    {
        // Paint the complete annulus first, then restore the white inner disc.
        // Payload pixels overwrite only the sampled audio coordinates, leaving
        // unused audio capacity visibly cerulean.
        $center = (int)round($size / 2);
        $outerRadius = $center - self::AUDIO_RING_OUTER_MARGIN_PX;
        $innerRadius = self::FILE_METADATA_OUTER_RADIUS_PX - 1;

        imagefilledellipse(
            $image,
            $center,
            $center,
            $outerRadius * 2,
            $outerRadius * 2,
            self::AUDIO_RING_BACKGROUND_COLOR
        );
        imagefilledellipse(
            $image,
            $center,
            $center,
            $innerRadius * 2,
            $innerRadius * 2,
            0xFFFFFF
        );
    }

    /** Writes the leading raw ID3 tag into symmetric outer-corner L-shapes. */
    private static function writeRawId3LShapes(GdImage $image, int $size, string $data): void
    {
        // Reserve at least one pixel in the canonical footprint even when the
        // source has no ID3 tag. Empty capacity remains white outside the disc.
        $dataLength = strlen($data);
        $armThickness = self::lArmThicknessForDataLength($size, max(1, $dataLength));

        foreach (range(0, 3) as $cornerId) {
            $offset = 0;

            foreach (self::cornerLCoordinatesForPayload($size, $cornerId, $armThickness, $dataLength) as [$x, $y]) {
                if ($offset >= $dataLength) {
                    break;
                }

                $red = ord($data[$offset++] ?? "\0");
                $green = ord($data[$offset++] ?? "\0");
                $blue = ord($data[$offset++] ?? "\0");
                imagesetpixel($image, $x, $y, ($red << 16) | ($green << 8) | $blue);
            }

            if ($offset < strlen($data)) {
                throw new RuntimeException('A Blu-ray raw ID3 L-shape is too small.');
            }
        }
    }

    /** Finds the narrowest symmetric L-shape that can hold the ID3 tag. */
    private static function lArmThicknessForDataLength(int $size, int $length): int
    {
        $requiredPixels = (int)ceil($length / self::PAYLOAD_BYTES_PER_PIXEL);
        $maximumThickness = (int)floor($size / 2) - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX - self::CORNER_DATA_MARGIN_PX;

        for ($thickness = 1; $thickness <= $maximumThickness; $thickness++) {
            if (self::lCoordinateCount($size, $thickness) >= $requiredPixels) {
                return $thickness;
            }
        }

        throw new RuntimeException('The Blu-ray corner L-shape is too small for the raw ID3 tag.');
    }

    /** Counts the usable coordinates in the smallest of the four L-shapes. */
    private static function lCoordinateCount(int $size, int $armThickness): int
    {
        $counts = [];

        foreach (range(0, 3) as $cornerId) {
            $count = 0;

            foreach (self::cornerLCoordinates($size, $cornerId, $armThickness) as $_) {
                $count++;
            }

            $counts[] = $count;
        }

        return min($counts);
    }

    /**
     * Yields an L-shaped payload order with the bytes balanced between arms.
     *
     * Splitting the actual data between the horizontal and vertical arms keeps
     * short ID3 payloads visibly L-shaped without drawing artificial padding.
     *
     * @return iterable<array{0: int, 1: int}>
     */
    private static function cornerLCoordinatesForPayload(int $size, int $cornerId, int $armThickness, int $length): iterable
    {
        $requiredPixels = (int)ceil($length / self::PAYLOAD_BYTES_PER_PIXEL);
        $horizontalPixels = (int)ceil($requiredPixels / 2);
        $verticalPixels = $requiredPixels - $horizontalPixels;
        $markerEnd = self::CORNER_BLOCK_INSET_PX + self::CORNER_BLOCK_SIZE_PX + self::CORNER_DATA_MARGIN_PX;
        $horizontalCount = 0;

        foreach (self::cornerLCoordinates($size, $cornerId, $armThickness) as $coordinate) {
            [, $y] = self::unmirrorLCoordinate($size, $cornerId, $coordinate[0], $coordinate[1]);

            if ($y < $markerEnd + $armThickness && $horizontalCount < $horizontalPixels) {
                yield $coordinate;
                $horizontalCount++;
            }
        }

        foreach (self::cornerLCoordinates($size, $cornerId, $armThickness) as $coordinate) {
            [, $y] = self::unmirrorLCoordinate($size, $cornerId, $coordinate[0], $coordinate[1]);

            if ($y < $markerEnd + $armThickness) {
                continue;
            }

            if ($verticalPixels <= 0) {
                break;
            }

            yield $coordinate;
            $verticalPixels--;
        }
    }

    /**
     * Converts a mirrored L-shape coordinate back to canonical top-left space.
     *
     * @return array{0: int, 1: int}
     */
    private static function unmirrorLCoordinate(int $size, int $cornerId, int $x, int $y): array
    {
        if ($cornerId === 1 || $cornerId === 3) {
            $x = $size - 1 - $x;
        }

        if ($cornerId === 2 || $cornerId === 3) {
            $y = $size - 1 - $y;
        }

        return [$x, $y];
    }

    /** @return iterable<array{0: int, 1: int}> */
    private static function cornerLCoordinates(int $size, int $cornerId, int $armThickness): iterable
    {
        // Raw ID3 data occupies matching L-shaped areas outside the audio
        // circle. Mirroring one generated arm preserves visual symmetry while
        // keeping the data away from each corner metadata block.
        $margin = self::CORNER_BLOCK_INSET_PX;
        $markerEnd = $margin + self::CORNER_BLOCK_SIZE_PX + self::CORNER_DATA_MARGIN_PX;
        $center = $size / 2;
        $audioRadius = $center - self::AUDIO_RING_OUTER_MARGIN_PX + self::AUDIO_RING_L_SHAPE_CLEARANCE_PX;
        $edge = (int)floor($center);
        $seen = str_repeat("\0", intdiv($size * $size + 7, 8));

        for ($row = 0; $row < $armThickness; $row++) {
            $y = $markerEnd + $row;
            $distanceY = $y - $center;
            $distanceSquared = ($audioRadius ** 2) - ($distanceY ** 2);
            $circleX = $distanceSquared > 0 ? $center - sqrt($distanceSquared) : $center;

            for ($x = $markerEnd; $x < min($edge, (int)floor($circleX)); $x++) {
                $coordinate = self::mirrorLCoordinate($size, $cornerId, $x, $y, $center, $audioRadius);

                if ($coordinate !== null && self::markCoordinate($seen, $size, $coordinate[0], $coordinate[1])) {
                    yield $coordinate;
                }
            }
        }

        for ($column = 0; $column < $armThickness; $column++) {
            $x = $markerEnd + $column;
            $distanceX = $x - $center;
            $distanceSquared = ($audioRadius ** 2) - ($distanceX ** 2);
            $circleY = $distanceSquared > 0 ? $center - sqrt($distanceSquared) : $center;

            for ($y = $markerEnd; $y < min($edge, (int)floor($circleY)); $y++) {
                $coordinate = self::mirrorLCoordinate($size, $cornerId, $x, $y, $center, $audioRadius);

                if ($coordinate !== null && self::markCoordinate($seen, $size, $coordinate[0], $coordinate[1])) {
                    yield $coordinate;
                }
            }
        }
    }

    /**
     * Mirrors one canonical L-shape coordinate into the requested corner.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function mirrorLCoordinate(
        int $size,
        int $cornerId,
        int $x,
        int $y,
        float $center,
        float $audioRadius,
    ): ?array {
        if ($cornerId === 1 || $cornerId === 3) {
            $x = $size - 1 - $x;
        }

        if ($cornerId === 2 || $cornerId === 3) {
            $y = $size - 1 - $y;
        }

        if (hypot($x - $center, $y - $center) <= $audioRadius) {
            return null;
        }

        return [$x, $y];
    }

    /** Draws all four black bordered, identity-coded corner markers. */
    private static function drawCornerMarkers(GdImage $image, int $size): void
    {
        $corners = [
            [self::CORNER_BLOCK_INSET_PX, self::CORNER_BLOCK_INSET_PX],
            [$size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX, self::CORNER_BLOCK_INSET_PX],
            [self::CORNER_BLOCK_INSET_PX, $size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX],
            [$size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX, $size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX],
        ];

        foreach ($corners as $copyId => [$x, $y]) {
            self::drawCornerMarker($image, $x, $y, $copyId);
        }
    }

    private static function drawCornerMarker(GdImage $image, int $x, int $y, int $copyId): void
    {
        // The border gives rotation detection a strong signal. Two white
        // slots encode the low two bits of the corner's stable copy ID.
        $last = self::CORNER_BLOCK_SIZE_PX - 1;
        $border = self::CORNER_MARKER_BORDER_PX - 1;
        $black = 0x000000;
        $white = 0xFFFFFF;

        imagefilledrectangle($image, $x, $y, $x + $last, $y + $border, $black);
        imagefilledrectangle($image, $x, $y + $last - $border, $x + $last, $y + $last, $black);
        imagefilledrectangle($image, $x, $y, $x + $border, $y + $last, $black);
        imagefilledrectangle($image, $x + $last - $border, $y, $x + $last, $y + $last, $black);

        for ($bit = 0; $bit < 2; $bit++) {
            if (($copyId & (1 << $bit)) === 0) {
                continue;
            }

            $slotStart = $x + self::CORNER_DATA_MARGIN_PX + ($bit * 16);
            imagefilledrectangle($image, $slotStart, $y, $slotStart + 7, $y + $border, $white);
        }
    }

    /** Writes identical metadata copies into the four protected corner blocks. */
    private static function writeCornerCopies(
        GdImage $image,
        int $size,
        string $header,
        string $structuralData,
        string $fileMetadata,
    ): void
    {
        // Every corner stores the same metadata with a distinct copy ID.
        // The decoder can therefore reject a damaged or misidentified block
        // before using the remaining copies for majority voting.
        $copies = [
            [0, self::CORNER_BLOCK_INSET_PX],
            [1, $size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX],
            [2, self::CORNER_BLOCK_INSET_PX],
            [3, $size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX],
        ];

        foreach ($copies as [$copyId, $x]) {
            $y = $copyId < 2
                ? self::CORNER_BLOCK_INSET_PX
                : $size - self::CORNER_BLOCK_INSET_PX - self::CORNER_BLOCK_SIZE_PX;
            $payload = 'BLC1'
                . chr($copyId)
                . pack('N', strlen($header))
                . pack('N', strlen($structuralData))
                . pack('N', strlen($fileMetadata))
                . $header
                . $structuralData
                . $fileMetadata;
            $offset = 0;

            foreach (self::cornerCoordinates($size, $x, $y) as [$pixelX, $pixelY]) {
                if ($offset >= strlen($payload)) {
                    break;
                }

                $red = ord($payload[$offset++] ?? "\0");
                $green = ord($payload[$offset++] ?? "\0");
                $blue = ord($payload[$offset++] ?? "\0");
                imagesetpixel($image, $pixelX, $pixelY, ($red << 16) | ($green << 8) | $blue);
            }

            if ($offset < strlen($payload)) {
                throw new RuntimeException('A Blu-ray corner information block is too small.');
            }
        }
    }

    /**
     * Yields payload coordinates inside a corner block, excluding marker space.
     *
     * @return iterable<array{0: int, 1: int}>
     */
    private static function cornerCoordinates(int $size, int $x, int $y): iterable
    {
        // Keep a margin for the marker border and reserve the center of the
        // block so payload bytes cannot overwrite orientation evidence.
        $start = self::CORNER_DATA_MARGIN_PX;
        $end = self::CORNER_BLOCK_SIZE_PX - self::CORNER_DATA_MARGIN_PX;
        $center = (int)floor(self::CORNER_BLOCK_SIZE_PX / 2);

        for ($pixelY = $y + $start; $pixelY < $y + $end; $pixelY++) {
            for ($pixelX = $x + $start; $pixelX < $x + $end; $pixelX++) {
                $offsetX = abs($pixelX - ($x + $center));
                $offsetY = abs($pixelY - ($y + $center));

                if ($offsetX <= 1 && $offsetY <= 1) {
                    continue;
                }

                yield [$pixelX, $pixelY];
            }
        }
    }

    /** Reads the complete leading ID3v2 tag, or returns empty when absent. */
    private function rawId3Tag(): string
    {
        $handle = fopen($this->audioPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open audio file: $this->audioPath");
        }

        $prefix = fread($handle, 10);
        fclose($handle);

        if (!is_string($prefix) || strlen($prefix) !== 10 || substr($prefix, 0, 3) !== 'ID3') {
            return '';
        }

        $length = 10 + self::synchsafeInteger(substr($prefix, 6, 4));
        $handle = fopen($this->audioPath, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Unable to open audio file: $this->audioPath");
        }

        $data = fread($handle, max(1, $length));
        fclose($handle);

        if ($data === false || strlen($data) !== $length) {
            throw new RuntimeException('Unable to read the complete ID3v2 tag.');
        }

        return $data;
    }

    /** @return array{header: string, structural: string, metadata: string}|null */
    private function readMostCommonCornerCopy(GdImage $image, int $size): ?array
    {
        // A single readable copy is deliberately insufficient: without a
        // second vote, corrupted bytes can be mistaken for valid metadata.
        // Fingerprints compare complete copies, not just their headers.
        $groups = [];
        $readableCorners = 0;

        foreach (self::cornerOrigins($size) as $copyId => [$x, $y]) {
            try {
                $copy = $this->readCornerCopy($image, $size, $x, $y);

                if ($copy['copy_id'] !== $copyId) {
                    $this->logger->debug('Corner copy ID mismatch', ['corner' => $copyId, 'stored_id' => $copy['copy_id']]);
                    continue;
                }

                $readableCorners++;
                $fingerprint = hash('sha256', $copy['header'] . $copy['structural'] . $copy['metadata']);
                $groups[$fingerprint]['copies'][] = $copy;
                $groups[$fingerprint]['votes'] = ($groups[$fingerprint]['votes'] ?? 0) + 1;
            } catch (RuntimeException $e) {
                $this->logger->debug('Corner copy unreadable', ['corner' => $copyId, 'error' => $e->getMessage()]);
                continue;
            }
        }

        if ($readableCorners < 2) {
            $this->logger->warning('Insufficient readable corner copies', ['readable' => $readableCorners]);
            return null;
        }

        if ($groups === []) {
            return null;
        }

        uasort($groups, static fn (array $left, array $right): int => $right['votes'] <=> $left['votes']);
        $groups = array_values($groups);

        $bestVotes = $groups[0]['votes'];
        $secondBestVotes = $groups[1]['votes'] ?? 0;

        if ($bestVotes < 2) {
            $this->logger->warning('Best corner copy has fewer than 2 votes', ['votes' => $bestVotes]);
            return null;
        }

        if ($bestVotes - $secondBestVotes <= self::MIN_CORNER_VOTE_MARGIN) {
            $this->logger->warning('Corner copy votes are tied or too close', ['best' => $bestVotes, 'second_best' => $secondBestVotes]);
            return null;
        }

        $copy = $groups[0]['copies'][0];

        return [
                    'header' => $copy['header'],
                    'structural' => $copy['structural'],
                    'metadata' => $copy['metadata'],
        ];
    }

    /**
     * Reads and bounds-checks one corner backup payload.
     *
     * @return array{copy_id: int, header: string, structural: string, metadata: string}
     */
    private function readCornerCopy(GdImage $image, int $size, int $x, int $y): array
    {
        $prefix = $this->readCornerBytes($image, $size, $x, $y, self::CORNER_PREFIX_LENGTH_BYTES);

        if (substr($prefix, 0, 4) !== 'BLC1') {
            throw new RuntimeException('Invalid Blu-ray corner marker data.');
        }

        $lengths = unpack('Nheader/Nstructural/Nmetadata', substr($prefix, 5, 12));

        if ($lengths === false) {
            throw new RuntimeException('Invalid Blu-ray corner marker lengths.');
        }

        // Lengths come from the image and must be bounded before they are used
        // to allocate/read a payload. This protects the fallback path from
        // treating damaged length bytes as an arbitrarily large block.
        $totalLength = self::CORNER_PREFIX_LENGTH_BYTES + $lengths['header'] + $lengths['structural'] + $lengths['metadata'];
        $maximumLength = (self::CORNER_BLOCK_SIZE_PX - (2 * self::CORNER_DATA_MARGIN_PX)) ** 2 * self::PAYLOAD_BYTES_PER_PIXEL;

        if ($totalLength > $maximumLength || $totalLength < self::CORNER_PREFIX_LENGTH_BYTES) {
            throw new RuntimeException('Blu-ray corner marker data exceeds its block.');
        }

        $payload = $this->readCornerBytes($image, $size, $x, $y, $totalLength);
        $offset = self::CORNER_PREFIX_LENGTH_BYTES;
        $headerLength = $lengths['header'];
        $structuralLength = $lengths['structural'];

        return [
            'copy_id' => ord($prefix[4]),
            'header' => substr($payload, $offset, $headerLength),
            'structural' => substr($payload, $offset + $headerLength, $structuralLength),
            'metadata' => substr($payload, $offset + $headerLength + $structuralLength, $lengths['metadata']),
        ];
    }

    /**
     * Returns corner block origins in stable copy-ID order.
     *
     * @return array<int, array{0: int, 1: int}>
     */
    private static function cornerOrigins(int $size): array
    {
        $offset = self::CORNER_BLOCK_INSET_PX;
        $last = $size - $offset - self::CORNER_BLOCK_SIZE_PX;

        return [
            [$offset, $offset],
            [$last, $offset],
            [$offset, $last],
            [$last, $last],
        ];
    }

    /** Reads a bounded RGB byte stream from one corner's payload area. */
    private function readCornerBytes(GdImage $image, int $size, int $x, int $y, int $length): string
    {
        $data = '';

        foreach ($this->readCornerCoordinates($size, $x, $y) as [$pixelX, $pixelY]) {
            $rgb = imagecolorat($image, $pixelX, $pixelY);
            $data .= chr(($rgb >> 16) & 0xFF);
            $data .= chr(($rgb >> 8) & 0xFF);
            $data .= chr($rgb & 0xFF);

            if (strlen($data) >= $length) {
                return substr($data, 0, $length);
            }
        }

        throw new RuntimeException('A Blu-ray corner information block is incomplete.');
    }

    /**
     * Applies detected rotation to canonical corner payload coordinates.
     *
     * @return iterable<array{0: int, 1: int}>
     */
    private function readCornerCoordinates(int $size, int $x, int $y): iterable
    {
        foreach (self::cornerCoordinates($size, $x, $y) as [$pixelX, $pixelY]) {
            yield $this->transformCoordinate($size, $pixelX, $pixelY);
        }
    }

    /** Selects the highest-scoring half-degree rotation from marker evidence. */
    private function detectRotationAngle(GdImage $image, int $size): float
    {
        // Search half-degree increments across a full turn. Each candidate is
        // scored against all marker borders and identity slots; combining all
        // corners makes one damaged marker less likely to determine rotation.
        $bestScore = -PHP_INT_MAX;
        $bestAngle = 0.0;
        $secondBestScore = -PHP_INT_MAX;

        for ($step = 0; $step < 720; $step++) {
            $angle = $step * M_PI / 360;
            $score = 0;

            foreach (self::cornerOrigins($size) as $copyId => [$x, $y]) {
                $last = self::CORNER_BLOCK_SIZE_PX - 1;
                $border = self::CORNER_MARKER_BORDER_PX - 1;
                $borderPixels = [];

                for ($offset = 0; $offset <= $last; $offset++) {
                    $borderPixels[] = [$x + $offset, $y + $border];
                    $borderPixels[] = [$x + $offset, $y + $last - $border];
                    $borderPixels[] = [$x + $border, $y + $offset];
                    $borderPixels[] = [$x + $last - $border, $y + $offset];
                }

                foreach ($borderPixels as [$pixelX, $pixelY]) {
                    [$sampleX, $sampleY] = self::rotateCoordinate($size, $pixelX, $pixelY, $angle);

                    if ($sampleX < 0 || $sampleX >= $size || $sampleY < 0 || $sampleY >= $size) {
                        $score--;
                        continue;
                    }

                    $rgb = imagecolorat($image, $sampleX, $sampleY);
                    $isDark = (($rgb >> 16) & 0xFF) < 96
                        && (($rgb >> 8) & 0xFF) < 96
                        && ($rgb & 0xFF) < 96;
                    $score += $isDark ? 1 : -1;
                }

                for ($bit = 0; $bit < 2; $bit++) {
                    $slotStart = $x + self::CORNER_DATA_MARGIN_PX + ($bit * 16);
                    [$sampleX, $sampleY] = self::rotateCoordinate($size, $slotStart + 3, $y + $border, $angle);

                    if ($sampleX < 0 || $sampleX >= $size || $sampleY < 0 || $sampleY >= $size) {
                        $score -= 4;
                        continue;
                    }

                    $rgb = imagecolorat($image, $sampleX, $sampleY);
                    $isLight = (($rgb >> 16) & 0xFF) > 160
                        && (($rgb >> 8) & 0xFF) > 160
                        && ($rgb & 0xFF) > 160;
                    $expectedLight = ($copyId & (1 << $bit)) !== 0;
                    $score += $isLight === $expectedLight ? 4 : -4;
                }
            }

            if ($score > $bestScore) {
                $secondBestScore = $bestScore;
                $bestScore = $score;
                $bestAngle = $angle;
            } elseif ($score > $secondBestScore) {
                $secondBestScore = $score;
            }
        }

        // Low confidence is logged for diagnostics, but the existing format
        // remains readable when marker evidence is imperfect.
        if ($bestScore < self::MIN_ROTATION_SCORE) {
            $this->logger->warning('Low rotation detection confidence', ['best_score' => $bestScore, 'threshold' => self::MIN_ROTATION_SCORE]);
        }

        if ($bestScore - $secondBestScore < self::MIN_ROTATION_SCORE) {
            $this->logger->warning('Rotation detection ambiguous', ['best' => $bestScore, 'second_best' => $secondBestScore, 'margin' => $bestScore - $secondBestScore]);
        }

        return $bestAngle;
    }

    /**
     * Transforms a canonical coordinate using the angle detected for the image.
     *
     * @return array{0: int, 1: int}
     */
    private function transformCoordinate(int $size, int $x, int $y): array
    {
        return self::rotateCoordinate($size, $x, $y, $this->rotationAngle);
    }

    /** @return array{0: int, 1: int} */
    private static function rotateCoordinate(int $size, int $x, int $y, float $angle): array
    {
        // Coordinates are rotated around the pixel-grid center. Sampling uses
        // nearest-neighbor rounding because the encoded data lives in pixels,
        // not interpolated image content.
        $center = ($size - 1) / 2;
        $relativeX = $x - $center;
        $relativeY = $y - $center;
        $cosine = cos($angle);
        $sine = sin($angle);

        return [
            (int)round($center + ($relativeX * $cosine) - ($relativeY * $sine)),
            (int)round($center + ($relativeX * $sine) + ($relativeY * $cosine)),
        ];
    }

    /** Describes the ID3v2 and MPEG-frame sections of the source MP3. */
    private static function structuralData(string $audioPath, int $length): string
    {
        $leadingTagLength = 0;
        $handle = fopen($audioPath, 'rb');

        if ($handle !== false) {
            $prefix = fread($handle, 10);
            fclose($handle);

            if (is_string($prefix) && substr($prefix, 0, 3) === 'ID3' && strlen($prefix) === 10) {
                $leadingTagLength = 10 + self::synchsafeInteger(substr($prefix, 6, 4));
            }
        }

        return json_encode([
            'source_format' => 'mp3',
            'sections' => [
                ['type' => 'id3v2', 'file_offset' => 0, 'length' => $leadingTagLength],
                ['type' => 'mpeg_frames', 'file_offset' => $leadingTagLength, 'length' => max(0, $length - $leadingTagLength)],
            ],
        ], JSON_THROW_ON_ERROR);
    }

    /** Decodes the seven-bit-per-byte integer used by ID3v2 sizes. */
    private static function synchsafeInteger(string $bytes): int
    {
        $value = 0;

        for ($index = 0; $index < strlen($bytes); $index++) {
            $value = ($value << 7) | (ord($bytes[$index]) & 0x7F);
        }

        return $value;
    }

    /**
     * Extracts optional ID3 and technical metadata using getID3.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>}
     */
    private static function readAudioMetadata(string $audioPath): array
    {
        $metadata = ['title' => '', 'artist' => '', 'album' => '', 'year' => '', 'technical' => []];

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

    /** Computes the source MP3 SHA-256 digest for the format header. */
    private function hashAudioFile(string $audioPath): string
    {
        $hash = hash_file('sha256', $audioPath, true);

        if ($hash === false) {
            throw new RuntimeException("Unable to hash audio file: $audioPath");
        }

        return $hash;
    }
}
