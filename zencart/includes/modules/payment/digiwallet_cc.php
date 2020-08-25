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

class digiwallet_cc extends digiwalletBase
{
    protected $tpPaymentMethodId = 'CC';
    protected $tpPaymentMethodName = 'credit_card';
    
    public function digiwallet_cc()
    {
        parent::digiwallet();
    }
}
