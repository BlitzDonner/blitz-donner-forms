=== Blitz & Donner Forms ===
Contributors: blitzdonner
Tags: forms, block, contact form, spam protection, submissions
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.3.4
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
