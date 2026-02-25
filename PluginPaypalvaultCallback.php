<?php

require_once 'api.php';

class PluginPaypalvaultCallback extends PluginCallback
{
    private $api;

    public function processCallback()
    {
        $this->api = new PayPalVaultAPI();

        CE_Lib::log(4, 'Paypal callback invoked');
        $approvalTokenId = $_REQUEST['approval_token_id'];
        $approvalSessionId = $_REQUEST['approval_session_id'];

        $return = $this->handleVaultReturn();

        $clientExecURL = CE_Lib::getSoftwareURL();

        if(isset($_REQUEST['invoiceId'])) {
            $invoiceId = $_REQUEST['invoiceId'];

            $invoice = new Invoice($invoiceId);
            $user = new User($invoice->getCustomerId());

            $params = [
                'userCurrency' => $invoice->getCurrency(),
                'invoiceTotal' => $invoice->getBalanceDue(),
                'invoiceNumber' => $invoiceId
            ];

            $order = $this->api->createVaultedOrder($params, $return['tokenId']);
            $this->api->processOrder($params, $order);

            $invoiceviewURLSuccess = $clientExecURL . "/index.php?fuse=billing&paid=1&controller=invoice&view=invoice&id=" . $invoiceId;

            //Need to check to see if user is coming from signup
            if (isset($_REQUEST['isSignup']) && $_REQUEST['isSignup'] == 1) {
                if ($this->settings->get('Signup Completion URL') != '') {
                    $return_url = $this->settings->get('Signup Completion URL') . '?success=1';
                } else {
                    $return_url = $clientExecURL . "/order.php?step=complete&pass=1";
                }
            } else {
                $return_url = $invoiceviewURLSuccess;
            }
        } else {
            $return_url = $clientExecURL . "/index.php?fuse=clients&controller=userprofile&view=paymentmethod";
        }

        header('Location: ' . $return_url);
        exit;
    }

     /**
     * Callback entrypoint.
     *
     * Expected query parameters:
     *  - token: Setup Token id (PayPal returns this on success)
     *  - invoiceNumber
     *
     * The callback script should instantiate this plugin and call this method, then
     * redirect the user back to the invoice/thank-you page as appropriate.
     */
    private function handleVaultReturn()
    {
        $setupToken = isset($_GET['approval_token_id']) ? $_GET['approval_token_id'] : null;
        if ($setupToken === null || $setupToken === '') {
            throw new CE_Exception('Missing PayPal setup token in return.');
        }

        $paymentToken = $this->api->exchangeSetupTokenForPaymentToken($setupToken);

        if (!isset($paymentToken['id']) || $paymentToken['id'] === '') {
            throw new CE_Exception('Unable to exchange setup token for PayPal payment token.');
        }

        if ($_REQUEST['clientId'] === null) {
            throw new CE_Exception('Unable to find client number');
        }

        $this->setVaultIdForThisPlugin($_REQUEST['clientId'], $paymentToken['id']);

        return [
            'tokenId' => $paymentToken['id'],
        ];
    }

    /**
     * Store the vault id (Payment Token) to Billing-Profile-ID for this plugin folder key.
     */
    private function setVaultIdForThisPlugin($clientId, $profile_id)
    {
        $user = new User($clientId);

        $Billing_Profile_ID = '';
        $profile_id_array = array();
        if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $existing = @unserialize($Billing_Profile_ID);
            if (is_array($existing)) {
                $profile_id_array = $existing;
            }
        }


        $profile_id_array[basename(dirname(__FILE__))] = $profile_id;
        $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
        $user->save();
    }
}
