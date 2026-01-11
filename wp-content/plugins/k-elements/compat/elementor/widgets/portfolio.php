<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Portfolio extends Widget_Base {

	public function get_name() {
		return 'kleo-portfolio';
	}

	public function get_title() {
		return __( 'Portfolio', 'k-elements' );
	}

	public function get_icon() {
		return 'fa fa-th-large';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_register_form',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
        );
        
        $this->add_control(
			'display_type',
			[
				'label'       => __( "Display Type", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'default'	=> 'Default',
                    'overlay'	=> 'Overlay'
				],
				'default'     => 'default',
			]
        );
        
        $this->add_control(
			'title_style',
			[
				'label'       => __( "Title style", 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'normal'	=> 'Normal',
                    'hover'	    => 'Shown only on item hover'
				],
				'default'     => 'default',
                'condition'   => [
                    'display_type' => 'overlay',
				],
			]
        );
        
        $this->add_control(
			'columns',
			[
				'label'       => __( "Number of items per row", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '4',
				'description' => __( "A number between 2 and 6", 'k-elements' )
			]
        );
        
        $this->add_control(
			'item_count',
			[
				'label'       => __( "Items to show", 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( "Leave blank for default value as in Settings - Reading", 'k-elements' )
			]
		);

        $this->add_control(
			'pagination',
			[
				'label'       => __( 'Display pagination', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'no'	=> 'No',
                    'yes'	=> 'Yes'
				],
				'default'     => 'no',
				'description' => __( 'Only if is the case it will be shown', 'k-elements' )
			]
        );

        $this->add_control(
			'filter',
			[
				'label'       => __( 'Show Category Filters', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'yes'	=> 'Yes',
                    'no'	=> 'No'
				],
				'default'     => 'yes',
			]
        );

        $this->add_control(
			'ajax',
			[
				'label'       => __( 'Enable AJAX', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
                    ''      => 'No',
					'yes'	=> 'Yes',
				],
				'default'     => '',
				'description' => __( 'Applies to filter and pagination. When clicking filters will send an AJAX request to get specific category items.Same goes for pagination' )
			]
        );

        $this->add_control(
			'excerpt',
			[
				'label'       => __( 'Display Subtitle for each item', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
                    'yes'	=> 'Yes',
                    'no'    => 'No'
				],
				'default'     => 'yes',
			]
        );

        $this->add_control(
			'image_size',
			[
				'label'       => __( 'Images size', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Leave blank to use default value 480x270. Expressed in pixels. Insert like: 400x400', 'k-elements' )
			]
        );
        
        $this->add_control(
			'category',
			[
				'label'       => __( 'Show from Category', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => kleo_get_category_list( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 2 ),
				'default'     => 'all',
				'description' => __( 'It will get also any subcategories', 'k-elements' )
			]
        );
        
        $exclude_cats = kleo_get_category_list_key_array( apply_filters( 'kleo_portfolio_cat_taxonomy', 'portfolio-category' ), 'term_id' );
        unset( $exclude_cats['all'] );
        //$exclude_cats = array_flip( $exclude_cats );

        $this->add_control(
            'exclude_categories',
            [
                'label'       => __( 'Exclude categories', 'k-elements' ),
                'type'        => Controls_Manager::SELECT,
                'options'     => $exclude_cats,
                'default'     => '',
            ]
        );
        
		$this->end_controls_section();

	}

	protected function render() {
		$values = $this->get_settings();

		$settings   = [
			'display_type',
			'title_style',
			'item_count',
			'columns',
            'pagination',
            'filter',
            'ajax',
            'excerpt',
            'image_size',
            'category',
            'exclude_categories'
		];
		$attributes = '';
		foreach ( $settings as $setting ) {
			$attributes .= ' ' . $setting . '="' . $values[ $setting ] . '"';
		}

		echo do_shortcode( '[kleo_portfolio' . $attributes . ']' );
	}
}
