<?php
/**
 * Digiwallet Payment Module for ZenCart
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 Zencart
 * @license see LICENSE.TXT
 */
require_once "digiwallet/digiwallet.client.php";

$ywincludefile = realpath(dirname(__FILE__) . '/../../extra_datafiles/digiwallet.php');
require_once ($ywincludefile);

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/../../languages/' . $langDir . '/modules/payment/digiwallet_a01_ide.php');
require_once ($ywincludefile);

$ywincludefile = realpath(dirname(__FILE__) . '/digiwallet/digiwallet.class.php');
require_once ($ywincludefile);

class digiwallet_a05_eps extends digiwalletClient
{

    protected $tpPaymentMethodId = 'EPS';
    protected $tpPaymentMethodName = 'EPS';

    /**
     *
     * @method digiwallet inits the module
     */
    public function digiwallet_a05_eps()
    {
        parent::digiwallet();
    }
}
