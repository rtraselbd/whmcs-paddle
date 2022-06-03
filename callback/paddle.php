<?php

/**
 * Paddle WHMCS Gateway
 *
 * Copyright (c) 2022 UuddoktaPay
 * Website: https://uddoktapay.com
 * Developer: rtrasel.com
 * 
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

class Paddle
{
    /**
     * @var self
     */
    private static $instance;

    /**
     * @var string
     */
    protected $gatewayModuleName;

    /**
     * @var array
     */
    protected $gatewayParams;

    /**
     * @var boolean
     */
    public $isActive;

    /**
     * @var integer
     */
    protected $customerCurrency;

    /**
     * @var object
     */
    protected $gatewayCurrency;

    /**
     * @var integer
     */
    protected $clientCurrency;

    /**
     * @var float
     */
    protected $convoRate;

    /**
     * @var array
     */
    protected $invoice;

    /**
     * @var float
     */
    protected $due;

    /**
     * @var int
     */
    public $invoiceID;

    /**
     * @var float
     */
    public $total;

    /**
     * UddoktaPay constructor.
     */
    public function __construct()
    {
        $this->setGateway();
    }

    /**
     * The instance.
     *
     * @return self
     */
    public static function init()
    {
        if (self::$instance == null) {
            self::$instance = new Paddle;
        }

        return self::$instance;
    }

    /**
     * Set the payment gateway.
     */
    private function setGateway()
    {
        $this->gatewayModuleName = basename(__FILE__, '.php');
        $this->gatewayParams     = getGatewayVariables($this->gatewayModuleName);
        $this->isActive          = !empty($this->gatewayParams['type']);
    }

    /**
     * Set the invoice.
     */
    private function setInvoice()
    {
        $this->invoice = localAPI('GetInvoice', [
            'invoiceid' => $this->invoiceID
        ]);

        $this->setCurrency();
        $this->setDue();
        $this->setTotal();
    }

    /**
     * Set currency.
     */
    private function setCurrency()
    {
        $this->gatewayCurrency  = (int) $this->gatewayParams['convertto'];
        $this->customerCurrency = (int) \WHMCS\Database\Capsule::table('tblclients')
            ->where('id', '=', $this->invoice['userid'])
            ->value('currency');

        if (!empty($this->gatewayCurrency) && ($this->customerCurrency !== $this->gatewayCurrency)) {
            $this->convoRate = \WHMCS\Database\Capsule::table('tblcurrencies')
                ->where('id', '=', $this->gatewayCurrency)
                ->value('rate');
        } else {
            $this->convoRate = 1;
        }
    }

    /**
     * Set due.
     */
    private function setDue()
    {
        $this->due = $this->invoice['balance'];
    }

    /**
     * Set total.
     */
    private function setTotal()
    {
        $this->total = ceil(($this->due) * $this->convoRate);
    }

    /**
     * Check if transaction if exists.
     *
     * @param string $trxId
     *
     * @return mixed
     */
    private function checkTransaction($trxId)
    {
        return localAPI(
            'GetTransactions',
            ['transid' => $trxId]
        );
    }

    /**
     * Log the transaction.
     *
     * @param array $payload
     *
     * @return mixed
     */
    private function logTransaction($payload)
    {
        return logTransaction(
            $this->gatewayParams['name'],
            $payload,
            $payload['status']
        );
    }

    /**
     * Add transaction to the invoice.
     *
     * @param string $trxId
     *
     * @return array
     */
    private function addTransaction($trxId)
    {
        $fields = [
            'invoiceid' => $this->invoice['invoiceid'],
            'transid'   => $trxId,
            'gateway'   => $this->gatewayModuleName,
            'date'      => \Carbon\Carbon::now()->toDateTimeString(),
            'amount'    => $this->due,
            'fees'      => 0,
        ];
        $add    = localAPI('AddInvoicePayment', $fields);

        return array_merge($add, $fields);
    }

    /**
     * Execute the payment by ID.
     *
     * @return array
     */
    private function executePayment()
    {
        $key = $this->gatewayParams['publicKey'];

        if (str_contains($key, 'BEGIN PUBLIC KEY') && str_contains($key, 'END PUBLIC KEY')) {
            $public_key = $this->gatewayParams['publicKey'];
        } else {
            $public_key = "-----BEGIN PUBLIC KEY-----" . "\n" . "{$key}" . "\n" . "-----END PUBLIC KEY-----";
        }

        $signature = base64_decode($_POST['p_signature']);

        $fields = $_POST;

        unset($fields['p_signature']);

        ksort($fields);
        foreach ($fields as $k => $v) {
            if (!in_array(gettype($v), array('object', 'array'))) {
                $fields[$k] = "$v";
            }
        }
        $data = serialize($fields);

        // Verify the signature
        $verification = openssl_verify($data, $signature, $public_key, OPENSSL_ALGO_SHA1);

        if ($verification != 1) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid API Signature.'
            ];
        }

        if (is_array($fields)) {
            return $fields;
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid response from UddoktaPay API.'
        ];
    }

    /**
     * Make the transaction.
     *
     * @return array
     */
    public function makeTransaction()
    {
        $executePayment = $this->executePayment();

        if (!isset($executePayment['passthrough']) && !isset($executePayment['alert_name'])) {
            return [
                'status'    => 'error',
                'message'   => 'Invalid Response.',
            ];
        }

        if (isset($executePayment['alert_name']) && $executePayment['alert_name'] === 'subscription_payment_succeeded' || $executePayment['alert_name'] === 'payment_succeeded') {

            $this->invoiceID = $executePayment['passthrough'];
            $this->setInvoice();

            $existing = $this->checkTransaction($executePayment['order_id']);

            if ($existing['totalresults'] > 0) {
                return [
                    'status'    => 'error',
                    'message'   => 'The transaction has been already used.'
                ];
            }
            if (!empty($this->gatewayParams["taxInclusive"])) {
                $paymentAmount = $executePayment['balance_gross'];
            } else {
                $paymentAmount = $executePayment['balance_fee'] + $executePayment['balance_earnings'];
            }

            if ($paymentAmount < $this->total) {
                return [
                    'status'    => 'error',
                    'message'   => 'You\'ve paid less than amount is required.'
                ];
            }

            $this->logTransaction($executePayment);

            $trxAddResult = $this->addTransaction($executePayment['order_id']);

            if ($trxAddResult['result'] === 'success') {
                return [
                    'status'  => 'success',
                    'message' => 'The payment has been successfully verified.',
                ];
            }
        }

        return [
            'status'    => 'error',
            'message'   => 'Invalid Response.',
        ];
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Direct access forbidden.");
}

$Paddle = Paddle::init();

if (!$Paddle->isActive) {
    die("The gateway is unavailable.");
}

$response = $Paddle->makeTransaction();
die(json_encode($response));
