<?php


require 'includes/application_top.php';
require_once(DIR_WS_CLASSES . 'order.php');
require_once(DIR_WS_CLASSES . 'payment.php');

require_once 'includes/modules/payment/digiwallet/client/ClientCore.php';
require_once 'includes/functions/functions_general.php';

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/includes/languages/' . $langDir . '/modules/payment/digiwallet_a01_ide.php');
require_once $ywincludefile;

if(!isset($_GET['method'])){
    echo 'No method ID received';
    die;
}

$method = zen_db_input($_GET['method']);
$action = zen_db_input($_GET['dw_action']);

$transactionID = zen_db_input($_REQUEST['trxid']);
if(empty($transactionID)) {
    $transactionID = zen_db_input($_REQUEST['transactionID']);
}

if (!isset($transactionID) || empty($transactionID)) {
    echo 'No transaction received';
    die;
}

$methods = array(
    'EPS' => 'EPS',
    'GIP' => 'GIP'
);

$pay_type = (empty($methods[$method])) ? "" : "_" . $methods[$method] . "_";

if (empty($pay_type)) {
    echo 'invalid method';
    die;
}
$testMode = false;
/*
if (constant('MODULE_PAYMENT_DIGIWALLET' . $pay_type . 'TESTACCOUNT') == "True") { // Always OK
    // Test mode = always OK!
    $testMode = true;
}
*/

// check for valid transaction
$transaction_info = getTransactionInfo($transactionID);

if ($transaction_info ===  false) {
    echo 'Transaction not exist!';
    die;
}
// Digiwallet Gateway
$objDigiCore = new \Digiwallet\ClientCore($transaction_info->fields['rtlo'], $method, 'nl');
$paymentIsPartial = false;
$bw_paid_amount = 0;
$order_success_status = constant('MODULE_PAYMENT_DIGIWALLET' . $pay_type . 'ORDER_STATUS_ID');
// Check status
$realstatus = "open";
if($testMode)
{
    // Don't set payment status to success in testmode
    //$realstatus = "success";
}
$unpaid_message = "";
// Check payment result from Targetpay
if($realstatus == "open")
{
    //verify payment
    $apiToken = constant("MODULE_PAYMENT_DIGIWALLET{$pay_type}DIGIWALLET_API_TOKEN");

    $result = $objDigiCore->checkTransaction($apiToken, $transactionID);

    if ($result) {
        $realstatus = "success";
    } else {
        $realstatus = "open";
        $unpaid_message = "Your payment was failed: ";
        if($objDigiCore->getErrorMessage()) {
            $unpaid_message .= $objDigiCore->getErrorMessage();
        }
    }
    // $result = "000000  8f33ac0f15ab7793b273708ae408c8fb7e2asda58fd12f891ac2b4b7a6c915013|AFP-0000012345|Captured";
    // $result = '000000  8f33ac0f15ab7793b273708ae408c8fb7e2asda58fd12f891ac2b4b7a6c915013|AFP-0000012345|Rejected|Leeftijd is onder de 18 jaar|[{"message":"De consument is onder 18 jaar oud","description":"Het spijt ons u te moeten mededelen dat uw aanvraag om uw bestelling achteraf \n te betalen niet door AfterPay wordt geaccepteerd. This is because your age is under 18. If you \n want to use the AfterPay Open Invoice service, your age has to be 18 years or older.\n Voor vragen over uw afwijzing kunt u contact opnemen met de Klantenservice van AfterPay. Of \n kijk op de website van AfterPay.\n Wij adviseren u voor een andere betaalmethode te kiezen om alsnog de betaling van uw bestelling \n af te ronden."}]';
}

$result_message = "";

switch ($realstatus) {
    case "success":
        if(empty($transaction_info->fields['order_id'])){
            // Create order and update status
            $session = unserialize(base64_decode($transaction_info->fields['ideal_session_data']));
            $_SESSION = $session;

            $order = new order();
            //set order cart
            $order->cart();
            $order->info['order_status'] = $order_success_status;
            $order->info['comments'] = 'Digiwallet result: ' . $realstatus . ($testMode ? " (Test mode)" : '');
            if($paymentIsPartial) {
                $order->info['comments'] .= " - Overschrijvingen partial paid: " . number_format($bw_paid_amount / 100, 2);
            }

            require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
            if (!isset($_SESSION['language'])) $_SESSION['language'] = 'english';
            zen_include_language_file('checkout_process.php', '/', 'inline');

            // load the selected shipping module
            require(DIR_WS_CLASSES . 'shipping.php');
            $shipping_modules = new shipping($_SESSION['shipping']);

            require(DIR_WS_CLASSES . 'order_total.php');
            $order_total_modules = new order_total;

            if (!isset($_SESSION['payment']) && $credit_covers === false) {
                zen_redirect(zen_href_link(FILENAME_DEFAULT));
            }

            if (strpos($GLOBALS[$_SESSION['payment']]->code, 'paypal') !== 0) {
                $order_totals = $order_total_modules->pre_confirmation_check();
            }

            $order->info['payment_method'] = constant("MODULE_PAYMENT_DIGIWALLET" . $pay_type . "TEXT_TITLE");
            $order->info['payment_module_code'] = $_SESSION['payment'];
            // Process order
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
            $_SESSION['order_number_created'] = $order_id;
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_ORDER_CREATE_ADD_PRODUCTS');
            // Send email
            @$order->send_order_email($order_id, 2);
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_AFTER_SEND_ORDER_EMAIL');
            // set history status
            if (isset($_SESSION['payment_attempt'])) unset($_SESSION['payment_attempt']);

            /**
             * Calculate order amount for display purposes on checkout-success page as well as adword campaigns etc
             * Takes the product subtotal and subtracts all credits from it
             */
            $ototal = $order_subtotal = $credits_applied = 0;
            for ($i=0, $n=sizeof($order_totals); $i<$n; $i++) {
                if ($order_totals[$i]['code'] == 'ot_subtotal') $order_subtotal = $order_totals[$i]['value'];
                if (${$order_totals[$i]['code']}->credit_class == true) $credits_applied += $order_totals[$i]['value'];
                if ($order_totals[$i]['code'] == 'ot_total') $ototal = $order_totals[$i]['value'];
                if ($order_totals[$i]['code'] == 'ot_tax') $otax = $order_totals[$i]['value'];
                if ($order_totals[$i]['code'] == 'ot_shipping') $oshipping = $order_totals[$i]['value'];
            }
            $commissionable_order = ($order_subtotal - $credits_applied);
            $commissionable_order_formatted = $currencies->format($commissionable_order);
            $_SESSION['order_summary']['order_number'] = $order_id;
            $_SESSION['order_summary']['order_subtotal'] = $order_subtotal;
            $_SESSION['order_summary']['credits_applied'] = $credits_applied;
            $_SESSION['order_summary']['order_total'] = $ototal;
            $_SESSION['order_summary']['commissionable_order'] = $commissionable_order;
            $_SESSION['order_summary']['commissionable_order_formatted'] = $commissionable_order_formatted;
            $_SESSION['order_summary']['coupon_code'] = urlencode($order->info['coupon_code']);
            $_SESSION['order_summary']['currency_code'] = $order->info['currency'];
            $_SESSION['order_summary']['currency_value'] = $order->info['currency_value'];
            $_SESSION['order_summary']['payment_module_code'] = $order->info['payment_module_code'];
            $_SESSION['order_summary']['shipping_method'] = $order->info['shipping_method'];
            $_SESSION['order_summary']['orders_status'] = $order->info['orders_status'];
            $_SESSION['order_summary']['tax'] = $otax;
            $_SESSION['order_summary']['shipping'] = $oshipping;
            $products_array = array();
            foreach ($order->products as $key=>$val) {
                $products_array[urlencode($val['id'])] = urlencode($val['model']);
            }
            $_SESSION['order_summary']['products_ordered_ids'] = implode('|', array_keys($products_array));
            $_SESSION['order_summary']['products_ordered_models'] = implode('|', array_values($products_array));

            updateTransactionInfo($realstatus, $transactionID, $pay_type, $order_id, $order_success_status);
            $zco_notifier->notify('NOTIFY_CHECKOUT_PROCESS_HANDLE_AFFILIATES');
        }
        else {
            // Update order status to OK
            updateTransactionInfo($realstatus, $transactionID, $pay_type, $transaction_info->fields['order_id'], $order_success_status);
        }

        unset($_SESSION['order_summary']);
        unset($_SESSION['order_number_created']);
        $_SESSION['cart']->reset(true);
        $result_message = 'Trxid ' . $transactionID . ' Paid';
        break;
    case "open":
        $result_message = 'Trxid ' . $transactionID . ' Not Paid';
        break;
    default:
        $result_message = 'Not Paid';
        break;
}
// Check action from return URL
if($action != "callback"){
    // Redirect to checkout result page
    if($realstatus == "success"){
        zen_redirect(zen_href_link(FILENAME_CHECKOUT_SUCCESS, '', 'SSL') . "&dw_action=process");
    } else {
        if(!empty($unpaid_message)) {
            $messageStack->add_session('checkout_payment', $unpaid_message);
        }
        if($action == "process") {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL') . "&dw_action=process");
        } else if($action == "cancel") {
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, '', 'SSL') . "&dw_action=cancel");
        }
    }
    exit(0);
}
if(!empty($result_message)) {
    echo $result_message;
}
/**
 * get customer info and update trans table
 */

function updateTransactionInfo($realstatus, $transactionID, $pay_type, $order_id, $order_status)
{
    global $db;

    $db->Execute("UPDATE " . TABLE_DIGIWALLET_TRANSACTIONS
        . " SET `order_id`=" . $order_id
        . ", `transaction_status` = '". $realstatus .
        "',`datetimestamp` = NOW( ) ,`consumer_name` = '',`consumer_account_number` = '',`consumer_city` = '' WHERE `transaction_id` = '" . $transactionID . "' LIMIT 1"
    );

    $db->Execute("update " . TABLE_ORDERS . " set orders_status = '" . $order_status . "', last_modified = now() where orders_id = '" . (int) $order_id . "'");
}

/**
 * @param transactionID
 *
 * return db object|boolean
 *
 * @return bool
 */

function getTransactionInfo($transactionID)
{
    global $db;
    // get rtlo for trans
    $sql = "select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where `transaction_id` = '" . $transactionID . "'";
    $result = $db->Execute($sql);

    return !empty($result) ? $result : false;
}
