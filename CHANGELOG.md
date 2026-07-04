# Changelog

## v0.6.18

- Added root-file health checks for public `/robots.txt` and `/sitemap.xml`.
- Added a health warning when the public robots file is not the SEO Helper output.
- Added a health warning when public robots.txt does not advertise `Sitemap: https://your-site/sitemap.xml`.
- Changed generated robots and sitemap responses to explicit PSR-7 content-type headers.
- Updated `.htaccess` guidance to prefer `/potts-seo-helper/robots.txt` and `/potts-seo-helper/sitemap.xml` fallback routes so webtrees core `/robots.txt` does not take over.
- Updated Custom Module Manager release guidance to `v0.6.18`.

## v0.6.17

- Added dynamic root endpoint support for `/robots.txt` and `/sitemap.xml`.
- Added fallback rewrite endpoints at `/potts-seo-helper/robots.txt` and `/potts-seo-helper/sitemap.xml`.
- Updated generated robots output so it advertises the root sitemap URL.
- Made new sitemap defaults safer by avoiding helper module person/surname URLs unless enabled intentionally.
- Updated the Apache `.htaccess` example for Option B.

## v0.6.16

- Fixed admin preview links so they always pass the configured tree name.
- Updated tree resolution for webtrees 2.2 by using the TreeService from the webtrees container.
- Added clearer diagnostic text if a tree still cannot be resolved for preview output.


## 0.6.15 - 2026-07-03

- Fixed Admin preview links on installations where the module Admin route does not include a tree context.
- The settings-page inspection buttons now pass the tree name explicitly using `potts_seo_tree`.
- Added stronger first-tree fallback detection for different webtrees database/API versions.
- Added an optional preview tree-name setting for hosted setups that do not expose tree context on module Admin routes.

## 0.6.14 - 2026-07-03

- Changed the settings-page inspection buttons to use the known-working Admin action route.
- Added admin preview handling for helper landing, health check, sitemap and robots output.
- This avoids hosted webtrees setups that redirect public module-action URLs back to the tree/My page before the module can handle them.

## 0.6.11 - 2026-07-03

- Fixed sitemap, robots and health-check quick links again by adding path-based helper routes such as `/tree/{tree}/potts-seo-helper/sitemap` and `/tree/{tree}/potts-seo-helper/robots`.
- Kept support for the older `potts_seo_action` and `action` query-string links as fallbacks.

## 0.6.10 - 2026-07-03

- Fixed sitemap and robots quick links by switching helper endpoints to the module-specific `potts_seo_action` query parameter.
- Kept backwards compatibility for older helper links that use the generic `action` parameter.

## 0.6.9 - 2026-07-03

- Fixed a database save error in webtrees where two setting keys exceeded the `wt_module_setting.setting_name` length limit.
- Shortened the Analytics logged-in-user setting key and helper sitemap setting key while preserving the same behaviour.

## 0.6.8 - 2026-07-03

- Added explicit `customModuleLatestVersion()` support for update managers.
- Generalised default site text so public installs do not inherit PottsNet-specific wording.
- Removed hard-coded PottsNet tree/domain fallbacks from generated URLs and quick links.
- Added Custom Module Manager release guidance and configuration snippet to the README.

## 0.6.7 - 2026-07-03

- Added configurable robots.txt protection for crawler-heavy webtrees routes.
- Added optional Bingbot crawl delay in robots output.
- Added configurable noindex rules for utility/generated routes.
- Added a cleaner sitemap option to exclude the helper landing page by default.
- Added additional sitemap URLs for stable public pages such as Family Books.
- Added health-check rows for crawler protection and noindex configuration.

## 0.6.6 - 2026-06-29

- Added optional Google Analytics 4 support using a GA4 Measurement ID.
- Added Analytics settings to exclude logged-in users, admin/settings pages and private-looking pages.
- Added Analytics checks to the SEO health check.
- Kept Analytics disabled by default so the module remains privacy-first.

## 0.6.5 - 2026-06-28

- Prepared the module for GitHub sharing.
- Added MIT licence information.
- Updated the support URL to the GitHub repository.
- Changed the helper landing page robots.txt instruction to use the current site URL.
- Replaced site-specific `.htaccess` rewrite examples with generic placeholders.
- Added README compatibility and customisation notes.

## 0.6.4 - 2026-06-27

- Added a fallback so settings-page quick links still appear when webtrees does not expose the tree context on the admin route.

## 0.6.3 - 2026-06-27

- Added a prominent SEO health check link at the top of the module settings page.

## 0.6.2 - 2026-06-27

- Changed the default helper menu label to SEO.
- Added a search-compatible menu class so Potts Modern displays the helper menu with an icon.

## 0.6.1 - 2026-06-27

- Added admin quick links to the module settings page for the helper landing page, SEO health check, sitemap and robots output.

## 0.6.0 - 2026-06-27

- Added support for using the existing tree homepage as the main public SEO landing page.
- Added homepage-specific title, description, canonical URL and structured data.
- Added optional Family History Start block for the existing homepage.
- Added Hidden option for the Family History menu link.
- Added homepage and block checks to the SEO health check.
- Added the existing tree homepage to the sitemap when homepage landing mode is enabled.
