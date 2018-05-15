<?php

namespace CoinbaseCommerce\PaymentGateway\Model;

use CoinbaseCommerce\PaymentGateway\Api\Data\CoinbaseInterface;
use Magento\Framework\DataObject\IdentityInterface;

class Coinbase extends \Magento\Framework\Model\AbstractModel implements CoinbaseInterface, IdentityInterface
{
    /**
     * Coinbase Commerce Order
     */
    const CACHE_TAG = 'coinbase_order';

    /**
     * @var string
     */
    protected $_cacheTag = 'coinbase_order';

    /**
     * Prefix of model events names
     *
     * @var string
     */
    protected $_eventPrefix = 'coinbase_order';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct()
    {
        $this->_init('CoinbaseCommerce\PaymentGateway\Model\ResourceModel\Coinbase');
    }

    /**
     * Return unique ID(s) for each object in system
     *
     * @return array
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG . '_' . $this->getId()];
    }

    /**
     * Get ID
     *
     * @return int|null
     */
    public function getId()
    {
        return $this->getData(self::ID);
    }

    /**
     * Get Store order increment id
     *
     * @return string
     */
    public function getStoreOrderId()
    {
        return $this->getData(self::STORE_ORDER_ID);
    }

    /**
     * Get Coinbase charge code
     *
     * @return string
     */
    public function getCoinbaseChargeCode()
    {
        return $this->getData(self::COINBASE_CHARGE_CODE);
    }

    /**
     * Set ID
     *
     * @param int $id
     * @return \CoinbaseCommerce\PaymentGateway\Api\Data\CoinbaseInterface
     */
    public function setId($id)
    {
        return $this->setData(self::ID, $id);
    }

    /**
     * @param string $incrementId
     * @return \CoinbaseCommerce\PaymentGateway\Api\Data\CoinbaseInterface
     */
    public function setStoreOrderId($incrementId)
    {
        return $this->setData(self::STORE_ORDER_ID, $incrementId);
    }

    /**
     * @param string $charge
     * @return \CoinbaseCommerce\PaymentGateway\Api\Data\CoinbaseInterface
     */
    public function setCoinbaseChargeCode($charge)
    {
        return $this->setData(self::COINBASE_CHARGE_CODE, $charge);
    }
}
