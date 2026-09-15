<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

require_once __DIR__ . '/nowpayments/lib.php';

/**
 * NOWPayments gateway metadata.
 *
 */
function nowpayments_MetaData()
{
    return [
        'DisplayName' => 'NOWPayments',
        'APIVersion' => '1.1',
    ];
}

/**
 * Gateway configuration.
 */
function nowpayments_config()
{
    return [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => 'NOWPayments',
        ],
        'apiKey' => [
            'FriendlyName' => 'API Key',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Your NOWPayments API key(Public key not required).',
        ],
        'ipnSecret' => [
            'FriendlyName' => 'IPN Secret',
            'Type' => 'password',
            'Size' => '60',
            'Default' => '',
            'Description' => 'Your NOWPayments Instant Payment Notification secret key.',
        ],
    ];
}

/**
 * Render the Pay button on the WHMCS invoice.
 *
 */
function nowpayments_link($params)
{
    $apiKey = isset($params['apiKey']) ? trim((string) $params['apiKey']) : '';
    $ipnSecret = isset($params['ipnSecret']) ? trim((string) $params['ipnSecret']) : '';

    if ($apiKey === '' || $ipnSecret === '') {
        return '<div class="alert alert-danger">NOWPayments is not fully configured. Please contact support.</div>';
    }

    $invoiceId = (string) $params['invoiceid'];
    $amount = (string) $params['amount'];
    $currency = strtoupper((string) $params['currency']);
    $systemUrl = nowpayments_normalize_system_url(isset($params['systemurl']) ? $params['systemurl'] : '');

    if ($systemUrl === '') {
        return '<div class="alert alert-danger">WHMCS System URL is not configured. Please contact support.</div>';
    }

    $token = nowpayments_checkout_token($invoiceId, $amount, $currency, $ipnSecret);
    $action = $systemUrl . 'modules/gateways/nowpayments/pay.php';
    $buttonText = !empty($params['langpaynow']) ? (string) $params['langpaynow'] : 'Pay Now';

    $html = '<form method="post" action="' . nowpayments_e($action) . '" style="display:inline-block;margin:0;">';
    $html .= '<input type="hidden" name="invoice_id" value="' . nowpayments_e($invoiceId) . '">';
    $html .= '<input type="hidden" name="amount" value="' . nowpayments_e($amount) . '">';
    $html .= '<input type="hidden" name="currency" value="' . nowpayments_e($currency) . '">';
    $html .= '<input type="hidden" name="token" value="' . nowpayments_e($token) . '">';
    $html .= '<button type="submit" class="btn btn-primary">' . nowpayments_e($buttonText) . '</button>';
    $html .= '</form>';

    return $html;
}
