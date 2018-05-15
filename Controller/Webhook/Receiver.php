<?php
/**
 * Coinbase Commerce
 */

namespace CoinbaseCommerce\PaymentGateway\Controller\Webhook;

use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Filesystem\Io\File;
use CoinbaseCommerce\PaymentGateway\Api\CoinbaseRepositoryInterface;
use CoinbaseCommerce\PaymentGateway\Api\Data\CoinbaseInterfaceFactory;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Registry;
use Magento\Sales\Model\Order\Status\HistoryFactory;
use Magento\Sales\Api\OrderStatusHistoryRepositoryInterface;

class Receiver extends Action
{
    private $orderRepository;
    private $scopeConfig;
    private $jsonResultFactory;
    private $file;
    private $coinbaseRepository;
    private $searchCriteriaBuilder;
    private $registry;
    private $order;
    private $coinStatus;
    private $historyRepository;
    private $historyFactory;
    /**
     * @var \CoinbaseCommerce\PaymentGateway\Model\Coinbase
     */
    private $coinbaseFactory;

    public function __construct(
        Context $context,
        OrderRepositoryInterface $order,
        ScopeConfigInterface $scopeConfig,
        JsonFactory $jsonResultFactory,
        File $file,
        CoinbaseRepositoryInterface $coinbaseRepository,
        CoinbaseInterfaceFactory $coinbaseInterfaceFactory,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        Registry $registry,
        HistoryFactory $historyFactory,
        OrderStatusHistoryRepositoryInterface $historyRepository
    ) {
        parent::__construct($context);
        $this->orderRepository = $order;
        $this->scopeConfig = $scopeConfig;
        $this->jsonResultFactory = $jsonResultFactory;
        $this->file = $file;
        $this->coinbaseRepository = $coinbaseRepository;
        $this->coinbaseFactory = $coinbaseInterfaceFactory;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->registry = $registry;
        $this->historyFactory = $historyFactory;
        $this->historyRepository = $historyRepository;
    }

    /**
     * @return \Magento\Framework\Controller\Result\Json
     */
    public function execute()
    {
        $input = $this->file->read('php://input');
//        $input = $this->returnSomething();

        if (!$this->authenticate($input)) {
            return null;
        }

        $event = $this->getEventData(json_decode($input));
        if (!$this->getOrder($event)) {
            return null;
        }

        if ($event['type'] == 'charge:created') {
            $this->saveOrderDetails($event);
        } elseif ($event['coinbaseStatus'] == 'UNRESOLVED') {
            $this->orderHoldAction($event);
        } elseif ($event['type'] == 'charge:failed' && $event['coinbaseStatus'] == 'EXPIRED') {
            $this->paymentFailedAction();
        } elseif ($event['type'] == 'charge:confirmed' && $event['coinbaseStatus'] == 'COMPLETED') {
            $this->paymentSuccessAction($event);
        }

        $this->getResponse()->setStatusHeader(200);
        /** @var \Magento\Framework\Controller\Result\Json $result */
        $result = $this->jsonResultFactory->create();
        return $result;
    }

    private function authenticate($payload)
    {
        $key = $this->scopeConfig->getValue('payment/coinbasemethod/api_secret');
        $headerSignature = $this->getRequest()->getHeader('X-CC-Webhook-Signature');
        $computedSignature = hash_hmac('sha256', $payload, $key);
        return $headerSignature === $computedSignature;
    }

    /**
     * Generate an "IPN" comment with additional explanation.
     * Returns the generated comment or order status history object
     *
     * @param string $comment
     * @param bool $addToHistory
     * @return string|\Magento\Sales\Model\Order\Status\History
     */
    private function _createIpnComment($comment = '', $addToHistory = false)
    {
        $message = __('IPN "%1"', $this->coinStatus);
        if ($comment) {
            $message .= ' ' . $comment;
        }
        if ($addToHistory) {
            $message = $this->order->addStatusHistoryComment($message);
            $message->setIsCustomerNotified(null);
        }
        return $message;
    }

    /**
     * @param $input
     * @return array
     */
    private function getEventData($input)
    {
        $data['incrementId'] = $input->event->data->metadata->store_increment_id;
        $data['chargeCode'] = $input->event->data->code;
        $data['type'] = $input->event->type;
        $data['timeline'] = end($input->event->data->timeline);
        $this->coinStatus = $data['coinbaseStatus'] = end($input->event->data->timeline)->status;
        $data['coinbasePayment'] = reset($input->event->data->payments);

        return $data;
    }

    /**
     * @param $event
     */
    private function saveOrderDetails($event)
    {
        /** @var \CoinbaseCommerce\PaymentGateway\Model\Coinbase $coinbase */
        $coinbase = $this->coinbaseFactory->create();
        $coinbase->setCoinbaseChargeCode($event['chargeCode']);
        $coinbase->setStoreOrderId($event['incrementId']);
        $this->coinbaseRepository->save($coinbase);
    }

    /**
     * @param $event
     * @return \Magento\Sales\Api\Data\OrderInterface|mixed|null
     */
    private function getOrder($event)
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('increment_id', $event['incrementId'], 'eq')->create();
        $orderList = $this->orderRepository->getList($searchCriteria)->getItems();
        $this->order = $order = reset($orderList) ? reset($orderList) : null;
        return $order;
    }

    /**
     * Remove order from store
     */
    private function paymentFailedAction()
    {
        $this->registry->register('isSecureArea', 'true');
        $this->orderRepository->delete($this->order);
        $this->registry->unregister('isSecureArea');
    }

    /**
     * Hold order state in store
     */
    private function orderHoldAction($event)
    {
        /** @var \Magento\Sales\Api\Data\OrderStatusHistoryInterface $history */
        $history = $this->historyFactory->create();
        $history->setParentId($this->order->getId())
            ->setComment($event['timeline']->context)
            ->setEntityName('order')
            ->setStatus(\Magento\Sales\Model\Order::STATE_HOLDED);

        $this->historyRepository->save($history);
        $this->order->hold();
        $this->orderRepository->save($this->order);
    }

    /**
     * @param $event
     * @param $comment
     * @return mixed
     */
    private function updatePaymentOnSuccess($event, $comment)
    {
        $payment = $this->order->getPayment();

        $payment->setTransactionId($event['coinbasePayment']->transaction_id);
        $payment->setCurrencyCode($event['coinbasePayment']->value->crypto->currency);
        $payment->setPreparedMessage($this->_createIpnComment($comment));
        $payment->setShouldCloseParentTransaction(true);
        $payment->setIsTransactionClosed(0);
        $payment->registerCaptureNotification(
            $event['coinbasePayment']->value->crypto->amount,
            true
        );
        return $payment;
    }

    /**
     * @param $payment
     */
    private function updateInvoiceOnSuccess($payment)
    {
        $invoice = $payment->getCreatedInvoice();
        if ($invoice && !$this->order->getEmailSent()) {
            $this->order->send($this->order);
            $this->order->addStatusHistoryComment(
                __('You notified customer about invoice #%1.', $invoice->getIncrementId())
            )->setIsCustomerNotified(
                true
            );
            $this->orderRepository->save($this->order);
        }
    }

    /**
     * @param $event
     */
    private function paymentSuccessAction($event)
    {
        $crypto = $event['coinbasePayment']->value->crypto;
        $comment = $crypto->currency . ' ' . $crypto->amount;
        $payment = $this->updatePaymentOnSuccess($event, $comment);
        $this->orderRepository->save($this->order);
        $this->updateInvoiceOnSuccess($payment);
    }

    private function returnSomething()
    {
        $testOrderId = '000000086';
        $testConfirmed = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-08T11:54:08Z","data":{"code":"EMDWFKFL","name":"Coinbase ","pricing":{"local":{"amount":"64.00","currency":"USD"},"bitcoin":{"amount":"0.00694144","currency":"BTC"},"ethereum":{"amount":"0.087313000","currency":"ETH"},"litecoin":{"amount":"0.39918915","currency":"LTC"},"bitcoincash":{"amount":"0.04030544","currency":"BCH"}},"metadata":{"id":"2","customer_name":"Omair Khalid","customer_email":"omair.khalid@appsgenii.eu","store_increment_id":"' . $testOrderId . '"},"payments":[{"network":"ethereum","transaction_id":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","status":"CONFIRMED","value":{"local":{"amount":"64.0","currency":"USD"},"crypto":{"amount":"0.087313000","currency":"ETH"}},"block":{"height":100,"hash":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","confirmations_accumulated":8,"confirmations_required":2}}],"timeline":[{"time":"2018-05-08T11:54:08Z","status":"COMPLETED"}],"addresses":{"bitcoin":"17PxE95wxC2pmdnzAJZM96uCGdzBZozXLo","ethereum":"0x9308d002dbd15fb80006166d81b058e9bb5a7c05","litecoin":"Lao5zncTkDvh8N2xXr1CEzRPj3VoQoQfb5","bitcoincash":"qrryq8fj7p75rejmrm3jqedrlmlqewl6dqvkv8fdns"},"created_at":"2018-05-08T11:54:08Z","expires_at":"2018-05-08T12:09:08Z","hosted_url":"https://commerce.coinbase.com/charges/EMDWFKFL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price"},"id":"37c7356c-4006-40e2-81a1-d4eb5f926c27","type":"charge:confirmed"},"id":"3807414c-6cbe-46c9-8f7c-0424748fece2","scheduled_for":"2018-05-08T11:54:08Z"}';
        $testPendingAfterFifteen = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-08T11:54:08Z","data":{"code":"EMDWkkFL","name":"Coinbase ","pricing":{"local":{"amount":"64.00","currency":"USD"},"bitcoin":{"amount":"0.00694144","currency":"BTC"},"ethereum":{"amount":"0.087313000","currency":"ETH"},"litecoin":{"amount":"0.39918915","currency":"LTC"},"bitcoincash":{"amount":"0.04030544","currency":"BCH"}},"metadata":{"id":"2","customer_name":"Omair Khalid","customer_email":"omair.khalid@appsgenii.eu","store_increment_id":"' . $testOrderId . '"},"payments":[{"network":"ethereum","transaction_id":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","status":"PENDING","value":{"local":{"amount":"64.0","currency":"USD"},"crypto":{"amount":"0.087313000","currency":"ETH"}},"block":{"height":100,"hash":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","confirmations_accumulated":8,"confirmations_required":2}}],"timeline":[{"time":"2018-05-08T11:54:08Z","status":"PENDING"}],"addresses":{"bitcoin":"17PxE95wxC2pmdnzAJZM96uCGdzBZozXLo","ethereum":"0x9308d002dbd15fb80006166d81b058e9bb5a7c05","litecoin":"Lao5zncTkDvh8N2xXr1CEzRPj3VoQoQfb5","bitcoincash":"qrryq8fj7p75rejmrm3jqedrlmlqewl6dqvkv8fdns"},"created_at":"2018-05-08T11:54:08Z","expires_at":"2018-05-08T12:09:08Z","hosted_url":"https://commerce.coinbase.com/charges/EMDWkkFL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price"},"id":"37c7356c-4006-40e2-81a1-d4eb5f926c27","type":"charge:failed"},"id":"3807414c-6cbe-46c9-8f7c-0424748fece2","scheduled_for":"2018-05-08T11:54:08Z"}';
        $testUnresolved = '{"attempt_number":1,"event":{"api_version":"2018-03-22","created_at":"2018-05-10T11:54:08Z","data":{"code":"EMDWkkFL","name":"Coinbase ","pricing":{"local":{"amount":"64.00","currency":"USD"},"bitcoin":{"amount":"0.00694144","currency":"BTC"},"ethereum":{"amount":"0.087313000","currency":"ETH"},"litecoin":{"amount":"0.39918915","currency":"LTC"},"bitcoincash":{"amount":"0.04030544","currency":"BCH"}},"metadata":{"id":"2","customer_name":"Omair Khalid","customer_email":"omair.khalid@appsgenii.eu","store_increment_id":"' . $testOrderId . '"},"payments":[{"network":"ethereum","transaction_id":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","status":"UNRESOLVED","value":{"local":{"amount":"64.0","currency":"USD"},"crypto":{"amount":"0.087313000","currency":"ETH"}},"block":{"height":100,"hash":"0xe02fead885c3e4019945428ed54d094247bada2d0ac41b08fce7ce137bf29587","confirmations_accumulated":8,"confirmations_required":2}}],"timeline":[{"time":"2018-05-10T11:54:08Z","status":"UNRESOLVED","context":"UNDERPAID"}],"addresses":{"bitcoin":"17PxE95wxC2pmdnzAJZM96uCGdzBZozXLo","ethereum":"0x9308d002dbd15fb80006166d81b058e9bb5a7c05","litecoin":"Lao5zncTkDvh8N2xXr1CEzRPj3VoQoQfb5","bitcoincash":"qrryq8fj7p75rejmrm3jqedrlmlqewl6dqvkv8fdns"},"created_at":"2018-05-10T11:54:08Z","expires_at":"2018-05-10T12:09:08Z","hosted_url":"https://commerce.coinbase.com/charges/EMDWkkFL","description":"Purchased through Coinbase Commerce","pricing_type":"fixed_price"},"id":"37c7356c-4006-40e2-81a1-d4eb5f926c27","type":"charge:failed"},"id":"3807414c-6cbe-46c9-8f7c-0424748fece2","scheduled_for":"2018-05-10T11:54:08Z"}';

        return $testUnresolved;
    }
}
