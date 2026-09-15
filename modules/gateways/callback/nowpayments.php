<?php

require_once __DIR__ . '/../../../init.php';
App::load_function('gateway');
App::load_function('invoice');

require_once __DIR__ . '/../nowpayments/lib.php';

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);
$gatewayName = !empty($gatewayParams['name']) ? $gatewayParams['name'] : 'NOWPayments';

if (empty($gatewayParams['type'])) {
    http_response_code(503);
    exit('Module is not activated');
}

$ipnSecret = isset($gatewayParams['ipnSecret']) ? trim((string) $gatewayParams['ipnSecret']) : '';
if ($ipnSecret === '') {
    http_response_code(503);
    exit('IPN Secret not configured properly');
}

if (strtoupper(isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed');
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody) || $rawBody === '' || strlen($rawBody) > 1048576) {
    http_response_code(400);
    exit('Invalid request body');
}

$signature = isset($_SERVER['HTTP_X_NOWPAYMENTS_SIG']) ? (string) $_SERVER['HTTP_X_NOWPAYMENTS_SIG'] : '';

try {
    if (!nowpayments_verify_ipn_signature($rawBody, $signature, $ipnSecret)) {
        logTransaction($gatewayName, ['body' => $rawBody], 'NOWPayments IPN signature mismatch');
        http_response_code(400);
        exit('HMAC signature does not match');
    }
} catch (Throwable $e) {
    logTransaction($gatewayName, ['error' => $e->getMessage()], 'NOWPayments IPN canonicalization error');
    http_response_code(400);
    exit('Invalid IPN payload');
}

$requestData = json_decode($rawBody, true);
if (!is_array($requestData)) {
    http_response_code(400);
    exit('Invalid JSON');
}

$orderId = isset($requestData['order_id']) ? (string) $requestData['order_id'] : '';
if (!preg_match('/^WHMCS-([1-9][0-9]*)$/', $orderId, $matches)) {
    logTransaction($gatewayName, $requestData, 'NOWPayments IPN has invalid order_id');
    http_response_code(400);
    exit('Invalid order ID');
}

$invoiceId = checkCbInvoiceID((int) $matches[1], $gatewayName);
$status = strtolower(isset($requestData['payment_status']) ? (string) $requestData['payment_status'] : '');
$transactionId = isset($requestData['payment_id']) ? (string) $requestData['payment_id'] : '';
$priceAmount = isset($requestData['price_amount']) && is_numeric($requestData['price_amount']) ? (float) $requestData['price_amount'] : 0.0;
$priceCurrency = strtoupper(isset($requestData['price_currency']) ? (string) $requestData['price_currency'] : '');
$payAmount = isset($requestData['pay_amount']) ? (string) $requestData['pay_amount'] : '';
$payCurrency = strtoupper(isset($requestData['pay_currency']) ? (string) $requestData['pay_currency'] : '');

if ($status === '') {
    logTransaction($gatewayName, $requestData, 'NOWPayments IPN missing payment_status');
    http_response_code(400);
    exit('Missing payment status');
}

// Every valid status update is useful in the WHMCS Gateway Log.
logTransaction($gatewayName, $requestData, 'NOWPayments IPN: ' . $status);

if ($status === 'finished') {
    if ($transactionId === '' || $priceAmount <= 0) {
        http_response_code(400);
        exit('Invalid completed payment data');
    }

    $invoice = localAPI('GetInvoice', ['invoiceid' => (int) $invoiceId]);
    if (!is_array($invoice) || !isset($invoice['result']) || $invoice['result'] !== 'success') {
        http_response_code(404);
        exit('Invoice not found');
    }

    if (isset($invoice['status']) && strcasecmp((string) $invoice['status'], 'Paid') === 0) {
        // Recurrent IPNs are normal. If another payment already paid the invoice,
        // acknowledge the callback without creating an overpayment automatically.
        http_response_code(200);
        echo 'OK';
        exit;
    }

    $currentBalance = isset($invoice['balance']) ? (float) $invoice['balance'] : 0.0;
    if ($currentBalance > 0 && ($priceAmount + 0.009) < $currentBalance) {
        logTransaction(
            $gatewayName,
            $requestData,
            'Finished payment amount is below the current WHMCS invoice balance; payment was not applied automatically'
        );
        http_response_code(409);
        exit('Payment amount mismatch');
    }

    // Only check transaction duplication at the terminal paid state. Earlier
    // waiting/confirming callbacks use the same payment_id by design.
    checkCbTransID($transactionId);

    addInvoicePayment(
        $invoiceId,
        $transactionId,
        $priceAmount,
        0.00,
        $gatewayModuleName
    );

    $summary = 'Invoice ' . $invoiceId . ' paid via NOWPayments. '
        . 'Invoice amount: ' . $priceAmount . ' ' . $priceCurrency
        . ($payAmount !== '' ? '; crypto requested: ' . $payAmount . ' ' . $payCurrency : '');
    logTransaction($gatewayName, $requestData, $summary);
}

// Do not credit partial payments automatically. The same NOWPayments payment
// may later advance from partially_paid to finished using the same payment_id.
// Crediting it early would make the final callback look like a duplicate and can
// create incorrect WHMCS balances. All non-finished states are therefore logged
// and acknowledged, while WHMCS remains unpaid until NOWPayments says finished.

http_response_code(200);
echo 'OK';
