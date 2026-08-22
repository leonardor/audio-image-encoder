<?php

declare(strict_types=1);

namespace CdEncoder\UI\Cli\Commands;

use CdEncoder\Application\Services\CdEncoder;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CliCommand extends Command
{
    public function __construct(private LoggerInterface $logger, string $name = 'cd-encoder')
    {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this
            ->setDescription('Encode audio into lossless WebP or decode WebP back to audio.')
            ->addArgument('operation', InputArgument::REQUIRED, 'Operation: encode, encode-max, or decode.')
            ->addArgument('input', InputArgument::REQUIRED, 'Input MP3 or WebP file.')
            ->addArgument('output', InputArgument::REQUIRED, 'Output WebP or MP3 file.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $operation = $input->getArgument('operation');
        $inputPath = $input->getArgument('input');
        $outputPath = $input->getArgument('output');

        if ($operation === 'decode') {
            $encoder = new CdEncoder($this->logger);
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

        $profile = $operation === 'encode-max'
            ? CdEncoder::PROFILE_DIGITAL_MAX
            : CdEncoder::PROFILE_STANDARD;
        $encoder = new CdEncoder($this->logger);
        $encoder->prepare($inputPath, $outputPath, $profile);

        if (!$encoder->encode()) {
            $output->writeln('<error>Encoding failed.</error>');

            return Command::FAILURE;
        }

        $output->writeln('<info>Encoded successfully.</info>');

        return Command::SUCCESS;
    }
}
