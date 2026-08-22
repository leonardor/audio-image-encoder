<?php

declare(strict_types=1);

namespace CdEncoder\Application\Services;

use CdEncoder\Application\Contracts\EncoderInterface;
use Psr\Log\LoggerInterface;

class CdEncoder implements EncoderInterface
{
    public const PROFILE_STANDARD = 'standard';
    public const PROFILE_DIGITAL_MAX = 'digital_max';

    /*
    * ============================================================
    * MP3 DISC - LOSSLESS WEBP / RGB 24-BIT
    * ============================================================
    *
    * FORMAT:
    *
    *   MP3 bytes
    *       |
    *       v
    *   HEADER
    *       |
    *       v
    *   SHA-256
    *       |
    *       v
    *   3 bytes -> 1 RGB color
    *       |
    *       v
    *   spiral mapping
    *       |
    *       v
    *   120 mm circular image
    *       |
    *       v
    *   WebP LOSSLESS
    *
    *
    * IMPORTANT:
    *
    * This is an experimental visual storage format.
    *
    * It is not CD-DA.
    * It cannot be played directly by a CD player.
    *
    * The current version is digitally reversible:
    *
    *     MP3 -> WebP -> MP3
    *
    * with SHA-256 verification.
    *
    * For:
    *
    *     WebP -> print -> image -> MP3
    *
    * an error-correction and geometric-calibration system
    * must be added later.
    *
    * Maximum capacity at 600 DPI and RGB 24-bit: 2,185,995 bytes (about 2.09 MiB).
    *
    * ============================================================
    */


    // ============================================================
    // PHYSICAL CONFIGURATION
    // ============================================================

    private const DISC_DIAMETER_MM = 120.0;

    private const CENTER_X_MM = 60.0;
    private const CENTER_Y_MM = 60.0;

    private const HOLE_DIAMETER_MM = 8.0;
    private const MARKER_DIAMETER_MM = 0.5;


    // ============================================================
    // IMAGE RESOLUTION
    // ============================================================
    //
    // 600 DPI:
    //
    // 120 mm ≈ 2835 pixels
    //
    // 1200 DPI:
    //
    // 120 mm ≈ 5669 pixels
    //
    // 1200 DPI may be useful for large MP3 files.
    //

    private const DEFAULT_DPI = 600;
    private const DIGITAL_MAX_DPI = 1200;


    // ============================================================
    // DATA AREA
    // ============================================================
    //
    // We do not use the exact center or edge.
    // Space is reserved for markers.
    //

    private const DATA_RADIUS_START_MM = 9;
    private const  DATA_RADIUS_START_HEADER = 8.5;
    private const  DATA_RADIUS_START_MARKER = 58.0;

    private const DATA_RADIUS_END_MM = 100.0;


    // ============================================================
    // SPIRAL
    // ============================================================
    //
    // A payload pixel stores three bytes using an RGB 8-8-8 color.
    //
    // Pixels are read along a spiral.
    //

    private const SPIRAL_PITCH_MM = 0.06;

    private const ANGLE_STEP = 0.007;


    // ============================================================
    // HEADER
    // ============================================================
    //
    // The header is stored in a dedicated ring.
    //
    // Structure:
    //
    // MAGIC       8 bytes
    // VERSION     1 byte
    // DPI         4 bytes
    // WIDTH       4 bytes
    // HEIGHT      4 bytes
    // FILE SIZE   8 bytes
    // SHA256      32 bytes
    // AUDIO METADATA 512 bytes (4 x 128 bytes)
    //
    // ============================================================

    private const MAGIC = 'MP3DISC1';

    private const FORMAT_VERSION = 7;

    private const METADATA_FIELD_LENGTH = 128;

    /** @var array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>} */
    private array $metadata = [
        'title' => '',
        'artist' => '',
        'album' => '',
        'year' => '',
        'technical' => [],
        'encoding' => [],
    ];

    // XMP encoding values override these defaults during decoding.
    /** @var array<string, int|float|string> */
    private array $decodingConfiguration = [];

    private string $audioPath = '';

    private string $imagePath = '';

    private string $profile = self::PROFILE_STANDARD;

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
            throw new \InvalidArgumentException("Cannot read MP3: $audioPath");
        }

        $length = filesize($audioPath);

        if ($length === false) {
            throw new \RuntimeException("Unable to determine MP3 file size: $audioPath");
        }

        $size = self::mmToPx(self::DISC_DIAMETER_MM, self::DEFAULT_DPI);

        return $length > self::calculateCapacity($size, $size);
    }

    public function prepare(string $audioPath, string $imagePath, string $profile = self::PROFILE_STANDARD): void
    {
        $this->audioPath = $audioPath;
        $this->imagePath = $imagePath;
        $this->profile = self::normalizeProfile($profile);
        $this->decodingConfiguration = self::encodingConfiguration();
    }

    public function encode(): bool
    {
        $this->logger->info('Encoding file {audioPath} using profile {profile} into image {imagePath}...', [
            'audioPath' => $this->audioPath,
            'profile' => $this->profile,
            'imagePath' => $this->imagePath,
        ]);

        if (!is_readable($this->audioPath)) {
            throw new \InvalidArgumentException("Cannot read MP3: $this->audioPath");
        }

        if (!extension_loaded('gd')) {
            throw new \RuntimeException('The GD extension is not installed.');
        }

        $rawAudioData = file_get_contents($this->audioPath);

        if ($rawAudioData === false) {
            throw new \RuntimeException('Unable to read the audio file contents.');
        }

        $sha256 = hash('sha256', $rawAudioData, true);

        $configuration = $this->encodingConfigurationForProfile();
        $payloadData = $rawAudioData;
        $dataLength = strlen($payloadData);

        $metadata = self::readMp3Metadata();
        $this->metadata = array_merge($metadata, ['encoding' => $configuration]);

        if (!defined('IMG_WEBP_LOSSLESS')) {
            throw new \RuntimeException('PHP/GD does not support IMG_WEBP_LOSSLESS. Use PHP 8.1+ with GD and WebP.');
        }

        $dpi = (int)$configuration['default_dpi'];

        // --------------------------------------------------------
        // DIMENSIUNI
        // --------------------------------------------------------

        $size = self::mmToPx(self::DISC_DIAMETER_MM, $dpi);

        if ($size < 1) {
            throw new \RuntimeException('The calculated image size is invalid.');
        }

        $this->logger->info('Image: info...', ['width' => $size, 'height' => $size]);

        // --------------------------------------------------------
        // IMAGE
        // --------------------------------------------------------

        $image = imagecreatetruecolor($size, $size);

        if (!$image) {
            throw new \RuntimeException('Unable to create the image.');
        }

        // --------------------------------------------------------
        // BACKGROUND
        // --------------------------------------------------------

        $white = self::allocateColor($image, 255, 255, 255);

        imagefill($image, 0, 0, $white);

        // --------------------------------------------------------
        // PIXELS / MM
        // --------------------------------------------------------

        $pixelsPerMm = $size / self::DISC_DIAMETER_MM;

        // --------------------------------------------------------
        // HEADER
        // --------------------------------------------------------

        $header = self::createHeader($dataLength, $sha256, $metadata, $size, $size, $dpi);

        /*
        * Each header byte is stored in one pixel.
        */

        $unpackedHeader = unpack('C*', $header);

        if ($unpackedHeader === false) {
            imagedestroy($image);

            throw new \RuntimeException('Unable to unpack the image header.');
        }

        $headerBytes = array_values($unpackedHeader);

        // --------------------------------------------------------
        // HEADER RING
        // --------------------------------------------------------

        $headerRadius = self::DATA_RADIUS_START_HEADER;

        $headerCount = count($headerBytes);

        foreach ($headerBytes as $i => $byte) {
            $angle = -M_PI / 2 + 2 * M_PI * $i / $headerCount;

            [$xMm, $yMm] = self::polar($headerRadius, $angle);

            $x = (int)round($xMm * $pixelsPerMm);
            $y = (int)round($yMm * $pixelsPerMm);

            [$r, $g, $b] = self::paletteColor($byte);

            $color = self::allocateColor($image, $r, $g, $b);

            /*
            * One pixel per header byte keeps the expanded header ring compact.
            */

            imagesetpixel($image, $x, $y, $color);
        }

        // --------------------------------------------------------
        // PAYLOAD
        // --------------------------------------------------------

        $this->logger->info('Encoding bytes...', ['bytes' => number_format($dataLength)]);

        $capacity = self::calculateCapacity($size, $size);

        if ($dataLength > $capacity) {
            imagedestroy($image);

            throw new \RuntimeException(
                "The MP3 does not fit in the image. Capacity: {$capacity} bytes; received: {$dataLength} bytes."
            );
        }

        $payloadPixels = (int)ceil($dataLength / 3);

        for ($i = 0; $i < $payloadPixels; $i++) {
            [$radius, $angle] = self::spiralPosition($i);

            //if ($radius > self::DATA_RADIUS_END_MM) {
            //    imagedestroy($image);

            //    throw new \RuntimeException("The MP3 does not fit on the disc.");
            //}

            [$xMm, $yMm] = self::polar($radius, $angle);

            $x = (int)round($xMm * $pixelsPerMm);
            $y = (int)round($yMm * $pixelsPerMm);

            $byteData = substr($payloadData, $i * 3, 3);

            $byteData = str_pad($byteData, 3, "\0");

            $bytes = unpack('C3', $byteData);

            if ($bytes === false) {
                imagedestroy($image);

                throw new \RuntimeException('Unable to unpack audio payload.');
            }

            $value = ($bytes[1] << 16) | ($bytes[2] << 8) | $bytes[3];

            [$r, $g, $b] = self::payloadColor($value);

            $color = self::allocateColor($image, $r, $g, $b);

            imagesetpixel($image, $x, $y, $color);
        }

        // --------------------------------------------------------
        // ORIENTATION MARKERS
        // --------------------------------------------------------

        /*
        * Four large markers.
        */

        $black = self::allocateColor($image, 10, 10, 10);

        $markerRadiusMm = self::MARKER_DIAMETER_MM / 2;

        foreach (
            [
                [self::CENTER_X_MM, self::CENTER_Y_MM - self::DATA_RADIUS_START_MARKER],
                [self::CENTER_X_MM + self::DATA_RADIUS_START_MARKER, self::CENTER_Y_MM],
                [self::CENTER_X_MM, self::CENTER_Y_MM + self::DATA_RADIUS_START_MARKER],
                [self::CENTER_X_MM - self::DATA_RADIUS_START_MARKER, self::CENTER_Y_MM],
            ] as [$xMm, $yMm]
        ) {
            $x = (int)round($xMm * $pixelsPerMm);
            $y = (int)round($yMm * $pixelsPerMm);
            $r = (int)round($markerRadiusMm * $pixelsPerMm);

            imagefilledellipse($image, $x, $y, $r * 2, $r * 2, $black);
        }

        // --------------------------------------------------------
        // CENTER HOLE
        // --------------------------------------------------------
        /*
        * Make it white.
        */
        $holeRadius = (int)round(self::HOLE_DIAMETER_MM / 2 * $pixelsPerMm);

        imagefilledellipse(
            $image,
            (int)round(self::CENTER_X_MM * $pixelsPerMm),
            (int)round(self::CENTER_Y_MM * $pixelsPerMm),
            $holeRadius * 2,
            $holeRadius * 2,
            $white
        );

        // --------------------------------------------------------
        // SAVE LOSSLESS WEBP
        // --------------------------------------------------------

        $this->logger->info('Writing lossless WebP to {imagePath}...', ['imagePath' => $this->imagePath]);

        $ok = imagewebp($image, $this->imagePath, IMG_WEBP_LOSSLESS);

        imagedestroy($image);

        if (!$ok) {
            throw new \RuntimeException("Unable to save WebP to file $this->imagePath.");
        }

        self::writeXmpMetadata($this->imagePath, $metadata, $configuration);

        $this->logger->info('Encoding completed.', [
            'output' => $this->imagePath,
            'diameter_mm' => 120,
            'dpi' => $dpi,
            'bytes_encoded' => $dataLength,
            'sha256' => bin2hex($sha256),
        ]);

        return true;
    }

    public function decode(): bool
    {
        $this->logger->info('Decoding file {imagePath} into audio {audioPath}...', [
            'imagePath' => $this->imagePath,
            'audioPath' => $this->audioPath,
        ]);

        if (!is_readable($this->imagePath)) {
            throw new \InvalidArgumentException("Cannot read WebP: $this->imagePath");
        }

        $xmpMetadata = self::readXmpMetadata($this->imagePath);

        $this->metadata = array_merge($this->metadata, $xmpMetadata);

        $this->decodingConfiguration = array_merge(
            self::encodingConfiguration(),
            is_array($this->metadata['encoding'] ?? null) ? $this->metadata['encoding'] : []
        );

        if (!extension_loaded('gd')) {
            throw new \RuntimeException('GD is required.');
        }

        $image = imagecreatefromwebp($this->imagePath);

        if (!$image) {
            throw new \RuntimeException('Unable to open the WebP image.');
        }

        $width = imagesx($image);
        $height = imagesy($image);

        $this->logger->info('Image info...', ['width' => $width, 'height' => $height]);

        $pixelsPerMm = $width / (float)$this->decodingConfiguration['disc_diameter_mm'];

        // --------------------------------------------------------
        // READ HEADER
        // --------------------------------------------------------

        $headerLength = self::headerSize();
        $headerBytes = '';
        $headerRadius = (float)$this->decodingConfiguration['data_radius_start_header_mm'];

        for ($i = 0; $i < $headerLength; $i++) {
            $angle = -M_PI / 2 + 2 * M_PI * $i / $headerLength;

            [$xMm, $yMm] = $this->polarForDecode($headerRadius, $angle);

            $x = (int)round($xMm * $pixelsPerMm);
            $y = (int)round($yMm * $pixelsPerMm);

            $headerBytes .= chr(self::readPixelByte($image, $x, $y));
        }

        // --------------------------------------------------------
        // PARSE HEADER
        // --------------------------------------------------------

        $offset = 0;

        $magic = substr($headerBytes, $offset, 8);

        $offset += 8;

        if ($magic !== self::MAGIC) {
            imagedestroy($image);

            throw new \RuntimeException('MAGIC invalid.');
        }

        $version = ord($headerBytes[$offset]);

        $offset++;

        if ($version !== self::FORMAT_VERSION) {
            imagedestroy($image);

            throw new \RuntimeException('Incompatible format version.');
        }

        $unpackedDpi = unpack('N', substr($headerBytes, $offset, 4));

        if ($unpackedDpi === false) {
            imagedestroy($image);

            throw new \RuntimeException('Unable to read the image DPI.');
        }

        $dpi = $unpackedDpi[1];

        $offset += 4;

        $unpackedWidth = unpack('N', substr($headerBytes, $offset, 4));

        if ($unpackedWidth === false) {
            imagedestroy($image);

            throw new \RuntimeException('Unable to read the stored image width.');
        }

        $storedWidth = $unpackedWidth[1];

        $offset += 4;

        $unpackedHeight = unpack('N', substr($headerBytes, $offset, 4));

        if ($unpackedHeight === false) {
            imagedestroy($image);

            throw new \RuntimeException('Unable to read the stored image height.');
        }

        $storedHeight = $unpackedHeight[1];

        $offset += 4;

        $fileSize = self::unpackUint64(substr($headerBytes, $offset, 8));

        $offset += 8;

        $expectedSha = substr($headerBytes, $offset, 32);

        $offset += 32;

        $metadata = $this->metadata;

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $metadata[$field] = rtrim(substr($headerBytes, $offset, self::METADATA_FIELD_LENGTH), "\0");
            $offset += self::METADATA_FIELD_LENGTH;
        }

        $this->metadata = $metadata;

        if ($fileSize > self::calculateCapacity($width, $height)) {
            imagedestroy($image);

            throw new \RuntimeException('The payload exceeds the image capacity.');
        }

        $this->logger->info('Decoded metadata.', [
            'dpi' => $dpi,
            'image' => $storedWidth . 'x' . $storedHeight,
            'bytes' => $fileSize,
            'title' => $metadata['title'],
            'artist' => $metadata['artist'],
            'album' => $metadata['album'],
            'year' => $metadata['year'],
            'encoding' => $metadata['encoding'],
        ]);

        // --------------------------------------------------------
        // READ PAYLOAD
        // --------------------------------------------------------

        $data = '';

        $width  = imagesx($image);
        $height = imagesy($image);

        $payloadPixels = (int)ceil($fileSize / 3);

        for ($i = 0; $i < $payloadPixels; $i++) {
            [$radius, $angle] = $this->spiralPositionForDecode($i);

            [$xMm, $yMm] = $this->polarForDecode($radius, $angle);

            $x = (int)round($xMm * $pixelsPerMm);
            $y = (int)round($yMm * $pixelsPerMm);

            $value = self::readPixelValue($image, $x, $y);

            $data .= pack(
                'C3',
                ($value >> 16) & 0xFF,
                ($value >> 8) & 0xFF,
                $value & 0xFF
            );
        }

        $data = substr($data, 0, $fileSize);

        imagedestroy($image);

        // --------------------------------------------------------
        // VERIFICATION
        // --------------------------------------------------------

        $actualSha = hash('sha256', $data, true);

        if (!hash_equals($expectedSha, $actualSha)) {
            throw new \RuntimeException('SHA-256 mismatch.');
        }

        // --------------------------------------------------------
        // SAVE
        // --------------------------------------------------------

        file_put_contents($this->audioPath, $data);

        $this->logger->info('Decoding completed.', [
            'recovered' => $this->audioPath,
            'sha256' => hash('sha256', $data),
        ]);

        return true;
    }

    private static function readPixelByte(\GdImage $image, int $x, int $y): int
    {
        $rgb = @imagecolorat($image, $x, $y);

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return self::colorToByte($r, $g, $b);
    }

    private static function readPixelValue(\GdImage $image, int $x, int $y): int
    {
        $rgb = @imagecolorat($image, $x, $y);

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return self::colorToValue($r, $g, $b);
    }

    private static function allocateColor(\GdImage $image, int $red, int $green, int $blue): int
    {
        if ($red < 0 || $red > 255 || $green < 0 || $green > 255 || $blue < 0 || $blue > 255) {
            throw new \InvalidArgumentException('Image color values must be between 0 and 255.');
        }

        $color = imagecolorallocate($image, $red, $green, $blue);

        if ($color === false) {
            throw new \RuntimeException('Unable to allocate an image color.');
        }

        return $color;
    }

    // ============================================================
    // PALETTE
    // ============================================================
    //
    // Header bytes use a 256-color palette.
    //
    // Important:
    //
    // We build an 8 x 8 x 4 = 256-color palette for header bytes.
    //
    // R = 8 levels
    // G = 8 levels
    // B = 4 levels
    //
    // 8 * 8 * 4 = 256
    //
    // Each header color maps exactly to one byte.
    //
    /** @return array{int, int, int} */
    private static function paletteColor(int $value): array
    {
        $rIndex = ($value >> 5) & 0x07;
        $gIndex = ($value >> 2) & 0x07;
        $bIndex = $value & 0x03;

        $r = (int)round($rIndex * 255 / 7);
        $g = (int)round($gIndex * 255 / 7);
        $b = (int)round($bIndex * 255 / 3);

        return [$r, $g, $b];
    }

    // ============================================================
    // PALETTE REVERSE MAPPING
    // ============================================================
    //
    // Convert RGB back to a byte.
    //

    private static function colorToByte(int $r, int $g, int $b): int
    {
        $rIndex = (int)round($r * 7 / 255);
        $gIndex = (int)round($g * 7 / 255);
        $bIndex = (int)round($b * 3 / 255);

        return($rIndex << 5) | ($gIndex << 2) | $bIndex;
    }

    /** @return array{int, int, int} */
    private static function payloadColor(int $value): array
    {
        $r = ($value >> 16) & 0xFF;
        $g = ($value >> 8) & 0xFF;
        $b = $value & 0xFF;

        return [$r, $g, $b];
    }

    private static function colorToValue(int $r, int $g, int $b): int
    {
        return ($r << 16) | ($g << 8) | $b;
    }

    // ============================================================
    // UTILITIES
    // ============================================================

    private static function mmToPx(float $mm, int $dpi): int
    {
        return max(1, (int)round($mm / 25.4 * $dpi));
    }

    /** @return array{float, float} */
    private static function polar(float $radius, float $angle): array
    {
        return [self::CENTER_X_MM + $radius * cos($angle), self::CENTER_Y_MM + $radius * sin($angle)];
    }

    /** @return array{float, float} */
    private function polarForDecode(float $radius, float $angle): array
    {
        return [
            (float)$this->decodingConfiguration['center_x_mm'] + $radius * cos($angle),
            (float)$this->decodingConfiguration['center_y_mm'] + $radius * sin($angle),
        ];
    }

    // ============================================================
    // SPIRAL PIXEL POSITION
    // ============================================================

    /** @return array{float, float} */
    private static function spiralPosition(int $index): array
    {
        $angle = $index * self::ANGLE_STEP;
        $radius = self::DATA_RADIUS_START_MM + self::SPIRAL_PITCH_MM * $angle / (2 * M_PI);

        return [$radius, $angle];
    }

    /** @return array{float, float} */
    private function spiralPositionForDecode(int $index): array
    {
        $angle = $index * (float)$this->decodingConfiguration['angle_step'];
        $radius = (float)$this->decodingConfiguration['data_radius_start_mm']
            + (float)$this->decodingConfiguration['spiral_pitch_mm'] * $angle / (2 * M_PI);

        return [$radius, $angle];
    }

    // ============================================================
    // CAPACITY
    // ============================================================

    private static function calculateCapacity(int $width, int $height): int
    {
        $pixelsPerMm = min($width, $height) / self::DISC_DIAMETER_MM;
        $imageRadiusMm = min($width, $height) / 2 / $pixelsPerMm;
        $markerRadiusMm = self::DATA_RADIUS_START_MARKER;
        $markerClearanceMm = self::MARKER_DIAMETER_MM / 2;
        $dataRadiusEndMm = min(
            self::DATA_RADIUS_END_MM,
            $imageRadiusMm - 1 / $pixelsPerMm,
            $markerRadiusMm - $markerClearanceMm - 1 / $pixelsPerMm
        );

        /*
        * Theoretical spiral-based limit.
        */

        if ($dataRadiusEndMm <= self::DATA_RADIUS_START_MM) {
            return 0;
        }

        $turns = ($dataRadiusEndMm - self::DATA_RADIUS_START_MM) / self::SPIRAL_PITCH_MM;
        $totalAngle = 2 * M_PI * $turns;
        $spiralPixels = (int)floor($totalAngle / self::ANGLE_STEP);

        return $spiralPixels * 3;
    }

    // ============================================================
    // HEADER
    // ============================================================

    /** @param array<string, mixed> $metadata */
    private static function createHeader(int $dataLength, string $sha256, array $metadata, int $width, int $height, int $dpi): string
    {
        /*
        * Header layout:
        *
        * MAGIC       8
        * VERSION     1
        * DPI         4
        * WIDTH       4
        * HEIGHT      4
        * FILE SIZE   8
    // AUDIO METADATA 512 bytes (4 x 128 bytes)
        *
        * AUDIO METADATA   512 bytes
        * TOTAL            573 bytes
        */

        // XMP encoding values override these defaults during decoding.

        $header = self::MAGIC . chr(self::FORMAT_VERSION) . pack('N', $dpi) . pack('N', $width) . pack('N', $height) . self::packUint64($dataLength) . $sha256;

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $value = (string)($metadata[$field] ?? '');

            if (strlen($value) > self::METADATA_FIELD_LENGTH) {
                $value = substr($value, 0, self::METADATA_FIELD_LENGTH);
            }

            $header .= str_pad($value, self::METADATA_FIELD_LENGTH, "\0");
        }

        return $header;
    }

    private static function normalizeProfile(string $profile): string
    {
        return in_array($profile, [self::PROFILE_STANDARD, self::PROFILE_DIGITAL_MAX], true)
            ? $profile
            : self::PROFILE_STANDARD;
    }

    /** @return array<string, int|float|string> */
    private function encodingConfigurationForProfile(): array
    {
        $configuration = self::encodingConfiguration();

        if ($this->profile === self::PROFILE_DIGITAL_MAX) {
            $configuration['default_dpi'] = self::DIGITAL_MAX_DPI;
            $configuration['profile'] = self::PROFILE_DIGITAL_MAX;

            return $configuration;
        }

        $configuration['profile'] = self::PROFILE_STANDARD;

        return $configuration;
    }

    /** @return array<string, int|float|string> */
    private static function encodingConfiguration(): array
    {
        return [
            'format_version' => self::FORMAT_VERSION,
            'default_dpi' => self::DEFAULT_DPI,
            'disc_diameter_mm' => self::DISC_DIAMETER_MM,
            'center_x_mm' => self::CENTER_X_MM,
            'center_y_mm' => self::CENTER_Y_MM,
            'hole_diameter_mm' => self::HOLE_DIAMETER_MM,
            'marker_diameter_mm' => self::MARKER_DIAMETER_MM,
            'data_radius_start_mm' => self::DATA_RADIUS_START_MM,
            'data_radius_start_header_mm' => self::DATA_RADIUS_START_HEADER,
            'data_radius_start_marker_mm' => self::DATA_RADIUS_START_MARKER,
            'data_radius_end_mm' => self::DATA_RADIUS_END_MM,
            'spiral_pitch_mm' => self::SPIRAL_PITCH_MM,
            'angle_step' => self::ANGLE_STEP,
            'payload_bytes_per_pixel' => 3,
            'metadata_field_length' => self::METADATA_FIELD_LENGTH,
        ];
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<string, int|float|string> $encodingConfiguration
     */
    private static function writeXmpMetadata(string $imagePath, array $metadata, array $encodingConfiguration): void
    {
        $encoding = json_encode($encodingConfiguration, JSON_THROW_ON_ERROR);
        $xmp = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>'
            . '<x:xmpmeta xmlns:x="adobe:ns:meta/">'
            . '<rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">'
            . '<rdf:Description xmlns:cd="https://cdencoder.local/ns/1.0/"'
            . ' cd:title="' . self::xmlAttribute((string)($metadata['title'] ?? '')) . '"'
            . ' cd:artist="' . self::xmlAttribute((string)($metadata['artist'] ?? '')) . '"'
            . ' cd:album="' . self::xmlAttribute((string)($metadata['album'] ?? '')) . '"'
            . ' cd:year="' . self::xmlAttribute((string)($metadata['year'] ?? '')) . '"'
            . ' cd:technical="' . self::xmlAttribute(json_encode($metadata['technical'] ?? [], JSON_THROW_ON_ERROR)) . '"'
            . ' cd:encoding="' . self::xmlAttribute($encoding) . '"'
            . '/></rdf:RDF></x:xmpmeta><?xpacket end="w"?>';

        $xmpLength = strlen($xmp);
        $chunk = 'XMP ' . pack('V', $xmpLength) . $xmp;

        if ($xmpLength % 2 !== 0) {
            $chunk .= "\0";
        }

        if (file_put_contents($imagePath, $chunk, FILE_APPEND) === false) {
            throw new \RuntimeException('Unable to write XMP metadata to the WebP file.');
        }

        $fileHandle = fopen($imagePath, 'r+b');

        if ($fileHandle === false) {
            throw new \RuntimeException('Unable to update the WebP RIFF header.');
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

        $unpackedChunkLength = unpack('V', substr($contents, $chunkPosition + 4, 4));

        if ($unpackedChunkLength === false) {
            return [];
        }

        $chunkLength = $unpackedChunkLength[1];
        $xmp = substr($contents, $chunkPosition + 8, $chunkLength);
        $metadata = [];

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            if (preg_match('/cd:' . $field . '="([^"]*)"/', $xmp, $matches) === 1) {
                $metadata[$field] = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }

        if (preg_match('/cd:encoding="([^"]*)"/', $xmp, $matches) === 1) {
            $encoding = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $metadata['encoding'] = json_decode($encoding, true, 512, JSON_THROW_ON_ERROR);
        }

        if (preg_match('/cd:technical="([^"]*)"/', $xmp, $matches) === 1) {
            $technical = html_entity_decode($matches[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
            $metadata['technical'] = json_decode($technical, true, 512, JSON_THROW_ON_ERROR);
        }

        return $metadata;
    }

    private static function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /** @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>} */
    private function readMp3Metadata(): array
    {
        $metadata = [
            'title' => '',
            'artist' => '',
            'album' => '',
            'year' => '',
            'technical' => [],
        ];

        $getId3 = new \getID3();
        $analysis = $getId3->analyze($this->audioPath);
        $tags = $analysis['tags'] ?? [];

        $audio = $analysis['audio'] ?? [];
        $metadata['technical'] = [
            'bitrate_kbps' => isset($analysis['bitrate']) ? round((float)$analysis['bitrate'] / 1000, 1) : null,
            'sample_rate_hz' => $audio['sample_rate'] ?? null,
            'channels' => $audio['channels'] ?? null,
            'codec' => $audio['codec'] ?? ($analysis['codec'] ?? null),
            'duration_seconds' => isset($analysis['playtime_seconds']) ? round((float)$analysis['playtime_seconds'], 2) : null,
            'file_size_bytes' => $analysis['filesize'] ?? filesize($this->audioPath),
        ];

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            foreach (['id3v2', 'id3v1'] as $tagVersion) {
                $value = $tags[$tagVersion][$field][0] ?? '';

                if ($metadata[$field] === '' && is_string($value)) {
                    $metadata[$field] = self::cleanMetadataValue($value);
                }
            }
        }

        return $metadata;
    }

    private static function cleanMetadataValue(string $value): string
    {
        return trim($value, " \t\r\n\0");
    }

    // ============================================================
    // PACK UINT64
    // ============================================================
    private static function packUint64(int $value): string
    {
        return pack('N2', 0, $value);
    }

    private static function unpackUint64(string $data): int
    {
        $parts = unpack('Nhigh/Nlow', $data);

        if ($parts === false) {
            throw new \InvalidArgumentException('Unable to unpack a 64-bit integer.');
        }

        return ((int)$parts['high'] << 32) | (int)$parts['low'];
    }

    // ============================================================
    // HEADER PIXEL COUNT
    // ============================================================
    private static function headerSize(): int
    {
        return 8 + 1 + 4 + 4 + 4 + 8 + 32 + (4 * self::METADATA_FIELD_LENGTH);
    }
}
