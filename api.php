<?php

require_once 'modules/billing/models/class.gateway.plugin.php';

class PayPalVaultAPI
{
    private $settings;

    public function __construct()
    {
        $this->settings = new CE_Settings();
    }

    public function processOrder($params, $order)
    {
        if (!isset($order['id'])) {
            throw new CE_Exception('Unable to create PayPal order for vaulted charge.');
        }

        $status = isset($order['status']) ? $order['status'] : null;

        if (isset($order['purchase_units'][0]['payments']['captures'][0]['id'])) {
            $captureId = $order['purchase_units'][0]['payments']['captures'][0]['id'];
            $status = $order['purchase_units'][0]['payments']['captures'][0]['status'];
        }

        if ($captureId === null) {
            // Fallback to order id as transaction id (better than nothing)
            $captureId = $order['id'];
        }

        if ($status !== null && strtoupper($status) !== 'COMPLETED') {
            // PayPal can return PENDING in some scenarios; treat as failure for gateway.
            throw new CE_Exception('PayPal charge did not complete. Status: ' . $status);
        }

        $amount = sprintf("%01.2f", round($params['invoiceTotal'], 2));
        $cPlugin = new Plugin($params['invoiceNumber'], 'paypalvault', $this->user);
        $cPlugin->setAmount($amount);
        $cPlugin->m_TransactionID = $captureId;
        $cPlugin->m_Action = "charge";
        $cPlugin->m_Last4 = "NA";
        $transaction = "PayPal Vault payment of $amount was accepted. Original Signup Invoice: {$params['invoiceNumber']} (OrderID: " . $captureId . ")";
        $cPlugin->PaymentAccepted($amount, $transaction, $captureId);
    }
    /**
     * Create a Setup Token for PayPal Vault.
     */
    public function createVaultSetupToken($params, $returnUrl, $cancelUrl)
    {
        $accessToken = $this->getAnAccessToken();

        $brandName = $this->settings->get('plugin_paypalvault_Brand Name');
        if ($brandName == '') {
            $brandName = $this->settings->get('Company Name');
        }



        $payload = array(
            'customer' => [
                'id' => $params['CustomerID']
            ],
            'payment_source' => array(
                'paypal' => array(
                    'description' => "{$brandName} Billing Authorization",
                    'permit_multiple_payment_tokens' => false,
                    'usage_pattern' => 'DEFERRED',
                    'usage_type' => 'MERCHANT',
                    'customer_type' => 'CONSUMER',
                    'experience_context' => array(
                        'shipping_preference' => 'NO_SHIPPING',
                        'payment_method_preference' => 'IMMEDIATE_PAYMENT_REQUIRED',
                        'brand_name' => $brandName,
                        'return_url' => $returnUrl,
                        'cancel_url' => $cancelUrl
                    )
                )
            )
        );

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('ce_', true)
        );

        return $this->makeApiRequest('/v3/vault/setup-tokens', $headers, json_encode($payload), 'POST');
    }

    /**
     * Exchange a Setup Token for a Payment Token (vault id).
     */
    public function exchangeSetupTokenForPaymentToken($setupTokenId)
    {
        $accessToken = $this->getAnAccessToken();

        $payload = array(
            'payment_source' => array(
                'token' => array(
                    'id' => $setupTokenId,
                    'type' => 'SETUP_TOKEN'
                )
            )
        );

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('ce_', true)
        );

        return $this->makeApiRequest('/v3/vault/payment-tokens', $headers, json_encode($payload), 'POST');
    }

    /**
     * Create an order using a vaulted PayPal token.
     */
    public function createVaultedOrder($params, $vaultId)
    {
        $currency = $params['userCurrency'];
        if ($currency == '') {
            $currency = $params['currency'];
        }
        if ($currency == '') {
            $currency = 'USD';
        }

        $amount = sprintf("%01.2f", round($params['invoiceTotal'], 2));
        CE_Lib::log(4, 'PayPalVault createVaultedOrder using vault_id=' . $vaultId . ' invoice=' . $params['invoiceNumber'] . ' amount=' . $amount . ' ' . $currency);

        $accessToken = $this->getAnAccessToken();

        $payload = array(
            'intent' => 'CAPTURE',
            'purchase_units' => array(
                array(
                    'reference_id' => (string)$params['invoiceNumber'],
                    'amount' => array(
                        'currency_code' => $currency,
                        'value' => (string)$amount
                    )
                )
            ),
            'payment_source' => array(
                'paypal' => array(
                    'vault_id' => $vaultId
                )
            )
        );

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('ce_', true)
        );

        return $this->makeApiRequest('/v2/checkout/orders', $headers, json_encode($payload), 'POST');
    }

    /**
     * Capture an order.
     */
    public function captureOrder($params, $orderId)
    {
        $accessToken = $this->getAnAccessToken();

        $headers = array(
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('ce_', true)
        );

        return $this->makeApiRequest('/v2/checkout/orders/' . urlencode($orderId) . '/capture', $headers, false, 'POST');
    }

    /**
     * Refund a PayPal capture (full or partial).
     *
     * @param array $params
     *   Expected keys:
     *     - invoiceRefundTransactionId (preferred)
     *     - transid (fallback)
     *     - refundAmount (optional)
     *     - amount (fallback)
     *     - currency (optional, defaults to USD)
     *
     * @return array
     */
    public function refundOrder($params)
    {
        $captureId = $params['invoiceRefundTransactionId'] ?? null;
        if ($captureId === null || $captureId === '') {
            throw new CE_Exception('Missing PayPal capture id for refund.');
        }

        $currency = $params['currency'] ?? 'USD';
        $amount = $params['amount'];

        $payload = false;
        if ($amount !== null && $amount !== '' && is_numeric($amount)) {
            // PayPal requires correctly formatted decimals
            $formattedAmount = number_format((float)$amount, 2, '.', '');

            $payload = json_encode([
                'amount' => [
                    'value' => $formattedAmount,
                    'currency_code' => $currency
                ]
            ]);
        }

        $accessToken = $this->getAnAccessToken();
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $accessToken,
            'PayPal-Request-Id: ' . uniqid('ce_refund_', true)
        ];

        $response = $this->makeApiRequest(
            '/v2/payments/captures/' . urlencode($captureId) . '/refund',
            $headers,
            $payload,
            'POST'
        );

        if (!isset($response['status'])) {
            throw new CE_Exception('Unexpected PayPal refund response.');
        }

        if (strtoupper($response['status']) !== 'COMPLETED') {
            throw new CE_Exception(
                'PayPal refund not completed. Status: ' . $response['status']
            );
        }

        return [
            'STATUS' => 'COMPLETED',
            'REFUND_ID' => $response['id'] ?? null,
            'AMOUNT' => $amount
        ];
    }



    /**
     * OAuth token (Client Credentials) - uses /v1/oauth2/token.
     */
    public function getAnAccessToken()
    {
        $sandbox = ($this->settings->get('plugin_paypalvault_Use PayPal Sandbox') == '1') ? 'sandbox.' : '';
        $url = 'https://api-m.' . $sandbox . 'paypal.com/v1/oauth2/token';

        $clientId = $this->settings->get('plugin_paypalvault_Client ID');
        $secret = $this->settings->get('plugin_paypalvault_Secret');

        $header = array(
            'Content-Type: application/x-www-form-urlencoded'
        );

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $header);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $clientId . ':' . $secret);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, 'grant_type=client_credentials');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (!$response) {
            throw new CE_Exception('cURL Paypal Error: ' . curl_error($ch) . ' (' . curl_errno($ch) . ')');
        }

        $response = json_decode($response, true);
        curl_close($ch);

        if (!isset($response['access_token'])) {
            CE_Lib::log(4, 'PayPal OAuth response: ' . print_r($response, true));
            throw new CE_Exception('Unable to obtain PayPal access token.');
        }

        return $response['access_token'];
    }

    /**
     * Generic PayPal API request helper supporting v2/v3 endpoints.
     *
     * $path should start with "/v2/..." or "/v3/..." etc.
     */
    public function makeApiRequest($path, $headers, $data = false, $method = 'POST', $acceptHTTPcode = false)
    {
        $sandbox = ($this->settings->get('plugin_paypalvault_Use PayPal Sandbox') == '1') ? 'sandbox.' : '';
        $base = 'https://api-m.' . $sandbox . 'paypal.com';
        $url = $base . $path;

        CE_Lib::log(4, 'Making PayPalVault request to: ' . $url);

        $ch = curl_init($url);

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, 1);
                break;
            case 'PATCH':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                break;
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, 1);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                break;
        }

        if ($data !== false) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

        // Turn off the server and peer verification (TrustManager Concept).
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($raw === false) {
            CE_Lib::log(4, 'PayPalVault Response HTTP Code: ' . print_r($httpCode, true));
            if (!$acceptHTTPcode || !in_array($httpCode, $acceptHTTPcode)) {
                throw new CE_Exception('cURL Paypal Error: ' . curl_error($ch) . ' (' . curl_errno($ch) . ')');
            }
            curl_close($ch);
            return array();
        }

        $decoded = json_decode($raw, true);
        CE_Lib::log(4, 'PayPalVault Response HTTP Code: ' . print_r($httpCode, true));
        CE_Lib::log(4, 'PayPalVault Response: ' . print_r($decoded, true));

        // Basic error handling
        if ($httpCode >= 400) {
            $msg = 'PayPal API error';
            if (is_array($decoded)) {
                if (isset($decoded['message'])) {
                    $msg = $decoded['message'];
                } elseif (isset($decoded['name'])) {
                    $msg = $decoded['name'];
                }
                if (isset($decoded['details'][0]['description'])) {
                    $msg .= ' - ' . $decoded['details'][0]['description'];
                }
            }
            curl_close($ch);
            throw new CE_Exception($msg);
        }

        curl_close($ch);
        return is_array($decoded) ? $decoded : array();
    }
}
