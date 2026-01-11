<?php

namespace K_Elements\Compat\Elementor\Widgets;

use Elementor\Controls_Manager;
use Elementor\Group_Control_Image_Size;
use Elementor\Repeater;
use Elementor\Utils;
use Elementor\Widget_Base;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
} // Exit if accessed directly

class Testimonials extends Widget_Base {

	public function get_name() {
		return 'kleo-testimonials';
	}

	public function get_title() {
		return __( 'Testimonials', 'k-elements' );
	}

	public function get_icon() {
		return 'eicon-testimonial-carousel';
	}

	public function get_categories() {
		return [ 'kleo-elements' ];
	}

	public function get_testimonial_tags() {
		$testimonial_tags = [];

		$defined_tags = get_terms( 'testimonials-tag' );
		if ( is_array( $defined_tags ) && ! empty( $defined_tags ) ) {

			foreach ( $defined_tags as $tag ) {
				$testimonial_tags[ $tag->name ] = $tag->term_id;
			}
		}

		return $testimonial_tags;
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
				'label'   => __( 'Type', 'k-elements' ),
				'type'    => Controls_Manager::SELECT,
				'options' => [
					'simple'   => 'Simple',
					'carousel' => 'Carousel',
					'boxed'    => 'Boxed with 5 star ratings'
				],
				'default' => 'simple',
			]
		);


		$this->add_control(
			'min_items',
			[
				'label'     => __( 'Minimum items to show', 'k-elements' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '1',
				'condition' => [
					'type' => 'carousel',
				],
			]
		);

		$this->add_control(
			'max_items',
			[
				'label'     => __( 'Maximum items to show', 'k-elements' ),
				'type'      => Controls_Manager::TEXT,
				'default'   => '1',
				'condition' => [
					'type' => 'carousel',
				],
			]
		);

		$this->add_control(
			'speed',
			[
				'label'       => __( 'Speed between slides', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '5000',
				'condition'   => [
					'type' => 'carousel',
				],
				'description' => 'In milliseconds. Default is 5000 milliseconds, meaning 5 seconds.'
			]
		);

		$this->add_control(
			'height',
			[
				'label'       => __( 'Elements height', 'k-elements' ),
				'type'        => Controls_Manager::TEXT,
				'default'     => '',
				'description' => 'Force a height on all elements. Expressed in pixels, eq: 300 will represent 300px.',
				'condition' => [
					'type' => 'carousel',
				],
			]
		);

		$this->end_controls_section();

		$this->start_controls_section(
			'section_testimonials',
			[
				'label' => __( 'Testimonials', 'k-elements' ),
			]
		);

		$repeater = new Repeater();

		$repeater->add_control(
			'name',
			[
				'label' => __( 'Name', 'elementor' ),
				'type' => Controls_Manager::TEXT,
				'dynamic' => [
					'active' => true,
				],
				'default' => 'John Doe',
			]
		);

		$repeater->add_control(
			'company',
			[
				'label' => __( 'Company/Job', 'elementor' ),
				'type' => Controls_Manager::TEXT,
				'dynamic' => [
					'active' => true,
				],
				'default' => 'Designer',
			]
		);

		$repeater->add_control(
			'content',
			[
				'label' => __( 'Content', 'elementor' ),
				'type' => Controls_Manager::WYSIWYG,
				'default' => 'Lorem ipsum dolor sit amet, consectetur adipiscing elit. Ut elit tellus, luctus nec ullamcorper mattis, pulvinar dapibus leo.',
			]
		);

		$repeater->add_control(
			'image',
			[
				'label' => __( 'Choose Image', 'elementor' ),
				'type' => Controls_Manager::MEDIA,
				'dynamic' => [
					'active' => true,
				],
				'default' => [
					'url' => Utils::get_placeholder_image_src(),
				],
                'description' => 'Shows only of Carousel type'
			]
		);

		$repeater->add_group_control(
			Group_Control_Image_Size::get_type(),
			[
				'name' => 'thumbnail', // Usage: `{name}_size` and `{name}_custom_dimension`, in this case `thumbnail_size` and `thumbnail_custom_dimension`.
				'separator' => 'none',
			]
		);



		$this->add_control(
			'testimonials',
			[
				'label' => __( 'Testimonials', 'elementor' ),
				'type' => Controls_Manager::REPEATER,
				'fields' => $repeater->get_controls(),

				'title_field' => '{{{ name }}}',
			]
		);

		$this->end_controls_section();

	}

	protected function render() {
		$settings = $this->get_settings_for_display();

		switch ( $settings['type'] ) {

			//Carousel Testimonials display
			case 'carousel':

				$data_attr = '';
				$data_attr .= ' data-min-items="' . $settings['min_items'] . '"';
				$data_attr .= ' data-max-items="' . $settings['max_items'] . '"';
				$data_attr .= ' data-speed="' . $settings['speed'] . '"';

				if ( $settings['height'] !== '' ) {
					$data_attr .= ' data-items-height="' . $settings['height'] . '"';
				} else {
					$data_attr .= ' data-items-height="variable"';
				}
				?>

				<div class="kleo-carousel-container kleo-testimonials">
					<div class="kleo-carousel-items kleo-carousel-testimonials" data-scroll-fx="crossfade" data-autoplay="true"
						<?php echo $data_attr; ?>>

						<ul class="kleo-carousel">

							<?php foreach ( $settings['testimonials'] as $k => $testimonial ) : ?>

							<li>
								<div class="testimonial-image">
									<?php
									$image_html = Group_Control_Image_Size::get_attachment_image_html( $testimonial, 'thumbnail', 'image');
									echo $image_html;
									?>
								</div>
								<div class="testimonial-content">
									<?php echo esc_html( $testimonial['content'] ); ?>
								</div>
								<div class="testimonial-meta">
									<strong class="testimonial-name"><?php echo $testimonial['name'] ?></strong>
									<span class="testimonial-subtitle"><?php echo $testimonial['company']; ?></span>
								</div>
							</li>

							<?php endforeach; ?>

						</ul><!-- end kleo-carousel -->
					</div><!-- end kleo-carousel-items -->
					<div class="kleo-carousel-pager carousel-pager"></div>
				</div><!-- end kleo-testimonials carousel-container -->

				<?php
				break;

			//Regular Testimonials display
			case 'boxed':
				?>
				<div class="kleo-testimonials starred">

				<?php foreach ( $settings['testimonials'] as $k => $testimonial ) : ?>

					<figure class="callout-blockquote light">
						<blockquote>
							<div class="rating-stars">
								<i class="icon icon-star"></i>
								<i class="icon icon-star"></i>
								<i class="icon icon-star"></i>
								<i class="icon icon-star"></i>
								<i class="icon icon-star"></i>
							</div>
							<?php echo esc_html( $testimonial['content'] ); ?>
						</blockquote>
						<figcaption>
						<span class="title-name"><?php echo $testimonial['name'] ?></span><br>
							<span><?php echo $testimonial['company']; ?></span><br>
						</figcaption>
					</figure>

				<?php endforeach; ?>

                </div><!-- end kleo-testimonials -->

				<?php
				break;

			//Regular Testimonials display
			default:
				?>

				<div class="kleo-testimonials">

				<?php foreach ( $settings['testimonials'] as $k => $testimonial ) : ?>

						<figure class="callout-blockquote light">
							<blockquote>
								<?php echo esc_html( $testimonial['content'] ); ?>
							</blockquote>
							<figcaption><span class="title-name"><?php echo $testimonial['name'] ?></span><br>
								<span><?php echo $testimonial['company'] ?></span><br>
							</figcaption>
						</figure>

				<?php endforeach; ?>

				</div><!-- end kleo-testimonials -->

				<?php
				break;
		}

	}

}
