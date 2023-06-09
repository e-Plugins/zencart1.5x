<?php
/**
 * Digiwallet Payment Module for ZenCart
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license see LICENSE.TXT
 */

require_once 'digiwallet.php';
require_once 'client/ClientCore.php';

class digiwalletClient extends digiwalletBase
{
    /**
     *
     * @method digiwallet inits the module
     */
    public function digiwallet()
    {
        $this->code = 'digiwallet_' . $this->order_prefix[$this->tpPaymentMethodId] . "_" . strtolower($this->tpPaymentMethodId);

        $availableLanguages = array("dutch" => "nl", "english" => "en");
        $this->language = (isset($_SESSION["language"]) && in_array($_SESSION["language"], array_keys($availableLanguages))) ? $availableLanguages[$_SESSION["language"]] : "nl";

        $this->payType = (empty($this->tpMethods[$this->tpPaymentMethodId])) ? "_" : "_" . $this->tpMethods[$this->tpPaymentMethodId] . "_";

        $this->title = $this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'TEXT_TITLE');

        $this->description = $this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'TEXT_DESCRIPTION') . $this->getConstant("MODULE_PAYMENT_DIGIWALLET_TESTMODE_WARNING_MESSAGE");
        $this->sort_order = $this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'SORT_ORDER');

        $this->enabled = (($this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'STATUS') == 'True') ? true : false);

        $this->rtlo = $this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'DIGIWALLET_RTLO');

        $this->transactionDescription = $this->getConstant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'TRANSACTION_DESCRIPTION');
        $this->transactionDescriptionText = $this->getConstant("MODULE_PAYMENT_DIGIWALLET" . $this->payType . "MERCHANT_TRANSACTION_DESCRIPTION_TEXT");

        $this->reportURL = zen_href_link('digiwallet_client_callback.php?dw_action=callback', '', 'SSL', false, false, true);
        $this->returnURL = zen_href_link('digiwallet_client_callback.php?dw_action=process', '', 'SSL', false, false, true);
        $this->cancelURL = zen_href_link('digiwallet_client_callback.php?dw_action=cancel', '', 'SSL', false, false, true);
        //$this->cancelURL = zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL');
    }

    /**
     * Start the transaction
     * @param int $cc_amount
     * @param string $cc_description
     * @param int $cc_purchaseID
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    protected function startTransaction($cc_amount, $cc_description, $cc_purchaseID, $objDigiCore = null)
    {
        global $db, $messageStack, $order;

        $objDigiCore = new \Digiwallet\ClientCore($this->rtlo, $this->tpPaymentMethodId, $this->language);

        // Consumer's email address
        $consumerEmail = isset($order->customer['email_address']) ? $order->customer['email_address'] : "";
        $formData = array(
            'amount' => $cc_amount,
            'inputAmount' => $cc_amount,
            'consumerEmail' => $consumerEmail,
            'description' => $cc_description,
            'returnUrl' => $this->returnURL . '&method=' . $this->tpPaymentMethodId,
            'reportUrl' => $this->reportURL . '&method=' . $this->tpPaymentMethodId,
            'cancelUrl' => $this->cancelURL . '&method=' . $this->tpPaymentMethodId,
            'test' => 0
        );

        $apiToken = constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}DIGIWALLET_API_TOKEN");

        /** @var \Digiwallet\Packages\Transaction\Client\Response\CreateTransactionInterface $clientResult */
        $clientResult = $objDigiCore->createTransaction($apiToken, $formData);

        if (empty($clientResult)) {
            $messageStack->add_session(FILENAME_CHECKOUT_PAYMENT, constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT), 'SSL', true, false);
        }

        $this->transactionID = $clientResult->transactionId();
        $this->bankUrl = $clientResult->launchUrl();

        if (constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}EMAIL_ORDER_INIT") == 'True') {
            $email_text = 'Er is zojuist een Digiwallet iDeal bestelling opgestart' . "\n\n";
            $email_text .= 'Details:' . "\n";
            $email_text .= 'customer_id: ' . $_SESSION['customer_id'] . "\n";
            $email_text .= 'customer_first_name: ' . $_SESSION['customer_first_name'] . "\n";
            $email_text .= 'Digiwallet transaction_id: ' . $this->transactionID . "\n";
            $email_text .= 'bedrag: ' . $cc_amount . ' (' . $this->acceptedCurrency . 'x100)' . "\n";
            $max_orders_id = $db->Execute("select max(orders_id) orders_id from " . TABLE_ORDERS);
            $new_order_id = $max_orders_id->fields['orders_id'] + 1;
            $email_text .= 'order_id: ' . $new_order_id . ' (verwacht indien de bestelling wordt voltooid, kan ook hoger zijn)' . "\n";
            $email_text .= "\n\n";
            $email_text .= 'Digiwallet transactions lookup: ' . HTTP_SERVER_DIGIWALLET_ADMIN . FILENAME_DIGIWALLET_TRANSACTIONS . '?action=lookup&transactionID=' . $this->transactionID . "\n";

            zen_mail('', STORE_OWNER_EMAIL_ADDRESS, '[iDeal bestelling opgestart] #' . $new_order_id . ' (?)', $email_text, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
        }

        $db->Execute("INSERT INTO " . TABLE_DIGIWALLET_TRANSACTIONS . "
            (
            `transaction_id`,
            `rtlo`,
            `purchase_id`,
            `issuer_id`,
            `transaction_status`,
            `datetimestamp`,
            `customer_id`,
            `amount`,
            `currency`,
            `session_id`,
            `ideal_session_data`,
            `more`
            ) VALUES (
            '" . $this->transactionID . "',
            '" . $this->rtlo . "',
            '" . $cc_purchaseID . "',
            '" . $this->tpPaymentMethodId . "',
            'open',
            NOW( ),
            '" . $_SESSION['customer_id'] . "',
            '" . $cc_amount . "',
            '" . $this->acceptedCurrency . "',
            '" . zen_db_input(zen_session_id()) . "',
            '" . base64_encode(serialize($_SESSION)) . "',
            '" . zen_db_input(is_array($clientResult->response()) ? json_encode($clientResult->response()) : $clientResult->response()) . "'
            );");

        zen_redirect(html_entity_decode($this->bankUrl));
    }

    /**
     * Implement refund method
     */
    public function _doRefund($oID)
    {
        global $db, $messageStack;
        $messageStack->add_session('This function is not supported yet!', 'error');
    }
}