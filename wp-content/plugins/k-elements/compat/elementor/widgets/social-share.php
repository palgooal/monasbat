<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class SocialShare extends Widget_Base {

	public function get_name() {
		return 'kleo-social-share';
	}

	public function get_title() {
		return __( 'Kleo Social Sharing', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-share-alt';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function render() {
	
		echo do_shortcode( '[kleo_social_share]' );
	}
}
