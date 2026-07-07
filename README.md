# Potts SEO Helper

A small custom webtrees module to help Google and social platforms understand a family-history website.

Version: 0.6.20

Created by Jason Potts.

## Features

- Homepage SEO support for the existing webtrees tree homepage
- Optional Family History Start homepage block
- Public Family History helper landing page
- Public surname landing pages
- Search-friendly public person summary pages
- Site-wide meta description
- Individual-page meta description for visible deceased people
- Open Graph and Twitter preview tags
- JSON-LD structured data for website, homepage, surname pages and person pages
- Sitemap endpoint
- Robots.txt helper endpoint
- Configurable crawler protection rules
- Configurable utility-page noindex rules
- Google Search Console verification token setting
- Optional Google Analytics 4 support
- Privacy-friendly Analytics exclusions for logged-in users, admin pages and private-looking pages
- Conservative privacy checks using webtrees visibility and death status
- Admin-only SEO health check

## New in 0.6.20

- Fixed helper menu, sitemap, robots and health-check links on sites where webtrees pretty URLs are not enabled.
- Generated helper links now use webtrees `index.php?route=` URLs for the registered tree-scoped helper routes instead of direct `/module/...` paths.
- Added a clean maintenance package note for users who have seen module-load errors after partial uploads or mixed-version files.

## New in 0.6.19

- Added root-file health checks that compare public `/robots.txt` and `/sitemap.xml` against the SEO Helper output.
- Added a warning when public `/robots.txt` does not contain a valid `Sitemap:` line pointing to `/sitemap.xml`.
- Switched generated robots and sitemap responses to explicit PSR-7 headers for `text/plain` and `application/xml`.
- Updated the `.htaccess` guidance to prefer the `/potts-seo-helper/*.xml/txt` fallback routes, avoiding webtrees core `/robots.txt` conflicts.
- Kept the Custom Module Manager release guidance current for `v0.6.19`.

## New in 0.6.17

- Added root-style dynamic endpoint support for `/robots.txt` and `/sitemap.xml`.
- Added `/potts-seo-helper/robots.txt` and `/potts-seo-helper/sitemap.xml` fallback endpoints for Apache rewrite rules.
- Robots output now points to the public root sitemap URL: `/sitemap.xml`.
- Changed new-install sitemap defaults to avoid helper module URLs in the sitemap unless intentionally enabled.
- Updated the `.htaccess` example for Option B dynamic root files.

## New in 0.6.16

- Fixed Admin preview links on installations where the module Admin route does not include a tree context.
- The settings-page inspection buttons now pass the selected/default tree name explicitly using `potts_seo_tree`.
- Added stronger fallback logic for finding the first available webtrees tree across different webtrees database/API versions.
- Added an optional “Tree name for SEO output previews” field for hosted setups that do not expose tree context on module Admin routes.

## New in 0.6.14

- Fixed the settings-page inspection buttons by routing them through the known-working Admin action page.
- Added admin preview handling for the helper landing page, SEO health check, sitemap XML and robots.txt output.
- Kept the public module routes as fallbacks, but the settings-page buttons no longer depend on public module-action routes that may be redirected by some hosted setups.

## New in 0.6.11

- Added path-based helper routes such as `/tree/{tree}/potts-seo-helper/sitemap` and `/tree/{tree}/potts-seo-helper/robots`.
- Kept backwards compatibility for older helper links that use `potts_seo_action=sitemap` or `action=sitemap`.

## New in 0.6.10

- Changed helper endpoint links to use the module-specific `potts_seo_action` query parameter instead of the generic `action` parameter.
- Added backwards compatibility for older helper links that still use `action=sitemap`, `action=robots`, `action=health`, `action=surname` or `action=person`.

## New in 0.6.9

- Fixes a settings save error caused by two setting keys that were too long for the webtrees module settings table.

## New in 0.6.8

- Added explicit `customModuleLatestVersion()` support for update managers.
- Generalised default site text so the module is safer for public GitHub use.
- Removed hard-coded PottsNet tree/domain fallbacks from generated URLs.
- Added Custom Module Manager release guidance and configuration snippet.

## New in 0.6.7

- Added configurable robots.txt protection for crawler-heavy webtrees routes
- Added a Bingbot crawl-delay setting
- Added configurable noindex rules for utility/generated pages such as calendars, search, lists, media-downloads and admin/account routes
- Added a cleaner sitemap option so the helper landing page can be excluded from the sitemap
- Added additional sitemap URLs for stable public pages such as Family Books or FAQ pages
- Added health-check warnings for crawler protection, noindex rules and sitemap extras

## New in 0.6.6

- Added optional Google Analytics 4 support using a GA4 Measurement ID
- Added Analytics settings to exclude logged-in users, admin/settings pages and private-looking pages
- Added Analytics checks to the SEO health check
- Kept Analytics disabled by default so the module remains privacy-first

## New in 0.6.5

- Added GitHub-ready release metadata
- Added dynamic robots.txt wording on the helper landing page
- Replaced site-specific rewrite examples with generic tree-name placeholders

## New in 0.6.4

- Added a fallback so settings-page quick links still appear when webtrees does not expose the tree context on the admin route

## New in 0.6.3

- Added a prominent SEO health check link at the top of the module settings page

## New in 0.6.2

- Changed the default helper menu label to SEO
- Added a search-compatible menu class so Potts Modern displays the helper menu with an icon

## New in 0.6.1

- Added admin quick links to the module settings page for the helper landing page, SEO health check, sitemap and robots output

## New in 0.6.0

- Added setting to use the existing tree homepage as the main public SEO landing page
- Added homepage-specific title, description, canonical URL and structured data
- Added optional Family History Start block for the existing homepage
- Added Hidden option for the Family History menu link
- Added homepage and block checks to the SEO health check
- Added the existing tree homepage to the sitemap when homepage landing mode is enabled

## Recommended settings while testing

- Show Family History menu link: Admin only or Hidden
- Allow search engines to index SEO helper pages: No, testing mode
- Use existing tree homepage as public SEO landing page: Yes
- Add homepage SEO metadata: Yes
- Enable Family History Start homepage block: Yes
- Include helper landing page in sitemap: No
- Include surname pages in sitemap: No, unless the public helper routes are confirmed working
- Include deceased public individuals in sitemap: No
- People sitemap target: webtrees, if you later enable people in the sitemap
- Enable robots.txt protection: Yes
- Enable utility-page noindex rules: Yes
- Enable Google Analytics: No, unless you specifically want visitor statistics
- Exclude logged-in users, admin pages and private-looking pages from Analytics: Yes


## Custom Module Manager readiness

This module is packaged for Jefferson49's Custom Module Manager style of installation.

Recommended GitHub release pattern:

- Tag: `v0.6.20`
- Release asset: `potts_seo_helper_v0.6.20.zip`
- ZIP contents: one top-level folder named `potts_seo_helper` containing `module.php`, `resources/`, `README.md`, `CHANGELOG.md`, `latest-version.txt` and `LICENSE`

Suggested Custom Module Manager configuration entry:

```json
"_potts_seo_helper_": {
  "update_service": "GithubModuleUpdate",
  "params": {
    "github_repo": "PottsNet/potts-seo-helper",
    "tag_prefix": "v",
    "category": "admin",
    "title": "Potts SEO Helper",
    "description": "Adds genealogy-focused SEO metadata, sitemap, robots.txt guidance, noindex controls and optional analytics for webtrees."
  }
}
```

The module will only appear in the Custom Module Manager public list after the manager's configuration includes this entry. Until then, users can install it manually from the GitHub release asset.

## Update service note

This release includes `latest-version.txt` and `customModuleLatestVersionUrl()` so webtrees and update managers can display an update service instead of `None`.

## Installation

1. Upload the `potts_seo_helper` folder to `modules_v4`.

If webtrees reports that `ModuleService::load()` returned the wrong type after an update, remove the old `modules_v4/potts_seo_helper` folder completely and upload a fresh copy from the release ZIP. This avoids mixed files from older releases or incomplete FTP uploads.
2. Enable the module in the webtrees control panel.
3. Open the module settings and save once.
4. Open the existing tree homepage and check that it still loads.
5. If wanted, add the Family History Start block to the tree homepage using the webtrees block editor.
6. Open the SEO health check while logged in as admin.
7. Check the sitemap endpoint shown on the health check page.
8. Optional: create a GA4 property, copy the Measurement ID such as `G-XXXXXXXXXX`, then enable Google Analytics in the module settings.

## Privacy note

Leave “Include deceased public individuals in sitemap” turned off until the surname and person pages have been tested.

Google Analytics is disabled by default. If enabled, the recommended settings exclude logged-in users, admin/settings pages and private-looking pages so visitor statistics focus on public content.

The module uses webtrees privacy and death-status checks before generating person summary pages. Living and private records should remain protected by normal webtrees privacy settings.

## Dynamic `robots.txt` and `sitemap.xml` via `.htaccess`

For Option B, route the public root files through webtrees so the module generates them dynamically.

The module registers these endpoint routes:

- `/robots.txt`
- `/sitemap.xml`
- `/potts-seo-helper/robots.txt`
- `/potts-seo-helper/sitemap.xml`

On most Apache/cPanel installs, add rewrite rules near the top of the webtrees root `.htaccess`, before the normal webtrees front-controller rules and before any `-f` / `-d` file checks.

Recommended rewrite rules:

```apache
RewriteEngine On
RewriteRule ^robots\.txt$ index.php?route=/potts-seo-helper/robots.txt [L,QSA]
RewriteRule ^sitemap\.xml$ index.php?route=/potts-seo-helper/sitemap.xml [L,QSA]
```

These fallback routes avoid a conflict where webtrees core may answer `/robots.txt` before the SEO Helper module does.

If your install already routes `/sitemap.xml` correctly through the root route, this also remains supported:

```apache
RewriteEngine On
RewriteRule ^sitemap\.xml$ index.php?route=/sitemap.xml [L,QSA]
```

For `robots.txt`, remove or rename any physical `robots.txt` file if your existing `.htaccess` serves real files before rewriting. After saving the rules, open the SEO health check and confirm that the root robots and sitemap source checks show `SEO Helper output`.

### v0.6.14 quick-link routing note

The settings-page check buttons use the same Admin route that is already displaying the settings page, with a module-specific preview parameter such as `potts_seo_admin_output=robots` or `potts_seo_admin_output=sitemap`. This avoids hosted setups that redirect public module-action URLs back to the tree/My page before the module can handle them.


### v0.6.16 notes

This release fixes admin preview tree detection on webtrees 2.2 by using TreeService and by always passing the configured preview tree name in the inspection links.
