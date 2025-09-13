<?php
/**
 * Aimane Couissi - https://aimanecouissi.com
 * Copyright © Aimane Couissi 2025–present. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Console\Command;

use AimaneCouissi\CatalogProductAttributesImport\Service\AttributeImportService;
use AimaneCouissi\CatalogProductAttributesImport\Service\AttributeSetService;
use AimaneCouissi\CatalogProductAttributesImport\Service\CsvService;
use Magento\Framework\App\Area;
use Magento\Framework\App\State as AppState;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ProductAttributesImport extends Command
{
    private const string ARGUMENT_CSV = 'csv';

    private const string OPTION_TYPE = 'type';

    private const string OPTION_BEHAVIOR = 'behavior';

    private const string TYPE_ATTRIBUTE = 'attribute';

    private const string TYPE_ATTRIBUTE_SET = 'attribute-set';

    private const array ATTRIBUTE_TYPES = [self::TYPE_ATTRIBUTE, self::TYPE_ATTRIBUTE_SET];

    private const array IMPORT_BEHAVIORS = [
        AttributeImportService::BEHAVIOR_ADD,
        AttributeImportService::BEHAVIOR_UPDATE,
        AttributeImportService::BEHAVIOR_DELETE
    ];

    /**
     * @param AppState $appState
     * @param CsvService $csvService
     * @param AttributeSetService $attributeSetService
     * @param AttributeImportService $attributeImportService
     * @param string|null $name
     */
    public function __construct(
        private readonly AppState               $appState,
        private readonly CsvService             $csvService,
        private readonly AttributeSetService    $attributeSetService,
        private readonly AttributeImportService $attributeImportService,
        ?string                                 $name = null,
    )
    {
        parent::__construct($name);
    }

    /**
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('catalog:product:attributes:import');
        $this->setDescription('Imports product attributes from a CSV file.');
        $this->addArgument(
            self::ARGUMENT_CSV,
            InputArgument::REQUIRED,
            'Path to the CSV file relative to var/ directory'
        );
        $this->addOption(
            self::OPTION_TYPE,
            't',
            InputOption::VALUE_REQUIRED,
            'Type of entity to import; attribute or attribute-set',
            self::TYPE_ATTRIBUTE
        );
        $this->addOption(
            self::OPTION_BEHAVIOR,
            'b',
            InputOption::VALUE_REQUIRED,
            'Import behavior; add, update, or delete',
            AttributeImportService::BEHAVIOR_ADD
        );
        parent::configure();
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->setAdminAreaCode($output);
        $csvData = $this->loadCsvOrFail($input, $output);
        if (is_null($csvData)) {
            return Command::FAILURE;
        }
        $type = (string)$input->getOption(self::OPTION_TYPE);
        if (!$this->isValidType($type, $output)) {
            return Command::FAILURE;
        }
        $behavior = (string)$input->getOption(self::OPTION_BEHAVIOR);
        if (!$this->isValidBehavior($behavior, $output)) {
            return Command::FAILURE;
        }
        return $this->executeByType($csvData, $type, $behavior, $output);
    }

    /**
     * Sets the area code to adminhtml safely.
     *
     * @param OutputInterface $output
     * @return void
     */
    private function setAdminAreaCode(OutputInterface $output): void
    {
        try {
            $this->appState->setAreaCode(Area::AREA_ADMINHTML);
        } catch (LocalizedException $e) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>An error occurred while setting the area code: {$e->getMessage()}</comment>");
            }
        }
    }

    /**
     * Loads CSV from argument and validates shape; returns data or null on failure.
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return array|null
     */
    private function loadCsvOrFail(InputInterface $input, OutputInterface $output): ?array
    {
        $csvArg = (string)$input->getArgument(self::ARGUMENT_CSV);
        $csvPath = $this->csvService->resolveCsvPath($csvArg);
        if (is_null($csvPath) || !$this->csvService->validateCsvPath($csvPath, $output)) {
            return null;
        }
        $csvData = $this->csvService->readCsvData($csvPath, $output);
        if (is_null($csvData) || !$this->csvService->validateCsvData($csvData, $output)) {
            return null;
        }
        return $csvData;
    }

    /**
     * Validates --type option against supported values.
     *
     * @param string $type
     * @param OutputInterface $output
     * @return bool
     */
    private function isValidType(string $type, OutputInterface $output): bool
    {
        if (!in_array($type, self::ATTRIBUTE_TYPES, true)) {
            $output->writeln("<error>Invalid --type '$type'; must be one of: " . implode(', ', self::ATTRIBUTE_TYPES) . "</error>");
            return false;
        }
        return true;
    }

    /**
     * Validates --behavior option against supported values.
     *
     * @param string $behavior
     * @param OutputInterface $output
     * @return bool
     */
    private function isValidBehavior(string $behavior, OutputInterface $output): bool
    {
        if (!in_array($behavior, self::IMPORT_BEHAVIORS, true)) {
            $output->writeln("<error>Invalid --behavior '$behavior'; must be one of: " . implode(', ', self::IMPORT_BEHAVIORS) . "</error>");
            return false;
        }
        return true;
    }

    /**
     * Executes the import flow based on the --type provided.
     *
     * @param array $csvData
     * @param string $type
     * @param string $behavior
     * @param OutputInterface $output
     * @return int
     */
    private function executeByType(
        array           $csvData,
        string          $type,
        string          $behavior,
        OutputInterface $output
    ): int
    {
        if ($type === self::TYPE_ATTRIBUTE_SET) {
            if ($behavior !== AttributeImportService::BEHAVIOR_DELETE) {
                $output->writeln("<error>Invalid --behavior '$behavior' for type '$type'; must be 'delete'</error>");
                return Command::FAILURE;
            }
            return $this->attributeSetService->executeAttributeSetImport($csvData, $output);
        }
        return $this->attributeImportService->executeAttributeImport($csvData, $behavior, $output);
    }
}
