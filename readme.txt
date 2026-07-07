=== Blitz & Donner Forms ===
Contributors: blitzdonner
Tags: forms, block, contact form, spam protection, submissions
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.8.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Block-native forms for the WordPress editor. Build forms like any other content and manage submissions in the backend.

== Description ==

Blitz & Donner Forms brings forms into the block editor – no shortcodes, no separate form builder screen. You compose a form out of field blocks exactly the way you write any other content, and core blocks are allowed inside the form.

**Features**

* Form block and field blocks: text, textarea, email, phone, number, URL, date/time, select, radio, checkbox, hidden, range, simple file upload
* Submissions stored in your WordPress database, with list and detail view in the backend
* Server-side validation, honeypot, nonce protection and rate limiting as standard – the full defence chain is part of the free plugin
* Optional spam protection with Friendly Captcha (privacy-friendly, no cookies, no tracking, proof-of-work, processed in the EU)
* One email notification per submission with field placeholders
* Local draft saving with a single switch: visitors' entries are kept in their browser, restored automatically and expire after 7 days
* Per-field help text: set it in the block sidebar and it renders like a caption below the field; screen readers announce it via aria-describedby
* CSV export and per-submission ZIP download
* Appearance system: light/dark/auto/theme, colours and gradients
* Per-field colour overrides in the Styles tab (label, input text, placeholder, background, border, focus – light and dark; button colours on the submit block); empty values inherit from the form
* Own capability model: decide per role who may view, delete or export submissions
* Translation-ready (German, English, French, Italian)

**Extensible by design**

The plugin exposes a documented set of hooks (`bdfrms_submit_chain`, `bdfrms_store_submission_payload`, `bdfrms_store_file`, `bdfrms_settings_cards`, `bdfrms_capabilities` and more) so that add-ons can extend the defence chain, storage and backend without patching the plugin. See `docs/hooks.md` in the plugin folder.

== External services ==

This plugin can optionally connect to **Friendly Captcha** (Friendly Captcha GmbH, Germany) to protect forms against spam. This only happens if you enable Friendly Captcha in the settings and enter your own API keys.

* What is sent: the captcha response token generated in the visitor's browser and your configured site key. No form content is transmitted.
* When: on form submission, to verify the captcha solution server-side.
* Endpoint: `https://global.frcapi.com/api/v2/captcha/siteverify`. The widget script is loaded from the jsDelivr CDN after the first form interaction.
* Provider information: [Friendly Captcha website](https://friendlycaptcha.com/) – [Privacy policy](https://friendlycaptcha.com/legal/privacy-end-users/) – [Terms](https://friendlycaptcha.com/legal/terms/)

If Friendly Captcha stays disabled, the plugin makes no external requests.

== Installation ==

1. Install and activate the plugin.
2. Add the «Form» block to any page or post and compose your fields.
3. Optional: enable Friendly Captcha under Forms → Settings with your own keys.

== Frequently Asked Questions ==

= Do I need an account with an external service? =

No. All core features work without any external service. Friendly Captcha is optional and requires your own (free or paid) Friendly Captcha account.

= Where are submissions stored? =

In your own WordPress database, in dedicated tables. Nothing leaves your server.

= Is this plugin GDPR/DSG friendly? =

Submissions stay on your server. The optional Friendly Captcha service is operated from the EU, sets no cookies and does no tracking. The settings page provides a copyable privacy text snippet.

== Changelog ==

= 0.8.4 =
* Added: the spam protection card now explains the built-in, always-on defences in plain language (bot trap, time lock, rate brake, server-side checks) and positions Friendly Captcha as the optional extra layer. Translated into English, French and Italian.

= 0.8.3 =
* Changed: the extension product panels now use the exact design tokens of plugins.blitzdonner.ch (card and surface colours, lime accents with original alpha values, pill radii, typography scale) instead of an approximated gradient look.

= 0.8.2 =
* Changed: installed add-ons now present themselves as product panels in the extensions card – title, version, licence status, value proposition, feature chips, licence rationale and the token field in one polished block (extension registry gains description, features, license_note and url).

= 0.8.1 =
* Changed: the extensions card is now the single home for installed add-ons – name, version, licence status and licence token in one place (new bdfrms_extensions filter). Add-ons no longer register separate licence cards, and the card badge counts actual add-ons instead of settings cards.

= 0.8.0 =
* Changed: the submissions screen now ships the full backend from the predecessor plugin – field-value columns with labels, form filter, search, sorting, pagination, a proper detail view, CSV export per form and ZIP exports (per form and per submission) including attachments.
* Added: extension hooks for the new backend – bdfrms_can_decrypt, bdfrms_can_download_files and bdfrms_zip_file_contents; bdfrms_security_event now carries target type/id so add-ons can audit backend actions.

= 0.7.3 =
* Changed: the radio layout toolbar buttons are now labelled "Vertical"/"Horizontal" for clarity.

= 0.7.2 =
* Fixed: file downloads from the submissions screen worked only over an outdated action name; the download endpoint is now registered as `bdfrms_download` and matches the links.

= 0.7.1 =
* Changed: the feedback block no longer appears in the global block inserter; it is limited to the form block (parent) and can only be re-inserted inside a form.

= 0.7.0 =
* Added: interim self-hosted auto-updates from plugins.blitzdonner.ch until the WordPress.org listing is approved (public mode, Ed25519-signed packages, no licence token). The WordPress.org build ships without the update client.

= 0.6.10 =
* Changed: every new form includes a feedback area at its end (the hard-to-find feedback block from the inserter). It only takes effect when it has content; left empty, the standard success message is shown.

= 0.6.9 =
* Changed: the admin menu item is now called "BD Forms" (with "Submissions" as first submenu entry) so the plugin is recognisable in the dashboard.
* Changed: the Friendly Captcha key guide collapses like the privacy text snippets.

= 0.6.8 =
* Added: the spam protection card now includes a step-by-step guide for obtaining the Friendly Captcha site key and API key (dashboard, application, API keys page), plus per-field hints.

= 0.6.7 =
* Fixed: the settings cards rendered without their card styling – the admin stylesheet variables are scoped to the bdfrms-admin wrapper class, which the settings page was missing.

= 0.6.6 =
* Changed: the settings page now uses the card cockpit from the predecessor plugin – every card collapsed with a status tag in its title row (spam protection: active/incomplete/off; extensions: add-on count). Add-on cards can provide their own status callable.

= 0.6.5 =
* Changed: the permissions card moved to the end of the settings page and is collapsed by default; its description now explains the main use case. Settings cards support a collapsed flag.

= 0.6.4 =
* Fixed: per-field colour overrides had no effect on the frontend in the light appearance mode. A */ inside a CSS comment ended the comment early and made the browser drop the light-mode mapping rule.

= 0.6.3 =
* Fixed: two doc comments were detached from their functions by recent insertions, failing the coding-standards check in CI.

= 0.6.2 =
* Added: font size settings for labels and help text (form Styles tab, plus theme.json tokens label-size and help-size).
* Fixed: the help text colour introduced in 0.6.1 was accidentally applied to labels instead of the help text.

= 0.6.1 =
* Added: dedicated colour for the field help text (form level, per-field override and theme.json token help), defaulting to the label colour as before.

= 0.6.0 =
* Added: site-wide form colours via theme.json (settings.custom.bdfrms.*). Cascade: field block, form block, theme.json, built-in default.
* Added: block examples – the form and all field blocks now preview in the Site Editor Style Book.

= 0.5.0 =
* Added: per-field colour overrides in the Styles tab of every field block (label, input text, placeholder, field background, border, focus – each for light and dark). The submit block gets button background, button text and focus. Empty values inherit the form colours; the theme colour mode keeps fields theme-styled.

= 0.4.1 =
* Changed: the appearance panel (colour mode and form colours) moved from the settings tab to the Styles tab of the form block, following the editor convention.

= 0.4.0 =
* Changed: choosing an email field as sender now sets it as Reply-To; the technical From always stays a site address, so notifications no longer fail SPF/DKIM/DMARC checks.
* Changed: radio option layout (stacked/inline) moved from the sidebar to the block toolbar.
* Added: fields without a label get an aria-label from their placeholder; the technical field name already derives from the placeholder in that case.
* Changed: placeholder text defaults to the same colour as labels and help text (custom placeholder colours still win).
* Added: required fields without a label show the required marker floating at the right edge of the input, in the editor and on the frontend.
* Changed: allowed upload types are now picked from predefined groups (images, PDF, Word, spreadsheets, presentations, text, ZIP) matching the server allowlist, instead of a free-text accept string.
* Changed: the file field hint is honest in the free base (size limit only); the protection wording returns when the Security add-on is active. The editor shows a gentle warning about risky file uploads.

= 0.3.6 =
* Changed: CSV export columns now use the field labels (from the per-submission label snapshot) instead of technical field names. Identical labels are disambiguated with the technical name in brackets. The backend detail view already showed labels.

= 0.3.5 =
* Changed: technical field names are now fully managed by the plugin. They are derived from the label, kept unique per form (also when duplicating fields, forms or patterns) and stay stable once assigned. The name is shown read-only under "Advanced" as a reference for CSV columns and the {{fieldname}} placeholder.

= 0.3.4 =
* Improved: the field help text is indented by the field's border radius and sits closer to the input.

= 0.3.3 =
* Improved: the help text now shows inside the field block on the editor canvas exactly as on the frontend (display only; editing stays in the sidebar), so layouts can be judged in the editor.

= 0.3.2 =
* Changed: the help text is now edited in the block sidebar. The on-canvas caption input (added in 0.3.0) lost focus while typing because it sat outside the block wrapper; the sidebar control is reliable.

= 0.3.1 =
* Fixed: the help text input disappeared when clicking into it (its visibility was tied to block selection, but the input sits outside the block wrapper).

= 0.3.0 =
* New: per-field help text. Add it from the field block's toolbar – it behaves like an image caption and renders below the field, linked for screen readers via aria-describedby.
* Fixed: aria attributes inside the form were silently stripped by the output filter (wp_kses supports wildcards for data-* only); they are now allowlisted explicitly.

= 0.2.1 =
* Simplified draft saving to a single on/off switch. Restore mode, expiry (7 days) and the reset button now use sensible defaults; the block attributes remain available for advanced use.
* The "confidential" field toggle and badge only appear when an add-on actually evaluates the flag (new filter bdfrms_sensitive_ui_active) – the free base stores plain text and no longer suggests otherwise.
* New forms default to the automatic (system) appearance mode.

= 0.2.0 =
* Ported the proven form engine from the predecessor plugin: form and field blocks (bdfrms/*), server-side schema validation, defence chain (nonce, HMAC token, honeypot, rate limit, Friendly Captcha), email notification, appearance system, draft saving, file uploads with protected storage, submissions backend with labels, file downloads, CSV export and per-submission ZIP download.
* Documented add-on hook surface finalised (docs/hooks.md).

= 0.1.0 =
* Initial skeleton: activation, database tables, capability model, Friendly Captcha integration, settings cards, submissions list/detail, CSV export, documented add-on hook surface.
