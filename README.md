# Simple Membership – Free Addons

This repository holds the **free addons** for the [Simple Membership](https://wordpress.org/plugins/simple-membership/) WordPress plugin.

Each addon is a small, standalone WordPress plugin that extends Simple Membership with an extra feature. The addons need the core Simple Membership plugin installed and active.

## Useful Links

| | |
|---|---|
| Plugin website | https://simple-membership-plugin.com/ |
| Simple Membership on WordPress.org | https://wordpress.org/plugins/simple-membership/ |
| Documentation | https://simple-membership-plugin.com/simple-membership-documentation/ |
| All addons (free and premium) | https://simple-membership-plugin.com/addons-extensions/ |
| Support forum | https://simple-membership-plugin.com/forums/forum/simple-membership-plugin/ |

## Addons in This Repository

| Addon | Folder | Description | Documentation |
|---|---|---|---|
| After Login Redirection | [`swpm-after-login-redirection`](swpm-after-login-redirection/) | Send members to a specific page after they log in, based on their membership level. | [Usage guide](https://simple-membership-plugin.com/configure-login-redirection-members/) |
| Custom Messages | [`swpm-custom-message`](swpm-custom-message/) | Customize the content protection messages the plugin shows to visitors and members. | [Usage guide](https://simple-membership-plugin.com/simple-membership-custom-messages-addon/) |
| Form Shortcode | [`swpm-form-shortcode`](swpm-form-shortcode/) | Generate a registration form shortcode for a specific membership level, so you can place that form on any post or page. | [Usage guide](https://simple-membership-plugin.com/simple-membership-registration-form-shortcode-generator/) |
| Full Page Protection | [`swpm-full-page-protection`](swpm-full-page-protection/) | Protect the whole post, page or custom post type post (not just its content), lock down specific URLs or URL patterns, or apply site-wide protection. | [Usage guide](https://simple-membership-plugin.com/full-page-protection-addon-simple-membership/) |
| Misc Shortcodes | [`swpm-misc-shortcodes`](swpm-misc-shortcodes/) | A collection of miscellaneous shortcodes and small features for Simple Membership. | [Usage guide](https://simple-membership-plugin.com/simple-membership-miscellaneous-shortcodes-addon/) |
| Partial Protection | [`swpm-partial-protection`](swpm-partial-protection/) | Protect or hide specific sections of a post or page, so you can show teaser content to everyone and keep the rest for members. | [Usage guide](https://simple-membership-plugin.com/apply-partial-section-protection/) |
| Show Member Info | [`swpm-show-member-info`](swpm-show-member-info/) | Display member details (such as name, level or expiry) on your site using shortcodes. | [Usage guide](https://simple-membership-plugin.com/simple-membership-addon-show-member-info/) |
| Social Login | [`swpm-social-login`](swpm-social-login/) | Let members register and log in with their Google or Facebook account. | [Usage guide](https://simple-membership-plugin.com/simple-membership-social-login-addon/) |
| WP User Import | [`swpm-wp-import`](swpm-wp-import/) | Import your existing WordPress users as members, either all at once or selectively, with a chosen membership level, account state and start date. | [Usage guide](https://simple-membership-plugin.com/import-existing-wordpress-users-simple-membership-plugin/) |
| Zapier Webhook Integration | [`swpm-zapier-hook-integration`](swpm-zapier-hook-integration/) | Send a new member's username, email and name to a Zapier webhook when they complete registration. Set your webhook URL in Settings → Addon Settings. | [Usage guide](https://simple-membership-plugin.com/zapier-webhook-integration-for-simple-membership-plugin/) |

### CAPTCHA Addons

These are in the [`01.CAPTCHA-related-addons-grp`](01.CAPTCHA-related-addons-grp/) folder. Each one adds spam protection to the registration, login and password reset forms.

| Addon | Folder | Description | Documentation |
|---|---|---|---|
| Cloudflare Turnstile | [`swpm-cloudflare-turnstile`](01.CAPTCHA-related-addons-grp/swpm-cloudflare-turnstile/) | Add Cloudflare Turnstile to the membership forms. | [Usage guide](https://simple-membership-plugin.com/simple-membership-and-cloudflare-turnstile-integration-addon/) |
| Google reCAPTCHA | [`swpm-recaptcha`](01.CAPTCHA-related-addons-grp/swpm-recaptcha/) | Add Google reCAPTCHA (v2, "I'm not a robot" checkbox) to the membership forms. | [Usage guide](https://simple-membership-plugin.com/simple-membership-and-google-recaptcha-integration/) |
| Google reCAPTCHA Enterprise | [`swpm-recaptcha-enterprise`](01.CAPTCHA-related-addons-grp/swpm-recaptcha-enterprise/) | Add Google reCAPTCHA Enterprise to the membership forms. | [Usage guide](https://simple-membership-plugin.com/simple-membership-google-recaptcha-enterprise-option/) |
| Google reCAPTCHA v3 | [`swpm-recaptcha-v3`](01.CAPTCHA-related-addons-grp/swpm-recaptcha-v3/) | Add invisible, score-based Google reCAPTCHA v3 to the membership forms. | [Usage guide](https://simple-membership-plugin.com/simple-membership-google-recaptcha-v3-integration/) |

## Installation

1. Download this repository as a ZIP (**Code → Download ZIP**) and extract it.
2. Pick the addon folder you want (for example, `swpm-partial-protection`) and compress that folder on its own into a ZIP file. Some addons are inside a group folder (for example, `01.CAPTCHA-related-addons-grp/swpm-recaptcha`): zip only the addon folder, not the group folder.
3. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**, upload the ZIP and activate it.

   Or upload the addon folder to `/wp-content/plugins/` over FTP, then activate it from the **Plugins** menu.

Each addon's `readme.txt` has its changelog and any extra setup notes.

## Support

- For questions about using the plugin or its addons, read the [documentation](https://simple-membership-plugin.com/simple-membership-documentation/) or post on the [support forum](https://simple-membership-plugin.com/forums/forum/simple-membership-plugin/).
- To report a bug in one of these addons, [open an issue](https://github.com/wp-insider/simple-membership-free-addons/issues) in this repository.

## Contributing

Pull requests are welcome. Please keep each change limited to a single addon and describe what it fixes or adds.

## License

These addons are released under the [GPLv2 or later](https://www.gnu.org/licenses/gpl-2.0.html) license, the same as the Simple Membership plugin.
