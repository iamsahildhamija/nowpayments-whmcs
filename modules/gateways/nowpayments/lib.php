<?php

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/** HTML escaping helper. */
function nowpayments_e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Ensure WHMCS system URL ends in exactly one slash. */
function nowpayments_normalize_system_url($url)
{
    $url = trim((string) $url);
    if ($url === '') {
        return '';
    }

    return rtrim($url, '/') . '/';
}

/**
 * Sign the short-lived checkout payload that is rendered into the invoice page.
 * This prevents a customer from changing the WHMCS invoice id/amount/currency
 * before the server-side NOWPayments invoice is created.
 */
function nowpayments_checkout_token($invoiceId, $amount, $currency, $ipnSecret)
{
    $payload = (string) $invoiceId . '|' . (string) $amount . '|' . strtoupper((string) $currency);
    return hash_hmac('sha256', $payload, trim((string) $ipnSecret));
}

/** Constant-time validation of the checkout token. */
function nowpayments_checkout_token_is_valid($invoiceId, $amount, $currency, $token, $ipnSecret)
{
    $expected = nowpayments_checkout_token($invoiceId, $amount, $currency, $ipnSecret);
    return is_string($token) && $token !== '' && hash_equals($expected, $token);
}

/**
 * Make an authenticated request to the NOWPayments API.
 *
 * @return array{ok:bool,http_code:int,data:array|null,body:string,error:string}
 */
function nowpayments_api_request($method, $endpoint, $apiKey, $payload = null)
{
    $url = 'https://api.nowpayments.io/v1/' . ltrim((string) $endpoint, '/');
    $ch = curl_init($url);

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'x-api-key: ' . trim((string) $apiKey),
    ];

    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_USERAGENT, 'WHMCS-NOWPayments/2.0');

    $method = strtoupper((string) $method);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            curl_close($ch);
            return [
                'ok' => false,
                'http_code' => 0,
                'data' => null,
                'body' => '',
                'error' => 'Unable to encode API request.',
            ];
        }
        curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
    } elseif ($method !== 'GET') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }

    $body = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        $body = '';
    }

    $data = null;
    if ($body !== '') {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $data = $decoded;
        }
    }

    $ok = $curlError === '' && $httpCode >= 200 && $httpCode < 300;

    return [
        'ok' => $ok,
        'http_code' => $httpCode,
        'data' => $data,
        'body' => (string) $body,
        'error' => (string) $curlError,
    ];
}

/**
 * Minimal JSON AST classes used only for exact NOWPayments IPN canonicalisation.
 * Numeric tokens are deliberately preserved as raw JSON text so PHP cannot
 * rewrite values such as 0.000016 into 1.6E-5 before HMAC verification.
 */
class NowPaymentsWhmcsJsonNumber
{
    public $raw;

    public function __construct($raw)
    {
        $this->raw = $raw;
    }
}

class NowPaymentsWhmcsJsonObject
{
    public $items = [];
}

class NowPaymentsWhmcsJsonArray
{
    public $items = [];
}

class NowPaymentsWhmcsJsonParser
{
    private $json;
    private $length;
    private $position = 0;

    public function __construct($json)
    {
        $this->json = (string) $json;
        $this->length = strlen($this->json);
    }

    public function parse()
    {
        $this->skipWhitespace();
        $value = $this->parseValue();
        $this->skipWhitespace();

        if ($this->position !== $this->length) {
            throw new RuntimeException('Unexpected trailing JSON data.');
        }

        return $value;
    }

    private function parseValue()
    {
        if ($this->position >= $this->length) {
            throw new RuntimeException('Unexpected end of JSON input.');
        }

        $char = $this->json[$this->position];

        if ($char === '{') {
            return $this->parseObject();
        }
        if ($char === '[') {
            return $this->parseArray();
        }
        if ($char === '"') {
            return $this->parseString();
        }
        if ($char === 't' && substr($this->json, $this->position, 4) === 'true') {
            $this->position += 4;
            return true;
        }
        if ($char === 'f' && substr($this->json, $this->position, 5) === 'false') {
            $this->position += 5;
            return false;
        }
        if ($char === 'n' && substr($this->json, $this->position, 4) === 'null') {
            $this->position += 4;
            return null;
        }
        if ($char === '-' || ($char >= '0' && $char <= '9')) {
            return $this->parseNumber();
        }

        throw new RuntimeException('Invalid JSON token.');
    }

    private function parseObject()
    {
        $object = new NowPaymentsWhmcsJsonObject();
        $this->position++; // {
        $this->skipWhitespace();

        if ($this->peek('}')) {
            $this->position++;
            return $object;
        }

        while (true) {
            $this->skipWhitespace();
            if (!$this->peek('"')) {
                throw new RuntimeException('JSON object key must be a string.');
            }

            $key = $this->parseString();
            $this->skipWhitespace();
            if (!$this->peek(':')) {
                throw new RuntimeException('Expected colon after JSON object key.');
            }
            $this->position++;
            $this->skipWhitespace();
            $object->items[$key] = $this->parseValue();
            $this->skipWhitespace();

            if ($this->peek('}')) {
                $this->position++;
                break;
            }
            if (!$this->peek(',')) {
                throw new RuntimeException('Expected comma in JSON object.');
            }
            $this->position++;
        }

        return $object;
    }

    private function parseArray()
    {
        $array = new NowPaymentsWhmcsJsonArray();
        $this->position++; // [
        $this->skipWhitespace();

        if ($this->peek(']')) {
            $this->position++;
            return $array;
        }

        while (true) {
            $this->skipWhitespace();
            $array->items[] = $this->parseValue();
            $this->skipWhitespace();

            if ($this->peek(']')) {
                $this->position++;
                break;
            }
            if (!$this->peek(',')) {
                throw new RuntimeException('Expected comma in JSON array.');
            }
            $this->position++;
        }

        return $array;
    }

    private function parseString()
    {
        $start = $this->position;
        $this->position++; // opening quote
        $escaped = false;

        while ($this->position < $this->length) {
            $char = $this->json[$this->position];

            if ($escaped) {
                $escaped = false;
                $this->position++;
                continue;
            }

            if ($char === '\\') {
                $escaped = true;
                $this->position++;
                continue;
            }

            if ($char === '"') {
                $this->position++;
                $token = substr($this->json, $start, $this->position - $start);
                $decoded = json_decode($token, true);
                if (!is_string($decoded)) {
                    throw new RuntimeException('Invalid JSON string.');
                }
                return $decoded;
            }

            $this->position++;
        }

        throw new RuntimeException('Unterminated JSON string.');
    }

    private function parseNumber()
    {
        $remaining = substr($this->json, $this->position);
        if (!preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:[eE][+\-]?[0-9]+)?/', $remaining, $matches)) {
            throw new RuntimeException('Invalid JSON number.');
        }

        $raw = $matches[0];
        $this->position += strlen($raw);
        return new NowPaymentsWhmcsJsonNumber($raw);
    }

    private function skipWhitespace()
    {
        while ($this->position < $this->length) {
            $char = $this->json[$this->position];
            if ($char === ' ' || $char === "\t" || $char === "\r" || $char === "\n") {
                $this->position++;
                continue;
            }
            break;
        }
    }

    private function peek($char)
    {
        return $this->position < $this->length && $this->json[$this->position] === $char;
    }
}

/** Encode a string in the same compact style used by JSON.stringify. */
function nowpayments_json_encode_string($value)
{
    $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
    if (defined('JSON_UNESCAPED_LINE_TERMINATORS')) {
        $flags |= JSON_UNESCAPED_LINE_TERMINATORS;
    }

    $encoded = json_encode((string) $value, $flags);
    if ($encoded === false) {
        throw new RuntimeException('Unable to encode JSON string.');
    }
    return $encoded;
}

/** Recursively serialise the IPN body with object keys sorted alphabetically. */
function nowpayments_json_canonical_serialize($value)
{
    if ($value instanceof NowPaymentsWhmcsJsonNumber) {
        return $value->raw;
    }

    if ($value instanceof NowPaymentsWhmcsJsonObject) {
        $items = $value->items;
        ksort($items, SORT_STRING);
        $parts = [];
        foreach ($items as $key => $itemValue) {
            $parts[] = nowpayments_json_encode_string($key) . ':' . nowpayments_json_canonical_serialize($itemValue);
        }
        return '{' . implode(',', $parts) . '}';
    }

    if ($value instanceof NowPaymentsWhmcsJsonArray) {
        $parts = [];
        foreach ($value->items as $itemValue) {
            $parts[] = nowpayments_json_canonical_serialize($itemValue);
        }
        return '[' . implode(',', $parts) . ']';
    }

    if (is_string($value)) {
        return nowpayments_json_encode_string($value);
    }
    if ($value === true) {
        return 'true';
    }
    if ($value === false) {
        return 'false';
    }
    if ($value === null) {
        return 'null';
    }

    throw new RuntimeException('Unsupported JSON value.');
}

/**
 * Canonicalize a raw NOWPayments IPN JSON body for HMAC verification.
 * This follows NOWPayments' documented deep-key sorting while preserving the
 * exact numeric representation from the original request body.
 */
function nowpayments_canonicalize_ipn_json($rawJson)
{
    $parser = new NowPaymentsWhmcsJsonParser($rawJson);
    return nowpayments_json_canonical_serialize($parser->parse());
}

/** Validate NOWPayments X-NOWPAYMENTS-SIG. */
function nowpayments_verify_ipn_signature($rawJson, $receivedSignature, $ipnSecret)
{
    $receivedSignature = strtolower(trim((string) $receivedSignature));
    if ($receivedSignature === '' || !preg_match('/^[a-f0-9]{128}$/', $receivedSignature)) {
        return false;
    }

    $canonical = nowpayments_canonicalize_ipn_json($rawJson);
    $expected = hash_hmac('sha512', $canonical, trim((string) $ipnSecret));

    return hash_equals($expected, $receivedSignature);
}
