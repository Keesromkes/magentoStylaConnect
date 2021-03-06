<?php

/**
 * Class Styla_Connect_Model_Product_Info_Renderer_Abstract
 *
 */
class Styla_Connect_Model_Product_Info_Renderer_Abstract
{
    const EVENT_COLLECT_ADDITIONAL_INFO = 'styla_connect_product_info_renderer_collect_additional';

    protected $_store;

    /**
     * Collect the data and return it as array, ready to be turned into json
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    final public function render(Mage_Catalog_Model_Product $product)
    {
        $productInfo = $this->_collectProductInfo($product);

        return $productInfo;
    }

    /**
     * Collect the basic information about the product and return it as an array.
     *
     * @param Mage_Catalog_Model_Product $product
     * @return array
     */
    protected function _collectProductInfo(Mage_Catalog_Model_Product $product)
    {
        //basic product info, same for every possible product type
        $productInfo = array(
            'id'            => $product->getId(),
            'type'          => $product->getTypeId(),
            'name'          => $product->getName(),
            'saleable'      => $product->isSaleable(),
            'price'         => Mage::helper('tax')->getPrice($product, $product->getFinalPrice()),
            'priceTemplate' => $this->getPriceTemplate(),
        );

        //if product has active special price
        if ($oldPrice = $this->getOldPrice($product)) {
            $productInfo['oldPrice'] = $oldPrice;
        }

        //allowed sale quantities
        if ($qtyLimits = $this->getProductQtyLimits($product)) {
            list($minQty, $maxQty) = $qtyLimits;

            if ($minQty !== null) {
                $productInfo['minqty'] = $minQty;
            }

            if ($maxQty !== null) {
                $productInfo['maxqty'] = $maxQty;
            }
        }

        //add product tax info
        if ($taxInfo = $this->getProductTax($product)) {
            $productInfo['tax'] = $taxInfo;
        }

        //get additional info, if possible
        //this may be different for various product types
        $productInfo = $this->_collectAdditionalProductInfo($product, $productInfo);

        return $productInfo;
    }

    /**
     * Get the tax info, if this product has a tax class
     * This will load a default tax rate (default address, default customer type)
     *
     * @param Mage_Catalog_Model_Product $product
     * @return boolean|array
     */
    public function getProductTax(Mage_Catalog_Model_Product $product)
    {
        $taxId = $product->getTaxClassId();
        if (null === $taxId) {
            return false;
        }

        $store = $this->_getStore();

        $taxCalculation = Mage::getModel('tax/calculation');
        $taxRequest     = $taxCalculation->getRateRequest(
            null,
            null,
            null,
            $store
        ); //for default address and customer class

        $taxRate = $taxCalculation->getRate($taxRequest->setProductClassId($taxId)); //get calculated default rate

        //get defailed tax info
        $taxRateInfo = $taxCalculation->getResource()->getRateInfo($taxRequest);
        $taxLabel    = isset($taxRateInfo['process'][0]['id']) ? $taxRateInfo['process'][0]['id'] : "";

        $isTaxIncluded = Mage::helper('tax')->priceIncludesTax($store);

        $taxInfo = array(
            'rate'        => $taxRate,
            'label'       => $taxLabel,
            'taxIncluded' => $isTaxIncluded,
            'showLabel'   => true, //TODO: this should have a system config
        );

        return $taxInfo;
    }

    /**
     * If there are limits for qty in cart for this product, return them
     *
     * @param Mage_Catalog_Model_Product $product
     * @return boolean|array
     */
    public function getProductQtyLimits(Mage_Catalog_Model_Product $product)
    {
        $minQty = null;
        $maxQty = null;

        $stockItem = Mage::getModel("cataloginventory/stock_item")
            ->loadByProduct($product->getId());

        if ($stockItem) {
            $minQty = ($stockItem->getMinSaleQty()
            && $stockItem->getMinSaleQty() > 0 ? $stockItem->getMinSaleQty() * 1 : null);
            $maxQty = ($stockItem->getMaxSaleQty()
            && $stockItem->getMaxSaleQty() > 0 ? $stockItem->getMaxSaleQty() * 1 : null);
        } else {
            return false;
        }

        return array($minQty, $maxQty);
    }

    /**
     * Return the default store
     *
     * @return Mage_Core_Model_Store
     */
    protected function _getStore()
    {
        if (!$this->_store) {
            $this->_store = Mage::app()->getStore();
        }

        return $this->_store;
    }

    /**
     * Get the price temaplate for the current store
     *
     * @return string
     */
    public function getPriceTemplate()
    {
        $currency       = $this->_getStore()->getCurrentCurrency();
        $currencyFormat = $currency->getOutputFormat();

        //convert to a format acceptable for Styla
        //normally is contains %s for inserting the price value
        return str_replace("%s", "#{price}", $currencyFormat);
    }

    /**
     * Return the "normal price" of the product, if it has a special price and this special price is active
     *
     * @param Mage_Catalog_Model_Product $product
     * @return mixed
     */
    public function getOldPrice(Mage_Catalog_Model_Product $product)
    {
        $normalPrice  = $product->getPrice();
        $currentPrice = $product->getFinalPrice();

        if ($normalPrice != $currentPrice) {
            return Mage::helper('tax')->getPrice($product, $normalPrice);
        } else {
            return false;
        }
    }

    /**
     * Load and collect any other product info that we may need
     *
     * @param Mage_Catalog_Model_Product $product
     * @param array                      $productInfo
     * @return array
     */
    protected function _collectAdditionalProductInfo($product, $productInfo)
    {
        //can be overriden and used in productType-specific classes to get more detailed attributes

        //allow for collecting additional data outside of the renderer
        $transportObject = new Varien_Object();
        $transportObject->setProductInfo($productInfo);
        $transportObject->setProduct($product);
        Mage::dispatchEvent(self::EVENT_COLLECT_ADDITIONAL_INFO, array('transport_object' => $transportObject));

        $productInfo = $transportObject->getProductInfo();

        return $productInfo;
    }
}