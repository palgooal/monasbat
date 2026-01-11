<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Typography;
use Elementor\Widget_Base;
use Elementor\Core\Schemes;
use Elementor\Core\Schemes\Color;
use Elementor\Core\Schemes\Typography;
use Elementor\Scheme_Color;
use Elementor\Scheme_Typography;
use Elementor\Core\Kits\Documents\Tabs\Global_Colors;
use Elementor\Core\Kits\Documents\Tabs\Global_Typography;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Stats extends Widget_Base {

	public function get_name() {
		return 'kleo-stats';
	}

	public function get_title() {
		return __( 'Count Statistics', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-counter';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	/**
	 * Retrieve the list of scripts the counter widget depended on.
	 *
	 * Used to set scripts dependencies required to run the widget.
	 *
	 * @return array Widget scripts dependencies.
	 * @since 1.3.0
	 * @access public
	 *
	 */
	public function get_script_depends() {
		return [ 'jquery-numerator' ];
	}

	/**
	 * Get widget keywords.
	 *
	 * Retrieve the list of keywords the widget belongs to.
	 *
	 * @return array Widget keywords.
	 * @since 2.1.0
	 * @access public
	 *
	 */
	public function get_keywords() {
		return [ 'counter' ];
	}

	private function get_post_types() {
		$kleo_post_types         = [];
		$kleo_post_types['post'] = 'Posts';
		$kleo_post_types['page'] = 'Pages';

		$args = array(
			'public'   => true,
			'_builtin' => false
		);

		$types_return = 'objects'; // names or objects, note names is the default
		$post_types   = get_post_types( $args, $types_return );

		foreach ( $post_types as $post_type ) {
			$kleo_post_types[ $post_type->name ] = $post_type->labels->name;
		}

		return $kleo_post_types;
	}

	public function get_fields() {

		if ( ! function_exists( 'bp_is_active' ) ) {
			return [];
		}

		if ( function_exists( 'bp_is_active' ) && bp_is_active( 'xprofile' ) ) :
			if ( function_exists( 'bp_has_profile' ) ) :
				if ( bp_has_profile( 'hide_empty_fields=0' ) ) :
					while ( bp_profile_groups() ) :
						bp_the_profile_group();
						while ( bp_profile_fields() ) :
							bp_the_profile_field();

							$data[ bp_get_the_profile_field_id() ] = bp_get_the_profile_field_name();

						endwhile;
					endwhile;
				endif;
			endif;
		endif;

		return $data;
	}

	protected function register_controls() {

		$this->start_controls_section(
			'section_register_form',
			[
				'label' => __( 'Settings', 'k-elements' ),
			]
		);

		$this->add_control(
			'type',
			[
				'label'       => __( 'Statistic Type', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => [
					'posts'   => 'Posts',
					'members' => 'Members',
				],
				'default'     => 'posts',
				'description' => __( 'What type of statistics to show', 'k-elements' ),
			]
		);

		$this->add_control(
			'post_type',
			[
				'label'       => __( 'Post type', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_post_types(),
				'default'     => 'post',
				'description' => __( 'Enter a post type to count for. This should be something like: post, page or portfolio.', 'k-elements' ),
				'condition'   => [
					'type' => 'posts',
				],
			]
		);

		$this->add_control(
			'bp_field',
			[
				'label'       => __( 'Field Name', 'k-elements' ),
				'type'        => Controls_Manager::SELECT,
				'options'     => $this->get_fields(),
				'default'     => '',
				'description' => __( 'Profile field name', 'k-elements' ),
				'condition'   => [
					'type' => 'members',
				],
			]
		);

		$this->add_control(
			'bp_value',
			[
				'label'       => __( 'Field Value', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => __( 'Value to get same members by. Example: Rome if the Field name is City', 'k-elements' ),
				'condition'   => [
					'type'      => 'members',
					'bp_field!' => '',
				],
			]
		);

		$this->add_control(
			'bp_online',
			[
				'label'       => __( 'Online only', 'k-elements' ),
				'type'        => Controls_Manager::SWITCHER,
				'label_off'   => esc_html__( 'Off', 'k-elements' ),
				'label_on'    => esc_html__( 'On', 'k-elements' ),
				'default'     => '',
				'description' => __( 'Only include online members.', 'k-elements' ),
				'condition'   => [
					'type' => 'members',
				],
			]
		);

		$this->add_control(
			'starting_number',
			[
				'label'   => esc_html__( 'Starting Number', 'k-elements' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 0,
			]
		);

		$this->add_control(
			'prefix',
			[
				'label'       => esc_html__( 'Number Prefix', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 1,
			]
		);

		$this->add_control(
			'text_prefix',
			[
				'label'       => esc_html__( 'Text Prefix', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => 1,
			]
		);

		$this->add_control(
			'suffix',
			[
				'label'       => esc_html__( 'Number Suffix', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'Plus', 'k-elements' ),
			]
		);

		$this->add_control(
			'duration',
			[
				'label'   => esc_html__( 'Animation Duration', 'k-elements' ),
				'type'    => Controls_Manager::NUMBER,
				'default' => 2000,
				'min'     => 100,
				'step'    => 100,
			]
		);

		$this->add_control(
			'thousand_separator',
			[
				'label'     => __( 'Thousand Separator', 'k-elements' ),
				'type'      => Controls_Manager::SWITCHER,
				'default'   => 'yes',
				'label_on'  => __( 'Show', 'k-elements' ),
				'label_off' => __( 'Hide', 'k-elements' ),
			]
		);

		$this->add_control(
			'thousand_separator_char',
			[
				'label'     => __( 'Separator', 'k-elements' ),
				'type'      => Controls_Manager::SELECT,
				'condition' => [
					'thousand_separator' => 'yes',
				],
				'options'   => [
					''  => 'Default',
					'.' => 'Dot',
					' ' => 'Space',
				],
			]
		);

		$this->add_control(
			'title',
			[
				'label'       => esc_html__( 'Title', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'label_block' => true,
				'default'     => esc_html__( 'Cool Statistic', 'k-elements' ),
				'placeholder' => esc_html__( 'Cool Statistic', 'k-elements' ),
			]
		);

		$this->add_control(
			'view',
			[
				'label'   => esc_html__( 'View', 'k-elements' ),
				'type'    => Controls_Manager::HIDDEN,
				'default' => 'traditional',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_number',
			[
				'label' => esc_html__( 'Number', 'k-elements' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'number_color',
			[
				'label'     => esc_html__( 'Text Color', 'k-elements' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [
					'default' => Global_Colors::COLOR_PRIMARY,
				],
				'selectors' => [
					'{{WRAPPER}} .elementor-counter-number-wrapper' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'typography_number',
				'typography_type' => 'primary',
				'global'   => [
					'default' => Global_Typography::TYPOGRAPHY_PRIMARY,
				],
				'selector' => '{{WRAPPER}} .elementor-counter-number-wrapper',
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_title',
			[
				'label' => esc_html__( 'Title', 'k-elements' ),
				'tab'   => Controls_Manager::TAB_STYLE,
			]
		);

		$this->add_control(
			'title_color',
			[
				'label'     => esc_html__( 'Text Color', 'k-elements' ),
				'type'      => Controls_Manager::COLOR,
				'global'    => [
					'default' => Global_Colors::COLOR_PRIMARY,
				],
				'selectors' => [
					'{{WRAPPER}} .elementor-counter-title' => 'color: {{VALUE}};',
				],
			]
		);

		$this->add_group_control(
			Group_Control_Typography::get_type(),
			[
				'name'     => 'typography_title',
				'typography_type' => 'secondary',
				'global'   => [
					'default' => Global_Typography::TYPOGRAPHY_SECONDARY,
				],
				'selector' => '{{WRAPPER}} .elementor-counter-title',
			]
		);

		$this->end_controls_section();

	}

	protected function render() {

		$settings = $this->get_settings();

		if ( $settings['type'] === 'posts' ) {
			$data       = [
				'type' => 'post_type'
			];
			$attributes = '';
			foreach ( $data as $k => $setting ) {
				$attributes .= ' ' . $k . '="' . $settings[ $setting ] . '"';
			}

			$ending_number = do_shortcode( '[kleo_post_count' . $attributes . ']' );

		} else {
			$online = ( isset( $settings['bp_online'] ) && $settings['bp_online'] == 1 );

			if ( ! function_exists( 'bp_is_active' ) ) {
				echo esc_html__( 'BuddyPress needs to be installed', 'seeko' );

				return;
			}

			if ( $settings['bp_field'] && $settings['bp_value'] ) {
				$ending_number = kleo_bp_member_stats( $settings['bp_field'], $settings['bp_value'], $online );
			} else {
				//get total member count
				$ending_number = bp_get_total_member_count();
			}

			if ( (int) $settings['prefix'] > 0 ) {
				$ending_number = (int) $settings['prefix'] . $ending_number;
			}
		}

		$this->add_render_attribute( 'counter', [
			'class'         => 'elementor-counter-number',
			'data-duration' => $settings['duration'],
			'data-to-value' => $ending_number,
		] );

		if ( ! empty( $settings['thousand_separator'] ) ) {
			$delimiter = empty( $settings['thousand_separator_char'] ) ? ',' : $settings['thousand_separator_char'];
			$this->add_render_attribute( 'counter', 'data-delimiter', $delimiter );
		}
		?>
        <div class="elementor-element elementor-widget" data-element_type="counter.default">
            <div class="elementor-counter">
                <div class="elementor-counter-number-wrapper h1">
                    <span class="elementor-counter-number-prefix"><?php echo wp_kses_post( $settings['text_prefix'] ); ?></span>
                    <span <?php echo $this->get_render_attribute_string( 'counter' ); ?>>
						<?php echo wp_kses_post( $settings['starting_number'] ); ?>
					</span>
                    <span class="elementor-counter-number-suffix"><?php echo wp_kses_post( $settings['suffix'] ); ?></span>
                </div>
				<?php if ( $settings['title'] ) : ?>
                    <div class="elementor-counter-title"><?php echo wp_kses_post( $settings['title'] ); ?></div>
				<?php endif; ?>
            </div>
        </div>
		<?php
	}
}
