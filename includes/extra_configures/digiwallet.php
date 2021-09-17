<?php
/**
 * Digiwallet Payment Module for osCommerce
 *
 * @copyright Copyright 2013-2014 Yellow Melon
 * @copyright Portions Copyright 2013 Paul Mathot
 * @copyright Portions Copyright 2003 osCommerce
 * @license see LICENSE.TXT
 */


    define('FILENAME_DIGIWALLET', 'digiwallet.php');
    define('HTTP_SERVER_DIGIWALLET_ADMIN', '');
    define('MODULE_PAYMENT_DIGIWALLET_ERROR_REPORTING', 0);
    define('MODULE_PAYMENT_DIGIWALLET_MERCHANT_RETURN_URL', ((ENABLE_SSL == 'true') ? HTTPS_SERVER : HTTP_SERVER) . DIR_WS_CATALOG . 'digiwallet_callback.php');
