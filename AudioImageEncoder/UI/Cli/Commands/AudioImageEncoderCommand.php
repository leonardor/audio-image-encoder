<?php

declare(strict_types=1);

namespace AudioImageEncoder\UI\Cli\Commands;

use AudioImageEncoder\Application\Services\DvdStyleEncoder;
use AudioImageEncoder\Application\Services\BluRayStyleEncoder;
use AudioImageEncoder\Application\Services\CdStyleEncoder;
use InvalidArgumentException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class AudioImageEncoderCommand extends Command
{
    public function __construct(private LoggerInterface $logger, string $name = 'audio-image-encoder')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Encode audio into lossless WebP or decode WebP back to audio.')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation: encode, encode-max, or decode.')
            ->addArgument('input', InputArgument::REQUIRED, 'Input MP3 or WebP file.')
            ->addArgument('output', InputArgument::REQUIRED, 'Output WebP or MP3 file.')
            ->addArgument('encoder', InputArgument::OPTIONAL, 'Encoder: cd, dvd, or bluray.', 'dvd');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = $input->getArgument('operation');
        $inputPath = $input->getArgument('input');
        $outputPath = $input->getArgument('output');
        $encoderName = $input->getArgument('encoder');

        if (!is_string($operation) || !is_string($encoderName) || !is_string($inputPath) || !is_string($outputPath)) {
            throw new InvalidArgumentException('CLI arguments must be strings.');
        }

        $encoder = $this->createEncoder($encoderName);

        if ($operation === 'decode') {
            $encoder->prepare($outputPath, $inputPath);

            if (!$encoder->decode()) {
                $output->writeln('<error>Decoding failed.</error>');

                return Command::FAILURE;
            }

            $output->writeln('<info>Decoded successfully.</info>');

            return Command::SUCCESS;
        }

        if (!in_array($operation, ['encode', 'encode-max'], true)) {
            $output->writeln('<error>Unknown operation. Use encode, encode-max, or decode.</error>');

            return Command::INVALID;
        }

        if ($operation === 'encode-max' && $encoderName !== 'dvd') {
            $output->writeln('<error>The encode-max profile is available only for the DVD encoder.</error>');

            return Command::INVALID;
        }

        $profile = $encoderName === 'dvd' && $operation === 'encode-max'
            ? DvdStyleEncoder::PROFILE_DIGITAL_MAX
            : DvdStyleEncoder::PROFILE_STANDARD;
        $encoder->prepare($inputPath, $outputPath, $profile);

        if (!$encoder->encode()) {
            $output->writeln('<error>Encoding failed.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Encoded successfully.</info>');

        return Command::SUCCESS;
    }

    private function createEncoder(string $encoderName): \AudioImageEncoder\Application\Contracts\EncoderInterface
    {
        return match ($encoderName) {
            'cd' => new CdStyleEncoder($this->logger),
            'dvd' => new DvdStyleEncoder($this->logger),
            'bluray' => new BluRayStyleEncoder($this->logger),
            default => throw new InvalidArgumentException('Encoder must be cd, dvd, or bluray.'),
        };
    }
}
