<?php
/**
 * Digiwallet Payment Module for ZenCart
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license see LICENSE.TXT
 */
$ywincludefile = realpath(dirname(__FILE__) . '/../../../extra_datafiles/digiwallet.php');
require_once ($ywincludefile);

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/../../../languages/' . $langDir . '/modules/payment/digiwallet_a01_ide.php');
require_once ($ywincludefile);

$ywincludefile = realpath(dirname(__FILE__) . '/digiwallet.class.php');
require_once ($ywincludefile);

//ini_set('display_errors', TRUE);

class digiwalletBase extends base
{

    protected $tpPaymentMethodId = 'AUTO';
    protected $tpPaymentMethodName = 'AUTO';
    protected $tpMethods = array(
        'DEB' => 'DEB',
        'MRC' => 'MRC',
        'WAL' => 'WAL',
        'CC' => 'CC',
        'IDE' => 'IDE',
        'PYP' => 'PYP',
        'BW' => 'BW',
        'AFP' => 'AFP',
        'GIP' => 'GIP',
        'EPS' => 'EPS'
    );

    protected $order_prefix = array(
        'IDE' => 'a01',
        'MRC' => 'a02',
        'AFP' => 'a03',
        'BW'  => 'a04',
        'EPS' => 'a05',
        'GIP' => 'a06',
        'WAL' => 'a07',
        'PYP' => 'a08',
        'DEB' => 'a09',
        'CC'  => 'a10',
    );

    public $cancelURL;

    public $code, $title, $description, $enabled, $sort_order, $payType, $language = "nl";

    public $acceptedCurrency = "EUR";

    public $rtlo;

    public $passwordKey;

    public $merchantReturnURL;

    public $expirationPeriod;

    public $transactionDescription;

    public $transactionDescriptionText;

    public $returnURL;

    public $reportURL;

    public $transactionID;

    public $purchaseID;

    public $directoryUpdateFrequency;

    public $bankID;

    public $countryID;

    private $error;
    private $errorcode;

    public $bankUrl;

    /**
     * Get the defined constant values
     *
     * @param unknown $key
     * @return mixed|boolean
     */
    public function getConstant($key)
    {
        if (defined($key)) {
            return constant($key);
        }
        return false;
    }
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

        $this->reportURL = zen_href_link('digiwallet_callback.php?dw_action=callback', '', 'SSL', false, false, true);
        $this->returnURL = zen_href_link('digiwallet_callback.php?dw_action=process', '', 'SSL', false, false, true);
        $this->cancelURL = zen_href_link('digiwallet_callback.php?dw_action=cancel', '', 'SSL', false, false, true);
        //$this->cancelURL = zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL');
    }

    /**
     * update module status
     */
    public function update_status()
    {
        global $order, $db;

        if (($this->enabled == true) && ((int) constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ZONE") > 0)) {
            $check_flag = false;
            $check = $db->Execute("select zone_id from " . TABLE_ZONES_TO_GEO_ZONES . " where geo_zone_id = '" . constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ZONE") . "' and zone_country_id = '" . $order->billing['country']['id'] . "' order by zone_id");

            while (! $check->EOF) {
                if ($check->fields['zone_id'] < 1) {
                    $check_flag = true;
                    break;
                } elseif ($check->fields['zone_id'] == $order->billing['zone_id']) {
                    $check_flag = true;
                    break;
                }
                $check->MoveNext();
            }
            if ($check_flag == false) {
                $this->enabled = false;
            }
        }
    }

    function javascript_validation()
    {
        return false;
    }

    /**
     * get bank directory
     */
    public function getDirectory()
    {
        global $db;
        $issuerList = array();

        $objDigiCore = new DigiwalletCore($this->tpPaymentMethodId, $this->rtlo);

        $bankList = $objDigiCore->getBankList();
        foreach ($bankList as $issuerID => $issuerName) {
            $i = new stdClass();
            $i->issuerID = $issuerID;
            $i->issuerName = $issuerName;
            $i->issuerList = 'short';
            array_push($issuerList, $i);
        }

        return $issuerList;
    }

    /**
     * make bank selection field
     */
    public function selection()
    {
        return array(
            'id' => $this->code,
            'module' => $this->title,
            'fields' => array(
                array(
                    'title' => zen_image('images/icons/' . $this->tpPaymentMethodId . '_60.png', '', '', '', 'align=absmiddle'),
                )
            )
        );
    }

    /**
     * pre_confirmation_check
     */
    public function pre_confirmation_check()
    {
        return false;
    }

    /**
     * prepare the transaction and send user back on error or forward to bank
     */
    public function prepareTransaction()
    {
        global $order, $currencies, $customer_id, $db, $messageStack, $order_totals;

        if(!empty($_POST["bankID"])) {
            $this->bankID = $_POST["bankID"];   //For ideal banking
        }
        if(!empty($_POST["countryID"])) {
            $this->countryID = $_POST["countryID"]; //For Sofort country
        }

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

        $this->startTransaction($cc_amount, $cc_description, $cc_purchaseID);
    }

    /**
     *
     * @return boolean
     */
    public function confirmation()
    {
        return false;
    }

    /**
     * make hidden value for payment system
     */
    public function process_button()
    {
        $process_button = zen_draw_hidden_field('bankID', $_POST['bankID']) . MODULE_PAYMENT_DIGIWALLET_EXPRESS_TEXT;

        if (defined('BUTTON_CHECKOUT_DIGIWALLET_ALT')) {
            $process_button .= zen_image_submit('digiwallet.gif', BUTTON_CHECKOUT_DIGIWALLET_ALT);
        }
        return $process_button;
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
     * check payment status
     */
    public function checkStatus()
    {
        return true;
    }

    /**
     * after order create set value in database
     *
     * @param
     *            $zf_order_id
     */
    public function after_order_create($zf_order_id)
    {
        global $db;
        $db->Execute("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS . " SET `order_id` = '" . $zf_order_id . "', `ideal_session_data` = '' WHERE `transaction_id` = '" . $this->transactionID . "' LIMIT 1 ;");
        if (isset($_SESSION['digiwallet_repair_transaction_id'])) {
            unset($_SESSION['digiwallet_repair_transaction_id']);
        }
    }

    /**
     * after process function
     *
     * @return false
     */
    public function after_process()
    {
        return false;
    }

    /**
     * checks installation of module
     */
    public function check()
    {
        global $db;

        if (! isset($this->_check)) {
            $check_query = $db->Execute("select configuration_value from " . TABLE_CONFIGURATION . " where configuration_key = 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "STATUS'");
            $this->_check = $check_query->RecordCount();
        }
        return $this->_check;
    }

    /**
     * install values in database
     */
    public function install()
    {
        global $db;

        $sort_order = array_search($this->tpPaymentMethodId, array_keys($this->order_prefix));
        if(is_numeric($sort_order)) {
            $sort_order = $sort_order + 1;
        }

        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable Digiwallet payment module', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "STATUS', 'True', 'Do you want to accept Digiwallet payments?', '6', '1', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Sortorder', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "SORT_ORDER', '{$sort_order}', 'Sort order of payment methods in list. Lowest is displayed first.', '6', '7', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, use_function, set_function, date_added) values ('Payment zone', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "ZONE', '0', 'If a zone is selected, enable this payment method for that zone only.', '6', '3', 'zen_get_zone_class_title', 'zen_cfg_pull_down_zone_classes(', now())");

        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Transaction description', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "TRANSACTION_DESCRIPTION', 'Automatic', 'Select automatic for product name as description, or manual to use the text you supply below.', '6', '8', 'zen_cfg_select_option(array(\'Automatic\',\'Manual\'), ', now())");

        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Transaction description text', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "MERCHANT_TRANSACTION_DESCRIPTION_TEXT', '" . TITLE . "', 'Description of transactions from this webshop. <strong>Should not be empty!</strong>.', '6', '9', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('Digiwallet Outletcode', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "DIGIWALLET_RTLO', " . DigiwalletCore::DEFAULT_RTLO . " , 'The Digiwallet Outletcode', '6', '2', now())"); // Default Digiwallet

        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('DigiWallet API Token', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "DIGIWALLET_API_TOKEN', '" . DigiwalletCore::DEFAULT_API_TOKEN . "' , 'You can obtain your token here: https://www.digiwallet.nl/nl/user/dashboard >> choose your Organization >> Developers', '6', '3', now())");

        /*
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Testaccount?', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "TESTACCOUNT', 'False', 'Enable testaccount (only for validation)?', '6', '6', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");
        */

        //$db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, date_added) values ('IP address', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "REPAIR_IP', '" . $_SERVER['REMOTE_ADDR'] . "', 'The IP address of the user (administrator) that is allowed to complete open ideal orders (if empty everyone will be allowed, which is not recommended!).', '6', '10', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, date_added) values ('Enable pre order emails', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "EMAIL_ORDER_INIT', 'False', 'Do you want emails to be sent to the store owner whenever an Digiwallet order is being initiated? The default is <strong>False</strong>.', '6', '17', 'zen_cfg_select_option(array(\'True\', \'False\'), ', now())");

        $db->Execute("CREATE TABLE  IF NOT EXISTS " . TABLE_DIGIWALLET_DIRECTORY . " (`issuer_id` VARCHAR( 4 ) NOT NULL ,`issuer_name` VARCHAR( 30 ) NOT NULL ,`issuer_issuerlist` VARCHAR( 5 ) NOT NULL ,`timestamp` DATETIME NOT NULL ,PRIMARY KEY ( `issuer_id` ) );");

        if (DIGIWALLET_OLD_MYSQL_VERSION_COMP == 'true') {
            $db->Execute("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_TRANSACTIONS . " (`transaction_id` VARCHAR( 30 ) NOT NULL ,`rtlo` VARCHAR( 7 ) NOT NULL ,`purchase_id` VARCHAR( 30 ) NOT NULL , `issuer_id` VARCHAR( 25 ) NOT NULL , `session_id` VARCHAR( 128 ) NOT NULL ,`ideal_session_data`  MEDIUMBLOB NOT NULL ,`order_id` INT( 11 ),`transaction_status` VARCHAR( 10 ) ,`datetimestamp` DATETIME, `consumer_name` VARCHAR( 50 ) ,`consumer_account_number` VARCHAR( 20 ) ,`consumer_city` VARCHAR( 50 ), `customer_id` INT( 11 ), `amount` DECIMAL( 15, 4 ), `currency` CHAR( 3 ), `batch_id` VARCHAR( 30 ), PRIMARY KEY ( `transaction_id` ));");
        } else {
            $db->Execute("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_TRANSACTIONS . " (`transaction_id` VARCHAR( 30 ) NOT NULL ,`rtlo` VARCHAR( 7 ) NOT NULL ,`purchase_id` VARCHAR( 30 ) NOT NULL , `issuer_id` VARCHAR( 25 ) NOT NULL , `session_id` VARCHAR( 128 ) NOT NULL ,`ideal_session_data`  MEDIUMBLOB NOT NULL ,`order_id` INT( 11 ),`transaction_status` VARCHAR( 10 ) ,`datetimestamp` DATETIME, `last_modified` TIMESTAMP NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP, `consumer_name` VARCHAR( 50 ) ,`consumer_account_number` VARCHAR( 20 ) ,`consumer_city` VARCHAR( 50 ), `customer_id` INT( 11 ), `amount` DECIMAL( 15, 4 ), `currency` CHAR( 3 ), `batch_id` VARCHAR( 30 ), PRIMARY KEY ( `transaction_id` ));");
        }

        // CREATE TABLE REFUND
        $db->Execute("CREATE TABLE IF NOT EXISTS " . TABLE_DIGIWALLET_REFUND . " (`refund_id` VARCHAR(30) NOT NULL, `transaction_id` VARCHAR( 30 ) , `order_id` INT( 11 ), `refund_amount` DECIMAL( 15, 4 ), `refund_message` VARCHAR(1024), `status` VARCHAR(30), `datetimestamp` DATETIME, `last_modified` TIMESTAMP NULL default CURRENT_TIMESTAMP on update CURRENT_TIMESTAMP, PRIMARY KEY (`refund_id`));");

        // Add more column for Afterpay and Bankwire payment
        $result = $db->Execute("SHOW COLUMNS FROM " . TABLE_DIGIWALLET_TRANSACTIONS . " LIKE 'more';");
        if ($result->RecordCount() != 1) {
            // Add more columns
            $db->Execute("ALTER TABLE " . TABLE_DIGIWALLET_TRANSACTIONS . " ADD `more` TEXT default null;");
        }
        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS;
        $status_query = $db->Execute($sql);
        // Install payment status
        $open = $status_query->fields['status_id'] + 1;
        $confirmed = $status_query->fields['status_id'] + 2;
        $review = $status_query->fields['status_id'] + 3;
        $refund = $status_query->fields['status_id'] + 4;

        $languages = zen_get_languages();
        for ($i = 0, $n = sizeof($languages); $i < $n; $i ++) {
            $db->Execute("insert IGNORE into " . TABLE_ORDERS_STATUS . "(orders_status_id, language_id, orders_status_name) SELECT '" . $open . "', '" . $languages[$i]['id'] . "', 'Payment Open [digiwallet]' FROM DUAL WHERE NOT EXISTS (SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Open [digiwallet]')");
            $db->Execute("insert IGNORE into " . TABLE_ORDERS_STATUS . "(orders_status_id, language_id, orders_status_name) SELECT '" . $confirmed . "', '" . $languages[$i]['id'] . "', 'Payment Paid [digiwallet]' FROM DUAL WHERE NOT EXISTS (SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Paid [digiwallet]')");
            $db->Execute("insert IGNORE into " . TABLE_ORDERS_STATUS . "(orders_status_id, language_id, orders_status_name) SELECT '" . $review . "', '" . $languages[$i]['id'] . "', 'Payment Review [digiwallet]' FROM DUAL WHERE NOT EXISTS (SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Review [digiwallet]')");
            $db->Execute("insert IGNORE into " . TABLE_ORDERS_STATUS . "(orders_status_id, language_id, orders_status_name) SELECT '" . $refund . "', '" . $languages[$i]['id'] . "', 'Payment Refund [digiwallet]' FROM DUAL WHERE NOT EXISTS (SELECT orders_status_name FROM " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Refund [digiwallet]')");
        }
        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Paid [digiwallet]'";
        $status = $db->Execute($sql);
        $confirmed = $status->fields['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Open [digiwallet]'";
        $status = $db->Execute($sql);
        $open = $status->fields['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Review [digiwallet]'";
        $status = $db->Execute($sql);
        $review = $status->fields['status_id'];

        $sql = "select max(orders_status_id) as status_id from " . TABLE_ORDERS_STATUS . " WHERE orders_status_name = 'Payment Refund [digiwallet]'";
        $status = $db->Execute($sql);
        $refund = $status->fields['status_id'];

        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            values ('Order status - confirmed', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "ORDER_STATUS_ID', '" . $confirmed . "', 'The status of orders that where successfully confirmed.', '6', '4', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            values ('Order status - open', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "ORDER_STATUS_ID_OPEN', '" . $open . "', 'The status of orders of which payment still inprogress.', '6', '5', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            values ('Order status - review', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "ORDER_STATUS_ID_REVIEW', '" . $review . "', 'The status of orders which paid by Bankwire - Overschrijving.', '6', '6', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
        $db->Execute("insert IGNORE into " . TABLE_CONFIGURATION . " (configuration_title, configuration_key, configuration_value, configuration_description, configuration_group_id, sort_order, set_function, use_function, date_added)
            values ('Order status - refund', 'MODULE_PAYMENT_DIGIWALLET" . $this->payType . "ORDER_STATUS_ID_REFUND', '" . $refund . "', 'The status of orders which have been refunded.', '6', '7', 'zen_cfg_pull_down_order_statuses(', 'zen_get_order_status_name', now())");
    }

    public function remove()
    {
        global $db;

        $db->Execute("delete from " . TABLE_CONFIGURATION . " where configuration_key in ('" . implode("', '", $this->keys()) . "')");
    }

    public function keys()
    {

        $out =  array(
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'STATUS',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'SORT_ORDER',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ZONE',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'TESTACCOUNT',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID_OPEN',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID_REVIEW',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'ORDER_STATUS_ID_REFUND',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'DIGIWALLET_RTLO',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'DIGIWALLET_API_TOKEN',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'TRANSACTION_DESCRIPTION',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'MERCHANT_TRANSACTION_DESCRIPTION_TEXT',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'EMAIL_ORDER_INIT',
            'MODULE_PAYMENT_DIGIWALLET' . $this->payType . 'REPAIR_IP'
        );

        return $out;
    }

    /**
     * @desc set the error code
     * @param string $error
     */
    private function setError($error)
    {
        $this->error = $error;
    }

    /**
     * @desc set the error code
     * @param string $error
     */
    private function setErrorCode($errorcode)
    {
        $this->errorcode = $errorcode;
    }

    /**
     * @desc get the error string
     * @return string $this->error
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * @desc get the error code
     * @return string $this->errorcode
     */
    public function getErrorCode()
    {
        return $this->errorcode;
    }

    /**
     * @desc set ideal amount
     * @param int $intIdealAmount
     * @return string $this
     */
    public function setPaymentAmount($intIdealAmount)
    {
        # Is this a valid ideal amount?
        if (is_numeric ( $intIdealAmount ) && $intIdealAmount > 0) {
            $this->idealAmount = $intIdealAmount;
        } else {
            $this->setError(MODULE_PAYMENT_DIGIWALLET_ERROR_AMOUNT_TO_LOW);
            return false;
        }
        return $this;
    }

    /**
     * Start the transaction
     * @param int $cc_amount
     * @param string $cc_description
     * @param int $cc_purchaseID
     * @param DigiwalletCore $objDigiCore
     */
    protected function startTransaction($cc_amount, $cc_description, $cc_purchaseID, $objDigiCore = null)
    {
        global $db, $messageStack, $order;
        $testMode = false;
        /*
        if (constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}TESTACCOUNT") == "True") { // Always OK
            $testMode = true;
        }
        */
        $objDigiCore = ($objDigiCore) ? $objDigiCore : new DigiwalletCore($this->tpPaymentMethodId, $this->rtlo, $this->language, $testMode);

        $objDigiCore->setAmount($cc_amount);
        $objDigiCore->setDescription($cc_description);

        $objDigiCore->setReportUrl($this->reportURL . '&method=%payMethod%');
        $objDigiCore->setReturnUrl($this->returnURL . '&method=%payMethod%');
        $objDigiCore->setCancelUrl($this->cancelURL . '&method=%payMethod%');

        $objDigiCore->setBankId($this->bankID);
        $objDigiCore->setCountryId($this->countryID);

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

        $this->bankUrl = $objDigiCore->getBankUrl();

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
            );");

        zen_redirect(html_entity_decode($this->bankUrl));
    }

    /**
     * Set decriptions
     *
     * @param order $order
     * @return string
     */
    protected function setIdealDescription($order)
    {
        if ((strtolower($this->transactionDescription) == 'automatic') && (count($order->products) == 1)) {
            $product = $order->products[0];
            $ideal_description = $product['name'];
        } else {
            $ideal_description = $this->transactionDescriptionText;
        }

        $ideal_description = trim(strip_tags($ideal_description));
        $ideal_description = preg_replace("/[^A-Za-z0-9 ]/", '*', $ideal_description);
        $ideal_description = substr($ideal_description, 0, 31); /* Max. 32 characters */

        if (empty($ideal_description)) {
            $ideal_description = 'nvt';
        }

        return $ideal_description;
    }
    /**
     * Build admin-page components
     *
     * @param int $zf_order_id
     * @return string
     */
    function admin_notification($zf_order_id)
    {
        global $db, $currencies;
        $output = "";
        $refund_history = "";
        $total_refunded = 0;
        $action_url = zen_href_link(FILENAME_ORDERS, zen_get_all_get_params(array('oID', 'action')) . 'oID=' . $zf_order_id . '&action=doRefund');
        $refund_query = $this->getRefundHistory($zf_order_id);
        while (!$refund_query->EOF) {
            $total_refunded += $refund_query->fields['status'] == "success" ? $refund_query->fields['refund_amount'] : 0;
            $status_color = $refund_query->fields['status'] == "success" ? "blue" : "red";
            $refund_history .= "<tr><td>{$refund_query->fields['refund_id']}</td>";
            $refund_history .= "<td style='text-align: right;'>". $currencies->display_price(((float) $refund_query->fields['refund_amount']), 0)."</td>";
            $refund_history .= "<td>{$refund_query->fields['refund_message']}</td>";
            $refund_history .= "<td style='color: {$status_color}'>{$refund_query->fields['status']}</td>";
            $refund_history .= "<td>{$refund_query->fields['datetimestamp']}</td>";
            $refund_history .= "<td style='text-align: center'>";
            if($refund_query->fields['status'] == "success"){
                $refund_history .= "<form method='get' action='".$action_url."&type=cancel'>";
                $refund_history .= ' <input type="hidden" name="trans_id" value="'.$refund_query->fields['transaction_id'].'"/>';
                $refund_history .= ' <input type="hidden" name="oID" value="'.$zf_order_id.'"/>';
                $refund_history .= ' <input type="hidden" name="action" value="doRefund"/>';
                $refund_history .= ' <input type="hidden" name="refundID" value="'.$refund_query->fields['refund_id'].'"/>';
                $refund_history .= ' <input type="hidden" name="type" value="cancel"/>';
                $refund_history .= "<input type='submit' value='Cancel'></input></form>";
            }
            $refund_history .= "</td></tr>";
            $refund_query->MoveNext();
        }
        if(!empty($refund_history)){
            $output .= "<table border='0' cellspacing='0' cellpadding='2' style='padding-bottom: 10px;'>";
            $output .= '<tr>';
            $output .= '<td class="main" style="vertical-align: top; width: 100px;"><strong>Refund history:</strong></td>';
            $output .= '<td><table border="1" style="width: 600px;" cellpadding="5">';
            $output .= '<tr><th style="text-align: center;">Refund ID</th>';
            $output .= '<th style="text-align: center;">Amount</th>';
            $output .= '<th style="text-align: center;">Message</th>';
            $output .= '<th style="text-align: center;">Status</th>';
            $output .= '<th style="text-align: center;">Date time</th>';
            $output .= '<th style="text-align: center;">Action</th></tr>';
            $output .= $refund_history;
            $output .='</table></td></table>';
        }
        // Check to enable/disable refund feature
        if(constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}DIGIWALLET_API_TOKEN")) {
            // Check if payment was paid then show refunding form
            $trans = $this->getTransactionInfo($zf_order_id, 'success');
            if(!empty($trans->fields['transaction_id'])){
                $remain_refund = $trans->fields['amount']/100 - $total_refunded;
                if($remain_refund > 0) {
                    $output .= '<form method="GET" action="'.$action_url.'&type=confirm"><table border="0" cellspacing="0" cellpadding="2">';
                    $output .= '<tr>';
                    $output .= '<td class="main" style="vertical-align: top; width: 100px;"><strong>Message:</strong></td>';
                    $output .= '<td><textarea name="refund_comment" style="height: 50px; width: 400px;" placeholder="'.MODULE_PAYMENT_DIGIWALLET_REFUND_COMMENT_PLACE_HOLDER.'"></textarea></td>';
                    $output .= '</tr>';
                    $output .= '<tr>';
                    $output .= '<td class="main" style="vertical-align: top; width: 100px;"><strong>Amount:</strong></td>';
                    $output .= '<td><input name="refund_amount" style="width: 400px;" value="0"></input></td>';
                    $output .= '</tr>';
                    $output .= '<tr><td>';
                    $output .= ' <input type="hidden" name="trans_id" value="'.$trans->fields['transaction_id'].'"/>';
                    $output .= ' <input type="hidden" name="oID" value="'.$zf_order_id.'"/>';
                    $output .= ' <input type="hidden" name="action" value="doRefund"/>';
                    $output .= '</td><td> <input type="submit" value="Refund"></input> (You can refund to customer: '. $currencies->display_price(((float) $remain_refund), 0) .')</td>';
                    $output .= '</tr>';
                    $output .='</table></form>';
                }
            }
        }
        if(!empty($output)) {
            $refund_template = '<td class="main"><div class="dataTableHeadingRow dataTableHeadingContent" style="width: 100%; padding: 5px;"> Refund</div>';
            $output = $refund_template. "<div class='dataTableRow dataTableContent'>{$output}</div></td>";
        }
        return $output;
    }
    /**
     * @param transactionID
     *
     * return db object|boolean
     *
     */

    protected function getTransactionInfo($order_id, $status = 'success')
    {
        global $db;
        $result = $db->Execute("select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `order_id` = $order_id AND `transaction_status` = '$status'");
        return $result;
    }
    /**
     * Get all existing refund history
     */
    protected function getRefundHistory($order_id)
    {
        global $db;
        $result = $db->Execute("select * from " . TABLE_DIGIWALLET_REFUND . " where `order_id` = $order_id order by datetimestamp asc");
        return $result;
    }
    /**
     * Implement refund method
     */
    public function _doRefund($oID)
    {
        global $db, $messageStack;
        $amount = 0;
        $action_type = "";
        $message = "";
        $refundID = "";
        $transID = "";

        if (isset($_GET['type'])) $action_type = $_GET['type'];
        if (isset($_GET['refund_amount'])) $amount = (double)$_GET['refund_amount'];
        if (isset($_GET['refund_comment'])) $message = urldecode($_GET['refund_comment']);
        if (isset($_GET['trans_id'])) $transID = $_GET['trans_id'];
        if (isset($_GET['refundID'])) $refundID = $_GET['refundID'];
        // Validate input information
        $total_refunded = 0;
        $refund_query = $this->getRefundHistory($oID);
        while (!$refund_query->EOF) {
            $total_refunded += $refund_query->fields['status'] == "success" ? $refund_query->fields['refund_amount'] : 0;
            $refund_query->MoveNext();
        }
        // get Testmode and API Token
        $testMode = false;
        /*
        if (constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}TESTACCOUNT") == "True") { // Always OK
            $testMode = true;
        }
        */
        $apiToken = constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}DIGIWALLET_API_TOKEN");
        // Get Order
        $order = $db->Execute("select * from " . TABLE_ORDERS . " WHERE orders_id = $oID LIMIT 1");
        $trans = $this->getTransactionInfo($oID, 'success');
        if(!empty($trans->fields['transaction_id'])){
            $remain_refund = $trans->fields['amount']/100 - $total_refunded;
            if($action_type == 'cancel'){
                // Cancel a refund
                // Init Tagetpay Object
                $digiCore = new DigiwalletCore($this->tpPaymentMethodId, $this->rtlo, $this->language, $testMode);
                if(!$testMode && !$digiCore->deleteRefund($trans->fields['transaction_id'], $apiToken)){
                    $messageStack->add_session("Digiwallet cancelling refund error: {$digiCore->getRawErrorMessage()}", 'error');
                } else {
                    $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_DELETE_REFUND_SUCCESS, 'success');
                    // UPDATE REFUND TABLE FOR TRACKING
                    $db->Execute("update " . TABLE_DIGIWALLET_REFUND . " set status = 'cancelled' where refund_id = '". zen_db_input($refundID) ."'");
                    // Re-SCAN Refund Totally
                    $total_refunded = 0;
                    $refund_query = $this->getRefundHistory($oID);
                    while (!$refund_query->EOF) {
                        $total_refunded += $refund_query->fields['status'] == "success" ? $refund_query->fields['refund_amount'] : 0;
                        $refund_query->MoveNext();
                    }
                    if($total_refunded <= 0) {
                        // Update Order Status As Success again
                        $success_status = constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ORDER_STATUS_ID");
                        if($success_status) {
                            $db->Execute ("update " . TABLE_ORDERS . " set orders_status = '" . zen_db_input($success_status) . "', last_modified = now() where orders_id = '" . zen_db_input((int) $oID) . "'");
                        }
                    }
                }
            } else {
                // Make a refund
                if($remain_refund < $amount) {
                    // Show error message
                    $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_REFUND_ERROR_NOT_ENOUGH_AMOUNT, 'error');
                } else if(empty($message)){
                    $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_REFUND_ERROR_NO_MESSAGE, 'error');
                }else if($amount <= 0){
                    $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_REFUND_ERROR_AMOUNT, 'error');
                } else {
                    // Call Digiwallet to make refund action
                    $internalNote =  "Refunding Order with orderId: " . $oID . " - Digiwallet transactionId: " . $trans->fields['transaction_id'] . " - Total price: " . $trans->fields['amount']/100;
                    $consumerName = "GUEST";
                    if(!empty($order->fields['customers_name'])) {
                        $consumerName = $order->fields['customers_name'];
                    }
                    $refundData = array(
                        'paymethodID' => $this->tpPaymentMethodId,
                        'transactionID' => $trans->fields['transaction_id'],
                        'amount' => (int) ($amount * 100), // Parse amount to Int and convert to cent value
                        'description' => $message,
                        'internalNote' => $internalNote,
                        'consumerName' => $consumerName
                    );
                    // Init Tagetpay Object
                    $digiCore = new DigiwalletCore($this->tpPaymentMethodId, $this->rtlo, $this->language, $testMode);
                    // Refund sucess if testmode enable
                    if(!$testMode && !$digiCore->refundInvoice($refundData, $apiToken)){
                        $messageStack->add_session("Digiwallet refunding error: {$digiCore->getRawErrorMessage()}", 'error');
                    } else {
                        $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_REFUND_SUCCESS, 'success');
                        $refund_id = $digiCore->getRefundID();
                        if($testMode) {
                            $refund_id = time();
                        }
                        // INSERT TO REFUND TABLE FOR LOGGING
                        $db->Execute("insert into " . TABLE_DIGIWALLET_REFUND . "
                                      (refund_id, transaction_id, order_id, refund_amount, refund_message, status, datetimestamp)
                                      values ('" . zen_db_input($refund_id) . "',
                                      '" . zen_db_input($trans->fields['transaction_id']) . "',
                                      '" . zen_db_input($oID) . "',
                                      '" . zen_db_input($amount) . "',
                                      '" . zen_db_input($message) . "',
                                      'success',
                                      now())");
                        // Update order status as refunded
                        $refund_status = constant("MODULE_PAYMENT_DIGIWALLET{$this->payType}ORDER_STATUS_ID_REFUND");
                        if($refund_status) {
                            $db->Execute ("update " . TABLE_ORDERS . " set orders_status = '" . zen_db_input($refund_status) . "', last_modified = now() where orders_id = '" . zen_db_input((int) $oID) . "'");
                        }
                    }
                }
            }
        }
        else
        {
            $messageStack->add_session(MODULE_PAYMENT_DIGIWALLET_REFUND_ERROR_TRANSACTION_NOT_FOUND, 'error');
        }
    }
}
