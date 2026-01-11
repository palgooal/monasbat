<?php
/*
 * Functions required for shortcodes to work without KLEO theme
*/

if ( ! defined( 'KLEO_DOMAIN' ) ) {
	define( 'KLEO_DOMAIN', 'kleo' );
}

//Theme options
if ( ! function_exists( 'sq_option' ) ) {

	//array with theme options
	global $kleo_options;
	$kleo_options = get_option( 'kleo_' . KLEO_DOMAIN );

	/**
	 * Function to get options in front-end
	 *
	 * @param int $option The option we need from the DB
	 * @param string $default If $option doesn't exist in DB return $default value
	 *
	 * @return string|array
	 */
	function sq_option( $option = false, $default = false ) {
		$output_data = false;

		if ( $option === false ) {
			return $output_data;
		}

		global $kleo_options;

		if ( isset( $kleo_options[ $option ] ) && $kleo_options[ $option ] !== '' ) {
			$output_data = $kleo_options[ $option ];
		} else {
			$output_data = $default;
		}

		return apply_filters( 'sq_option', $output_data, $option );
	}
}

add_action( 'init', 'kleo_options_filter_manage' );

function kleo_options_filter_manage() {
	global $kleo_options;
	$kleo_options = apply_filters( 'kleo_options', $kleo_options );
}

if ( ! function_exists( 'sq_option_url' ) ) {
	/**
	 * Function to get url options in front-end
	 *
	 * @param int $option The option we need from the DB
	 * @param string $default If $option doesn't exist in DB return $default value
	 *
	 * @return string
	 */
	function sq_option_url( $option = false, $default = false ) {
		if ( $option === false ) {
			return false;
		}
		global $kleo_options;

		if ( isset( $kleo_options[ $option ]['url'] ) && $kleo_options[ $option ]['url'] !== '' ) {
			return $kleo_options[ $option ]['url'];
		} else {
			return $default;
		}
	}
}

if ( ! function_exists( 'kleo_has_shortcode' ) ) {
	function kleo_has_shortcode( $shortcode = '', $post_id = null ) {

		if ( ! $post_id ) {
			if ( ! is_singular() ) {
				return false;
			}
			$post_id = get_the_ID();
		}

		if ( is_page() || is_single() ) {
			$current_post = get_post( $post_id );

			if ( post_password_required( $current_post ) ) {
				return false;
			}

			//remove_filter( 'the_content', 'do_shortcode', 11 );
			//$post_content  = apply_filters( 'the_content', $current_post->post_content );
			$post_content = $current_post->post_content;
			//add_filter( 'the_content', 'do_shortcode', 11 );

			$found = false;

			if ( ! $shortcode ) {
				return $found;
			}

			if ( stripos( $post_content, '[' . $shortcode ) !== false ) {
				$found = true;
			}

			return $found;
		} else {
			return false;
		}
	}
}


//Fontello icons array
if ( ! function_exists( 'kleo_icons_array' ) ) {
	function kleo_icons_array( $prefix = '', $before = [ '' ] ) {

		// Get any existing copy of our transient data
		$transient_name = 'kleo_font_icons_' . $prefix . implode( '', $before );

		if ( false === ( $icons = get_transient( $transient_name ) ) ) {

			// It wasn't there, so regenerate the data and save the transient
			$icons = $before;

			if ( 1 == sq_option( 'full_fontawesome', 0 ) ) {
				$icons_json = svq_fs_get_contents( THEME_DIR . '/assets/font-all/font/config.json' );
			} else {
				$icons_json = svq_fs_get_contents( THEME_DIR . '/assets/font/config.json' );
			}

			if ( is_child_theme() && file_exists( CHILD_THEME_DIR . '/assets/css/fontello.css' ) ) {
				$icons_json = svq_fs_get_contents( CHILD_THEME_DIR . '/assets/config.json' );
			}

			if ( $icons_json ) {
				$arr = json_decode( $icons_json, true );
				foreach ( $arr['glyphs'] as $icon ) {
					if ( ( isset( $icon['selected'] ) && $icon['selected'] == true ) || ! isset( $icon['selected'] ) ) {
						$icons[ $prefix . $icon['css'] ] = $icon['css'];
					}
				}
				asort( $icons );
			}

			// set transient for one day
			set_transient( $transient_name, $icons, 86400 );
		}

		return $icons;
	}
}

/* Get User online */
if ( ! function_exists( 'kleo_is_user_online' ) ):
	/**
	 * Check if a Buddypress member is online or not
	 *
	 * @param integer $user_id
	 * @param integer $time
	 *
	 * @return boolean
	 * @global object $wpdb
	 *
	 */
	function kleo_is_user_online( $user_id, $time = 5 ) {
		$current_time  = bp_core_current_time( true, 'timestamp' );
		$last_activity = bp_get_user_last_activity( $user_id );
		$still_online  = strtotime( '+' . $time . ' minutes', strtotime( $last_activity ) );

		// Has the user been active recently?
		return $current_time <= $still_online;
	}
endif;


if ( ! function_exists( 'kleo_get_online_status' ) ) :
	function kleo_get_online_status( $user_id ) {
		$output = '';
		if ( kleo_is_user_online( $user_id ) ) {
			$output .= '<span class="kleo-online-status high-bg"></span>';
		} else {
			$output .= '<span class="kleo-online-status"></span>';
		}

		return $output;
	}
endif;


/**
 * Render the html to show if a user is online or not
 */
if ( ! function_exists( 'kleo_online_status' ) ) :
	function kleo_online_status( $user_id ) {
		echo kleo_get_online_status( $user_id );
	}
endif;


if ( ! function_exists( 'get_cfield' ) ) {

	function get_cfield( $meta = null, $id = null ) {
		if ( $meta === null ) {
			return false;
		}

		if ( ! $id && ! in_the_loop() && is_home() && get_option( 'page_for_posts' ) ) {
			$id = get_option( 'page_for_posts' );
		}

		if ( $id === null ) {
			$id = get_the_ID();
		}

		if ( ! $id ) {
			return false;
		}

		return get_post_meta( $id, '_kleo_' . $meta, true );
	}
}

/*
 * Echo the custom field
 */
if ( ! function_exists( 'the_cfield' ) ) {
	function the_cfield( $meta = null, $id = null ) {
		echo get_cfield( $meta, $id );
	}
}


if ( ! function_exists( 'kleo_get_img_overlay' ) ) {
	function kleo_get_img_overlay() {
		global $kleo_config;

		if ( isset( $kleo_config['image_overlay'] ) ) {
			return $kleo_config['image_overlay'];
		}

		return '';
	}
}

if ( ! function_exists( 'kleo_parse_multi_attribute' ) ) {
	/**
	 * Parse string like "title:Hello world|weekday:Monday" to array('title' => 'Hello World', 'weekday' => 'Monday')
	 *
	 * @param $value
	 * @param array $default
	 *
	 * @return array
	 */
	function kleo_parse_multi_attribute( $value, $default = array() ) {
		$result       = $default;
		$params_pairs = explode( '|', $value );
		if ( ! empty( $params_pairs ) ) {
			foreach ( $params_pairs as $pair ) {
				$param = preg_split( '/\:/', $pair );
				if ( ! empty( $param[0] ) && isset( $param[1] ) ) {
					$result[ $param[0] ] = rawurldecode( $param[1] );
				}
			}
		}

		return $result;
	}
}

if ( ! function_exists( 'kleo_get_post_media' ) ) {
	/**
	 * Return post media by format
	 *
	 * @param $post_format
	 * @param $options
	 *
	 * @return string
	 *
	 * @since 3.0
	 */
	function kleo_get_post_media( $post_format = 'standard', $options = array() ) {

		global $kleo_config;

		if ( isset( $options['icons'] ) && $options['icons'] ) {
			$icons = true;
		} else {
			$icons = false;
		}

		if ( isset( $options['media_width'] ) && isset( $options['media_height'] ) ) {
			$media_width  = $options['media_width'];
			$media_height = $options['media_height'];
		} else {
			$media_width  = $kleo_config['post_gallery_img_width'];
			$media_height = $kleo_config['post_gallery_img_height'];
		}

		$output = '';

		switch ( $post_format ) {

			case 'video':

				//oEmbed video
				$video = get_cfield( 'embed' );
				// video bg self hosted
				$bg_video_args = array();
				$k_video       = '';

				if ( get_cfield( 'video_mp4' ) ) {
					$bg_video_args['mp4'] = get_cfield( 'video_mp4' );
				}
				if ( get_cfield( 'video_ogv' ) ) {
					$bg_video_args['ogv'] = get_cfield( 'video_ogv' );
				}
				if ( get_cfield( 'video_webm' ) ) {
					$bg_video_args['webm'] = get_cfield( 'video_webm' );
				}

				if ( ! empty( $bg_video_args ) ) {
					$attr_strings = array(
						'preload="none"'
					);

					if ( get_cfield( 'video_poster' ) ) {
						$attr_strings[] = 'poster="' . get_cfield( 'video_poster' ) . '"';
					}

					$k_video .= '<div class="kleo-video-wrap"><video ' . join( ' ', $attr_strings ) . ' controls="controls" class="kleo-video" style="width: 100%; height: 100%;">';

					$source = '<source type="%s" src="%s" />';
					foreach ( $bg_video_args as $video_type => $video_src ) {
						$video_type = wp_check_filetype( $video_src, wp_get_mime_types() );
						$k_video    .= sprintf( $source, $video_type['type'], esc_url( $video_src ) );
					}

					$k_video .= '</video></div>';

					$output .= $k_video;
				} // oEmbed
				elseif ( ! empty( $video ) ) {
					global $wp_embed;
					$output .= apply_filters( 'kleo_oembed_video', $video );
				}

				break;

			case 'audio':

				$audio = get_cfield( 'audio' );

				if ( ! empty( $audio ) ) {
					$output .=
						'<div class="post-audio">' .
						'<audio preload="none" class="kleo-audio" id="audio_' . get_the_ID() . '" style="width:100%;" src="' . $audio . '"></audio>' .
						'</div>';
				}
				break;

			case 'gallery':

				$slides = get_cfield( 'slider' );

				$output .= '<div class="kleo-banner-slider">'
				           . '<div class="kleo-banner-items" >';

				if ( $slides ) {
					foreach ( $slides as $slide ) {
						if ( $slide ) {
							$image = aq_resize( $slide, $media_width, $media_height, true, true, true );
							//small hack for non-hosted images
							if ( ! $image ) {
								$image = $slide;
							}
							$output .= '<article>
								<a href="' . $slide . '" data-rel="prettyPhoto[inner-gallery]">
									<img src="' . $image . '" alt="' . get_the_title() . '">'
							           . kleo_get_img_overlay()
							           . '</a>
							</article>';
						}
					}
				}

				$output .= '</div>'
				           . '<a href="#" class="kleo-banner-prev"><i class="icon-angle-left"></i></a>'
				           . '<a href="#" class="kleo-banner-next"><i class="icon-angle-right"></i></a>'
				           . '<div class="kleo-banner-features-pager carousel-pager"></div>'
				           . '</div>';

				break;


			case 'aside':
				if ( $icons ) {
					$output .= '<div class="post-format-icon"><i class="icon icon-doc"></i></div>';
				}
				break;

			case 'link':
				if ( $icons ) {
					$output .= '<div class="post-format-icon"><i class="icon icon-link"></i></div>';
				}
				break;

			case 'quote':
				if ( $icons ) {
					$output .= '<div class="post-format-icon"><i class="icon icon-quote-right"></i></div>';
				}
				break;

			case 'image':
			default:
				if ( kleo_get_post_thumbnail_url() != '' ) {
					$output .= '<div class="post-image">';

					$img_url = kleo_get_post_thumbnail_url();
					$image   = aq_resize( $img_url, $media_width, null, true, true, true );
					if ( ! $image ) {
						$image = $img_url;
					}
					$output .= '<a href="' . get_permalink() . '" class="element-wrap">'
					           . '<img src="' . $image . '" alt="' . get_the_title() . '">'
					           . kleo_get_img_overlay()
					           . '</a>';

					$output .= '</div><!--end post-image-->';
				} elseif ( $icons ) {
					$post_icon = $post_format == 'image' ? 'picture' : 'doc';
					$output    .= '<div class="post-format-icon"><i class="icon icon-' . $post_icon . '"></i></div>';
				}

				break;
		}

		return $output;
	}
}
if ( ! function_exists( 'kleo_set_default_unit' ) ) {
	function kleo_set_default_unit( $text, $default = 'px' ) {
		return preg_match( '/(px|em|\%|pt|cm|vh|vw)$/', $text ) ? $text : $text . $default;
	}
}


if ( ! function_exists( 'kleo_excerpt' ) ) {
	function kleo_excerpt( $limit = 20, $words = true ) {

		$excerpt_initial = get_the_excerpt();
		if ( $excerpt_initial == '' ) {
			$excerpt_initial = get_the_content();
		}
		$excerpt_initial = preg_replace( '`\[[^\]]*\]`', '', $excerpt_initial );
		$excerpt_initial = strip_tags( $excerpt_initial );

		if ( $words ) {
			$excerpt = explode( ' ', $excerpt_initial, $limit );
			if ( count( $excerpt ) >= $limit ) {
				array_pop( $excerpt );
				$excerpt = implode( " ", $excerpt ) . '...';
			} else {
				$excerpt = implode( " ", $excerpt ) . '';
			}
		} else {
			$excerpt = $excerpt_initial;
			$excerpt = substr( $excerpt, 0, $limit ) . ( strlen( $excerpt ) > $limit ? '...' : '' );
		}

		return '<p>' . $excerpt . '</p>';
	}
}


/**
 * GET CUSTOM POST TYPE TAXONOMY LIST
 */
if ( ! function_exists( 'kleo_get_category_list' ) ) {
	function kleo_get_category_list( $category_name, $filter = 0, $category_child = "" ) {

		if ( $category_child != "" && $category_child != "All" ) {

			$childcategory = get_term_by( 'slug', $category_child, $category_name );
			$get_category  = get_categories( array(
				'taxonomy' => $category_name,
				'child_of' => $childcategory->term_id
			) );
			$category_list = array( '0' => 'All' );

			foreach ( $get_category as $category ) {
				if ( isset( $category->cat_name ) ) {
					$category_list[] = $category->slug;
				}
			}

			return $category_list;

		} else {
			if ( $filter === 0 ) {

				$get_category  = get_categories( array( 'taxonomy' => $category_name ) );
				$category_list = array( '0' => 'All' );

				foreach ( $get_category as $category ) {
					if ( isset( $category->slug ) ) {
						$category_list[] = $category->slug;
					}
				}

				return $category_list;

			} elseif ( $filter === 1 ) {
				$get_category  = get_categories( array( 'taxonomy' => $category_name ) );
				$category_list = array( '0' => 'All' );

				foreach ( $get_category as $category ) {
					if ( isset( $category->cat_name ) ) {
						$category_list[] = $category->cat_name;
					}
				}

				return $category_list;
			} else {
				$get_category  = get_categories( array( 'taxonomy' => $category_name ) );
				$category_list = array( '0' => 'All' );

				foreach ( $get_category as $category ) {
					if ( isset( $category->cat_name ) ) {
						$category_list[ $category->cat_name ] = $category->slug;
					}
				}

				return $category_list;
			}

		}
	}
}

if ( ! function_exists( 'kleo_get_category_list_key_array' ) ) {
	function kleo_get_category_list_key_array( $category_name, $key = 'slug' ) {

		$get_category  = get_categories( array( 'taxonomy' => $category_name ) );
		$category_list = array( 'all' => 'All' );

		foreach ( $get_category as $category ) {
			if ( isset( $category->{$key} ) ) {
				$category_list[ $category->{$key} ] = $category->cat_name;
			}
		}

		return $category_list;
	}
}
