<?php
/**
 * Model metadata returned by list endpoints.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Providers\Contracts;

defined( 'ABSPATH' ) || exit;

/**
 * Model listing entry.
 */
final readonly class ModelInfo {

	/**
	 * Constructor.
	 *
	 * @param string   $id    Model id used in API calls.
	 * @param string   $label Display name.
	 * @param string[] $hints Capability hints reported by the API (e.g. "generateContent"); empty when unknown.
	 */
	public function __construct( public string $id, public string $label = '', public array $hints = array() ) {}

	/**
	 * Array form for REST responses.
	 *
	 * @return array{id: string, label: string, hints: string[]}
	 */
	public function toArray(): array {
		return array(
			'id'    => $this->id,
			'label' => '' === $this->label ? $this->id : $this->label,
			'hints' => $this->hints,
		);
	}
}
