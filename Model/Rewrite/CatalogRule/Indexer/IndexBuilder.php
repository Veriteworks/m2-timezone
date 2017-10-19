<?php
/**
 * Copyright © 2013-2017 Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Veriteworks\Timezone\Model\Rewrite\CatalogRule\Indexer;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\CatalogRule\Model\ResourceModel\Rule\CollectionFactory as RuleCollectionFactory;
use Magento\CatalogRule\Model\Rule;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Magento\Framework\Pricing\PriceCurrencyInterface;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IndexBuilder extends \Magento\CatalogRule\Model\Indexer\IndexBuilder
{
    const SECONDS_IN_DAY = 86400;

    /**
     * @var \Magento\Framework\EntityManager\MetadataPool
     */
    protected $metadataPool;

    /**
     * CatalogRuleGroupWebsite columns list
     *
     * This array contain list of CatalogRuleGroupWebsite table columns
     *
     * @var array
     */
    protected $_catalogRuleGroupWebsiteColumnsList = ['rule_id', 'customer_group_id', 'website_id'];

    /**
     * @var \Magento\Framework\App\ResourceConnection
     */
    protected $resource;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var RuleCollectionFactory
     */
    protected $ruleCollectionFactory;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $logger;

    /**
     * @var PriceCurrencyInterface
     */
    protected $priceCurrency;

    /**
     * @var \Magento\Eav\Model\Config
     */
    protected $eavConfig;

    /**
     * @var \Magento\Framework\Stdlib\DateTime
     */
    protected $dateFormat;

    /**
     * @var \Magento\Framework\Stdlib\DateTime\DateTime
     */
    protected $dateTime;

    /**
     * @var \Magento\Catalog\Model\ProductFactory
     */
    protected $productFactory;

    /**
     * @var Product[]
     */
    protected $loadedProducts;

    /**
     * @var int
     */
    protected $batchCount;

    /**
     * @var \Magento\Framework\DB\Adapter\AdapterInterface
     */
    protected $connection;

    /**
     * @param RuleCollectionFactory $ruleCollectionFactory
     * @param PriceCurrencyInterface $priceCurrency
     * @param \Magento\Framework\App\ResourceConnection $resource
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Psr\Log\LoggerInterface $logger
     * @param \Magento\Eav\Model\Config $eavConfig
     * @param \Magento\Framework\Stdlib\DateTime $dateFormat
     * @param \Magento\Framework\Stdlib\DateTime\DateTime $dateTime
     * @param \Magento\Catalog\Model\ProductFactory $productFactory
     * @param int $batchCount
     * @SuppressWarnings(PHPMD.ExcessiveParameterList)
     */
    public function __construct(
        RuleCollectionFactory $ruleCollectionFactory,
        PriceCurrencyInterface $priceCurrency,
        \Magento\Framework\App\ResourceConnection $resource,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Eav\Model\Config $eavConfig,
        \Magento\Framework\Stdlib\DateTime $dateFormat,
        \Magento\Framework\Stdlib\DateTime\DateTime $dateTime,
        \Magento\Catalog\Model\ProductFactory $productFactory,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        $batchCount = 1000
    ) {
        $this->resource = $resource;
        $this->connection = $resource->getConnection();
        $this->storeManager = $storeManager;
        $this->ruleCollectionFactory = $ruleCollectionFactory;
        $this->logger = $logger;
        $this->priceCurrency = $priceCurrency;
        $this->eavConfig = $eavConfig;
        $this->dateFormat = $dateFormat;
        $this->dateTime = $dateTime;
        $this->productFactory = $productFactory;
        $this->scopeConfig = $scopeConfig;
        $this->batchCount = $batchCount;
    }

    /**
     * @param Rule $rule
     * @param Product $product
     * @return $this
     * @throws \Exception
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function applyRule(Rule $rule, $product)
    {
        $ruleId = $rule->getId();
        $productEntityId = $product->getId();
        $websiteIds = array_intersect($product->getWebsiteIds(), $rule->getWebsiteIds());

        if (!$rule->validate($product)) {
            return $this;
        }

        $this->connection->delete(
            $this->resource->getTableName('catalogrule_product'),
            [
                $this->connection->quoteInto('rule_id = ?', $ruleId),
                $this->connection->quoteInto('product_id = ?', $productEntityId)
            ]
        );

        $customerGroupIds = $rule->getCustomerGroupIds();
        $storeTimezone = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $tz = date_default_timezone_get();
        date_default_timezone_set($storeTimezone);
        $fromTime = strtotime($rule->getFromDate());
        $toTime = strtotime($rule->getToDate());
        date_default_timezone_set($tz);

        $toTime = $toTime ? $toTime + self::SECONDS_IN_DAY - 1 : 0;
        $sortOrder = (int)$rule->getSortOrder();
        $actionOperator = $rule->getSimpleAction();
        $actionAmount = $rule->getDiscountAmount();
        $actionStop = $rule->getStopRulesProcessing();

        $rows = [];
        try {
            foreach ($websiteIds as $websiteId) {
                foreach ($customerGroupIds as $customerGroupId) {
                    $rows[] = [
                        'rule_id' => $ruleId,
                        'from_time' => $fromTime,
                        'to_time' => $toTime,
                        'website_id' => $websiteId,
                        'customer_group_id' => $customerGroupId,
                        'product_id' => $productEntityId,
                        'action_operator' => $actionOperator,
                        'action_amount' => $actionAmount,
                        'action_stop' => $actionStop,
                        'sort_order' => $sortOrder,
                    ];

                    if (count($rows) == $this->batchCount) {
                        $this->connection->insertMultiple($this->getTable('catalogrule_product'), $rows);
                        $rows = [];
                    }
                }
            }

            if (!empty($rows)) {
                $this->connection->insertMultiple($this->resource->getTableName('catalogrule_product'), $rows);
            }
        } catch (\Exception $e) {
            throw $e;
        }

        $this->applyAllRules($product);

        return $this;
    }

    /**
     * @param Rule $rule
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     */
    protected function updateRuleProductData(Rule $rule)
    {
        $ruleId = $rule->getId();
        if ($rule->getProductsFilter()) {
            $this->connection->delete(
                $this->getTable('catalogrule_product'),
                ['rule_id=?' => $ruleId, 'product_id IN (?)' => $rule->getProductsFilter()]
            );
        } else {
            $this->connection->delete(
                $this->getTable('catalogrule_product'),
                $this->connection->quoteInto('rule_id=?', $ruleId)
            );
        }

        if (!$rule->getIsActive()) {
            return $this;
        }

        $websiteIds = $rule->getWebsiteIds();
        if (!is_array($websiteIds)) {
            $websiteIds = explode(',', $websiteIds);
        }
        if (empty($websiteIds)) {
            return $this;
        }

        \Magento\Framework\Profiler::start('__MATCH_PRODUCTS__');
        $productIds = $rule->getMatchingProductIds();
        \Magento\Framework\Profiler::stop('__MATCH_PRODUCTS__');
        $customerGroupIds = $rule->getCustomerGroupIds();

        $storeTimezone = $this->scopeConfig->getValue('general/locale/timezone', \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        $tz = date_default_timezone_get();
        date_default_timezone_set($storeTimezone);
        $fromTime = strtotime($rule->getFromDate());
        $toTime = strtotime($rule->getToDate());
        date_default_timezone_set($tz);

        $toTime = $toTime ? $toTime + self::SECONDS_IN_DAY - 1 : 0;
        $sortOrder = (int)$rule->getSortOrder();
        $actionOperator = $rule->getSimpleAction();
        $actionAmount = $rule->getDiscountAmount();
        $actionStop = $rule->getStopRulesProcessing();

        $rows = [];

        foreach ($productIds as $productId => $validationByWebsite) {
            foreach ($websiteIds as $websiteId) {
                if (empty($validationByWebsite[$websiteId])) {
                    continue;
                }
                foreach ($customerGroupIds as $customerGroupId) {
                    $rows[] = [
                        'rule_id' => $ruleId,
                        'from_time' => $fromTime,
                        'to_time' => $toTime,
                        'website_id' => $websiteId,
                        'customer_group_id' => $customerGroupId,
                        'product_id' => $productId,
                        'action_operator' => $actionOperator,
                        'action_amount' => $actionAmount,
                        'action_stop' => $actionStop,
                        'sort_order' => $sortOrder,
                    ];

                    if (count($rows) == $this->batchCount) {
                        $this->connection->insertMultiple($this->getTable('catalogrule_product'), $rows);
                        $rows = [];
                    }
                }
            }
        }
        if (!empty($rows)) {
            $this->connection->insertMultiple($this->getTable('catalogrule_product'), $rows);
        }

        return $this;
    }
}
