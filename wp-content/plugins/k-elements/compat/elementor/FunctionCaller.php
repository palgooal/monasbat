<?php

namespace K_Elements\Compat\Elementor;

use K_Elements\Compat\Elementor\Traits\Wp_Trait;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

class FunctionCaller {
	use Wp_Trait;
}
