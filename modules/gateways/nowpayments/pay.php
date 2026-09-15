<?php

require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');

require_once __DIR__ . '/lib.php';

$gatewayModuleName = 'nowpayments';
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('NOWPayments gateway is not activated.');
}

$apiKey = isset($gatewayParams['apiKey']) ? trim((string) $gatewayParams['apiKey']) : '';
$ipnSecret = isset($gatewayParams['ipnSecret']) ? trim((string) $gatewayParams['ipnSecret']) : '';

if ($apiKey === '' || $ipnSecret === '') {
    http_response_code(503);
    exit('NOWPayments gateway is not fully configured.');
}

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

$invoiceId = isset($_POST['invoice_id']) ? trim((string) $_POST['invoice_id']) : '';
$amount = isset($_POST['amount']) ? trim((string) $_POST['amount']) : '';
$currency = isset($_POST['currency']) ? strtoupper(trim((string) $_POST['currency'])) : '';
$token = isset($_POST['token']) ? trim((string) $_POST['token']) : '';

if (!preg_match('/^[1-9][0-9]*$/', $invoiceId) || !is_numeric($amount) || (float) $amount <= 0 || !preg_match('/^[A-Z0-9]{2,10}$/', $currency)) {
    http_response_code(400);
    exit('Invalid payment request.');
}

if (!nowpayments_checkout_token_is_valid($invoiceId, $amount, $currency, $token, $ipnSecret)) {
    http_response_code(400);
    exit('Invalid or expired payment request. Please return to the invoice and try again.');
}

$invoice = localAPI('GetInvoice', ['invoiceid' => (int) $invoiceId]);
if (!is_array($invoice) || !isset($invoice['result']) || $invoice['result'] !== 'success') {
    http_response_code(404);
    exit('Invoice not found.');
}

$status = isset($invoice['status']) ? (string) $invoice['status'] : '';
if (strcasecmp($status, 'Paid') === 0) {
    $systemUrl = nowpayments_normalize_system_url(isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : '');
    header('Location: ' . $systemUrl . 'viewinvoice.php?id=' . rawurlencode($invoiceId), true, 302);
    exit;
}

if (!in_array(strtolower($status), ['unpaid', 'overdue'], true)) {
    http_response_code(409);
    exit('This invoice is not currently payable.');
}

if (isset($invoice['paymentmethod']) && $invoice['paymentmethod'] !== '' && $invoice['paymentmethod'] !== $gatewayModuleName) {
    http_response_code(409);
    exit('The invoice payment method has changed. Please return to the invoice and try again.');
}

$currentBalance = isset($invoice['balance']) ? (float) $invoice['balance'] : 0.0;
$postedAmount = (float) $amount;
if ($currentBalance <= 0) {
    http_response_code(409);
    exit('This invoice has no outstanding balance.');
}

// WHMCS can render the gateway amount through its own currency/formatting path,
// while GetInvoice returns the authoritative live outstanding balance.  Do not
// block checkout when those two representations differ.  The browser-submitted
// amount is HMAC-protected above, but the NOWPayments invoice is always created
// for the latest server-side WHMCS balance so a stale page cannot over/underpay.
if (abs($currentBalance - $postedAmount) > 0.009) {
    logTransaction(
        isset($gatewayParams['name']) ? $gatewayParams['name'] : 'NOWPayments',
        [
            'invoice_id' => $invoiceId,
            'rendered_gateway_amount' => $amount,
            'live_invoice_balance' => $invoice['balance'],
            'currency' => $currency,
        ],
        'NOWPayments checkout amount refreshed from live WHMCS invoice balance'
    );
}

$requestedAmount = $currentBalance;

$systemUrl = nowpayments_normalize_system_url(isset($gatewayParams['systemurl']) ? $gatewayParams['systemurl'] : '');
if ($systemUrl === '') {
    http_response_code(500);
    exit('WHMCS System URL is not configured.');
}

$returnUrl = $systemUrl . 'viewinvoice.php?id=' . rawurlencode($invoiceId);
$callbackUrl = $systemUrl . 'modules/gateways/callback/nowpayments.php';

$payload = [
    'price_amount' => $requestedAmount,
    'price_currency' => strtolower($currency),
    'order_id' => 'WHMCS-' . $invoiceId,
    'order_description' => 'WHMCS Invoice #' . $invoiceId,
    'ipn_callback_url' => $callbackUrl,
    'success_url' => $returnUrl,
    'cancel_url' => $returnUrl,
];

$response = nowpayments_api_request('POST', 'invoice', $apiKey, $payload);

if (!$response['ok'] || !is_array($response['data']) || empty($response['data']['invoice_url'])) {
    $logData = [
        'invoice_id' => $invoiceId,
        'http_code' => $response['http_code'],
        'response' => $response['data'] !== null ? $response['data'] : $response['body'],
        'curl_error' => $response['error'],
    ];
    logTransaction(isset($gatewayParams['name']) ? $gatewayParams['name'] : 'NOWPayments', $logData, 'Unable to create NOWPayments invoice');

    http_response_code(502);
    exit('Unable to start the crypto payment at the moment. Please return to the invoice and try again, or contact support.');
}

$invoiceUrl = (string) $response['data']['invoice_url'];
if (!preg_match('#^https://(?:www\.)?nowpayments\.io/#i', $invoiceUrl)) {
    logTransaction(isset($gatewayParams['name']) ? $gatewayParams['name'] : 'NOWPayments', ['invoice_id' => $invoiceId, 'invoice_url' => $invoiceUrl], 'Invalid NOWPayments invoice URL returned');
    http_response_code(502);
    exit('Invalid payment URL received from NOWPayments. Please contact support.');
}

header('Location: ' . $invoiceUrl, true, 303);
exit;
