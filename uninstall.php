<?php

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'wpplugin_message' );
delete_option( 'wpplugin_activity_log' );