<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Console\Command;

use EcomEU\DbTool\Model\Import\Importer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Thin CLI wrapper around the import engine. No table-specific logic lives here.
 *
 * bin/magento Tool:import:specialcustomer --customer_id=564321
 */
class ImportSpecialCustomerCommand extends Command
{
    private const string OPTION_CUSTOMER_ID = 'customer_id';
    private const string OPTION_MODE = 'mode';
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
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
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
                . '"clone" (force-create a NEW customer + footprint; email/order numbers are regenerated '
                . 'only if they collide on the target)',
                self::MODE_RESTORE
            );
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
        $customerId = $input->getOption(self::OPTION_CUSTOMER_ID);
        if ($customerId === null || !ctype_digit((string)$customerId)) {
            $output->writeln('<error>--customer_id is required and must be a positive integer.</error>');
            return Command::FAILURE;
        }
        $customerId = (int)$customerId;

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

        $this->ensureAreaCode();

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
                $cloneMode
            );
        } catch (Throwable $e) {
            $output->writeln('<error>Import failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $total = array_sum($result['tables']);
        $output->writeln(sprintf(
            '<info>Done. %d row(s) imported across %d table(s) from %s</info>',
            $total,
            count($result['tables']),
            $result['directory']
        ));
        if ($result['skipped'] !== []) {
            $output->writeln('<comment>Skipped (no CSV present): ' . implode(', ', $result['skipped']) . '</comment>');
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

        return Command::SUCCESS;
    }

    /**
     * Some resource models expect an area code when invoked from CLI.
     */
    private function ensureAreaCode(): void
    {
        try {
            // Throws when no area code has been set yet.
            $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
    }
}
