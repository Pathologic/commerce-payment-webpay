<?php


namespace Commerce\Payments;


class Webpay extends Payment
{
    protected $debug = false;
	protected $test = false;
    public function __construct($modx, array $params = [])
    {
        parent::__construct($modx, $params);
        $this->debug = !empty($this->getSetting('debug'));
		$this->test = !empty($this->getSetting('test'));
        $this->lang = $modx->commerce->getUserLanguage('webpay');
    }

    public function getMarkup()
    {
        $out = [];

        if (empty($this->getSetting('store_id'))) {
            $out[] = $this->lang['payments.error_empty_shop_id'];
        }

        if (empty($this->getSetting('secret'))) {
            $out[] = $this->lang['payments.error_empty_secret'];
        }

        $currency = ci()->currency->getDefaultCurrencyCode();
        if (!in_array(strtoupper($currency), ['BYN', 'USD', 'EUR', 'RUB'])) {
            $out[] = $this->lang['webpay.error.unsupported_curency'];
        }

        $out = implode('<br>', $out);

        if (!empty($out)) {
            $out = '<span class="error" style="color: red;">' . $out . '</span>';
        }

        return $out;
    }

    public function getPaymentMarkup()
    {
        $seed = time();

        $processor = ci()->commerce->loadProcessor();
        $order = $processor->getOrder();
        $currency = ci()->currency->getCurrency($order['currency']);
        $payment = $this->createPayment($order['id'],
            ci()->currency->convertToDefault($order['amount'], $currency['code']));

        $site_url = $this->modx->getConfig('site_url');
        $data = [
            '*scart'                => '',
            'wsb_version'           => '2',
            'wsb_seed'              => $seed,
            'wsb_test'              => $this->test ? 1 : 0,
            'wsb_storeid'           => $this->getSetting('store_id'),
            'wsb_order_num'         => $order['id'],
            'wsb_currency_id'       => $currency['code'],
            'wsb_return_url'        => $site_url . 'commerce/webpay/payment-success/',
            'wsb_cancel_return_url' => $site_url . 'commerce/webpay/payment-failed/',
            'wsb_notify_url'        => $site_url . 'commerce/webpay/payment-process/?' . http_build_query([
                    'paymentId'   => $payment['id'],
                    'paymentHash' => $payment['hash']
                ])
        ];

        $isPartialPayment = $payment['amount'] < $order['amount'];
        $cart = $processor->getCart();
        if ($isPartialPayment) {
            $items = parent::prepareItems($cart);
            $items = $this->decreaseItemsAmount($items, $order['amount'], $payment['amount']);
            $data['wsb_total'] = number_format($payment['amount'], 2, '.', '');
        } else {
            $items = $this->prepareItems($cart);
            $items_price = $total = $discount = 0;
            foreach ($items as $item) {
                $items_price += $item['total'];
            }
            $subtotals = [];
            $cart->getSubtotals($subtotals, $total);
            $delivery = $processor->getCurrentDelivery();
            $deliveries = ci()->commerce->getDeliveries();
            if (isset($deliveries[$delivery]['title'])) {
                $delivery = $deliveries[$delivery]['title'];
            }
            foreach ($subtotals as $id => $item) {
                if ($item['title'] == $delivery) {
                    $data['wsb_shipping_name'] = $delivery;
                    $data['wsb_shipping_price'] = number_format($item['price'], 2, '.', '');
                    continue;
                }
                if ($item['price'] < 0) {
                    $discount -= $item['price'];
                } else if ($item['price'] > 0) {
                    $items_price += $item['price'];
                    $items[] = [
                        'id'      => 0,
                        'name'    => mb_substr($item['title'], 0, 255),
                        'count'   => 1,
                        'price'   => number_format($item['price'], 2, '.', ''),
                        'total'   => number_format($item['price'], 2, '.', ''),
                        'product' => false,
                    ];
                }
            }
            $data['wsb_discount_name'] = $this->lang['webpay.discount'];
            $data['wsb_discount_price'] = abs(number_format($discount, 2, '.', ''));
            $data['wsb_total'] = number_format($order['amount'], 2, '.', '');
        }

        foreach ($items as $i => $item) {
            $data['wsb_invoice_item_name'][] = $item['name'];
            $data['wsb_invoice_item_quantity'][] = $item['count'];
            $data['wsb_invoice_item_price'][] = number_format($item['price'], 2, '.', '');
        }

        if (!empty($order['name']) && is_scalar([$order['name']])) {
            $data['wsb_customer_name'] = $order['name'];
        }

        if (!empty($order['email']) && filter_var($order['email'], FILTER_VALIDATE_EMAIL)) {
            $data['wsb_email'] = $order['email'];
        }

        if (!empty($order['phone']) && is_scalar($order['phone'])) {
            $data['wsb_phone'] = preg_replace('/[^\d]/', '', $order['phone']);
        }

        $data['wsb_signature'] = sha1(
            $data['wsb_seed']
            . $data['wsb_storeid']
            . $data['wsb_order_num']
            . $data['wsb_test']
            . $data['wsb_currency_id']
            . $data['wsb_total']
            . $this->getSetting('secret')
        );

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Request data: <pre>' . htmlentities(print_r($data, true)) . '</pre>',
                'Commerce Webpay Payment Debug: payment start');
        }

        $view = new \Commerce\Module\Renderer($this->modx, null, [
            'path' => 'assets/plugins/commerce/templates/front/',
        ]);

        return $view->render('payment_form.tpl', [
            'url'    => $this->test ? 'https://securesandbox.webpay.by' : 'https://payment.webpay.by',
            'method' => 'post',
            'data'   => $data,
        ]);
    }

    protected function prepareItems($cart, $orderCurrency = NULL, $paymentCurrency = NULL)
    {
        $items = [];

        $discount = 0;
        $items_price = 0;

        foreach ($cart->getItems() as $item) {
            $items_price += $item['price'] * $item['count'];
            $items[] = [
                'id'      => $item['id'],
                'name'    => mb_substr($item['name'], 0, 255),
                'count'   => number_format($item['count'], 3, '.', ''),
                'price'   => number_format($item['price'], 2, '.', ''),
                'total'   => number_format($item['price'] * $item['count'], 2, '.', ''),
                'product' => true,
            ];
        }

        return $items;
    }

    public function handleCallback()
    {
        $data = $_POST;

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Callback data: <pre>' . htmlentities(print_r($data, true)) . '</pre>',
                'Commerce Webpay Payment Debug: callback start');
        }

        $fields = [
            'batch_timestamp',
            'currency_id',
            'amount',
            'payment_method',
            'order_id',
            'site_order_id',
            'transaction_id',
            'payment_type',
            'rrn',
        ];

        $process = false;

        if (!empty($_REQUEST['paymentId']) && is_scalar($_REQUEST['paymentId']) && !empty($data['wsb_signature'])) {
            $paymentId = $_REQUEST['paymentId'];
            foreach ($fields as $field) {
                if (empty($data[$field]) || !is_scalar($data[$field])) {
                    $process = false;
                    break;
                } else {
                    $process = true;
                }
            }
        }

        if (!$process) {
            $this->modx->logEvent(0, 3, 'Not enough data', 'Commerce Webpay Payment');

            return false;
        }

        $signature = md5(
            $data['batch_timestamp']
            . $data['currency_id']
            . $data['amount']
            . $data['payment_method']
            . $data['order_id']
            . $data['site_order_id']
            . $data['transaction_id']
            . $data['payment_type']
            . $data['rrn']
            . $this->getSetting('secret')
        );

        if ($this->debug) {
            $this->modx->logEvent(0, 1, 'Generated signature: ' . $signature, 'Commerce Robokassa Payment Debug');
        }

        if ($signature != $data['wsb_signature']) {
            $this->modx->logEvent(0, 3, 'Signature check failed: ' . $signature . ' != ' . $data['wsb_signature'],
                'Commerce Webpay Payment');
            return false;
        }

        try {
            $this->modx->commerce->loadProcessor()->processPayment($paymentId, floatval($data['amount']));
        } catch (\Exception $e) {
            $this->modx->logEvent(0, 3, 'Payment processing failed: ' . $e->getMessage(), 'Commerce Webpay Payment');

            return false;
        }

        return true;
    }
}
