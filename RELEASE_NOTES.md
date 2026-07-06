## Potts SEO Helper v0.6.20

Maintenance release for early public GitHub issue feedback.

### Fixed

- Fixed generated helper links on sites where webtrees pretty URLs are not enabled.
- Changed menu, homepage block, SEO health, sitemap, robots and public helper links to use webtrees `index.php?route=` URLs for the registered tree-scoped helper routes.
- Updated the fallback Admin URL so it does not rely on a direct `/module/...` path.

### Notes

If webtrees reports that `ModuleService::load()` returned the wrong type after updating, remove the old `modules_v4/potts_seo_helper` folder completely and upload a fresh copy from this release ZIP. This avoids mixed files from older versions or incomplete FTP uploads.

Install the attached release ZIP asset named `potts_seo_helper_v0.6.20.zip`. Do not use GitHub’s automatic source-code ZIP for installation.
