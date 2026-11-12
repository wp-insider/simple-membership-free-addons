import { test } from 'node:test';
import assert from 'node:assert/strict';
import { SwpmApiClient } from '../src/api-client.js';
import { readConfig } from '../src/config.js';
import { toToolResult } from '../src/tools.js';

function fakeFetch(status, body, calls) {
	return async (url, init) => {
		calls.push({ url, init });
		return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
	};
}

const baseConfig = readConfig({ SWPM_SITE_URL: 'https://example.com/', SWPM_API_KEY: 'k3y' });

test('config builds the API URL and needs credentials', () => {
	assert.equal(baseConfig.apiUrl, 'https://example.com/wp-json/swpm/v1');
	assert.deepEqual(baseConfig.errors, []);

	assert.equal(readConfig({}).errors.length, 2);
	assert.equal(readConfig({ SWPM_SITE_URL: 'example.com', SWPM_API_KEY: 'x' }).errors.length, 1);
	assert.equal(readConfig({ SWPM_SITE_URL: 'https://a.test', SWPM_WP_USERNAME: 'u', SWPM_WP_APP_PASSWORD: 'p' }).errors.length, 0);
	assert.equal(readConfig({ SWPM_SITE_URL: 'https://a.test', SWPM_API_KEY: 'x', SWPM_READ_ONLY: 'true' }).readOnly, true);
});

test('GET sends the key header and only the set query parameters', async () => {
	const calls = [];
	const client = new SwpmApiClient(baseConfig, fakeFetch(200, { data: [] }, calls));

	const response = await client.request('GET', '/members', { query: { page: 2, search: '', account_state: undefined } });

	assert.equal(response.ok, true);
	assert.equal(calls[0].url, 'https://example.com/wp-json/swpm/v1/members?page=2');
	assert.equal(calls[0].init.method, 'GET');
	assert.equal(calls[0].init.headers['X-SWPM-API-Key'], 'k3y');
	assert.equal(calls[0].init.body, undefined);
});

test('POST sends a JSON body', async () => {
	const calls = [];
	const client = new SwpmApiClient(baseConfig, fakeFetch(201, { data: { member_id: 5 } }, calls));

	await client.request('POST', '/members', { body: { user_name: 'jane', membership_level: 2 } });

	assert.equal(calls[0].init.headers['Content-Type'], 'application/json');
	assert.deepEqual(JSON.parse(calls[0].init.body), { user_name: 'jane', membership_level: 2 });
});

test('rest_route style base URL', async () => {
	const calls = [];
	const config = readConfig({ SWPM_API_URL: 'https://example.com/?rest_route=/swpm/v1', SWPM_API_KEY: 'k' });
	const client = new SwpmApiClient(config, fakeFetch(200, {}, calls));

	await client.request('GET', '/levels/3', { query: { force: true } });

	const url = new URL(calls[0].url);
	assert.equal(url.searchParams.get('rest_route'), '/swpm/v1/levels/3');
	assert.equal(url.searchParams.get('force'), 'true');
});

test('application password uses basic auth', async () => {
	const calls = [];
	const config = readConfig({ SWPM_SITE_URL: 'https://a.test', SWPM_WP_USERNAME: 'admin', SWPM_WP_APP_PASSWORD: 'abcd efgh' });
	const client = new SwpmApiClient(config, fakeFetch(200, {}, calls));

	await client.request('GET', '/levels');

	assert.equal(calls[0].init.headers.Authorization, `Basic ${Buffer.from('admin:abcd efgh').toString('base64')}`);
	assert.equal(calls[0].init.headers['X-SWPM-API-Key'], undefined);
});

test('network failures do not throw', async () => {
	const client = new SwpmApiClient(baseConfig, async () => {
		throw new TypeError('fetch failed');
	});

	const result = toToolResult(await client.request('GET', '/levels'));

	assert.equal(result.isError, true);
	assert.match(result.content[0].text, /Could not reach the site/);
});

test('errors carry field problems and a recovery hint', async () => {
	const client = new SwpmApiClient(
		baseConfig,
		fakeFetch(
			400,
			{ code: 'swpm_rest_invalid_member_data', message: 'Rejected', data: { status: 400, errors: { email: 'Email is already used.' } } },
			[]
		)
	);
	const result = toToolResult(await client.request('POST', '/members', { body: {} }));

	assert.equal(result.isError, true);
	assert.match(result.content[0].text, /HTTP 400/);
	assert.match(result.content[0].text, /- email: Email is already used\./);

	const blocked = toToolResult(
		await new SwpmApiClient(baseConfig, fakeFetch(403, { code: 'swpm_rest_api_write_disabled', message: 'No' }, [])).request('POST', '/levels', { body: {} })
	);
	assert.match(blocked.content[0].text, /Allow write access/);
});

test('success returns the response body as text', async () => {
	const client = new SwpmApiClient(baseConfig, fakeFetch(200, { data: { id: 3 } }, []));

	const result = toToolResult(await client.request('GET', '/levels/3'));

	assert.equal(result.isError, undefined);
	assert.deepEqual(JSON.parse(result.content[0].text), { data: { id: 3 } });
});
