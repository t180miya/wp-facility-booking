<?php
/**
 * Plugin Name: WP Plugin Starter
 * Description: メッセージと月間カレンダーをショートコードで表示します。
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: Your Name
 * License: GPL-2.0-or-later
 * Text Domain: wpplugin-starter
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const WPPLUGIN_OPTION_NAME = 'wpplugin_message';
const WPPLUGIN_FACILITY_NAME_OPTION = 'wpplugin_facility_name';
const WPPLUGIN_FACILITY_ADDRESS_OPTION = 'wpplugin_facility_address';
const WPPLUGIN_FACILITY_PHONE_OPTION = 'wpplugin_facility_phone';
const WPPLUGIN_FACILITY_FEE_OPTION = 'wpplugin_facility_fee';
const WPPLUGIN_FACILITY_START_OPTION = 'wpplugin_facility_start';
const WPPLUGIN_FACILITY_END_OPTION = 'wpplugin_facility_end';
const WPPLUGIN_FACILITY_INFO_OPTION = 'wpplugin_facility_info';
const WPPLUGIN_FACILITIES_OPTION = 'wpplugin_facilities';
const WPPLUGIN_RESERVATION_POST_TYPE = 'wpplugin_reservation';

function wpplugin_sanitize_plain_text( $value ) {
	return sanitize_text_field( wp_unslash( $value ) );
}

function wpplugin_sanitize_plain_textarea( $value ) {
	return sanitize_textarea_field( wp_unslash( $value ) );
}

function wpplugin_reservation_type_for_facility( $facility ) {
	$type = isset( $facility['reservation_type'] ) ? $facility['reservation_type'] : '時間貸';

	return in_array( $type, array( '時間貸', '宿泊', '両方' ), true ) ? $type : '時間貸';
}

function wpplugin_is_valid_reservation_time( $time, $allow_midnight = false ) {
	return (bool) preg_match( $allow_midnight ? '/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/' : '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time );
}

function wpplugin_get_facilities() {
	$facilities = get_option( WPPLUGIN_FACILITIES_OPTION, array() );
	if ( ! is_array( $facilities ) || empty( $facilities ) ) {
		$facilities = array(
			array(
				'id' => 'default',
				'name' => get_option( WPPLUGIN_FACILITY_NAME_OPTION, '施設名未設定' ),
				'fee' => get_option( WPPLUGIN_FACILITY_FEE_OPTION, '料金未設定' ),
				'start' => get_option( WPPLUGIN_FACILITY_START_OPTION, '09:00' ),
				'end' => get_option( WPPLUGIN_FACILITY_END_OPTION, '18:00' ),
				'info' => get_option( WPPLUGIN_FACILITY_INFO_OPTION, '施設情報未設定' ),
				'additional_condition_1' => '',
				'additional_fee_1' => '',
				'additional_condition_2' => '',
				'additional_fee_2' => '',
				'additional_condition_3' => '',
				'additional_fee_3' => '',
				'reservation_type' => '時間貸',
				'stay_start' => '15:00',
				'stay_end' => '10:00',
				'stay_fee' => '',
			)
		);
	}
	return $facilities;
}

function wpplugin_get_facility( $facility_id ) {
	foreach ( wpplugin_get_facilities() as $facility ) {
		if ( (string) $facility_id === (string) $facility['id'] ) {
			return $facility;
		}
	}
	return null;
}

function wpplugin_user_can_manage_reservations( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	$management_roles = array( 'management_company', 'management-company', 'facility_manager' );

	return user_can( $user, 'manage_options' ) || user_can( $user, 'manage_wpplugin_reservations' ) || (bool) array_intersect( $management_roles, (array) $user->roles );
}

function wpplugin_user_can_edit_reservation( $post_id, $user = null ) {
	$user = $user ? $user : wp_get_current_user();

	return wpplugin_user_can_manage_reservations( $user ) || (int) get_post_field( 'post_author', $post_id ) === (int) $user->ID;
}

function wpplugin_register_reservation_post_type() {
	register_post_type(
		WPPLUGIN_RESERVATION_POST_TYPE,
		array(
			'labels'       => array( 'name' => '予約', 'singular_name' => '予約' ),
			'public'       => false,
			'show_ui'      => true,
			'show_in_menu' => true,
			'supports'     => array( 'title', 'author' ),
			'capability_type' => 'post',
		)
	);
}
add_action( 'init', 'wpplugin_register_reservation_post_type' );

function wpplugin_save_reservation() {
	if ( ! is_user_logged_in() || ! check_admin_referer( 'wpplugin_save_reservation', 'wpplugin_reservation_nonce' ) ) {
		wp_die( '予約を保存できませんでした。' );
	}

	$date  = isset( $_POST['reservation_date'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_date'] ) : '';
	$start = isset( $_POST['reservation_start'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_start'] ) : '';
	$end   = isset( $_POST['reservation_end'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_end'] ) : '';
	$title = isset( $_POST['reservation_title'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_title'] ) : '';
	$name  = isset( $_POST['reservation_name'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_name'] ) : '';
	$details = isset( $_POST['reservation_details'] ) ? wpplugin_sanitize_plain_textarea( $_POST['reservation_details'] ) : '';
	$facility_id = isset( $_POST['facility_id'] ) ? sanitize_key( wp_unslash( $_POST['facility_id'] ) ) : 'default';
	$facility = wpplugin_get_facility( $facility_id );
	if ( ! $facility ) {
		wp_die( '施設を選択してください。' );
	}
	$facility_start = $facility['start'];
	$facility_end   = $facility['end'];
	$facility_reservation_type = wpplugin_reservation_type_for_facility( $facility );
	$reservation_type = isset( $_POST['reservation_type'] ) ? wpplugin_sanitize_plain_text( $_POST['reservation_type'] ) : $facility_reservation_type;
	if ( ! in_array( $reservation_type, array( '時間貸', '宿泊' ), true ) || ( '両方' !== $facility_reservation_type && $reservation_type !== $facility_reservation_type ) ) {
		wp_die( '予約タイプを確認してください。' );
	}
	$is_stay = '宿泊' === $reservation_type;
	if ( $is_stay ) {
		$start = $facility['stay_start'];
		$end = '24:00';
	}
	$additional_quantities = array();
	for ( $index = 1; $index <= 3; $index++ ) {
		$additional_quantities[ $index ] = ! empty( $facility[ 'additional_condition_' . $index ] ) && isset( $_POST[ 'additional_quantity_' . $index ] ) ? max( 0, absint( $_POST[ 'additional_quantity_' . $index ] ) ) : 0;
	}

	if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! wpplugin_is_valid_reservation_time( $start ) || ! wpplugin_is_valid_reservation_time( $end, true ) || '' === $title || '' === $name ) {
		wp_die( '予約内容を確認してください。' );
	}
	if ( ! $is_stay && $end <= $start ) {
		wp_die( '終了時刻は開始時刻より後を指定してください。' );
	}
	if ( ! $is_stay && ( $start < $facility_start || $end > $facility_end ) ) {
		wp_die( '施設の利用可能時間内で指定してください。' );
	}

	$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
	$group_id = $reservation_id ? (string) get_post_meta( $reservation_id, '_wpplugin_reservation_group', true ) : '';
	if ( ! $group_id && $reservation_id ) {
		$group_id = (string) $reservation_id;
	}
	$periods = array( array( 'date' => $date, 'start' => $start, 'end' => $end ) );
	if ( $is_stay ) {
		$periods[] = array(
			'date' => wp_date( 'Y-m-d', strtotime( '+1 day', strtotime( $date ) ) ),
			'start' => '00:00',
			'end' => $facility['stay_end'],
		);
	}
	$existing_reservations = get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1 ) );
	foreach ( $periods as $period ) {
		foreach ( $existing_reservations as $existing_reservation ) {
			if ( ( $group_id && (string) get_post_meta( $existing_reservation->ID, '_wpplugin_reservation_group', true ) === $group_id ) || get_post_meta( $existing_reservation->ID, '_wpplugin_reservation_date', true ) !== $period['date'] || (string) get_post_meta( $existing_reservation->ID, '_wpplugin_reservation_facility_id', true ) !== (string) $facility_id ) {
				continue;
			}

			$existing_start = get_post_meta( $existing_reservation->ID, '_wpplugin_reservation_start', true );
			$existing_end   = get_post_meta( $existing_reservation->ID, '_wpplugin_reservation_end', true );
			if ( $period['start'] < $existing_end && $period['end'] > $existing_start ) {
				wp_die( '指定した時間帯は、すでに予約されている時間帯と重なっています。別の時間を指定してください。' );
			}
		}
	}

	$post_data = array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_title' => $title, 'post_status' => 'publish' );
	if ( $reservation_id && get_post_type( $reservation_id ) === WPPLUGIN_RESERVATION_POST_TYPE ) {
		if ( ! wpplugin_user_can_edit_reservation( $reservation_id ) ) {
			wp_die( 'この予約を編集する権限がありません。' );
		}
		$post_data['ID'] = $reservation_id;
		$post_id = wp_update_post( $post_data, true );
	} else {
		$post_data['post_author'] = get_current_user_id();
		$post_id = wp_insert_post( $post_data, true );
	}
	if ( is_wp_error( $post_id ) ) {
		wp_die( '予約を保存できませんでした。' );
	}

	$group_id = $group_id ? $group_id : (string) $post_id;
	$save_period = function( $reservation_post_id, $period, $part ) use ( $facility_id, $name, $details, $reservation_type, $additional_quantities, $group_id ) {
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_date', $period['date'] );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_facility_id', $facility_id );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_start', $period['start'] );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_end', $period['end'] );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_name', $name );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_details', $details );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_type', $reservation_type );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_additional_quantities', $additional_quantities );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_group', $group_id );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_stay_part', $part );
	};
	$save_period( $post_id, $periods[0], 'start' );
	$group_posts = get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1, 'meta_key' => '_wpplugin_reservation_group', 'meta_value' => $group_id ) );
	if ( $is_stay ) {
		$end_post = null;
		foreach ( $group_posts as $group_post ) {
			if ( $group_post->ID !== $post_id && 'end' === get_post_meta( $group_post->ID, '_wpplugin_reservation_stay_part', true ) ) {
				$end_post = $group_post;
				break;
			}
		}
		$end_post_id = $end_post ? $end_post->ID : wp_insert_post( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_title' => $title, 'post_status' => 'publish', 'post_author' => get_current_user_id() ), true );
		if ( is_wp_error( $end_post_id ) ) {
			wp_die( '予約を保存できませんでした。' );
		}
		if ( $end_post ) {
			wp_update_post( array( 'ID' => $end_post_id, 'post_title' => $title ) );
		}
		$save_period( $end_post_id, $periods[1], 'end' );
	} else {
		foreach ( $group_posts as $group_post ) {
			if ( $group_post->ID !== $post_id && 'end' === get_post_meta( $group_post->ID, '_wpplugin_reservation_stay_part', true ) ) {
				wp_delete_post( $group_post->ID, true );
			}
		}
	}
	$redirect_url = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'wpplugin_reservation_status', 'saved', $redirect_url ) );
	exit;
}
add_action( 'admin_post_wpplugin_save_reservation', 'wpplugin_save_reservation' );
add_action( 'admin_post_nopriv_wpplugin_save_reservation', 'wpplugin_save_reservation' );

function wpplugin_cancel_reservation() {
	if ( ! is_user_logged_in() || ! check_admin_referer( 'wpplugin_cancel_reservation', 'wpplugin_cancel_nonce' ) ) {
		wp_die( '予約をキャンセルできませんでした。' );
	}

	$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
	if ( ! $reservation_id || get_post_type( $reservation_id ) !== WPPLUGIN_RESERVATION_POST_TYPE || ! wpplugin_user_can_edit_reservation( $reservation_id ) ) {
		wp_die( 'この予約をキャンセルする権限がありません。' );
	}

	$group_id = (string) get_post_meta( $reservation_id, '_wpplugin_reservation_group', true );
	$group_posts = $group_id ? get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1, 'meta_key' => '_wpplugin_reservation_group', 'meta_value' => $group_id ) ) : array();
	if ( empty( $group_posts ) ) {
		$group_posts = array( get_post( $reservation_id ) );
	}
	foreach ( $group_posts as $group_post ) {
		if ( ! $group_post || ! wpplugin_user_can_edit_reservation( $group_post->ID ) || ! wp_delete_post( $group_post->ID, true ) ) {
			wp_die( '予約をキャンセルできませんでした。' );
		}
	}

	$redirect_url = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'wpplugin_reservation_status', 'cancelled', $redirect_url ) );
	exit;
}
add_action( 'admin_post_wpplugin_cancel_reservation', 'wpplugin_cancel_reservation' );
add_action( 'admin_post_nopriv_wpplugin_cancel_reservation', 'wpplugin_cancel_reservation' );

function wpplugin_get_reservations() {
	$reservations = array();
	$posts = get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1 ) );
	$current_user = wp_get_current_user();
	$group_starts = array();
	foreach ( $posts as $post ) {
		$group_id = get_post_meta( $post->ID, '_wpplugin_reservation_group', true );
		if ( $group_id && 'start' === get_post_meta( $post->ID, '_wpplugin_reservation_stay_part', true ) ) {
			$group_starts[ $group_id ] = array(
				'id' => $post->ID,
				'date' => get_post_meta( $post->ID, '_wpplugin_reservation_date', true ),
			);
		}
	}
	foreach ( $posts as $post ) {
		$date = get_post_meta( $post->ID, '_wpplugin_reservation_date', true );
		if ( $date ) {
			$group_id = get_post_meta( $post->ID, '_wpplugin_reservation_group', true );
			$reservations[] = array(
				'id' => $post->ID,
				'facility_id' => get_post_meta( $post->ID, '_wpplugin_reservation_facility_id', true ) ?: 'default',
				'date' => $date,
				'start' => get_post_meta( $post->ID, '_wpplugin_reservation_start', true ),
				'end' => get_post_meta( $post->ID, '_wpplugin_reservation_end', true ),
				'title' => $post->post_title,
				'name' => get_post_meta( $post->ID, '_wpplugin_reservation_name', true ),
				'details' => get_post_meta( $post->ID, '_wpplugin_reservation_details', true ),
				'reservation_type' => get_post_meta( $post->ID, '_wpplugin_reservation_type', true ) ?: '時間貸',
				'additional_quantities' => get_post_meta( $post->ID, '_wpplugin_reservation_additional_quantities', true ) ?: array(),
				'group_id' => $group_id,
				'group_primary_id' => isset( $group_starts[ $group_id ] ) ? $group_starts[ $group_id ]['id'] : $post->ID,
				'group_start_date' => isset( $group_starts[ $group_id ] ) ? $group_starts[ $group_id ]['date'] : $date,
				'can_edit' => wpplugin_user_can_edit_reservation( $post->ID, $current_user ),
			);
		}
	}
	return $reservations;
}

function wpplugin_activate() {
	if ( false === get_option( WPPLUGIN_OPTION_NAME ) ) {
		add_option( WPPLUGIN_OPTION_NAME, 'Hello from WP Plugin Starter!' );
	}
}
register_activation_hook( __FILE__, 'wpplugin_activate' );

function wpplugin_add_settings_page() {
	add_options_page(
		'WP Plugin Starter',
		'WP Plugin Starter',
		'manage_options',
		'wpplugin-starter',
		'wpplugin_render_settings_page'
	);
}
add_action( 'admin_menu', 'wpplugin_add_settings_page' );

function wpplugin_register_settings() {
	register_setting(
		'wpplugin_settings',
		WPPLUGIN_OPTION_NAME,
		array(
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_text_field',
			'default'           => 'Hello from WP Plugin Starter!',
		)
	);
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_NAME_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '施設名未設定' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_ADDRESS_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '住所未設定' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_PHONE_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '電話番号未設定' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_FEE_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field', 'default' => '料金未設定' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_START_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'wpplugin_sanitize_time', 'default' => '09:00' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_END_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'wpplugin_sanitize_time', 'default' => '18:00' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITY_INFO_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'sanitize_textarea_field', 'default' => '施設情報未設定' ) );
	register_setting( 'wpplugin_settings', WPPLUGIN_FACILITIES_OPTION, array( 'type' => 'array', 'sanitize_callback' => 'wpplugin_sanitize_facilities', 'default' => array() ) );

	add_settings_section(
		'wpplugin_main_section',
		'メッセージ設定',
		'wpplugin_render_settings_section',
		'wpplugin-starter'
	);

	add_settings_field(
		'wpplugin_message_field',
		'表示するメッセージ',
		'wpplugin_render_message_field',
		'wpplugin-starter',
		'wpplugin_main_section'
	);
	add_settings_field( 'wpplugin_facility_name_field', '施設名', 'wpplugin_render_facility_name_field', 'wpplugin-starter', 'wpplugin_main_section' );
	add_settings_field( 'wpplugin_facility_fee_field', '利用料金', 'wpplugin_render_facility_fee_field', 'wpplugin-starter', 'wpplugin_main_section' );
	add_settings_field( 'wpplugin_facility_start_field', '利用可能開始時刻', 'wpplugin_render_facility_start_field', 'wpplugin-starter', 'wpplugin_main_section' );
	add_settings_field( 'wpplugin_facility_end_field', '利用可能終了時刻', 'wpplugin_render_facility_end_field', 'wpplugin-starter', 'wpplugin_main_section' );
	add_settings_field( 'wpplugin_facility_info_field', '施設情報', 'wpplugin_render_facility_info_field', 'wpplugin-starter', 'wpplugin_main_section' );
	add_settings_field( 'wpplugin_facilities_field', '施設一覧', 'wpplugin_render_facilities_field', 'wpplugin-starter', 'wpplugin_main_section' );
}
add_action( 'admin_init', 'wpplugin_register_settings' );

function wpplugin_render_settings_section() {
	echo '<p>ショートコード <code>[wpplugin_message]</code> で表示する文言を設定します。</p>';
}

function wpplugin_render_message_field() {
	$message = get_option( WPPLUGIN_OPTION_NAME, 'Hello from WP Plugin Starter!' );
	?>
	<input
		type="text"
		name="<?php echo esc_attr( WPPLUGIN_OPTION_NAME ); ?>"
		value="<?php echo esc_attr( $message ); ?>"
		class="regular-text"
	/>
	<?php
}

function wpplugin_render_facility_field( $option_name, $default ) {
	$value = get_option( $option_name, $default );
	?>
	<input type="text" name="<?php echo esc_attr( $option_name ); ?>" value="<?php echo esc_attr( $value ); ?>" class="regular-text" />
	<?php
}

function wpplugin_render_facility_name_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_NAME_OPTION, '施設名未設定' );
}

function wpplugin_render_facility_address_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_ADDRESS_OPTION, '住所未設定' );
}

function wpplugin_render_facility_phone_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_PHONE_OPTION, '電話番号未設定' );
}

function wpplugin_sanitize_time( $value ) {
	$value = sanitize_text_field( $value );
	return preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$|^24:00$/', $value ) ? $value : '09:00';
}

function wpplugin_render_facility_fee_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_FEE_OPTION, '料金未設定' );
}

function wpplugin_render_facility_start_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_START_OPTION, '09:00' );
}

function wpplugin_render_facility_end_field() {
	wpplugin_render_facility_field( WPPLUGIN_FACILITY_END_OPTION, '18:00' );
}

function wpplugin_render_facility_info_field() {
	$value = get_option( WPPLUGIN_FACILITY_INFO_OPTION, '施設情報未設定' );
	?>
	<textarea name="<?php echo esc_attr( WPPLUGIN_FACILITY_INFO_OPTION ); ?>" rows="4" class="large-text"><?php echo esc_textarea( $value ); ?></textarea>
	<?php
}

function wpplugin_sanitize_facilities( $value ) {
	if ( is_string( $value ) ) {
		$value = json_decode( wp_unslash( $value ), true );
	}
	if ( ! is_array( $value ) ) {
		return array();
	}
	$facilities = array();
	foreach ( $value as $facility ) {
		if ( empty( $facility['id'] ) || empty( $facility['name'] ) ) {
			continue;
		}
		$facilities[] = array(
			'id' => sanitize_key( $facility['id'] ),
			'name' => sanitize_text_field( wp_unslash( $facility['name'] ) ),
			'fee' => sanitize_text_field( wp_unslash( $facility['fee'] ?? '' ) ),
			'start' => wpplugin_sanitize_time( wp_unslash( $facility['start'] ?? '09:00' ) ),
			'end' => wpplugin_sanitize_time( wp_unslash( $facility['end'] ?? '18:00' ) ),
			'info' => sanitize_textarea_field( wp_unslash( $facility['info'] ?? '' ) ),
			'additional_condition_1' => sanitize_text_field( wp_unslash( $facility['additional_condition_1'] ?? '' ) ),
			'additional_fee_1' => sanitize_text_field( wp_unslash( $facility['additional_fee_1'] ?? '' ) ),
			'additional_condition_2' => sanitize_text_field( wp_unslash( $facility['additional_condition_2'] ?? '' ) ),
			'additional_fee_2' => sanitize_text_field( wp_unslash( $facility['additional_fee_2'] ?? '' ) ),
			'additional_condition_3' => sanitize_text_field( wp_unslash( $facility['additional_condition_3'] ?? '' ) ),
			'additional_fee_3' => sanitize_text_field( wp_unslash( $facility['additional_fee_3'] ?? '' ) ),
			'reservation_type' => in_array( $facility['reservation_type'] ?? '時間貸', array( '時間貸', '宿泊', '両方' ), true ) ? $facility['reservation_type'] : '時間貸',
			'stay_start' => wpplugin_sanitize_time( wp_unslash( $facility['stay_start'] ?? '15:00' ) ),
			'stay_end' => wpplugin_sanitize_time( wp_unslash( $facility['stay_end'] ?? '10:00' ) ),
			'stay_fee' => sanitize_text_field( wp_unslash( $facility['stay_fee'] ?? '' ) ),
		);
	}
	return $facilities;
}

function wpplugin_render_facilities_field() {
	$facilities = get_option( WPPLUGIN_FACILITIES_OPTION, array() );
	if ( empty( $facilities ) ) {
		$facilities = wpplugin_get_facilities();
	}
	?>
	<textarea name="<?php echo esc_attr( WPPLUGIN_FACILITIES_OPTION ); ?>" rows="8" class="large-text code"><?php echo esc_textarea( wp_json_encode( $facilities, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) ); ?></textarea>
	<p class="description">施設一覧をJSONで設定します。id、name、fee、start、end、info、additional_condition_1〜3、additional_fee_1〜3、reservation_type（時間貸・宿泊・両方）、stay_start、stay_end、stay_feeを指定できます。</p>
	<?php
}

function wpplugin_render_time_options( $name, $available_start, $available_end, $selected = '' ) {
	$output = '<select name="' . esc_attr( $name ) . '" required>';
	$output .= '<option value="">選択してください</option>';
	$start_minutes = (int) substr( $available_start, 0, 2 ) * 60 + (int) substr( $available_start, 3, 2 );
	$end_minutes   = (int) substr( $available_end, 0, 2 ) * 60 + (int) substr( $available_end, 3, 2 );
	for ( $minutes = $start_minutes; $minutes <= $end_minutes; $minutes += 30 ) {
		$time = sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
		$output .= '<option value="' . esc_attr( $time ) . '"' . selected( $time, $selected, false ) . '>' . esc_html( $time ) . '</option>';
	}
	$output .= '</select>';

	return $output;
}

function wpplugin_render_settings_page() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'wpplugin_settings' );
			do_settings_sections( 'wpplugin-starter' );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function wpplugin_message_shortcode() {
	$message = get_option( WPPLUGIN_OPTION_NAME, 'Hello from WP Plugin Starter!' );

	return '<p class="wpplugin-message">' . esc_html( $message ) . '</p>';
}
add_shortcode( 'wpplugin_message', 'wpplugin_message_shortcode' );

function wpplugin_enqueue_calendar_styles() {
	wp_register_style(
		'wpplugin-calendar',
		plugins_url( 'calendar.css', __FILE__ ),
		array(),
		'1.0.35'
	);
	wp_register_script(
		'wpplugin-calendar',
		plugins_url( 'calendar.js', __FILE__ ),
		array(),
		'1.0.35',
		true
	);
}
add_action( 'wp_enqueue_scripts', 'wpplugin_enqueue_calendar_styles' );

function wpplugin_render_calendar_table( $year, $month, $today_date ) {
	$first_day  = mktime( 0, 0, 0, $month, 1, $year );
	$start_week = (int) wp_date( 'w', $first_day );
	$days_count = (int) wp_date( 't', $first_day );
	$weekdays   = array( '日', '月', '火', '水', '木', '金', '土' );

	$output  = '<table class="wpplugin-calendar__table">';
	$output .= '<thead><tr>';

	foreach ( $weekdays as $weekday ) {
		$output .= '<th scope="col">' . esc_html( $weekday ) . '</th>';
	}

	$output .= '</tr></thead><tbody><tr>';

	for ( $empty_day = 0; $empty_day < $start_week; $empty_day++ ) {
		$output .= '<td class="is-empty" aria-hidden="true"></td>';
	}

	for ( $day = 1; $day <= $days_count; $day++ ) {
		$date       = sprintf( '%04d-%02d-%02d', $year, $month, $day );
		$is_today   = $date === $today_date;
		$cell_class = $is_today ? ' class="is-today"' : '';
		$aria_label = $is_today ? ' aria-current="date"' : '';

		$output .= '<td' . $cell_class . $aria_label . '><button type="button" class="wpplugin-calendar__day" data-date="' . esc_attr( $date ) . '"><span class="wpplugin-calendar__day-number">' . esc_html( (string) $day ) . '</span></button></td>';

		$position = $start_week + $day;
		if ( 0 === $position % 7 && $day !== $days_count ) {
			$output .= '</tr><tr>';
		}
	}

	$remaining_days = ( 7 - ( ( $start_week + $days_count ) % 7 ) ) % 7;
	for ( $empty_day = 0; $empty_day < $remaining_days; $empty_day++ ) {
		$output .= '<td class="is-empty" aria-hidden="true"></td>';
	}

	$output .= '</tr></tbody></table>';

	return $output;
}

function wpplugin_calendar_shortcode() {
	if ( ! is_user_logged_in() ) {
		return '<p class="wpplugin-calendar__login-required">予約カレンダーを利用するにはログインしてください。</p>';
	}

	wp_enqueue_style( 'wpplugin-calendar' );
	wp_enqueue_script( 'wpplugin-calendar' );

	$today      = current_time( 'timestamp' );
	$month      = (int) wp_date( 'n', $today );
	$year       = (int) wp_date( 'Y', $today );
	$today_date = wp_date( 'Y-m-d', $today );
	$current_user = wp_get_current_user();
	$reservations = wpplugin_get_reservations();
	$facilities = wpplugin_get_facilities();
	$selected_facility = reset( $facilities );
	$facility_start = $selected_facility['start'];
	$facility_end   = $selected_facility['end'];
	$facility_type = wpplugin_reservation_type_for_facility( $selected_facility );
	$output     = '<div class="wpplugin-calendar" data-today="' . esc_attr( $today_date ) . '" data-default-name="' . esc_attr( $current_user->user_login ) . '" data-facilities="' . esc_attr( wp_json_encode( $facilities ) ) . '" data-reservations="' . esc_attr( wp_json_encode( $reservations ) ) . '">';
	$status = isset( $_GET['wpplugin_reservation_status'] ) ? sanitize_key( wp_unslash( $_GET['wpplugin_reservation_status'] ) ) : '';
	if ( 'saved' === $status ) {
		$output .= '<p class="wpplugin-reservation__notice" role="status">予約が完了しました。</p>';
	} elseif ( 'cancelled' === $status ) {
		$output .= '<p class="wpplugin-reservation__notice" role="status">予約キャンセルが完了しました。</p>';
	}
	$output    .= '<div class="wpplugin-calendar__header">';
	$output    .= '<div class="wpplugin-calendar__selectors">';
	$output    .= '<label><span class="screen-reader-text">施設を選択</span><select class="wpplugin-calendar__facility" aria-label="施設を選択">';
	foreach ( $facilities as $facility ) {
		$output .= '<option value="' . esc_attr( $facility['id'] ) . '">' . esc_html( $facility['name'] ) . '</option>';
	}
	$output .= '</select></label>';
	$output    .= '<label><span class="screen-reader-text">年を選択</span><select class="wpplugin-calendar__year" aria-label="年を選択">';

	for ( $year_option = $year - 10; $year_option <= $year + 10; $year_option++ ) {
		$output .= '<option value="' . esc_attr( (string) $year_option ) . '"' . selected( $year_option, $year, false ) . '>' . esc_html( (string) $year_option ) . '年</option>';
	}

	$output .= '</select>';
	$output .= '<label><span class="screen-reader-text">月を選択</span><select class="wpplugin-calendar__month" aria-label="月を選択">';

	for ( $month_option = 1; $month_option <= 12; $month_option++ ) {
		$output .= '<option value="' . esc_attr( (string) $month_option ) . '"' . selected( $month_option, $month, false ) . '>' . esc_html( (string) $month_option ) . '月</option>';
	}

	$output .= '</select></div></div>';
	$output .= '<button type="button" class="wpplugin-calendar__toggle" aria-expanded="false" aria-controls="wpplugin-calendar-panel">すべての日を表示</button>';
	$output .= '<div class="wpplugin-calendar__date-picker"><label><span class="screen-reader-text">日付を指定</span><select class="wpplugin-calendar__date-select" aria-label="日付を指定"><option value="">日付を指定</option></select></label></div>';
	$output .= '<div id="wpplugin-calendar-panel" class="wpplugin-calendar__panel" hidden><div class="wpplugin-calendar__grid">';
	$output .= wpplugin_render_calendar_table( $year, $month, $today_date );
	$output .= '</div><div class="wpplugin-calendar__list"></div></div><div class="wpplugin-calendar__selected-date-list" hidden></div></div>';
	$output .= '<section class="wpplugin-facility-info">';
	$output .= '<h2>施設情報</h2>';
	$output .= '<dl>';
	$output .= '<div><dt>施設名</dt><dd class="wpplugin-facility-info__name">' . esc_html( $selected_facility['name'] ) . '</dd></div>';
	$output .= '<div class="wpplugin-facility-info__fee-row"' . ( '宿泊' === $facility_type ? ' hidden' : '' ) . '><dt class="wpplugin-facility-info__fee-label">' . ( '両方' === $facility_type ? '利用料金（時間貸）' : '利用料金' ) . '</dt><dd class="wpplugin-facility-info__fee">' . esc_html( $selected_facility['fee'] ) . '</dd></div>';
	$output .= '<div class="wpplugin-facility-info__stay-fee-row"' . ( in_array( $facility_type, array( '両方', '宿泊' ), true ) ? '' : ' hidden' ) . '><dt>利用料金（宿泊）</dt><dd class="wpplugin-facility-info__stay-fee">' . esc_html( $selected_facility['stay_fee'] ?? '' ) . '／泊</dd></div>';
	$output .= '<div class="wpplugin-facility-info__hours-row"' . ( '宿泊' === $facility_type ? ' hidden' : '' ) . '><dt>利用可能時間（時間貸）</dt><dd class="wpplugin-facility-info__hours">' . esc_html( $facility_start . '〜' . $facility_end ) . '</dd></div>';
	$output .= '<div class="wpplugin-facility-info__stay-hours-row"' . ( in_array( $facility_type, array( '両方', '宿泊' ), true ) ? '' : ' hidden' ) . '><dt>利用可能時間（宿泊）</dt><dd class="wpplugin-facility-info__stay-hours">' . esc_html( ( $selected_facility['stay_start'] ?? '' ) . '〜' . ( $selected_facility['stay_end'] ?? '' ) ) . ' ※ 翌日</dd></div>';
	$output .= '<div class="wpplugin-facility-info__description-row"><dt>施設情報</dt><dd class="wpplugin-facility-info__description">' . nl2br( esc_html( $selected_facility['info'] ) ) . '</dd></div>';
	$output .= '</dl></section>';
	$output .= '<section class="wpplugin-reservation" hidden>'; 
	$output .= '<h2>予約内容</h2>';
	$output .= '<form class="wpplugin-reservation__form" method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
	$output .= '<input type="hidden" name="action" value="wpplugin_save_reservation">';
	$output .= '<input type="hidden" name="facility_id" class="wpplugin-reservation__facility-id" value="' . esc_attr( $selected_facility['id'] ) . '">';
	$output .= '<input type="hidden" name="reservation_id" class="wpplugin-reservation__id" value="">';
	$output .= wp_nonce_field( 'wpplugin_save_reservation', 'wpplugin_reservation_nonce', true, false );
	$output .= '<p class="wpplugin-reservation__selected">選択日: <strong class="wpplugin-reservation__selected-date"></strong></p>';
	$output .= '<p class="wpplugin-reservation__error" role="alert" hidden></p>';
	$output .= '<input type="hidden" name="reservation_date" class="wpplugin-reservation__date" required>';
	$output .= '<label>予約タイトル<input type="text" name="reservation_title" maxlength="200" required></label>';
	$output .= '<label>予約者名<input type="text" name="reservation_name" value="' . esc_attr( $current_user->user_login ) . '" maxlength="60" required></label>';
	$output .= '<label class="wpplugin-reservation__type-row" hidden>予約タイプ<select name="reservation_type" class="wpplugin-reservation__type"><option value="時間貸">時間貸</option><option value="宿泊">宿泊</option></select></label>';
	$output .= '<div class="wpplugin-reservation__time-row wpplugin-reservation__hourly-times">';
	$output .= '<label>開始時刻' . wpplugin_render_time_options( 'reservation_start', $facility_start, $facility_end ) . '</label>';
	$output .= '<label>終了時刻' . wpplugin_render_time_options( 'reservation_end', $facility_start, $facility_end ) . '</label>';
	$output .= '</div>';
	$output .= '<p class="wpplugin-reservation__stay-fee" hidden>宿泊 <span>（<strong class="wpplugin-reservation__stay-fee-value"></strong>／泊）</span></p>';
	$output .= '<div class="wpplugin-reservation__additional-conditions"></div>';
	$output .= '<label>詳細<textarea name="reservation_details" rows="5"></textarea></label>';
	$output .= '<button type="submit">予約を保存</button>';
	$output .= '<button type="submit" class="wpplugin-reservation__cancel" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" name="action" value="wpplugin_cancel_reservation" formmethod="post" onclick="return confirm(\'この予約をキャンセルしますか？\');" hidden>予約をキャンセル</button>';
	$output .= wp_nonce_field( 'wpplugin_cancel_reservation', 'wpplugin_cancel_nonce', true, false );
	$output .= '</form></section>';

	return $output;
}
add_shortcode( 'wpplugin_calendar', 'wpplugin_calendar_shortcode' );