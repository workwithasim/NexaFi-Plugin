=== NexaFi Financing Application & Contact Form ===
Contributors: nexafi
Tags: financing, contact form, patient financing, calculator, application
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.11
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Patient financing workflow, eligibility engine, representative management, and admin dashboard for NexaFi / GDG Smiles.

== Description ==

NexaFi Financing Application & Contact Form provides an advanced multi-step financing application form, representative routing, dynamic calculator, and full administration dashboard for dental and medical practices.

== Installation ==

1. Upload the plugin files to the `/wp-content/plugins/contact-form` directory, or install the plugin through the WordPress plugins screen directly.
2. Activate the plugin through the 'Plugins' screen in WordPress.
3. Place `[nexafi_financing_form]` shortcode on any page to render the application.

== Frequently Asked Questions ==

= How do I display the application form? =
Use the shortcode `[nexafi_financing_form]` on your desired page.

== Changelog ==

= 2.4.11 =
* Fixed auto-updater package extraction: implemented content-based package detection, multi-strategy atomic renaming (direct rename, WP_Filesystem, copy_dir fallback), and safe error abort preventing "files not found" deactivation.
* Switched tag archive downloads to direct GitHub archive URLs to eliminate unauthenticated API rate limits.

= 2.4.10 =
* Added setting in NexaFi Settings to enable or disable admin notification emails upon new application submission.
* Added custom admin recipient email field with fallback to default WordPress administrator email.
* Integrated dynamic UI toggle for admin recipient email input in settings dashboard.

= 2.4.9 =
* Transformed admin notification email into a comprehensive, beautifully styled HTML template including full applicant profile, address, employment, financing calculations, and representative details.

= 2.4.8 =
* Polished representative modal internal notes: full-width responsive layout, modern typography, and clean helper hints.

= 2.4.7 =
* Updated text domain to nexafi-plugin across all files and translation functions.

= 2.4.6 =
* Fixed admin CSS syntax error restoring full dashboard styles (cards, topnav, tables, and buttons).
* Fixed representative photo sizing in table with strict dimensions to prevent image explosion.
* Enhanced auto-updater to dynamically detect any folder installation name.

= 2.4.5 =
* Unified button styles and updated author/repo URIs.

= 2.4.4 =
* Refactored dashboard tables with predefined responsive classes and polished modern UI.
* Added custom styled table scrollbars and refined email logs and reports layout.
* Cleaned up representative modal and admin navigation headers.

= 2.4.3 =
* Plugin Check compatibility improvements and security hardening.
