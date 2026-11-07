<?php

declare(strict_types=1);

namespace Hachitechs\Hideguard\Console\Command;

use Hachitechs\Hideguard\Model\StateRepository;
use Hachitechs\Hideguard\Model\StoreLock;
use Magento\Store\Model\StoreManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/** Run after migrating or an out-of-band database repair; never deletes security state. */
class ReconcileSessions extends Command
{
    public function __construct(
        private readonly StateRepository $storage,
        private readonly StoreLock $atomic,
        private readonly StoreManagerInterface $stores
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('hideguard:sessions:reconcile')
            ->setDescription('Rebuild HideGuard session counters under an exclusive store fence.')
            ->addOption('store', null, InputOption::VALUE_REQUIRED, 'Store ID; omit for all stores');
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $selected = $input->getOption('store');
        if ($selected !== null && (!ctype_digit((string)$selected) || (int)$selected < 1 || (int)$selected > 65535)) {
            $output->writeln('<error>Store must be an existing storefront store ID (1..65535).</error>');
            return Command::FAILURE;
        }
        $stores = $selected === null ? $this->stores->getStores() : [$this->stores->getStore((int)$selected)];
        $failed = false;
        foreach ($stores as $store) {
            $id = (string)$store->getId();
            try {
                $count = $this->atomic->run($id, fn() => $this->storage->reconcileSessionCount($id));
                $output->writeln('Store ' . $id . ': ' . $count . ' sessions');
            } catch (\Throwable $error) {
                $failed = true;
                $output->writeln('<error>Store ' . $id . ': ' . $error->getMessage() . '</error>');
            }
        }
        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
