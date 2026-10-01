<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Console\Command;

use EcomEU\DbTool\Model\Export\Exporter;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Thin CLI wrapper around the export engine. No table-specific logic lives here.
 *
 * bin/magento Tool:export:specialcustomer --customer_id=564321
 */
class ExportSpecialCustomerCommand extends Command
{
    private const string OPTION_CUSTOMER_ID = 'customer_id';

    /**
     * Constructor
     *
     * @param Exporter $exporter
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly Exporter $exporter,
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Conf
     *
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('Tool:export:specialcustomer')
            ->setDescription('Export all data for a customer to var/export/specialcustomer/<customer_id>/*.csv')
            ->addOption(
                self::OPTION_CUSTOMER_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'The customer entity id to export'
            );
        parent::configure();
    }

    /**
     * Exe
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
