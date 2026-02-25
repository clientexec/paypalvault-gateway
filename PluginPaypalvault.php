<?php

require_once 'modules/admin/models/GatewayPlugin.php';
require_once 'modules/billing/models/class.gateway.plugin.php';
require_once 'modules/billing/models/Currency.php';

require_once 'api.php';

class PluginPaypalvault extends GatewayPlugin
{
    private $api;

    public function getVariables()
    {
        $variables = array(
            lang('Plugin Name') => array(
                'type'        => 'hidden',
                'description' => lang('How CE sees this plugin (not to be confused with the Signup Name)'),
                'value'       => lang('PayPal Vault')
            ),
            lang('Signup Name') => array(
                'type'        => 'text',
                'description' => lang('Select the name to display in the signup process for this payment type.'),
                'value'       => 'PayPal (Vault)'
            ),
            lang('Invoice After Signup') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want an invoice sent to the client after signup is complete.'),
                'value'       => '1'
            ),
            lang('Use PayPal Sandbox') => array(
                'type'        => 'yesno',
                'description' => lang('Select YES if you want to use PayPal\'s Sandbox environment.'),
                'value'       => '0'
            ),
            lang('Client ID') => array(
                'type'        => 'text',
                'description' => lang('Your PayPal REST App Client ID.'),
                'value'       => ''
            ),
            lang('Secret') => array(
                'type'        => 'text',
                'description' => lang('Your PayPal REST App Secret.'),
                'value'       => ''
            ),
            lang('Brand Name') => array(
                'type'        => 'text',
                'description' => lang('Optional. A brand name to display in the PayPal approval experience.'),
                'value'       => ''
            ),
            lang('CC Stored Outside') => array(
                'type'        => 'hidden',
                'description' => lang('If this plugin is Auto Payment, is Credit Card stored outside of Clientexec? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Billing Profile ID') => array(
                'type'        => 'hidden',
                'description' => lang('Is this plugin storing a Billing-Profile-ID? 1 = YES, 0 = NO'),
                'value'       => '1'
            ),
            lang('Auto Payment') => array(
                'type'        => 'hidden',
                'description' => lang('No description'),
                'value'       => '1'
            ),
            lang('Call on updateGatewayInformation') => array(
                'type'        => 'hidden',
                'description' => lang('Function name to be called in this plugin when given conditions are meet while updateGatewayInformation is invoked'),
                'value'       => serialize(
                    array(
                        'function' => 'createVaultSetupToken'
                    )
                )
            ),
            lang('Update Gateway') => array(
                'type'        => 'hidden',
                'description' => lang('1 = Create, update or remove Gateway client information through the function UpdateGateway when client choose to use this gateway, client profile is updated, client is deleted or client status is changed. 0 = Do nothing.'),
                'value'       => '1'
            )
        );

        return $variables;
    }

    public function singlepayment($params)
    {
        $vaultId = $this->getVaultIdForThisPlugin($params);

        if ($vaultId === null || $vaultId === '') {
            //If called from admin, ignore:
            //- Process Credit Card Payments
            //- Process Invoices
            //- Credit Card Payments Processor
            if (in_array($_REQUEST['action'], array('processinvoice', 'actoninvoice', 'executeservice'))) {
                return $this->user->lang('Billing Profile ID not available.');
            }

            $this->createVaultSetupToken($params);
            exit;
        }

        $this->api = new PayPalVaultAPI();
        $order = $this->api->createVaultedOrder($params, $vaultId);
        $this->api->processOrder($params, $order);
    }

    public function createVaultSetupToken($params)
    {
        $returnUrl = $this->getCallbackUrl($params);
        $cancelUrl = $params['invoiceviewURLCancel'];

        $this->api = new PayPalVaultAPI();
        $setup = $this->api->createVaultSetupToken($params, $returnUrl, $cancelUrl);

        if (!isset($setup['links']) || !is_array($setup['links'])) {
            throw new CE_Exception('Unable to create PayPal vault setup token.');
        }

        $approvalUrl = null;

        foreach ($setup['links'] as $link) {
            if (isset($link['rel']) && $link['rel'] === 'approve' && isset($link['href'])) {
                $approvalUrl = $link['href'];
                break;
            }
        }

        if ($approvalUrl === null) {
            throw new CE_Exception('Unable to locate PayPal approval URL for vaulting.');
        }

        if (isset($params['location']) && $params['location'] == 'updateGatewayInformation') {
            return array(
                'error'  => false,
                'action' => 'approval',
                'detail' => $approvalUrl,
            );
        } else {
            header("Location: " . $approvalUrl);
            exit;
        }
    }

    public function credit($params)
    {
        $this->api = new PayPalVaultAPI();

        $this->api->refundOrder($params);
        return [
            'AMOUNT' => $params['invoiceTotal']
        ];
    }

    private function getCallbackUrl($params)
    {
        $base = rtrim($params['clientExecURL'], '/') . '/';
        $pluginFolder = basename(dirname(__FILE__));
        $callbackURL = $base . 'plugins/gateways/' . $pluginFolder . '/callback.php';

        $additionalParams = '';

        if (isset($params['invoiceNumber'])) {
            $additionalParams .= '?invoiceId=' . urlencode($params['invoiceNumber']);
        }

        if (isset($params['CustomerID'])) {
            if ($additionalParams == '') {
                $additionalParams .= '?';
            } else {
                $additionalParams .= '&';
            }

            $additionalParams .= 'clientId=' . urlencode($params['CustomerID']);
        }

        return $callbackURL.$additionalParams;
    }

    private function getVaultIdForThisPlugin($params)
    {
        $user = new User($params['CustomerID']);

        $Billing_Profile_ID = '';
        if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $profile_id_array = @unserialize($Billing_Profile_ID);
            if (is_array($profile_id_array)) {
                $key = basename(dirname(__FILE__));
                if (isset($profile_id_array[$key])) {
                    $parts = explode('|', $profile_id_array[$key]);
                    if (isset($parts[0]) && $parts[0] != '') {
                        return $parts[0];
                    }
                }
            }
        }
        return null;
    }

    public function UpdateGateway($params)
    {
        switch ($params['Action']) {
            case 'update':
                $statusAliasGateway = StatusAliasGateway::getInstance($this->user);

                if (in_array($params['Status'], $statusAliasGateway->getUserStatusIdsFor(array(USER_STATUS_INACTIVE, USER_STATUS_CANCELLED, USER_STATUS_FRAUD)))) {
                    $this->removeCustomer($params);
                }

                break;
            case 'delete':
                $this->removeCustomer($params);
                break;
        }
    }

    public function removeCustomer($params)
    {
        require_once 'modules/clients/models/Client_EventLog.php';

        $profile_id = '';
        $Billing_Profile_ID = '';
        $profile_id_array = array();
        $user = new User($params['User ID']);

        if ($user->getCustomFieldsValue('Billing-Profile-ID', $Billing_Profile_ID) && $Billing_Profile_ID != '') {
            $profile_id_array = unserialize($Billing_Profile_ID);

            if (is_array($profile_id_array)) {
                if (isset($profile_id_array[basename(dirname(__FILE__))])) {
                    $profile_id = $profile_id_array[basename(dirname(__FILE__))];
                }
            }
        }

        $profile_id_values_array = explode('|', $profile_id);
        $profile_id = $profile_id_values_array[0];
        if (is_array($profile_id_array)) {
            unset($profile_id_array[basename(dirname(__FILE__))]);
        } else {
            $profile_id_array = array();
        }
        $user->updateCustomTag('Billing-Profile-ID', serialize($profile_id_array));
        $user->save();

        $eventLog = Client_EventLog::newInstance(false, $user->getId(), $user->getId());
        $eventLog->setSubject($this->user->getId());
        $eventLog->setAction(CLIENT_EVENTLOG_DELETEDBILLINGPROFILEID);
        $params = array(
            'paymenttype' => $this->settings->get("plugin_" . basename(dirname(__FILE__)) . "_Plugin Name"),
            'profile_id' => $profile_id
        );
        $eventLog->setParams(serialize($params));
        $eventLog->save();

        return array(
            'error'      => false,
            'profile_id' => $profile_id
        );
    }
}
