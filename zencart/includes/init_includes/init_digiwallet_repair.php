<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license see LICENSE.TXT
 */

  
if (!defined('IS_ADMIN_FLAG')) {
    die('Illegal Access');
}
function zen_digiwallet_repair_allowed()
{
    global $messageStack;
    if ((trim(MODULE_PAYMENT_DIGIWALLET_REPAIR_IP) == '')
    || ($_SERVER['REMOTE_ADDR'] == trim(MODULE_PAYMENT_DIGIWALLET_REPAIR_IP))) {
        return true;
    }
    $messageStack->add_session('header', 'Order repair not allowed! (your ip address = ' . $_SERVER['REMOTE_ADDR'] . ')');
    return false;
}

if (($_GET['digiwallet_repair_order'] == 'true')&&(zen_digiwallet_repair_allowed())) {
    $digiCore_repair_result = $db->Execute("SELECT transaction_id, order_id, ideal_session_data  FROM " . TABLE_DIGIWALLET_TRANSACTIONS . " WHERE (transaction_id='" . zen_db_input($_GET['digiwallet_transaction_id']) . "' AND `transaction_status` = 'success')");

    if (($digiCore_repair_result->RecordCount() == 1) && (!($digiCore_repair_result->fields['order_id'] > 0))) {
        //exit('match!');
        $_SESSION = unserialize(base64_decode($digiCore_repair_result->fields['ideal_session_data']));
        // MODULE_PAYMENT_IDEAL_REPAIR_ORDER  true enables forced order completion in iDeal module
        define('MODULE_PAYMENT_DIGIWALLET_REPAIR_ORDER', true);
    } elseif ($digiCore_repair_result->fields['order_id'] > 0) {
        $messageStack->add_session('header', 'Order already exists! (order id = ' . $digiCore_repair_result->fields['order_id'] . ')');
    } else {
        $messageStack->add_session('header', 'Fatal digiwalletrepair error!');
    }
}
