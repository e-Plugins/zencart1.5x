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

// This should be first line of the script:
$zco_notifier->notify('NOTIFY_HEADER_START_SITE_MAP');
define('NAVBAR_TITLE', 'Overschrijvingen payment');
/**
 * load language files
 */
require(DIR_WS_MODULES . zen_get_module_directory('require_languages.php'));
$breadcrumb->add(NAVBAR_TITLE);
// include template specific file name defines

// This should be last line of the script:
$zco_notifier->notify('NOTIFY_HEADER_END_SITE_MAP');
?>