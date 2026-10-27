<?php
/**
 * Removes the plugin settings. Order notes and order meta are kept: they are the store's payment records.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

delete_option( 'woocommerce_cigalpay_settings' );
