<?php
$default_atts = [
	'tab_id'    => '',
	'title'     => '',
	'icon'      => '',
	'icon_type' => 'fontello',
];

extract( shortcode_atts( $default_atts, $atts ) );

$css_class = apply_filters( 'vc_shortcodes_css_class', 'tab-pane', 'vc_tab', $atts );

$tab_id = ( empty( $tab_id ) || $tab_id == __( "Tab", "js_composer" ) ) ? esc_attr( str_replace( "%", "", sanitize_title_with_dashes( $title ) ) ) : $tab_id;
global $kleo_tab_active;
if ( $tab_id == $kleo_tab_active ) {
	$css_class .= ' active';
}

// Enqueue needed font for icon element
if ( function_exists( 'vc_icon_element_fonts_enqueue' ) && 'pixelicons' !== $icon_type && 'fontello' != $icon_type ) {
	vc_icon_element_fonts_enqueue( $icon_type );
}
?>

<div id="tab-<?php echo esc_attr($tab_id); ?>" class="<?php echo esc_attr($css_class); ?>">
    <?php
    if ($content == '' || $content == ' ') {
        echo __("Empty section. Edit page to add content here.", "js_composer");
    } else {
        echo kleo_remove_wpautop($content);
    }
    ?>
</div>
