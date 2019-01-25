<?php
/**
 * Digiwallet Payment Module for osCommerce
*
* @copyright Copyright 2013-2014 Yellow Melon
* @copyright Portions Copyright 2013 Paul Mathot
* @copyright Portions Copyright 2003 osCommerce
* @license   see LICENSE.TXT
*/
require_once "targetpay/targetpay.php";

$ywincludefile = realpath(dirname(__FILE__) . '/../../extra_datafiles/targetpay.php');
require_once $ywincludefile;

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/../../languages/' . $langDir . '/modules/payment/targetpay_ide.php');
require_once $ywincludefile;

class targetpay_afp extends targetpayBase
{
    protected $tpPaymentMethodId = 'AFP';
    protected $tpPaymentMethodName = 'AfterPay';
    /**
     * Tax applying percent
     * @var array
     */
    protected $array_tax = [
        1 => 21,
        2 => 6,
        3 => 0,
        4 => 'none'
    ];
    /***
     * Get product tax by Digiwallet
     * @param unknown $val
     * @return number
     */
    private function getTax($val)
    {
        if(empty($val)) return 4; // No tax
        else if($val >= 21) return 1;
        else if($val >= 6) return 2;
        else return 3;
    }
    /**
     * Contructor
     */
    public function targetpay_afp()
    {
        parent::targetpay();
    }

    /**
     * Format phonenumber by NL/BE
     *
     * @param unknown $country
     * @param unknown $phone
     * @return unknown
     */
    private static function format_phone($country, $phone) {
        $function = 'format_phone_' . strtolower($country);
        if(method_exists('targetpay_afp', $function)) {
            return self::$function($phone);
        }
        else {
            echo "unknown phone formatter for country: ". $function;
            exit;
        }
        return $phone;
    }
    /**
     * Format phone number
     *
     * @param unknown $phone
     * @return string|mixed
     */
    private static function format_phone_nld($phone) {
        // note: making sure we have something
        if(!isset($phone{3})) { return ''; }
        // note: strip out everything but numbers
        $phone = preg_replace("/[^0-9]/", "", $phone);
        $length = strlen($phone);
        switch($length) {
            case 9:
                return "+31".$phone;
                break;
            case 10:
                return "+31".substr($phone, 1);
                break;
            case 11:
            case 12:
                return "+".$phone;
                break;
            default:
                return $phone;
                break;
        }
    }

    /**
     * Format phone number
     *
     * @param unknown $phone
     * @return string|mixed
     */
    private static function format_phone_bel($phone) {
        // note: making sure we have something
        if(!isset($phone{3})) { return ''; }
        // note: strip out everything but numbers
        $phone = preg_replace("/[^0-9]/", "", $phone);
        $length = strlen($phone);
        switch($length) {
            case 9:
                return "+32".$phone;
                break;
            case 10:
                return "+32".substr($phone, 1);
                break;
            case 11:
            case 12:
                return "+".$phone;
                break;
            default:
                return $phone;
                break;
        }
    }
    /**
     * Breadown street address
     * @param unknown $street
     * @return NULL[]|string[]|unknown[]
     */
    private static function breakDownStreet($street)
    {
        $out = [
            'street' => null,
            'houseNumber' => null,
            'houseNumberAdd' => null,
        ];
        $addressResult = null;
        preg_match("/(?P<address>\D+) (?P<number>\d+) (?P<numberAdd>.*)/", $street, $addressResult);
        if(!$addressResult) {
            preg_match("/(?P<address>\D+) (?P<number>\d+)/", $street, $addressResult);
        }
        if (empty($addressResult)) {
            $out['street'] = $street;

            return $out;
        }
        $out['street'] = array_key_exists('address', $addressResult) ? $addressResult['address'] : null;
        $out['houseNumber'] = array_key_exists('number', $addressResult) ? $addressResult['number'] : null;
        $out['houseNumberAdd'] = array_key_exists('numberAdd', $addressResult) ? trim(strtoupper($addressResult['numberAdd'])) : null;
        return $out;
    }
    /**
     * before process check status or prepare transaction
     * Press Confirm Order
     */
    public function before_process()
    {
        global  $order;
        if ($this->getConstant("MODULE_PAYMENT_TARGETPAY{$this->payType}REPAIR_ORDER") === true) {
            global $order;
            // when repairing iDeal the transaction status is succes, set order status accordingly
            $order->info['order_status'] = constant("MODULE_PAYMENT_TARGETPAY{$this->payType}ORDER_STATUS_ID");
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
     * @see targetpayBase::prepareTransaction()
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $messageStack, $order_totals;

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
        if (constant("MODULE_PAYMENT_TARGETPAY{$this->payType}TESTACCOUNT") == "True") { // Always OK
            $testMode = true;
        }
        */
        // Starting transaction
        $objDigiCore = new TargetPayCore($this->tpPaymentMethodId, $this->rtlo, $this->language, $testMode);

        $objDigiCore->setAmount($cc_amount);
        $objDigiCore->setDescription($cc_description);

        $objDigiCore->setReportUrl($this->reportURL . '&method=%payMethod%');
        $objDigiCore->setReturnUrl($this->returnURL . '&method=%payMethod%');

        $objDigiCore->setBankId($this->bankID);
        $objDigiCore->setCountryId($this->countryID);

        $objDigiCore->bindParam('email', $order->customer['email_address']);
        $objDigiCore->bindParam('userip', $_SERVER["REMOTE_ADDR"]);

        $customer_bod = "";
        $customer_gender = "";
        if (!empty($_SESSION['customer_id'])) {
            $sql = "select * from " . TABLE_CUSTOMERS . " where customers_id = :custID ";
            $sql = $db->bindVars($sql, ':custID', $_SESSION['customer_id'], 'integer');
            $result = $db->Execute($sql);
            if ($result->RecordCount() > 0 && $result->fields['customers_gender'] != '') {
                $customer_gender = strtoupper($result->fields['customers_gender']);
            }
            if ($result->RecordCount() > 0 && $result->fields['customers_dob'] != '') {
                $customer_bod = $result->fields['customers_dob'];
                $customer_bod = $customer_bod == '0001-01-01 00:00:00' ? '' : substr($customer_bod, 0, 10);
            }
        }
        // Add product infomation
        // Adding more information for Afterpay method
        $b_country = $order->billing['country']['iso_code_3'];
        $s_country = $order->delivery['country']['iso_code_3'];
        $b_country = (strtoupper($b_country) == 'BE' ? 'BEL' : 'NLD');
        $s_country = (strtoupper($s_country) == 'BE' ? 'BEL' : 'NLD');
        // Build billing address
        if (
            (isset($order->billing['adresnummer']) && !empty($order->billing['adresnummer']))
            ||
            (isset($order->billing['huisnummertoevoeging']) && !empty($order->billing['huisnummertoevoeging']))
        ) {
            $streetParts = [
                'street' => $order->billing['street_address'],
                'houseNumber' => $order->billing['adresnummer'],
                'houseNumberAdd' => $order->billing['huisnummertoevoeging'],
            ];
        } else {
            $streetParts = self::breakDownStreet($order->billing['street_address']);
        }
        $objDigiCore->bindParam('billingstreet', empty($streetParts['street']) ? $order->billing['street_address'] : $streetParts['street']);
        $objDigiCore->bindParam('billinghousenumber', (!empty($streetParts['houseNumber']) || !empty($streetParts['houseNumberAdd'])) ? ($streetParts['houseNumber'] . ' ' . $streetParts['houseNumberAdd']) : $order->billing['street_address']);
        $objDigiCore->bindParam('billingpostalcode', $order->billing['postcode']);
        $objDigiCore->bindParam('billingcity', $order->billing['city']);
        $objDigiCore->bindParam('billingpersonemail', $order->customer['email_address']);
        $objDigiCore->bindParam('billingpersoninitials', ((!empty($order->billing['firstname'])) ? substr($order->billing['firstname'], 0, 1) : ""));
        $objDigiCore->bindParam('billingpersongender', $customer_gender);
        $objDigiCore->bindParam('billingpersonfirstname', $order->billing['firstname']);
        $objDigiCore->bindParam('billingpersonsurname',  $order->billing['lastname']);
        $objDigiCore->bindParam('billingcountrycode', $b_country);
        $objDigiCore->bindParam('billingpersonlanguagecode', $b_country);
        $objDigiCore->bindParam('billingpersonbirthdate', $customer_bod);
        $objDigiCore->bindParam('billingpersonphonenumber', $order->customer['telephone']);
        // Build shipping address
        if (
            (isset($order->delivery['adresnummer']) && !empty($order->delivery['adresnummer']))
            ||
            (isset($order->delivery['huisnummertoevoeging']) && !empty($order->delivery['huisnummertoevoeging']))
        ) {
            $streetParts = [
                'street' => $order->delivery['street_address'],
                'houseNumber' => $order->delivery['adresnummer'],
                'houseNumberAdd' => $order->delivery['huisnummertoevoeging'],
            ];
        } else {
            $streetParts = self::breakDownStreet($order->delivery['street_address']);
        }
        $objDigiCore->bindParam('shippingstreet', empty($streetParts['street']) ? $order->delivery['street_address'] : $streetParts['street']);
        $objDigiCore->bindParam('shippinghousenumber', (!empty($streetParts['houseNumber']) || !empty($streetParts['houseNumberAdd'])) ? ($streetParts['houseNumber'] . ' ' . $streetParts['houseNumberAdd']) : $order->delivery['street_address']);
        $objDigiCore->bindParam('shippingpostalcode',  $order->delivery['postcode']);
        $objDigiCore->bindParam('shippingcity',  $order->delivery['city']);
        $objDigiCore->bindParam('shippingpersonemail',  $order->customer['email_address']);
        $objDigiCore->bindParam('shippingpersoninitials', ((!empty($order->delivery['firstname'])) ? substr($order->delivery['firstname'], 0, 1) : ""));
        $objDigiCore->bindParam('shippingpersongender', $customer_gender);
        $objDigiCore->bindParam('shippingpersonfirstname', $order->delivery['firstname']);
        $objDigiCore->bindParam('shippingpersonsurname',  $order->delivery['lastname']);
        $objDigiCore->bindParam('shippingcountrycode', $s_country);
        $objDigiCore->bindParam('shippingpersonlanguagecode', $s_country);
        $objDigiCore->bindParam('shippingpersonbirthdate', $customer_bod);
        $objDigiCore->bindParam('shippingpersonphonenumber',  $order->customer['telephone']);

        // Add products
        $invoice_lines = null;
        $total_amount_by_product = 0;
        if(!empty($order->products)){
            foreach ($order->products as $product){
                $invoice_lines[] = [
                    'productCode' => (string) $product['id'],
                    'productDescription' => $product['name'],
                    'quantity' => (int) $product['qty'],
                    'price' => (float) $product['price'],
                    'taxCategory' => ((float) $product['price'] > 0) ? $this->getTax(100 * $product['tax'] / ((float) $product['price'])) : 3
                ];
                $total_amount_by_product += (float) $product['price'];
            }
        }
        // Update to fix the total amount and item price
        if($total_amount_by_product < $order->info['total']){
            $invoice_lines[] = [
                'productCode' => "000000",
                'productDescription' => "Other fees (shipping, additional fees)",
                'quantity' => 1,
                'price' => $order->info['total'] - $total_amount_by_product,
                'taxCategory' => 3
            ];
        }
        // Add to invoice data
        if($invoice_lines != null && !empty($invoice_lines)){
            $objDigiCore->bindParam('invoicelines', json_encode($invoice_lines));
        }

        $result = @$objDigiCore->startPayment();

        if ($result === false) {
            $messageStack->add_session('checkout_payment', constant("MODULE_PAYMENT_TARGETPAY{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $objDigiCore->getErrorMessage());
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode(constant("MODULE_PAYMENT_TARGETPAY{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING")) . " " . $objDigiCore->getErrorMessage()), 'SSL', true, false);
        }

        $this->transactionID = $objDigiCore->getTransactionId();

        if (constant("MODULE_PAYMENT_TARGETPAY{$this->payType}EMAIL_ORDER_INIT") == 'True') {
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
            $email_text .= 'Digiwallet transactions lookup: ' . HTTP_SERVER_TARGETPAY_ADMIN . FILENAME_TARGETPAY_TRANSACTIONS . '?action=lookup&transactionID=' . $this->transactionID . "\n";

            zen_mail('', STORE_OWNER_EMAIL_ADDRESS, '[iDeal bestelling opgestart] #' . $new_order_id . ' (?)', $email_text, STORE_OWNER, STORE_OWNER_EMAIL_ADDRESS);
        }

        $db->Execute("INSERT INTO " . TABLE_TARGETPAY_TRANSACTIONS . "
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
        // Check the result of payment
        $result_str = $objDigiCore->getMoreInformation();
        // Process return message
        list ($trxid, $status) = explode("|", $result_str);

        if (strtolower($status) != "captured") {
            list ($trxid, $status, $ext_info) = explode("|", $result_str);
            if (strtolower($status) == "rejected") {
                // Show the error message
                $messageStack->add_session('checkout_payment', $this->getConstant("MODULE_PAYMENT_TARGETPAY{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . "<br/>" . $ext_info);
                zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode($this->getConstant("MODULE_PAYMENT_TARGETPAY{$this->payType}ERROR_TEXT_ERROR_OCCURRED_PROCESSING") . " " . $ext_info), 'SSL', true, false));
                exit(0);
            } else {
                // Redirect to enrichment URL
                zen_redirect($ext_info);
                exit(0);
            }
        } else {
            // Order OK, transfer to success page
            // Redirect to AFP's callback action to update order and relate data
            zen_redirect($objDigiCore->getReturnUrl() . "&trxid=" . $this->transactionID);
            exit();
        }
    }
}
