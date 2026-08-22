<?php

declare(strict_types=1);

namespace CdEncoder\Application\Services;

use Psr\Log\LoggerInterface;
use Symfony\Component\Process\Process;

class Transcoder
{
    private const TRANSCODE_BITRATE = '64k';
    private const FFMPEG_PATH = '/usr/bin/ffmpeg';
    private const CODEC = 'libmp3lame';

    public function __construct(private LoggerInterface $logger)
    {
    }

    public function transcode(string $inputPath): string
    {
        $this->logger->info('Transcoding audio...');

        $outputPath = tempnam(sys_get_temp_dir(), 'cd-encoder-');

        if ($outputPath === false) {
            throw new \RuntimeException('Unable to create the temporary audio file.');
        }

        unlink($outputPath);
        $outputPath .= '.mp3';

        try {
            $process = new Process([
                self::FFMPEG_PATH,
                '-y',
                '-i', $inputPath,
                '-map', '0:a:0',
                '-map_metadata', '0',
                '-id3v2_version', '3',
                '-write_id3v1', '1',
                '-codec:a', self::CODEC,
                '-b:a', self::TRANSCODE_BITRATE,
                $outputPath,
            ]);
            $process->setTimeout(120);
            $process->run();

            if (!$process->isSuccessful()) {
                throw new \RuntimeException($process->getErrorOutput() ?: $process->getOutput());
            }

            $this->logger->info('Transcoded audio file {outputPath}...', ['outputPath' => $outputPath]);

            return $outputPath;
        } catch (\Throwable $exception) {
            if (file_exists($outputPath)) {
                unlink($outputPath);
            }

            $this->logger->error('Error transcoding audio file {outputPath}.', [
                'outputPath' => $outputPath,
                'exception' => $exception,
            ]);

            throw new \RuntimeException(
                'Transcoding to 64 kbps failed. Check the ffmpeg and ffprobe installation and paths.',
                0,
                $exception
            );
        }
    }

    public function getTranscodeBitrate(): string
    {
        return self::TRANSCODE_BITRATE;
    }
}
