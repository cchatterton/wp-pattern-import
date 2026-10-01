<?php
/**
 * Cron handling for WP Pattern Import.
 *
 * @package WPPatternImport
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( WPI_CRON_HOOK, 'wpi_cron_run' );

/**
 * Update daily schedule based on the recipe schedule value.
 *
 * @param string $schedule manual|daily.
 * @param string $schedule_time HH:MM site-local time.
 * @return void
 */
function wpi_update_schedule( $schedule, $schedule_time = '02:00' ) {
	if ( 'daily' !== $schedule ) {
		wpi_clear_schedule();
		return;
	}

	wpi_clear_schedule();
	if ( ! wp_next_scheduled( WPI_CRON_HOOK ) ) {
		wp_schedule_event( wpi_next_daily_timestamp( $schedule_time ), 'daily', WPI_CRON_HOOK );
	}
}

/**
 * Get the next timestamp for a daily site-local HH:MM schedule.
 *
 * @param string $schedule_time HH:MM.
 * @return int
 */
function wpi_next_daily_timestamp( $schedule_time ) {
	if ( ! preg_match( '/^([01]\d|2[0-3]):([0-5]\d)$/', $schedule_time, $matches ) ) {
		$schedule_time = '02:00';
	}

	list( $hour, $minute ) = array_map( 'absint', explode( ':', $schedule_time ) );
	$timezone             = wp_timezone();
	$now                  = new DateTimeImmutable( 'now', $timezone );
	$next                 = $now->setTime( $hour, $minute, 0 );

	if ( $next <= $now ) {
		$next = $next->modify( '+1 day' );
	}

	return $next->getTimestamp();
}

/**
 * Clear the scheduled daily import.
 *
 * @return void
 */
function wpi_clear_schedule() {
	$timestamp = wp_next_scheduled( WPI_CRON_HOOK );
	while ( $timestamp ) {
		wp_unschedule_event( $timestamp, WPI_CRON_HOOK );
		$timestamp = wp_next_scheduled( WPI_CRON_HOOK );
	}
}

/**
 * Cron callback.
 *
 * @return void
 */
function wpi_cron_run() {
	wpi_run_import( 'scheduled' );
}
