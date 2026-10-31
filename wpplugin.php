<?php
/**
 * Plugin Name: 施設予約
 * Description: 施設管理と予約カレンダーを提供します。
 * Version: 1.0.0
 * Requires at least: 7.0
 * Requires PHP: 7.4
 * Author: t180miya
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
const WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY = 'manage_wpplugin_reservations';
const WPPLUGIN_ADMIN_MENU_SLUG = 'wpplugin-facility';
const WPPLUGIN_FACILITY_PAGE_SLUG = 'wpplugin-facilities';
const WPPLUGIN_CALENDAR_PAGE_SLUG = 'wpplugin-reservation-calendar';
const WPPLUGIN_RESERVATION_LIST_PAGE_SLUG = 'wpplugin-reservations';
const WPPLUGIN_SETTINGS_PAGE_SLUG = 'wpplugin-settings';
const WPPLUGIN_MANAGEMENT_EMAIL_OPTION = 'wpplugin_management_company_email';
const WPPLUGIN_STATUS_TENTATIVE = 'tentative';
const WPPLUGIN_LOG_OPTION = 'wpplugin_activity_log';
const WPPLUGIN_LOG_LIMIT = 1000;
const WPPLUGIN_BOOKING_DEADLINE_OPTION = 'wpplugin_booking_deadline_days';
const WPPLUGIN_CANCEL_DEADLINE_OPTION = 'wpplugin_cancel_deadline_days';

function wpplugin_sanitize_deadline_days( $value ) {
	$value = is_string( $value ) ? trim( mb_convert_kana( wp_unslash( $value ), 'n', 'UTF-8' ) ) : '';
	if ( '' === $value || ! preg_match( '/^\d{1,3}$/', $value ) ) {
		return '';
	}

	return (string) (int) $value;
}

function wpplugin_get_days_until( $date ) {
	$target = DateTimeImmutable::createFromFormat( '!Y-m-d', (string) $date, wp_timezone() );
	if ( ! $target ) {
		return null;
	}
	$today = new DateTimeImmutable( 'today', wp_timezone() );

	return (int) $today->diff( $target )->format( '%r%a' );
}

function wpplugin_get_booking_restriction_error( $date, $user = null ) {
	$days = get_option( WPPLUGIN_BOOKING_DEADLINE_OPTION, '' );
	if ( '' === (string) $days || wpplugin_user_can_manage_reservations( $user ) ) {
		return '';
	}
	$until = wpplugin_get_days_until( $date );
	if ( null !== $until && $until < (int) $days ) {
		return '予約は利用日の' . (int) $days . '日前まで可能です。この日付は予約できません。';
	}

	return '';
}

function wpplugin_get_cancel_restriction_error( $date, $user = null ) {
	$days = get_option( WPPLUGIN_CANCEL_DEADLINE_OPTION, '' );
	if ( '' === (string) $days || wpplugin_user_can_manage_reservations( $user ) ) {
		return '';
	}
	$until = wpplugin_get_days_until( $date );
	if ( null !== $until && $until <= (int) $days ) {
		return '利用日の' . (int) $days . '日前以降はキャンセルできません。';
	}

	return '';
}
const WPPLUGIN_STATUS_CONFIRMED = 'confirmed';

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
			'show_ui'      => false,
			'show_in_menu' => false,
			'supports'     => array( 'title', 'author' ),
			'capability_type' => 'post',
		)
	);
}
add_action( 'init', 'wpplugin_register_reservation_post_type' );

function wpplugin_grant_reservation_management_capability() {
	$role_names = array_merge( array( 'administrator' ), array( 'management_company', 'management-company', 'facility_manager' ) );
	foreach ( array_unique( $role_names ) as $role_name ) {
		$role = get_role( $role_name );
		if ( $role ) {
			$role->add_cap( WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY );
		}
	}
}
add_action( 'init', 'wpplugin_grant_reservation_management_capability', 20 );

function wpplugin_user_has_reservation_management_capability( $allcaps, $caps, $args, $user ) {
	if ( ! in_array( WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY, $caps, true ) ) {
		return $allcaps;
	}
	if ( ! empty( $allcaps['manage_options'] ) ) {
		$allcaps[ WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY ] = true;
		return $allcaps;
	}

	$known_role_names = array( 'management_company', 'management-company', 'facility_manager' );
	$role_definitions = wp_roles()->roles;
	foreach ( (array) $user->roles as $role_name ) {
		$role_label = isset( $role_definitions[ $role_name ]['name'] ) ? translate_user_role( $role_definitions[ $role_name ]['name'] ) : '';
		if ( in_array( $role_name, $known_role_names, true ) || false !== stripos( $role_name, 'management' ) || false !== stripos( $role_label, 'management' ) || false !== strpos( $role_label, '管理会社' ) ) {
			$allcaps[ WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY ] = true;
			return $allcaps;
		}
	}

	return $allcaps;
}
add_filter( 'user_has_cap', 'wpplugin_user_has_reservation_management_capability', 10, 4 );

function wpplugin_get_actor_label( $user = null ) {
	$user = $user ? $user : wp_get_current_user();
	if ( ! $user || ! $user->ID ) {
		return '未ログイン';
	}

	$suffix = '';
	if ( in_array( 'administrator', (array) $user->roles, true ) ) {
		$suffix = '（管理者）';
	} else {
		$role_definitions = wp_roles()->roles;
		foreach ( (array) $user->roles as $role_name ) {
			$role_label = isset( $role_definitions[ $role_name ]['name'] ) ? translate_user_role( $role_definitions[ $role_name ]['name'] ) : '';
			if ( in_array( $role_name, array( 'management_company', 'management-company', 'facility_manager' ), true ) || false !== stripos( $role_name, 'management' ) || false !== stripos( $role_label, 'management' ) || false !== strpos( $role_label, '管理会社' ) ) {
				$suffix = '（管理会社）';
				break;
			}
		}
	}

	return $user->display_name . '（' . $user->user_login . '）' . $suffix;
}

function wpplugin_get_reservation_status( $post_id ) {
	return WPPLUGIN_STATUS_TENTATIVE === get_post_meta( $post_id, '_wpplugin_reservation_status', true ) ? WPPLUGIN_STATUS_TENTATIVE : WPPLUGIN_STATUS_CONFIRMED;
}

function wpplugin_reservation_status_label( $status ) {
	return WPPLUGIN_STATUS_TENTATIVE === $status ? '仮予約' : '予約済';
}

function wpplugin_get_reservation_group_posts( $reservation_id ) {
	$group_id = (string) get_post_meta( $reservation_id, '_wpplugin_reservation_group', true );
	$posts = $group_id ? get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1, 'meta_key' => '_wpplugin_reservation_group', 'meta_value' => $group_id ) ) : array();
	if ( ! in_array( (int) $reservation_id, wp_list_pluck( $posts, 'ID' ), true ) ) {
		$post = get_post( $reservation_id );
		if ( $post ) {
			$posts[] = $post;
		}
	}

	return $posts;
}

function wpplugin_set_reservation_group_status( $reservation_id, $status ) {
	foreach ( wpplugin_get_reservation_group_posts( $reservation_id ) as $group_post ) {
		update_post_meta( $group_post->ID, '_wpplugin_reservation_status', $status );
	}
}

function wpplugin_get_reservation_notification_data( $reservation_id ) {
	$primary = null;
	foreach ( wpplugin_get_reservation_group_posts( $reservation_id ) as $group_post ) {
		if ( ! $primary || 'end' !== get_post_meta( $group_post->ID, '_wpplugin_reservation_stay_part', true ) ) {
			$primary = $group_post;
		}
	}
	if ( ! $primary ) {
		return null;
	}

	$facility = wpplugin_get_facility( (string) get_post_meta( $primary->ID, '_wpplugin_reservation_facility_id', true ) );
	$type = get_post_meta( $primary->ID, '_wpplugin_reservation_type', true ) ?: '時間貸';
	$start = get_post_meta( $primary->ID, '_wpplugin_reservation_start', true );
	$time_label = '宿泊' === $type && $facility ? $start . '〜' . $facility['stay_end'] . '（翌日）' : $start . '〜' . get_post_meta( $primary->ID, '_wpplugin_reservation_end', true );
	$quantities = get_post_meta( $primary->ID, '_wpplugin_reservation_additional_quantities', true );
	$additional = array();
	for ( $index = 1; $index <= 3; $index++ ) {
		$quantity = is_array( $quantities ) && isset( $quantities[ $index ] ) ? absint( $quantities[ $index ] ) : 0;
		if ( $facility && ! empty( $facility[ 'additional_condition_' . $index ] ) && $quantity ) {
			$additional[] = $facility[ 'additional_condition_' . $index ] . ' × ' . $quantity;
		}
	}
	$author = get_userdata( (int) $primary->post_author );

	return array(
		'id'            => $primary->ID,
		'email'         => $author ? $author->user_email : '',
		'facility_name' => $facility ? $facility['name'] : '削除済み施設',
		'date'          => get_post_meta( $primary->ID, '_wpplugin_reservation_date', true ),
		'time_label'    => $time_label,
		'type'          => $type,
		'title'         => $primary->post_title,
		'name'          => get_post_meta( $primary->ID, '_wpplugin_reservation_name', true ),
		'additional'    => implode( '、', $additional ),
		'details'       => get_post_meta( $primary->ID, '_wpplugin_reservation_details', true ),
		'status'        => wpplugin_get_reservation_status( $primary->ID ),
		'cost_by_association' => '1' === (string) get_post_meta( $primary->ID, '_wpplugin_reservation_cost_by_association', true ),
	);
}

function wpplugin_log_actions() {
	return array(
		'created'   => '予約登録',
		'updated'   => '予約変更',
		'tentative' => '仮予約申請',
		'approved'  => '承認',
		'rejected'  => '却下',
		'cancelled' => 'キャンセル',
	);
}

function wpplugin_log_reservation_summary( $data ) {
	if ( ! $data ) {
		return '';
	}

	$lines = array(
		'予約ID：' . $data['id'],
		'ステータス：' . wpplugin_reservation_status_label( $data['status'] ),
		'施設：' . $data['facility_name'],
		'利用日：' . $data['date'],
		'時刻：' . $data['time_label'],
		'予約タイプ：' . $data['type'],
		'予約タイトル：' . $data['title'],
		'予約者：' . $data['name'],
		'追加条件：' . $data['additional'],
		'管理組合が費用を負担：' . ( $data['cost_by_association'] ? 'はい' : 'いいえ' ),
		'詳細：' . $data['details'],
	);

	return implode( "\n", $lines );
}

function wpplugin_add_log( $action, $data ) {
	$user = wp_get_current_user();
	$logs = get_option( WPPLUGIN_LOG_OPTION, array() );
	$logs = is_array( $logs ) ? $logs : array();
	$logs[] = array(
		'time'           => current_time( 'mysql' ),
		'action'         => $action,
		'user_id'        => (int) $user->ID,
		'user'           => wpplugin_get_actor_label( $user ),
		'reservation_id' => $data ? (int) $data['id'] : 0,
		'summary'        => wpplugin_log_reservation_summary( $data ),
	);
	update_option( WPPLUGIN_LOG_OPTION, array_slice( $logs, -WPPLUGIN_LOG_LIMIT ), false );
}

function wpplugin_send_reservation_mail( $to, $subject, $lead, $data, $footer = '' ) {
	if ( ! $to || ! is_email( $to ) || ! $data ) {
		return false;
	}

	$site_name = trim( preg_replace( '/\s+/', ' ', wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES ) ) );
	$lines = array(
		$lead,
		'',
		'操作者：' . wpplugin_get_actor_label(),
		'施設：' . $data['facility_name'],
		'利用日：' . $data['date'],
		'時刻：' . $data['time_label'],
		'予約タイプ：' . $data['type'],
		'予約タイトル：' . $data['title'],
		'予約者：' . $data['name'],
		'追加条件：' . $data['additional'],
		'詳細：' . $data['details'],
	);
	if ( $footer ) {
		$lines[] = '';
		$lines[] = $footer;
	}

	return wp_mail( $to, '[' . $site_name . '] ' . $subject, implode( "\n", $lines ) );
}

function wpplugin_notify_applicant( $data, $kind ) {
	if ( ! $data ) {
		return;
	}
	$messages = array(
		'confirmed' => array( '予約が確定しました', '以下の予約が確定しました。' ),
		'cancelled' => array( '予約がキャンセルされました', '以下の予約がキャンセルされました。' ),
		'rejected'  => array( '仮予約について「管理組合が費用を負担」の確認をお願いします', '以下の仮予約は、「管理組合が費用を負担」とは認められませんでした。予約内容の画面で「管理組合が費用を負担」のチェックを外して、予約を保存し直してください。' ),
	);
	if ( isset( $messages[ $kind ] ) ) {
		wpplugin_send_reservation_mail( $data['email'], $messages[ $kind ][0], $messages[ $kind ][1], $data );
	}
}

function wpplugin_notify_management_company( $data, $confirmed = false ) {
	$to = get_option( WPPLUGIN_MANAGEMENT_EMAIL_OPTION, '' );
	if ( ! is_string( $to ) || preg_match( '/[\r\n,;<>"\s]/', $to ) ) {
		return;
	}
	if ( $confirmed ) {
		wpplugin_send_reservation_mail( $to, '予約がありました（内容のご確認）', '以下の予約が登録されました。承認の操作は不要ですが、予約内容をご確認ください。', $data, admin_url( 'admin.php?page=' . WPPLUGIN_RESERVATION_LIST_PAGE_SLUG ) );
		return;
	}
	wpplugin_send_reservation_mail( $to, '仮予約の承認をお願いします', '以下の予約が「管理組合が費用を負担」の仮予約として申請されました。予約管理画面で内容を確認し、承認または却下してください。', $data, admin_url( 'admin.php?page=' . WPPLUGIN_RESERVATION_LIST_PAGE_SLUG ) );
}

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

	$is_new_reservation = ! ( $reservation_id && get_post_type( $reservation_id ) === WPPLUGIN_RESERVATION_POST_TYPE );
	$original_date = '';
	if ( ! $is_new_reservation ) {
		foreach ( wpplugin_get_reservation_group_posts( $reservation_id ) as $group_post ) {
			if ( ! $original_date || 'end' !== get_post_meta( $group_post->ID, '_wpplugin_reservation_stay_part', true ) ) {
				$original_date = (string) get_post_meta( $group_post->ID, '_wpplugin_reservation_date', true );
			}
		}
	}
	if ( $is_new_reservation || $original_date !== $date ) {
		$booking_error = wpplugin_get_booking_restriction_error( $date );
		if ( $booking_error ) {
			wp_die( esc_html( $booking_error ) );
		}
		$cancel_error = $original_date ? wpplugin_get_cancel_restriction_error( $original_date ) : '';
		if ( $cancel_error ) {
			wp_die( esc_html( '予約日を変更できません。' . $cancel_error ) );
		}
	}

	$post_data = array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_title' => $title, 'post_status' => 'publish' );
	$cost_by_association = ! empty( $_POST['cost_by_association'] );
	$new_status = $cost_by_association ? WPPLUGIN_STATUS_TENTATIVE : WPPLUGIN_STATUS_CONFIRMED;
	$previous_status = '';
	if ( $reservation_id && get_post_type( $reservation_id ) === WPPLUGIN_RESERVATION_POST_TYPE ) {
		if ( ! wpplugin_user_can_edit_reservation( $reservation_id ) ) {
			wp_die( 'この予約を編集する権限がありません。' );
		}
		$previous_status = wpplugin_get_reservation_status( $reservation_id );
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
	$save_period = function( $reservation_post_id, $period, $part ) use ( $facility_id, $name, $details, $reservation_type, $additional_quantities, $group_id, $new_status, $cost_by_association ) {
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_status', $new_status );
		update_post_meta( $reservation_post_id, '_wpplugin_reservation_cost_by_association', $cost_by_association ? '1' : '0' );
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
	$log_notification = wpplugin_get_reservation_notification_data( $post_id );
	wpplugin_add_log( $previous_status ? 'updated' : 'created', $log_notification );
	if ( $new_status !== $previous_status ) {
		$notification = $log_notification;
		if ( WPPLUGIN_STATUS_TENTATIVE === $new_status ) {
			wpplugin_add_log( 'tentative', $notification );
		}
		if ( WPPLUGIN_STATUS_TENTATIVE === $new_status ) {
			wpplugin_notify_management_company( $notification );
		} else {
			wpplugin_notify_applicant( $notification, 'confirmed' );
			if ( ! empty( $facility['notify_management'] ) ) {
				wpplugin_notify_management_company( $notification, true );
			}
		}
	}
	$redirect_url = wp_get_referer() ? wp_get_referer() : home_url( '/' );
	wp_safe_redirect( add_query_arg( 'wpplugin_reservation_status', 'saved', $redirect_url ) );
	exit;
}
add_action( 'admin_post_wpplugin_save_reservation', 'wpplugin_save_reservation' );
add_action( 'admin_post_nopriv_wpplugin_save_reservation', 'wpplugin_save_reservation' );

function wpplugin_cancel_reservation_group( $reservation_id, $user = null ) {
	$reservation_id = absint( $reservation_id );
	if ( ! $reservation_id || WPPLUGIN_RESERVATION_POST_TYPE !== get_post_type( $reservation_id ) || ! wpplugin_user_can_edit_reservation( $reservation_id, $user ) ) {
		return false;
	}

	$group_id = (string) get_post_meta( $reservation_id, '_wpplugin_reservation_group', true );
	$group_posts = $group_id ? get_posts( array( 'post_type' => WPPLUGIN_RESERVATION_POST_TYPE, 'post_status' => array( 'private', 'publish' ), 'numberposts' => -1, 'meta_key' => '_wpplugin_reservation_group', 'meta_value' => $group_id ) ) : array();
	if ( empty( $group_posts ) ) {
		$group_posts = array( get_post( $reservation_id ) );
	} elseif ( ! in_array( $reservation_id, wp_list_pluck( $group_posts, 'ID' ), true ) ) {
		$group_posts[] = get_post( $reservation_id );
	}
	$group_posts = array_values( array_filter( $group_posts ) );

	$cancel_date = '';
	foreach ( $group_posts as $group_post ) {
		$post_date = (string) get_post_meta( $group_post->ID, '_wpplugin_reservation_date', true );
		if ( '' === $cancel_date || ( $post_date && $post_date < $cancel_date ) ) {
			$cancel_date = $post_date;
		}
	}
	$cancel_error = wpplugin_get_cancel_restriction_error( $cancel_date, $user );
	if ( $cancel_error ) {
		$GLOBALS['wpplugin_cancel_error'] = $cancel_error;
		return false;
	}
	foreach ( $group_posts as $group_post ) {
		if ( WPPLUGIN_RESERVATION_POST_TYPE !== get_post_type( $group_post->ID ) || ! wpplugin_user_can_edit_reservation( $group_post->ID, $user ) ) {
			return false;
		}
	}

	$notification = wpplugin_get_reservation_notification_data( $reservation_id );
	foreach ( $group_posts as $group_post ) {
		if ( ! wp_delete_post( $group_post->ID, true ) ) {
			return false;
		}
	}
	wpplugin_add_log( 'cancelled', $notification );
	wpplugin_notify_applicant( $notification, 'cancelled' );

	return true;
}

function wpplugin_cancel_reservation() {
	if ( ! is_user_logged_in() || ! check_admin_referer( 'wpplugin_cancel_reservation', 'wpplugin_cancel_nonce' ) ) {
		wp_die( '予約をキャンセルできませんでした。' );
	}

	$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
	if ( ! wpplugin_cancel_reservation_group( $reservation_id ) ) {
		wp_die( esc_html( ! empty( $GLOBALS['wpplugin_cancel_error'] ) ? $GLOBALS['wpplugin_cancel_error'] : 'この予約をキャンセルする権限がありません。' ) );
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
				'status' => wpplugin_get_reservation_status( $post->ID ),
				'cost_by_association' => '1' === get_post_meta( $post->ID, '_wpplugin_reservation_cost_by_association', true ),
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
	$manage = WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY;
	add_menu_page( '施設予約', '施設予約', 'read', WPPLUGIN_ADMIN_MENU_SLUG, 'wpplugin_render_admin_calendar_page', 'dashicons-calendar-alt', 26 );
	add_submenu_page( WPPLUGIN_ADMIN_MENU_SLUG, '予約管理', '予約管理', $manage, WPPLUGIN_RESERVATION_LIST_PAGE_SLUG, 'wpplugin_render_reservation_management_page' );
	add_submenu_page( WPPLUGIN_ADMIN_MENU_SLUG, '施設管理', '施設管理', $manage, WPPLUGIN_FACILITY_PAGE_SLUG, 'wpplugin_render_settings_page' );
	add_submenu_page( WPPLUGIN_ADMIN_MENU_SLUG, '設定', '設定', $manage, WPPLUGIN_SETTINGS_PAGE_SLUG, 'wpplugin_render_plugin_settings_page' );
	add_submenu_page( WPPLUGIN_ADMIN_MENU_SLUG, '予約', '予約', 'read', WPPLUGIN_CALENDAR_PAGE_SLUG, 'wpplugin_render_admin_calendar_page' );
	remove_submenu_page( WPPLUGIN_ADMIN_MENU_SLUG, WPPLUGIN_ADMIN_MENU_SLUG );
}
add_action( 'admin_menu', 'wpplugin_add_settings_page' );

function wpplugin_option_page_capability( $capability ) {
	return WPPLUGIN_MANAGE_RESERVATIONS_CAPABILITY;
}
add_filter( 'option_page_capability_wpplugin_settings', 'wpplugin_option_page_capability' );
add_filter( 'option_page_capability_wpplugin_general_settings', 'wpplugin_option_page_capability' );

function wpplugin_enqueue_facility_admin_assets( $hook_suffix ) {
	$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
	if ( ! in_array( $page, array( WPPLUGIN_FACILITY_PAGE_SLUG, WPPLUGIN_CALENDAR_PAGE_SLUG, WPPLUGIN_RESERVATION_LIST_PAGE_SLUG, WPPLUGIN_SETTINGS_PAGE_SLUG ), true ) ) {
		return;
	}

	wp_enqueue_style( 'wpplugin-facility-admin', plugins_url( 'facility-admin.css', __FILE__ ), array(), '1.0.10' );
	if ( WPPLUGIN_FACILITY_PAGE_SLUG === $page ) {
		wp_enqueue_script( 'wpplugin-facility-admin', plugins_url( 'facility-admin.js', __FILE__ ), array(), '1.0.2', true );
	}
	if ( WPPLUGIN_CALENDAR_PAGE_SLUG === $page ) {
		wp_enqueue_style( 'wpplugin-calendar', plugins_url( 'calendar.css', __FILE__ ), array(), '1.0.37' );
		wp_enqueue_script( 'wpplugin-calendar', plugins_url( 'calendar.js', __FILE__ ), array(), '1.0.38', true );
	}
}
add_action( 'admin_enqueue_scripts', 'wpplugin_enqueue_facility_admin_assets' );

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

	add_settings_section( 'wpplugin_facility_section', '施設定義', '__return_false', WPPLUGIN_FACILITY_PAGE_SLUG );
	add_settings_field( 'wpplugin_facilities_field', '施設一覧', 'wpplugin_render_facilities_field', WPPLUGIN_FACILITY_PAGE_SLUG, 'wpplugin_facility_section' );

	register_setting( 'wpplugin_general_settings', WPPLUGIN_MANAGEMENT_EMAIL_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'wpplugin_sanitize_management_email', 'default' => '' ) );
	register_setting( 'wpplugin_general_settings', WPPLUGIN_BOOKING_DEADLINE_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'wpplugin_sanitize_deadline_days', 'default' => '' ) );
	register_setting( 'wpplugin_general_settings', WPPLUGIN_CANCEL_DEADLINE_OPTION, array( 'type' => 'string', 'sanitize_callback' => 'wpplugin_sanitize_deadline_days', 'default' => '' ) );
	add_settings_section( 'wpplugin_general_section', '通知設定', '__return_false', WPPLUGIN_SETTINGS_PAGE_SLUG );
	add_settings_field( 'wpplugin_management_email_field', '管理会社のメールアドレス', 'wpplugin_render_management_email_field', WPPLUGIN_SETTINGS_PAGE_SLUG, 'wpplugin_general_section' );
	add_settings_section( 'wpplugin_deadline_section', '予約・キャンセルの期限', '__return_false', WPPLUGIN_SETTINGS_PAGE_SLUG );
	add_settings_field( 'wpplugin_booking_deadline_field', '予約可能な期限', 'wpplugin_render_booking_deadline_field', WPPLUGIN_SETTINGS_PAGE_SLUG, 'wpplugin_deadline_section' );
	add_settings_field( 'wpplugin_cancel_deadline_field', 'キャンセル不可の期間', 'wpplugin_render_cancel_deadline_field', WPPLUGIN_SETTINGS_PAGE_SLUG, 'wpplugin_deadline_section' );
}
add_action( 'admin_init', 'wpplugin_register_settings' );

function wpplugin_sanitize_management_email( $value ) {
	$previous = (string) get_option( WPPLUGIN_MANAGEMENT_EMAIL_OPTION, '' );
	if ( ! wpplugin_user_can_manage_reservations() ) {
		return $previous;
	}

	$value = is_string( $value ) ? trim( wp_unslash( $value ) ) : '';
	if ( '' === $value ) {
		return '';
	}
	// 改行・複数宛先・表示名付き形式を拒否し、単一のメールアドレスだけを許可する。
	if ( strlen( $value ) > 254 || preg_match( '/[\r\n,;<>"\s]/', $value ) || ! is_email( $value ) ) {
		add_settings_error( WPPLUGIN_MANAGEMENT_EMAIL_OPTION, 'wpplugin_invalid_management_email', '管理会社のメールアドレスの形式が正しくありません。以前の設定を維持しました。' );
		return $previous;
	}

	return sanitize_email( $value );
}

function wpplugin_render_booking_deadline_field() {
	?>
	利用日の <input type="number" name="<?php echo esc_attr( WPPLUGIN_BOOKING_DEADLINE_OPTION ); ?>" value="<?php echo esc_attr( get_option( WPPLUGIN_BOOKING_DEADLINE_OPTION, '' ) ); ?>" class="small-text" min="0" max="999" step="1" /> 日前まで予約可能
	<p class="description">例：3 → 利用日の3日前までの予約のみ受け付けます。0 は当日まで、空欄は制限なしです。管理者・管理会社は制限を受けません。</p>
	<?php
}

function wpplugin_render_cancel_deadline_field() {
	?>
	利用日の <input type="number" name="<?php echo esc_attr( WPPLUGIN_CANCEL_DEADLINE_OPTION ); ?>" value="<?php echo esc_attr( get_option( WPPLUGIN_CANCEL_DEADLINE_OPTION, '' ) ); ?>" class="small-text" min="0" max="999" step="1" /> 日前以降はキャンセル不可
	<p class="description">例：3 → 利用日の3日前から当日まで、キャンセルできません。0 は当日のみ不可、空欄は制限なしです。管理者・管理会社は制限を受けません。</p>
	<?php
}

function wpplugin_render_management_email_field() {
	?>
	<input type="email" name="<?php echo esc_attr( WPPLUGIN_MANAGEMENT_EMAIL_OPTION ); ?>" value="<?php echo esc_attr( get_option( WPPLUGIN_MANAGEMENT_EMAIL_OPTION, '' ) ); ?>" class="regular-text" maxlength="254" autocomplete="off" spellcheck="false" />
	<p class="description">「管理組合が費用を負担」の仮予約や、「予約時管理会社に通知」が有効な施設の予約があったとき、このアドレスに通知します。メールアドレスは1件のみ指定できます。空欄の場合は通知しません。</p>
	<?php
}

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
			'notify_management' => ! empty( $facility['notify_management'] ) && '0' !== (string) $facility['notify_management'] ? '1' : '',
		);
	}
	return $facilities;
}

function wpplugin_render_facilities_field() {
	$facilities = get_option( WPPLUGIN_FACILITIES_OPTION, array() );
	if ( empty( $facilities ) ) {
		$facilities = wpplugin_get_facilities();
	}
	$columns = array(
		'id' => '施設ID',
		'name' => '施設名',
		'reservation_type' => '時間貸／宿泊／両方',
		'fee' => '利用料金',
		'start' => '時間貸開始',
		'end' => '時間貸終了',
		'stay_start' => '宿泊開始',
		'stay_end' => '宿泊終了',
		'stay_fee' => '宿泊料金',
		'additional_condition_1' => '追加条件名1',
		'additional_fee_1' => '追加料金1',
		'additional_condition_2' => '追加条件名2',
		'additional_fee_2' => '追加料金2',
		'additional_condition_3' => '追加条件名3',
		'additional_fee_3' => '追加料金3',
		'info' => '施設情報・説明',
		'notify_management' => '予約時管理会社に通知',
	);
	?>
	<div class="wpplugin-facility-manager" data-facilities="<?php echo esc_attr( wp_json_encode( $facilities ) ); ?>">
		<input type="hidden" class="wpplugin-facility-manager__data" name="<?php echo esc_attr( WPPLUGIN_FACILITIES_OPTION ); ?>" value="<?php echo esc_attr( wp_json_encode( $facilities ) ); ?>" />
		<div class="wpplugin-facility-manager__toolbar">
			<div class="wpplugin-facility-manager__actions">
				<button type="button" class="button button-primary" data-action="add">新規追加</button>
				<button type="button" class="button" data-action="edit" disabled>編集</button>
				<button type="button" class="button" data-action="delete" disabled>削除</button>
			</div>
			<p class="description">行を1つ選択して編集・削除できます。上下ボタンで予約画面の施設表示順を変更します。</p>
		</div>
		<div class="wpplugin-facility-manager__table-wrap">
			<table class="widefat striped wpplugin-facility-manager__table">
				<thead><tr><th scope="col" class="wpplugin-facility-manager__select-column">選択</th><?php foreach ( $columns as $label ) : ?><th scope="col"><?php echo esc_html( $label ); ?></th><?php endforeach; ?><th scope="col">表示順</th></tr></thead>
				<tbody class="wpplugin-facility-manager__rows">
					<?php foreach ( $facilities as $facility ) : ?>
						<tr data-facility-id="<?php echo esc_attr( $facility['id'] ); ?>">
							<th scope="row" class="wpplugin-facility-manager__select-column"><input type="radio" name="wpplugin_facility_selected" value="<?php echo esc_attr( $facility['id'] ); ?>" aria-label="<?php echo esc_attr( $facility['name'] ); ?>を選択" /></th>
							<?php foreach ( $columns as $key => $label ) : ?><td><?php echo esc_html( 'notify_management' === $key ? ( ! empty( $facility[ $key ] ) ? 'あり' : 'なし' ) : ( isset( $facility[ $key ] ) ? $facility[ $key ] : '' ) ); ?></td><?php endforeach; ?>
							<td class="wpplugin-facility-manager__sort"><button type="button" class="button button-small" data-action="up" aria-label="<?php echo esc_attr( $facility['name'] ); ?>を上へ">↑</button> <button type="button" class="button button-small" data-action="down" aria-label="<?php echo esc_attr( $facility['name'] ); ?>を下へ">↓</button></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<p class="wpplugin-facility-manager__empty" <?php echo empty( $facilities ) ? '' : 'hidden'; ?>>施設が登録されていません。</p>
		</div>
		<dialog class="wpplugin-facility-manager__dialog">
			<div class="wpplugin-facility-manager__form">
				<h2 class="wpplugin-facility-manager__dialog-title">施設を追加</h2>
				<input type="hidden" name="id" />
				<div class="wpplugin-facility-manager__form-grid">
					<label>施設名<input name="name" type="text" maxlength="200" /></label>
					<label>予約タイプ<select name="reservation_type"><option value="時間貸">時間貸</option><option value="宿泊">宿泊</option><option value="両方">両方</option></select></label>
					<label>時間貸料金<input name="fee" type="text" /></label>
					<label>時間貸開始時刻<input name="start" type="text" inputmode="numeric" placeholder="09:00" value="09:00" /></label>
					<label>時間貸終了時刻<input name="end" type="text" inputmode="numeric" placeholder="18:00" value="18:00" /></label>
					<label>宿泊料金<input name="stay_fee" type="text" /></label>
					<label>宿泊開始時刻<input name="stay_start" type="text" inputmode="numeric" placeholder="15:00" value="15:00" /></label>
					<label>宿泊終了時刻<input name="stay_end" type="text" inputmode="numeric" placeholder="10:00" value="10:00" /></label>
					<?php for ( $index = 1; $index <= 3; $index++ ) : ?>
						<label>追加条件名<?php echo esc_html( (string) $index ); ?><input name="additional_condition_<?php echo esc_attr( (string) $index ); ?>" type="text" /></label>
						<label>追加条件の料金<?php echo esc_html( (string) $index ); ?><input name="additional_fee_<?php echo esc_attr( (string) $index ); ?>" type="text" /></label>
					<?php endfor; ?>
					<label class="wpplugin-facility-manager__info">施設情報・説明<textarea name="info" rows="5"></textarea></label>
					<label class="wpplugin-facility-manager__check"><input name="notify_management" type="checkbox" value="1" /> 予約時管理会社に通知</label>
				</div>
				<div class="wpplugin-facility-manager__dialog-actions"><button type="button" class="button" data-action="close">キャンセル</button><button type="button" class="button button-primary" data-action="save-facility">保存</button></div>
			</div>
		</dialog>
		<p class="wpplugin-facility-manager__notice" role="status" aria-live="polite"></p>
	</div>
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
	if ( ! wpplugin_user_can_manage_reservations() ) {
		wp_die( '施設管理を表示する権限がありません。' );
	}
	?>
	<div class="wrap wpplugin-facility-admin-page">
		<h1>施設管理</h1>
		<?php settings_errors(); ?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'wpplugin_settings' );
			do_settings_sections( WPPLUGIN_FACILITY_PAGE_SLUG );
			submit_button();
			?>
		</form>
	</div>
	<?php
}

function wpplugin_render_plugin_settings_page() {
	if ( ! wpplugin_user_can_manage_reservations() ) {
		wp_die( '設定を表示する権限がありません。' );
	}
	?>
	<div class="wrap wpplugin-facility-admin-page">
		<h1>設定</h1>
		<?php settings_errors(); ?>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'wpplugin_general_settings' );
			do_settings_sections( WPPLUGIN_SETTINGS_PAGE_SLUG );
			submit_button();
			?>
		</form>
		<?php wpplugin_render_activity_log(); ?>
	</div>
	<?php
}

function wpplugin_render_activity_log() {
	$logs = get_option( WPPLUGIN_LOG_OPTION, array() );
	$logs = is_array( $logs ) ? array_reverse( $logs ) : array();
	$labels = wpplugin_log_actions();
	?>
	<h2>操作ログ</h2>
	<p class="description">予約・変更・承認・却下・キャンセルの履歴を新しい順に表示します（最大<?php echo esc_html( (string) WPPLUGIN_LOG_LIMIT ); ?>件）。</p>
	<table class="widefat striped">
		<thead><tr><th>日時</th><th>操作</th><th>実行者</th><th>予約内容</th></tr></thead>
		<tbody>
			<?php if ( empty( $logs ) ) : ?>
				<tr><td colspan="4">ログはありません。</td></tr>
			<?php endif; ?>
			<?php foreach ( $logs as $log ) : ?>
				<tr>
					<td><?php echo esc_html( $log['time'] ); ?></td>
					<td><?php echo esc_html( isset( $labels[ $log['action'] ] ) ? $labels[ $log['action'] ] : $log['action'] ); ?></td>
					<td><?php echo esc_html( $log['user'] ); ?></td>
					<td><?php echo nl2br( esc_html( $log['summary'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<?php
}

function wpplugin_render_admin_calendar_page() {
	if ( ! is_user_logged_in() ) {
		wp_die( '予約画面を表示する権限がありません。' );
	}
	?>
	<div class="wrap wpplugin-admin-calendar">
		<h1>予約</h1>
		<?php echo do_shortcode( '[wpplugin_calendar]' ); ?>
	</div>
	<?php
}

function wpplugin_sanitize_reservation_month( $month ) {
	$month = is_string( $month ) ? sanitize_text_field( wp_unslash( $month ) ) : '';
	if ( ! preg_match( '/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $matches ) ) {
		return '';
	}

	return checkdate( (int) $matches[2], 1, (int) $matches[1] ) ? $month : '';
}

function wpplugin_csv_safe_value( $value ) {
	$value = (string) $value;
	return preg_match( '/^[\x09\x0D =+@\-]/', $value ) ? "'" . $value : $value;
}

function wpplugin_parse_fee( $fee ) {
	$fee = mb_convert_kana( (string) $fee, 'n', 'UTF-8' );
	$fee = preg_replace( '/[^0-9.]/', '', $fee );
	return '' === $fee ? 0 : (float) $fee;
}

function wpplugin_get_reservation_nights( $reservation ) {
	if ( empty( $reservation['group_id'] ) ) {
		return 1;
	}
	$end_posts = get_posts( array(
		'post_type' => WPPLUGIN_RESERVATION_POST_TYPE,
		'post_status' => array( 'private', 'publish' ),
		'numberposts' => 1,
		'meta_query' => array(
			array( 'key' => '_wpplugin_reservation_group', 'value' => $reservation['group_id'] ),
			array( 'key' => '_wpplugin_reservation_stay_part', 'value' => 'end' ),
		),
	) );
	if ( empty( $end_posts ) ) {
		return 1;
	}
	$end_date = get_post_meta( $end_posts[0]->ID, '_wpplugin_reservation_date', true );
	$days = (int) round( ( strtotime( $end_date ) - strtotime( $reservation['date'] ) ) / DAY_IN_SECONDS );
	return max( 1, $days );
}

function wpplugin_get_reservation_hours( $reservation ) {
	$start = (int) substr( $reservation['start'], 0, 2 ) * 60 + (int) substr( $reservation['start'], 3, 2 );
	$end   = (int) substr( $reservation['end'], 0, 2 ) * 60 + (int) substr( $reservation['end'], 3, 2 );
	return max( 0, ( $end - $start ) / 60 );
}

function wpplugin_get_reservation_management_rows( $month ) {
	$reservations = wpplugin_get_reservations();
	$facilities = array();
	foreach ( wpplugin_get_facilities() as $facility ) {
		$facilities[ (string) $facility['id'] ] = $facility;
	}
	$rows = array();
	foreach ( $reservations as $reservation ) {
		if ( 'end' === get_post_meta( $reservation['id'], '_wpplugin_reservation_stay_part', true ) || 0 !== strpos( $reservation['date'], $month . '-' ) ) {
			continue;
		}

		$facility = $facilities[ (string) $reservation['facility_id'] ] ?? null;
		$time_label = '宿泊' === $reservation['reservation_type'] && $facility ? $reservation['start'] . '〜' . $facility['stay_end'] . '（翌日）' : $reservation['start'] . '〜' . $reservation['end'];
		$additional_labels = array();
		for ( $index = 1; $index <= 3; $index++ ) {
			$quantity = isset( $reservation['additional_quantities'][ $index ] ) ? absint( $reservation['additional_quantities'][ $index ] ) : 0;
			$condition = $facility['additional_condition_' . $index ] ?? '';
			if ( $condition && $quantity ) {
				$additional_labels[] = $condition . ' × ' . $quantity;
			}
		}
		$rows[] = array(
			'reservation' => $reservation,
			'facility_name' => $facility['name'] ?? '削除済み施設',
			'time_label' => $time_label,
			'additional_label' => implode( '、', $additional_labels ),
			'facility' => $facility,
			'nights' => '宿泊' === $reservation['reservation_type'] ? wpplugin_get_reservation_nights( $reservation ) : 0,
		);
	}

	usort( $rows, function( $first, $second ) {
		return array( $second['reservation']['date'], $second['reservation']['start'] ) <=> array( $first['reservation']['date'], $first['reservation']['start'] );
	} );

	return $rows;
}

function wpplugin_render_reservation_management_page() {
	if ( ! wpplugin_user_can_manage_reservations() ) {
		wp_die( '予約管理を表示する権限がありません。' );
	}
	$month = wpplugin_sanitize_reservation_month( isset( $_GET['reservation_month'] ) ? $_GET['reservation_month'] : '' );
	if ( ! $month ) {
		$month = current_time( 'Y-m' );
	}
	$all_rows = wpplugin_get_reservation_management_rows( $month );
	$tentative_count = count( array_filter( $all_rows, function( $row ) {
		return WPPLUGIN_STATUS_TENTATIVE === $row['reservation']['status'];
	} ) );
	$filter = isset( $_GET['reservation_filter'] ) && 'tentative' === sanitize_key( wp_unslash( $_GET['reservation_filter'] ) ) ? 'tentative' : '';
	$rows = $filter ? array_values( array_filter( $all_rows, function( $row ) {
		return WPPLUGIN_STATUS_TENTATIVE === $row['reservation']['status'];
	} ) ) : $all_rows;
	$filter_url = function( $value ) use ( $month ) {
		$args = array( 'page' => WPPLUGIN_RESERVATION_LIST_PAGE_SLUG, 'reservation_month' => $month );
		if ( $value ) {
			$args['reservation_filter'] = $value;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	};
	$status = isset( $_GET['wpplugin_reservation_admin_status'] ) ? sanitize_key( wp_unslash( $_GET['wpplugin_reservation_admin_status'] ) ) : '';
	$processed_count = isset( $_GET['processed'] ) ? absint( $_GET['processed'] ) : 0;
	?>
	<div class="wrap wpplugin-facility-admin-page">
		<h1>予約管理</h1>
		<?php if ( 'cancelled' === $status ) : ?><div class="notice notice-success is-dismissible"><p>予約を<?php echo esc_html( $processed_count ); ?>件キャンセルしました。</p></div><?php endif; ?>
		<?php if ( 'approved' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $processed_count ); ?>件の仮予約を承認しました。</p></div><?php endif; ?>
		<?php if ( 'rejected' === $status ) : ?><div class="notice notice-success is-dismissible"><p><?php echo esc_html( $processed_count ); ?>件の仮予約を却下し、申請者にメールを送信しました。</p></div><?php endif; ?>
		<?php if ( 'none' === $status ) : ?><div class="notice notice-warning is-dismissible"><p>処理できる予約が選択されていません。</p></div><?php endif; ?>
		<form method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>" class="wpplugin-reservation-month-filter">
			<input type="hidden" name="page" value="<?php echo esc_attr( WPPLUGIN_RESERVATION_LIST_PAGE_SLUG ); ?>" />
			<label for="wpplugin-reservation-month">対象月</label>
			<input id="wpplugin-reservation-month" type="month" name="reservation_month" value="<?php echo esc_attr( $month ); ?>" required />
			<input type="hidden" name="reservation_filter" value="<?php echo esc_attr( $filter ); ?>" />
			<button type="submit" class="button">表示</button>
		</form>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpplugin-reservation-csv-export">
			<input type="hidden" name="action" value="wpplugin_export_reservations_csv" />
			<input type="hidden" name="reservation_month" value="<?php echo esc_attr( $month ); ?>" />
			<?php wp_nonce_field( 'wpplugin_export_reservations_csv', 'wpplugin_reservation_csv_nonce' ); ?>
			<button type="submit" class="button button-primary">この月のCSVをダウンロード</button>
		</form>
		<form id="wpplugin-reservation-bulk-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="wpplugin-reservation-bulk-actions" onsubmit="var a=this.elements.bulk_action.value;if(!a){alert('操作を選択してください。');return false;}if(!document.querySelector('.wpplugin-reservation-select:checked')){alert('予約を選択してください。');return false;}if(a==='reject'){return confirm('選択した仮予約を却下し、申請者に「管理組合が費用を負担」のチェックを外すよう通知しますか？');}if(a==='cancel'){return confirm('選択した予約をキャンセルしますか？');}return true;">
			<input type="hidden" name="action" value="wpplugin_bulk_reservations" />
			<input type="hidden" name="reservation_month" value="<?php echo esc_attr( $month ); ?>" />
			<?php wp_nonce_field( 'wpplugin_bulk_reservations', 'wpplugin_bulk_nonce' ); ?>
			<label for="wpplugin-bulk-action" class="screen-reader-text">一括操作</label>
			<select id="wpplugin-bulk-action" name="bulk_action">
				<option value="">一括操作を選択</option>
				<option value="approve">選択した仮予約を承認</option>
				<option value="reject">選択した仮予約を却下</option>
				<option value="cancel">予約をキャンセル</option>
			</select>
			<button type="submit" class="button">適用</button>
			<input type="hidden" name="reservation_filter" value="<?php echo esc_attr( $filter ); ?>" />
			<span class="wpplugin-reservation-filter-links">
				<a href="<?php echo esc_url( $filter_url( '' ) ); ?>"<?php echo $filter ? '' : ' class="current" aria-current="page"'; ?>>すべて</a> (<?php echo esc_html( (string) count( $all_rows ) ); ?>) |
				<a href="<?php echo esc_url( $filter_url( 'tentative' ) ); ?>"<?php echo 'tentative' === $filter ? ' class="current" aria-current="page"' : ''; ?>>仮予約</a> (<?php echo esc_html( (string) $tentative_count ); ?>)
			</span>
		</form>
		<div class="wpplugin-facility-manager__table-wrap">
			<table class="widefat striped">
				<thead><tr><th class="check-column"><input type="checkbox" aria-label="すべて選択" onclick="document.querySelectorAll('.wpplugin-reservation-select').forEach(function(c){c.checked=this.checked;}.bind(this));" /></th><th>ステータス</th><th>利用日</th><th>時刻</th><th>施設</th><th>予約タイプ</th><th>予約タイトル</th><th>予約者</th><th>追加条件</th><th>詳細</th></tr></thead>
				<tbody>
					<?php foreach ( $rows as $row ) : $reservation = $row['reservation']; ?>
						<tr>
							<th class="check-column"><input type="checkbox" class="wpplugin-reservation-select" form="wpplugin-reservation-bulk-form" name="reservation_ids[]" value="<?php echo esc_attr( (string) $reservation['id'] ); ?>" aria-label="<?php echo esc_attr( $reservation['title'] ); ?>を選択" /></th>
							<td><?php echo esc_html( wpplugin_reservation_status_label( $reservation['status'] ) ); ?></td>
							<td><?php echo esc_html( $reservation['date'] ); ?></td>
							<td><?php echo esc_html( $row['time_label'] ); ?></td>
							<td><?php echo esc_html( $row['facility_name'] ); ?></td>
							<td><?php echo esc_html( $reservation['reservation_type'] ); ?></td>
							<td><?php echo esc_html( $reservation['title'] ); ?></td>
							<td><?php echo esc_html( $reservation['name'] ); ?></td>
							<td><?php echo esc_html( $row['additional_label'] ); ?></td>
							<td><?php echo esc_html( $reservation['details'] ); ?></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( empty( $rows ) ) : ?><tr><td colspan="10">予約はありません。</td></tr><?php endif; ?>
				</tbody>
			</table>
		</div>
	</div>
	<?php
}

function wpplugin_export_reservations_csv() {
	if ( ! wpplugin_user_can_manage_reservations() || ! check_admin_referer( 'wpplugin_export_reservations_csv', 'wpplugin_reservation_csv_nonce' ) ) {
		wp_die( '予約一覧を出力する権限がありません。' );
	}
	$month = wpplugin_sanitize_reservation_month( isset( $_POST['reservation_month'] ) ? $_POST['reservation_month'] : '' );
	if ( ! $month ) {
		wp_die( '対象月を正しく指定してください。' );
	}

	$rows = wpplugin_get_reservation_management_rows( $month );
	$filename = 'reservations-' . $month . '.csv';
	nocache_headers();
	header( 'Content-Type: text/csv; charset=UTF-8' );
	header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
	echo "\xEF\xBB\xBF";
	$output = fopen( 'php://output', 'w' );
	$header = array( '予約ID', 'ステータス', '管理組合が費用を負担', '利用日', '開始時刻', '終了時刻', '施設', '予約タイプ', '予約タイトル', '予約者', '予約者メール', '詳細', '利用時間(時間)', '泊数', '基本料金単価', '基本料金小計' );
	for ( $index = 1; $index <= 3; $index++ ) {
		array_push( $header, '追加条件' . $index, '追加料金単価' . $index, '追加数量' . $index, '追加料金小計' . $index );
	}
	$header[] = '合計金額';
	fputcsv( $output, $header, ',', '"', '\\' );
	foreach ( $rows as $row ) {
		$reservation = $row['reservation'];
		$facility = is_array( $row['facility'] ) ? $row['facility'] : array();
		$is_stay = '宿泊' === $reservation['reservation_type'];
		$hours = $is_stay ? 0 : wpplugin_get_reservation_hours( $reservation );
		$unit_price = wpplugin_parse_fee( $is_stay ? ( $facility['stay_fee'] ?? '' ) : ( $facility['fee'] ?? '' ) );
		$base_total = $unit_price * ( $is_stay ? $row['nights'] : $hours );
		$author = get_userdata( (int) get_post_field( 'post_author', $reservation['id'] ) );
		$end_label = $is_stay ? ( $facility['stay_end'] ?? '' ) . '（翌日以降）' : $reservation['end'];
		$total = $base_total;
		$additional_columns = array();
		for ( $index = 1; $index <= 3; $index++ ) {
			$quantity = isset( $reservation['additional_quantities'][ $index ] ) ? absint( $reservation['additional_quantities'][ $index ] ) : 0;
			$additional_price = wpplugin_parse_fee( $facility[ 'additional_fee_' . $index ] ?? '' );
			$subtotal = $additional_price * $quantity;
			$total += $subtotal;
			array_push( $additional_columns, $facility[ 'additional_condition_' . $index ] ?? '', $additional_price, $quantity, $subtotal );
		}
		$csv_row = array_merge(
			array( $reservation['id'], wpplugin_reservation_status_label( $reservation['status'] ), $reservation['cost_by_association'] ? '1' : '0', $reservation['date'], $reservation['start'], $end_label, $row['facility_name'], $reservation['reservation_type'], $reservation['title'], $reservation['name'], $author ? $author->user_email : '', $reservation['details'], $hours, $is_stay ? $row['nights'] : 0, $unit_price, $base_total ),
			$additional_columns,
			array( $total )
		);
		fputcsv( $output, array_map( 'wpplugin_csv_safe_value', $csv_row ), ',', '"', '\\' );
	}
	fclose( $output );
	exit;
}
add_action( 'admin_post_wpplugin_export_reservations_csv', 'wpplugin_export_reservations_csv' );

function wpplugin_admin_cancel_reservation() {
	if ( ! wpplugin_user_can_manage_reservations() || ! check_admin_referer( 'wpplugin_admin_cancel_reservation', 'wpplugin_admin_cancel_nonce' ) ) {
		wp_die( '予約をキャンセルする権限がありません。' );
	}
	$reservation_id = isset( $_POST['reservation_id'] ) ? absint( $_POST['reservation_id'] ) : 0;
	if ( ! wpplugin_cancel_reservation_group( $reservation_id ) ) {
		wp_die( '予約をキャンセルできませんでした。本人または管理権限を確認してください。' );
	}
	$month = wpplugin_sanitize_reservation_month( isset( $_POST['reservation_month'] ) ? $_POST['reservation_month'] : '' );
	$redirect_args = array( 'page' => WPPLUGIN_RESERVATION_LIST_PAGE_SLUG, 'wpplugin_reservation_admin_status' => 'cancelled', 'processed' => 1 );
	if ( $month ) {
		$redirect_args['reservation_month'] = $month;
	}
	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_wpplugin_admin_cancel_reservation', 'wpplugin_admin_cancel_reservation' );

function wpplugin_bulk_reservations() {
	if ( ! wpplugin_user_can_manage_reservations() || ! check_admin_referer( 'wpplugin_bulk_reservations', 'wpplugin_bulk_nonce' ) ) {
		wp_die( '予約を承認または却下する権限がありません。' );
	}
	$bulk_action = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : '';
	if ( ! in_array( $bulk_action, array( 'approve', 'reject', 'cancel' ), true ) ) {
		wp_die( '操作を確認してください。' );
	}

	$reservation_ids = isset( $_POST['reservation_ids'] ) ? array_unique( array_map( 'absint', (array) wp_unslash( $_POST['reservation_ids'] ) ) ) : array();
	$processed_groups = array();
	$processed = 0;
	foreach ( $reservation_ids as $reservation_id ) {
		if ( ! $reservation_id || WPPLUGIN_RESERVATION_POST_TYPE !== get_post_type( $reservation_id ) ) {
			continue;
		}
		if ( 'cancel' !== $bulk_action && WPPLUGIN_STATUS_TENTATIVE !== wpplugin_get_reservation_status( $reservation_id ) ) {
			continue;
		}
		$group_key = (string) get_post_meta( $reservation_id, '_wpplugin_reservation_group', true ) ?: (string) $reservation_id;
		if ( isset( $processed_groups[ $group_key ] ) ) {
			continue;
		}
		$processed_groups[ $group_key ] = true;

		if ( 'cancel' === $bulk_action ) {
			if ( wpplugin_cancel_reservation_group( $reservation_id ) ) {
				$processed++;
			}
			continue;
		}
		$notification = wpplugin_get_reservation_notification_data( $reservation_id );
		if ( 'approve' === $bulk_action ) {
			wpplugin_set_reservation_group_status( $reservation_id, WPPLUGIN_STATUS_CONFIRMED );
			wpplugin_add_log( 'approved', $notification );
			wpplugin_notify_applicant( $notification, 'confirmed' );
		} else {
			wpplugin_add_log( 'rejected', $notification );
			wpplugin_notify_applicant( $notification, 'rejected' );
		}
		$processed++;
	}

	$month = wpplugin_sanitize_reservation_month( isset( $_POST['reservation_month'] ) ? $_POST['reservation_month'] : '' );
	$result_statuses = array( 'approve' => 'approved', 'reject' => 'rejected', 'cancel' => 'cancelled' );
	$redirect_args = array(
		'page' => WPPLUGIN_RESERVATION_LIST_PAGE_SLUG,
		'wpplugin_reservation_admin_status' => $processed ? $result_statuses[ $bulk_action ] : 'none',
		'processed' => $processed,
	);
	if ( $month ) {
		$redirect_args['reservation_month'] = $month;
	}
	if ( isset( $_POST['reservation_filter'] ) && 'tentative' === sanitize_key( wp_unslash( $_POST['reservation_filter'] ) ) ) {
		$redirect_args['reservation_filter'] = 'tentative';
	}
	wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
	exit;
}
add_action( 'admin_post_wpplugin_bulk_reservations', 'wpplugin_bulk_reservations' );

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
		'1.0.37'
	);
	wp_register_script(
		'wpplugin-calendar',
		plugins_url( 'calendar.js', __FILE__ ),
		array(),
		'1.0.38',
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
	$hourly_fee = (string) ( $selected_facility['fee'] ?? '' );
	if ( '' !== $hourly_fee && '料金未設定' !== $hourly_fee && false === strpos( $hourly_fee, '円' ) ) {
		$hourly_fee .= ' 円';
	}
	$stay_fee = (string) ( $selected_facility['stay_fee'] ?? '' );
	if ( '' !== $stay_fee && '料金未設定' !== $stay_fee && false === strpos( $stay_fee, '円' ) ) {
		$stay_fee .= ' 円';
	}
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
	$output .= '<div class="wpplugin-facility-info__fee-row"' . ( '宿泊' === $facility_type ? ' hidden' : '' ) . '><dt class="wpplugin-facility-info__fee-label">' . ( '両方' === $facility_type ? '利用料金（時間貸）' : '利用料金' ) . '</dt><dd class="wpplugin-facility-info__fee">' . esc_html( $hourly_fee ) . '</dd></div>';
	$output .= '<div class="wpplugin-facility-info__stay-fee-row"' . ( in_array( $facility_type, array( '両方', '宿泊' ), true ) ? '' : ' hidden' ) . '><dt>利用料金（宿泊）</dt><dd class="wpplugin-facility-info__stay-fee">' . esc_html( $stay_fee ) . '／泊</dd></div>';
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
	$output .= '<p class="wpplugin-reservation__stay-fee" hidden>宿泊 <span>（<strong class="wpplugin-reservation__stay-fee-value"></strong> 円／泊）</span></p>';
	$output .= '<div class="wpplugin-reservation__additional-conditions"></div>';
	$output .= '<label>詳細<textarea name="reservation_details" rows="5"></textarea></label>';
	$output .= '<label class="wpplugin-reservation__cost"><input type="checkbox" name="cost_by_association" value="1"> 管理組合が費用を負担</label>';
	$output .= '<button type="submit">予約を保存</button>';
	$output .= '<button type="submit" class="wpplugin-reservation__cancel" formaction="' . esc_url( admin_url( 'admin-post.php' ) ) . '" name="action" value="wpplugin_cancel_reservation" formmethod="post" onclick="return confirm(\'この予約をキャンセルしますか？\');" hidden>予約をキャンセル</button>';
	$output .= wp_nonce_field( 'wpplugin_cancel_reservation', 'wpplugin_cancel_nonce', true, false );
	$output .= '</form></section>';

	return $output;
}
add_shortcode( 'wpplugin_calendar', 'wpplugin_calendar_shortcode' );