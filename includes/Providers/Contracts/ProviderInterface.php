<?php
/**
 * Common provider contract.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Every AI provider adapter implements this.
 */
interface ProviderInterface {

	/** Stable machine id, e.g. "openai". */
	public function id(): string;

	/** Human-readable name. */
	public function label(): string;

	/**
	 * Capabilities supported by this adapter.
	 *
	 * @return Capability[]
	 */
	public function capabilities(): array;

	/**
	 * Whether a capability is supported.
	 *
	 * @param Capability $capability Capability.
	 */
	public function supports( Capability $capability ): bool;

	/** Whether required credentials/settings are present. */
	public function isConfigured(): bool;

	/**
	 * Verify credentials without incurring generation costs where the API allows.
	 */
	public function testConnection(): ConnectionResult;

	/**
	 * Models visible to the configured credentials.
	 *
	 * @return ModelInfo[]
	 * @throws ProviderException On failure.
	 */
	public function listModels(): array;
}
