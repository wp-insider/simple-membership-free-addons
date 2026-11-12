import { test } from 'node:test';
import assert from 'node:assert/strict';
import { McpServer } from '@modelcontextprotocol/server';
import { getTools } from '../src/tools.js';
import { createServer } from '../src/server.js';
import { readConfig } from '../src/config.js';

const tool = (name) => getTools().find((t) => t.name === name);

function recordingClient() {
	const calls = [];
	return {
		calls,
		request: async (method, path, options = {}) => {
			calls.push({ method, path, ...options });
			return { ok: true, status: 200, body: {}, headers: {} };
		},
	};
}

test('every tool has a unique name, a description and annotations', () => {
	const names = getTools().map((t) => t.name);

	assert.equal(new Set(names).size, names.length);
	assert.equal(names.length, 12);
	for (const t of getTools()) {
		assert.ok(t.description.length > 20, t.name);
		assert.equal(typeof t.annotations.readOnlyHint, 'boolean', t.name);
		assert.equal(t.annotations.readOnlyHint, !t.write, t.name);
	}
});

test('delete tools are marked destructive', () => {
	assert.equal(tool('delete_member').annotations.destructiveHint, true);
	assert.equal(tool('delete_level').annotations.destructiveHint, true);
	assert.equal(tool('update_member').annotations.destructiveHint, false);
});

test('tools map onto the right requests', async () => {
	const client = recordingClient();

	await tool('get_member').call(client, { id: 7 });
	await tool('update_member').call(client, { id: 7, first_name: 'Ann' });
	await tool('delete_level').call(client, { id: 4, force: true });
	await tool('list_transactions').call(client, { member_id: 7 });

	assert.deepEqual(client.calls[0], { method: 'GET', path: '/members/7' });
	assert.deepEqual(client.calls[1], { method: 'PATCH', path: '/members/7', body: { first_name: 'Ann' } });
	assert.deepEqual(client.calls[2], { method: 'DELETE', path: '/levels/4', query: { force: true } });
	assert.deepEqual(client.calls[3], { method: 'GET', path: '/transactions', query: { member_id: 7 } });
});

test('input schemas reject bad arguments', () => {
	assert.equal(tool('create_member').inputSchema.safeParse({ user_name: 'a' }).success, false);
	assert.equal(tool('create_member').inputSchema.safeParse({ user_name: 'a', email: 'a@b.c', password: 'x', membership_level: 2 }).success, true);
	assert.equal(tool('list_members').inputSchema.safeParse({ per_page: 500 }).success, false);
	assert.equal(tool('list_members').inputSchema.safeParse({ account_state: 'banana' }).success, false);
	assert.equal(tool('update_member').inputSchema.safeParse({ first_name: 'No id' }).success, false);
	assert.equal(tool('list_transactions').inputSchema.safeParse({ after: '01/02/2026' }).success, false);
});

function registeredToolNames(config) {
	const names = [];
	const original = McpServer.prototype.registerTool;
	McpServer.prototype.registerTool = function (name, ...rest) {
		names.push(name);
		return original.call(this, name, ...rest);
	};
	try {
		createServer(config, recordingClient());
	} finally {
		McpServer.prototype.registerTool = original;
	}
	return names;
}

test('the server registers every tool with the SDK', () => {
	const config = readConfig({ SWPM_SITE_URL: 'https://a.test', SWPM_API_KEY: 'k' });

	assert.equal(registeredToolNames(config).length, 12);
});

test('read-only mode leaves out the write tools', () => {
	const config = readConfig({ SWPM_SITE_URL: 'https://a.test', SWPM_API_KEY: 'k', SWPM_READ_ONLY: '1' });
	const names = registeredToolNames(config);

	assert.deepEqual(names.sort(), ['get_level', 'get_member', 'get_transaction', 'list_levels', 'list_members', 'list_transactions']);
});
