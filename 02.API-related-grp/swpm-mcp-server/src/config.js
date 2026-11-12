/**
 * Reads the server's settings from environment variables.
 *
 *   SWPM_SITE_URL         The WordPress site address, e.g. https://example.com (required,
 *                         unless SWPM_API_URL is set).
 *   SWPM_API_URL          The full API base, for a site where /wp-json/ does not work, e.g.
 *                         https://example.com/?rest_route=/swpm/v1. Overrides SWPM_SITE_URL.
 *   SWPM_API_KEY          The key from the add-on's settings.
 *   SWPM_WP_USERNAME      A WordPress username, with SWPM_WP_APP_PASSWORD, as an alternative to
 *   SWPM_WP_APP_PASSWORD  the API key. The user needs membership management permission.
 *   SWPM_READ_ONLY        Set to 1 to offer only the read tools, whatever the site allows.
 *   SWPM_TIMEOUT_SECONDS  How long to wait for the site (default 30).
 */
export function readConfig(env = process.env) {
	const errors = [];

	const siteUrl = (env.SWPM_SITE_URL || '').trim().replace(/\/+$/, '');
	let apiUrl = (env.SWPM_API_URL || '').trim().replace(/\/+$/, '');

	if (!apiUrl && siteUrl) {
		apiUrl = `${siteUrl}/wp-json/swpm/v1`;
	}
	if (!apiUrl) {
		errors.push('Set SWPM_SITE_URL to your WordPress site address, for example https://example.com.');
	} else if (!/^https?:\/\//i.test(apiUrl)) {
		errors.push(`The site address must start with https:// (got "${apiUrl}").`);
	}

	const apiKey = (env.SWPM_API_KEY || '').trim();
	const wpUsername = (env.SWPM_WP_USERNAME || '').trim();
	const wpAppPassword = (env.SWPM_WP_APP_PASSWORD || '').trim();

	if (!apiKey && !(wpUsername && wpAppPassword)) {
		errors.push('Set SWPM_API_KEY to the key from WP Membership > Settings > Addon Settings (or set SWPM_WP_USERNAME and SWPM_WP_APP_PASSWORD).');
	}

	const timeoutSeconds = Number(env.SWPM_TIMEOUT_SECONDS || 30);

	return {
		errors,
		apiUrl,
		apiKey,
		wpUsername,
		wpAppPassword,
		readOnly: ['1', 'true', 'yes'].includes(String(env.SWPM_READ_ONLY || '').trim().toLowerCase()),
		timeoutMs: Number.isFinite(timeoutSeconds) && timeoutSeconds > 0 ? timeoutSeconds * 1000 : 30000,
	};
}
