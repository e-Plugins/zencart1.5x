<?php
/**
 * site_map header_php.php
*
* @package page
* @copyright Copyright 2003-2006 Zen Cart Development Team
* @copyright Portions Copyright 2003 osCommerce
* @license http://www.zen-cart.com/license/2_0.txt GNU Public License V2.0
* @version $Id: header_php.php 3230 2006-03-20 23:21:29Z drbyte $
*/
require_once(DIR_WS_CLASSES . 'order.php');
require_once(DIR_WS_CLASSES . 'payment.php');

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";
$ywincludefile = realpath(DIR_WS_LANGUAGES . $langDir . '/modules/payment/digiwallet_a01_ide.php');
require_once $ywincludefile;

$transactionID = zen_db_input($_REQUEST['trxid']);
$process_valid = true;
if(empty($transactionID)){
    // Redirect to index page
    $process_valid = false;
}
$result = $db->Execute("select * from " . TABLE_DIGIWALLET_TRANSACTIONS . " where transaction_id = '{$transactionID}' and issuer_id = 'BW'");
if($result->count() < 1){
    // Redirect to index page
    $process_valid = false;
}
if($process_valid){
    if($result->fields['transaction_status'] == "success"){
        ?>
        	<h2><?php echo MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_FINISHED;?></h2>
        <?php 
    } else {
        list($trxid, $accountNumber, $iban, $bic, $beneficiary, $bank) = explode("|", $result->fields['more']);
        // Check customer's order information
        $session = unserialize(base64_decode($result->fields['ideal_session_data']));
        // Backup current session
        $backup_session = $_SESSION;
        $_SESSION = $session;

        $order = new order();
        //set order cart
        $order->cart();
        // Encode email address
        $emails = str_split($order->customer['email_address']);
        $counter = 0;
        $cus_email = "";
        foreach ($emails as $char) {
            if($counter == 0) {
                $cus_email .= $char;
                $counter++;
            } else if($char == "@") {
                $cus_email .= $char;
                $counter++;
            } else if($char == "." && $counter > 1) {
                $cus_email .= $char;
                $counter++;
            } else if($counter > 2) {
                $cus_email .= $char;
            } else {
                $cus_email .= "*";
            }
        }
        echo sprintf(MODULE_PAYMENT_DIGIWALLET_BANKWIRE_THANKYOU_PAGE,                   
               $currencies->display_price(((float) $result->fields['amount'])/100, 0),
               $iban,
               $beneficiary,
               $trxid,
               $cus_email,
               $bic,
               $bank
           );
        // Restore current session
        $_SESSION = $backup_session;
        $order = new order();
        $order->cart();
    }
}
else{
    ?>
    	<script>
			window.location.href="<?php echo zen_href_link(FILENAME_DEFAULT, '', 'SSL', true, false); ?>";
    	</script>
    <?php 
}
