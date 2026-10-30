<?php
/**
 * Lazily built Website Studio services.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\WebsiteStudio;

use AiSeoContentStudio\Elementor\ElementorBridge;
use AiSeoContentStudio\Elementor\ElementorBridgeInterface;
use AiSeoContentStudio\WebsiteStudio\Cloner\PageCloner;
use AiSeoContentStudio\WebsiteStudio\Engine\DocumentValidator;
use AiSeoContentStudio\WebsiteStudio\Engine\DraftManager;
use AiSeoContentStudio\WebsiteStudio\Engine\ElementorCompat;
use AiSeoContentStudio\WebsiteStudio\Engine\PageBuilder;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\GeneratedPageRepository;

defined( 'ABSPATH' ) || exit;

/**
 * Elementor registers its widgets late (after `init`), so anything that asks which widgets
 * exist must be built on first use, not on `plugins_loaded`. Background jobs and REST
 * controllers get this object and ask it for services when they run.
 */
final class StudioServices {

	private ?ElementorCompat $compat = null;
	private ?DraftManager $drafts    = null;
	private ?PageCloner $cloner      = null;
	private ?PageBuilder $builder    = null;

	public function __construct(
		private readonly ElementorBridgeInterface $bridge = new ElementorBridge(),
		private readonly GeneratedPageRepository $pages = new GeneratedPageRepository(),
		private readonly AuditLog $audit = new AuditLog(),
	) {}

	public function bridge(): ElementorBridgeInterface {
		return $this->bridge;
	}

	public function pages(): GeneratedPageRepository {
		return $this->pages;
	}

	public function compat(): ElementorCompat {
		return $this->compat ??= ElementorCompat::fromBridge( $this->bridge );
	}

	public function drafts(): DraftManager {
		return $this->drafts ??= new DraftManager( $this->bridge, $this->compat(), new DocumentValidator( $this->compat() ), $this->pages, $this->audit );
	}

	public function cloner(): PageCloner {
		return $this->cloner ??= new PageCloner( $this->bridge, $this->drafts() );
	}

	public function builder(): PageBuilder {
		return $this->builder ??= new PageBuilder( $this->bridge, $this->compat(), $this->drafts() );
	}
}
