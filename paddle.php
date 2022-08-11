<?php

/**
 * Paddle WHMCS Modules
 *
 * Copyright (c) 2022 RtRasel
 * Website: https://rtrasel.com
 * Developer: Md Rasel Islam
 * Facebook: https://facebook.com/rtraselbd
 * 
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function paddle_MetaData()
{
    return array(
        'DisplayName' => 'Paddle Gateway',
        'APIVersion' => '1.0',
        'DisableLocalCredtCardInput' => true,
        'TokenisedStorage' => false,
    );
}

function paddle_config($params)
{
    $systemUrl = $params['systemurl'];
    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'Paddle Gateway',
        ),
        'accountID' => array(
            'FriendlyName' => 'Paddle Vendor ID',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'Enter your Vendor ID here (Developer Tools -> Authentication)',
        ),
        'secretKey' => array(
            'FriendlyName' => 'Paddle Auth Code',
            'Type' => 'text',
            'Size' => '50',
            'Default' => '',
            'Description' => 'Enter your Paddle Auth Code here (Developer Tools -> Authentication -> Reveal Auth Code)',
        ),
        'publicKey' => array(
            'FriendlyName' => 'Paddle Public Key',
            'Type' => 'textarea',
            'Rows' => '3',
            'Cols' => '60',
            'Description' => 'Enter your Paddle Auth Code here (Developer Tools -> Public Key)',
        ),
        'prodId' => array(
            'FriendlyName' => 'One Time Product ID',
            'Type' => 'text',
            'Size' => '25',
            'Default' => '',
            'Description' => 'If you are selling one time products, put a Paddle Product ID here for WHMCS to use',
        ),
        'taxInclusive' => array(
            'FriendlyName' => 'Paddle account VAT Settings',
            'Type' => 'yesno',
            'Size' => '255',
            'Default' => '',
            'Description' => 'Checkout -> Sales Tax Settings -> Include in price ticked?',
        ),
        'logoUrl' => array(
            'FriendlyName' => 'Your Logo URL',
            'Type' => 'text',
            'Size' => '255',
            'Default' => '',
            'Description' => 'Enter a link to your logo for the Paddle checkout',
        ),
        'sandbox'      => [
            'FriendlyName' => 'Sandbox',
            'Type'         => 'yesno',
            'Description'  => 'Tick to enable sandbox mode',
        ],
        'webhookUrl'      => [
            'FriendlyName' => 'Webhook URL',
            'Description'  => $systemUrl . '/modules/gateways/callback/paddle.php',
        ],
    );
}


function paddle_link($params)
{
    $data = paddle_payment_url($params);
    if (!empty($data->success)) {
        $url = $data->response->url;
        return "<script src='https://cdn.paddle.com/paddle/paddle.js'></script>
        <a href='#!' id='buy' class='btn btn-success'>Pay Now</a>
        <script type='text/javascript'>
            function openCheckout() {
                Paddle.Checkout.open({
                    override: '{$url}'
                });
            }
            document.getElementById('buy').addEventListener('click', openCheckout, false);
        </script>";
    }

    return $data->error->message;
}

function paddle_payment_url($params)
{
    $invoiceId = $params['invoiceid'];
    $systemUrl = $params['systemurl'];

    $data = array();
    $data['vendor_id'] = $params['accountID'];
    $data['vendor_auth_code'] = $params['secretKey'];
    $data['customer_email'] = $params['clientdetails']['email'];
    $data['product_id'] = $params["prodId"];
    $data['title'] = $params["description"];
    $data['image_url'] = $params["logoUrl"];
    $data['return_url'] = $systemUrl . '/viewinvoice.php?id=' . $invoiceId;
    $data['marketing_consent'] = $params["marketing_emails_opt_in"];
    $data['customer_country'] = $params['clientdetails']['country'];
    $data['customer_postcode'] = $params['clientdetails']['postcode'];
    $data['passthrough'] = $params['invoiceid'];
    $data['expires'] = date('Y-m-d', strtotime("+1 days"));
    $data['prices'] = [
        'USD' . ":" . $params['amount']
    ];

    $url = 'https://vendors.paddle.com/api/2.0/product/generate_pay_link';
    if (!empty($params['sandbox'])) {
        $url = 'https://sandbox-vendors.paddle.com/api/2.0/product/generate_pay_link';
    }

    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_ENCODING => "",
        CURLOPT_MAXREDIRS => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
        CURLOPT_CUSTOMREQUEST => "POST",
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_HTTPHEADER => [
            "Content-Type: application/x-www-form-urlencoded"
        ],
    ]);

    $response = curl_exec($curl);
    curl_close($curl);
    $result = json_decode($response);
    return $result;
}
