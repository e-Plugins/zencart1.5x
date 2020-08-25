<?php
/**
 * Digiwallet Payment Module for osCommerce
*
* @copyright Copyright 2013-2014 Yellow Melon
* @copyright Portions Copyright 2013 Paul Mathot
* @copyright Portions Copyright 2003 osCommerce
* @license   see LICENSE.TXT
*/
require_once "digiwallet/digiwallet.php";

$ywincludefile = realpath(dirname(__FILE__) . '/../../extra_datafiles/digiwallet.php');
require_once $ywincludefile;

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/../../languages/' . $langDir . '/modules/payment/digiwallet_ide.php');
require_once $ywincludefile;

class digiwallet_bw extends digiwalletBase
{
    protected $tpPaymentMethodId = 'BW';
    protected $tpPaymentMethodName = 'Overschrijvingen';

    /**
     * Contructor
     */
    public function digiwallet_bw()
    {
        parent::digiwallet();
    }

    /**
     * before process check status or prepare transaction
     * Press Confirm Order
     */
    public function before_process()
    {
        global  $order;
        if ($this->getConstant("MODULE_PAYMENT_DIGIWALLET{$this->payType}REPAIR_ORDER") === true) {
            global $order;
            // when repairing iDeal the transaction status is succes, set order status accordingly
            $order->info['order_status'] = constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ORDER_STATUS_ID");
            return false;
        }
        if (isset($_GET['dw_action']) && $_GET['dw_action'] == "process") {
            $this->checkStatus();
        } else {
            $this->prepareTransaction();
        }
    }
    /**
     * Prepare transaction for AFP method
     *
     * {@inheritDoc}
     * @see digiwalletBase::prepareTransaction()
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $messageStack, $order_totals, $zco_notifier;

        $cc_purchaseID = time();
        $cc_amount = round($order->info['total'] * 100, 0);
        $cc_entranceCode = zen_session_id();
        if ((strtolower($this->transactionDescription) == 'automatic') && (count($order->products) == 1)) {
            $product = $order->products[0];
            $cc_description = $product['name'];
        } else {
            $cc_description = 'Order: ' . $this->transactionDescriptionText;
        }
        $cc_description = trim(strip_tags($cc_description));
        $cc_description = preg_replace("/[^a-zA-Z0-9\s]/", '', $cc_description);
        $cc_description = substr($cc_description, 0, 31); /* Max. 32 characters */
        if (empty($cc_description)) {
            $cc_description = 'nvt';
        }
        $testMode = false;
        /*
        if (constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}TESTACCOUNT") == "True") { // Always OK
            $testMode = true;
        }
        */
        // Starting transaction
        $objDigiCore = new DigiwalletCore($this->tpPaymentMethodId, $this->rtlo, $this->language, $testMode);

        $objDigiCore->setAmount($cc_amount);
        $objDigiCore->setDescription($cc_description);

        $objDigiCore->setReportUrl($this->reportURL . '&method=%payMethod%');
        $objDigiCore->setReturnUrl($this->returnURL . '&method=%payMethod%');

        $objDigiCore->setBankId($this->bankID);
        $objDigiCore->setCountryId($this->countryID);

        $objDigiCore->bindParam('email', $order->customer['email_address']);
        $objDigiCore->bindParam('userip', $_SERVER["REMOTE_ADDR"]);

        // Consumer's email address
        $consumerEmail = isset($order->customer['email_address']) ? $order->customer['email_address'] : "";
        if(!empty($consumerEmail)) {
            $objDigiCore->bindParam("email", $consumerEmail);
        }

        $result = @$objDigiCore->startPayment();

        if ($result === false) {
            $messageStack->add_session('checkout_payment', constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode(constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING")) . " " . $objDigiCore->getErrorMessage()), 'SSL', true, false);
        }

        $this->transactionID = $objDigiCore->getTransactionId();

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
            '" . zen_db_input($objDigiCore->getMoreInformation()) . "'
            );"
            );
        /*
         // Clear cart and return to check result
         $order->info['order_status'] = constant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID_OPEN');
         $order->info['comments'] = 'Digiwallet payment: inprogress';

         $order->info['payment_method'] = constant("MODULE_PAYMENT_DIGIWALLET" . $this->payType . "TEXT_TITLE");
         $order->info['payment_module_code'] = $_SESSION['payment'];

         $order_total_modules = new order_total;

         if (strpos($GLOBALS[$_SESSION['payment']]->code, 'paypal') !== 0) {
         $order_totals = $order_total_modules->pre_confirmation_check();
         }
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_BEFORE_ORDER_TOTALS_PROCESS');
         $order_totals = $order_total_modules->process();
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_TOTALS_PROCESS');
         // create the order record
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_PAYMENT_MODULES_BEFOREPROCESS');
         $order_id = $order->create($order_totals);
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE');
         // decrease stock
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_PAYMENT_MODULES_AFTER_ORDER_CREATE');
         $order->create_add_products($order_id);
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS');

         // set history status
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_SEND_ORDER_EMAIL');
         if (isset($_SESSION['payment_attempt'])) unset($_SESSION['payment_attempt']);
         $db->Execute("update " . TABLE_ORDERS . " set orders_status = '"
         . constant('MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID_OPEN')
         . "', last_modified = now() where orders_id = '" . (int) $order_id . "'"
         );
         $db->Execute("update " . TABLE_DIGIWALLET_TRANSACTIONS . " set order_id = '". $order_id. "' where transaction_id = '" . $this->transactionID . "'");
         $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_HANDLE_AFFILIATES');
         */
        // Clear cart
        unset($_SESSION['order_summary']);
        unset($_SESSION['order_number_created']);
        $_SESSION['cart']->reset(true);
        // Process return message
        zen_redirect(zen_href_link('bankwire_success', '', 'SSL') . "&status=success&trxid=" . $this->transactionID);
        exit(0);
    }
}
