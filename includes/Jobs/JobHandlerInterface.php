<?php
/**
 * Background job handler contract.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Jobs;

use AiSeoContentStudio\Providers\Contracts\ProviderException;

defined( 'ABSPATH' ) || exit;

/**
 * A job is a list of steps. Each step should finish well within a normal PHP
 * time limit (one external call or a bounded batch). Steps must be idempotent:
 * an interrupted step may run again, so paid calls must go through caches.
 */
interface JobHandlerInterface {

	/** Job type id. */
	public function type(): string;

	/**
	 * Steps for this run.
	 *
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $options    Job options.
	 * @return string[]
	 */
	public function steps( int $project_id, array $options ): array;

	/**
	 * Run one step.
	 *
	 * @param string               $step       Step id.
	 * @param int                  $project_id Project.
	 * @param array<string, mixed> $state      Scratch state persisted between steps.
	 * @param array<string, mixed> $options    Job options.
	 * @return bool True when the step is complete; false to run it again (continuation).
	 * @throws ProviderException On provider failures.
	 */
	public function runStep( string $step, int $project_id, array &$state, array $options ): bool;

	/**
	 * Human-readable label for a step.
	 *
	 * @param string $step Step id.
	 */
	public function stepLabel( string $step ): string;
}
