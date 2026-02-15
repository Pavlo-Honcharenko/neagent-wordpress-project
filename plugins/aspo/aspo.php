<?php
/**
 * Plugin Name: ASPO
 * Description: Import real estate listings from ASPO XML feed.
 * Version: 1.0.0
 * Author: Pavlo
 * Text Domain: aspo
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// Додаємо перевірку, щоб уникнути помилок при повторному підключенні
if ( ! defined( 'ASPO_PATH' ) ) {
    define( 'ASPO_PATH', plugin_dir_path( __FILE__ ) );
}

if ( ! defined( 'ASPO_URL' ) ) {
    define( 'ASPO_URL', plugin_dir_url( __FILE__ ) );
}

require_once ASPO_PATH . 'includes/class-aspo-feed.php';
require_once ASPO_PATH . 'includes/class-aspo-importer.php';
require_once ASPO_PATH . 'admin/class-aspo-admin.php';

new ASPO_Admin();

// Додаємо кастомний інтервал 30 хвилин
add_filter( 'cron_schedules', 'aspo_add_cron_intervals' );
function aspo_add_cron_intervals( $schedules ) {
    if ( ! isset( $schedules['every_thirty_minutes'] ) ) {
        $schedules['every_thirty_minutes'] = [
            'interval' => 30 * MINUTE_IN_SECONDS,
            'display'  => __( 'Every 30 Minutes', 'aspo' ),
        ];
    }
    return $schedules;
}

// Реєстрація крону при активації
register_activation_hook( __FILE__, 'aspo_setup_cron_on_activation' );
function aspo_setup_cron_on_activation() {
    if ( ! wp_next_scheduled( 'aspo_cron_import_event' ) ) {
        wp_schedule_event( time(), 'every_thirty_minutes', 'aspo_cron_import_event' );
    }
}

// Очищення при деактивації
register_deactivation_hook( __FILE__, 'aspo_clear_cron_on_deactivate' );
function aspo_clear_cron_on_deactivate() {
    $timestamp = wp_next_scheduled( 'aspo_cron_import_event' );
    if ( $timestamp ) {
        wp_unschedule_event( $timestamp, 'aspo_cron_import_event' );
    }
}