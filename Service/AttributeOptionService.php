<?php
/**
 * Aimane Couissi - https://aimanecouissi.com
 * Copyright © Aimane Couissi 2025–present. All rights reserved.
 * Licensed under the MIT License. See LICENSE for details.
 */

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeOptionManagementInterface;
use Magento\Eav\Api\Data\AttributeOptionInterfaceFactory;
use Magento\Eav\Api\Data\AttributeOptionLabelInterfaceFactory;
use Magento\Framework\Exception\LocalizedException;
use Magento\Ui\Component\Form\Element\MultiSelect;
use Magento\Ui\Component\Form\Element\Select;
use Symfony\Component\Console\Output\OutputInterface;

class AttributeOptionService
{
    /**
     * @param AttributeOptionManagementInterface $attributeOptionManagement
     * @param AttributeOptionInterfaceFactory $optionFactory
     * @param AttributeOptionLabelInterfaceFactory $optionLabelFactory
     * @param ProductAttributeRepositoryInterface $productAttributeRepository
     * @param CsvService $csvService
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly AttributeOptionManagementInterface   $attributeOptionManagement,
        private readonly AttributeOptionInterfaceFactory      $optionFactory,
        private readonly AttributeOptionLabelInterfaceFactory $optionLabelFactory,
        private readonly ProductAttributeRepositoryInterface  $productAttributeRepository,
        private readonly CsvService                           $csvService,
        private readonly StoreResolver                        $storeResolver,
    )
    {
    }

    /**
     * Builds options payload (with scoped labels) for select/multiselect.
     *
     * @param string|null $frontendInput
     * @param array $data
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @param bool $attributeExists
     * @return array
     */
    public function buildOptionsData(
        ?string         $frontendInput,
        array           $data,
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output,
        bool            $attributeExists = false
    ): array
    {
        $sourceCell = $this->csvService->readCell($row, $headerMap, 'source');
        if (!$this->supportsOptions($frontendInput, $sourceCell)) {
            unset($data['option']);
            return $data;
        }
        $baseOptionLabels = $this->prepareBaseOptionLabels($data, $row, $headerMap, $attributeCode, $output);
        if (empty($baseOptionLabels)) {
            return $data;
        }
        $shouldReplaceOptions = $this->handleReplaceStrategy(
            $attributeExists,
            $row,
            $headerMap,
            $attributeCode,
            $errors,
            $output
        );
        $scopedOptionColumns = $this->collectScopedOptionColumns($row, $headerMap, $attributeCode, $output);
        $optionValues = $this->buildOptionValues($baseOptionLabels, $scopedOptionColumns, $attributeCode, $output);
        $optionOrders = $this->buildOptionOrders($row, $headerMap, $optionValues, $attributeCode, $output);
        [$optionValues, $optionOrders] = $this->mergeOptions(
            $attributeExists,
            $shouldReplaceOptions,
            $attributeCode,
            $optionValues,
            $optionOrders,
            $errors,
            $output
        );
        if ($this->maybeAddOptionsIncrementally(
            $attributeExists,
            $shouldReplaceOptions,
            $attributeCode,
            $optionValues,
            $optionOrders,
            $errors,
            $output
        )) {
            $data['option'] = null;
            return $data;
        }
        $data['option'] = !empty($optionValues) ? ['value' => $optionValues] : null;
        $data['option'] = !empty($optionValues) && !empty($optionOrders)
            ? array_merge($data['option'], ['order' => $optionOrders])
            : $data['option'];
        return $data;
    }

    /**
     * Returns true if the frontend input supports options.
     *
     * @param string|null $frontendInput
     * @param string $sourceCell
     * @return bool
     */
    private function supportsOptions(?string $frontendInput, string $sourceCell): bool
    {
        $frontendInput = strtolower(trim($frontendInput ?? ''));
        return ($frontendInput === Select::NAME || $frontendInput === MultiSelect::NAME) && $sourceCell === '';
    }

    /**
     * Reads, validates and de-duplicates base option labels; handles 'source' conflict and empty case.
     *
     * @param array $data
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function prepareBaseOptionLabels(
        array           &$data,
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $optionCell = $this->csvService->readCell($row, $headerMap, 'option');
        if ($this->csvService->readCell($row, $headerMap, 'source') !== '') {
            if ($optionCell !== '' && $output->isVerbose()) {
                $output->writeln("<comment>Both 'option' and 'source' are set for attribute '$attributeCode'; ignoring 'option'</comment>");
            }
            unset($data['option']);
            return [];
        }
        $baseOptionLabels = $this->csvService->parseList($optionCell);
        if (empty($baseOptionLabels)) {
            unset($data['option']);
            return [];
        }
        $this->warnDuplicateOptionLabels($baseOptionLabels, $attributeCode, $output);
        return $this->dedupeOptionLabels($baseOptionLabels);
    }

    /**
     * Warns about duplicate option labels within a row.
     *
     * @param array $optionLabels
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return void
     */
    private function warnDuplicateOptionLabels(array $optionLabels, string $attributeCode, OutputInterface $output): void
    {
        $processedOptionLabels = [];
        $duplicateOptionLabels = [];
        foreach ($optionLabels as $optionLabel) {
            $optionLabel = $this->normalizeLabel(trim((string)$optionLabel));
            if ($optionLabel === '') {
                continue;
            }
            isset($processedOptionLabels[$optionLabel])
                ? $duplicateOptionLabels[$optionLabel] = true
                : $processedOptionLabels[$optionLabel] = true;
        }
        if (!empty($duplicateOptionLabels) && $output->isVerbose()) {
            $output->writeln("<comment>The attribute '$attributeCode' contains duplicate option labels: " . implode(', ', array_keys($duplicateOptionLabels)) . "; keeping first occurrence(s)</comment>");
        }
    }

    /**
     * Normalizes labels for consistent matching.
     *
     * @param string $optionLabel
     * @return string
     */
    private function normalizeLabel(string $optionLabel): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($optionLabel)) ?? '');
    }

    /**
     * De-dupes normalized labels while preserving the first occurrence.
     *
     * @param array $optionLabels
     * @return array
     */
    private function dedupeOptionLabels(array $optionLabels): array
    {
        $seen = [];
        $out = [];
        foreach ($optionLabels as $optionLabel) {
            $normalizedOptionLabel = $this->normalizeLabel((string)$optionLabel);
            if ($normalizedOptionLabel === '' || isset($seen[$normalizedOptionLabel])) {
                continue;
            }
            $seen[$normalizedOptionLabel] = true;
            $out[] = $optionLabel;
        }
        return $out;
    }

    /**
     * Performs "replace" strategy if requested.
     *
     * @param bool $attributeExists
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return bool True when replace should be applied
     */
    private function handleReplaceStrategy(
        bool            $attributeExists,
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        int             &$errors,
        OutputInterface $output
    ): bool
    {
        $optionStrategyCell = strtolower($this->csvService->readCell($row, $headerMap, 'option_strategy'));
        $shouldReplaceOptions = $attributeExists && $optionStrategyCell === 'replace';
        if ($shouldReplaceOptions) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>Replacing existing options for attribute '$attributeCode'; existing options will be deleted before adding new ones</comment>");
            }
            $this->deleteAttributeOptions($attributeCode, $errors, $output);
        }
        return $shouldReplaceOptions;
    }

    /**
     * Deletes all existing options for an attribute.
     *
     * @param string $attributeCode
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     */
    private function deleteAttributeOptions(string $attributeCode, int &$errors, OutputInterface $output): void
    {
        try {
            $options = $this->attributeOptionManagement->getItems(Product::ENTITY, $attributeCode);
            foreach ($options as $option) {
                $optionId = (int)$option->getValue();
                if ($optionId > 0) {
                    $this->attributeOptionManagement->delete(Product::ENTITY, $attributeCode, $optionId);
                }
            }
        } catch (LocalizedException $e) {
            $output->writeln("<error>An error occurred while deleting existing options for replacement for attribute '$attributeCode': {$e->getMessage()}</error>");
            $errors++;
        }
    }

    /**
     * Collects store-scoped option columns from the row.
     *
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function collectScopedOptionColumns(
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $byStore = [];
        foreach ($headerMap as $key => $index) {
            if (!(str_starts_with($key, 'option_') && strlen($key) > 7)) {
                continue;
            }
            if (in_array($key, ['option_order', 'option_strategy'])) {
                continue;
            }
            $cell = trim((string)($row[$index] ?? ''));
            if ($cell === '') {
                continue;
            }
            $storeCode = substr($key, 7);
            $storeId = $this->storeResolver->getStoreIdByCode($storeCode);
            if (is_null($storeId)) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>The store code '$storeCode' is not valid for attribute '$attributeCode' on column 'option_$storeCode'</comment>");
                }
                continue;
            }
            $byStore[$storeId] = array_values($this->csvService->parseList($cell));
        }
        return $byStore;
    }

    /**
     * Builds the 'option' values payload keyed by index.
     *
     * @param array $baseOptionLabels
     * @param array $scopedOptionColumns
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function buildOptionValues(
        array           $baseOptionLabels,
        array           $scopedOptionColumns,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $optionValues = [];
        $this->warnInconsistentOptionCounts($scopedOptionColumns, count($baseOptionLabels), $attributeCode, $output);
        foreach ($baseOptionLabels as $index => $baseOptionLabel) {
            $baseOptionLabel = $this->fallbackBaseOptionLabel((string)$baseOptionLabel, $index, $scopedOptionColumns, $output);
            if ($baseOptionLabel === '') {
                continue;
            }
            $key = 'option_' . $index;
            $optionValues[$key] = [0 => $baseOptionLabel];
            foreach ($scopedOptionColumns as $storeId => $scopedOptionLabels) {
                $scopedOptionLabel = $scopedOptionLabels[$index] ?? '';
                if ($scopedOptionLabel !== '') {
                    $optionValues[$key][(int)$storeId] = $scopedOptionLabel;
                }
            }
        }
        return $optionValues;
    }

    /**
     * Warns about inconsistent scoped option counts across stores.
     *
     * @param array $scopedOptionColumns
     * @param int $baseOptionCount
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return void
     */
    public function warnInconsistentOptionCounts(
        array           $scopedOptionColumns,
        int             $baseOptionCount,
        string          $attributeCode,
        OutputInterface $output
    ): void
    {
        foreach ($scopedOptionColumns as $storeId => $scopedOptionLabels) {
            $scopedOptionCount = count($scopedOptionLabels);
            if ($scopedOptionCount !== $baseOptionCount && $output->isVerbose()) {
                $output->writeln("<comment>The number of scoped option labels for store $storeId ($scopedOptionCount) does not match the number of base option labels ($baseOptionCount) for attribute '$attributeCode'; mapping by index, extras ignored</comment>");
            }
        }
    }

    /**
     * Fills missing base option label using scoped labels at the same index.
     *
     * @param string $baseOptionLabel
     * @param int $index
     * @param array $scopedOptionColumns
     * @param OutputInterface $output
     * @return string
     */
    private function fallbackBaseOptionLabel(
        string          $baseOptionLabel,
        int             $index,
        array           $scopedOptionColumns,
        OutputInterface $output
    ): string
    {
        if ($baseOptionLabel !== '') {
            return $baseOptionLabel;
        }
        foreach ($scopedOptionColumns as $scopedOptionLabels) {
            $candidate = (string)($scopedOptionLabels[$index] ?? '');
            if ($candidate !== '') {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>Using scoped option label '$candidate' as fallback for missing base option label at index $index; treating as base label</comment>");
                }
                return $candidate;
            }
        }
        return '';
    }

    /**
     * Builds the 'option' orders payload keyed by index.
     *
     * @param array $row
     * @param array $headerMap
     * @param array $optionValues
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function buildOptionOrders(
        array           $row,
        array           $headerMap,
        array           $optionValues,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        if (empty($optionValues)) {
            return [];
        }
        $optionOrderCell = $this->csvService->readCell($row, $headerMap, 'option_order');
        if ($optionOrderCell === '') {
            return [];
        }
        [$optionLabels, $optionOrders] = $this->parseOrdersRow($row, $headerMap);
        $firstIndexByLabel = $this->indexFirstUniqueLabels($optionLabels);
        $firstOrderByLabel = $this->resolveFirstOrderByLabel($firstIndexByLabel, $optionOrders, $attributeCode, $output);
        $finalLabelsByKey = $this->normalizeFinalOptionLabels($optionValues);
        [$optionOrders, $missingKeys] = $this->mapOrdersToFinalOptions($finalLabelsByKey, $firstOrderByLabel);
        return empty($missingKeys) ? $optionOrders : $this->applyFallbackOrders($optionOrders, $missingKeys);
    }

    /**
     * Parses the raw option labels and their sort orders from the CSV row.
     *
     * @param array $row
     * @param array $headerMap
     * @return array
     */
    private function parseOrdersRow(array $row, array $headerMap): array
    {
        $optionOrders = $this->csvService->parseList($this->csvService->readCell($row, $headerMap, 'option_order'));
        $optionLabels = $this->csvService->parseList($this->csvService->readCell($row, $headerMap, 'option'));
        return [$optionLabels, $optionOrders];
    }

    /**
     * Indexes the first occurrence of each unique, normalized option label.
     *
     * @param array $optionLabels
     * @return array
     */
    private function indexFirstUniqueLabels(array $optionLabels): array
    {
        $firstIndexByLabel = [];
        foreach ($optionLabels as $index => $optionLabel) {
            $optionLabel = $this->normalizeLabel((string)$optionLabel);
            if ($optionLabel !== '' && !isset($firstIndexByLabel[$optionLabel])) {
                $firstIndexByLabel[$optionLabel] = $index;
            }
        }
        return $firstIndexByLabel;
    }

    /**
     * Resolves numeric sort orders by matching labels to their first raw index.
     *
     * @param array $firstIndexByLabel
     * @param array $optionOrders
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function resolveFirstOrderByLabel(
        array           $firstIndexByLabel,
        array           $optionOrders,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $map = [];
        foreach ($firstIndexByLabel as $optionLabel => $index) {
            $optionOrder = trim((string)($optionOrders[$index] ?? ''));
            if ($optionOrder === '') {
                continue;
            }
            if (!ctype_digit($optionOrder)) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>The value '$optionOrder' for option '$optionLabel' is not a valid sort order for attribute '$attributeCode'; ignoring this order</comment>");
                }
                continue;
            }
            $map[$optionLabel] = (int)$optionOrder;
        }
        return $map;
    }

    /**
     * Normalizes the final (de-duplicated) option labels keyed by option_*.
     *
     * @param array $optionValues
     * @return array
     */
    private function normalizeFinalOptionLabels(array $optionValues): array
    {
        return array_map(function ($value) {
            return $this->normalizeLabel((string)($value[0] ?? ''));
        }, $optionValues);
    }

    /**
     * Maps label-based sort orders onto the final de-duplicated option keys.
     *
     * @param array $finalLabelsByKey
     * @param array $firstOrderByLabel
     * @return array
     */
    private function mapOrdersToFinalOptions(array $finalLabelsByKey, array $firstOrderByLabel): array
    {
        $optionOrders = [];
        $missingKeys = [];
        foreach ($finalLabelsByKey as $key => $optionLabel) {
            if ($optionLabel !== '' && isset($firstOrderByLabel[$optionLabel])) {
                $optionOrders[$key] = $firstOrderByLabel[$optionLabel];
            } else {
                $missingKeys[] = $key;
            }
        }
        return [$optionOrders, $missingKeys];
    }

    /**
     * Assigns sequential fallback sort orders to any keys missing an explicit order.
     *
     * @param array $optionOrders
     * @param array $missingKeys
     * @return array
     */
    private function applyFallbackOrders(array $optionOrders, array $missingKeys): array
    {
        $maxOptionOrder = $optionOrders ? max($optionOrders) : 0;
        $step = 10;
        foreach ($missingKeys as $index => $key) {
            $optionOrders[$key] = $maxOptionOrder + ($index + 1) * $step;
        }
        return $optionOrders;
    }

    /**
     * Merges new options with existing ones.
     *
     * @param bool $attributeExists
     * @param bool $shouldReplaceOptions
     * @param string $attributeCode
     * @param array $optionValues
     * @param array $optionOrders
     * @param int $errors
     * @param OutputInterface $output
     * @return array
     */
    public function mergeOptions(
        bool            $attributeExists,
        bool            $shouldReplaceOptions,
        string          $attributeCode,
        array           $optionValues,
        array           $optionOrders,
        int             &$errors,
        OutputInterface $output
    ): array
    {
        if (!$attributeExists || $shouldReplaceOptions) {
            return [$optionValues, $optionOrders];
        }
        try {
            $existingOptions = $this->attributeOptionManagement->getItems(Product::ENTITY, $attributeCode);
            $existingOptionLabels = array_reduce($existingOptions, function ($optionLabels, $option) {
                $optionLabel = trim($option->getLabel());
                if ($optionLabel !== '') {
                    $optionLabels[$this->normalizeLabel($optionLabel)] = true;
                }
                return $optionLabels;
            }, []);
            foreach ($optionValues as $key => $optionValue) {
                $candidates = [];
                foreach ($optionValue as $optionLabel) {
                    $optionLabel = $this->normalizeLabel((string)$optionLabel);
                    if ($optionLabel !== '') {
                        $candidates[$optionLabel] = true;
                    }
                }
                $isDuplicate = false;
                foreach ($candidates as $index => $_) {
                    if (isset($existingOptionLabels[$index])) {
                        $isDuplicate = true;
                        break;
                    }
                }
                if ($isDuplicate) {
                    unset($optionValues[$key], $optionOrders[$key]);
                }
            }
        } catch (LocalizedException $e) {
            if ($output->isVerbose()) {
                $output->writeln("<error>An error occurred while fetching existing options for attribute '$attributeCode': {$e->getMessage()}</error>");
            }
            $errors++;
        }
        return [$optionValues, $optionOrders];
    }

    /**
     * Adds options incrementally for the merge path (side effects).
     *
     * @param bool $attributeExists
     * @param bool $shouldReplaceOptions
     * @param string $attributeCode
     * @param array $optionValues
     * @param array $optionOrders
     * @param int $errors
     * @param OutputInterface $output
     * @return bool True when an incremental path is taken (caller should NOT embed options in payload)
     */
    private function maybeAddOptionsIncrementally(
        bool            $attributeExists,
        bool            $shouldReplaceOptions,
        string          $attributeCode,
        array           $optionValues,
        array           $optionOrders,
        int             &$errors,
        OutputInterface $output
    ): bool
    {
        if (!($attributeExists && !$shouldReplaceOptions)) {
            return false;
        }
        $this->addOptionsIncrementally($attributeCode, $optionValues, $optionOrders, $errors, $output);
        return true;
    }

    /**
     * Adds new options to an existing attribute one-by-one.
     *
     * @param string $attributeCode
     * @param array $optionValues
     * @param array $optionOrders
     * @param int $errors
     * @param OutputInterface $output
     * @return void
     */
    private function addOptionsIncrementally(
        string          $attributeCode,
        array           $optionValues,
        array           $optionOrders,
        int             &$errors,
        OutputInterface $output
    ): void
    {
        foreach ($optionValues as $key => $optionValue) {
            $optionLabel = trim((string)($optionValue[0] ?? ''));
            if ($optionLabel === '') {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>The option label '$optionLabel' for attribute '$attributeCode' is empty; skipping</comment>");
                }
                continue;
            }
            $option = $this->optionFactory->create()
                ->setLabel($optionLabel);
            if (isset($optionOrders[$key])) {
                $option->setSortOrder((int)$optionOrders[$key]);
            }
            $storeLabels = [];
            foreach ($optionValue as $storeId => $optionLabel) {
                if ((int)$storeId === 0) {
                    continue;
                }
                $optionLabel = trim((string)$optionLabel);
                if ($optionLabel === '') {
                    continue;
                }
                $storeLabel = $this->optionLabelFactory->create()
                    ->setStoreId((int)$storeId)
                    ->setLabel($optionLabel);
                $storeLabels[] = $storeLabel;
            }
            if (!empty($storeLabels)) {
                $option->setStoreLabels($storeLabels);
            }
            try {
                $this->attributeOptionManagement->add(Product::ENTITY, $attributeCode, $option);
                if ($output->isVerbose()) {
                    $output->writeln("<comment>Added option '$optionLabel' to attribute '$attributeCode'</comment>");
                }
            } catch (LocalizedException $e) {
                $output->writeln("<error>An error occurred while adding option '$optionLabel' to attribute '$attributeCode': {$e->getMessage()}</error>");
                $errors++;
            }
        }
    }

    /**
     * Sets default option(s) after options are created.
     *
     * @param string|null $frontendInput
     * @param array $row
     * @param array $headerMap
     * @param int $errors
     * @param ProductAttributeInterface $attribute
     * @param OutputInterface $output
     * @return void
     */
    public function setDefaultOptions(
        ?string                   $frontendInput,
        array                     $row,
        array                     $headerMap,
        int                       &$errors,
        ProductAttributeInterface $attribute,
        OutputInterface           $output
    ): void
    {
        $sourceCell = $this->csvService->readCell($row, $headerMap, 'source');
        if (!$this->supportsOptions($frontendInput, $sourceCell)) {
            return;
        }
        $defaultCell = $this->csvService->readCell($row, $headerMap, 'default');
        if ($defaultCell === '') {
            return;
        }
        $defaultValues = ($frontendInput === MultiSelect::NAME)
            ? $this->csvService->parseList($defaultCell)
            : [trim($defaultCell)];
        $attributeCode = $attribute->getAttributeCode();
        try {
            $options = $this->attributeOptionManagement->getItems(Product::ENTITY, $attributeCode);
            [$idSet, $labelToId] = $this->buildOptionMaps($options);
            $optionLabelSetsByIndex = $this->buildOptionLabelSets($row, $headerMap, $attributeCode, $output);
            $indexToId = [];
            foreach ($options as $option) {
                $optionId = (int)$option->getValue();
                if ($optionId <= 0) continue;
                $optionLabel = $this->normalizeLabel($option->getLabel());
                foreach ($optionLabelSetsByIndex as $index => $optionLabels) {
                    if (in_array($optionLabel, $optionLabels, true)) {
                        if (!isset($indexToId[$index])) {
                            $indexToId[$index] = $optionId;
                        }
                        break;
                    }
                }
            }
            foreach ($optionLabelSetsByIndex as $index => $optionLabels) {
                $optionId = $indexToId[$index] ?? null;
                if (is_null($optionId)) {
                    continue;
                }
                foreach ($optionLabels as $optionLabel) {
                    $labelToId[$optionLabel] = $optionId;
                }
            }
            $resolvedIds = $this->resolveOptionIds($defaultValues, $idSet, $labelToId, $attributeCode, $output);
            if (empty($resolvedIds)) {
                return;
            }
            $resolvedIds = array_values(array_unique($resolvedIds));
            $defaultValue = ($frontendInput === MultiSelect::NAME)
                ? implode(',', $resolvedIds)
                : (string)$resolvedIds[0];
            if (($attribute->getDefaultValue() ?? '') === $defaultValue) {
                return;
            }
            $attribute->setDefaultValue($defaultValue);
            $this->productAttributeRepository->save($attribute);
        } catch (LocalizedException $e) {
            $output->writeln("<error>An error occurred while setting default option(s) for attribute '$attributeCode': {$e->getMessage()}</error>");
            $errors++;
        }
    }

    /**
     * Builds maps of option IDs and labels.
     *
     * @param array $options
     * @return array[]
     */
    private function buildOptionMaps(array $options): array
    {
        $idSet = [];
        $labelToId = [];
        foreach ($options as $option) {
            $optionId = (int)$option->getValue();
            if ($optionId <= 0) {
                continue;
            }
            $idSet[$optionId] = true;
            $optionLabel = trim((string)$option->getLabel());
            if ($optionLabel !== '') {
                $labelToId[$this->normalizeLabel($optionLabel)] = $optionId;
            }
        }
        return [$idSet, $labelToId];
    }

    /**
     * Builds per-index label sets including scoped labels.
     *
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function buildOptionLabelSets(
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $sets = [];
        $baseOptionLabels = $this->csvService->parseList($this->csvService->readCell($row, $headerMap, 'option'));
        if (empty($baseOptionLabels)) {
            return $sets;
        }
        $scopedOptionColumns = $this->collectScopedOptionColumns($row, $headerMap, $attributeCode, $output);
        $seen = [];
        $u = 0;
        foreach ($baseOptionLabels as $index => $baseOptionLabel) {
            $baseOptionLabel = $this->normalizeLabel($baseOptionLabel);
            if ($baseOptionLabel === '' || isset($seen[$baseOptionLabel])) {
                continue;
            }
            $seen[$baseOptionLabel] = true;
            $optionLabels = [$baseOptionLabel];
            foreach ($scopedOptionColumns as $scopedOptionLabels) {
                $scopedOptionLabel = $this->normalizeLabel((string)($scopedOptionLabels[$index] ?? ''));
                if ($scopedOptionLabel !== '') {
                    $optionLabels[] = $scopedOptionLabel;
                }
            }
            $sets[$u++] = array_values(array_unique($optionLabels));
        }
        return $sets;
    }

    /**
     * Resolves default values to option IDs.
     *
     * @param array $values
     * @param array $idSet
     * @param array $labelToId
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array
     */
    private function resolveOptionIds(
        array           $values,
        array           $idSet,
        array           $labelToId,
        string          $attributeCode,
        OutputInterface $output
    ): array
    {
        $resolvedIds = [];
        foreach ($values as $value) {
            $value = trim((string)$value);
            if ($value === '') {
                continue;
            }
            if (ctype_digit($value) && isset($idSet[(int)$value])) {
                $resolvedIds[] = (int)$value;
                continue;
            }
            $optionLabel = $this->normalizeLabel($value);
            if (isset($labelToId[$optionLabel])) {
                $resolvedIds[] = $labelToId[$optionLabel];
            } else {
                if ($output->isVerbose()) {
                    $available = implode(', ', array_keys($labelToId));
                    $output->writeln("<comment>The option label '$optionLabel' for attribute '$attributeCode' is not valid; available options: $available</comment>");
                } else {
                    $output->writeln("<comment>The option label '$optionLabel' for attribute '$attributeCode' is not valid; not set as default</comment>");
                }
            }
        }
        return array_values(array_unique($resolvedIds));
    }
}
