<?php
/**
 * Coinbase Commerce
 */

namespace CoinbaseCommerce\PaymentGateway\Controller\Start;

use Magento\Store\Model\ScopeInterface;

class Index extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    private $checkoutSession;

    private $resultJsonFactory;

    private $logger;

    private $scopeConfig;

    private $logo;

    /**
     *  * @var \Magento\Framework\HTTP\Client\Curl
     *  */
    private $curl;

    /**
     * Index constructor.
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory,
        \Psr\Log\LoggerInterface $logger,
        \Magento\Framework\HTTP\Client\Curl $curl,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Theme\Block\Html\Header\Logo $logo
    ) {
        $this->checkoutSession = $checkoutSession;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->logger = $logger;
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logo = $logo;
        parent::__construct($context);
    }

    /**
     * Start checkout by requesting checkout code and dispatching customer to Coinbase.
     */
    public function execute()
    {
        $responseArray = $this->getCurlResponse();
        $redirectUrl = $this->extractRedirectUrl($responseArray);

        $result = $this->resultJsonFactory->create();
        return $result->setData(['redirectUrl' => $redirectUrl]);
    }

    /**
     * Get order object.
     *
     * @return \Magento\Sales\Model\Order
     */
    private function getOrder()
    {
        return $this->checkoutSession->getLastRealOrder();
    }

    /**
     * @return mixed|string
     */
    private function getStoreName()
    {
        $store = $this->scopeConfig->getValue('general/store_information/name', ScopeInterface::SCOPE_STORE);
        $store = $store ? $store : 'E-commerce Store';
        return $store;
    }

    /**
     * @param $order
     * @return mixed
     */
    private function getMetaData($order)
    {
        $metaData["id"] = $order->getCustomerId();
        $metaData["customer_name"] = $order->getCustomerName();
        $metaData["customer_email"] = $order->getCustomerEmail();
        $metaData["store_increment_id"] = $order->getIncrementId();
        return $metaData;
    }

    /**
     * @param $order
     * @return mixed
     */
    private function getPricing($order)
    {
        $pricing["amount"] = $order->getGrandTotal();
        $pricing["currency"] = $order->getStoreCurrencyCode();
        return $pricing;
    }

    /**
     * @return string
     */
    private function setJsonData()
    {
        $order = $this->getOrder();
        $data = json_encode([
            "name" => $this->getStoreName(),
            "description" => "Purchased through Coinbase Commerce",
            "local_price" => $this->getPricing($order),
            "pricing_type" => "fixed_price",
            "metadata" => $this->getMetaData($order),
            "redirect_url" => $this->_url->getUrl('coinbasecommerce/webhook/redirect')
        ]);
        return $data;
    }

    /**
     * @return mixed
     */
    private function getJsonHeaders()
    {
        $apiKey = $this->scopeConfig->getValue('payment/coinbasemethod/api_key', ScopeInterface::SCOPE_STORE);
        $headers["Content-Type"] = "application/json";
        $headers["X-CC-Api-Key"] = $apiKey;
        $headers["X-CC-Version"] = "2018-03-22";
        return $headers;
    }

    /**
     * @return mixed
     */
    private function getCurlResponse()
    {
        $url = "https://api.commerce.coinbase.com/charges/";
        $this->curl->setHeaders($this->getJsonHeaders());
        $this->curl->post($url, $this->setJsonData());
        $response = $this->curl->getBody();
        return json_decode($response, true);
    }

    /**
     * @param $responseArray
     * @return string
     */
    private function extractRedirectUrl($responseArray)
    {
        $redirectUrl = '';
        if (isset($responseArray['data']['hosted_url'])) {
            $redirectUrl = $responseArray['data']['hosted_url'];
        }
        return $redirectUrl;
    }
}
