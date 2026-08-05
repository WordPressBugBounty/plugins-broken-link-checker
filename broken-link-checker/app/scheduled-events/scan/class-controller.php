<?php
/**
 * Scheduled event for BLC Scan.
 * A single scheduled event that gets triggered based on options set in "Schedule Scan"
 *
 * @link    https://wordpress.org/plugins/broken-link-checker/
 * @since   2.0.0
 *
 * @author  WPMUDEV (https://wpmudev.com)
 * @package WPMUDEV_BLC\App\Schedule_Events\Scan
 *
 * @copyright (c) 2022, Incsub (http://incsub.com)
 */

namespace WPMUDEV_BLC\App\Scheduled_Events\Scan;

// Abort if called directly.
defined( 'WPINC' ) || die;

use Exception;
use WPMUDEV_BLC\App\Http_Requests\Scan\Controller as Scan_API;
use WPMUDEV_BLC\Core\Utils\Abstracts\Base;
use WPMUDEV_BLC\App\Options\Settings\Model as Settings;
use WPMUDEV_BLC\Core\Traits\Cron;
use WPMUDEV_BLC\Core\Utils\Utilities;

/**
 * Class Controller
 *
 * @package WPMUDEV_BLC\App\Schedule_Events\Scan
 */
class Controller extends Base {
	use Cron;

	/**
	 * WP Cron hook to execute when event is run.
	 *
	 * @var string
	 */
	public $cron_hook_name = 'blc_schedule_scan';

	/**
	 * BLC settings from options table.
	 *
	 * @var array
	 */
	private $settings = null;

	/**
	 * Init Schedule
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public function init() {
		add_action( $this->get_hook_name(), array( $this, 'process_scheduled_event' ) );
		
		//if ( wp_doing_ajax() || ! $this->get_schedule( 'active' ) ) {
		if ( wp_doing_ajax() ) {
			return;
		}

		Settings::instance()->init();

		//add_action( 'init', array( $this, 'load' ) );
		//add_action( 'wpmudev_blc_rest_enpoints_after_save_schedule_settings', array( $this, 'deactivate_cron' ), 10 );
		add_action( 'wpmudev_blc_rest_enpoints_after_save_schedule_settings', array( $this, 'set_scan_schedule' ) );

		add_action( 'wpmudev_blc_plugin_deactivated', array( $this, 'deactivate_cron' ) );
	}

	/**
	 * Starts the scheduled scan.
	 */
	public function process_scheduled_event() {
		if ( ! $this->get_schedule( 'active' ) || ! apply_filters( 'wpmudev_blc_can_run_scan_schedule', $this->can_run_schedule() ) ) {
			// Reset Schedule to make sure that it runs in time.
			$this->set_scan_schedule();
			return false;
		}

		// At his point we're setting the scan status flag to `in_progress`. So if it doesn't get `completed` there
		// will ba a sync request fired on page load.
		Settings::instance()->set( array( 'scan_status' => 'in_progress' ) );
		Settings::instance()->save();

		$scan = Scan_API::instance();

		// Setting scan schedule so that it doesn't run while scan still is running.
		$this->set_scan_schedule();
		$scan->start();
		$this->set_scan_schedule_flag();
		//$this->deactivate_cron();
		//$this->set_scan_schedule();
	}

	/**
	 * Returns the schedule from settings, or if a key is set, it returns that key's value
	 *
	 * @param string $key .
	 *
	 * @return array|mixed|null
	 */
	private function get_schedule( string $key = '' ) {
		if ( is_null( $this->settings ) ) {
			$this->settings = Settings::instance()->get( 'schedule' );
		}

		if ( ! empty( $key ) && is_array( $this->settings ) ) {
			return $this->settings[ $key ] ?? null;
		}

		return $this->settings;
	}

	/**
	 * Makes sure that the cron does not get triggered long before it's time.
	 *
	 * @throws Exception
	 * @return boolean
	 */
	protected function can_run_schedule() {
		// Better not run when membership is expired.
		if ( boolval( Utilities::membership_expired() ) ) {
			return false;
		}

		// For some reason this is called multiple times
		$cur_date           = new \DateTimeImmutable();
		$scan_results       = Settings::instance()->get( 'scan_results' );
		$last_timestamp     = $scan_results['end_time'] ?? null;
		$last_scan_date     = new \DateTimeImmutable( date( 'Y-m-d H:i:s', $last_timestamp ) );
		$interval_from_last = $cur_date->diff( $last_scan_date );

		if ( 'in_progress' === Settings::instance()->get( 'scan_status' ) || ( ! empty( $last_timestamp ) && abs( $interval_from_last->format( '%h' ) ) < 1 ) ) {
			// Since we return `false` at this point, `$this->set_scan_schedule()` will be called from `process_scheduled_event()`. No need to run it here too.
			return false;
		}
		/**
		 * Removed scan condition that checks upcoming scan schedule which blocked scan if that time frame is more than 5 hours to reach.
		 * @since 2.4.3
		 */
		return true;
	}

	/**
	 * Returns the scheduled event's hook name.
	 * Overriding Trait's method.
	 */
	public function get_hook_name() {
		return $this->cron_hook_name;
	}

	/**
	 * Sets the scan flag to true. Useful when API sends the SET request, an email about the current schedule should
	 * be sent to schedule receivers.
	 */
	public function set_scan_schedule_flag( bool $flag = true ) {
		Settings::instance()->set( array( 'blc_schedule_scan_in_progress' => $flag ) );
		Settings::instance()->set( array( 'scan_status' => 'in_progress' ) );
		Settings::instance()->save();
	}

	/**
	 * Sets new scan schedule.
	 *
	 * @param array $settings The settings param from `wpmudev_blc_rest_enpoints_after_save_schedule_settings` action.
	 *
	 * @return bool
	 */
	public function set_scan_schedule( array $settings = array() ) {
		if ( ! $this->get_schedule( 'active' ) ) {
			return false;
		}

		$settings = empty( $settings ) ?? Settings::instance();

		// Deactivate cron if is already created, so we will replace it later on.
		$this->deactivate_cron();

		// As a single event it will be possible to set custom timestamps to run.
		$this->is_single_event = true;
		// Set the timestamp based on Schedule options.
		$this->timestamp = intval( $this->get_timestamp( $settings['schedule'] ?? array() ) );

		//$this->setup_cron();
		return $this->activate_cron();
	}

	/**
	 * Returns the timestamp of next scheduled scan.
	 *
	 * @param array $schedule     The schedule data. Falls back to stored settings if frequency is absent.
	 * @param int   $current_time Optional. Local timestamp to use as the current time. Defaults to current time in site's timezone.
	 *
	 * @return int|null
	 */
	public function get_timestamp( array $schedule = array(), int $current_time = 0 ): ?int {
		if ( ! $current_time ) {
			// If current time is not provided, we will use the current timestamp in the site's timezone.
			$zone       = wp_timezone();
			$local_time = date_create( 'now' );

			$local_time->setTimezone( $zone );
			$current_time = $local_time->getTimestamp();
		}

		if ( empty( $schedule['frequency'] ) ) {
			// If frequency is not set, we will try to get the schedule from settings.
			$schedule = $this->get_schedule();
		}

		if ( empty( $schedule['frequency'] ) || empty( $schedule['time'] ) ) {
			return null;
		}

		$frequency     = $schedule['frequency'];
		$schedule_time = $schedule['time'];

		if ( 'daily' === $frequency ) {
			// Return the timestamp for the next daily scan.
			return self::build_daily_schedule_timestamp( $schedule_time, $current_time );
		}

		if ( 'monthly' === $frequency ) {
			// For monthly frequency, we need to get the current day of the month and the scheduled month days.
			$current_day_num = gmdate( 'd', $current_time );
			$schedule_days   = $schedule['monthdays'] ?? array();
		} else {
			// For weekly frequency, we need to get the current day of the week and the scheduled weekdays.
			$current_day_num = gmdate( 'w', $current_time );
			$schedule_days   = $schedule['days'] ?? array();
		}

		if ( empty( $schedule_days ) ) {
			return null;
		}

		sort( $schedule_days );

		if ( 'weekly' === $frequency ) {
			// Return the timestamp for the next weekly scan.
			return self::build_weekly_schedule_timestamp(
				$schedule_time,
				$schedule_days,
				$current_day_num,
				$current_time
			);
		}

		if ( 'monthly' === $frequency ) {
			// Return the timestamp for the next monthly scan.
			return self::build_monthly_schedule_timestamp(
				$schedule_time,
				$schedule_days,
				$current_day_num,
				$current_time
			);
		}

		return null;
	}

	/**
	 * Returns the timestamp for the next daily scan.
	 *
	 * @param string $time The time of the day in HH:MM format.
	 * @param int    $current_time The current timestamp.
	 *
	 * @return int
	 */
	private static function build_daily_schedule_timestamp( string $time, int $current_time ): int {
		// Create a DateTime object for the scheduled time today in the site's timezone.
		// Date example: "3:00 pm" or "4:00 am".
		$schedule_time = date_create_from_format(
			'g:i a',
			$time,
			wp_timezone()
		);

		if ( $current_time > $schedule_time->getTimestamp() ) {
			// Current time is later than the scheduled time, so schedule for tomorrow.
			$timestamp = Utilities::str_to_time( "tomorrow {$time}" );
		} else {
			// Current time is earlier than or equal to the scheduled time, so schedule for today.
			$timestamp = Utilities::str_to_time( "today {$time}" );
		}

		return $timestamp;
	}

	/**
	 * Finds which day number to schedule next, and whether the period must roll over.
	 * Shared by weekly and monthly calculations.
	 *
	 * @param array  $days            Sorted list of scheduled day numbers.
	 * @param mixed  $current_day_num The current day number (0-6 for weekly, 1-31 for monthly).
	 * @param string $time            Scheduled time string in "HH:MM" format.
	 * @param int    $current_time    Current Unix timestamp.
	 *
	 * @return array{schedule_today: bool, next_day_num: int|null, move_to_next_period: bool}
	 */
	private static function find_next_scheduled_day( array $days, $current_day_num, string $time, int $current_time ): array {
		$schedule_today = false;
		$next_day_num   = null;

		if ( in_array( $current_day_num, $days, true ) ) {

			// If today is a scheduled day, check if the scheduled time has already passed.
			$day_key = array_keys( $days, $current_day_num, true )[0];

			if ( date_i18n( 'H:i', $current_time ) < $time ) {
				$schedule_today = true;
			} elseif ( array_key_exists( $day_key + 1, $days ) ) {

				// If there is a next scheduled day in the list, set it as the next day.
				$next_day_num = $days[ $day_key + 1 ];
			}
		}

		if ( ! $schedule_today && is_null( $next_day_num ) ) {

			// If today is not a scheduled day, find the next scheduled day in the list.
			foreach ( $days as $day_num ) {
				if ( intval( $day_num ) > intval( $current_day_num ) ) {

					// If the scheduled day is later in the week/month, set it as the next day.
					$next_day_num = intval( $day_num );
					break;
				}
			}
		}

		$move_to_next_period = false;
		if ( ! $schedule_today && is_null( $next_day_num ) ) {
			// If there is no next scheduled day in the list,
			// roll over to the first scheduled day in the next period.
			$next_day_num        = intval( $days[0] );
			$move_to_next_period = true;
		}

		return array(
			'schedule_today'      => $schedule_today,
			'next_day_num'        => $next_day_num,
			'move_to_next_period' => $move_to_next_period,
		);
	}

	/**
	 * Builds the timestamp for the next weekly scheduled scan.
	 *
	 * @param string $time            Scheduled time string in "HH:MM" format.
	 * @param array  $days            Sorted list of scheduled weekday numbers (0 = Sunday, 6 = Saturday).
	 * @param mixed  $current_day_num Current weekday number from date('w').
	 * @param int    $current_time    Current Unix timestamp.
	 *
	 * @return int
	 */
	private static function build_weekly_schedule_timestamp( string $time, array $days, $current_day_num, int $current_time ): int {
		// Find the next scheduled day and whether to schedule for today or the next week.
		$result = self::find_next_scheduled_day( $days, $current_day_num, $time, $current_time );

		if ( $result['schedule_today'] ) {
			return Utilities::str_to_time( "today {$time}" );
		}

		$next_day_num = $result['next_day_num'];
		// Get name of the next scheduled day (e.g., "Monday", "Tuesday") based on its number.
		$day_name     = gmdate( 'l', Utilities::str_to_time( "Sunday +{$next_day_num} days" ) );
		return Utilities::str_to_time( "next {$day_name} {$time}" );
	}

	/**
	 * Builds the timestamp for the next monthly scheduled scan.
	 *
	 * @param string $time            Scheduled time string in "HH:MM" format.
	 * @param array  $days            Sorted list of scheduled month-day numbers (1–31).
	 * @param mixed  $current_day_num Current month day from date('d').
	 * @param int    $current_time    Current Unix timestamp.
	 *
	 * @return int
	 */
	private static function build_monthly_schedule_timestamp( string $time, array $days, $current_day_num, int $current_time ): int {
		$result = self::find_next_scheduled_day( $days, $current_day_num, $time, $current_time );

		if ( $result['schedule_today'] ) {
			return Utilities::str_to_time( "today {$time}" );
		}

		$next_day_num = $result['next_day_num'];

		if ( $result['move_to_next_period'] ) {
			// Subtracting one because we're offsetting from "first day of next month".
			--$next_day_num;
			return Utilities::str_to_time( "first day of next month +{$next_day_num} days {$time}" );
		}

		$days_diff = intval( $next_day_num ) - intval( $current_day_num );
		return Utilities::str_to_time( "+{$days_diff} days {$time}" );
	}
}
