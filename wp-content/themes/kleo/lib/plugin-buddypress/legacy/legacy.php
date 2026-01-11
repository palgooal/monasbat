<?php

namespace Kleo\Buddypress;

class Legacy {


	public function __construct() {

		add_theme_support( 'buddypress-use-legacy' );

		/* Dynamic styles */
		require_once( KLEO_LIB_DIR . '/plugin-buddypress/legacy/dynamic.php' );

		require 'helpers.php';
	}
}

new Legacy();
