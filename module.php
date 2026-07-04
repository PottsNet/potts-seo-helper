<?php

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\FlashMessages;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Menu;
use Fisharebest\Webtrees\Module\AbstractModule;
use Fisharebest\Webtrees\Module\ModuleBlockInterface;
use Fisharebest\Webtrees\Module\ModuleBlockTrait;
use Fisharebest\Webtrees\Module\ModuleConfigInterface;
use Fisharebest\Webtrees\Module\ModuleConfigTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\ModuleGlobalInterface;
use Fisharebest\Webtrees\Module\ModuleGlobalTrait;
use Fisharebest\Webtrees\Module\ModuleMenuInterface;
use Fisharebest\Webtrees\Module\ModuleMenuTrait;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Support\Str;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

return new class extends AbstractModule implements ModuleCustomInterface, ModuleConfigInterface, ModuleGlobalInterface, ModuleMenuInterface, ModuleBlockInterface, RequestHandlerInterface {
    use ModuleCustomTrait;
    use ModuleBlockTrait;
    use ModuleConfigTrait;
    use ModuleGlobalTrait;
    use ModuleMenuTrait;

    /**
     * Debug details from the most recent surname lookup, shown only to administrators.
     *
     * @var array<string,int|string>
     */
    private array $last_surname_debug = [];

    private const CUSTOM_VERSION = '0.6.19';
    private const LATEST_VERSION_URL = 'https://raw.githubusercontent.com/PottsNet/potts-seo-helper/main/latest-version.txt';
    private const ACTION_PARAM = 'potts_seo_action';
    private const ADMIN_OUTPUT_PARAM = 'potts_seo_admin_output';
    private const TREE_PARAM = 'potts_seo_tree';
    private const ROUTE_URL = '/tree/{tree}/potts-seo-helper';
    private const ROOT_ROBOTS_ROUTE = '/robots.txt';
    private const ROOT_SITEMAP_ROUTE = '/sitemap.xml';
    private const DYNAMIC_ROBOTS_ROUTE = '/potts-seo-helper/robots.txt';
    private const DYNAMIC_SITEMAP_ROUTE = '/potts-seo-helper/sitemap.xml';

    public function title(): string
    {
        return I18N::translate('Potts SEO Helper');
    }

    public function description(): string
    {
        return I18N::translate('Adds genealogy-focused SEO metadata, homepage support, public landing pages, robots text and sitemap endpoints.');
    }

    public function customModuleAuthorName(): string
    {
        return 'Jason Potts';
    }

    public function customModuleVersion(): string
    {
        return self::CUSTOM_VERSION;
    }

    public function customModuleLatestVersion(): string
    {
        return Registry::cache()->file()->remember(
            $this->name() . '-latest-version',
            function (): string {
                $latest = trim((string) @file_get_contents(self::LATEST_VERSION_URL));

                if (preg_match('/^v?(\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.\-]+)?)$/', $latest, $match) === 1) {
                    return $match[1];
                }

                return $this->customModuleVersion();
            },
            86400
        );
    }

    public function customModuleLatestVersionUrl(): string
    {
        return self::LATEST_VERSION_URL;
    }

    public function customModuleSupportUrl(): string
    {
        return 'https://github.com/PottsNet/potts-seo-helper';
    }

    public function resourcesFolder(): string
    {
        return __DIR__ . '/resources/';
    }

    public function boot(): void
    {
        $route_map = Registry::routeFactory()->routeMap();
        // Existing tree-scoped helper routes.
        $route_map->get(static::class, self::ROUTE_URL, $this);
        $route_map->get(static::class . '.sitemap', self::ROUTE_URL . '/sitemap', $this);
        $route_map->get(static::class . '.robots', self::ROUTE_URL . '/robots', $this);
        $route_map->get(static::class . '.health', self::ROUTE_URL . '/health', $this);
        $route_map->get(static::class . '.person', self::ROUTE_URL . '/person', $this);
        $route_map->get(static::class . '.surname', self::ROUTE_URL . '/surname', $this);

        // Root-style endpoints for option B: let Apache/.htaccess route
        // /robots.txt and /sitemap.xml through webtrees to this module.
        // These routes use the configured preview tree or the first available tree,
        // because root files do not naturally include a webtrees tree context.
        $route_map->get(static::class . '.root_robots', self::ROOT_ROBOTS_ROUTE, $this);
        $route_map->get(static::class . '.root_sitemap', self::ROOT_SITEMAP_ROUTE, $this);
        $route_map->get(static::class . '.dynamic_robots', self::DYNAMIC_ROBOTS_ROUTE, $this);
        $route_map->get(static::class . '.dynamic_sitemap', self::DYNAMIC_SITEMAP_ROUTE, $this);

        View::registerNamespace($this->name(), $this->resourcesFolder() . 'views/');
    }

    public function defaultMenuOrder(): int
    {
        return 99;
    }

    public function loadAjax(): bool
    {
        return false;
    }

    public function isUserBlock(): bool
    {
        return $this->prefBool('enable_homepage_block', true);
    }

    public function isTreeBlock(): bool
    {
        return $this->prefBool('enable_homepage_block', true);
    }

    /**
     * Generate the optional homepage start block.
     *
     * @param array<string,string> $config
     */
    public function getBlock(Tree $tree, int $block_id, string $context, array $config = []): string
    {
        $content = view($this->name() . '::start-block', [
            'tree' => $tree,
            'title' => $this->pref('homepage_block_title', 'Start exploring our family history'),
            'intro' => $this->pref('homepage_block_intro', 'Browse the main family names, featured ancestors and public family-history pages.'),
            'surnames' => $this->surnames(),
            'featured_people' => $this->featuredPeople($tree),
            'sitemap_url' => $this->urlForAction($tree, 'sitemap'),
            'health_url' => $this->urlForAction($tree, 'health'),
            'is_admin' => Auth::isAdmin(),
            'module' => $this,
        ]);

        if ($context === self::CONTEXT_EMBED) {
            return $content;
        }

        return view('modules/block-template', [
            'block' => Str::kebab($this->name()) . '-start',
            'id' => $block_id,
            'config_url' => $this->configUrl($tree, $context, $block_id),
            'title' => $this->pref('homepage_block_title', 'Start exploring our family history'),
            'content' => $content,
        ]);
    }

    public function saveBlockConfiguration(ServerRequestInterface $request, int $block_id): void
    {
        // This block is configured from the module settings page.
    }

    public function editBlockConfiguration(Tree $tree, int $block_id): string
    {
        return '<p>' . I18N::translate('Configure this block from the Potts SEO Helper module settings.') . '</p>';
    }

    public function getMenu(Tree $tree): ?Menu
    {
        if (!$this->menuIsVisible()) {
            return null;
        }

        $menu_title = $this->menuTitle();
        $url = $this->urlForAction($tree, 'landing');

        return new Menu($menu_title, e($url), $this->name() . ' search');
    }

    public function menuTitle(): string
    {
        $menu_title = $this->pref('menu_title', 'SEO');

        if ($menu_title === '' || $menu_title === 'Family History') {
            return 'SEO';
        }

        return $menu_title;
    }

    /**
     * Content added near the end of the HTML <head> by webtrees global modules.
     */
    public function headContent(): string
    {
        $analytics = $this->analyticsHeadContent();
        $noindex = $this->utilityNoindexHeadContent();

        if (!$this->prefBool('enable_head_tags', true)) {
            return $noindex . $analytics;
        }

        $person_context = $this->currentPersonContext();

        if ($person_context !== null && $this->prefBool('enable_individual_tags', true)) {
            return $this->mergeHeadContent($noindex, $this->headContentForPerson($person_context)) . $analytics;
        }

        $surname_context = $this->currentSurnameContext();

        if ($surname_context !== null && $this->prefBool('enable_surname_pages', true)) {
            return $this->mergeHeadContent($noindex, $this->headContentForSurname($surname_context['tree'], $surname_context['surname'])) . $analytics;
        }

        $tree = $this->currentTree();
        if ($tree instanceof Tree && $this->prefBool('use_existing_homepage_as_landing', true) && $this->prefBool('enable_homepage_seo_tags', true) && $this->isTreeHomepageRoute($tree)) {
            return $this->mergeHeadContent($noindex, $this->headContentForHomepage($tree)) . $analytics;
        }

        return $this->mergeHeadContent($noindex, $this->headContentForSite()) . $analytics;
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getQueryParams();
        $admin_output = strtolower(trim((string) ($params[self::ADMIN_OUTPUT_PARAM] ?? '')));

        if ($admin_output !== '') {
            $tree = $this->treeFromActionRequest($request);

            if (!$tree instanceof Tree) {
                return $this->treeNotFoundResponse();
            }

            return $this->adminOutputResponse($admin_output, $tree);
        }

        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::edit', [
            'title' => $this->title(),
            'module' => $this,
            'preferences' => $this->adminPreferences(),
            'admin_links' => $this->adminPreviewLinks($request),
        ]);
    }

    public function postAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array) $request->getParsedBody();

        foreach ($this->adminPreferences() as $key => $default) {
            $value = $params[$key] ?? '';
            $this->setPreference($key, trim((string) $value));
        }

        FlashMessages::addMessage(I18N::translate('The preferences for the module “%s” have been updated.', $this->title()), 'success');

        return redirect((string) ($_SERVER['REQUEST_URI'] ?? ''));
    }

    public function getLandingAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        return $this->landingPage($tree);
    }

    public function getSitemapAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        return $this->xmlResponse($this->renderSitemap($tree));
    }

    public function getRobotsAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        return $this->plainTextResponse($this->renderRobots($tree));
    }

    private function plainTextResponse(string $body, int $status = 200): ResponseInterface
    {
        return response($body, $status)
            ->withHeader('Content-Type', 'text/plain; charset=UTF-8')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    private function xmlResponse(string $body, int $status = 200): ResponseInterface
    {
        return response($body, $status)
            ->withHeader('Content-Type', 'application/xml; charset=UTF-8')
            ->withHeader('X-Robots-Tag', 'noindex');
    }

    public function getHealthAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        return $this->healthPage($tree);
    }

    public function getPersonAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        $params = $request->getQueryParams();

        return $this->personPage($tree, (string) ($params['xref'] ?? ''));
    }

    public function getSurnameAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $this->treeFromActionRequest($request);

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        $params = $request->getQueryParams();

        return $this->surnamePage($tree, (string) ($params['surname'] ?? ''));
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        if (!$tree instanceof Tree) {
            $tree = $this->treeFromActionRequest($request);
        }

        if (!$tree instanceof Tree) {
            return $this->treeNotFoundResponse();
        }

        $params = $request->getQueryParams();
        $action = $this->seoActionFromParams($params);

        if ($action === 'sitemap') {
            return $this->xmlResponse($this->renderSitemap($tree));
        }

        if ($action === 'robots') {
            return $this->plainTextResponse($this->renderRobots($tree));
        }

        if ($action === 'person') {
            return $this->personPage($tree, (string) ($params['xref'] ?? ''));
        }

        if ($action === 'health') {
            return $this->healthPage($tree);
        }

        if ($action === 'surname') {
            return $this->surnamePage($tree, (string) ($params['surname'] ?? ''));
        }

        return $this->landingPage($tree);
    }

    private function landingPage(Tree $tree): ResponseInterface
    {
        $this->layout = 'layouts/default';

        return $this->viewResponse($this->name() . '::landing', [
            'tree' => $tree,
            'title' => $this->pref('landing_title', 'Family History'),
            'site_name' => $this->pref('site_name', 'Family History'),
            'intro' => $this->pref('landing_intro', 'Welcome to our family history website.'),
            'description' => $this->pref('site_description', 'A family history website built with webtrees, sharing family stories, records, photographs and genealogy research.'),
            'surnames' => $this->surnames(),
            'places' => $this->places(),
            'sitemap_url' => $this->urlForAction($tree, 'sitemap'),
            'robots_url' => $this->urlForAction($tree, 'robots'),
            'site_robots_url' => rtrim($this->siteBaseUrl(), '/') . '/robots.txt',
            'robots_text' => $this->renderRobots($tree),
            'health_url' => $this->urlForAction($tree, 'health'),
            'featured_people' => $this->featuredPeople($tree),
            'is_admin' => Auth::isAdmin(),
            'menu_visibility' => $this->pref('menu_visibility', 'admin'),
            'seo_indexing_mode' => $this->pref('seo_indexing_mode', 'testing'),
            'module' => $this,
        ]);
    }

    private function personPage(Tree $tree, string $xref): ResponseInterface
    {
        $individual = $this->individual($tree, $xref);

        if (!$individual instanceof Individual || !$this->isPublicDeceasedIndividual($individual)) {
            return response('This person is not available as a public SEO page.', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $this->layout = 'layouts/default';
        $context = $this->personContext($individual, $tree);

        return $this->viewResponse($this->name() . '::person', [
            'tree' => $tree,
            'title' => $context['title'],
            'context' => $context,
            'module' => $this,
        ]);
    }

    private function surnamePage(Tree $tree, string $surname): ResponseInterface
    {
        $surname = trim($surname);
        if ($surname === '') {
            return response('Surname not supplied.', 404, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $this->layout = 'layouts/default';
        $people = $this->publicPeopleBySurname($tree, $surname, (int) $this->pref('max_people_per_surname_page', '100'));
        $title = $surname . ' Family History';

        return $this->viewResponse($this->name() . '::surname', [
            'tree' => $tree,
            'title' => $title,
            'surname' => $surname,
            'people' => $people,
            'description' => $this->surnameDescription($surname),
            'search_debug' => $this->last_surname_debug ?? [],
            'is_admin' => Auth::isAdmin(),
            'module' => $this,
        ]);
    }

    private function healthPage(Tree $tree): ResponseInterface
    {
        if (!Auth::isAdmin()) {
            return response('Only administrators can view the SEO health check.', 403, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        }

        $this->layout = 'layouts/default';

        return $this->viewResponse($this->name() . '::health', [
            'tree' => $tree,
            'title' => 'SEO health check',
            'summary' => $this->healthSummary($tree),
            'surname_reports' => $this->surnameHealthReports($tree),
            'sitemap_url' => $this->urlForAction($tree, 'sitemap'),
            'robots_url' => $this->urlForAction($tree, 'robots'),
            'homepage_url' => $this->treeHomeUrl($tree),
            'landing_url' => $this->urlForAction($tree, 'landing'),
            'module' => $this,
        ]);
    }

    private function adminPreferences(): array
    {
        return [
            'site_name' => 'Family History',
            'preview_tree_name' => '',
            'menu_title' => 'SEO',
            'menu_visibility' => 'admin',
            'seo_indexing_mode' => 'testing',
            'use_existing_homepage_as_landing' => '1',
            'enable_homepage_seo_tags' => '1',
            'enable_homepage_block' => '1',
            'homepage_title' => 'Family History | Genealogy and Family Tree',
            'homepage_description' => 'A family history website built with webtrees, sharing family stories, records, photographs and genealogy research.',
            'homepage_block_title' => 'Start exploring our family history',
            'homepage_block_intro' => 'Browse the main family names, featured ancestors and public family-history pages.',
            'landing_title' => 'Family History',
            'publisher_name' => '',
            'site_description' => 'A family history website built with webtrees, sharing family stories, records, photographs and genealogy research.',
            'landing_intro' => 'Welcome to our family history website.',
            'primary_surnames' => '',
            'primary_places' => '',
            'featured_individual_xrefs' => '',
            'google_site_verification' => '',
            'enable_google_analytics' => '0',
            'google_analytics_measurement_id' => '',
            'analytics_exclude_logged_users' => '1',
            'analytics_exclude_admin_pages' => '1',
            'analytics_respect_private_pages' => '1',
            'enable_robots_protection' => '1',
            'robots_bingbot_crawl_delay' => '10',
            'robots_disallow_paths' => $this->defaultRobotsDisallowPaths(),
            'enable_noindex_rules' => '1',
            'noindex_url_patterns' => $this->defaultNoindexUrlPatterns(),
            'include_helper_in_sitemap' => '0',
            'additional_sitemap_urls' => '/tree/{tree}/family-books',
            'enable_head_tags' => '1',
            'enable_individual_tags' => '1',
            'enable_surname_pages' => '1',
            'noindex_living_or_private_people' => '1',
            'include_surname_pages_in_sitemap' => '0',
            'include_people_in_sitemap' => '0',
            'people_sitemap_target' => 'webtrees',
            'max_people_in_sitemap' => '1000',
            'max_people_per_surname_page' => '100',
        ];
    }

    private function headContentForSite(): string
    {
        $site_name = $this->pref('site_name', 'Family History');
        $description = $this->pref('site_description', 'A family history website built with webtrees, sharing family stories, records, photographs and genealogy research.');
        $canonical = $this->currentAbsoluteUrl();
        $verification = $this->pref('google_site_verification', '');

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site_name,
            'url' => $this->siteBaseUrl(),
            'description' => $description,
            'inLanguage' => 'en-AU',
        ];

        $publisher_name = $this->pref('publisher_name', '');
        if ($publisher_name !== '') {
            $json['publisher'] = [
                '@type' => 'Person',
                'name' => $publisher_name,
            ];
        }

        $is_noindex = $this->seoHelperPageShouldNoindex();

        return $this->tags([
            '<!-- Potts SEO Helper -->',
            $is_noindex ? '<meta name="robots" content="noindex,follow">' : '',
            '<meta name="description" content="' . $this->h($description) . '">',
            '<meta property="og:site_name" content="' . $this->h($site_name) . '">',
            '<meta property="og:title" content="' . $this->h($site_name) . '">',
            '<meta property="og:description" content="' . $this->h($description) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="' . $this->h($canonical) . '">',
            '<meta name="twitter:card" content="summary">',
            '<meta name="twitter:title" content="' . $this->h($site_name) . '">',
            '<meta name="twitter:description" content="' . $this->h($description) . '">',
            '<link rel="canonical" href="' . $this->h($canonical) . '">',
            $verification !== '' ? '<meta name="google-site-verification" content="' . $this->h($verification) . '">' : '',
            '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>',
            '<!-- /Potts SEO Helper -->',
        ]);
    }

    private function headContentForHomepage(Tree $tree): string
    {
        $site_name = $this->pref('site_name', 'Family History');
        $title = $this->pref('homepage_title', 'Family History | Genealogy and Family Tree');
        $description = $this->pref('homepage_description', 'A family history website built with webtrees, sharing family stories, records, photographs and genealogy research.');
        $canonical = $this->treeHomeUrl($tree);
        $verification = $this->pref('google_site_verification', '');
        $surnames = $this->surnames();
        $places = $this->places();

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site_name,
            'alternateName' => $tree->title(),
            'url' => $canonical,
            'description' => $description,
            'about' => array_values(array_map(static function (string $surname): array {
                return [
                    '@type' => 'Thing',
                    'name' => $surname . ' family history',
                ];
            }, $surnames)),
            'keywords' => implode(', ', array_merge($surnames, $places, ['family history', 'genealogy', 'family tree'])),
            'inLanguage' => 'en-AU',
        ];

        $publisher_name = $this->pref('publisher_name', '');
        if ($publisher_name !== '') {
            $json['publisher'] = [
                '@type' => 'Person',
                'name' => $publisher_name,
            ];
        }

        return $this->tags([
            '<!-- Potts SEO Helper homepage metadata -->',
            '<meta name="description" content="' . $this->h($description) . '">',
            '<meta property="og:site_name" content="' . $this->h($site_name) . '">',
            '<meta property="og:title" content="' . $this->h($title) . '">',
            '<meta property="og:description" content="' . $this->h($description) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="' . $this->h($canonical) . '">',
            '<meta name="twitter:card" content="summary">',
            '<meta name="twitter:title" content="' . $this->h($title) . '">',
            '<meta name="twitter:description" content="' . $this->h($description) . '">',
            '<link rel="canonical" href="' . $this->h($canonical) . '">',
            $verification !== '' ? '<meta name="google-site-verification" content="' . $this->h($verification) . '">' : '',
            '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>',
            '<!-- /Potts SEO Helper homepage metadata -->',
        ]);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function headContentForPerson(array $context): string
    {
        $site_name = $this->pref('site_name', 'Family History');
        $verification = $this->pref('google_site_verification', '');
        $title = $context['title'];
        $description = $context['description'];
        $canonical = $context['canonical'];

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'Person',
            'name' => $context['name'],
            'url' => $canonical,
            'description' => $description,
        ];

        if ($context['birth_date'] !== '') {
            $json['birthDate'] = $context['birth_date'];
        }
        if ($context['death_date'] !== '') {
            $json['deathDate'] = $context['death_date'];
        }
        if ($context['birth_place'] !== '') {
            $json['birthPlace'] = [
                '@type' => 'Place',
                'name' => $context['birth_place'],
            ];
        }
        if ($context['death_place'] !== '') {
            $json['deathPlace'] = [
                '@type' => 'Place',
                'name' => $context['death_place'],
            ];
        }

        $is_noindex = (string) ($context['noindex'] ?? '0') === '1' || $this->seoHelperPageShouldNoindex();

        return $this->tags([
            '<!-- Potts SEO Helper person metadata -->',
            $is_noindex ? '<meta name="robots" content="noindex,nofollow">' : '',
            '<meta name="description" content="' . $this->h($description) . '">',
            '<meta property="og:site_name" content="' . $this->h($site_name) . '">',
            '<meta property="og:title" content="' . $this->h($title) . '">',
            '<meta property="og:description" content="' . $this->h($description) . '">',
            '<meta property="og:type" content="profile">',
            '<meta property="og:url" content="' . $this->h($canonical) . '">',
            '<meta name="twitter:card" content="summary">',
            '<meta name="twitter:title" content="' . $this->h($title) . '">',
            '<meta name="twitter:description" content="' . $this->h($description) . '">',
            '<link rel="canonical" href="' . $this->h($canonical) . '">',
            $verification !== '' ? '<meta name="google-site-verification" content="' . $this->h($verification) . '">' : '',
            !$is_noindex ? '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>' : '',
            '<!-- /Potts SEO Helper person metadata -->',
        ]);
    }

    private function headContentForSurname(Tree $tree, string $surname): string
    {
        $site_name = $this->pref('site_name', 'Family History');
        $title = $surname . ' Family History | ' . $site_name;
        $description = $this->surnameDescription($surname);
        $canonical = $this->surnameUrl($tree, $surname);
        $verification = $this->pref('google_site_verification', '');

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'CollectionPage',
            'name' => $title,
            'url' => $canonical,
            'description' => $description,
            'isPartOf' => [
                '@type' => 'WebSite',
                'name' => $site_name,
                'url' => $this->siteBaseUrl(),
            ],
        ];

        $is_noindex = $this->seoHelperPageShouldNoindex();

        return $this->tags([
            '<!-- Potts SEO Helper surname metadata -->',
            $is_noindex ? '<meta name="robots" content="noindex,follow">' : '',
            '<meta name="description" content="' . $this->h($description) . '">',
            '<meta property="og:site_name" content="' . $this->h($site_name) . '">',
            '<meta property="og:title" content="' . $this->h($title) . '">',
            '<meta property="og:description" content="' . $this->h($description) . '">',
            '<meta property="og:type" content="website">',
            '<meta property="og:url" content="' . $this->h($canonical) . '">',
            '<meta name="twitter:card" content="summary">',
            '<meta name="twitter:title" content="' . $this->h($title) . '">',
            '<meta name="twitter:description" content="' . $this->h($description) . '">',
            '<link rel="canonical" href="' . $this->h($canonical) . '">',
            $verification !== '' ? '<meta name="google-site-verification" content="' . $this->h($verification) . '">' : '',
            '<script type="application/ld+json">' . json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>',
            '<!-- /Potts SEO Helper surname metadata -->',
        ]);
    }

    private function analyticsHeadContent(): string
    {
        if (!$this->prefBool('enable_google_analytics', false)) {
            return '';
        }

        $measurement_id = $this->googleAnalyticsMeasurementId();
        if ($measurement_id === '' || !$this->analyticsShouldTrackCurrentPage()) {
            return '';
        }

        $measurement_id_json = json_encode($measurement_id, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return $this->tags([
            '<!-- Potts SEO Helper Google Analytics -->',
            '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $this->h($measurement_id) . '"></script>',
            '<script>window.dataLayer = window.dataLayer || []; function gtag(){dataLayer.push(arguments);} gtag("js", new Date()); gtag("config", ' . $measurement_id_json . ', {"anonymize_ip": true});</script>',
            '<!-- /Potts SEO Helper Google Analytics -->',
        ]);
    }

    private function googleAnalyticsMeasurementId(): string
    {
        $raw = strtoupper(trim($this->pref('google_analytics_measurement_id', '')));
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\bG-[A-Z0-9]{4,}\b/', $raw, $match) === 1) {
            return $match[0];
        }

        return '';
    }

    private function analyticsShouldTrackCurrentPage(): bool
    {
        if ($this->prefBool('analytics_exclude_logged_users', true) && $this->currentUserIsLoggedIn()) {
            return false;
        }

        if ($this->prefBool('analytics_exclude_admin_pages', true) && $this->isAdminLikePage()) {
            return false;
        }

        if ($this->prefBool('analytics_respect_private_pages', true) && $this->currentPageLooksPrivate()) {
            return false;
        }

        return true;
    }

    private function currentUserIsLoggedIn(): bool
    {
        try {
            if (method_exists(Auth::class, 'check')) {
                return (bool) Auth::check();
            }

            if (method_exists(Auth::class, 'user')) {
                return Auth::user() !== null;
            }
        } catch (Throwable $ex) {
            return Auth::isAdmin();
        }

        return Auth::isAdmin();
    }

    private function isAdminLikePage(): bool
    {
        $uri = strtolower(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '')));
        $route = strtolower(rawurldecode((string) ($_GET['route'] ?? '')));

        foreach ([$uri, $route] as $value) {
            if ($value === '') {
                continue;
            }

            if (strpos($value, '/admin') !== false || strpos($value, 'control-panel') !== false || strpos($value, 'control_panel') !== false || strpos($value, 'admin_') !== false || strpos($value, '/module') !== false) {
                return true;
            }
        }

        return false;
    }

    private function currentPageLooksPrivate(): bool
    {
        $person_context = $this->currentPersonContext();
        if ($person_context !== null && (string) ($person_context['noindex'] ?? '0') === '1') {
            return true;
        }

        $uri = strtolower(rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? '')));
        $route = strtolower(rawurldecode((string) ($_GET['route'] ?? '')));
        $value = $uri . ' ' . $route;

        if (strpos($value, '/edit') !== false || strpos($value, '/add') !== false || strpos($value, '/delete') !== false || strpos($value, '/note') !== false || strpos($value, '/media') !== false || strpos($value, '/source') !== false || strpos($value, '/repository') !== false) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int,string> $tags
     */
    private function tags(array $tags): string
    {
        return implode("\n", array_values(array_filter($tags))) . "\n";
    }

    private function menuIsVisible(): bool
    {
        $visibility = $this->pref('menu_visibility', 'admin');

        if ($visibility === 'hidden') {
            return false;
        }

        if ($visibility === 'everyone') {
            return true;
        }

        if ($visibility === 'logged_in') {
            try {
                if (method_exists(Auth::class, 'check')) {
                    return (bool) Auth::check();
                }

                if (method_exists(Auth::class, 'user')) {
                    return Auth::user() !== null;
                }
            } catch (Throwable $ex) {
                return Auth::isAdmin();
            }
        }

        return Auth::isAdmin();
    }

    private function seoHelperPageShouldNoindex(): bool
    {
        if ($this->pref('seo_indexing_mode', 'testing') !== 'testing') {
            return false;
        }

        return $this->isSeoHelperRoute();
    }

    private function isSeoHelperRoute(): bool
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (strpos($uri, 'potts-seo-helper') !== false) {
            return true;
        }

        // Fallback for servers that route module requests differently.
        $action = $this->seoActionFromParams($_GET);
        return in_array($action, ['landing', 'surname', 'person', 'sitemap', 'robots', 'health'], true) && strpos($uri, 'module.php') !== false;
    }

    private function mergeHeadContent(string $noindex, string $head_content): string
    {
        if ($noindex === '') {
            return $head_content;
        }

        if (stripos($head_content, 'name="robots"') !== false || stripos($head_content, "name='robots'") !== false || stripos($head_content, 'name=robots') !== false) {
            return $head_content;
        }

        return $noindex . $head_content;
    }

    private function utilityNoindexHeadContent(): string
    {
        if (!$this->prefBool('enable_noindex_rules', true)) {
            return '';
        }

        if (!$this->currentUrlMatchesAnyPattern($this->noindexUrlPatterns())) {
            return '';
        }

        return "<!-- Potts SEO Helper utility noindex -->\n<meta name=\"robots\" content=\"noindex,follow\">\n<!-- /Potts SEO Helper utility noindex -->\n";
    }

    /**
     * @return array<int,string>
     */
    private function robotsDisallowPaths(): array
    {
        return $this->linesPreference('robots_disallow_paths', $this->defaultRobotsDisallowPaths());
    }

    /**
     * @return array<int,string>
     */
    private function noindexUrlPatterns(): array
    {
        return $this->linesPreference('noindex_url_patterns', $this->defaultNoindexUrlPatterns());
    }

    private function defaultRobotsDisallowPaths(): string
    {
        return implode("\n", [
            '/tree/{tree}/calendar/',
            '/tree/{tree}/calendar-events/',
            '/tree/{tree}/search',
            '/tree/{tree}/individual-list',
            '/tree/{tree}/family-list',
            '/tree/{tree}/timeline-',
            '/tree/{tree}/lifespans',
            '/tree/{tree}/media-download',
            '/tree/{tree}/media-thumbnail',
            '/tree/{tree}/tom-select-individual',
            '/tree/{tree}/tree-page-block',
            '/tree/{tree}/potts-narrative-ancestor-book',
            '/tree/{tree}-test/',
            '/login/',
            '/logout',
            '/register/',
            '/my-account/',
            '/admin/',
            '/*?route=/admin',
            '/*?route=/login',
            '/*?route=/register',
            '/*?route=/tree/{tree}/calendar',
            '/*?route=/tree/{tree}/calendar-events',
            '/*?route=/tree/{tree}/search',
            '/*?route=/tree/{tree}/media-download',
            '/*?route=/tree/{tree}/media-thumbnail',
            '/*?route=/tree/{tree}/tree-page-block',
            '/*?route=/tree/{tree}/potts-narrative-ancestor-book',
        ]);
    }

    private function defaultNoindexUrlPatterns(): string
    {
        return implode("\n", [
            '/tree/{tree}/calendar/',
            '/tree/{tree}/calendar-events/',
            '/tree/{tree}/search',
            '/tree/{tree}/individual-list',
            '/tree/{tree}/family-list',
            '/tree/{tree}/timeline-',
            '/tree/{tree}/lifespans',
            '/tree/{tree}/media-download',
            '/tree/{tree}/media-thumbnail',
            '/tree/{tree}/tom-select-individual',
            '/tree/{tree}/tree-page-block',
            '/tree/{tree}/my-page',
            '/tree/{tree}/my-page-edit',
            '/tree/{tree}/potts-narrative-ancestor-book',
            '/tree/{tree}-test/',
            '/login/',
            '/logout',
            '/register/',
            '/my-account/',
            '/admin/',
            '/module/*/Admin',
            'route=/admin',
            'route=/login',
            'route=/register',
            'route=/tree/{tree}/calendar',
            'route=/tree/{tree}/calendar-events',
            'route=/tree/{tree}/search',
            'route=/tree/{tree}/media-download',
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function linesPreference(string $key, string $default): array
    {
        $tree = $this->currentTree() ?? $this->firstAvailableTree();
        $tree_name = $tree instanceof Tree ? $tree->name() : 'default';
        $items = [];

        foreach (preg_split('/\R/', $this->pref($key, $default)) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            $line = str_replace('{tree}', $tree_name, $line);
            $items[] = $line;
        }

        return array_values(array_unique($items));
    }

    /**
     * @return array<int,string>
     */
    private function currentUrlValues(): array
    {
        $uri = rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?: ''));
        $route = rawurldecode((string) ($_GET['route'] ?? ''));
        $query = rawurldecode((string) (parse_url($uri, PHP_URL_QUERY) ?: ''));

        return array_values(array_unique(array_filter([
            $uri,
            $path,
            $route,
            $query,
            ltrim($route, '/'),
        ], static fn (string $value): bool => $value !== '')));
    }

    /**
     * @param array<int,string> $patterns
     */
    private function currentUrlMatchesAnyPattern(array $patterns): bool
    {
        $values = $this->currentUrlValues();

        foreach ($patterns as $pattern) {
            if ($this->urlValuesMatchPattern($values, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int,string> $values
     */
    private function urlValuesMatchPattern(array $values, string $pattern): bool
    {
        $pattern = rawurldecode(trim($pattern));
        if ($pattern === '') {
            return false;
        }

        foreach ($values as $value) {
            if ($value === '') {
                continue;
            }

            if (str_contains($pattern, '*')) {
                $regex = '~' . str_replace('\\*', '.*', preg_quote($pattern, '~')) . '~i';
                if (preg_match($regex, $value) === 1) {
                    return true;
                }
                continue;
            }

            if (stripos($value, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int,string>
     */
    private function additionalSitemapUrls(Tree $tree): array
    {
        $urls = [];
        $tree_token = rawurlencode($tree->name());

        foreach ($this->linesPreference('additional_sitemap_urls', '') as $line) {
            $line = str_replace('{tree}', $tree_token, $line);
            if ($line === '') {
                continue;
            }
            $urls[] = $this->absoluteUrl($line);
        }

        return $urls;
    }


    private function publicSitemapUrl(): string
    {
        return rtrim($this->siteBaseUrl(), '/') . '/sitemap.xml';
    }

    private function publicRobotsUrl(): string
    {
        return rtrim($this->siteBaseUrl(), '/') . '/robots.txt';
    }

    private function renderRobots(Tree $tree): string
    {
        if (!$this->prefBool('enable_robots_protection', true)) {
            return "User-agent: *\n" .
                "Disallow:\n\n" .
                "Sitemap: " . $this->publicSitemapUrl() . "\n";
        }

        $paths = $this->robotsDisallowPaths();
        $lines = [];
        $crawl_delay = (int) $this->pref('robots_bingbot_crawl_delay', '10');

        if ($crawl_delay > 0) {
            $lines[] = 'User-agent: bingbot';
            $lines[] = 'Crawl-delay: ' . min($crawl_delay, 60);
            foreach ($paths as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            $lines[] = '';
        }

        $lines[] = 'User-agent: *';
        foreach ($paths as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $this->publicSitemapUrl();

        return implode("\n", $lines) . "\n";
    }

    private function renderSitemap(Tree $tree): string
    {
        $urls = [];
        $urls[] = $this->siteBaseUrl();
        if ($this->prefBool('use_existing_homepage_as_landing', true)) {
            $urls[] = $this->treeHomeUrl($tree);
        }
        if ($this->prefBool('include_helper_in_sitemap', false)) {
            $urls[] = $this->urlForAction($tree, 'landing');
        }

        foreach ($this->additionalSitemapUrls($tree) as $url) {
            $urls[] = $url;
        }

        if ($this->prefBool('include_surname_pages_in_sitemap', true)) {
            foreach ($this->surnames() as $surname) {
                $urls[] = $this->surnameUrl($tree, $surname);
            }
        }

        if ($this->prefBool('include_people_in_sitemap', false)) {
            foreach ($this->publicIndividualUrls($tree) as $url) {
                $urls[] = $url;
            }
        }

        $urls = array_values(array_unique(array_filter($urls)));

        $xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
        $xml .= "<urlset xmlns=\"http://www.sitemaps.org/schemas/sitemap/0.9\">\n";

        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= "    <loc>" . $this->h($url) . "</loc>\n";
            $xml .= "    <changefreq>weekly</changefreq>\n";
            $xml .= "    <priority>" . $this->sitemapPriority($url, $tree) . "</priority>\n";
            $xml .= "  </url>\n";
        }

        $xml .= "</urlset>\n";

        return $xml;
    }

    /**
     * @return array<string,mixed>
     */
    private function healthSummary(Tree $tree): array
    {
        $featured_count = count($this->featuredPeople($tree));
        $surnames = $this->surnames();
        $places = $this->places();
        $people_sitemap_enabled = $this->prefBool('include_people_in_sitemap', false);
        $indexing_mode = $this->pref('seo_indexing_mode', 'testing');
        $menu_visibility = $this->pref('menu_visibility', 'admin');

        $checks = [];
        $checks[] = [
            'label' => 'Existing homepage as landing page',
            'value' => $this->prefBool('use_existing_homepage_as_landing', true) ? 'enabled' : 'disabled',
            'status' => $this->prefBool('use_existing_homepage_as_landing', true) ? 'ready' : 'testing',
            'note' => $this->prefBool('use_existing_homepage_as_landing', true) ? 'The sitemap and homepage metadata are focused on your existing webtrees homepage.' : 'Enable this if you want the current homepage to be the main public SEO landing page.',
        ];
        $checks[] = [
            'label' => 'Homepage SEO metadata',
            'value' => $this->prefBool('enable_homepage_seo_tags', true) ? 'enabled' : 'disabled',
            'status' => $this->prefBool('enable_homepage_seo_tags', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('enable_homepage_seo_tags', true) ? 'Your homepage gets its own description, canonical link and structured data.' : 'Enable this before going public.',
        ];
        $checks[] = [
            'label' => 'Homepage start block',
            'value' => $this->prefBool('enable_homepage_block', true) ? 'available' : 'disabled',
            'status' => $this->prefBool('enable_homepage_block', true) ? 'ready' : 'testing',
            'note' => $this->prefBool('enable_homepage_block', true) ? 'The optional Family History Start block is available for your existing homepage.' : 'The block is hidden from the webtrees block picker.',
        ];
        $checks[] = [
            'label' => 'Menu visibility',
            'value' => $menu_visibility,
            'status' => $menu_visibility === 'everyone' ? 'ready' : 'testing',
            'note' => $menu_visibility === 'everyone' ? 'Family History is visible to public visitors.' : 'Fine for testing or when the existing homepage is the public entry point.',
        ];
        $checks[] = [
            'label' => 'Indexing mode',
            'value' => $indexing_mode,
            'status' => $indexing_mode === 'public' ? 'ready' : 'testing',
            'note' => $indexing_mode === 'public' ? 'SEO helper pages can be indexed.' : 'Testing mode adds noindex to SEO helper pages.',
        ];
        $checks[] = [
            'label' => 'Head tags',
            'value' => $this->prefBool('enable_head_tags', true) ? 'enabled' : 'disabled',
            'status' => $this->prefBool('enable_head_tags', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('enable_head_tags', true) ? 'Meta descriptions and structured data are enabled.' : 'Enable this before going public.',
        ];
        $checks[] = [
            'label' => 'Individual SEO tags',
            'value' => $this->prefBool('enable_individual_tags', true) ? 'enabled' : 'disabled',
            'status' => $this->prefBool('enable_individual_tags', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('enable_individual_tags', true) ? 'Public deceased individual pages get person-specific metadata.' : 'Individual page metadata is disabled.',
        ];
        $checks[] = [
            'label' => 'Living/private noindex',
            'value' => $this->prefBool('noindex_living_or_private_people', true) ? 'enabled' : 'disabled',
            'status' => $this->prefBool('noindex_living_or_private_people', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('noindex_living_or_private_people', true) ? 'Recommended privacy protection is enabled.' : 'Recommended to turn this on.',
        ];
        $analytics_id = $this->googleAnalyticsMeasurementId();
        $analytics_enabled = $this->prefBool('enable_google_analytics', false) && $analytics_id !== '';
        $checks[] = [
            'label' => 'Google Analytics',
            'value' => $analytics_enabled ? $analytics_id : 'disabled',
            'status' => $analytics_enabled ? 'ready' : 'testing',
            'note' => $analytics_enabled ? 'GA4 tracking is enabled with privacy-friendly exclusions.' : 'Optional. Add a GA4 measurement ID if you want visitor statistics.',
        ];
        $checks[] = [
            'label' => 'Analytics privacy exclusions',
            'value' => $this->prefBool('analytics_exclude_logged_users', true) && $this->prefBool('analytics_exclude_admin_pages', true) && $this->prefBool('analytics_respect_private_pages', true) ? 'enabled' : 'review',
            'status' => $this->prefBool('analytics_exclude_logged_users', true) && $this->prefBool('analytics_exclude_admin_pages', true) && $this->prefBool('analytics_respect_private_pages', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('analytics_exclude_logged_users', true) && $this->prefBool('analytics_exclude_admin_pages', true) && $this->prefBool('analytics_respect_private_pages', true) ? 'Logged-in users, admin pages and private-looking pages are excluded.' : 'Recommended to exclude logged-in users, admin pages and private-looking pages.',
        ];
        $checks[] = [
            'label' => 'Robots protection',
            'value' => $this->prefBool('enable_robots_protection', true) ? count($this->robotsDisallowPaths()) . ' disallow rules' : 'disabled',
            'status' => $this->prefBool('enable_robots_protection', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('enable_robots_protection', true) ? 'Robots output discourages crawling of calendars, search, account, admin and media-download routes.' : 'Recommended while the site is being hit by unwanted crawlers.',
        ];
        foreach ($this->rootFileHealthChecks($tree) as $check) {
            $checks[] = $check;
        }

        $checks[] = [
            'label' => 'Utility-page noindex',
            'value' => $this->prefBool('enable_noindex_rules', true) ? count($this->noindexUrlPatterns()) . ' patterns' : 'disabled',
            'status' => $this->prefBool('enable_noindex_rules', true) ? 'ready' : 'warning',
            'note' => $this->prefBool('enable_noindex_rules', true) ? 'Matching webtrees utility pages get a noindex,follow meta tag.' : 'Recommended for calendars, generated routes, search pages and account/admin pages.',
        ];
        $checks[] = [
            'label' => 'Helper landing in sitemap',
            'value' => $this->prefBool('include_helper_in_sitemap', false) ? 'included' : 'excluded',
            'status' => $this->prefBool('include_helper_in_sitemap', false) ? 'testing' : 'ready',
            'note' => $this->prefBool('include_helper_in_sitemap', false) ? 'Only include this if you want the helper page indexed.' : 'Clean default. Your existing tree homepage remains the public landing page.',
        ];
        $checks[] = [
            'label' => 'Additional sitemap URLs',
            'value' => count($this->additionalSitemapUrls($tree)),
            'status' => 'ready',
            'note' => 'Use this for stable public pages such as family books or FAQ pages.',
        ];
        $checks[] = [
            'label' => 'People in sitemap',
            'value' => $people_sitemap_enabled ? 'enabled' : 'disabled',
            'status' => $people_sitemap_enabled ? 'testing' : 'ready',
            'note' => $people_sitemap_enabled ? 'Check the sitemap carefully before submitting to Google.' : 'Safe default. Enable later after surname pages and privacy checks look right.',
        ];

        return [
            'version' => self::CUSTOM_VERSION,
            'site_name' => $this->pref('site_name', 'Family History'),
            'tree_name' => $tree->name(),
            'configured_surnames' => count($surnames),
            'configured_places' => count($places),
            'featured_people' => $featured_count,
            'sitemap_people_limit' => $this->pref('max_people_in_sitemap', '1000'),
            'people_sitemap_target' => $this->pref('people_sitemap_target', 'seo'),
            'checks' => $checks,
        ];
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function rootFileHealthChecks(Tree $tree): array
    {
        $robots_url = $this->publicRobotsUrl();
        $sitemap_url = $this->publicSitemapUrl();
        $robots = $this->fetchPublicUrl($robots_url);
        $sitemap = $this->fetchPublicUrl($sitemap_url);
        $checks = [];

        $robots_ok = (int) $robots['status_code'] === 200 && (string) $robots['body'] !== '';
        $checks[] = [
            'label' => 'Root robots.txt status',
            'value' => $this->httpProbeValue($robots),
            'status' => $robots_ok ? 'ready' : 'warning',
            'note' => $robots_ok ? 'The public /robots.txt URL is reachable.' : 'Crawlers need a 200 response from /robots.txt. Check the rewrite rules or physical robots.txt file.',
        ];

        $robots_matches = $robots_ok && $this->normaliseText((string) $robots['body']) === $this->normaliseText($this->renderRobots($tree));
        $checks[] = [
            'label' => 'Root robots.txt source',
            'value' => $robots_matches ? 'SEO Helper output' : 'different output',
            'status' => $robots_matches ? 'ready' : 'warning',
            'note' => $robots_matches ? 'The public /robots.txt content matches the SEO Helper robots endpoint.' : 'The public /robots.txt is not the SEO Helper robots output. Use the dynamic fallback rewrite route if webtrees core robots.txt is being served instead.',
        ];

        $sitemap_lines = $this->robotsSitemapLines((string) $robots['body']);
        $advertises_public_sitemap = in_array($sitemap_url, $sitemap_lines, true);
        $checks[] = [
            'label' => 'Root robots.txt Sitemap line',
            'value' => $advertises_public_sitemap ? $sitemap_url : ($sitemap_lines === [] ? 'missing' : implode(', ', $sitemap_lines)),
            'status' => $advertises_public_sitemap ? 'ready' : 'warning',
            'note' => $advertises_public_sitemap ? 'The public robots.txt advertises the public sitemap URL.' : 'Add exactly this line to the robots output: Sitemap: ' . $sitemap_url,
        ];

        $sitemap_ok = (int) $sitemap['status_code'] === 200 && (string) $sitemap['body'] !== '';
        $checks[] = [
            'label' => 'Root sitemap.xml status',
            'value' => $this->httpProbeValue($sitemap),
            'status' => $sitemap_ok ? 'ready' : 'warning',
            'note' => $sitemap_ok ? 'The public /sitemap.xml URL is reachable.' : 'Crawlers need a 200 response from /sitemap.xml. Check the rewrite rules.',
        ];

        $sitemap_matches = $sitemap_ok && $this->normaliseText((string) $sitemap['body']) === $this->normaliseText($this->renderSitemap($tree));
        $checks[] = [
            'label' => 'Root sitemap.xml source',
            'value' => $sitemap_matches ? 'SEO Helper output' : 'different output',
            'status' => $sitemap_matches ? 'ready' : 'warning',
            'note' => $sitemap_matches ? 'The public /sitemap.xml content matches the SEO Helper sitemap endpoint.' : 'The public /sitemap.xml is not the SEO Helper sitemap output.',
        ];

        $sitemap_content_type = strtolower((string) $sitemap['content_type']);
        $sitemap_content_type_ok = $sitemap_content_type !== '' && (strpos($sitemap_content_type, 'xml') !== false);
        $checks[] = [
            'label' => 'Root sitemap.xml content type',
            'value' => $sitemap_content_type !== '' ? $sitemap_content_type : 'unknown',
            'status' => $sitemap_content_type_ok ? 'ready' : 'testing',
            'note' => $sitemap_content_type_ok ? 'The sitemap is being served with an XML content type.' : 'The XML body may still work, but application/xml or text/xml is cleaner.',
        ];

        return $checks;
    }

    /**
     * @return array<string,int|string>
     */
    private function fetchPublicUrl(string $url): array
    {
        $headers = [
            'User-Agent: PottsSEOHelper/' . self::CUSTOM_VERSION,
            'Accept: text/plain, application/xml, text/xml, */*;q=0.5',
        ];

        $context = stream_context_create([
            'http' => [
                'timeout' => 5,
                'ignore_errors' => true,
                'follow_location' => 1,
                'max_redirects' => 5,
                'header' => implode("\r\n", $headers),
            ],
        ]);

        $body = @file_get_contents($url, false, $context);
        $response_headers = isset($http_response_header) && is_array($http_response_header) ? $http_response_header : [];
        $error = is_string($body) ? '' : 'Unable to fetch URL from the server.';

        if (!is_string($body) && function_exists('curl_init')) {
            $curl = curl_init($url);
            if ($curl !== false) {
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_HEADER, true);
                curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
                curl_setopt($curl, CURLOPT_MAXREDIRS, 5);
                curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);
                curl_setopt($curl, CURLOPT_TIMEOUT, 5);
                curl_setopt($curl, CURLOPT_USERAGENT, 'PottsSEOHelper/' . self::CUSTOM_VERSION);
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['Accept: text/plain, application/xml, text/xml, */*;q=0.5']);

                $raw = curl_exec($curl);
                if (is_string($raw)) {
                    $header_size = (int) curl_getinfo($curl, CURLINFO_HEADER_SIZE);
                    $header_text = substr($raw, 0, $header_size);
                    $body_text = substr($raw, $header_size);
                    $response_headers = preg_split('/\r\n|\n|\r/', trim($header_text)) ?: [];
                    $body = $body_text;
                    $error = '';
                } else {
                    $error = curl_error($curl) ?: $error;
                }

                curl_close($curl);
            }
        }

        return [
            'url' => $url,
            'status_code' => $this->httpStatusCode($response_headers),
            'content_type' => $this->httpContentType($response_headers),
            'body' => is_string($body) ? $body : '',
            'error' => $error,
        ];
    }

    /**
     * @param array<int,string> $headers
     */
    private function httpStatusCode(array $headers): int
    {
        $status = 0;

        foreach ($headers as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d{3})\b~i', $header, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }

    /**
     * @param array<int,string> $headers
     */
    private function httpContentType(array $headers): string
    {
        $content_type = '';

        foreach ($headers as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $content_type = trim(substr($header, strlen('Content-Type:')));
            }
        }

        return $content_type;
    }

    /**
     * @param array<string,int|string> $probe
     */
    private function httpProbeValue(array $probe): string
    {
        $status = (int) ($probe['status_code'] ?? 0);
        $content_type = trim((string) ($probe['content_type'] ?? ''));
        $error = trim((string) ($probe['error'] ?? ''));

        if ($status > 0) {
            return 'HTTP ' . $status . ($content_type !== '' ? ' | ' . $content_type : '');
        }

        return $error !== '' ? $error : 'not reachable';
    }

    private function normaliseText(string $text): string
    {
        return trim(str_replace(["\r\n", "\r"], "\n", $text));
    }

    /**
     * @return array<int,string>
     */
    private function robotsSitemapLines(string $robots): array
    {
        $urls = [];

        if (preg_match_all('~^\s*Sitemap:\s*(\S+)\s*$~mi', $robots, $matches) === 1) {
            foreach ($matches[1] as $url) {
                $urls[] = trim((string) $url);
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function surnameHealthReports(Tree $tree): array
    {
        $reports = [];
        $limit = min(max((int) $this->pref('max_people_per_surname_page', '100'), 1), 100);

        foreach ($this->surnames() as $surname) {
            $people = $this->publicPeopleBySurname($tree, $surname, $limit);
            $debug = $this->last_surname_debug;
            $reports[] = [
                'surname' => $surname,
                'url' => $this->surnameUrl($tree, $surname),
                'included' => count($people),
                'debug' => $debug,
            ];
        }

        return $reports;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function featuredPeople(Tree $tree): array
    {
        $people = [];

        foreach ($this->csvPreference('featured_individual_xrefs') as $xref) {
            if (count($people) >= 12) {
                break;
            }

            $individual = $this->individual($tree, $xref);
            if (!$individual instanceof Individual || !$this->isPublicDeceasedIndividual($individual)) {
                continue;
            }

            $context = $this->personContext($individual, $tree);
            $people[] = [
                'name' => $context['name'],
                'years' => $context['years'],
                'description' => $context['description'],
                'url' => $context['canonical'],
                'profile_url' => $context['profile_url'],
            ];
        }

        return $people;
    }

    private function sitemapPriority(string $url, Tree $tree): string
    {
        if ($url === $this->siteBaseUrl() || $url === $this->treeHomeUrl($tree) || $url === $this->urlForAction($tree, 'landing')) {
            return '1.0';
        }

        if (strpos($url, self::ACTION_PARAM . '=surname') !== false || strpos($url, 'action=surname') !== false) {
            return '0.8';
        }

        if (strpos($url, self::ACTION_PARAM . '=person') !== false || strpos($url, 'action=person') !== false || strpos($url, 'individual') !== false) {
            return '0.6';
        }

        return '0.5';
    }

    /**
     * Build a conservative list of visible, deceased individual URLs.
     * This is disabled by default. Enable it only after testing privacy results.
     *
     * @return array<int,string>
     */
    private function publicIndividualUrls(Tree $tree): array
    {
        $urls = [];
        $limit = (int) $this->pref('max_people_in_sitemap', '1000');
        if ($limit < 1) {
            $limit = 1000;
        }

        try {
            $rows = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->select(['i_id'])
                ->orderBy('i_id')
                ->limit($limit)
                ->get();
        } catch (Throwable $ex) {
            return [];
        }

        foreach ($rows as $row) {
            $individual = $this->individual($tree, (string) $row->i_id);

            if (!$individual instanceof Individual || !$this->isPublicDeceasedIndividual($individual)) {
                continue;
            }

            if ($this->pref('people_sitemap_target', 'seo') === 'webtrees' && method_exists($individual, 'url')) {
                $urls[] = $this->absoluteUrl((string) $individual->url());
            } else {
                $urls[] = $this->personUrl($tree, $individual);
            }
        }

        return $urls;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function publicPeopleBySurname(Tree $tree, string $surname, int $limit): array
    {
        $people = [];
        $this->last_surname_debug = [
            'surname' => $surname,
            'database_candidates' => 0,
            'loaded_candidates' => 0,
            'surname_matches' => 0,
            'hidden_or_private' => 0,
            'not_deceased' => 0,
            'included' => 0,
            'mode' => 'fast',
        ];

        if ($limit < 1) {
            $limit = 100;
        }

        $candidate_limit = max($limit * 20, 2000);
        $candidate_xrefs = $this->candidateXrefsForSurname($tree, $surname, $candidate_limit);

        // Some webtrees installs do not store names in individuals.i_name with /surname/ markers.
        // If the quick database lookup finds nothing useful, fall back to scanning visible people in the tree.
        if (count($candidate_xrefs) === 0) {
            $this->last_surname_debug['mode'] = 'scan';
            $candidate_xrefs = $this->candidateXrefsForTree($tree, $candidate_limit);
        }

        $this->last_surname_debug['database_candidates'] = count($candidate_xrefs);

        foreach ($candidate_xrefs as $xref) {
            if (count($people) >= $limit) {
                break;
            }

            $individual = $this->individual($tree, $xref);
            if (!$individual instanceof Individual) {
                continue;
            }

            $this->last_surname_debug['loaded_candidates']++;

            if (!$this->individualMatchesSurname($individual, $surname)) {
                continue;
            }

            $this->last_surname_debug['surname_matches']++;

            $visibility = $this->individualVisibilityStatus($individual);
            if ($visibility === 'hidden') {
                $this->last_surname_debug['hidden_or_private']++;
                continue;
            }

            if ($visibility === 'living') {
                $this->last_surname_debug['not_deceased']++;
                continue;
            }

            $context = $this->personContext($individual, $tree);
            $people[] = [
                'name' => $context['name'],
                'years' => $context['years'],
                'description' => $context['description'],
                'url' => $context['canonical'],
                'birth_place' => $context['birth_place'],
                'death_place' => $context['death_place'],
            ];
            $this->last_surname_debug['included']++;
        }

        usort($people, static function (array $a, array $b): int {
            return strnatcasecmp($a['name'], $b['name']);
        });

        return $people;
    }

    /**
     * @return array<int,string>
     */
    private function candidateXrefsForSurname(Tree $tree, string $surname, int $limit): array
    {
        $surname = trim($surname);
        if ($surname === '') {
            return [];
        }

        $like_plain = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $surname) . '%';
        $like_gedcom = '%/' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $surname) . '/%';

        try {
            $rows = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->where(static function ($query) use ($like_plain, $like_gedcom): void {
                    $query->where('i_name', 'like', $like_plain)
                        ->orWhere('i_gedcom', 'like', $like_gedcom);
                })
                ->select(['i_id'])
                ->orderBy('i_name')
                ->limit($limit)
                ->get();
        } catch (Throwable $ex) {
            return [];
        }

        $xrefs = [];
        foreach ($rows as $row) {
            $xref = trim((string) ($row->i_id ?? ''));
            if ($xref !== '') {
                $xrefs[] = $xref;
            }
        }

        return array_values(array_unique($xrefs));
    }

    /**
     * @return array<int,string>
     */
    private function candidateXrefsForTree(Tree $tree, int $limit): array
    {
        try {
            $rows = DB::table('individuals')
                ->where('i_file', '=', $tree->id())
                ->select(['i_id'])
                ->orderBy('i_id')
                ->limit($limit)
                ->get();
        } catch (Throwable $ex) {
            return [];
        }

        $xrefs = [];
        foreach ($rows as $row) {
            $xref = trim((string) ($row->i_id ?? ''));
            if ($xref !== '') {
                $xrefs[] = $xref;
            }
        }

        return array_values(array_unique($xrefs));
    }

    private function individualMatchesSurname(Individual $individual, string $surname): bool
    {
        $needle = $this->normaliseSurname($surname);
        if ($needle === '') {
            return false;
        }

        $gedcom = $this->gedcom($individual);
        if ($gedcom !== '' && preg_match_all('/^1 NAME\s+(.+)$/m', $gedcom, $matches) > 0) {
            foreach ($matches[1] as $raw_name) {
                if (preg_match_all('~/([^/]+)/~', (string) $raw_name, $surname_matches) > 0) {
                    foreach ($surname_matches[1] as $raw_surname) {
                        if ($this->normaliseSurname((string) $raw_surname) === $needle) {
                            return true;
                        }
                    }
                }

                // Fallback for unusual NAME values without GEDCOM surname slashes.
                $plain_name = $this->normaliseFreeText((string) $raw_name);
                if (preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $plain_name) === 1) {
                    return true;
                }
            }
        }

        try {
            foreach (['surname', 'getSurname', 'sortName', 'fullName', 'name'] as $method) {
                if (method_exists($individual, $method)) {
                    $value = $individual->{$method}();
                    if (is_string($value) && preg_match('/\b' . preg_quote($needle, '/') . '\b/u', $this->normaliseFreeText($value)) === 1) {
                        return true;
                    }
                }
            }
        } catch (Throwable $ex) {
            // Ignore and use the GEDCOM result above.
        }

        return false;
    }

    private function individualVisibilityStatus(Individual $individual): string
    {
        try {
            if (method_exists($individual, 'canShow') && !$individual->canShow()) {
                return 'hidden';
            }

            if (method_exists($individual, 'isDead') && !$individual->isDead()) {
                return 'living';
            }
        } catch (Throwable $ex) {
            return 'hidden';
        }

        return 'public_deceased';
    }

    private function normaliseSurname(string $surname): string
    {
        return $this->normaliseFreeText(str_replace('/', ' ', $surname));
    }

    private function normaliseFreeText(string $value): string
    {
        $value = trim(html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = preg_replace('/[^\pL\pN]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
        return mb_strtolower(trim($value), 'UTF-8');
    }

    private function individual(Tree $tree, string $xref): ?Individual
    {
        $xref = trim($xref);
        if ($xref === '') {
            return null;
        }

        try {
            $individual = Registry::individualFactory()->make($xref, $tree);
        } catch (Throwable $ex) {
            return null;
        }

        return $individual instanceof Individual ? $individual : null;
    }

    private function isPublicDeceasedIndividual(Individual $individual): bool
    {
        try {
            if (method_exists($individual, 'canShow') && !$individual->canShow()) {
                return false;
            }

            if (method_exists($individual, 'isDead') && !$individual->isDead()) {
                return false;
            }
        } catch (Throwable $ex) {
            return false;
        }

        return true;
    }

    /**
     * @return array<string,string>|null
     */
    private function currentPersonContext(): ?array
    {
        $tree = $this->currentTree();
        $xref = $this->currentIndividualXref();

        if (!$tree instanceof Tree || $xref === '') {
            return null;
        }

        $individual = $this->individual($tree, $xref);
        if (!$individual instanceof Individual) {
            return null;
        }

        if (!$this->isPublicDeceasedIndividual($individual)) {
            if ($this->prefBool('noindex_living_or_private_people', true)) {
                return [
                    'title' => 'Private individual | ' . $this->pref('site_name', 'Family History'),
                    'description' => 'This individual page is not available for public indexing.',
                    'canonical' => $this->currentAbsoluteUrl(),
                    'name' => 'Private individual',
                    'years' => '',
                    'birth_date' => '',
                    'death_date' => '',
                    'birth_place' => '',
                    'death_place' => '',
                    'profile_url' => $this->currentAbsoluteUrl(),
                    'noindex' => '1',
                ];
            }

            return null;
        }

        $context = $this->personContext($individual, $tree);

        if ($this->isSeoPersonRoute()) {
            $context['canonical'] = $this->personUrl($tree, $individual);
        } else {
            $context['canonical'] = $this->currentAbsoluteUrl();
        }

        return $context;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentSurnameContext(): ?array
    {
        $params = $_GET;
        if ($this->seoActionFromParams($params) !== 'surname') {
            return null;
        }

        $surname = trim((string) ($params['surname'] ?? ''));
        if ($surname === '') {
            return null;
        }

        $tree = $this->currentTree();
        if (!$tree instanceof Tree) {
            return null;
        }

        return [
            'tree' => $tree,
            'surname' => $surname,
        ];
    }

    /**
     * @return array<string,string>
     */
    private function personContext(Individual $individual, Tree $tree): array
    {
        $name = $this->individualName($individual);
        $birth_date = $this->eventDate($individual, 'BIRT');
        $death_date = $this->eventDate($individual, 'DEAT');
        $birth_place = $this->eventPlace($individual, 'BIRT');
        $death_place = $this->eventPlace($individual, 'DEAT');
        $birth_year = $this->yearFromDate($birth_date);
        $death_year = $this->yearFromDate($death_date);
        $years = $this->years($birth_year, $death_year);
        $site_name = $this->pref('site_name', 'Family History');

        $title = trim($name . ($years !== '' ? ' (' . $years . ')' : '') . ' | ' . $site_name);
        $description = $this->personDescription($name, $birth_date, $birth_place, $death_date, $death_place);
        $profile_url = method_exists($individual, 'url') ? $this->absoluteUrl((string) $individual->url()) : $this->currentAbsoluteUrl();

        return [
            'title' => $title,
            'description' => $description,
            'canonical' => $this->personUrl($tree, $individual),
            'name' => $name,
            'years' => $years,
            'birth_date' => $birth_date,
            'death_date' => $death_date,
            'birth_place' => $birth_place,
            'death_place' => $death_place,
            'profile_url' => $profile_url,
            'noindex' => '0',
        ];
    }

    private function personDescription(string $name, string $birth_date, string $birth_place, string $death_date, string $death_place): string
    {
        $parts = [];

        if ($birth_date !== '' || $birth_place !== '') {
            $text = $name . ' was born';
            if ($birth_date !== '') {
                $text .= ' ' . $birth_date;
            }
            if ($birth_place !== '') {
                $text .= ' in ' . $birth_place;
            }
            $parts[] = $text;
        }

        if ($death_date !== '' || $death_place !== '') {
            $text = 'died';
            if ($death_date !== '') {
                $text .= ' ' . $death_date;
            }
            if ($death_place !== '') {
                $text .= ' in ' . $death_place;
            }
            $parts[] = $text;
        }

        if (count($parts) > 0) {
            return implode(' and ', $parts) . '. View family history, facts, places and source context at ' . $this->pref('site_name', 'Family History') . '.';
        }

        return $name . ' family history profile at ' . $this->pref('site_name', 'Family History') . '.';
    }

    private function surnameDescription(string $surname): string
    {
        return $surname . ' family history and genealogy records from ' . $this->pref('site_name', 'Family History') . ', including public deceased ancestors, places, dates and related family stories.';
    }

    private function individualName(Individual $individual): string
    {
        $name = '';

        try {
            if (method_exists($individual, 'fullName')) {
                $name = (string) $individual->fullName();
            } elseif (method_exists($individual, 'name')) {
                $name = (string) $individual->name();
            }
        } catch (Throwable $ex) {
            $name = '';
        }

        $name = trim(html_entity_decode(strip_tags($name), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($name !== '') {
            return preg_replace('/\s+/', ' ', $name) ?? $name;
        }

        $gedcom = $this->gedcom($individual);
        if (preg_match('/^1 NAME\s+(.+)$/m', $gedcom, $match) === 1) {
            $name = str_replace('/', '', trim($match[1]));
            return preg_replace('/\s+/', ' ', $name) ?? $name;
        }

        return 'Unknown individual';
    }

    private function eventDate(Individual $individual, string $tag): string
    {
        $block = $this->eventBlock($individual, $tag);
        if ($block !== '' && preg_match('/^2 DATE\s+(.+)$/m', $block, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    private function eventPlace(Individual $individual, string $tag): string
    {
        $block = $this->eventBlock($individual, $tag);
        if ($block !== '' && preg_match('/^2 PLAC\s+(.+)$/m', $block, $match) === 1) {
            return trim($match[1]);
        }

        return '';
    }

    private function eventBlock(Individual $individual, string $tag): string
    {
        $gedcom = $this->gedcom($individual);
        if ($gedcom === '') {
            return '';
        }

        if (preg_match('/^1 ' . preg_quote($tag, '/') . '(?:\s.*)?$(.*?)(?=^1\s|\z)/ms', $gedcom, $match) === 1) {
            return $match[0];
        }

        return '';
    }

    private function gedcom(Individual $individual): string
    {
        try {
            if (method_exists($individual, 'gedcom')) {
                return (string) $individual->gedcom();
            }
        } catch (Throwable $ex) {
            return '';
        }

        return '';
    }

    private function yearFromDate(string $date): string
    {
        if (preg_match('/(\d{4})/', $date, $match) === 1) {
            return $match[1];
        }

        return '';
    }

    private function years(string $birth_year, string $death_year): string
    {
        if ($birth_year !== '' && $death_year !== '') {
            return $birth_year . '–' . $death_year;
        }

        if ($birth_year !== '') {
            return $birth_year . '–';
        }

        if ($death_year !== '') {
            return '–' . $death_year;
        }

        return '';
    }

    private function currentTree(): ?Tree
    {
        $tree_name = trim((string) ($_GET[self::TREE_PARAM] ?? $_GET['tree'] ?? $_GET['ged'] ?? ''));

        if ($tree_name === '') {
            $route = rawurldecode(trim((string) ($_GET['route'] ?? '')));
            if (preg_match('~^/tree/([^/?#]+)~', $route, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            }
        }

        if ($tree_name === '') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if (preg_match('~[?&](?:' . preg_quote(self::TREE_PARAM, '~') . '|tree|ged)=([^&#]+)~', $uri, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            } elseif (preg_match('~[?&]route=%2Ftree%2F([^&#]+)~i', $uri, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            } elseif (preg_match('~/tree/([^/?#]+)~', $uri, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            }
        }

        return $this->treeByName($tree_name);
    }

    private function firstAvailableTree(): ?Tree
    {
        $trees = $this->allAvailableTrees();
        foreach ($trees as $tree) {
            if ($tree instanceof Tree) {
                return $tree;
            }
        }

        foreach ([
            ['gedcom', 'gedcom_name', 'gedcom_id'],
            ['tree', 'tree_name', 'tree_id'],
        ] as [$table, $name_column, $order_column]) {
            try {
                $tree_name = DB::table($table)
                    ->where($name_column, '<>', '')
                    ->orderBy($order_column)
                    ->value($name_column);

                $tree = is_string($tree_name) ? $this->treeByName($tree_name) : null;
                if ($tree instanceof Tree) {
                    return $tree;
                }
            } catch (Throwable $ex) {
                // Try the next table name used by different webtrees versions.
            }
        }

        return null;
    }

    /**
     * @return array<int|string,Tree>
     */
    private function allAvailableTrees(): array
    {
        try {
            if (method_exists(Registry::class, 'container')) {
                $container = Registry::container();

                if (is_object($container) && method_exists($container, 'get')) {
                    $tree_service = $container->get(TreeService::class);

                    if ($tree_service instanceof TreeService) {
                        $trees = $tree_service->all();

                        if (is_object($trees) && method_exists($trees, 'all')) {
                            $trees = $trees->all();
                        }

                        if (is_iterable($trees)) {
                            $result = [];
                            foreach ($trees as $key => $tree) {
                                if ($tree instanceof Tree) {
                                    $result[$key] = $tree;
                                }
                            }

                            if ($result !== []) {
                                return $result;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $ex) {
            // Fall back to older factory/database lookup styles below.
        }

        try {
            if (method_exists(Registry::class, 'treeFactory')) {
                $factory = Registry::treeFactory();

                foreach (['all', 'allTrees', 'trees'] as $method) {
                    if (is_object($factory) && method_exists($factory, $method)) {
                        $trees = $factory->{$method}();

                        if (is_object($trees) && method_exists($trees, 'all')) {
                            $trees = $trees->all();
                        }

                        if (is_iterable($trees)) {
                            $result = [];
                            foreach ($trees as $key => $tree) {
                                if ($tree instanceof Tree) {
                                    $result[$key] = $tree;
                                }
                            }

                            if ($result !== []) {
                                return $result;
                            }
                        }
                    }
                }
            }
        } catch (Throwable $ex) {
            // No tree factory on webtrees 2.2; TreeService above is preferred.
        }

        return [];
    }

    private function treeByName(string $tree_name): ?Tree
    {
        $tree_name = trim(rawurldecode($tree_name));
        if ($tree_name === '') {
            return null;
        }

        try {
            if (method_exists(Tree::class, 'findByName')) {
                $tree = Tree::findByName($tree_name);
                if ($tree instanceof Tree) {
                    return $tree;
                }
            }
        } catch (Throwable $ex) {
            // Fall back below.
        }

        foreach ($this->allAvailableTrees() as $key => $tree) {
            if ($tree instanceof Tree && ($tree->name() === $tree_name || (is_string($key) && $key === $tree_name))) {
                return $tree;
            }
        }

        return null;
    }

    private function currentIndividualXref(): string
    {
        $params = $_GET;

        if ($this->seoActionFromParams($params) === 'person' && isset($params['xref'])) {
            return trim((string) $params['xref']);
        }

        if (isset($params['pid'])) {
            return trim((string) $params['pid']);
        }

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');

        if (preg_match('~/individual/([^/?#]+)~', $uri, $match) === 1) {
            return rawurldecode($match[1]);
        }

        return '';
    }

    private function isSeoPersonRoute(): bool
    {
        return $this->seoActionFromParams($_GET) === 'person' && isset($_GET['xref']);
    }

    private function isTreeHomepageRoute(Tree $tree): bool
    {
        $route = rawurldecode(trim((string) ($_GET['route'] ?? '')));
        if ($route === '/tree/' . $tree->name()) {
            return true;
        }

        $uri = rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if (preg_match('~/tree/([^/?#]+)/?$~', parse_url($uri, PHP_URL_PATH) ?: '', $match) === 1) {
            return rawurldecode($match[1]) === $tree->name();
        }

        return strpos($uri, 'route=/tree/' . $tree->name()) !== false || strpos($uri, 'route=%2Ftree%2F' . rawurlencode($tree->name())) !== false;
    }

    /**
     * @return array<int,string>
     */
    private function surnames(): array
    {
        return $this->csvPreference('primary_surnames');
    }

    /**
     * @return array<int,string>
     */
    private function places(): array
    {
        return $this->csvPreference('primary_places');
    }

    /**
     * @return array<int,string>
     */
    private function csvPreference(string $key): array
    {
        $items = [];
        foreach (explode(',', $this->pref($key, '')) as $item) {
            $item = trim($item);
            if ($item !== '') {
                $items[] = $item;
            }
        }

        return $items;
    }

    public function surnameUrl(Tree $tree, string $surname): string
    {
        return $this->moduleActionUrl($tree, 'Surname', ['surname' => $surname]);
    }

    public function personUrl(Tree $tree, Individual $individual): string
    {
        $xref = method_exists($individual, 'xref') ? (string) $individual->xref() : '';

        return $this->moduleActionUrl($tree, 'Person', ['xref' => $xref]);
    }

    private function adminOutputResponse(string $admin_output, Tree $tree): ResponseInterface
    {
        if ($admin_output === 'robots') {
            return $this->plainTextResponse($this->renderRobots($tree));
        }

        if ($admin_output === 'sitemap') {
            return $this->xmlResponse($this->renderSitemap($tree));
        }

        if ($admin_output === 'health') {
            return $this->healthPage($tree);
        }

        if ($admin_output === 'landing') {
            return $this->landingPage($tree);
        }

        return response('Unknown SEO helper preview action.', 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * Admin preview links deliberately reuse the current Admin route. On some
     * hosted webtrees installations, public module-action URLs are rewritten
     * or redirected to the tree/My page before the module can handle them.
     * The Admin action is known to work because it is the page currently being
     * viewed, so these links provide a reliable way to inspect generated output.
     *
     * @return array<string,string>
     */
    private function adminPreviewLinks(ServerRequestInterface $request): array
    {
        $base_url = $this->currentAdminRequestUrl($request);
        $configured_tree_name = trim($this->pref('preview_tree_name', ''));
        $configured_tree = $this->treeByName($configured_tree_name);
        $tree = $configured_tree ?? $this->treeFromActionRequest($request) ?? $this->currentTree() ?? $this->firstAvailableTree();
        $tree_params = [];

        if ($configured_tree_name !== '') {
            // Pass the configured name even if the tree object cannot be resolved
            // while building the admin form. The preview request will resolve it
            // using TreeService, and this makes the generated URL transparent.
            $tree_params[self::TREE_PARAM] = $configured_tree_name;
        } elseif ($tree instanceof Tree) {
            // Admin module URLs do not always carry a webtrees tree context, so
            // the preview buttons pass the tree name explicitly. This keeps the
            // generated robots/sitemap previews on the working Admin route.
            $tree_params[self::TREE_PARAM] = $tree->name();
        }

        return [
            'Helper landing' => $this->appendQueryParams($base_url, array_merge($tree_params, [self::ADMIN_OUTPUT_PARAM => 'landing'])),
            'SEO health check' => $this->appendQueryParams($base_url, array_merge($tree_params, [self::ADMIN_OUTPUT_PARAM => 'health'])),
            'Sitemap' => $this->appendQueryParams($base_url, array_merge($tree_params, [self::ADMIN_OUTPUT_PARAM => 'sitemap'])),
            'Robots' => $this->appendQueryParams($base_url, array_merge($tree_params, [self::ADMIN_OUTPUT_PARAM => 'robots'])),
        ];
    }

    private function currentAdminRequestUrl(ServerRequestInterface $request): string
    {
        $uri = (string) $request->getUri();
        $parts = parse_url($uri);

        if (is_array($parts)) {
            $url = '';

            if (isset($parts['scheme'], $parts['host'])) {
                $url .= $parts['scheme'] . '://';

                if (isset($parts['user'])) {
                    $url .= $parts['user'];

                    if (isset($parts['pass'])) {
                        $url .= ':' . $parts['pass'];
                    }

                    $url .= '@';
                }

                $url .= $parts['host'];

                if (isset($parts['port'])) {
                    $url .= ':' . $parts['port'];
                }
            }

            $url .= $parts['path'] ?? '';

            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);
            unset($query[self::ADMIN_OUTPUT_PARAM]);

            if ($query !== []) {
                $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
            }

            if ($url !== '') {
                return $url;
            }
        }

        
        $tree = $this->currentTree() ?? $this->firstAvailableTree();
        if ($tree instanceof Tree) {
            return $this->moduleActionUrl($tree, 'Admin');
        }

        return $this->absoluteUrl('/module/' . rawurlencode($this->name()) . '/Admin');
    }

    /**
     * @return array<string,string>
     */
    public function adminQuickLinks(): array
    {
        $tree = $this->currentTree() ?? $this->firstAvailableTree();

        if (!$tree instanceof Tree) {
            return $this->fallbackAdminQuickLinks();
        }

        return [
            'Helper landing' => $this->urlForAction($tree, 'landing'),
            'SEO health check' => $this->urlForAction($tree, 'health'),
            'Sitemap' => $this->urlForAction($tree, 'sitemap'),
            'Robots' => $this->urlForAction($tree, 'robots'),
        ];
    }

    /**
     * @return array<string,string>
     */
    private function fallbackAdminQuickLinks(): array
    {
        $tree = $this->firstAvailableTree();
        $tree_name = $tree instanceof Tree ? $tree->name() : 'default';

        return [
            'Helper landing' => $this->fallbackUrlForAction($tree_name, 'landing'),
            'SEO health check' => $this->fallbackUrlForAction($tree_name, 'health'),
            'Sitemap' => $this->fallbackUrlForAction($tree_name, 'sitemap'),
            'Robots' => $this->fallbackUrlForAction($tree_name, 'robots'),
        ];
    }

    private function fallbackUrlForAction(string $tree_name, string $action): string
    {
        $tree = null;

        try {
            if (method_exists(Tree::class, 'findByName')) {
                $tree = Tree::findByName($tree_name);
            }
        } catch (Throwable $ex) {
            $tree = null;
        }

        if ($tree instanceof Tree) {
            return $this->urlForAction($tree, $action);
        }

        $action_name = $this->moduleActionName($action);
        $path = 'index.php?route=' . rawurlencode('/module/' . $this->name() . '/' . $action_name . '/' . $tree_name);

        return $this->absoluteUrl($path);
    }

    private function urlForAction(Tree $tree, string $action): string
    {
        $allowed_actions = ['landing', 'surname', 'person', 'sitemap', 'robots', 'health'];
        $action = in_array($action, $allowed_actions, true) ? $action : 'landing';

        return $this->moduleActionUrl($tree, $this->moduleActionName($action));
    }

    private function moduleActionName(string $action): string
    {
        $map = [
            'landing' => 'Landing',
            'surname' => 'Surname',
            'person' => 'Person',
            'sitemap' => 'Sitemap',
            'robots' => 'Robots',
            'health' => 'Health',
        ];

        return $map[strtolower($action)] ?? 'Landing';
    }

    /**
     * Build a standard webtrees module-action URL.
     *
     * Use the pretty module route directly. On sites with pretty URLs enabled,
     * the older `index.php?route=...` form can be redirected before webtrees has
     * a chance to resolve the module action, and some installations fall back to
     * the tree landing/My page. The explicit `/module/{module}/{Action}/{tree}`
     * path is the native route webtrees registers for module actions.
     *
     * @param array<string,string> $params
     */
    private function moduleActionUrl(Tree $tree, string $action_name, array $params = []): string
    {
        $url = '/module/' . rawurlencode($this->name()) . '/' . rawurlencode($action_name) . '/' . rawurlencode($tree->name());

        if ($params !== []) {
            $url .= '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        }

        return $this->absoluteUrl($url);
    }

    /**
     * Read the module endpoint action from a path segment or query parameter.
     *
     * Current helper links use path-based routes such as
     * `/tree/{tree}/potts-seo-helper/sitemap`. Older helper links used either
     * `?potts_seo_action=sitemap` or `?action=sitemap`, so those are still
     * supported for backwards compatibility.
     *
     * @param array<string,mixed> $params
     */
    private function seoActionFromParams(array $params): string
    {
        $allowed_actions = ['landing', 'surname', 'person', 'sitemap', 'robots', 'health'];
        $action = trim((string) ($params[self::ACTION_PARAM] ?? $params['action'] ?? ''));

        if ($action === '') {
            $route = rawurldecode((string) ($params['route'] ?? ''));

            if (preg_match('~(?:^|/)sitemap\.xml(?:$|[?&#])~i', $route) === 1) {
                $action = 'sitemap';
            } elseif (preg_match('~(?:^|/)robots\.txt(?:$|[?&#])~i', $route) === 1) {
                $action = 'robots';
            } elseif (preg_match('~/potts-seo-helper/(sitemap|robots|health|person|surname)(?:/|$|[?&#])~i', $route, $match) === 1) {
                $action = strtolower($match[1]);
            } elseif (preg_match('~[?&](?:' . preg_quote(self::ACTION_PARAM, '~') . '|action)=([^&#]+)~', $route, $match) === 1) {
                $action = rawurldecode($match[1]);
            }
        }

        if ($action === '') {
            $uri = rawurldecode((string) ($_SERVER['REQUEST_URI'] ?? ''));

            if (preg_match('~(?:^|/)sitemap\.xml(?:$|[?&#])~i', $uri) === 1) {
                $action = 'sitemap';
            } elseif (preg_match('~(?:^|/)robots\.txt(?:$|[?&#])~i', $uri) === 1) {
                $action = 'robots';
            } elseif (preg_match('~/potts-seo-helper/(sitemap|robots|health|person|surname)(?:/|$|[?&#])~i', $uri, $match) === 1) {
                $action = strtolower($match[1]);
            } elseif (preg_match('~[?&](?:' . preg_quote(self::ACTION_PARAM, '~') . '|action)=([^&#]+)~', $uri, $match) === 1) {
                $action = rawurldecode($match[1]);
            }
        }

        return in_array($action, $allowed_actions, true) ? $action : 'landing';
    }

    private function treeFromActionRequest(ServerRequestInterface $request): ?Tree
    {
        $tree = $request->getAttribute('tree');
        if ($tree instanceof Tree) {
            return $tree;
        }

        $tree_name = '';

        foreach (['tree', 'ged', 'tree_name'] as $attribute) {
            $value = $request->getAttribute($attribute);
            if (is_string($value) && trim($value) !== '') {
                $tree_name = trim($value);
                break;
            }
        }

        $params = $request->getQueryParams();
        foreach ([self::TREE_PARAM, 'tree', 'ged'] as $key) {
            if ($tree_name === '' && isset($params[$key])) {
                $tree_name = trim((string) $params[$key]);
            }
        }

        $request_uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
        $route = (string) ($params['route'] ?? '');
        $path = '';

        try {
            $path = (string) $request->getUri()->getPath();
        } catch (Throwable $ex) {
            $path = '';
        }

        foreach ([$route, $path, $request_uri] as $value) {
            if ($tree_name !== '') {
                break;
            }

            $value = rawurldecode($value);

            if (preg_match('~/module/[^/]+/[^/?#]+/([^/?#&]+)~', $value, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
                break;
            }

            if (preg_match('~/tree/([^/?#&]+)~', $value, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
                break;
            }

            if (preg_match('~[?&](?:tree|ged)=([^&#]+)~', $value, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
                break;
            }
        }

        $tree = $this->treeByName($tree_name);
        if ($tree instanceof Tree) {
            return $tree;
        }

        $configured_tree = $this->treeByName($this->pref('preview_tree_name', ''));
        if ($configured_tree instanceof Tree) {
            return $configured_tree;
        }

        return $this->currentTree() ?? $this->firstAvailableTree();
    }

    private function treeNotFoundResponse(): ResponseInterface
    {
        $requested_tree = trim((string) ($_GET[self::TREE_PARAM] ?? $_GET['tree'] ?? $_GET['ged'] ?? ''));
        $configured_tree = $this->pref('preview_tree_name', '');
        $known_trees = [];

        foreach ($this->allAvailableTrees() as $tree) {
            if ($tree instanceof Tree) {
                $known_trees[] = $tree->name();
            }
        }

        $message = "Tree not found for Potts SEO Helper request.
";
        $message .= 'Requested tree: ' . ($requested_tree !== '' ? $requested_tree : '(none)') . "
";
        $message .= 'Configured preview tree: ' . ($configured_tree !== '' ? $configured_tree : '(none)') . "
";
        $message .= 'Known trees: ' . ($known_trees !== [] ? implode(', ', $known_trees) : '(none found)') . "
";

        return response($message, 404, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'X-Robots-Tag' => 'noindex',
        ]);
    }

    /**
     * @param array<string,string> $params
     */
    private function appendQueryParams(string $url, array $params): string
    {
        $separator = strpos($url, '?') === false ? '?' : '&';

        return $url . $separator . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    private function pref(string $key, string $default = ''): string
    {
        $defaults = $this->adminPreferences();
        if ($default === '' && isset($defaults[$key])) {
            $default = $defaults[$key];
        }

        return trim((string) $this->getPreference($key, $default));
    }

    private function prefBool(string $key, bool $default): bool
    {
        $value = $this->pref($key, $default ? '1' : '0');

        return $value === '1' || $value === 'true' || $value === 'yes' || $value === 'on';
    }

    private function treeHomeUrl(Tree $tree): string
    {
        return $this->absoluteUrl('index.php?route=%2Ftree%2F' . rawurlencode($tree->name()));
    }

    private function siteBaseUrl(): string
    {
        $scheme = $this->scheme();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'example.com'));

        return $scheme . '://' . $host . '/';
    }

    private function currentAbsoluteUrl(): string
    {
        $scheme = $this->scheme();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'example.com'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return $scheme . '://' . $host . $uri;
    }

    private function absoluteUrl(string $url): string
    {
        if (preg_match('/^https?:\/\//i', $url) === 1) {
            return $url;
        }

        if (strlen($url) > 0 && $url[0] === '/') {
            return rtrim($this->siteBaseUrl(), '/') . $url;
        }

        return rtrim($this->siteBaseUrl(), '/') . '/' . $url;
    }

    private function scheme(): string
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return 'https';
        }

        if ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
            return 'https';
        }

        return 'http';
    }

    private function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
};
