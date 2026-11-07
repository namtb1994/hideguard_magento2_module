<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Console\Command;

use Hachitechs\Hideguard\Model\Cache\{PolicyFiles, Activation};
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\{InputInterface, InputArgument};
use Symfony\Component\Console\Output\OutputInterface;

class CachePolicy extends Command
{
    public function __construct(private readonly PolicyFiles $files, private readonly Activation $activation)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hideguard:cache:policy')
            ->setDescription('Invalidate managed cache before deployment, or activate an already-loaded VCL snapshot.')
            ->addArgument('action', InputArgument::REQUIRED, 'invalidate or activate')
            ->addArgument('snapshot', InputArgument::OPTIONAL, 'SHA-256 snapshot ID, required for activate');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            if ($input->getArgument('action') === 'invalidate') {
                $this->files->locked(fn() => $this->files->invalidate());
                $output->writeln('Readiness retired. Managed proxies now pass HTML.');
            } elseif ($input->getArgument('action') === 'activate') {
                $this->activation->activate((string)$input->getArgument('snapshot'));
                $output->writeln('Snapshot acknowledged and activated on every configured proxy.');
            } else {
                throw new \InvalidArgumentException('Use invalidate or activate.');
            }
            return Command::SUCCESS;
        } catch (\Throwable $error) {
            $output->writeln('<error>' . htmlspecialchars($error->getMessage(), ENT_QUOTES) . '</error>');
            return Command::FAILURE;
        }
    }
}
