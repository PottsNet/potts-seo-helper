# Potts SEO Helper

A small custom webtrees module to help Google and social platforms understand a family-history website.

Version: 0.6.5

Created by Jason Potts with AI-assisted development.

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
- Google Search Console verification token setting
- Conservative privacy checks using webtrees visibility and death status
- Admin-only SEO health check

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
- Include deceased public individuals in sitemap: No

## Installation

1. Upload the `potts_seo_helper` folder to `modules_v4`.
2. Enable the module in the webtrees control panel.
3. Open the module settings and save once.
4. Open the existing tree homepage and check that it still loads.
5. If wanted, add the Family History Start block to the tree homepage using the webtrees block editor.
6. Open the SEO health check while logged in as admin.
7. Check the sitemap endpoint shown on the health check page.

## Compatibility

This module was built for webtrees 2.2.x and PHP 8.x. It was first tested on a private family-history site using webtrees 2.2.6.

The default wording and example surnames reflect the Potts family-history site. Before making the helper pages indexable on another site, update the module settings for your own site name, publisher, surnames, places and featured individuals.

## Privacy note

Leave “Include deceased public individuals in sitemap” turned off until the surname and person pages have been tested.

The module uses webtrees privacy and death-status checks before generating person summary pages. Living and private records should remain protected by normal webtrees privacy settings.

## Robots.txt

The Family History helper page and SEO health check show suggested robots.txt content to copy to the site root if required.

If you use the optional `.htaccess` rewrite examples, replace `YourTreeName` with the exact tree name from your own webtrees URL.
