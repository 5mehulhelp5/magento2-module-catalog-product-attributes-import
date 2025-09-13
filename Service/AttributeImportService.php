<?php
/**
 * Aimane Couissi - https://aimanecouissi.com
 * Copyright © Aimane Couissi 2025–present. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Exception;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Eav\Model\Entity\Attribute\Backend\ArrayBackend;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Validator\ValidateException;
use Magento\Ui\Component\Form\Element\MultiSelect;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

class AttributeImportService
{
    const string BEHAVIOR_ADD = 'add';

    const string BEHAVIOR_UPDATE = 'update';

    const string BEHAVIOR_DELETE = 'delete';

    /**
     * @param EavSetupFactory $eavSetupFactory
     * @param ProductAttributeRepositoryInterface $productAttributeRepository
     * @param CsvService $csvService
     * @param AttributeOptionService $attributeOptionService
     * @param AttributeLabelService $attributeLabelService
     * @param AttributeSetService $attributeSetService
     * @param EavConfig $eavConfig
     */
    public function __construct(
        private readonly EavSetupFactory                     $eavSetupFactory,
        private readonly ProductAttributeRepositoryInterface $productAttributeRepository,
        private readonly CsvService                          $csvService,
        private readonly AttributeOptionService              $attributeOptionService,
        private readonly AttributeLabelService               $attributeLabelService,
        private readonly AttributeSetService                 $attributeSetService,
        private readonly EavConfig                           $eavConfig,
    )
    {
    }

    /**
     * Executes attribute import flow by behavior.
     *
     * @param array $csvData
     * @param string $behavior
     * @param OutputInterface $output
     * @return int
     */
    public function executeAttributeImport(array $csvData, string $behavior, OutputInterface $output): int
    {
        $headerMap = $this->csvService->buildHeaderMap($csvData[0]);
        if (!isset($headerMap['attribute_code'])) {
            $output->writeln("<error>The CSV file is missing the 'attribute_code' column</error>");
            return Command::FAILURE;
        }
        return $this->processAttributeRows($csvData, $headerMap, $behavior, $output);
    }

    /**
     * Processes attribute rows to add/update/delete.
     *
     * @param array $csvData
     * @param array $headerMap
     * @param string $behavior
     * @param OutputInterface $output
     * @return int
     */
    private function processAttributeRows(array $csvData, array $headerMap, string $behavior, OutputInterface $output): int
    {
        $eavSetup = $this->eavSetupFactory->create();
        $added = $updated = $deleted = $errors = 0;
        $this->csvService->iterateDataRows($csvData, function (array $row) use ($headerMap, $output, $eavSetup, $behavior, &$added, &$updated, &$deleted, &$errors) {
            $attributeCodeCell = $this->csvService->readCell($row, $headerMap, 'attribute_code');
            if ($attributeCodeCell === '') {
                return;
            }
            $attributeExists = (bool)$eavSetup->getAttribute(Product::ENTITY, $attributeCodeCell);
            $verb = match ($behavior) {
                self::BEHAVIOR_DELETE => 'Deleting',
                self::BEHAVIOR_ADD => 'Adding',
                default => $attributeExists ? 'Updating' : 'Adding',
            };
            $output->writeln("$verb attribute '$attributeCodeCell'...");
            if ($behavior === self::BEHAVIOR_DELETE) {
                if ($this->handleDeleteBehavior($behavior, $attributeExists, $attributeCodeCell, $eavSetup, $deleted, $output)) {
                    return;
                }
            }
            if ($behavior === self::BEHAVIOR_ADD && $attributeExists) {
                $output->writeln("<comment>The attribute '$attributeCodeCell' already exists and cannot be added; skipping</comment>");
                return;
            }
            $attributeData = $this->buildAttributeData($row, $headerMap);
            $frontendInput = $this->ensureFrontendInput($attributeData, $attributeExists, $attributeCodeCell, $errors, $output);
            $attributeData = $this->ensureBackendForMultiSelect($frontendInput, $attributeData);
            $this->preserveExistingDefault($attributeData, $attributeExists, $attributeCodeCell, $errors, $output);
            $this->checkFrontendInputChange($attributeExists, $frontendInput, $attributeCodeCell, $errors, $output);
            $this->ensureDefaultLabelFallback($attributeData, $attributeExists, $attributeCodeCell, $errors, $output);
            [$inputChanging, $attributeData] = $this->prepareOptionsTwoPhase(
                $frontendInput,
                $attributeData,
                $row,
                $headerMap,
                $attributeCodeCell,
                $attributeExists,
                $errors,
                $output
            );

            try {
                $this->saveAttributeWithTwoPhaseOptions(
                    $eavSetup,
                    $attributeCodeCell,
                    $attributeData,
                    $frontendInput,
                    $row,
                    $headerMap,
                    $inputChanging,
                    $errors,
                    $output
                );
                if ($behavior === self::BEHAVIOR_UPDATE) {
                    $attributeExists ? $updated++ : $added++;
                } else {
                    $added++;
                }
                $attribute = $this->productAttributeRepository->get($attributeCodeCell);
                $this->attributeOptionService->setDefaultOptions($frontendInput, $row, $headerMap, $errors, $attribute, $output);
                $this->attributeLabelService->applyScopedFrontendLabels($row, $headerMap, $errors, $attribute, $output);
                $this->attributeSetService->assignToAttributeSets($row, $headerMap, $attributeCodeCell, $eavSetup, $output);
            } catch (Exception $e) {
                $verb = $behavior === self::BEHAVIOR_UPDATE ? 'updating' : 'adding';
                $output->writeln("<error>An error occurred while $verb attribute '$attributeCodeCell': {$e->getMessage()}</error>");
                $errors++;
            }
        });
        if ($behavior === self::BEHAVIOR_DELETE) {
            $output->writeln("<info>Deleted $deleted attribute(s)</info>");
        } elseif ($behavior === self::BEHAVIOR_UPDATE) {
            $output->writeln("<info>Added $added attribute(s), updated $updated attribute(s)</info>");
        } else {
            $output->writeln("<info>Added $added attribute(s)</info>");
        }
        if ($errors > 0) {
            $output->writeln("<error>$errors error(s) occurred during import</error>");
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Handles delete behavior and updates counters.
     *
     * @param string $behavior
     * @param bool $attributeExists
     * @param string $attributeCode
     * @param EavSetup $eavSetup
     * @param int $deleted
     * @param OutputInterface $output
     * @return bool
     */
    private function handleDeleteBehavior(
        string          $behavior,
        bool            $attributeExists,
        string          $attributeCode,
        EavSetup        $eavSetup,
        int             &$deleted,
        OutputInterface $output
    ): bool
    {
        if ($behavior !== self::BEHAVIOR_DELETE || !$attributeExists) {
            $output->writeln("<comment>The attribute '$attributeCode' does not exist and cannot be deleted; skipping</comment>");
            return false;
        }
        $eavSetup->removeAttribute(Product::ENTITY, $attributeCode);
        $deleted++;
        return true;
    }

    /**
     * Builds an attribute data array from a row.
     *
     * @param array $row
     * @param array $headerMap
     * @return array
     */
    private function buildAttributeData(array $row, array $headerMap): array
    {
        $data = array_combine(
            array_keys($headerMap),
            array_map(function ($index) use ($row) {
                return trim((string)($row[$index] ?? ''));
            }, $headerMap)
        ) ?: [];
        unset($data['attribute_code'], $data['attribute_set'], $data['group']);
        $data = $this->filterSpecialKeys($data);
        return $this->normalizeSpecialKeys($data, $row, $headerMap);
    }

    /**
     * Removes label_* and option_* keys from attribute data.
     *
     * @param array $data
     * @return array
     */
    private function filterSpecialKeys(array $data): array
    {
        return array_filter(
            $data,
            fn($key) => !str_starts_with($key, 'label_') && !str_starts_with($key, 'option_'),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Normalizes special column keys (e.g., apply_to) into proper values.
     *
     * @param array $data
     * @param array $row
     * @param array $headerMap
     * @return array
     */
    private function normalizeSpecialKeys(array $data, array $row, array $headerMap): array
    {
        if (!isset($headerMap['apply_to'])) {
            return $data;
        }
        $applyToCell = $this->csvService->readCell($row, $headerMap, 'apply_to');
        if ($applyToCell === '') {
            return $data;
        }
        $applyTo = $this->csvService->parseList($applyToCell);
        if ($applyTo) {
            $data['apply_to'] = implode(',', array_unique($applyTo));
        }
        return $data;
    }

    /**
     * Ensures frontend input for existing attributes when not given.
     *
     * @param array $attributeData
     * @param bool $attributeExists
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return string|null
     */
    private function ensureFrontendInput(
        array           &$attributeData,
        bool            $attributeExists,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output
    ): ?string
    {
        $frontendInput = $attributeData['input'] ?? null;
        if ($attributeExists && !$frontendInput) {
            try {
                $attribute = $this->productAttributeRepository->get($attributeCode);
                $frontendInput = $attribute->getFrontendInput();
                if ($frontendInput !== '') {
                    $attributeData['input'] = $frontendInput;
                }
            } catch (NoSuchEntityException $e) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>An error occurred while loading existing attribute '$attributeCode': {$e->getMessage()}</comment>");
                }
                $errors++;
            }
        }
        return $frontendInput;
    }

    /**
     * Ensures the backend is set for multi-select attributes.
     *
     * @param mixed $frontendInput
     * @param array $attributeData
     * @return array
     */
    private function ensureBackendForMultiSelect(mixed $frontendInput, array $attributeData): array
    {
        if (($frontendInput ?? '') === MultiSelect::NAME && empty($attributeData['backend'])) {
            $attributeData['backend'] = ArrayBackend::class;
        }
        return $attributeData;
    }

    /**
     * Preserves existing default value when not provided.
     *
     * @param array $attributeData
     * @param bool $attributeExists
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     */
    private function preserveExistingDefault(
        array           &$attributeData,
        bool            $attributeExists,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output
    ): void
    {
        if (!$attributeExists || isset($attributeData['default']) || isset($attributeData['default_value'])) {
            return;
        }
        try {
            $attribute = $this->productAttributeRepository->get($attributeCode);
            $currentDefault = (string)$attribute->getDefaultValue();
            if ($currentDefault !== '') {
                $attributeData['default'] = $currentDefault;
            }
        } catch (NoSuchEntityException $e) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>An error occurred while loading existing attribute '$attributeCode': {$e->getMessage()}</comment>");
            }
            $errors++;
        }
    }

    /**
     * Checks and warns on frontend input type changes.
     *
     * @param bool $attributeExists
     * @param string|null $frontendInput
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     */
    private function checkFrontendInputChange(
        bool            $attributeExists,
        ?string         $frontendInput,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output
    ): void
    {
        if ($attributeExists && $frontendInput) {
            try {
                $attribute = $this->productAttributeRepository->get($attributeCode);
                $currentFrontendInput = strtolower((string)$attribute->getFrontendInput());
                $incomingFrontendInput = strtolower((string)$frontendInput);
                if ($currentFrontendInput !== '' && $incomingFrontendInput !== '' && $currentFrontendInput !== $incomingFrontendInput) {
                    $output->writeln("<comment>Warning: The frontend input type for attribute '$attributeCode' is changing from '$currentFrontendInput' to '$incomingFrontendInput'</comment>");
                }
            } catch (NoSuchEntityException $e) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>An error occurred while loading existing attribute '$attributeCode': {$e->getMessage()}</comment>");
                }
                $errors++;
            }
        }
    }

    /**
     * Ensures a default label is present when updating and the CSV label is missing.
     *
     * @param array $attributeData
     * @param bool $attributeExists
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     */
    private function ensureDefaultLabelFallback(
        array           &$attributeData,
        bool            $attributeExists,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output
    ): void
    {
        if (!($attributeExists && (!array_key_exists('label', $attributeData) || trim((string)$attributeData['label']) === ''))) {
            return;
        }
        try {
            $attribute = $this->productAttributeRepository->get($attributeCode);
            $defaultFrontendLabel = (string)$attribute->getDefaultFrontendLabel();
            if ($defaultFrontendLabel !== '') {
                $attributeData['label'] = $defaultFrontendLabel;
            }
        } catch (NoSuchEntityException $e) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>An error occurred while loading existing attribute '$attributeCode': {$e->getMessage()}</comment>");
            }
            $errors++;
        }
    }

    /**
     * Prepares option payload in a two-phase way when an input type changes.
     *
     * @param string|null $frontendInput
     * @param array $attributeData
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param bool $attributeExists
     * @param int $errors
     * @param OutputInterface $output
     * @return array [bool $inputChanging, array $attributeData]
     */
    private function prepareOptionsTwoPhase(
        ?string         $frontendInput,
        array           $attributeData,
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        bool            $attributeExists,
        int             &$errors,
        OutputInterface $output
    ): array
    {
        $currentFrontendInput = $this->getCurrentFrontendInputSafe($attributeExists, $attributeCode);
        $incomingFrontendInput = strtolower($frontendInput ?? '');
        $inputChanging = $attributeExists && $incomingFrontendInput !== '' && $currentFrontendInput !== '' && $incomingFrontendInput !== $currentFrontendInput;
        $attributeData = $this->attributeOptionService->buildOptionsData(
            $frontendInput,
            $attributeData,
            $row,
            $headerMap,
            $attributeCode,
            $errors,
            $output,
            $attributeExists
        );
        return [$inputChanging, $attributeData];
    }

    /**
     * Gets current frontend input safely for an existing attribute.
     *
     * @param bool $attributeExists
     * @param string $attributeCode
     * @return string
     */
    private function getCurrentFrontendInputSafe(bool $attributeExists, string $attributeCode): string
    {
        if (!$attributeExists) {
            return '';
        }
        try {
            return strtolower((string)$this->productAttributeRepository->get($attributeCode)->getFrontendInput());
        } catch (NoSuchEntityException) {
            return '';
        }
    }

    /**
     * Saves attribute and, if needed, applies second-pass option adds.
     *
     * @param EavSetup $eavSetup
     * @param string $attributeCode
     * @param array $attributeData
     * @param string|null $frontendInput
     * @param array $row
     * @param array $headerMap
     * @param bool $inputChanging
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     * @throws LocalizedException
     * @throws ValidateException
     */
    private function saveAttributeWithTwoPhaseOptions(
        EavSetup        $eavSetup,
        string          $attributeCode,
        array           $attributeData,
        ?string         $frontendInput,
        array           $row,
        array           $headerMap,
        bool            $inputChanging,
        int             &$errors,
        OutputInterface $output
    ): void
    {
        $eavSetup->addAttribute(Product::ENTITY, $attributeCode, $attributeData);
        $this->eavConfig->clear();
        if ($inputChanging) {
            $dummy = [];
            $this->attributeOptionService->buildOptionsData(
                $frontendInput,
                $dummy,
                $row,
                $headerMap,
                $attributeCode,
                $errors,
                $output,
                true
            );
        }
        $this->applyAdditionalAttributeFlags($eavSetup, $attributeCode, $attributeData);
        $this->eavConfig->clear();
    }

    /**
     * Applies additional attribute flags to an attribute.
     *
     * @param EavSetup $eavSetup
     * @param string $attributeCode
     * @param array $attributeData
     * @return void
     */
    private function applyAdditionalAttributeFlags(EavSetup $eavSetup, string $attributeCode, array $attributeData): void
    {
        $attributeFlags = ['is_used_for_price_rules', 'is_required_in_admin_store', 'is_pagebuilder_enabled'];
        foreach ($attributeFlags as $attributeFlag) {
            if (!array_key_exists($attributeFlag, $attributeData)) {
                continue;
            }
            $attributeFlagValue = trim((string)$attributeData[$attributeFlag]);
            $eavSetup->updateAttribute(Product::ENTITY, $attributeCode, $attributeFlag, $attributeFlagValue);
        }
    }
}
