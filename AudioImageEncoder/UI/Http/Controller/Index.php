<?php

declare(strict_types=1);

namespace AudioImageEncoder\UI\Http\Controller;

use AudioImageEncoder\Application\Services\Transcoder;
use AudioImageEncoder\Application\Contracts\EncoderInterface;
use AudioImageEncoder\Application\Services\BluRayStyleEncoder;
use AudioImageEncoder\Application\Services\CdStyleEncoder;
use AudioImageEncoder\Application\Services\DvdStyleEncoder;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class Index
{
    private const AUDIO_FILES_PATH = __DIR__ . '/../../../../public/audio/';
    private const IMAGE_FILES_PATH = __DIR__ . '/../../../../public/images/';

    public function __construct(private LoggerInterface $logger, private EncoderInterface $encoder)
    {
    }

    public function index(Request $request): Response
    {
        $templateData = [
            'message' => '',
            'messageType' => '',
            'downloadUrl' => '',
            'imageUrl' => '',
            'metadata' => [],
            'action' => 'encode',
            'encoder' => 'dvd',
        ];

        if ($request->isMethod('POST')) {
            set_time_limit(120);

            $action = $request->request->get('action', '');
            $encoderName = $request->request->get('encoder', 'dvd');

            if (!is_string($encoderName) || !in_array($encoderName, ['cd', 'dvd', 'bluray'], true)) {
                throw new InvalidArgumentException('Please choose a valid encoder.');
            }

            $templateData['encoder'] = $encoderName;
            $this->encoder = $this->createEncoder($encoderName);

            $file = match ($action) {
                'encode' => $request->files->get('audio_file'),
                'decode' => $request->files->get('image_file'),
                default => null,
            };

            $tmpFile = $file instanceof UploadedFile ? $file->getPathname() : '';
            $error = $file instanceof UploadedFile ? $file->getError() : \UPLOAD_ERR_NO_FILE;

            $mimeType = match ($action) {
                'encode' => 'MP3',
                'decode' => 'WebP',
                default => '',
            };

            $fileName = $file instanceof UploadedFile ? $file->getClientOriginalName() : '';

            $this->logger->info("Uploading file...", ['file' => $fileName, 'action' => $action]);

            if (!empty($action)) {
                try {
                    if (isset($file) && $error === \UPLOAD_ERR_OK) {
                        // 1. Generate a unique name based on UUID v4
                        $targetDir = match ($action) {
                            'encode' => self::IMAGE_FILES_PATH,
                            'decode' => self::AUDIO_FILES_PATH,
                            default => '',
                        };

                        if (!file_exists($targetDir)) {
                            mkdir($targetDir, 0755, true); // Create the directory if it does not exist
                        }

                        $uuid = Uuid::uuid4()->toString();

                        $outputFile = match ($action) {
                            'encode' => $uuid . '.webp',
                            'decode' => $uuid . '.mp3',
                            default => '',
                        };

                        $outputPathFile = $targetDir . $outputFile;

                        switch ($action) {
                            case 'encode':
                                $inputPathFile = $tmpFile;

                                $this->logger->info('Checking if the input file needs transcoding...', ['file' => $fileName]);

                                $isTranscoded = $this->encoder->shouldTranscode($inputPathFile);

                                if ($isTranscoded) {
                                    $transcoder = new Transcoder($this->logger);
                                    $inputPathFile = $transcoder->transcode($tmpFile);
                                }

                                $this->logger->info('Preparing the encoder...', ['input' => $inputPathFile, 'output' => $outputPathFile]);

                                $this->encoder->prepare($inputPathFile, $outputPathFile);

                                $this->logger->info('Starting encoding process...', ['input' => $inputPathFile, 'output' => $outputPathFile]);

                                if ($this->encoder->encode()) {
                                    $templateData['message'] = "Success! The song was encoded into the CD image." . ($isTranscoded ? " The original file was transcoded to MP3 format." : "");
                                    $templateData['messageType'] = 'success';
                                    $templateData['imageUrl'] = $outputFile;
                                    $templateData['metadata'] = $this->encoder->getMetadata();
                                } else {
                                    throw new \RuntimeException('Error while generating the image.');
                                }
                                break;
                            case 'decode':
                                $this->logger->info('Preparing the decoder...', ['output' => $outputPathFile, 'tmp' => $tmpFile]);
                                
                                $this->encoder->prepare($outputPathFile, $tmpFile);

                                $this->logger->info('Starting decoding process...', ['output' => $outputPathFile, 'tmp' => $tmpFile]);

                                $decoded = $this->encoder->decode();

                                if ($decoded) {
                                    $templateData['message'] = 'Success! The music was decoded from the image.';
                                    $templateData['messageType'] = 'success';
                                    $templateData['downloadUrl'] = $outputFile;
                                    $templateData['metadata'] = $this->encoder->getMetadata();
                                } else {
                                    throw new \RuntimeException('Decoding failed. Make sure the image is a valid lossless WebP generated by this application.');
                                }
                                break;
                        }
                    } else {
                        throw new \RuntimeException("Please upload a valid {$mimeType} file.");
                    }
                } catch (\Throwable $exception) {
                    $templateData['message'] = $exception->getMessage();
                    $templateData['messageType'] = 'error';

                    if (($outputPathFile ?? null) !== null && file_exists($outputPathFile)) {
                        unlink($outputPathFile);
                    }

                    $this->logger->error($exception->getMessage(), [
                        'exception' => $exception,
                    ]);
                } finally {
                    if (($inputPathFile ?? null) !== null && file_exists($inputPathFile)) {
                        unlink($inputPathFile);
                    }
                }
            }
        }

        return $this->render('interface.twig', $templateData);
    }

    /** @param array<string, mixed> $variables */
    private function render(string $filePath, array $variables = []): Response
    {
        $twig = new Environment(new FilesystemLoader(__DIR__ . '/../Views'), [
            'cache' => false,
            'strict_variables' => true,
        ]);

        return new Response($twig->render($filePath, $variables));
    }

    private function createEncoder(string $encoderName): EncoderInterface
    {
        return match ($encoderName) {
            'cd' => new CdStyleEncoder($this->logger),
            'dvd' => new DvdStyleEncoder($this->logger),
            'bluray' => new BluRayStyleEncoder($this->logger),
            default => throw new InvalidArgumentException('Please choose a valid encoder.'),
        };
    }

    public function image(Request $request): Response
    {
        $file = $request->query->get('file', '');
        // Sanitize the path for security (allow access only to the images directory)
        $fileName = basename($file);
        $filePath = self::IMAGE_FILES_PATH . $fileName;

        if (file_exists($filePath) && is_file($filePath)) {
            return new BinaryFileResponse($filePath, Response::HTTP_OK, [
                'Content-Type' => 'image/webp',
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline',
            ]);
        } else {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }

    public function audio(Request $request): Response
    {
        $file = $request->query->get('file', '');
        // Sanitize the path for security (allow access only to the audio directory)
        $fileName = basename($file);
        $filePath = self::AUDIO_FILES_PATH . $fileName;

        if (file_exists($filePath) && is_file($filePath)) {
            return new BinaryFileResponse($filePath, Response::HTTP_OK, [
                'Content-Type' => 'audio/mpeg',
                'Accept-Ranges' => 'bytes',
                'Content-Disposition' => 'inline',
            ]);
        } else {
            return new Response('Not Found', Response::HTTP_NOT_FOUND);
        }
    }
}
