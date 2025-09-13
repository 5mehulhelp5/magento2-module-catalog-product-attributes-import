<?php

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Magento\Catalog\Api\Data\ProductAttributeInterface;
use Magento\Catalog\Api\ProductAttributeRepositoryInterface;
use Magento\Eav\Model\Entity\Attribute\FrontendLabelFactory;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Output\OutputInterface;

class AttributeLabelService
{
    /**
     * @param FrontendLabelFactory $frontendLabelFactory
     * @param ProductAttributeRepositoryInterface $productAttributeRepository
     * @param StoreResolver $storeResolver
     */
    public function __construct(
        private readonly FrontendLabelFactory                $frontendLabelFactory,
        private readonly ProductAttributeRepositoryInterface $productAttributeRepository,
        private readonly StoreResolver                       $storeResolver,
    )
    {
    }

    /**
     * Applies store-scoped frontend labels to the attribute.
     *
     * @param array $row
     * @param array $headerMap
     * @param int $errors
     * @param ProductAttributeInterface $attribute
     * @param OutputInterface $output
     * @return void
     */
    public function applyScopedFrontendLabels(
        array                     $row,
        array                     $headerMap,
        int                       &$errors,
        ProductAttributeInterface $attribute,
        OutputInterface           $output
    ): void
    {
        $attributeCode = $attribute->getAttributeCode();
        $scopedFrontendLabels = $this->collectScopedFrontendLabels($row, $headerMap, $attributeCode, $output);
        if (empty($scopedFrontendLabels)) {
            return;
        }
        try {
            $frontendLabels = $this->mergeFrontendLabels(
                $attribute->getFrontendLabels() ?: [],
                $this->buildFrontendLabelObjects($scopedFrontendLabels),
                array_keys($scopedFrontendLabels)
            );
            if ($frontendLabels) {
                $attribute->setFrontendLabels($frontendLabels);
                $this->productAttributeRepository->save($attribute);
            }
        } catch (LocalizedException $e) {
            $output->writeln("<error>An error occurred while applying scoped frontend labels: {$e->getMessage()}</error>");
            $errors++;
        }
    }

    /**
     * Collects store-scoped frontend label pairs from the row.
     *
     * @param array $row
     * @param array $headerMap
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return array [storeId => label]
     */
    private function collectScopedFrontendLabels(array $row, array $headerMap, string $attributeCode, OutputInterface $output): array
    {
        $storeLabelPairs = [];
        foreach ($headerMap as $key => $index) {
            if (!$this->isScopedLabelHeader($key)) {
                continue;
            }
            $cell = trim((string)($row[$index] ?? ''));
            if ($cell === '') {
                continue;
            }
            $storeCode = $this->extractStoreCodeFromLabelHeader($key);
            $storeId = $this->resolveStoreId($storeCode, $attributeCode, $output);
            if (is_null($storeId)) {
                continue;
            }
            $storeLabelPairs[$storeId] = $cell;
        }
        return $storeLabelPairs;
    }

    /**
     * Determines if a header key targets a scoped label column.
     *
     * @param string $key
     * @return bool
     */
    private function isScopedLabelHeader(string $key): bool
    {
        return str_starts_with($key, 'label_') && strlen($key) > 6;
    }

    /**
     * Extracts store code from a scoped label header key (label_{storeCode}).
     *
     * @param string $key
     * @return string
     */
    private function extractStoreCodeFromLabelHeader(string $key): string
    {
        return substr($key, 6);
    }

    /**
     * Resolves a store id from store code and reports when missing.
     *
     * @param string $storeCode
     * @param string $attributeCode
     * @param OutputInterface $output
     * @return int|null
     */
    private function resolveStoreId(string $storeCode, string $attributeCode, OutputInterface $output): ?int
    {
        $storeId = $this->storeResolver->getStoreIdByCode($storeCode);
        if (is_null($storeId)) {
            if ($output->isVerbose()) {
                $output->writeln("<comment>The store code '$storeCode' is not valid for attribute '$attributeCode' on column 'label_$storeCode'; column ignored</comment>");
            }
            return null;
        }
        return (int)$storeId;
    }

    /**
     * Merges new frontend labels with existing ones.
     *
     * @param array $currentFrontendLabels
     * @param array $newFrontendLabels
     * @param array $affectedStoreIds
     * @return array|null
     */
    private function mergeFrontendLabels(array $currentFrontendLabels, array $newFrontendLabels, array $affectedStoreIds): ?array
    {
        $frontendLabelsByStore = $this->indexFrontendLabelsByStore($currentFrontendLabels);
        $willChange = false;
        foreach ($affectedStoreIds as $affectedStoreId) {
            $willChange = true;
            unset($frontendLabelsByStore[$affectedStoreId]);
        }
        if (!$willChange && empty($newFrontendLabels)) {
            return null;
        }
        return array_values(array_merge($frontendLabelsByStore, $newFrontendLabels));
    }

    /**
     * Indexes existing frontend labels by store id.
     *
     * @param array $currentFrontendLabels
     * @return array [storeId => labelObject]
     */
    private function indexFrontendLabelsByStore(array $currentFrontendLabels): array
    {
        $frontendLabelsByStore = [];
        foreach ($currentFrontendLabels as $currentFrontendLabel) {
            $frontendLabelsByStore[(int)$currentFrontendLabel->getStoreId()] = $currentFrontendLabel;
        }
        return $frontendLabelsByStore;
    }

    /**
     * Creates FrontendLabel objects from store/label pairs.
     *
     * @param array $scopedFrontendLabels [storeId => label]
     * @return array
     */
    private function buildFrontendLabelObjects(array $scopedFrontendLabels): array
    {
        $frontendLabelObjects = [];
        foreach ($scopedFrontendLabels as $storeId => $label) {
            $frontendLabelObjects[] = $this->frontendLabelFactory->create()
                ->setStoreId((int)$storeId)
                ->setLabel($label);
        }
        return $frontendLabelObjects;
    }
}
