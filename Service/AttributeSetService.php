<?php

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Magento\Catalog\Model\Product;
use Magento\Eav\Api\AttributeSetManagementInterface;
use Magento\Eav\Model\Entity\Attribute\Set as AttributeSet;
use Magento\Eav\Model\Entity\Attribute\SetFactory as AttributeSetFactory;
use Magento\Eav\Setup\EavSetup;
use Magento\Eav\Setup\EavSetupFactory;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class AttributeSetService
{
    /**
     * @param EavSetupFactory $eavSetupFactory
     * @param CsvService $csvService
     * @param AttributeSetFactory $attributeSetFactory
     * @param AttributeSetManagementInterface $attributeSetManagement
     */
    public function __construct(
        private readonly EavSetupFactory                 $eavSetupFactory,
        private readonly CsvService                      $csvService,
        private readonly AttributeSetFactory             $attributeSetFactory,
        private readonly AttributeSetManagementInterface $attributeSetManagement,
    )
    {
    }

    /**
     * Executes attribute-set import flow.
     *
     * @param array $csvData
     * @param OutputInterface $output
     * @return int
     */
    public function executeAttributeSetImport(array $csvData, OutputInterface $output): int
    {
        $headerMap = $this->csvService->buildHeaderMap($csvData[0]);
        if (!isset($headerMap['attribute_set'])) {
            $output->writeln("<error>The CSV file is missing the 'attribute_set' column</error>");
            return Command::FAILURE;
        }
        return $this->deleteAttributeSets($csvData, $headerMap, $output);
    }

    /**
     * Deletes attribute sets listed in the CSV.
     *
     * @param array $csvData
     * @param array $headerMap
     * @param OutputInterface $output
     * @return int
     */
    private function deleteAttributeSets(array $csvData, array $headerMap, OutputInterface $output): int
    {
        $eavSetup = $this->eavSetupFactory->create();
        $uniqueAttributeSetNames = $this->collectUniqueAttributeSetNames($csvData, $headerMap);
        $deleted = $errors = 0;
        foreach ($uniqueAttributeSetNames as $uniqueAttributeSetName) {
            $output->writeln("Deleting attribute set '$uniqueAttributeSetName'...");
            if ($this->isDefaultAttributeSetName($uniqueAttributeSetName)) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>The default attribute set cannot be deleted; skipping</comment>");
                }
                continue;
            }
            [$attributeSet, $identifier] = $this->loadAttributeSetAndIdentifier($eavSetup, $uniqueAttributeSetName);
            if (!$attributeSet) {
                $output->writeln("<comment>The attribute set '$uniqueAttributeSetName' does not exist; skipping</comment>");
                continue;
            }
            if ($this->deleteAttributeSetByIdentifier($eavSetup, $identifier, $uniqueAttributeSetName, $output)) {
                $deleted++;
            } else {
                $errors++;
            }
        }
        $output->writeln("<info>Deleted $deleted attribute set(s)</info>");
        if ($errors > 0) {
            $output->writeln("<error>$errors error(s) occurred during import</error>");
            return Command::FAILURE;
        }
        return Command::SUCCESS;
    }

    /**
     * Collects unique attribute set names from CSV (case-insensitive; preserves first casing).
     *
     * @param array $csvData
     * @param array $headerMap
     * @return array
     */
    private function collectUniqueAttributeSetNames(array $csvData, array $headerMap): array
    {
        $uniqueAttributeSetNames = [];
        $this->csvService->iterateDataRows($csvData, function (array $row) use ($headerMap, &$uniqueAttributeSetNames) {
            $attributeSetCell = $this->csvService->readCell($row, $headerMap, 'attribute_set');
            if ($attributeSetCell === '') {
                return;
            }
            foreach ($this->csvService->parseList($attributeSetCell) as $attributeSetName) {
                $key = $this->normalizeSetKey($attributeSetName);
                if ($key !== '') {
                    $uniqueAttributeSetNames[$key] = $attributeSetName;
                }
            }
        });
        return array_values($uniqueAttributeSetNames);
    }

    /**
     * Normalizes an attribute set key for case-insensitive de-duplication.
     *
     * @param string $key
     * @return string
     */
    private function normalizeSetKey(string $key): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($key)) ?? '');
    }

    /**
     * Determines if the given name is the default attribute set (case-insensitive).
     *
     * @param string $attributeSetName
     * @return bool
     */
    private function isDefaultAttributeSetName(string $attributeSetName): bool
    {
        return $this->normalizeSetKey($attributeSetName) === 'default';
    }

    /**
     * Loads an attribute set and computes the best identifier (id if available, else name).
     *
     * @param EavSetup $eavSetup
     * @param string $attributeSetNameOrId
     * @return array [attributeSetArray|null, identifier]
     */
    private function loadAttributeSetAndIdentifier(EavSetup $eavSetup, string $attributeSetNameOrId): array
    {
        $attributeSet = is_numeric($attributeSetNameOrId)
            ? $eavSetup->getAttributeSet(Product::ENTITY, (int)$attributeSetNameOrId)
            : $eavSetup->getAttributeSet(Product::ENTITY, $attributeSetNameOrId);
        if (!$attributeSet) {
            return [null, $attributeSetNameOrId];
        }
        $attributeSetId = (int)($attributeSet[AttributeSet::KEY_ATTRIBUTE_SET_ID] ?? 0);
        $identifier = $attributeSetId ?: $attributeSetNameOrId;
        return [$attributeSet, $identifier];
    }

    /**
     * Deletes an attribute set by identifier and reports errors.
     *
     * @param EavSetup $eavSetup
     * @param int|string $identifier
     * @param string $attributeSetName
     * @param OutputInterface $output
     * @return bool
     */
    private function deleteAttributeSetByIdentifier(
        EavSetup        $eavSetup,
        int|string      $identifier,
        string          $attributeSetName,
        OutputInterface $output
    ): bool
    {
        try {
            $eavSetup->removeAttributeSet(Product::ENTITY, $identifier);
            return true;
        } catch (Throwable $e) {
            $output->writeln("<error>An error occurred while deleting attribute set '$attributeSetName': {$e->getMessage()}</error>");
            return false;
        }
    }

    /**
     * Assigns the attribute to sets/groups, creating them as needed.
     *
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param EavSetup $eavSetup
     * @param OutputInterface $output
     * @return void
     */
    public function assignToAttributeSets(
        array           $row,
        array           $headerMap,
        string          $attributeCode,
        EavSetup        $eavSetup,
        OutputInterface $output,
    ): void
    {
        $attributeSetNamesOrIds = $this->csvService->parseList($this->csvService->readCell($row, $headerMap, 'attribute_set'))
            ?: [$eavSetup->getDefaultAttributeSetId(Product::ENTITY)];
        $attributeSetOrderCell = $this->csvService->readCell($row, $headerMap, 'attribute_set_order');
        $attributeSetSortOrders = $attributeSetOrderCell !== '' ? $this->csvService->parseList($attributeSetOrderCell) : [];
        $attributeSetSortOrder = count($attributeSetSortOrders) === 1
            ? $this->parseOptionalInt($attributeSetSortOrders[0])
            : null;
        $attributeGroupName = $this->csvService->readCell($row, $headerMap, 'group');
        $attributeGroupOrderCell = $this->csvService->readCell($row, $headerMap, 'group_order');
        $attributeGroupSortOrder = $this->parseOptionalInt($attributeGroupOrderCell);
        $attributeSortOrderCell = $this->csvService->readCell($row, $headerMap, 'sort_order');
        $attributeSortOrder = $this->parseOptionalInt($attributeSortOrderCell);
        $attributeSetSortOrderCount = count($attributeSetSortOrders);
        $attributeSetCount = count($attributeSetNamesOrIds);
        if (is_null($attributeSetSortOrder) && !empty($attributeSetSortOrders) && $attributeSetSortOrderCount !== $attributeSetCount && $output->isVerbose()) {
            $output->writeln("<comment>The number of 'attribute_set_order' values ($attributeSetSortOrderCount) does not match the number of 'attribute_set' values ($attributeSetCount) for attribute '$attributeCode'</comment>");
        }
        $processedAttributeSetIds = [];
        foreach ($attributeSetNamesOrIds as $index => $attributeSetNameOrId) {
            $currentSetSortOrder = $attributeSetSortOrder;
            if (is_null($attributeSetSortOrder) && isset($attributeSetSortOrders[$index])) {
                $currentSetSortOrder = $this->parseOptionalInt($attributeSetSortOrders[$index]);
            }
            $attributeSet = $this->ensureAttributeSet($attributeSetNameOrId, $eavSetup, $output, $currentSetSortOrder);
            if (is_null($attributeSet)) {
                continue;
            }
            [$attributeSetId] = $attributeSet;
            if (isset($processedAttributeSetIds[$attributeSetId])) {
                continue;
            }
            $processedAttributeSetIds[$attributeSetId] = true;
            $attributeGroupId = $this->ensureAttributeGroup($attributeSetId, $attributeGroupName, $eavSetup, $output, $attributeGroupSortOrder);
            $eavSetup->addAttributeToSet(Product::ENTITY, $attributeSetId, $attributeGroupId, $attributeCode, $attributeSortOrder);
        }
    }

    /**
     * Parses an optional integer; returns null if empty or invalid.
     *
     * @param string|null $value
     * @return int|null
     */
    private function parseOptionalInt(?string $value): ?int
    {
        $value = trim((string)$value);
        return $value === '' || !preg_match('/^\d+$/', $value) ? null : (int)$value;
    }

    /**
     * Ensures an attribute set exists and returns its ID and name.
     *
     * @param string $attributeSetNameOrId
     * @param EavSetup $eavSetup
     * @param OutputInterface $output
     * @param int|null $sortOrder
     * @return array|null
     */
    private function ensureAttributeSet(
        string          $attributeSetNameOrId,
        EavSetup        $eavSetup,
        OutputInterface $output,
        ?int            $sortOrder = null
    ): ?array
    {
        if (is_numeric($attributeSetNameOrId)) {
            $attributeSetId = (int)$attributeSetNameOrId;
            $attributeSet = $eavSetup->getAttributeSet(Product::ENTITY, $attributeSetId);
            if (!$attributeSet) {
                if ($output->isVerbose()) {
                    $output->writeln("<comment>The attribute set ID '$attributeSetId' does not exist; skipping</comment>");
                }
                return null;
            }
            if (!is_null($sortOrder)) {
                $eavSetup->updateAttributeSet(Product::ENTITY, $attributeSetId, AttributeSet::KEY_SORT_ORDER, $sortOrder);
            }
            $attributeSetName = $attributeSet[AttributeSet::KEY_ATTRIBUTE_SET_NAME] ?? (string)$attributeSetId;
            return [$attributeSetId, $attributeSetName];
        }
        $attributeSet = $eavSetup->getAttributeSet(Product::ENTITY, $attributeSetNameOrId);
        if ($attributeSet) {
            $attributeSetId = (int)($attributeSet[AttributeSet::KEY_ATTRIBUTE_SET_ID] ?? 0);
            if (!is_null($sortOrder) && $attributeSetId) {
                $eavSetup->updateAttributeSet(Product::ENTITY, $attributeSetId, AttributeSet::KEY_SORT_ORDER, $sortOrder);
            }
            return [$attributeSetId, $attributeSetNameOrId];
        }
        try {
            $entityTypeId = (int)$eavSetup->getEntityTypeId(Product::ENTITY);
            $defaultAttributeSetId = $eavSetup->getDefaultAttributeSetId(Product::ENTITY);
            $attributeSet = $this->attributeSetFactory->create([
                'data' => array_filter([
                    AttributeSet::KEY_ATTRIBUTE_SET_NAME => $attributeSetNameOrId,
                    AttributeSet::KEY_ENTITY_TYPE_ID => $entityTypeId,
                    AttributeSet::KEY_SORT_ORDER => $sortOrder,
                ], static fn($value) => !is_null($value)),
            ]);
            $this->attributeSetManagement->create($entityTypeId, $attributeSet, $defaultAttributeSetId);
            $attributeSetId = (int)$attributeSet->getId();
            if (!$attributeSetId) {
                throw new LocalizedException(__('Attribute set ID is not set for "%1"', $attributeSetNameOrId));
            }
            if ($output->isVerbose()) {
                $output->writeln("<comment>The attribute set '$attributeSetNameOrId' did not exist; created automatically</comment>");
            }
            return [$attributeSetId, $attributeSetNameOrId];
        } catch (LocalizedException $e) {
            if ($output->isVerbose()) {
                $output->writeln("<error>An error occurred while creating attribute set '$attributeSetNameOrId': {$e->getMessage()}</error>");
            }
            return null;
        }
    }

    /**
     * Ensures an attribute group exists in a set and returns its ID.
     *
     * @param int $attributeSetId
     * @param string $attributeGroupName
     * @param EavSetup $eavSetup
     * @param OutputInterface $output
     * @param int|null $sortOrder
     * @return int
     */
    private function ensureAttributeGroup(
        int             $attributeSetId,
        string          $attributeGroupName,
        EavSetup        $eavSetup,
        OutputInterface $output,
        ?int            $sortOrder = null
    ): int
    {
        $defaultAttributeGroupId = (int)$eavSetup->getDefaultAttributeGroupId(Product::ENTITY, $attributeSetId);
        if (empty($attributeGroupName)) {
            return $defaultAttributeGroupId;
        }
        $eavSetup->addAttributeGroup(Product::ENTITY, $attributeSetId, $attributeGroupName, $sortOrder);
        try {
            return (int)$eavSetup->getAttributeGroupId(Product::ENTITY, $attributeSetId, $attributeGroupName);
        } catch (LocalizedException) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>The attribute group '$attributeGroupName' could not be created or retrieved; using default group</comment>");
            }
            return $defaultAttributeGroupId;
        }
    }
}
