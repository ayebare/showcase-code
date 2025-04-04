<?php

namespace Piccolo\Gmail;

/**
 * Class Retry
 *
 * Helper Class to Calculate retry time using exponential backoff algorithms.
 * This class also keeps track of the number of retry's made and 
 * sets its retryable parameter to false when the set cap is exceeded.
 *
 * For all time-based errors (maximum of N requests per X minutes),
 * its recommend code catches the exception and, using an exponential backoff algorithm,
 * implement a small delay before trying again. If requests are still unsuccessful,
 * it's important the delays between requests increase over time until the request is successful.
 *
 * @see https://developers.google.com/admin-sdk/email-audit/limits
 * @see https://developers.google.com/drive/api/guides/limits#exponential
 *
 * If errors are caused by load, retries can be ineffective if all clients retry at the same time.
 * To avoid this problem, we employ jitter. This is a random amount of time before making or retrying a request
 * to help prevent large bursts by spreading out the arrival rate.
 */
class Retry {

	/**
	 * This will decide whether to retry or not.
	 *
	 * @var callable
	 */
	public $retryable = false;

	/**
	 * Base wait time in ms
	 *
	 * @var int
	 */
	protected $base = 100;

	/**
	 * @var int
	 */
	protected $max_attempts = 5;

	/**
	 * The max wait time you want to allow,
	 *
	 * @var int|null     In milliseconds
	 */
	protected $wait_cap = 10000;

	/**
	 * Keeps track of the number of attempts
	 *
	 * @var int
	 */
	protected $attempt = 0;

	/**
	 * Creates a new retry object with exponential backoff support.
	 *
	 * @param int $max_attempts to retry
	 */
	public function __construct( $max_attempts ) {
		$this->max_attempts = $max_attempts;
	}

	/**
	 * wait using  jitter randomness to calculate waiting time.
	 *
	 * @since 0.0.1
	 */
	public function backoff() {
		if ( 0 === $this->attempt ) {
			return;
		}

		usleep( $this->get_jitter_wait_time( $this->attempt ) * 1000 );
	}

	/**
	 * Gets the jitter wait time.
	 *
	 * @since 0.0.1
	 *
	 * @param int $attempt Number of registered attempts.
	 *
	 * @return int
	 */
	public function get_jitter_wait_time( $attempt ) {
		$wait_time = $this->get_wait_time( $attempt );

		return $this->jitter( $this->cap( $wait_time ) );
	}

	/**
	 * Get wait time
	 *
	 * @param int $attempt
	 *
	 * @return int
	 */
	public function get_wait_time( $attempt ) {
		if ( 1 === $attempt ) {
			return $this->base;
		}

		return pow( 2, $attempt ) * $this->base;
	}

	/**
	 *
	 * Calculates wait jitter (random number) from wait time
	 *
	 * @since 0.0.1
	 *
	 * @param $wait_time
	 *
	 * @return int
	 */
	protected function jitter( $wait_time ) {
		return wp_rand( 0, $wait_time );
	}

	/**
	 * Get the max allowable wait time.
	 *
	 * @param int $wait_time
	 *
	 * @return int
	 */
	protected function cap( $wait_time ) {
		return min( $this->wait_cap, $wait_time );
	}

	/**
	 * Updates the state of the class after a wait.
	 *
	 * @return void
	 */
	public function update_state() {
		$this->increment_attempts();
		$this->update_retryable();
	}

	/**
	 * Increment attempts
	 *
	 * @return void
	 */
	public function increment_attempts() {
		$this->attempt++;
	}

	/**
	 * Set retryable based on simply check exceptions and maxattempts
	 *
	 * @return void
	 */
	protected function update_retryable() {
		$this->retryable = $this->attempt < $this->max_attempts;
	}

	/**
	 * Gets the current number of attempts
	 *
	 * @return int
	 */
	public function get_attempt() {
		return $this->attempt;
	}

	/**
	 * Gets the maximum allowed attempts
	 *
	 * @return int
	 */
	public function get_max_attempts() {
		return $this->max_attempts;
	}
}
