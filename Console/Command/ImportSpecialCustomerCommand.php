<?php
declare(strict_types=1);

namespace Tool\DbTool\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Tool\DbTool\Model\Import\Importer;

/**
 * Thin CLI wrapper around the import engine. No table-specific logic lives here.
 *
 * bin/magento Tool:import:specialcustomer --customer_id=564321
 */
class ImportSpecialCustomerCommand extends Command
{
    private const OPTION_CUSTOMER_ID = 'customer_id';
    private const OPTION_MODE = 'mode';
    private const MODE_RESTORE = 'restore';
    private const MODE_CLONE = 'clone';

    private Importer $importer;
    private State $appState;

    public function __construct(Importer $importer, State $appState, ?string $name = null)
    {
        $this->importer = $importer;
        $this->appState = $appState;
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('Tool:import:specialcustomer')
            ->setDescription('Import previously exported customer data from var/import/specialcustomer/<customer_id>/*.csv')
            ->addOption(
                self::OPTION_CUSTOMER_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'The customer entity id to import'
            )
            ->addOption(
                self::OPTION_MODE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Execution mode: "restore" (default; match & reuse existing rows) or '
                . '"clone" (force-create a NEW customer + footprint with regenerated email/order numbers)',
                self::MODE_RESTORE
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $input->getOption(self::OPTION_CUSTOMER_ID);
        if ($customerId === null || !ctype_digit((string)$customerId)) {
            $output->writeln('<error>--customer_id is required and must be a positive integer.</error>');
            return Command::FAILURE;
        }
        $customerId = (int)$customerId;

        $mode = (string)($input->getOption(self::OPTION_MODE) ?? self::MODE_RESTORE);
        if (!in_array($mode, [self::MODE_RESTORE, self::MODE_CLONE], true)) {
            $output->writeln(sprintf('<error>--mode must be "%s" or "%s".</error>', self::MODE_RESTORE, self::MODE_CLONE));
            return Command::FAILURE;
        }
        $cloneMode = $mode === self::MODE_CLONE;

        $this->ensureAreaCode();

        if ($cloneMode) {
            $output->writeln(sprintf(
                '<info>Cloning customer %d as a NEW customer (email + order numbers will be regenerated)...</info>',
                $customerId
            ));
        } else {
            $output->writeln(sprintf('<info>Importing customer %d...</info>', $customerId));
        }

        try {
            $result = $this->importer->import(
                $customerId,
                static function (string $table, int $count, string $status) use ($output): void {
                    $output->writeln(sprintf('  %-28s %-8s %d row(s)', $table, $status, $count));
                },
                $cloneMode
            );
        } catch (\Throwable $e) {
            $output->writeln('<error>Import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $total = array_sum($result['tables']);
        $output->writeln(sprintf('<info>Done. %d row(s) imported across %d table(s) from %s</info>',
            $total, count($result['tables']), $result['directory']));
        if ($result['skipped'] !== []) {
            $output->writeln('<comment>Skipped (no CSV present): ' . implode(', ', $result['skipped']) . '</comment>');
        }
        if ($cloneMode) {
            $newIds = $result['new_customer_ids'] ?? [];
            $output->writeln(sprintf(
                '<info>Clone token: %s — new customer id(s): %s (email suffix "+clone-%s")</info>',
                (string)$result['clone_token'],
                $newIds === [] ? '(none)' : implode(', ', $newIds),
                (string)$result['clone_token']
            ));
        }

        return Command::SUCCESS;
    }

    /**
     * Some resource models expect an area code when invoked from CLI.
     */
    private function ensureAreaCode(): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (\Throwable $e) {
            // Area code already set — nothing to do.
        }
    }
}
