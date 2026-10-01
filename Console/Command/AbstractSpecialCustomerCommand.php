<?php
declare(strict_types=1);

namespace EcomEU\DbTool\Console\Command;

use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Shared plumbing of the export/import commands: the --customer_id option, its strict validation
 * and the CLI area code. No table-specific logic lives here.
 */
abstract class AbstractSpecialCustomerCommand extends Command
{
    protected const string OPTION_CUSTOMER_ID = 'customer_id';

    /**
     * @param State $appState
     * @param string|null $name
     */
    public function __construct(
        private readonly State $appState,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    /**
     * Declare the --customer_id option.
     *
     * @param string $description
     * @return void
     */
    protected function addCustomerIdOption(string $description): void
    {
        $this->addOption(self::OPTION_CUSTOMER_ID, null, InputOption::VALUE_REQUIRED, $description);
    }

    /**
     * The validated --customer_id, or null (after printing the error) when it is missing or invalid.
     *
     * Accepts a strictly positive integer that fits in PHP's int; "0", "-1", "12abc", "1e3" and an
     * out-of-range value are rejected.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int|null
     */
    protected function readCustomerId(InputInterface $input, OutputInterface $output): ?int
    {
        $raw = $input->getOption(self::OPTION_CUSTOMER_ID);
        $customerId = is_scalar($raw)
            ? filter_var(trim((string)$raw), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;
        if ($customerId === false) {
            $output->writeln('<error>--customer_id is required and must be a positive integer.</error>');
            return null;
        }
        return $customerId;
    }

    /**
     * Some resource models expect an area code when invoked from CLI.
     *
     * @return void
     */
    protected function ensureAreaCode(): void
    {
        try {
            // Throws when no area code has been set yet.
            $this->appState->getAreaCode();
        } catch (LocalizedException $e) {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        }
    }
}
