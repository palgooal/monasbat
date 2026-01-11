<?php
/*
 * BP Profile Search - form template 'bps-form-legacy'
 *
 * See http://dontdream.it/bp-profile-search/form-templates/ if you wish to modify this template or develop a new one.
 *
 * Move new or modified templates to the 'buddypress/members' directory in your theme's root,
 * otherwise they will be overwritten during the next BP Profile Search update.
 *
 */

// 1st section: set the default value of the template options

if ( ! isset ( $options['collapsible'] ) ) {
	$options['collapsible'] = 'Yes';
}

// 2nd section: display the form to select the template options

if ( is_admin() ) {
	?>
	<p><strong><?php _e( 'Collapsible Form', 'bp-profile-search' ); ?></strong></p>
	<select name="options[collapsible]">
		<option
			value='Yes' <?php selected( $options['collapsible'], 'Yes' ); ?>><?php _e( 'Yes', 'bp-profile-search' ); ?></option>
		<option
			value='No' <?php selected( $options['collapsible'], 'No' ); ?>><?php _e( 'No', 'bp-profile-search' ); ?></option>
	</select>
	<?php
	return 'end_of_options 4.9';
}

// 3rd section: display the search form

$F = bps_escaped_form_data( '4.9' );

$toggle_id = 'bps_toggle' . $F->unique_id;
$form_id   = 'bps_' . $F->location . $F->unique_id;

if ( $F->location != 'directory' ) {
	echo "<div id='buddypress'>";
} elseif ( $options['collapsible'] == 'Yes' ) {

	?>
	<div class="item-list-tabs bps_header">
		<ul>
            <li><?php echo esc_html( $F->title ); ?></li>
			<li class="last">
				<input id="<?php echo esc_attr( $toggle_id ); ?>" type="submit"
				       value="<?php esc_html_e( 'Toggle filters', 'kleo' ); ?>">
			</li>
		</ul>

		<script type="text/javascript">
			jQuery(document).ready(function ($) {
				$('#<?php echo esc_attr( $form_id ); ?>').hide();
				$('#<?php echo esc_attr( $toggle_id ); ?>').click(function () {
					$('#<?php echo esc_attr( $form_id ); ?>').toggle('slow');
				});
			});
		</script>
		<div class="clear clearfix"></div>
	</div>
	<?php
}
$form_extra_class= $options['collapsible'] === 'Yes' ? ' form-collapsible' : '';
echo "<form action='$F->action' method='$F->method' id='$form_id' class='bps-form bps-form-labels clear clearfix". $form_extra_class ."'>\n";

$j = 0;
foreach ( $F->fields as $f ) {
	$id      = $f->unique_id;
	$name    = $f->html_name;
	$value   = $f->value;
	$display = $f->display;

	if ( $display == 'none' ) {
		continue;
	}

	if ( $display == 'hidden' ) {
		?>
		<input type="hidden" name="<?php echo esc_attr( $name ); ?>" value="<?php echo esc_attr( $value ); ?>">
		<?php
		continue;
	}

	$alt           = ( $j ++ % 2 ) ? 'alt' : '';
	$class         = "editfield bps-$display field_$id field_$name $alt";

	if ( in_array( $display, [ 'range', 'integer-range', 'date-range' ] ) ) {
		$class .= ' form-group form-inline';
	} else {
		$class .= ' form-group';
	}

	echo "<div id='" . $id . "_wrap' class='$class'>\n";

	if ( ! empty ( $f->error_message ) ) {
		?>
		<span class="bps-error"><?php echo esc_html( $f->error_message ); ?></span><br>
		<?php
	}

	switch ( $display ) {
		case 'range':

			echo "<label style='display: block;' for='$name'>$f->label</label>\n";
			echo "<input type='text' name='{$name}[min]' id='$id' value='" . $value['min'] . "' class='form-control input-range'>";
			echo '&nbsp;-&nbsp;';
			echo "<input type='text' name='{$name}[max]' value='" . $value['max'] . "' class='form-control input-range'>\n";
			break;

		case 'integer-range':

			echo "<label style='display: block;' for='$name'>$f->label</label>\n";
			echo "<input type='number' name='{$name}[min]' id='$id' value='" . $value['max'] . "' class='form-control input-range'>";
			echo '&nbsp;-&nbsp;';
			echo "<input type='number' name='{$name}[max]' value='" . $value['max'] . "' class='form-control input-range'>\n";
			break;

		case 'date-range':

			echo "<label style='display: block;' for='$name'>$f->label</label>\n";
			echo "<input type='date' name='{$name}[min]' id='$name' value='" . $value['min'] . "' class='form-control input-range'>";
			echo '&nbsp;-&nbsp;';
			echo "<input type='date' name='{$name}[max]' value='" . $value['max'] . "' class='form-control input-range'>\n";
			break;

		case 'range-select':
			echo "<label for='$id'>$f->label</label>\n";
			?>
			<select id="<?php echo esc_attr( $id ); ?>" name="<?php echo esc_attr( $name ) . '[min]'; ?>" class="form-control">
				<?php foreach ( $f->options as $key => $label ) { ?>
					<option <?php if ( $key == $value['min'] ) {
						echo 'selected="selected"';
					} ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> </option>
				<?php } ?>
			</select>
			<span> - </span>
			<select name="<?php echo esc_attr( $name ) . '[max]'; ?>" class="form-control">
				<?php foreach ( $f->options as $key => $label ) { ?>
					<option <?php if ( $key == $value['max'] ) {
						echo 'selected="selected"';
					} ?> value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?> </option>
				<?php } ?>
			</select><br>
			<?php
			break;

		case 'textbox':
		case 'textarea':
			echo "<label for='$name'>$f->label</label>\n";
			echo "<input type='text' name='$name' id='$id' value='$value' class='form-control'>\n";
			break;

		case 'number':
		case 'integer':
			echo "<label for='$name'>$f->label</label>\n";
			echo "<input type='number' name='$name' id='$id' value='$value' class='form-control'>\n";
			break;

		case 'url':
			echo "<label for='$name'>$f->label</label>\n";
			echo "<input type='text' inputmode='url' name='$name' id='$id' value='$value' class='form-control'>\n";
			break;

		case 'distance':
			$within = __( 'Within', 'kleo' );
			$of      = __( 'of', 'bp-profile-search' );
			$km      = __( 'km', 'bp-profile-search' );
			$miles   = __( 'miles', 'bp-profile-search' );
			$placeholder = __('Start typing, then select a location', 'bp-profile-search');
			$icon_title = __('get current location', 'bp-profile-search');
			?>

			<label><?php echo wp_kses_post( $f->label ); ?></label>

			<div class="row bps-distance-group">
				<div class="col-sm-12 form-group">
					<div class="input-group">
						<span class="input-group-addon" id=""><?php echo esc_html( $within ); ?></span>
						<input class="form-control bps-distance-input" type="number" min="1"
						       name="<?php echo esc_attr( $name ) . '[distance]'; ?>"
						       value="<?php echo esc_attr( $value['distance'] ); ?>">

						<span class="input-group-btn" style="width: 0;"></span>

						<select class="form-control bps-distance-units" name="<?php echo esc_attr( $name ) . '[units]'; ?>">
							<option value="km" <?php selected( $value['units'], "km" ); ?>><?php echo esc_html( $km ); ?></option>
							<option value="miles" <?php selected( $value['units'], "miles" ); ?>><?php echo esc_html( $miles ); ?></option>
						</select>
					</div>
				</div>

				<div class="col-sm-12 form-group">
					<div class="input-group">
						<span class="input-group-addon"><?php echo esc_html( $of ); ?></span>
						<input type="text"
						       id="<?php echo esc_attr( $id ); ?>"
						       class="form-control"
						       name="<?php echo esc_attr( $name ) . '[location]'; ?>"
						       value="<?php echo esc_attr( $value['location'] ); ?>"
						       placeholder="<?php esc_html_e( 'Start typing, then select a location', 'bp-profile-search' ); ?>">
						<div class="input-group-addon" style="width:45px;">
                            <button class="bps-location-selector" type="button" id="<?php echo esc_attr( $id ); ?>_icon" title="<?php echo esc_attr( $icon_title ); ?>">
                                <span class="dashicons dashicons-location"></span>
                            </button>
                            <input type="hidden" id="<?php echo esc_attr( $id ); ?>_lat" name="<?php echo esc_attr( $name.'[lat]' ); ?>" value="<?php echo esc_attr( $value['lat'] ); ?>">
                            <input type="hidden" id="<?php echo esc_attr( $id ); ?>_lng" name="<?php echo esc_attr( $name.'[lng]' ); ?>" value="<?php echo esc_attr( $value['lng'] ); ?>">

                            <script>
                                jQuery(function ($) {
                                    bps_autocomplete('<?php echo esc_attr( $id ); ?>', '<?php echo esc_attr( $id ); ?>_lat', '<?php echo esc_attr( $id ); ?>_lng');
                                    $('#<?php echo esc_attr( $id ); ?>_icon').click(function () {
                                        bps_locate('<?php echo esc_attr( $id ); ?>', '<?php echo esc_attr( $id ); ?>_lat', '<?php echo esc_attr( $id ); ?>_lng')
                                    });
                                });
                            </script>
						</div>

					</div>

				</div>
			</div>

			<?php
			break;


		case 'selectbox':
			echo "<label for='$id'>$f->label</label>\n";
			echo "<select name='$name' id='$id' class='form-control'>\n";

			foreach ( $f->options as $key => $label ) {

			    if ( $key === '' && empty( $label ) ) {
				    $label = apply_filters( 'bps_field_selectbox_no_selection', $f->label, $f );
			    }

				$selected = in_array( $key, (array) $value ) ? "selected='selected'" : "";
				echo "<option $selected value='$key'>$label</option>\n";
			}
			echo "</select>\n";
			break;

		case 'multiselectbox':
			echo "<label for='$id'>$f->label</label>\n";
			echo "<select name='{$name}[]' id='$id' multiple='multiple' class='form-control'>\n";

			foreach ( $f->options as $key => $label ) {
				$selected = in_array( $key, (array) $value ) ? "selected='selected'" : "";
				echo "<option $selected value='$key'>$label</option>\n";
			}
			echo "</select>\n";
			break;

		case 'radio':

			echo "<label>$f->label</label>\n";
			echo "<div id='$id'>\n";

			foreach ( $f->options as $key => $label ) {
				$checked = in_array( $key, (array) $value ) ? "checked='checked'" : "";
				echo "<div class='radio'>\n";
				echo "<label><input $checked type='radio' name='$name' value='$key'>$label</label>\n";
				echo "</div>\n";
			}

			echo "<a class='clear-value' href='javascript:clear(\"$name\");'>" . esc_html__( 'Clear', 'buddypress' ) . "</a>\n";
			echo "</div>\n";
			break;

		case 'checkbox':

			echo "<label>$f->label</label>\n";

			foreach ( $f->options as $key => $label ) {
				$checked = in_array( $key, (array) $value ) ? "checked='checked'" : "";
				echo "<div class='checkbox'>\n";
				echo "<label><input $checked type='checkbox' name='{$name}[]' value='$key'>$label</label>\n";
				echo "</div>\n";
			}

			break;

		default:
			$field_template = 'members/bps-' . $display . '-form-field.php';
			$located = bp_locate_template( $field_template );
			if ( $located ) {
				include $located;
			} else {
				?>
				<p class="bps-error">
					<?php echo "BP Profile Search: unknown display <em>$display</em> for field <em>$f->name</em>."; ?>
				</p>
				<?php
			}
			break;
	}

	if ( ! empty ( $f->description ) && $f->description != '-' ) {
		echo "<p class='description'>$f->description</p>\n";
	}

	echo "</div>\n";
}

echo "<input type='hidden' name='bp_profile_search' value='$F->id'>\n";
echo '<a href="#" class="btn btn-highlight form-submit">' . esc_html__( 'Search', 'default' ) . '</a>';
echo "</form>\n";

if ( $F->location != 'directory' ) {
	echo "</div>\n";
}

// BP Profile Search - end of template
