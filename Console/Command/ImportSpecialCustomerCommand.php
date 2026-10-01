<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Console\Command;

use EcomEU\DbTool\Model\Import\Importer;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Thin CLI wrapper around the import engine. No table-specific logic lives here.
 *
 * bin/magento Tool:import:specialcustomer --customer_id=564321 [--mode=clone] [--dry-run]
 */
class ImportSpecialCustomerCommand extends AbstractSpecialCustomerCommand
{
    private const string OPTION_MODE = 'mode';
    private const string OPTION_DRY_RUN = 'dry-run';
    private const string MODE_RESTORE = 'restore';
    private const string MODE_CLONE = 'clone';

    /**
     * Constructor
     *
     * @param Importer $importer
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly Importer $importer,
        State $appState,
        ?string $name = null
    ) {
        parent::__construct($appState, $name);
    }

    /**
     * Configure
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('Tool:import:specialcustomer')
            ->setDescription(
                'Import previously exported customer data from var/import/specialcustomer/<customer_id>/*.csv'
            )
            ->addOption(
                self::OPTION_MODE,
                null,
                InputOption::VALUE_OPTIONAL,
                'Execution mode: "restore" (default; match & reuse existing rows) or '
                . '"clone" (force-create a NEW customer + footprint; email/order numbers are regenerated '
                . 'only if they collide on the target)',
                self::MODE_RESTORE
            )
            ->addOption(
                self::OPTION_DRY_RUN,
                null,
                InputOption::VALUE_NONE,
                'Run the whole import inside a transaction and roll it back: reports what would happen '
                . '(matched / inserted / dropped rows, ignored columns) without writing anything'
            );
        $this->addCustomerIdOption('The customer entity id to import');
        parent::configure();
    }

    /**
     * Execute
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $customerId = $this->readCustomerId($input, $output);
        if ($customerId === null) {
            return Command::FAILURE;
        }

        $mode = (string)($input->getOption(self::OPTION_MODE) ?? self::MODE_RESTORE);
        if (!in_array($mode, [self::MODE_RESTORE, self::MODE_CLONE], true)) {
            $output->writeln(
                sprintf(
                    '<error>--mode must be "%s" or "%s".</error>',
                    self::MODE_RESTORE,
                    self::MODE_CLONE
                )
            );
            return Command::FAILURE;
        }
        $cloneMode = $mode === self::MODE_CLONE;
        $dryRun = (bool)$input->getOption(self::OPTION_DRY_RUN);

        $this->ensureAreaCode();

        if ($dryRun) {
            $output->writeln('<comment>DRY RUN: nothing will be written (the transaction is rolled back).</comment>');
        }
        if ($cloneMode) {
            $output->writeln(sprintf(
                '<info>Cloning customer %d as a NEW customer '
                . '(email + order numbers regenerated only on collision)...</info>',
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
                $cloneMode,
                $dryRun
            );
        } catch (Throwable $e) {
            $output->writeln('<error>Import failed (nothing was written): ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $total = array_sum($result['tables']);
        $output->writeln(sprintf(
            '<info>%s %d row(s) imported across %d table(s) from %s</info>',
            $dryRun ? 'Dry run:' : 'Done.',
            $total,
            count($result['tables']),
            $result['directory']
        ));
        if ($result['skipped'] !== []) {
            $output->writeln('<comment>Skipped (no CSV present): ' . implode(', ', $result['skipped']) . '</comment>');
        }
        if ($result['absent'] !== []) {
            $output->writeln(
                '<comment>Not on this database (skipped): ' . implode(', ', $result['absent']) . '</comment>'
            );
        }
        foreach ($result['ignored_columns'] as $table => $columns) {
            $output->writeln(sprintf(
                '<comment>%s: column(s) not on this database ignored: %s</comment>',
                $table,
                implode(', ', $columns)
            ));
        }
        if (!empty($result['dropped'])) {
            $output->writeln(sprintf(
                '<comment>%d row(s) dropped: a row they require exists neither in the export nor on this database.'
                . '</comment>',
                $result['dropped']
            ));
        }
        if (!empty($result['unlinked'])) {
            $output->writeln(sprintf(
                '<comment>%d link(s) to rows that exist neither in the export nor on this database were set to NULL.'
                . '</comment>',
                $result['unlinked']
            ));
        }
        if (!empty($result['deferred_updates'])) {
            $output->writeln(sprintf(
                '<info>%d back-reference(s) rewritten after import (default addresses, order address ids).</info>',
                $result['deferred_updates']
            ));
        }
        $customers = $result['new_customers'];
        if ($customers === []) {
            $output->writeln('<comment>No customer row was imported.</comment>');
        } else {
            $label = $cloneMode ? 'New customer' : 'Customer';
            foreach ($customers as $customer) {
                $output->writeln(sprintf(
                    '<info>%s: id=%s  email=%s</info>',
                    $label,
                    $customer['id'],
                    $customer['email']
                ));
            }
        }
        if ($cloneMode) {
            $output->writeln(sprintf('<comment>Clone token: %s</comment>', $result['clone_token']));
        }
        if ($dryRun) {
            $output->writeln('<comment>DRY RUN: the transaction was rolled back, the database is unchanged.</comment>');
        }

        return Command::SUCCESS;
    }
}
