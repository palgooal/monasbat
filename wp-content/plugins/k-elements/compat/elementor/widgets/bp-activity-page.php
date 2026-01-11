<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class BpActivityPage extends Widget_Base {

	public function get_name() {
		return 'kleo-activity-page';
	}

	public function get_title() {
		return __( 'Activity Page', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-list-ul';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function render() {
	
		echo do_shortcode( '[kleo_bp_activity_page]' );
	}
}
