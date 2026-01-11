<?php

/*
 :: Custom functions
 */
function kleo_bp_new_group_invite_friend_list() {
	echo kleo_bp_get_new_group_invite_friend_list();
}

function kleo_bp_get_new_group_invite_friend_list( $args = '' ) {
	global $bp;

	if ( ! bp_is_active( 'friends' ) ) {
		return false;
	}

	$defaults = array(
		'group_id'  => false,
		'separator' => 'li'
	);

	$r = wp_parse_args( $args, $defaults );
	extract( $r, EXTR_SKIP );

	if ( empty( $group_id ) ) {
		$group_id = ! empty( $bp->groups->new_group_id ) ? $bp->groups->new_group_id : $bp->groups->current_group->id;
	}

	if ( $friends = friends_get_friends_invite_list( bp_loggedin_user_id(), $group_id ) ) {
		$invites = groups_get_invites_for_group( bp_loggedin_user_id(), $group_id );

		for ( $i = 0, $count = count( $friends ); $i < $count; ++ $i ) {
			$checked = '';

			if ( ! empty( $invites ) ) {
				if ( in_array( $friends[ $i ]['id'], $invites ) ) {
					$checked = ' checked="checked"';
				}
			}

			$items[] = '<' . $separator . '>'
			           . '<label class="mark-item">'
			           . '<input class="checkbox-cb"' . $checked . ' type="checkbox" name="friends[]" id="f-' . $friends[ $i ]['id'] . '" value="' . esc_attr( $friends[ $i ]['id'] ) . '" /> '
			           . '<span class="checkbox-mark"></span>'
			           . '</label>'
			           . '<div class="item-avatar rounded">'
			           . get_avatar( $friends[ $i ]['id'] )
			           . '</div>'
			           . '<div class="invite-list-content"><h4>' . bp_core_get_userlink( $friends[ $i ]['id'] ) . '</h4>'
			           . '<span class="activity">' . bp_get_last_activity( $friends[ $i ]['id'] ) . '</span>'
			           . '<span class="group-invites-status"></span></div>'
			           . '</' . $separator . '>';
		}
	}

	if ( ! empty( $items ) ) {
		return implode( "\n", (array) $items );
	}

	return false;
}

if ( ! function_exists( 'bp_nouveau_avatar_args' ) ) {
	/**
	 * Get the full size avatar args.
	 *
	 * @return array The avatar arguments.
	 * @since 3.0.0
	 *
	 */
	function bp_nouveau_avatar_args() {
		/**
		 * Filter arguments for full-size avatars.
		 *
		 * @param array $args {
		 * @param string $type Avatar type.
		 * @param int $width Avatar width value.
		 * @param int $height Avatar height value.
		 * }
		 *
		 * @since 3.0.0
		 *
		 */
		return apply_filters( 'bp_nouveau_avatar_args', array(
			'type'   => 'full',
			'width'  => bp_core_avatar_full_width(),
			'height' => bp_core_avatar_full_height(),
		) );
	}
}

if ( ! function_exists( 'bp_core_avatar_full_width' ) ) {
	/**
	 * Get the 'full' avatar width setting.
	 *
	 * @return int The 'full' width.
	 * @since 1.5.0
	 *
	 */
	function bp_core_avatar_full_width() {

		/**
		 * Filters the 'full' avatar width setting.
		 *
		 * @param int $value Value for the 'full' avatar width setting.
		 *
		 * @since 1.5.0
		 *
		 */
		return apply_filters( 'bp_core_avatar_full_width', bp_core_avatar_dimension( 'full', 'width' ) );
	}
}

if ( ! function_exists( 'bp_core_avatar_full_height' ) ) {
	/**
	 * Get the 'full' avatar height setting.
	 *
	 * @return int The 'full' height.
	 * @since 1.5.0
	 *
	 */
	function bp_core_avatar_full_height() {

		/**
		 * Filters the 'full' avatar height setting.
		 *
		 * @param int $value Value for the 'full' avatar height setting.
		 *
		 * @since 1.5.0
		 *
		 */
		return apply_filters( 'bp_core_avatar_full_height', bp_core_avatar_dimension( 'full', 'height' ) );
	}
}
