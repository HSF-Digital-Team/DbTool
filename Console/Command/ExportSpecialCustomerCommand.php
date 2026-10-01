<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Console\Command;

use EcomEU\DbTool\Model\Export\Exporter;
use Magento\Framework\App\State;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Thin CLI wrapper around the export engine. No table-specific logic lives here.
 *
 * bin/magento Tool:export:specialcustomer --customer_id=564321
 */
class ExportSpecialCustomerCommand extends AbstractSpecialCustomerCommand
{
    /**
     * Constructor
     *
     * @param Exporter $exporter
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly Exporter $exporter,
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
        $this->setName('Tool:export:specialcustomer')
            ->setDescription('Export all data for a customer to var/export/specialcustomer/<customer_id>/*.csv');
        $this->addCustomerIdOption('The customer entity id to export');
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

        $this->ensureAreaCode();

        $output->writeln(sprintf('<info>Exporting customer %d...</info>', $customerId));

        try {
            $result = $this->exporter->export(
                $customerId,
                static function (string $table, int $count, string $status) use ($output): void {
                    $output->writeln(sprintf('  %-36s %-8s %d row(s)', $table, $status, $count));
                }
            );
        } catch (Throwable $e) {
            $output->writeln('<error>Export failed: ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        $total = array_sum($result['tables']);
        $output->writeln(sprintf(
            '<info>Done. %d row(s) across %d table(s) written to %s</info>',
            $total,
            count($result['tables']),
            $result['directory']
        ));
        if ($result['absent'] !== []) {
            $output->writeln(
                '<comment>Not on this database (skipped): ' . implode(', ', $result['absent']) . '</comment>'
            );
        }

        try {
            $zipPath = $this->exporter->zip($customerId);
            $output->writeln(sprintf('<info>Archive: %s</info>', $zipPath));
        } catch (Throwable $e) {
            $output->writeln('<comment>Zip archive not created: ' . $e->getMessage() . '</comment>');
        }

        return Command::SUCCESS;
    }
}
