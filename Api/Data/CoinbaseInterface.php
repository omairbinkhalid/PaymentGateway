<?php

namespace CoinbaseCommerce\PaymentGateway\Api\Data;

interface CoinbaseInterface
{
    /**
     * Constants for keys of data array. Identical to the name of the getter in snake case
     */
    const ID       = 'id';
    const STORE_ORDER_ID = 'store_order_id';
    const COINBASE_CHARGE_CODE = 'coinbase_charge_code';

    /**
     * @return int
     */
    public function getId();

    /**
     * @param int $id
     * @return void
     */
    public function setId($id);

    /**
     * Gets the magento store increment ID for the order.
     *
     * @return string|null Increment ID.
     */
    public function getStoreOrderId();

    /**
     * @param string $incrementId
     * @return void
     */
    public function setStoreOrderId($incrementId);

    /**
     * Gets the magento store increment ID for the order.
     *
     * @return string|null Increment ID.
     */
    public function getCoinbaseChargeCode();

    /**
     * @param string $code
     * @return void
     */
    public function setCoinbaseChargeCode($code);
}
