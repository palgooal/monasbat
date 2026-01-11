<?php
$output = $title = $tooltip_title = $tooltip_text = $tooltip_action = $tooltip_position = '';

extract( shortcode_atts( array(
	'title'            => __( "Section", "js_composer" ),
	'icon'             => '',
	'icon_closed'      => '',
	'tooltip'          => '',
	'tooltip_position' => '',
	'tooltip_title'    => '',
	'tooltip_text'     => '',
	'tooltip_action'   => 'hover',
	'el_id'            => '',

), $atts ) );

global $kleo_acc_id, $kleo_acc_active_tab, $kleo_acc_count;
if ( isset( $el_id ) && ! empty( $el_id ) ) {
	$elem_id = $el_id;
} else {
	$elem_id = $kleo_acc_id . '-' . kleo_vc_elem_increment() . '-d';
}

$kleo_acc_count ++;

if ( $icon != '' ) {
	$icon = ' icon-' . $icon;
	if ( $kleo_acc_count != $kleo_acc_active_tab ) {
		$icon .= ' hide';
	}
}

if ( $icon_closed != '' ) {
	$icon_closed = ' icon-' . $icon_closed;

	if ( $kleo_acc_count == $kleo_acc_active_tab ) {
		$icon_closed .= ' hide';
	}
} elseif ( $icon != '' ) {
	$icon_closed = ' icon-' . $icon;
}

$tooltip_class = '';
$tooltip_data  = '';
if ( $tooltip != '' ) {
	if ( $tooltip == 'popover' ) {
		$tooltip_class = ' ' . esc_attr( $tooltip_action ) . '-pop';
		$tooltip_data = array(
            'data-toggle' => "popover",
            'data-container' => "body",
            'data-title' => esc_attr( $tooltip_title ),
            'data-content' => esc_attr( $tooltip_text ),
            'data-placement' => esc_attr( $tooltip_position )
        );
	} else {
		$tooltip_class .= ' ' . esc_attr( $tooltip_action ) . '-tip';
		$tooltip_data = array(
            'data-toggle' => "tooltip",
            'data-original-title' => esc_attr( $tooltip_title ),
            'data-placement' => esc_attr( $tooltip_position )
        );
	}
}

$icon_closed .= $tooltip_class;
$extra_content_class = '';
if ( $kleo_acc_count == $kleo_acc_active_tab ) {
	$extra_content_class .= ' in';
}
?>

<div class="panel">
    <div class="panel-heading">
        <div class="panel-title">
            <a href="#acc-<?php echo esc_attr( $elem_id ); ?>" data-parent="#accordion-<?php echo esc_attr( $kleo_acc_id ); ?>" data-toggle="collapse" class="accordion-toggle">
                <?php echo wp_kses_post( $title ); ?>
                <span class="icon-closed<?php echo esc_attr( $icon_closed ); ?>"<?php echo kleo_get_attributes_string( $tooltip_data ); ?>></span> 
                <span class="icon-opened<?php echo esc_attr( $icon ); ?>"></span>
            </a>
        </div>
    </div>
    <div id="acc-<?php echo esc_attr( $elem_id ); ?>" class="panel-collapse collapse<?php echo esc_attr( $extra_content_class ); ?>">
        <div class="panel-body">
            <?php
            if ( $content == '' || $content == ' ' ) {
                echo esc_html__( "Empty section. Edit page to add content here.", "js_composer" );
            } else {
                echo wpb_js_remove_wpautop( $content );
            }
            ?>
        </div>
    </div>
</div>
