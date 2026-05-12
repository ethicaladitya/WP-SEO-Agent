<?php
/**
 * Queue Manager.
 *
 * Persistent batch processing queue for large-site analysis.
 * Processes posts in batches of 10, enforces API rate limits
 * (≤1 req/sec GSC, ≤0.5 req/sec GA4), and retries on 429/503
 * with exponential backoff (up to 3 attempts per post).
 *
 * Queue state is stored in a single WP option as a JSON array
 * so it survives page loads and cron restarts.
 *
 * @package SEO_Agent_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SEO_Agent_AI_Queue_Manager {

	const OPTION_KEY       = 'seo_agent_ai_queue';
	const BATCH_SIZE       = 10;
	const MAX_RETRIES      = 3;
	const GSC_SLEEP_US     = 1000000; // 1 second between GSC calls.
	const GA4_SLEEP_US     = 500000;  // 0.5 seconds between GA4 calls.
	const BACKOFF_BASE_SEC = 30;      // Base back-off on 429/503.

	/** @var SEO_Agent_AI_Logger */
	private $logger;

	public function __construct( SEO_Agent_AI_Logger $logger ) {
		$this->logger = $logger;
	}

	// -------------------------------------------------------------------
	// Queue management
	// -------------------------------------------------------------------

	/**
	 * Add a list of post IDs to the processing queue (deduped).
	 *
	 * @param int[] $post_ids
	 */
	public function enqueue( array $post_ids ) {
		$queue = $this->load_queue();

		$existing_ids = array_column( $queue['items'], 'post_id' );
		$added        = 0;

		foreach ( $post_ids as $id ) {
			$id = (int) $id;
			if ( $id <= 0 || in_array( $id, $existing_ids, true ) ) {
				continue;
			}
			$queue['items'][] = array(
				'post_id'    => $id,
				'retries'    => 0,
				'queued_at'  => time(),
				'last_error' => '',
			);
			$existing_ids[] = $id;
			$added++;
		}

		$queue['total_queued'] = isset( $queue['total_queued'] ) ? $queue['total_queued'] + $added : $added;
		$this->save_queue( $queue );

		$this->logger->info( "Queue: {$added} posts added. Total pending: " . count( $queue['items'] ) );
	}

	/**
	 * Enqueue all published posts that have not been analyzed recently.
	 *
	 * @param int $stale_days  Posts not analyzed in this many days are considered stale.
	 */
	public function enqueue_all_stale( $stale_days = 7 ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', strtotime( '-' . (int) $stale_days . ' days' ) );

		$post_ids = get_posts( array(
			'post_type'      => 'post',
			'post_status'    => 'publish',
			'posts_per_page' => 2000,
			'fields'         => 'ids',
			'meta_query'     => array(
				'relation' => 'OR',
				array(
					'key'     => '_seo_agent_ai_last_analyzed',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'     => '_seo_agent_ai_last_analyzed',
					'value'   => $cutoff,
					'compare' => '<',
					'type'    => 'DATETIME',
				),
			),
		) );

		if ( ! empty( $post_ids ) ) {
			$this->enqueue( $post_ids );
		}
	}

	/**
	 * Clear the entire queue.
	 */
	public function clear() {
		$this->save_queue( $this->empty_queue() );
	}

	/**
	 * Get current queue status.
	 *
	 * @return array  [pending, total_queued, last_run, last_batch_processed].
	 */
	public function status() {
		$queue = $this->load_queue();
		return array(
			'pending'               => count( $queue['items'] ),
			'total_queued'          => $queue['total_queued'] ?? 0,
			'total_processed'       => $queue['total_processed'] ?? 0,
			'total_errors'          => $queue['total_errors'] ?? 0,
			'last_run'              => $queue['last_run'] ?? '',
			'last_batch_processed'  => $queue['last_batch_processed'] ?? 0,
		);
	}

	// -------------------------------------------------------------------
	// Batch processing
	// -------------------------------------------------------------------

	/**
	 * Process the next batch of posts from the queue.
	 *
	 * Each post in the batch runs the GSC + GA4 fetch sequentially.
	 * Calls the provided $processor callable: fn(int $post_id) : void|WP_Error.
	 * On WP_Error, the post is retried up to MAX_RETRIES before being dropped.
	 *
	 * @param callable $processor  fn(int $post_id) — called for each post.
	 * @return array  [processed, skipped, errors, remaining].
	 */
	public function process_batch( callable $processor ) {
		$queue = $this->load_queue();

		if ( empty( $queue['items'] ) ) {
			return array( 'processed' => 0, 'skipped' => 0, 'errors' => 0, 'remaining' => 0 );
		}

		$batch     = array_splice( $queue['items'], 0, self::BATCH_SIZE );
		$processed = 0;
		$skipped   = 0;
		$errors    = 0;
		$requeue   = array();

		foreach ( $batch as $item ) {
			$post_id = (int) $item['post_id'];
			$retries = (int) ( $item['retries'] ?? 0 );

			// Skip posts that have been deleted.
			$post = get_post( $post_id );
			if ( ! $post instanceof WP_Post || $post->post_status !== 'publish' ) {
				$skipped++;
				continue;
			}

			try {
				$result = $processor( $post_id );

				if ( is_wp_error( $result ) ) {
					$code = $result->get_error_code();
					$msg  = $result->get_error_message();

					// Rate-limited: back off and requeue.
					if ( $this->is_rate_limit_error( $code, $msg ) ) {
						$this->logger->warning( "Rate limit hit on post {$post_id} — backing off " . self::BACKOFF_BASE_SEC . "s." );
						$this->backoff();
						$item['retries']    = $retries + 1;
						$item['last_error'] = $msg;
						if ( $item['retries'] < self::MAX_RETRIES ) {
							$requeue[] = $item; // Put back for retry.
						} else {
							$this->logger->error( "Post {$post_id} exceeded max retries — dropped from queue." );
							$errors++;
							$queue['total_errors'] = ( $queue['total_errors'] ?? 0 ) + 1;
						}
					} else {
						$this->logger->error( "Processor error on post {$post_id}: {$msg}" );
						$errors++;
						$queue['total_errors'] = ( $queue['total_errors'] ?? 0 ) + 1;
					}
				} else {
					update_post_meta( $post_id, '_seo_agent_ai_last_analyzed', current_time( 'mysql' ) );
					$processed++;
					$queue['total_processed'] = ( $queue['total_processed'] ?? 0 ) + 1;
					$this->logger->debug( "Queue processed post {$post_id}." );
				}
			} catch ( Exception $e ) {
				$this->logger->error( "Exception on post {$post_id}: " . $e->getMessage() );
				$errors++;
				$queue['total_errors'] = ( $queue['total_errors'] ?? 0 ) + 1;
			}

			// Enforce GSC rate limit between posts.
			usleep( self::GSC_SLEEP_US );
		}

		// Prepend requeue items back to the front of the queue.
		$queue['items'] = array_merge( $requeue, $queue['items'] );

		$queue['last_run']              = gmdate( 'Y-m-d H:i:s' );
		$queue['last_batch_processed']  = $processed;

		$this->save_queue( $queue );

		$remaining = count( $queue['items'] );
		$this->logger->info( "Queue batch: processed={$processed}, skipped={$skipped}, errors={$errors}, remaining={$remaining}" );

		return array(
			'processed' => $processed,
			'skipped'   => $skipped,
			'errors'    => $errors,
			'remaining' => $remaining,
		);
	}

	/**
	 * Sleep with exponential back-off for rate limit errors.
	 * Sleeps BASE_SEC * 2^(retry count) but caps at 300s.
	 *
	 * @param int $retry  Current retry count.
	 */
	public function backoff( $retry = 0 ) {
		$seconds = min( self::BACKOFF_BASE_SEC * ( 2 ** max( 0, (int) $retry ) ), 300 );
		sleep( (int) $seconds );
	}

	// -------------------------------------------------------------------
	// Private helpers
	// -------------------------------------------------------------------

	private function load_queue() {
		$raw = get_option( self::OPTION_KEY, '' );
		if ( $raw === '' ) {
			return $this->empty_queue();
		}
		$data = json_decode( $raw, true );
		return is_array( $data ) ? $data : $this->empty_queue();
	}

	private function save_queue( array $queue ) {
		update_option( self::OPTION_KEY, wp_json_encode( $queue ), false );
	}

	private function empty_queue() {
		return array(
			'items'               => array(),
			'total_queued'        => 0,
			'total_processed'     => 0,
			'total_errors'        => 0,
			'last_run'            => '',
			'last_batch_processed' => 0,
		);
	}

	private function is_rate_limit_error( $code, $message ) {
		if ( in_array( $code, array( 'seo_agent_ai_gsc_api_error', 'seo_agent_ai_ga4_api_error' ), true ) ) {
			return ( strpos( $message, '429' ) !== false || strpos( $message, '503' ) !== false
				|| stripos( $message, 'rate limit' ) !== false || stripos( $message, 'quota' ) !== false );
		}
		return false;
	}
}
