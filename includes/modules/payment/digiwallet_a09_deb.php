<?php
/**
 * Digiwallet Payment Module for Zencart
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 Zencart
 * @license   see LICENSE.TXT
 */
require_once "digiwallet/digiwallet.php";

$ywincludefile = realpath(dirname(__FILE__) . '/../../extra_datafiles/digiwallet.php');
require_once $ywincludefile;

$availableLanguages = array("dutch","english");
$langDir = (isset($_SESSION["language"]) && in_array($_SESSION["language"], $availableLanguages)) ? $_SESSION["language"] : "dutch";

$ywincludefile = realpath(dirname(__FILE__) . '/../../languages/' . $langDir . '/modules/payment/digiwallet_a01_ide.php');
require_once $ywincludefile;

$ywincludefile = realpath(dirname(__FILE__) . '/digiwallet/digiwallet.class.php');
require_once $ywincludefile;

class digiwallet_a09_deb extends digiwalletBase
{

    protected $tpPaymentMethodId = 'DEB';
    protected $tpPaymentMethodName = 'sofort';
    
    public function digiwallet_a09_deb()
    {
        parent::digiwallet();
    }

    /**
     * to override and create country list
     */
    public function selection()
    {
        global $order;
        
        $directory = $this->getDirectory();
        if (! is_null($directory)) {
            $issuers = array();
            $issuerType = "Short";
            
            $issuers[] = array(
                'id' => "-1",
                'text' => MODULE_PAYMENT_DIGIWALLET_DEB_TEXT_ISSUER_SELECTION
            );
            
            foreach ($directory as $issuer) {
                if ($issuer->issuerList != $issuerType) {
                    $issuerType = $issuer->issuerList;
                }
                $issuers[] = array(
                    'id' => str_replace($this->tpPaymentMethodId, "", $issuer->issuerID),
                    'text' => $issuer->issuerName
                );
            }
            
            $selection = array(
                'id' => $this->code,
                'module' => $this->title,
                'fields' => array(
                    array(
                        'title' => zen_image('images/icons/' . $this->tpPaymentMethodId . '_60.png', '', '', '', 'align=absmiddle'), // .MODULE_PAYMENT_DIGIWALLET_TEXT_ISSUER_SELECTION
                        'field' => zen_draw_pull_down_menu('countryID', $issuers, '', 'onChange="$(\'input[type=radio][name=payment][value=' . $this->code . ']\').prop(\'checked\', true);"')
                    )
                ),
                'issuers' => $issuers
            );
        } else {
            $selection = array(
                'id' => $this->code,
                'module' => $this->title . MODULE_PAYMENT_DIGIWALLET_DEB_TEXT_INFO,
                'fields' => array(
                    array(
                        'title' => MODULE_PAYMENT_DIGIWALLET_DEB_TEXT_ISSUER_SELECTION,
                        'field' => "Could not get country. "
                    )
                ),
                'issuers' => []
            );
        }
        return $selection;
    }

    /**
     * pre_confirmation_check
     */
    public function pre_confirmation_check()
    {
        global $messageStack;
    
        if (! isset($_POST['countryID']) || ($_POST['countryID'] < 0)) {
            $messageStack->add_session('checkout_payment', MODULE_PAYMENT_DIGIWALLET_DEB_ERROR_TEXT_NO_ISSUER_SELECTED);
            zen_redirect(zen_href_link(FILENAME_CHECKOUT_PAYMENT, 'error_message=' . urlencode(MODULE_PAYMENT_DIGIWALLET_DEB_ERROR_TEXT_NO_ISSUER_SELECTED), 'SSL', true, false));
        }
    }

    /**
     * make hidden value for payment system
     */
    public function process_button()
    {
        $process_button = zen_draw_hidden_field('countryID', $_POST['countryID']) . MODULE_PAYMENT_DIGIWALLET_EXPRESS_TEXT;
        
        if (defined('BUTTON_CHECKOUT_DIGIWALLET_ALT')) {
            $process_button .= zen_image_submit('digiwallet.gif', BUTTON_CHECKOUT_DIGIWALLET_ALT);
        }
        return $process_button;
    }

}
