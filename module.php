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

    private const CUSTOM_VERSION = '0.6.5';
    private const ROUTE_URL = '/tree/{tree}/potts-seo-helper';

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
        Registry::routeFactory()->routeMap()
            ->get(static::class, self::ROUTE_URL, $this);

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
        $url = route(static::class, [
            'tree' => $tree->name(),
        ]);

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
        if (!$this->prefBool('enable_head_tags', true)) {
            return '';
        }

        $person_context = $this->currentPersonContext();

        if ($person_context !== null && $this->prefBool('enable_individual_tags', true)) {
            return $this->headContentForPerson($person_context);
        }

        $surname_context = $this->currentSurnameContext();

        if ($surname_context !== null && $this->prefBool('enable_surname_pages', true)) {
            return $this->headContentForSurname($surname_context['tree'], $surname_context['surname']);
        }

        $tree = $this->currentTree();
        if ($tree instanceof Tree && $this->prefBool('use_existing_homepage_as_landing', true) && $this->prefBool('enable_homepage_seo_tags', true) && $this->isTreeHomepageRoute($tree)) {
            return $this->headContentForHomepage($tree);
        }

        return $this->headContentForSite();
    }

    public function getAdminAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/administration';

        return $this->viewResponse($this->name() . '::edit', [
            'title' => $this->title(),
            'module' => $this,
            'preferences' => $this->adminPreferences(),
            'admin_links' => $this->adminQuickLinks(),
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

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $tree = $request->getAttribute('tree');
        assert($tree instanceof Tree);

        $params = $request->getQueryParams();
        $action = (string) ($params['action'] ?? 'landing');

        if ($action === 'sitemap') {
            return response($this->renderSitemap($tree), 200, [
                'Content-Type' => 'application/xml; charset=UTF-8',
            ]);
        }

        if ($action === 'robots') {
            return response($this->renderRobots($tree), 200, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
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
            'title' => $this->pref('landing_title', 'The Potts Family Tree'),
            'site_name' => $this->pref('site_name', 'The Potts Family Tree'),
            'intro' => $this->pref('landing_intro', 'Welcome to The Potts Family Tree, a family history website researched and maintained by Jason Potts.'),
            'description' => $this->pref('site_description', 'The Potts Family Tree is a family history website covering the Potts, Carr, Madill, Strachan, Toomath, Starritt, Lynas, Bayly, Thurston and related families across Australia, Britain, Ireland, Scotland, Wales and the United States.'),
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
            'site_name' => 'The Potts Family Tree',
            'menu_title' => 'SEO',
            'menu_visibility' => 'admin',
            'seo_indexing_mode' => 'testing',
            'use_existing_homepage_as_landing' => '1',
            'enable_homepage_seo_tags' => '1',
            'enable_homepage_block' => '1',
            'homepage_title' => 'Our Family Tree | The Potts Family Tree and Australian Family History',
            'homepage_description' => 'Our Family is Jason Potts’ family history website, online since 1994, documenting the Potts, Carr, Madill, Strachan, Starritt, Gregg, Bayly, Toomath, Lynas, Thurston and related families through records, stories, books and photographs.',
            'homepage_block_title' => 'Start exploring our family history',
            'homepage_block_intro' => 'Browse the main family names, featured ancestors and public family-history pages.',
            'landing_title' => 'The Potts Family Tree',
            'publisher_name' => 'Jason Potts',
            'site_description' => 'The Potts Family Tree is a family history website covering the Potts, Carr, Madill, Strachan, Toomath, Starritt, Lynas, Bayly, Thurston and related families across Australia, Britain, Ireland, Scotland, Wales and the United States.',
            'landing_intro' => 'Welcome to The Potts Family Tree, a family history website researched and maintained by Jason Potts.',
            'primary_surnames' => 'Potts, Carr, Madill, Strachan, Toomath, Starritt, Gregg, Lynas, Bayly, Thurston, Watson, Ford, Vernon, Wood',
            'primary_places' => 'Victoria, Tasmania, New South Wales, Queensland, England, Scotland, Wales, Ireland, Northern Ireland, United States',
            'featured_individual_xrefs' => 'I1318, I1299, I2835, I1470, I1310',
            'google_site_verification' => '',
            'enable_head_tags' => '1',
            'enable_individual_tags' => '1',
            'enable_surname_pages' => '1',
            'noindex_living_or_private_people' => '1',
            'include_surname_pages_in_sitemap' => '1',
            'include_people_in_sitemap' => '0',
            'people_sitemap_target' => 'seo',
            'max_people_in_sitemap' => '1000',
            'max_people_per_surname_page' => '100',
        ];
    }

    private function headContentForSite(): string
    {
        $site_name = $this->pref('site_name', 'The Potts Family Tree');
        $description = $this->pref('site_description', 'The Potts Family Tree is a family history website covering the Potts, Carr, Madill, Strachan, Toomath, Starritt, Lynas, Bayly, Thurston and related families across Australia, Britain, Ireland, Scotland, Wales and the United States.');
        $canonical = $this->currentAbsoluteUrl();
        $verification = $this->pref('google_site_verification', '');

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site_name,
            'url' => $this->siteBaseUrl(),
            'description' => $description,
            'publisher' => [
                '@type' => 'Person',
                'name' => $this->pref('publisher_name', 'Jason Potts'),
            ],
            'inLanguage' => 'en-AU',
        ];

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
        $site_name = $this->pref('site_name', 'The Potts Family Tree');
        $title = $this->pref('homepage_title', 'Our Family Tree | The Potts Family Tree and Australian Family History');
        $description = $this->pref('homepage_description', 'Our Family is Jason Potts’ family history website, online since 1994, documenting the Potts, Carr, Madill, Strachan, Starritt, Gregg, Bayly, Toomath, Lynas, Thurston and related families through records, stories, books and photographs.');
        $canonical = $this->treeHomeUrl($tree);
        $verification = $this->pref('google_site_verification', '');
        $surnames = $this->surnames();
        $places = $this->places();

        $json = [
            '@context' => 'https://schema.org',
            '@type' => 'WebSite',
            'name' => $site_name,
            'alternateName' => 'Our Family',
            'url' => $canonical,
            'description' => $description,
            'publisher' => [
                '@type' => 'Person',
                'name' => $this->pref('publisher_name', 'Jason Potts'),
            ],
            'about' => array_values(array_map(static function (string $surname): array {
                return [
                    '@type' => 'Thing',
                    'name' => $surname . ' family history',
                ];
            }, $surnames)),
            'keywords' => implode(', ', array_merge($surnames, $places, ['Australian family history', 'genealogy', 'family tree'])),
            'inLanguage' => 'en-AU',
        ];

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
        $site_name = $this->pref('site_name', 'The Potts Family Tree');
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
        $site_name = $this->pref('site_name', 'The Potts Family Tree');
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
        $action = (string) ($_GET['action'] ?? '');
        return in_array($action, ['landing', 'surname', 'person', 'sitemap', 'robots', 'health'], true) && strpos($uri, 'module.php') !== false;
    }

    private function renderRobots(Tree $tree): string
    {
        return "User-agent: *\n" .
            "Disallow:\n\n" .
            "Sitemap: " . $this->urlForAction($tree, 'sitemap') . "\n";
    }

    private function renderSitemap(Tree $tree): string
    {
        $urls = [];
        $urls[] = $this->siteBaseUrl();
        if ($this->prefBool('use_existing_homepage_as_landing', true)) {
            $urls[] = $this->treeHomeUrl($tree);
        }
        $urls[] = $this->urlForAction($tree, 'landing');

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
        $checks[] = [
            'label' => 'People in sitemap',
            'value' => $people_sitemap_enabled ? 'enabled' : 'disabled',
            'status' => $people_sitemap_enabled ? 'testing' : 'ready',
            'note' => $people_sitemap_enabled ? 'Check the sitemap carefully before submitting to Google.' : 'Safe default. Enable later after surname pages and privacy checks look right.',
        ];

        return [
            'version' => self::CUSTOM_VERSION,
            'site_name' => $this->pref('site_name', 'The Potts Family Tree'),
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

        if (strpos($url, 'action=surname') !== false) {
            return '0.8';
        }

        if (strpos($url, 'action=person') !== false || strpos($url, 'individual') !== false) {
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
                    'title' => 'Private individual | ' . $this->pref('site_name', 'The Potts Family Tree'),
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
        if ((string) ($params['action'] ?? '') !== 'surname') {
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
        $site_name = $this->pref('site_name', 'The Potts Family Tree');

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
            return implode(' and ', $parts) . '. View family history, facts, places and source context at ' . $this->pref('site_name', 'The Potts Family Tree') . '.';
        }

        return $name . ' family history profile at ' . $this->pref('site_name', 'The Potts Family Tree') . '.';
    }

    private function surnameDescription(string $surname): string
    {
        return $surname . ' family history and genealogy records from ' . $this->pref('site_name', 'The Potts Family Tree') . ', including public deceased ancestors, places, dates and related family stories.';
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
        $tree_name = trim((string) ($_GET['ged'] ?? ''));

        if ($tree_name === '') {
            $route = rawurldecode(trim((string) ($_GET['route'] ?? '')));
            if (preg_match('~^/tree/([^/?#]+)~', $route, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            }
        }

        if ($tree_name === '') {
            $uri = (string) ($_SERVER['REQUEST_URI'] ?? '');
            if (preg_match('~[?&]route=%2Ftree%2F([^&#]+)~i', $uri, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            } elseif (preg_match('~/tree/([^/?#]+)~', $uri, $match) === 1) {
                $tree_name = rawurldecode($match[1]);
            }
        }

        if ($tree_name === '') {
            return null;
        }

        try {
            if (method_exists(Tree::class, 'findByName')) {
                $tree = Tree::findByName($tree_name);
                return $tree instanceof Tree ? $tree : null;
            }
        } catch (Throwable $ex) {
            return null;
        }

        return null;
    }

    private function firstAvailableTree(): ?Tree
    {
        try {
            $tree_name = DB::table('tree')
                ->orderBy('tree_id')
                ->value('tree_name');

            if (is_string($tree_name) && $tree_name !== '' && method_exists(Tree::class, 'findByName')) {
                $tree = Tree::findByName($tree_name);
                return $tree instanceof Tree ? $tree : null;
            }
        } catch (Throwable $ex) {
            // Fall back below.
        }

        try {
            if (method_exists(Tree::class, 'findByName')) {
                $tree = Tree::findByName('OurFamily');
                return $tree instanceof Tree ? $tree : null;
            }
        } catch (Throwable $ex) {
            return null;
        }

        return null;
    }

    private function currentIndividualXref(): string
    {
        $params = $_GET;

        if ((string) ($params['action'] ?? '') === 'person' && isset($params['xref'])) {
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
        return (string) ($_GET['action'] ?? '') === 'person' && isset($_GET['xref']);
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
        $url = route(static::class, [
            'tree' => $tree->name(),
        ]);

        $url .= (strpos($url, '?') === false ? '?' : '&') . 'action=surname&surname=' . rawurlencode($surname);

        return $this->absoluteUrl($url);
    }

    public function personUrl(Tree $tree, Individual $individual): string
    {
        $xref = method_exists($individual, 'xref') ? (string) $individual->xref() : '';
        $url = route(static::class, [
            'tree' => $tree->name(),
        ]);

        $url .= (strpos($url, '?') === false ? '?' : '&') . 'action=person&xref=' . rawurlencode($xref);

        return $this->absoluteUrl($url);
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
        $tree_name = 'OurFamily';

        return [
            'Helper landing' => $this->fallbackUrlForAction($tree_name, 'landing'),
            'SEO health check' => $this->fallbackUrlForAction($tree_name, 'health'),
            'Sitemap' => $this->fallbackUrlForAction($tree_name, 'sitemap'),
            'Robots' => $this->fallbackUrlForAction($tree_name, 'robots'),
        ];
    }

    private function fallbackUrlForAction(string $tree_name, string $action): string
    {
        $url = '/tree/' . rawurlencode($tree_name) . '/potts-seo-helper';

        if ($action !== 'landing') {
            $url .= '?action=' . rawurlencode($action);
        }

        return $this->absoluteUrl($url);
    }

    private function urlForAction(Tree $tree, string $action): string
    {
        $url = route(static::class, [
            'tree' => $tree->name(),
        ]);

        if ($action !== 'landing') {
            $url .= (strpos($url, '?') === false ? '?' : '&') . 'action=' . $action;
        }

        return $this->absoluteUrl($url);
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
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');

        return $scheme . '://' . $host . '/';
    }

    private function currentAbsoluteUrl(): string
    {
        $scheme = $this->scheme();
        $host = (string) ($_SERVER['HTTP_HOST'] ?? 'localhost');
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
