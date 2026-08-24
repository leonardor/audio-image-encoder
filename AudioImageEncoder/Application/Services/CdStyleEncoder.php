<?php

declare(strict_types=1);

namespace AudioImageEncoder\Application\Services;

use AudioImageEncoder\Application\Contracts\EncoderInterface;
use Psr\Log\LoggerInterface;

/**
 * Encodes MP3 bytes into a lossless WebP image using the original CD-style
 * experimental format. Header bytes use a reversible palette; audio bytes use
 * RGB triplets along a deterministic spiral, with XMP storing configuration.
 */
class CdStyleEncoder implements EncoderInterface
{
    /** Standard 600 DPI disc profile. */
    public const PROFILE_STANDARD = 'standard';
    /** 1200 DPI profile for larger digital payloads. */
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

    /** Physical output diameter in millimeters. */
    private const DISC_DIAMETER_MM = 120.0;

    /** Horizontal center coordinate in millimeters. */
    private const CENTER_X_MM = 60.0;
    /** Vertical center coordinate in millimeters. */
    private const CENTER_Y_MM = 60.0;

    /** Reserved center-hole diameter in millimeters. */
    private const HOLE_DIAMETER_MM = 8.0;
    /** Diameter of each cardinal orientation marker in millimeters. */
    private const MARKER_DIAMETER_MM = 0.5;
    /** White clearance between each corner marker and the image edge. */
    private const CORNER_MARKER_EDGE_CLEARANCE_PX = 32;
    /** Width of the gray outline around the outer audio-ring boundary. */
    private const AUDIO_RING_BORDER_WIDTH_PX = 2;
    /** Gray color used for the outer audio-ring outline. */
    private const AUDIO_RING_BORDER_COLOR = 0x808080;


    // ============================================================
    // IMAGE RESOLUTION
    // ============================================================
    //
    // 600 DPI:
    //
    // 120 mm â‰ˆ 2835 pixels
    //
    // 1200 DPI:
    //
    // 120 mm â‰ˆ 5669 pixels
    //
    // 1200 DPI may be useful for large MP3 files.
    //

    /** Resolution used by the standard profile. */
    private const DEFAULT_DPI = 600;
    /** Resolution used by the high-capacity digital profile. */
    private const DIGITAL_MAX_DPI = 1200;


    // ============================================================
    // DATA AREA
    // ============================================================
    //
    // We do not use the exact center or edge.
    // Space is reserved for markers.
    //

    /** Inner radius where the audio spiral begins. */
    private const DATA_RADIUS_START_MM = 9;
    /** Radius of the dedicated header ring. */
    private const DATA_RADIUS_START_HEADER_MM = 8.5;
    /** Radius of the marker ring used to keep markers clear of payload data. */
    private const DATA_RADIUS_START_MARKER_MM = 58.0;

    /** Maximum physical radius considered for the spiral capacity calculation. */
    private const DATA_RADIUS_END_MM = 100.0;


    // ============================================================
    // SPIRAL
    // ============================================================
    //
    // A payload pixel stores three bytes using an RGB 8-8-8 color.
    //
    // Pixels are read along a spiral.
    //

    /** Radial distance between successive spiral revolutions in millimeters. */
    private const SPIRAL_PITCH_MM = 0.06;

    /** Angular increment, in radians, between successive payload pixels. */
    private const ANGLE_STEP_RADIANS = 0.007;


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

    /** Binary header signature identifying this encoder format. */
    private const MAGIC = 'CDMP3';

    /** Binary header version used for compatibility validation. */
    private const FORMAT_VERSION = 1;

    /** Fixed byte width allocated to each textual metadata field. */
    private const METADATA_FIELD_LENGTH = 128;

    /**
     * Audio tags, technical details, and the persisted encoding settings.
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

    // XMP encoding values override these defaults during decoding.
    /**
     * Geometry loaded from XMP and used to reproduce encoder coordinates.
     *
     * @var array<string, int|float|string>
     */
    private array $decodingConfiguration = [];

    /** Source MP3 path during encoding or destination path during decoding. */
    private string $audioPath = '';

    /** Destination WebP path during encoding or source path during decoding. */
    private string $imagePath = '';

    /** Normalized profile selected for the current operation. */
    private string $profile = self::PROFILE_STANDARD;

    /** Creates an encoder that reports progress and failures through the logger. */
    public function __construct(private LoggerInterface $logger)
    {
    }

    /**
     * Returns the audio metadata and encoding configuration for this instance.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>, encoding: array<string, mixed>}
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }

    /** Reports whether the file exceeds the standard profile's physical capacity. */
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

    /** Stores paths and normalizes the profile used by subsequent operations. */
    public function prepare(string $audioPath, string $imagePath, string $profile = self::PROFILE_STANDARD): void
    {
        $this->audioPath = $audioPath;
        $this->imagePath = $imagePath;
        $this->profile = self::normalizeProfile($profile);
        $this->decodingConfiguration = self::encodingConfiguration();
    }

    /** Writes the MP3, binary header, markers, and XMP metadata to lossless WebP. */
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

        $headerRadius = self::DATA_RADIUS_START_HEADER_MM;

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

        self::drawAudioRingBorder($image, $size, $pixelsPerMm);

        // --------------------------------------------------------
        // ORIENTATION MARKERS
        // --------------------------------------------------------

        self::drawMarkers($image, $size, $pixelsPerMm);

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

    /** Reads, validates, hashes, and writes the MP3 payload from a WebP image. */
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

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            if (is_string($xmpMetadata[$field] ?? null)) {
                $this->metadata[$field] = $xmpMetadata[$field];
            }
        }

        $this->decodingConfiguration = self::encodingConfiguration();
        if (is_array($xmpMetadata['encoding'] ?? null)) {
            foreach ($xmpMetadata['encoding'] as $key => $value) {
                if (is_string($key) && (is_int($value) || is_float($value) || is_string($value))) {
                    $this->decodingConfiguration[$key] = $value;
                }
            }
        }

        $this->metadata['encoding'] = $this->decodingConfiguration;
        if (is_array($xmpMetadata['technical'] ?? null)) {
            $this->metadata['technical'] = [];
            foreach ($xmpMetadata['technical'] as $key => $value) {
                if (is_string($key)) {
                    $this->metadata['technical'][$key] = $value;
                }
            }
        }

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

        $magic = substr($headerBytes, $offset, strlen(self::MAGIC));

        $offset += strlen(self::MAGIC);

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

        foreach (['title', 'artist', 'album', 'year'] as $field) {
            $this->metadata[$field] = rtrim(substr($headerBytes, $offset, self::METADATA_FIELD_LENGTH), "\0");
            $offset += self::METADATA_FIELD_LENGTH;
        }

        if ($fileSize > self::calculateCapacity($width, $height)) {
            imagedestroy($image);

            throw new \RuntimeException('The payload exceeds the image capacity.');
        }

        $this->logger->info('Decoded metadata.', [
            'dpi' => $dpi,
            'image' => $storedWidth . 'x' . $storedHeight,
            'bytes' => $fileSize,
            'title' => $this->metadata['title'],
            'artist' => $this->metadata['artist'],
            'album' => $this->metadata['album'],
            'year' => $this->metadata['year'],
            'encoding' => $this->metadata['encoding'],
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

    /** Converts a palette-encoded image pixel back into one header byte. */
    private static function readPixelByte(\GdImage $image, int $x, int $y): int
    {
        $rgb = @imagecolorat($image, $x, $y);

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return self::colorToByte($r, $g, $b);
    }

    /** Reads a lossless RGB pixel as a packed 24-bit payload value. */
    private static function readPixelValue(\GdImage $image, int $x, int $y): int
    {
        $rgb = @imagecolorat($image, $x, $y);

        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;

        return self::colorToValue($r, $g, $b);
    }

    /** Validates and allocates an RGB color in the GD image. */
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
    /** @return array{0: int, 1: int, 2: int} */
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

    /** Reconstructs a header byte by quantizing RGB channels to the palette. */
    private static function colorToByte(int $r, int $g, int $b): int
    {
        $rIndex = (int)round($r * 7 / 255);
        $gIndex = (int)round($g * 7 / 255);
        $bIndex = (int)round($b * 3 / 255);

        return($rIndex << 5) | ($gIndex << 2) | $bIndex;
    }

    /**
     * Splits a packed 24-bit payload value into its RGB channels.
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function payloadColor(int $value): array
    {
        $r = ($value >> 16) & 0xFF;
        $g = ($value >> 8) & 0xFF;
        $b = $value & 0xFF;

        return [$r, $g, $b];
    }

    /** Packs three 8-bit channels into the value used by payload storage. */
    private static function colorToValue(int $r, int $g, int $b): int
    {
        return ($r << 16) | ($g << 8) | $b;
    }

    // ============================================================
    // UTILITIES
    // ============================================================

    /** Converts physical millimeters to at least one output pixel. */
    private static function mmToPx(float $mm, int $dpi): int
    {
        return max(1, (int)round($mm / 25.4 * $dpi));
    }

    /**
     * Converts disc-centered polar coordinates into physical X/Y coordinates.
     *
     * @return array{0: float, 1: float}
     */
    private static function polar(float $radius, float $angle): array
    {
        return [self::CENTER_X_MM + $radius * cos($angle), self::CENTER_Y_MM + $radius * sin($angle)];
    }

    /**
     * Applies stored decoder geometry when converting polar coordinates.
     *
     * @return array{0: float, 1: float}
     */
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

    /**
     * Returns the physical location assigned to a payload pixel during encoding.
     *
     * @return array{0: float, 1: float}
     */
    private static function spiralPosition(int $index): array
    {
        $angle = $index * self::ANGLE_STEP_RADIANS;
        $radius = self::DATA_RADIUS_START_MM + self::SPIRAL_PITCH_MM * $angle / (2 * M_PI);

        return [$radius, $angle];
    }

    /**
     * Recreates an encoded payload location from stored decoder configuration.
     *
     * @return array{0: float, 1: float}
     */
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

    /** Calculates usable spiral bytes after edge, marker, and geometry clearance. */
    private static function calculateCapacity(int $width, int $height): int
    {
        $pixelsPerMm = min($width, $height) / self::DISC_DIAMETER_MM;
        $imageRadiusMm = min($width, $height) / 2 / $pixelsPerMm;
        $markerRadiusMm = self::DATA_RADIUS_START_MARKER_MM;
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
        $spiralPixels = (int)floor($totalAngle / self::ANGLE_STEP_RADIANS);

        return $spiralPixels * 3;
    }

    private static function drawAudioRingBorder(\GdImage $image, int $imageSize, float $pixelsPerMm): void
    {
        $centerX = (int)round(self::CENTER_X_MM * $pixelsPerMm);
        $centerY = (int)round(self::CENTER_Y_MM * $pixelsPerMm);
        $radius = (int)round(self::DATA_RADIUS_START_MARKER_MM * $pixelsPerMm);

        imagesetthickness($image, self::AUDIO_RING_BORDER_WIDTH_PX);
        imageellipse(
            $image,
            $centerX,
            $centerY,
            $radius * 2,
            $radius * 2,
            self::AUDIO_RING_BORDER_COLOR,
        );
        imagesetthickness($image, 1);
    }

    private static function drawMarkers(\GdImage $image, int $imageSize, float $pixelsPerMm): void
    {
        $black = self::allocateColor($image, 10, 10, 10);
        $markerRadius = (int)round(self::MARKER_DIAMETER_MM / 2 * $pixelsPerMm);
        $markerInset = self::CORNER_MARKER_EDGE_CLEARANCE_PX + $markerRadius;

        foreach (
            [
                [$markerInset, $markerInset],
                [$imageSize - $markerInset, $markerInset],
                [$markerInset, $imageSize - $markerInset],
                [$imageSize - $markerInset, $imageSize - $markerInset],
            ] as [$x, $y]
        ) {
            imagefilledellipse($image, $x, $y, $markerRadius * 2, $markerRadius * 2, $black);
        }
    }

    // ============================================================
    // HEADER
    // ============================================================

    /**
     * Serializes fixed-width binary header fields and tagged metadata.
     *
     * @param array<string, mixed> $metadata
     */
    private static function createHeader(int $dataLength, string $sha256, array $metadata, int $width, int $height, int $dpi): string
    {
        /*
        * Header layout:
        *
        * MAGIC       variable length
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
            $value = is_string($metadata[$field] ?? null) ? $metadata[$field] : '';

            if (strlen($value) > self::METADATA_FIELD_LENGTH) {
                $value = substr($value, 0, self::METADATA_FIELD_LENGTH);
            }

            $header .= str_pad($value, self::METADATA_FIELD_LENGTH, "\0");
        }

        return $header;
    }

    /** Restricts profile selection to the formats understood by this encoder. */
    private static function normalizeProfile(string $profile): string
    {
        return in_array($profile, [self::PROFILE_STANDARD, self::PROFILE_DIGITAL_MAX], true)
            ? $profile
            : self::PROFILE_STANDARD;
    }

    /**
     * Builds the persisted geometry configuration for the selected profile.
     *
     * @return array<string, int|float|string>
     */
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

    /**
     * Returns default geometry and serialization values for this format.
     *
     * @return array<string, int|float|string>
     */
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
            'data_radius_start_header_mm' => self::DATA_RADIUS_START_HEADER_MM,
            'data_radius_start_marker_mm' => self::DATA_RADIUS_START_MARKER_MM,
            'data_radius_end_mm' => self::DATA_RADIUS_END_MM,
            'spiral_pitch_mm' => self::SPIRAL_PITCH_MM,
            'angle_step' => self::ANGLE_STEP_RADIANS,
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
            . ' cd:title="' . self::xmlAttribute(is_string($metadata['title'] ?? null) ? $metadata['title'] : '') . '"'
            . ' cd:artist="' . self::xmlAttribute(is_string($metadata['artist'] ?? null) ? $metadata['artist'] : '') . '"'
            . ' cd:album="' . self::xmlAttribute(is_string($metadata['album'] ?? null) ? $metadata['album'] : '') . '"'
            . ' cd:year="' . self::xmlAttribute(is_string($metadata['year'] ?? null) ? $metadata['year'] : '') . '"'
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

    /** Escapes metadata for safe placement in an XMP XML attribute. */
    private static function xmlAttribute(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    /**
     * Extracts optional ID3 and technical metadata using getID3.
     *
     * @return array{title: string, artist: string, album: string, year: string, technical: array<string, mixed>}
     */
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

    /** Removes control whitespace from a metadata field. */
    private static function cleanMetadataValue(string $value): string
    {
        return trim($value, " \t\r\n\0");
    }

    // ============================================================
    // PACK UINT64
    // ============================================================
    /** Encodes a non-negative payload length in the format's 64-bit field. */
    private static function packUint64(int $value): string
    {
        return pack('N2', 0, $value);
    }

    /** Decodes the format's big-endian 64-bit length field. */
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
    /** Returns the exact number of bytes occupied by the binary header. */
    private static function headerSize(): int
    {
        return strlen(self::MAGIC) + 1 + 4 + 4 + 4 + 8 + 32 + (4 * self::METADATA_FIELD_LENGTH);
    }
}
