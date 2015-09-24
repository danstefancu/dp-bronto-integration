<?php
/*
Plugin Name: DP Bronto Integration
Plugin URI: http://dreamproduction.com/wordpress-plugins/dp-bronto-integration
Description: A drop-and-go solution for syncing WordPress users with Bronto mailing lists. Uses user Groups for Bronto contact list mapping, defaults to first Bronto list created. It was developed for <a href="http://insideretail.com.au">Inside Retail</a> and currently powers all operations. Requires built-in PHP SOAP extension enabled.
Version: 1.0.0
Author: Dream Production
Author URI: http://dreamproduction.com
License: GPL2
*/

include 'class-dp-bronto.php';
include 'class-dp-bronto-session.php';

/**
 * Returns the main instance of class to prevent the need to use globals.
 * @return DP_Bronto
 */
function dp_bronto() {
	$instance_object = apply_filters( 'dp_bronto_object', 'DP_Bronto' );
	return $instance_object::instance();
}

// Fire plugin on init
add_action( 'init', 'dp_bronto', 21 );