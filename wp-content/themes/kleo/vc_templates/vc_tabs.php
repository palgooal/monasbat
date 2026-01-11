<?php
$title = $interval = $el_class = '';
extract( shortcode_atts( array(
	'title'       => '',
	'type'        => 'tabs',
	'active_tab'  => '1',
	'style'       => 'default',
	'style_pills' => 'square',
	'align'       => '',
	'margin_top'  => '',
	'interval'    => 0,
	'position'    => '',
	'no_tab_drop' => '',
	'el_class'    => ''
), $atts));

if ( '' !== $el_class ) {
	$el_class = ' ' . str_replace( '.', '', $el_class );
}

$element = 'kleo-tabs';

if ( isset( $this ) ) {
	$shortcode = $this->shortcode;
}

if ( 'vc_tour' == $shortcode ) {
	$element = 'wpb_tour';
	$type    = 'tab';
}

$align = $align != "" ? " tabs-" . $align : "";


if ( $type == 'pills' ) {
	$style = $style_pills;
}

$style_att = '';
if ( $margin_top != '' ) {
	$style_att .= 'margin-top:' . (int) $margin_top . 'px;';
}

// Extract tab titles
//preg_match_all( '/vc_tab title="([^\"]+)"(\stab_id\=\"([^\"]+)\"){0,1}(\sicon\=\"([^\"]+)\")*/i', $content, $matches, PREG_OFFSET_CAPTURE );
preg_match_all( '/(?:kleo_tab|vc_tab)([^\]]+)/i', $content, $matches, PREG_OFFSET_CAPTURE );

$tab_titles = [];

/**
 * vc_tabs
 *
 */
$i = 1;
global $kleo_tab_active;

$active_tab = (int) $active_tab != 0 ? $active_tab : 1;

if ( isset( $matches[0] ) ) {
	$tab_titles = $matches[0];
}

$css_class = apply_filters( 'vc_shortcodes_css_class', trim( $element . ' tabbable ' . $el_class ), $shortcode, $atts );

if ( $position != '' ) {
	$css_class .= ' pos-' . $position;
}
?>

<div class="<?php echo esc_attr($css_class); ?>" style="<?php echo esc_attr($style_att); ?>" data-interval="<?php echo esc_attr($interval); ?>">
    <ul class="nav nav-<?php echo esc_attr($type); ?> responsive-<?php echo esc_attr($type); ?> <?php echo esc_attr($type); ?>-style-<?php echo esc_attr($style . $align . ($no_tab_drop != '' ? ' no-tabdrop' : '')); ?>">
        <?php
        foreach ( $tab_titles as $tab ) :
            $tab_atts = shortcode_parse_atts( $tab[0] );

            $iconClass = '';
            if ( isset( $tab_atts['icon'] ) && $tab_atts['icon'] ) {
                $iconClass = 'icon-' . str_replace( "icon-", "", $tab_atts['icon'] );
            } elseif ( isset( $tab_atts['icon_type'] ) ) {
                $iconClass = isset( $tab_atts[ "icon_" . $tab_atts['icon_type'] ] ) ? $tab_atts[ "icon_" . $tab_atts['icon_type'] ] : "";
            }
            if ( isset( $tab_atts['title'] ) ) :
                $tabid = ( ( isset( $tab_atts['tab_id'] ) && $tab_atts['tab_id'] != __( "Tab", "js_composer" ) ) ? $tab_atts['tab_id'] : esc_attr( str_replace( "%", "", sanitize_title_with_dashes( $tab_atts['title'] ) ) ) );

                $icon = $iconClass != '' ? '<i class="' . esc_attr( $iconClass ) . '"></i> ' : '';

                ?>
                <li<?php echo (int)$active_tab == $i ? ' class="active"' : ''; ?>>
                    <a href="#tab-<?php echo esc_attr($tabid); ?>" data-toggle="tab" onclick="return false;">
                        <?php
						if ($iconClass != '') {
							?>
							<i class="<?php echo esc_attr($iconClass); ?>"></i>
							<?php
						}
						echo wp_kses_post($tab_atts['title']); ?>
                    </a>
                </li>
                <?php
                if ( $i == $active_tab ) {
                    $kleo_tab_active = $tabid;
                }
            endif;
            $i++;
        endforeach;
        ?>
    </ul>
    <div class="tab-content">
        <?php echo kleo_remove_wpautop( $content ); ?>

		<?php if ( 'vc_tour' == $shortcode ) : ?>
			<div class="wpb_tour_next_prev_nav clearfix">
				<small>
					<span class="tour_prev_slide">
						<a href="#" title="<?php esc_attr_e('Previous section', 'kleo'); ?>"><?php esc_html_e('Previous section', 'kleo'); ?></a>
					</span> | 
					<span class="tour_next_slide">
						<a href="#" title="<?php esc_attr_e('Next section', 'kleo'); ?>"><?php esc_html_e('Next section', 'kleo'); ?></a>
					</span>
				</small>
			</div>
    	<?php endif; ?>
	</div>
</div>
