<?php

declare(strict_types=1);

namespace Mondu\MonduPaymentHyva\Preference\Mondu\Mondu\Model\Request;

use Hyva\Checkout\Model\ConfigData\HyvaThemes\Checkout as HyvaCheckoutConfig;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\Locale\Resolver;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Cart\CartTotalRepository;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Mondu\Mondu\Helpers\BuyerParams\BuyerParamsInterface;
use Mondu\Mondu\Helpers\Logger\Logger as MonduFileLogger;
use Mondu\Mondu\Helpers\OrderHelper;
use Mondu\Mondu\Helpers\PaymentMethod;
use Mondu\Mondu\Model\Request\Transactions as OriginalTransactions;
use Mondu\Mondu\Model\Ui\ConfigProvider;

class Transactions extends OriginalTransactions
{
    private string $fallbackEmail;

    public function __construct(
        Curl $curl,
        CartTotalRepository $cartTotalRepository,
        CheckoutSession $checkoutSession,
        ConfigProvider $configProvider,
        private readonly OrderHelper $orderHelper,
        private readonly UrlInterface $urlBuilder,
        private readonly BuyerParamsInterface $buyerParams,
        private readonly Resolver $store,
        private readonly MonduFileLogger $monduFileLogger,
        private readonly HyvaCheckoutConfig $checkoutConfig,
    ) {
        parent::__construct(
            $curl,
            $cartTotalRepository,
            $checkoutSession,
            $configProvider,
            $orderHelper,
            $urlBuilder,
            $buyerParams,
            $store,
            $monduFileLogger
        );
    }

    public function request($_params = []): array
    {
        try {
            if ($_params['email']) {
                $this->fallbackEmail = $_params['email'];
            }
            $params = $this->getRequestParams();

            if (in_array(
                $_params['payment_method'],
                [PaymentMethod::DIRECT_DEBIT, PaymentMethod::INSTALLMENT, PaymentMethod::INSTALLMENT_BY_INVOICE])
            ) {
                $params['payment_method'] = $_params['payment_method'];
            }

            $params = json_encode($params);

            $url = $this->_configProvider->getApiUrl('orders');

            $this->curl->addHeader('X-Mondu-User-Agent', $_params['user-agent']);

            $result = $this->sendRequestWithParams('post', $url, $params);
            $data = json_decode($result, true);
            $this->_checkoutSession->setMonduid($data['order']['uuid'] ?? null);

            if (!isset($data['order']['uuid'])) {
                return [
                    'error' => 1,
                    'body' => json_decode($result, true),
                    'message' => __('Error placing an order Please try again later.')
                ];
            } else {
                return [
                    'error' => 0,
                    'body' => json_decode($result, true),
                    'message' => __('Success')
                ];
            }
        } catch (\Exception $e) {
            $this->monduFileLogger->error('Error while creating an order', [
                'message' => $e->getMessage(),
                'trace' => $e->getTrace()
            ]);
            return [
                'error' => 1,
                'body' => null,
                'message' => $e->getMessage(),
            ];
        }
    }

    private function extractAddressParams(?Address $address, array $attributeMapping): array
    {
        if (!$address || empty($attributeMapping)) {
            return [];
        }

        $params = [];
        $fieldMap = [
            'country_id' => 'country_code',
            'postcode'   => 'zip_code',
            'region'     => 'state',
            'street.0'   => 'address_line1',
            'street.1'   => 'address_line2',
            'street.3'   => 'address_line3',
            'street.4'   => 'address_line4',
        ];

        $street = (array) $address->getStreet();
        foreach (array_keys($attributeMapping) as $key) {
            $value = $this->resolveAddressValue($address, $key, $street);
            if ($value === null || $value === '') {
                continue;
            }

            $params[$fieldMap[$key] ?? $key] = $value;
        }

        if (!empty($params['address_line1']) && $address->getStreetNumber()) {
            $params['address_line1'] .= ', ' . $address->getStreetNumber();
        }

        return $params;
    }

    private function resolveAddressValue(Address $address, string $key, array $street): mixed
    {
        if (str_starts_with($key, 'street.')) {
            $index = (int) explode('.', $key)[1];
            return $street[$index] ?? null;
        }

        $method = 'get' . str_replace(' ', '', ucwords(str_replace('_', ' ', $key)));
        if (method_exists($address, $method)) {
            return $address->$method();
        }

        return null;
    }
}
