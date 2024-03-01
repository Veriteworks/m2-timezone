<?php
namespace Veriteworks\Timezone\Model\Rewrite\CatalogRule\Indexer;

use Magento\Catalog\Model\Product;

/**
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects)
 */
class IndexBuilder extends \Magento\CatalogRule\Model\Indexer\IndexBuilder
{
    /**
     * Apply rules
     *
     * @param Product|null $product
     * @throws \Exception
     * @return $this
     * @SuppressWarnings(PHPMD.CyclomaticComplexity)
     * @SuppressWarnings(PHPMD.NPathComplexity)
     * @SuppressWarnings(PHPMD.ExcessiveMethodLength)
     */
    protected function applyAllRules(Product $product = null)
    {
        $fromDate = mktime(0, 0, 0, date('m'), date('d') - 1);
        $toDate = mktime(0, 0, 0, date('m'), date('d') + 1);

        /**
         * Update products rules prices per each website separately
         * because of max join limit in mysql
         */
        $tz = date_default_timezone_get();

        /** @var \Magento\Store\Model\Website $website */
        foreach ($this->storeManager->getWebsites() as $website) {
            $ntz = $website->getConfig('general/locale/timezone');
            date_default_timezone_set($ntz);

            $fromDate = mktime(0, 0, 0, date('m'), date('d') - 1);
            $toDate = mktime(0, 0, 0, date('m'), date('d') + 1);

            $productsStmt = $this->getRuleProductsStmt($website->getId(), $product);

            $dayPrices = [];
            $stopFlags = [];
            $prevKey = null;

            while ($ruleData = $productsStmt->fetch()) {
                $ruleProductId = $ruleData['product_id'];
                $productKey = $ruleProductId .
                    '_' .
                    $ruleData['website_id'] .
                    '_' .
                    $ruleData['customer_group_id'];

                if ($prevKey && $prevKey != $productKey) {
                    $stopFlags = [];
                    if (count($dayPrices) > $this->batchCount) {
                        $this->saveRuleProductPrices($dayPrices);
                        $dayPrices = [];
                    }
                }

                $ruleData['from_time'] = $this->_roundTime($ruleData['from_time']);
                $ruleData['to_time'] = $this->_roundTime($ruleData['to_time']);
                /**
                 * Build prices for each day
                 */
                for ($time = $fromDate; $time <= $toDate; $time += self::SECONDS_IN_DAY) {
                    if (($ruleData['from_time'] == 0 ||
                            $time >= $ruleData['from_time']) && ($ruleData['to_time'] == 0 ||
                            $time <= $ruleData['to_time'])
                    ) {
                        $priceKey = $time . '_' . $productKey;

                        if (isset($stopFlags[$priceKey])) {
                            continue;
                        }

                        if (!isset($dayPrices[$priceKey])) {
                            $dayPrices[$priceKey] = [
                                'rule_date' => $time,
                                'website_id' => $ruleData['website_id'],
                                'customer_group_id' => $ruleData['customer_group_id'],
                                'product_id' => $ruleProductId,
                                'rule_price' => $this->calcRuleProductPrice($ruleData),
                                'latest_start_date' => $ruleData['from_time'],
                                'earliest_end_date' => $ruleData['to_time'],
                            ];
                        } else {
                            $dayPrices[$priceKey]['rule_price'] = $this->calcRuleProductPrice(
                                $ruleData,
                                $dayPrices[$priceKey]
                            );
                            $dayPrices[$priceKey]['latest_start_date'] = max(
                                $dayPrices[$priceKey]['latest_start_date'],
                                $ruleData['from_time']
                            );
                            $dayPrices[$priceKey]['earliest_end_date'] = min(
                                $dayPrices[$priceKey]['earliest_end_date'],
                                $ruleData['to_time']
                            );
                        }

                        if ($ruleData['action_stop']) {
                            $stopFlags[$priceKey] = true;
                        }
                    }
                }

                $prevKey = $productKey;
            }
            $this->saveRuleProductPrices($dayPrices);
        }
        date_default_timezone_set($tz);
        return $this->updateCatalogRuleGroupWebsiteData();
    }

    /**
     * Round time
     *
     * @param int $timeStamp
     * @return int
     */
    private function _roundTime($timeStamp)
    {
        if (is_numeric($timeStamp) && $timeStamp != 0) {
            $timeStamp = $this->dateTime->timestamp($this->dateTime->date('Y-m-d 00:00:00', $timeStamp));
        }

        return $timeStamp;
    }
}
