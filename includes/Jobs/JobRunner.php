<?php
/**
 * Runs step-based background jobs for projects.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Jobs;

use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\Contracts\ProviderException;
use AiSeoContentStudio\Support\Lock;

defined( 'ABSPATH' ) || exit;

/**
 * Job lifecycle: queued → running → completed | failed | cancelled.
 *
 * Each step runs in its own request: Action Scheduler when available, otherwise
 * a WP-Cron single event. Because WP-Cron timing is not guaranteed, the status
 * endpoint calls tick(), which runs the next step inline when a job has made no
 * progress for STALE seconds. A per-project lock prevents two steps (and their
 * paid calls) from running at the same time.
 */
class JobRunner {

	public const HOOK         = 'aiscs_run_job';
	public const STALE        = 15;
	public const MAX_ATTEMPTS = 2;
	public const LOCK_TTL     = 300;
	/** Automatic waits after a provider rate limit before the job gives up. */
	public const MAX_RATE_WAITS = 6;
	/** Runs one step may take when it reports progress but is not yet complete (e.g. one part per run). */
	public const MAX_STEP_RUNS = 60;

	/**
	 * Handlers keyed by type.
	 *
	 * @var array<string, JobHandlerInterface>
	 */
	private array $handlers = array();

	/**
	 * Dispatcher, injectable for tests.
	 *
	 * @var callable(int, int): void
	 */
	private $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param ProjectRepository     $projects   Projects.
	 * @param JobHandlerInterface[] $handlers   Handlers.
	 * @param Logger                $logger     Logger.
	 * @param callable|null         $dispatcher Schedules the next step.
	 * @param int                   $lock_ttl   Job lock lifetime (seconds); must exceed the slowest step.
	 */
	public function __construct( private readonly ProjectRepository $projects, array $handlers, private readonly Logger $logger, ?callable $dispatcher = null, private readonly int $lock_ttl = self::LOCK_TTL ) {
		foreach ( $handlers as $handler ) {
			$this->handlers[ $handler->type() ] = $handler;
		}
		$this->dispatcher = $dispatcher ?? array( self::class, 'dispatchDefault' );
	}

	/**
	 * Start a job. Fails if another job for the project is active.
	 *
	 * @param int                  $project_id Project.
	 * @param string               $type       Handler type.
	 * @param int                  $user_id    User the job runs as.
	 * @param array<string, mixed> $options    Options.
	 * @return array<string, mixed> Job.
	 * @throws ProviderException When a job is active or the type is unknown.
	 */
	public function start( int $project_id, string $type, int $user_id, array $options = array() ): array {
		if ( ! isset( $this->handlers[ $type ] ) ) {
			throw new ProviderException( 'Unknown job type.', ProviderException::CONFIG );
		}
		// Two quick starts must not both pass the "no active job" check.
		$start_lock = 'job-start:' . $project_id;
		if ( ! Lock::acquire( $start_lock, 60 ) ) {
			throw new ProviderException( __( 'Another operation is already running for this project. Wait for it to finish or cancel it.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
		}
		try {
			$job = $this->create( $project_id, $type, $user_id, $options );
		} finally {
			Lock::release( $start_lock );
		}
		( $this->dispatcher )( $project_id, 0 );
		return $job;
	}

	/**
	 * Create and store a queued job (caller holds the start lock).
	 *
	 * @param int                  $project_id Project.
	 * @param string               $type       Handler type.
	 * @param int                  $user_id    User the job runs as.
	 * @param array<string, mixed> $options    Options.
	 * @return array<string, mixed> Job.
	 * @throws ProviderException When a job is active.
	 */
	private function create( int $project_id, string $type, int $user_id, array $options ): array {
		$current = $this->projects->section( $project_id, 'job' );
		if ( $current && in_array( $current['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			throw new ProviderException( __( 'Another operation is already running for this project. Wait for it to finish or cancel it.', 'ai-seo-content-studio' ), ProviderException::LIMIT );
		}

		$steps = $this->handlers[ $type ]->steps( $project_id, $options );
		$job   = array(
			'id'         => wp_generate_uuid4(),
			'type'       => $type,
			'status'     => 'queued',
			'steps'      => $steps,
			'step_index' => 0,
			'attempts'   => 0,
			'progress'   => 0,
			'message'    => __( 'Queued.', 'ai-seo-content-studio' ),
			'error'      => null,
			'user_id'    => $user_id,
			'options'    => $options,
			'state'      => array(),
			'seq'        => 0,
			'created_at' => time(),
			'updated_at' => time(),
		);
		$this->projects->save( $project_id, 'job', $job );
		return $job;
	}

	/**
	 * Cron/Action Scheduler callback: run the next step.
	 *
	 * @param int $project_id Project.
	 */
	public function run( int $project_id ): void {
		$lock = 'job:' . $project_id;
		if ( ! Lock::acquire( $lock, max( self::LOCK_TTL, $this->lock_ttl ) ) ) {
			return;
		}
		$previous_user = get_current_user_id();
		try {
			$this->runLocked( $project_id );
		} finally {
			wp_set_current_user( $previous_user );
			Lock::release( $lock );
		}
	}

	/**
	 * Run the next step if the job looks stalled (called from status polling).
	 *
	 * @param int $project_id Project.
	 */
	public function tick( int $project_id ): void {
		$job = $this->projects->section( $project_id, 'job' );
		if ( $job && in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) && time() - (int) ( $job['updated_at'] ?? 0 ) >= self::STALE ) {
			$this->run( $project_id );
		}
	}

	/**
	 * Cancel an active job. A step already in progress finishes, then stops.
	 *
	 * @param int $project_id Project.
	 */
	public function cancel( int $project_id ): void {
		$job = $this->projects->section( $project_id, 'job' );
		if ( $job && in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			$job['status']     = 'cancelled';
			$job['message']    = __( 'Cancelled.', 'ai-seo-content-studio' );
			$job['updated_at'] = time();
			$this->projects->save( $project_id, 'job', $job );
		}
	}

	/**
	 * Public view of a job (no internal state).
	 *
	 * @param array<string, mixed>|null $job Job.
	 * @return array<string, mixed>|null
	 */
	public function publicView( ?array $job ): ?array {
		if ( null === $job ) {
			return null;
		}
		$handler = $this->handlers[ $job['type'] ?? '' ] ?? null;
		$index   = (int) ( $job['step_index'] ?? 0 );
		$steps   = (array) ( $job['steps'] ?? array() );
		return array(
			'id'         => (string) ( $job['id'] ?? '' ),
			'type'       => (string) ( $job['type'] ?? '' ),
			'status'     => (string) ( $job['status'] ?? '' ),
			'progress'   => (int) ( $job['progress'] ?? 0 ),
			'message'    => (string) ( $job['message'] ?? '' ),
			'error'      => $job['error'] ?? null,
			'step'       => $steps[ $index ] ?? '',
			'step_label' => $handler && isset( $steps[ $index ] ) ? $handler->stepLabel( $steps[ $index ] ) : '',
			'steps'      => array_map(
				static fn ( string $s ): array => array(
					'id'    => $s,
					'label' => $handler ? $handler->stepLabel( $s ) : $s,
				),
				$steps
			),
			'step_index' => $index,
			'updated_at' => (int) ( $job['updated_at'] ?? 0 ),
		);
	}

	/**
	 * Step execution (caller holds the lock).
	 *
	 * @param int $project_id Project.
	 */
	private function runLocked( int $project_id ): void {
		$job = $this->projects->section( $project_id, 'job' );
		if ( ! $job || ! in_array( $job['status'] ?? '', array( 'queued', 'running' ), true ) ) {
			return;
		}
		if ( time() < (int) ( $job['not_before'] ?? 0 ) ) {
			return; // Waiting out a provider rate limit.
		}
		$handler = $this->handlers[ $job['type'] ] ?? null;
		$steps   = (array) $job['steps'];
		$index   = (int) $job['step_index'];

		if ( ! $handler || ! $this->projects->canAccess( $project_id, (int) $job['user_id'] ) ) {
			$this->fail( $project_id, $job, __( 'The job owner can no longer access this project.', 'ai-seo-content-studio' ), ProviderException::PERMISSION );
			return;
		}
		if ( ! isset( $steps[ $index ] ) ) {
			$this->finish( $project_id, $job );
			return;
		}

		wp_set_current_user( (int) $job['user_id'] );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( max( self::LOCK_TTL, $this->lock_ttl ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- may be disabled by the host.
		}

		$step = $steps[ $index ];

		// Count the run before it starts, so a step whose PHP process is killed (time or
		// memory limit) is not re-run forever: each run may make billed requests.
		$job['attempts'] = (int) ( $job['attempts'] ?? 0 ) + 1;
		if ( $job['attempts'] > self::MAX_ATTEMPTS ) {
			$this->fail( $project_id, $job, __( 'This step did not finish. The server may have stopped it (time or memory limit). Try again; if it keeps happening, choose a faster model or ask your host to raise the PHP time limit.', 'ai-seo-content-studio' ), 'interrupted' );
			return;
		}
		$job['status']     = 'running';
		$job['message']    = $handler->stepLabel( $step );
		$job['updated_at'] = time();
		$this->projects->save( $project_id, 'job', $job );

		$state = (array) ( $job['state'] ?? array() );
		try {
			$complete = $handler->runStep( $step, $project_id, $state, (array) ( $job['options'] ?? array() ) );
		} catch ( ProviderException $e ) {
			if ( ! $this->stillCurrent( $project_id, $job ) ) {
				return; // Cancelled or replaced while the step ran.
			}
			$job['state'] = $state;
			if ( ProviderException::RATE_LIMIT === $e->category && (int) ( $job['rate_waits'] ?? 0 ) < self::MAX_RATE_WAITS ) {
				// Free and low tiers allow only a few requests per minute: wait, then continue.
				$wait              = (int) min( 300, max( 60, (int) ( $e->retryAfter ?? 0 ) ) );
				$job['rate_waits'] = (int) ( $job['rate_waits'] ?? 0 ) + 1;
				$job['attempts']   = max( 0, (int) $job['attempts'] - 1 );
				$job['not_before'] = time() + $wait;
				/* translators: %d: seconds. */
				$job['message']    = sprintf( __( 'The AI provider’s per-minute limit was reached. Waiting about %d seconds, then continuing automatically.', 'ai-seo-content-studio' ), $wait );
				$job['updated_at'] = time();
				$this->projects->save( $project_id, 'job', $job );
				$this->dispatchNext( $project_id, $job, $wait );
				return;
			}
			if ( ProviderException::RATE_LIMIT === $e->category ) {
				$this->fail( $project_id, $job, __( 'The AI provider kept refusing requests because of its rate limit. Your daily limit may be used up (free plans allow only a few requests per day). Try again later or use a model with higher limits.', 'ai-seo-content-studio' ), $e->category );
				return;
			}
			if ( $e->retryable && (int) $job['attempts'] < self::MAX_ATTEMPTS ) {
				/* translators: %s: error message. */
				$job['message']    = sprintf( __( 'Temporary problem, retrying: %s', 'ai-seo-content-studio' ), $e->getMessage() );
				$job['updated_at'] = time();
				$this->projects->save( $project_id, 'job', $job );
				$this->dispatchNext( $project_id, $job );
				return;
			}
			$this->fail( $project_id, $job, $e->getMessage(), $e->category );
			return;
		} catch ( \Throwable $e ) {
			$this->logger->error(
				'Job step crashed',
				array(
					'step'  => $step,
					'error' => $e->getMessage(),
				)
			);
			if ( ! $this->stillCurrent( $project_id, $job ) ) {
				return;
			}
			$job['state'] = $state;
			$this->fail( $project_id, $job, __( 'An unexpected error stopped this step. Details were written to the debug log.', 'ai-seo-content-studio' ), 'unexpected' );
			return;
		}

		// The job may have been cancelled, failed or replaced while the step ran.
		if ( ! $this->stillCurrent( $project_id, $job ) ) {
			return;
		}

		$job['state'] = $state;
		// The step finished its run normally, so the attempt is not counted as interrupted.
		// Multi-run steps (one part, or one time budget, per run) return false to be called
		// again; those runs are bounded separately so a step that never completes still stops.
		$job['attempts'] = 0;
		if ( $complete ) {
			++$job['step_index'];
			$job['step_runs'] = 0;
		} else {
			$job['step_runs'] = (int) ( $job['step_runs'] ?? 0 ) + 1;
			if ( $job['step_runs'] >= self::MAX_STEP_RUNS ) {
				$this->fail( $project_id, $job, __( 'This step kept running without finishing, so it was stopped. Try again.', 'ai-seo-content-studio' ), 'stalled' );
				return;
			}
		}
		$job['rate_waits'] = 0;
		$job['progress']   = (int) floor( $job['step_index'] / max( 1, count( $steps ) ) * 100 );
		$job['updated_at'] = time();

		if ( $job['step_index'] >= count( $steps ) ) {
			$this->finish( $project_id, $job );
			return;
		}
		$job['message'] = $handler->stepLabel( $steps[ $job['step_index'] ] );
		$this->projects->save( $project_id, 'job', $job );
		$this->dispatchNext( $project_id, $job );
	}

	/**
	 * Mark completed.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $job        Job.
	 */
	private function finish( int $project_id, array $job ): void {
		$job['status']     = 'completed';
		$job['progress']   = 100;
		$job['message']    = __( 'Completed.', 'ai-seo-content-studio' );
		$job['state']      = array();
		$job['updated_at'] = time();
		$this->projects->save( $project_id, 'job', $job );
	}

	/**
	 * The stored job is still this run's job and still running.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $job        Job as loaded for this run.
	 */
	private function stillCurrent( int $project_id, array $job ): bool {
		$fresh = $this->projects->section( $project_id, 'job' );
		return is_array( $fresh ) && ( $fresh['id'] ?? '' ) === $job['id'] && 'running' === ( $fresh['status'] ?? '' );
	}

	/**
	 * Mark failed.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $job        Job.
	 * @param string               $message    Message.
	 * @param string               $code       Error category.
	 */
	private function fail( int $project_id, array $job, string $message, string $code ): void {
		$job['status']     = 'failed';
		$job['message']    = $message;
		$job['error']      = array(
			'code'    => $code,
			'message' => $message,
			'step'    => $job['steps'][ $job['step_index'] ] ?? '',
		);
		$job['updated_at'] = time();
		$this->projects->save( $project_id, 'job', $job );
	}

	/**
	 * Schedule the next step.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $job        Job.
	 * @param int                  $delay      Seconds to wait before running.
	 */
	private function dispatchNext( int $project_id, array $job, int $delay = 0 ): void {
		$job['seq'] = (int) ( $job['seq'] ?? 0 ) + 1;
		$this->projects->save( $project_id, 'job', $job );
		( $this->dispatcher )( $project_id, $job['seq'], $delay );
	}

	/**
	 * Default dispatcher: Action Scheduler when present, else WP-Cron. The
	 * sequence number keeps WP-Cron from de-duplicating consecutive steps.
	 *
	 * @param int $project_id Project.
	 * @param int $seq        Sequence.
	 * @param int $delay      Seconds to wait before running (0 = now).
	 */
	public static function dispatchDefault( int $project_id, int $seq, int $delay = 0 ): void {
		if ( $delay > 0 && function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + $delay, self::HOOK, array( $project_id, $seq ), 'aiscs' );
			return;
		}
		if ( 0 === $delay && function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK, array( $project_id, $seq ), 'aiscs' );
			return;
		}
		wp_schedule_single_event( time() + $delay, self::HOOK, array( $project_id, $seq ) );
		if ( $delay > 0 ) {
			return; // WP-Cron runs it when due (or the status poll does, after the wait).
		}
		if ( function_exists( 'spawn_cron' ) && ! ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) ) {
			spawn_cron();
		}
	}
}
