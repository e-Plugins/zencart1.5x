<?php
/**
 * Digiwallet Payment Module for osCommerce
*
* @copyright Copyright 2013-2014 Yellow Melon
* @copyright Portions Copyright 2013 Paul Mathot
* @copyright Portions Copyright 2003 osCommerce
* @license see LICENSE.TXT
*/

!defined('FILENAME_DIGIWALLET_TRANSACTIONS') && define('FILENAME_DIGIWALLET_TRANSACTIONS', 'digiwallet_transactions.php');

!defined('TABLE_DIGIWALLET_TRANSACTIONS') && define('TABLE_DIGIWALLET_TRANSACTIONS', DB_PREFIX . 'digiwallet_transactions');
!defined('TABLE_DIGIWALLET_DIRECTORY') && define('TABLE_DIGIWALLET_DIRECTORY', DB_PREFIX . 'digiwallet_directory');
!defined('TABLE_DIGIWALLET_REFUND') && define('TABLE_DIGIWALLET_REFUND', DB_PREFIX . 'digiwallet_refund');