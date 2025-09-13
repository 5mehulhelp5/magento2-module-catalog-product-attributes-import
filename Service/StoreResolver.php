<?php

declare(strict_types=1);

namespace AimaneCouissi\CatalogProductAttributesImport\Service;

use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

class StoreResolver
{
    /**
     * @var array<string,int|null> $cache
     */
    private array $cache = [];

    /**
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(private readonly StoreManagerInterface $storeManager)
    {
    }

    /**
     * Gets a store ID from code with cache.
     *
     * @param string $storeCode
     * @return int|null
     */
    public function getStoreIdByCode(string $storeCode): ?int
    {
        if ($storeCode === Store::ADMIN_CODE) {
            return 0;
        }
        if (array_key_exists($storeCode, $this->cache)) {
            return $this->cache[$storeCode];
        }
        try {
            return $this->cache[$storeCode] = (int)$this->storeManager->getStore($storeCode)->getId();
        } catch (NoSuchEntityException) {
            return $this->cache[$storeCode] = null;
        }
    }
}
