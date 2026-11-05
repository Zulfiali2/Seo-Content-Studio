<?php
/**
 * Composition root: builds services once and registers hooks.
 *
 * @package AiSeoContentStudio
 */

namespace AiSeoContentStudio\Core;

use AiSeoContentStudio\Admin\AdminPage;
use AiSeoContentStudio\Admin\WebsiteStudioPage;
use AiSeoContentStudio\Content\BriefJob;
use AiSeoContentStudio\Content\ContentJob;
use AiSeoContentStudio\Content\DraftWriter;
use AiSeoContentStudio\Content\SourceVerifier;
use AiSeoContentStudio\Content\VersionStore;
use AiSeoContentStudio\Elementor\ElementorBridge;
use AiSeoContentStudio\Elementor\ElementorWriter;
use AiSeoContentStudio\Frontend\BlogShortcodes;
use AiSeoContentStudio\Http\HttpClient;
use AiSeoContentStudio\Jobs\JobRunner;
use AiSeoContentStudio\Logging\Logger;
use AiSeoContentStudio\Media\ImageJob;
use AiSeoContentStudio\Media\MediaImporter;
use AiSeoContentStudio\Media\StockImages;
use AiSeoContentStudio\Media\Stock\OpenverseSource;
use AiSeoContentStudio\Media\Stock\PexelsSource;
use AiSeoContentStudio\Media\Stock\PixabaySource;
use AiSeoContentStudio\Optimizer\OptimizeJobs;
use AiSeoContentStudio\Projects\ProjectRepository;
use AiSeoContentStudio\Providers\ImageGenerationService;
use AiSeoContentStudio\Providers\ProviderRegistry;
use AiSeoContentStudio\Providers\TextGenerationService;
use AiSeoContentStudio\REST\ContentController;
use AiSeoContentStudio\REST\ImagesController;
use AiSeoContentStudio\REST\OptimizerController;
use AiSeoContentStudio\REST\ProjectsController;
use AiSeoContentStudio\REST\ProvidersController;
use AiSeoContentStudio\REST\ResearchController;
use AiSeoContentStudio\REST\SettingsController;
use AiSeoContentStudio\REST\StatusController;
use AiSeoContentStudio\REST\UsageController;
use AiSeoContentStudio\REST\WebsiteDesignController;
use AiSeoContentStudio\REST\PageToolsController;
use AiSeoContentStudio\REST\DesignLibraryController;
use AiSeoContentStudio\REST\WebsiteStudioController;
use AiSeoContentStudio\Research\CompetitorFetcher;
use AiSeoContentStudio\Research\CostEstimates;
use AiSeoContentStudio\Research\DataForSEO\DataForSeoProvider;
use AiSeoContentStudio\Research\MarketList;
use AiSeoContentStudio\Research\ResearchCache;
use AiSeoContentStudio\Research\ResearchGateway;
use AiSeoContentStudio\Research\ResearchJob;
use AiSeoContentStudio\SEO\EvaluationJob;
use AiSeoContentStudio\SEO\DeterministicChecks;
use AiSeoContentStudio\SEO\Evaluator;
use AiSeoContentStudio\SEO\MetaOutput;
use AiSeoContentStudio\Security\CredentialStore;
use AiSeoContentStudio\Security\Encryption;
use AiSeoContentStudio\Security\Redactor;
use AiSeoContentStudio\Security\UrlValidator;
use AiSeoContentStudio\Settings\SettingsRepository;
use AiSeoContentStudio\Usage\CostEstimator;
use AiSeoContentStudio\Usage\UsageLimiter;
use AiSeoContentStudio\Usage\UsageRepository;
use AiSeoContentStudio\WebsiteStudio\Analysis\PageCatalog;
use AiSeoContentStudio\WebsiteStudio\Analysis\SiteDesignSource;
use AiSeoContentStudio\WebsiteStudio\Analysis\WebsiteAnalyzer;
use AiSeoContentStudio\WebsiteStudio\Persistence\AnalysisRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\AuditLog;
use AiSeoContentStudio\WebsiteStudio\Persistence\BrandProfileRepository;
use AiSeoContentStudio\WebsiteStudio\Cloner\CloneJob;
use AiSeoContentStudio\WebsiteStudio\StudioServices;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratedImages;
use AiSeoContentStudio\WebsiteStudio\Generator\GeneratorJob;
use AiSeoContentStudio\WebsiteStudio\Generator\SiteGateway;
use AiSeoContentStudio\WebsiteStudio\Editor\PageChecks;
use AiSeoContentStudio\WebsiteStudio\History\RevisionService;
use AiSeoContentStudio\WebsiteStudio\Library\DesignLibrary;
use AiSeoContentStudio\WebsiteStudio\Persistence\LibraryRepository;
use AiSeoContentStudio\WebsiteStudio\Persistence\RevisionRepository;
use AiSeoContentStudio\WebsiteStudio\Preview\PreviewManager;
use AiSeoContentStudio\WebsiteStudio\Sections\Proposals;
use AiSeoContentStudio\WebsiteStudio\Sections\RegenerateJob;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotJob;
use AiSeoContentStudio\WebsiteStudio\Vision\ScreenshotStore;

defined( 'ABSPATH' ) || exit;

/**
 * Main plugin object.
 */
final class Plugin {

	/**
	 * Singleton instance, created on `plugins_loaded`.
	 *
	 * @var Plugin|null
	 */
	private static ?Plugin $instance = null;

	/**
	 * Settings repository.
	 *
	 * @var SettingsRepository
	 */
	public readonly SettingsRepository $settings;

	/**
	 * Encrypted credential store.
	 *
	 * @var CredentialStore
	 */
	public readonly CredentialStore $credentials;

	/**
	 * Logger.
	 *
	 * @var Logger
	 */
	public readonly Logger $logger;

	/**
	 * HTTP client.
	 *
	 * @var HttpClient
	 */
	public readonly HttpClient $http;

	/**
	 * AI providers.
	 *
	 * @var ProviderRegistry
	 */
	public readonly ProviderRegistry $providers;

	/**
	 * Usage log.
	 *
	 * @var UsageRepository
	 */
	public readonly UsageRepository $usage;

	/**
	 * Usage limits.
	 *
	 * @var UsageLimiter
	 */
	public readonly UsageLimiter $limiter;

	/**
	 * Cost estimator.
	 *
	 * @var CostEstimator
	 */
	public readonly CostEstimator $costs;

	/**
	 * Controlled text generation entry point.
	 *
	 * @var TextGenerationService
	 */
	public readonly TextGenerationService $text;

	/**
	 * Controlled image generation entry point.
	 *
	 * @var ImageGenerationService
	 */
	public readonly ImageGenerationService $images;

	/**
	 * Media Library importer.
	 *
	 * @var MediaImporter
	 */
	private MediaImporter $media;

	/**
	 * Environment probe.
	 *
	 * @var Environment
	 */
	public readonly Environment $environment;

	/**
	 * Research gateway (cache, limits, usage).
	 *
	 * @var ResearchGateway
	 */
	public readonly ResearchGateway $research;

	/**
	 * Content projects.
	 *
	 * @var ProjectRepository
	 */
	public readonly ProjectRepository $projects;

	/**
	 * Background job runner.
	 *
	 * @var JobRunner
	 */
	public readonly JobRunner $jobs;

	/**
	 * Job handlers by type.
	 *
	 * @var array<string, \AiSeoContentStudio\Jobs\JobHandlerInterface>
	 */
	private array $handlers;

	/**
	 * Content versions.
	 *
	 * @var VersionStore
	 */
	private VersionStore $versions;

	/**
	 * SEO evaluator.
	 *
	 * @var Evaluator
	 */
	private Evaluator $evaluator;

	/**
	 * Draft writer.
	 *
	 * @var DraftWriter
	 */
	private DraftWriter $drafts;

	/**
	 * DataForSEO adapter.
	 *
	 * @var DataForSeoProvider
	 */
	private DataForSeoProvider $dataforseo;

	/**
	 * Market list.
	 *
	 * @var MarketList
	 */
	private MarketList $markets;

	/**
	 * Cost estimates.
	 *
	 * @var CostEstimates
	 */
	private CostEstimates $estimates;

	/**
	 * AI Website Studio services (built on first use).
	 *
	 * @var StudioServices
	 */
	private StudioServices $studio;

	/**
	 * Build the service graph.
	 */
	private function __construct() {
		$this->settings    = new SettingsRepository();
		$redactor          = new Redactor();
		$this->logger      = new Logger( $redactor, (bool) $this->settings->get( 'general.debug_logging' ) );
		$this->credentials = new CredentialStore( Encryption::fromEnvironment(), Encryption::previousFromEnvironment() );
		$redactor->addSecretSource( fn (): array => $this->credentials->allPlaintextSecrets() );

		$this->http = new HttpClient(
			$this->logger,
			new UrlValidator(),
			max_retries: (int) $this->settings->get( 'ai.max_retries' ),
			timeout: (int) $this->settings->get( 'ai.request_timeout' ),
		);

		$this->providers   = new ProviderRegistry( $this->settings, $this->credentials, $this->http );
		$this->usage       = new UsageRepository();
		$this->limiter     = new UsageLimiter( $this->usage, $this->settings );
		$this->costs       = new CostEstimator( $this->settings );
		$this->text        = new TextGenerationService( $this->providers, $this->settings, $this->limiter, $this->usage, $this->costs, $this->logger );
		$this->images      = new ImageGenerationService( $this->providers, $this->settings, $this->limiter, $this->usage, $this->costs, $this->logger );
		$this->media       = new MediaImporter();
		$this->environment = new Environment();

		$this->dataforseo = new DataForSeoProvider( $this->settings, $this->credentials, $this->http );
		$this->research   = new ResearchGateway( $this->dataforseo, new ResearchCache(), $this->limiter, $this->usage, $this->settings );
		$this->projects   = new ProjectRepository();
		$this->versions   = new VersionStore( $this->projects );
		$this->evaluator  = new Evaluator( $this->projects, new DeterministicChecks(), $this->settings );
		$this->drafts     = new DraftWriter( $this->projects, $this->versions );
		$this->studio     = new StudioServices();
		$this->handlers   = array(
			'research'          => new ResearchJob( $this->projects, $this->research, new CompetitorFetcher( $this->http ), $this->text, $this->settings ),
			'brief'             => new BriefJob( $this->projects, $this->text, $this->settings ),
			'content'           => new ContentJob( $this->projects, $this->text, $this->settings, $this->versions, $this->evaluator, new SourceVerifier( $this->http ) ),
			'evaluate'          => new EvaluationJob( $this->projects, $this->text, $this->evaluator, $this->settings ),
			'optimize_analysis' => new OptimizeJobs( 'optimize_analysis', $this->projects, $this->text, $this->settings, $this->evaluator, $this->versions ),
			'optimize_rewrite'  => new OptimizeJobs( 'optimize_rewrite', $this->projects, $this->text, $this->settings, $this->evaluator, $this->versions ),
			'images'            => new ImageJob( $this->projects, $this->images, $this->media ),
			CloneJob::TYPE      => new CloneJob( $this->projects, $this->text, $this->settings, $this->studio ),
		);
		foreach ( array( ScreenshotJob::TYPE_ANALYZE, ScreenshotJob::TYPE_BUILD ) as $screenshot_type ) {
			$this->handlers[ $screenshot_type ] = new ScreenshotJob( $screenshot_type, $this->projects, $this->text, $this->settings, $this->studio, $this->media, new ScreenshotStore(), new BrandProfileRepository() );
		}
		foreach ( array( GeneratorJob::TYPE_WRITE, GeneratorJob::TYPE_BUILD, GeneratorJob::TYPE_IMAGES ) as $generator_type ) {
			$this->handlers[ $generator_type ] = new GeneratorJob( $generator_type, $this->projects, $this->text, $this->images, $this->settings, $this->studio, $this->media, new SiteGateway(), new BrandProfileRepository() );
		}
		$this->handlers[ RegenerateJob::TYPE ] = new RegenerateJob( $this->projects, $this->text, $this->images, $this->media, $this->settings, $this->proposals() );
		// The job lock must outlive the slowest request a step can make (all retries included).
		$job_ttl         = (int) $this->settings->get( 'ai.request_timeout' ) * ( 1 + (int) $this->settings->get( 'ai.max_retries' ) ) + 180;
		$this->jobs      = new JobRunner( $this->projects, array_values( $this->handlers ), $this->logger, null, $job_ttl );
		$this->markets   = new MarketList( $this->research );
		$this->estimates = new CostEstimates( $this->usage, $this->costs, $this->settings );
	}

	/**
	 * Entry point hooked to `plugins_loaded`.
	 */
	public static function boot(): void {
		if ( null !== self::$instance ) {
			return;
		}
		self::$instance = new self();
		self::$instance->registerHooks();
	}

	/**
	 * Access the running instance (null before boot).
	 */
	public static function instance(): ?Plugin {
		return self::$instance;
	}

	/**
	 * Register WordPress hooks.
	 */
	private function registerHooks(): void {
		add_action( 'init', array( $this, 'loadTextdomain' ) );
		// Cheap version check; cron and REST requests may run before any admin page load.
		Migrations::maybeMigrate();
		add_action( 'rest_api_init', array( $this, 'registerRestRoutes' ) );
		add_action( 'init', array( $this, 'scheduleMaintenance' ) );
		add_action( 'aiscs_prune_usage_log', array( $this, 'pruneUsageLog' ) );
		add_action( 'init', array( ProjectRepository::class, 'registerPostType' ) );
		add_action( JobRunner::HOOK, array( $this, 'runJob' ), 10, 2 );
		add_action( 'pre_get_posts', array( PreviewManager::class, 'hideFromLists' ) );
		add_action( 'deleted_post', static fn ( int $post_id ) => ( new RevisionRepository() )->deleteForPage( $post_id ) );

		// Blog template shortcodes render on the front end and in the Elementor editor (admin AJAX).
		( new BlogShortcodes() )->register();

		if ( is_admin() ) {
			( new AdminPage( $this->environment, $this->settings ) )->register();
			( new WebsiteStudioPage( $this->environment ) )->register();
		} else {
			( new MetaOutput( $this->settings, $this->environment ) )->register();
		}
	}

	/**
	 * Load translations.
	 */
	public function loadTextdomain(): void {
		load_plugin_textdomain( 'ai-seo-content-studio', false, dirname( plugin_basename( AISCS_FILE ) ) . '/languages' );
	}

	/**
	 * Ensure the daily maintenance event exists. WP-Cron timing is approximate,
	 * which is fine for log pruning.
	 */
	public function scheduleMaintenance(): void {
		if ( ! wp_next_scheduled( 'aiscs_prune_usage_log' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'aiscs_prune_usage_log' );
		}
	}

	/**
	 * Delete usage records older than the retention setting.
	 */
	public function pruneUsageLog(): void {
		$days = (int) $this->settings->get( 'general.usage_retention_days' );
		$this->usage->prune( $days );
		// Website Studio retention follows the same setting.
		( new AnalysisRepository() )->prune( $days );
		( new AuditLog() )->prune( $days );
		// Uploaded screenshots are deleted after analysis; this removes any left behind.
		( new ScreenshotStore() )->prune( DAY_IN_SECONDS );
		// Expired preview pages and the history of pages that were deleted.
		$this->previews()->prune();
		( new RevisionRepository() )->pruneOrphans();
	}

	/**
	 * Background job callback. The sequence argument only exists to keep WP-Cron
	 * from de-duplicating consecutive steps.
	 *
	 * @param int $project_id Project.
	 * @param int $seq        Sequence (unused).
	 */
	public function runJob( $project_id, $seq = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed
		$this->jobs->run( (int) $project_id );
	}

	/**
	 * Register REST controllers.
	 */
	public function registerRestRoutes(): void {
		( new SettingsController( $this->settings ) )->register();
		( new ProvidersController( $this->providers, $this->credentials, $this->settings ) )->register();
		( new UsageController( $this->usage ) )->register();
		( new StatusController( $this->environment, $this->providers, $this->credentials, $this->settings ) )->register();
		( new ResearchController( $this->research, $this->dataforseo, $this->credentials, $this->markets ) )->register();
		$projects_controller = new ProjectsController( $this->projects, $this->jobs, $this->estimates, $this->handlers );
		$projects_controller->register();
		( new ContentController( $projects_controller, $this->projects, $this->jobs, $this->evaluator, $this->versions, $this->drafts, $this->settings, $this->environment, new ElementorWriter( $this->projects, $this->drafts, new ElementorBridge() ) ) )->register();
		( new OptimizerController( $projects_controller, $this->projects, $this->jobs ) )->register();
		$stock = new StockImages(
			array(
				new OpenverseSource( $this->http ),
				new PexelsSource( $this->http, $this->credentials ),
				new PixabaySource( $this->http, $this->credentials ),
			),
			$this->http,
			$this->media,
			$this->projects
		);
		( new ImagesController( $projects_controller, $this->projects, $this->jobs, $this->media, $this->images, $stock, $this->credentials ) )->register();

		// AI Website Studio.
		$design_source = new SiteDesignSource( new ElementorBridge() );
		$analyses      = new AnalysisRepository();
		( new WebsiteStudioController(
			new PageCatalog( $design_source ),
			new WebsiteAnalyzer( $design_source, $analyses ),
			$analyses,
			new BrandProfileRepository(),
			new AuditLog()
		) )->register();
		$site = new SiteGateway();
		( new WebsiteDesignController( $this->studio, $this->projects, $this->jobs, new BrandProfileRepository(), $this->settings, $this->images, new GeneratedImages( $this->projects, $this->studio, $site, $this->media ), $site ) )->register();
		( new PageToolsController( $this->studio, $this->projects, $this->jobs, new BrandProfileRepository(), new WebsiteAnalyzer( $design_source, $analyses ), new PageChecks( $this->studio ), $this->proposals(), new RevisionService( $this->studio ), $this->images ) )->register();
		( new DesignLibraryController( new LibraryRepository(), new DesignLibrary( new LibraryRepository(), $this->studio, $this->previews() ), new BrandProfileRepository() ) )->register();
	}

	/**
	 * Temporary preview pages.
	 */
	private function previews(): PreviewManager {
		return new PreviewManager( $this->studio );
	}

	/**
	 * Section regeneration proposals.
	 */
	private function proposals(): Proposals {
		return new Proposals( $this->projects, $this->studio, $this->previews(), new SiteGateway(), $this->media, new BrandProfileRepository() );
	}
}
