/**
 * A small client for the add-on's swpm/v1 REST API.
 *
 * Every call resolves to { ok, status, body, headers } and never throws for an HTTP error, so the
 * tools can turn any failure into a message the model can act on.
 */
export class SwpmApiClient {
	/**
	 * @param {object} config From readConfig().
	 * @param {typeof fetch} [fetchImpl] Replaceable in tests.
	 */
	constructor(config, fetchImpl = globalThis.fetch) {
		this.config = config;
		this.fetch = fetchImpl;
	}

	/**
	 * Builds the URL for a path such as /members/12, with query parameters.
	 *
	 * Supports both the /wp-json/swpm/v1 form and the ?rest_route=/swpm/v1 form, for sites
	 * without pretty permalinks.
	 */
	buildUrl(path, query = {}) {
		const base = this.config.apiUrl;
		let url;

		if (base.includes('rest_route=')) {
			url = new URL(base);
			url.searchParams.set('rest_route', url.searchParams.get('rest_route').replace(/\/+$/, '') + path);
		} else {
			url = new URL(base + path);
		}

		for (const [key, value] of Object.entries(query)) {
			if (value !== undefined && value !== null && value !== '') {
				url.searchParams.set(key, String(value));
			}
		}

		return url.toString();
	}

	headers(hasBody) {
		const headers = {
			Accept: 'application/json',
			'User-Agent': 'swpm-mcp-server/1.0',
		};

		if (this.config.apiKey) {
			headers['X-SWPM-API-Key'] = this.config.apiKey;
		} else {
			const pair = `${this.config.wpUsername}:${this.config.wpAppPassword}`;
			headers.Authorization = `Basic ${Buffer.from(pair).toString('base64')}`;
		}

		if (hasBody) {
			headers['Content-Type'] = 'application/json';
		}

		return headers;
	}

	/**
	 * @param {string} method
	 * @param {string} path
	 * @param {{query?: object, body?: object}} [options]
	 */
	async request(method, path, { query, body } = {}) {
		const hasBody = body !== undefined;
		let response;

		try {
			response = await this.fetch(this.buildUrl(path, query), {
				method,
				headers: this.headers(hasBody),
				body: hasBody ? JSON.stringify(body) : undefined,
				signal: AbortSignal.timeout(this.config.timeoutMs),
			});
		} catch (error) {
			return {
				ok: false,
				status: 0,
				body: null,
				headers: {},
				networkError: error && error.name === 'TimeoutError' ? 'The site did not respond in time.' : String((error && error.message) || error),
			};
		}

		const text = await response.text();
		let parsed = null;
		try {
			parsed = text ? JSON.parse(text) : null;
		} catch {
			parsed = { raw: text.slice(0, 2000) };
		}

		return {
			ok: response.ok,
			status: response.status,
			body: parsed,
			headers: Object.fromEntries(response.headers.entries()),
		};
	}
}
