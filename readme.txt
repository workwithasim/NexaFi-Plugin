=== NexaFi Financing Application & Contact Form ===
Contributors: nexafi
Tags: financing, contact form, patient financing, calculator, application
Requires at least: 5.8
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 2.4.16
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

= 2.4.16 =
* Button arrow consistency: updated "See Your Next Steps" button on Screen 4 to use &rarr; in an aria-hidden span matching all other form action buttons while preserving consent gating and navigation.
* Screen 5 return button text: updated final action button to "Return to GDG Smiles &rarr;" with an aria-hidden arrow, keeping existing blue pill styling, size, placement, and tagline card branding.
* Return URL handling: defaulted nexafi_return_url to empty instead of home_url('/'), hiding return buttons when empty, added HTTPS URL validation on save, and added admin notice prompting to configure the homepage URL.
* IP-hash transient rate limiting: added rate limiting to nexafi_lookup_rep (max 20 req/min) and nexafi_submit_application (max 5 submissions/10 min) keyed by SHA-256 IP hash without storing raw IPs.
* Settings pre-launch checklist: added overview checklist box on the NexaFi settings page showing done/not done status for return URL, active representative, terms verified, decision mode, disclosure text, and plan fee.

= 2.4.15 =
* Pending screen headline & styling fix: replaced "YOU'RE APPROVED!" and amber warning icon on pending / unverified screens with "Your Application Has Been Received" and a calm blue info icon; strictly reserved approval headlines and "Congratulations" wording for verified approvals (result === 'approved' AND terms_verified === true) across Screen 4, Screen 5, and applicant emails.
* Age verification enforcement: added server-side and client-side validation requiring applicants to be at least 18 years old and under 120 years old, setting date of birth input max to today minus 18 years, failing safely without logging sensitive data.
* Minimum quote setting: added nexafi_min_quote admin setting (default $0 = no minimum) next to max quote, enforced in submit_application and Screen 3 validation with "Treatment quote must be at least $X."
* Representative seeding & validation: discontinued sample representative seeding on fresh installs, ensured shortcode renders cleanly when no active representative exists, updated representative lookup failure message to "Representative ID not found.", and added admin notice warning when no active representative exists.

= 2.4.14 =
* Fixed upfront plan fee calculation: Required Down Payment includes plan fee without reducing Amount Financed; Financing Charge and Total Installment Payments exclude the upfront fee.
* Separated base_down_payment and plan_fee in calculator return structure, ensuring upfront_amount consistently reflects total required down payment across Screen 4, emails, admin details, and CSV exports.
* Removed static placeholder sample texts and dates from Screen 5 markup, replacing with clean neutral dashes prior to submission data load.
* Removed hardcoded representative fallback names and IDs across shortcode and assets.
* Added monthly payment reconciliation notice on Screen 4 and applicant notification email when remainder cents differs and terms are verified.
* Added administrative guidance and explanations on the NexaFi Settings page for Financing Terms Verification Gate, Decision Mode, and Plan Fee Treatment.

= 2.4.13 =
* Form UX improvements: added autocomplete="bday" to DOB, enforced inputmode="numeric" on currency fields, full state names on mobile with full-width native select, and 16px font-size to prevent iOS Safari auto-zoom.
* Navigation fixes: preserved and restored representative ID on session resume, single session submission token to eliminate duplicate applications, and sessionStorage clearance on pagehide.
* Redesigned Screen 4 approval breakdown with exact labels and order, prominent Required Down Payment featured card, and fail-closed gate requiring verified terms.
* Precise integer-cents financing calculator supporting charge basis selection (financed amount vs treatment price), configurable plan fee, booking deposit credit calculation, and remainder cents reconciliation.
* Hardened consent and terms acceptance: explicit acknowledgment checkbox, per-application 32-char token secret hashing, and strict CSRF protection with nonce refresh.
* Screen 5 "What's Next?" steps updated with booking deposit credit non-promotional notice.
* Removed patient-facing third-party provider branding and added configurable provider disclosures.

= 2.4.12 =
* Removed Declarations & Consent details from email notifications across all eligibility outcomes.
* Cleaned up Assigned Representative card layout in email template.

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
