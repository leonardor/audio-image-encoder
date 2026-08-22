# CdEncoder Project Knowledge

This document records the current implementation, format decisions, dependencies, tests, and known limitations of the CdEncoder application.

## Project Purpose

CdEncoder stores an audio file inside a lossless WebP image and recovers the original bytes later. Recovery is verified with SHA-256.

This is a digital storage format. It is not CD-DA and the generated image cannot be played by a normal CD player.

## License

The project is licensed under the MIT License. The full license text is in `LICENSE`.

## Runtime Requirements

- PHP 8.4 or newer;
- GD with WebP support, including lossless WebP support;
- FFmpeg installed at `/usr/bin/ffmpeg`;
- Composer dependencies installed from `composer.json`.

## Current Implementation

The active implementation is in `CdEncoder/Application/Services/CdEncoder.php`.

The web application controller is in `CdEncoder/UI/Http/Controller/Index.php` and uses the following WebP flow:

1. The user uploads an audio file.
2. `Transcoder` transcodes it to MP3 at `64 kbps` with `/usr/bin/ffmpeg`.
3. FFmpeg copies metadata and writes ID3v2.3 and ID3v1 tags.
4. `CdEncoder` reads the temporary MP3 and creates a lossless WebP.
5. The temporary transcoded MP3 is deleted.
6. During decoding, `CdEncoder` extracts the payload, verifies SHA-256, writes the recovered MP3, and exposes metadata through `getMetadata()`.

The CLI in `public/cli.php` uses Symfony Console and the `CliCommand` class. It does not perform the web application's 64 kbps transcoding step.

The web application currently generates and decodes WebP files only.

The standalone HTTP entrypoints use Symfony HttpFoundation responses. Images
and recovered audio are streamed with `BinaryFileResponse`, including standard
file metadata and range support.

## WebP Metadata Access

The application stores audio metadata in the custom pixel header and stores audio metadata, MP3 technical data, and encoding configuration in a WebP XMP chunk.

It is stored as pixel data in a custom header ring. Therefore:

- XMP metadata can be inspected before GD decodes the image pixels;
- the custom pixel-header copy still requires WebP pixel decoding;
- `imagecreatefromwebp()` is required for the payload and pixel header;
- the header ring is sampled using the XMP configuration when available;
- missing or incomplete XMP values fall back to the compiled defaults.

The current API is:

```php
$decoder = new CdEncoder($logger);
$decoder->prepare($audioOutputPath, $imagePath);
$decoder->decode();
$metadata = $decoder->getMetadata();
```

The encoder and transcoder require a PSR-3 logger. The HTTP and CLI entrypoints
use Monolog, while tests can use `Psr\Log\NullLogger`.

## Format Version 7

The current format version is `7`. Older image versions are not compatible with the active decoder.

### Image

- Format: lossless WebP
- Default resolution: `600 DPI`
- Image size at 600 DPI: approximately `2835 x 2835` pixels
- Payload color model: RGB 8-8-8
- Payload density: `3 audio bytes per payload pixel`
- Spiral pitch: `0.06 mm`
- Angle step: `0.007`

### Header

The header is stored as one byte per pixel in a dedicated ring. Header bytes use an 8 x 8 x 4 palette with 256 exact byte values.

Header size is `573` bytes:

| Field | Size |
|---|---:|
| Magic | 8 bytes |
| Format version | 1 byte |
| DPI | 4 bytes |
| Stored width | 4 bytes |
| Stored height | 4 bytes |
| Audio file size | 8 bytes |
| SHA-256 | 32 bytes |
| Audio metadata | 512 bytes |
| **Total** | **573 bytes** |

### Audio Metadata

Four fixed fields are stored, each with a maximum of 128 bytes:

- `title`
- `artist`
- `album`
- `year`

Technical MP3 data is stored in the XMP `technical` property and includes:

- `bitrate_kbps`
- `sample_rate_hz`
- `channels`
- `codec`
- `duration_seconds`
- `file_size_bytes`

The active parser is `james-heinrich/getid3`. It reads ID3v1 and ID3v2 metadata.

### Encoding Configuration Metadata

The `encoding` entry stores the constants used to create the image in the WebP XMP chunk. It is serialized as JSON properties and can be read before pixel decoding.

Current keys include:

- `format_version`
- `default_dpi`
- `disc_diameter_mm`
- `center_x_mm`
- `center_y_mm`
- `hole_diameter_mm`
- `marker_diameter_mm`
- `data_radius_start_mm`
- `data_radius_start_header_mm`
- `data_radius_start_marker_mm`
- `data_radius_end_mm`
- `spiral_pitch_mm`
- `angle_step`
- `payload_bytes_per_pixel`
- `metadata_field_length`

This makes each generated image self-descriptive regarding the encoder configuration. During decoding, valid XMP values override the local defaults; missing or incomplete values fall back to the compiled defaults.

## Geometry

Current constants:

```text
DISC_DIAMETER_MM       = 120.0
CENTER_X_MM            = 60.0
CENTER_Y_MM            = 60.0
HOLE_DIAMETER_MM       = 8.0
MARKER_DIAMETER_MM     = 0.5
DEFAULT_DPI            = 600
DATA_RADIUS_START_MM  = 9
DATA_RADIUS_START_HEADER = 8.5
DATA_RADIUS_START_MARKER = 58.0
DATA_RADIUS_END_MM    = 100.0
SPIRAL_PITCH_MM        = 0.06
ANGLE_STEP             = 0.007
```

The marker radius limits the payload area. The theoretical current capacity is approximately:

- `728,665` payload pixels;
- `2,185,995` audio bytes;
- approximately `2.09 MiB`.

The header ring must remain sparse enough to avoid two header bytes mapping to the same raster pixel. Increasing the header size or changing the header radius requires collision testing.

## Encoding and Decoding

Encoding:

```php
$encoder = new CdEncoder($logger);
$encoder->prepare($audioPath, $imagePath);
$encoder->encode();
```

Decoding:

```php
$decoder = new CdEncoder($logger);
$decoder->prepare($audioOutputPath, $imagePath);
$decoder->decode();
$metadata = $decoder->getMetadata();
```

The payload is read in three-byte chunks. The final chunk is padded with zero bytes during encoding and trimmed to the original file size during decoding.

The original audio bytes are hashed before encoding. The decoded payload is hashed again, and a mismatch raises `SHA-256 mismatch.`

## FFmpeg Transcoding

The web upload path uses `Symfony Process` to run:

```text
/usr/bin/ffmpeg
```

Relevant options:

```text
-y
-map 0:a:0
-map_metadata 0
-id3v2_version 3
-write_id3v1 1
-codec:a libmp3lame
-b:a 64k
```

The Composer package manages the child process, timeout, output, and exit status. FFmpeg itself is not installed by Composer; it is a system executable.

Required server checks:

```bash
/usr/bin/ffmpeg -version
/usr/bin/ffprobe -version
```

The current direct transcoding flow uses `/usr/bin/ffmpeg`. The error text still mentions both FFmpeg and FFprobe because FFprobe may be needed by related tooling or future metadata workflows.

The current implementation does not provide encryption, copyright protection,
or censorship resistance. Those are possible future directions only.

## Composer Dependencies

Install dependencies with:

```bash
php composer.phar install --no-interaction
```

Runtime packages:

- `james-heinrich/getid3` for MP3 metadata;
- `symfony/process` for FFmpeg process execution;
- `symfony/http-foundation` for HTTP requests and responses;
- `symfony/console` for the CLI;
- `twig/twig` for web templates;
- `ramsey/uuid` for generated file names;
- `monolog/monolog` for PSR-3 logging.

Development packages:

- `phpstan/phpstan`;
- `phpunit/phpunit`;
- `friendsofphp/php-cs-fixer`.

Composer autoloading is loaded by `vendor/autoload.php` through the project entrypoints.

Application logs are written to `logs/cd-encoder.log`. Runtime log files are excluded from Git.

## Tests

Tests are in `tests/CdEncoderTest.php` and configuration is in `phpunit.xml`.

```bash
php vendor/bin/phpunit --configuration phpunit.xml
```

Current coverage includes:

- encode/decode of a 257-byte odd-length payload;
- byte-level integrity through SHA-256;
- metadata behavior for an untagged audio file;
- recovery of the stored encoding constants.

The expected current result is:

```text
OK (2 tests, 8 assertions)
```

Run PHPStan at level 8:

```bash
php vendor/bin/phpstan analyse --configuration phpstan.neon --no-progress
```

Format PHP files with PHP CS Fixer:

```bash
php vendor/bin/php-cs-fixer fix CdEncoder
```

## Important Limitations

- Lossy WebP compression is not allowed because it can change payload bytes.
- Images from format versions before v7 are rejected.
- Metadata fields are limited to 128 bytes each.
- The encoding configuration block is limited to 256 bytes.
- The current decoder reads the XMP configuration before decoding pixels and uses it for coordinate calculation, with local defaults as fallback.
- `packUint64()` currently stores the file size with a zero high word, which limits practical file sizes to the supported PHP integer range used by this project.
- The web application requires `/usr/bin/ffmpeg` and sufficient process permissions for the PHP user.
- The web application currently generates a fixed-size image. A smaller source MP3 does not automatically produce a physically smaller WebP canvas.
- Changing DPI, spiral constants, marker positions, payload density, or header layout requires a format-version change and new round-trip tests.

## Historical Notes

Earlier iterations used one byte per pixel, then RGB 5-6-5 with two bytes per pixel. The active implementation uses RGB 8-8-8 with three bytes per payload pixel.

Older files and old implementations remain in files such as `CdEncoder/vechi.php` and `chat`. Those files are historical references and should not be treated as the active implementation.

## Example and Demo

The repository includes a sample generated image at `examples/example.webp`.

The live demo is available at:

https://cdencoder.muzichii.ro
