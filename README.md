# CD Encoder

CD Encoder stores an audio file as a lossless WebP image and can recover the original bytes with SHA-256 verification.

This is an experimental digital storage format. It is not CD-DA and cannot be played directly by a conventional CD player.

## Purpose

The project started out of curiosity and as a small experiment for fun.

While working on it, I wondered whether the idea could become a larger project that converts audio into image files with optional copyright protection or encryption. An encrypted image could require a key before the audio can be recovered. These capabilities are ideas for future development; the current implementation does not provide encryption, copyright protection, or censorship resistance.

Given these premises, this project might evolve into a tool that helps people circumvent censorship.

## License

This project is open-source software licensed under the MIT License.

You can freely use, modify, and distribute it in both personal and commercial projects.
You must include the original copyright notice and permission notice in your copies or substantial portions of the software.
The software comes with no warranty, and the authors are not liable for damages.

## Current Format

- Image format: lossless WebP
- Format version: `7`
- Default image resolution: `600 DPI` (`2835 x 2835` pixels)
- Payload encoding: RGB 8-8-8, three audio bytes per payload pixel
- Spiral pitch: `0.06 mm`
- Header: format version, DPI, image dimensions, audio size, SHA-256, and metadata
- Metadata fields: title, artist, album, and year
- Metadata storage: four fixed fields of up to `128` bytes in the custom image header
- Encoding configuration: JSON properties stored in the WebP XMP chunk
- Pixel header size: `573` bytes (`61` bytes core header + `512` bytes audio metadata)

Audio metadata remains in the custom pixel header, while the `encoding` and `technical` entries are stored in the WebP XMP chunk and can be read before pixel decoding. During decoding, valid XMP values override the local default constants; missing XMP values fall back to those defaults.

The web interface supports WebP encode/decode only. Metadata and technical MP3 data are shown in separate interface panels.

## Requirements

- PHP 8.4 or newer
- GD with WebP support
- FFmpeg installed at `/usr/bin/ffmpeg`
- Composer dependencies from `composer.json`

Check the required executables:

```bash
ffmpeg -version
ffprobe -version
```

The web application transcodes uploaded audio to `64 kbps` before encoding. The `CdEncoder` class itself encodes the file it receives. The transcoded file is temporary and is removed after the image is generated. FFmpeg copies the source metadata into ID3v2.3 and ID3v1 tags.

## Installation

Run the following commands from the `src` directory:

```bash
php composer.phar install --no-interaction
```

Composer installs:

- `james-heinrich/getid3` for ID3 metadata parsing;
- `symfony/process` for managing FFmpeg processes;
- `symfony/http-foundation` for HTTP requests and responses;
- `symfony/console` for the command-line interface;
- `twig/twig` for rendering the web interface;
- `ramsey/uuid` for generated file names;
- `monolog/monolog` for PSR-3 logging;
- `phpstan/phpstan` for static analysis;
- `phpunit/phpunit` for tests.

FFmpeg itself is a system executable and must be installed separately. On Debian or Ubuntu:

```bash
sudo apt update
sudo apt install ffmpeg
```

## PHP API

Encode an audio file:

```php
use CdEncoder\Application\Services\CdEncoder;
use Psr\Log\NullLogger;

$encoder = new CdEncoder(new NullLogger());
$encoder->prepare($audioPath, $imagePath);
$encoder->encode();
```

Decode an image:

```php
use CdEncoder\Application\Services\CdEncoder;
use Psr\Log\NullLogger;

$decoder = new CdEncoder(new NullLogger());
$decoder->prepare($audioOutputPath, $imagePath);
$decoder->decode();
$metadata = $decoder->getMetadata();
```

`getMetadata()` returns an array with these keys:

```php
[
    'title' => '',
    'artist' => '',
    'album' => '',
    'year' => '',
    'technical' => [
        'bitrate_kbps' => 64.0,
        'sample_rate_hz' => 44100,
        'channels' => 2,
        'codec' => 'mp3',
        'duration_seconds' => 0.0,
        'file_size_bytes' => 0,
    ],
    'encoding' => [
        'format_version' => 7,
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
    ],
]
```

## CLI

The CLI entry point is `public/cli.php`:

```bash
php public/cli.php cd-encoder encode input.mp3 output.webp
php public/cli.php cd-encoder encode-max input.mp3 output.webp
php public/cli.php cd-encoder decode input.webp recovered.mp3
```

The Symfony Console command accepts input and output paths directly. The CLI does not transcode audio; the web upload flow performs the `64 kbps` conversion before encoding.

## Tests

Run the PHPUnit suite from `src`:

```bash
php vendor/bin/phpunit --configuration phpunit.xml
```

The tests cover:

- encode/decode of an odd-length payload;
- byte-for-byte recovery verified by SHA-256;
- empty metadata handling for an untagged audio file;
- recovery of the encoding constants and MP3 technical data stored in WebP XMP.

Expected result:

```text
OK (2 tests, 8 assertions)
```

## Important Compatibility Notes

- Images created with older format versions are not compatible with format version `7`.
- If the source metadata changes, regenerate the WebP image.
- Lossless recovery depends on using lossless WebP. Lossy image compression can corrupt payload bytes and cause a SHA-256 mismatch.
- Reducing the image resolution or changing spiral constants requires matching encoder and decoder changes and new round-trip tests.
