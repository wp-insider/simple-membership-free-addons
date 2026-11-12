import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { parse } from 'yaml';
import { getTools } from '../src/tools.js';

/*
 * Each tool's arguments must be exactly the parameters and body fields the API accepts for its
 * operation, as listed in the add-on's generated OpenAPI file. A route change in the add-on that is
 * not carried over to the tools fails here.
 */

const specPath = fileURLToPath(new URL('../../swpm-rest-api/docs/openapi.yaml', import.meta.url));
const spec = parse(readFileSync(specPath, 'utf8'));

const operations = {
	list_members: ['get', '/swpm/v1/members'],
	get_member: ['get', '/swpm/v1/members/{id}'],
	create_member: ['post', '/swpm/v1/members'],
	update_member: ['patch', '/swpm/v1/members/{id}'],
	delete_member: ['delete', '/swpm/v1/members/{id}'],
	list_levels: ['get', '/swpm/v1/levels'],
	get_level: ['get', '/swpm/v1/levels/{id}'],
	create_level: ['post', '/swpm/v1/levels'],
	update_level: ['patch', '/swpm/v1/levels/{id}'],
	delete_level: ['delete', '/swpm/v1/levels/{id}'],
	list_transactions: ['get', '/swpm/v1/transactions'],
	get_transaction: ['get', '/swpm/v1/transactions/{id}'],
};

function apiFields(method, path) {
	const operation = spec.paths[path] && spec.paths[path][method];
	assert.ok(operation, `${method.toUpperCase()} ${path} is not in openapi.yaml`);

	const fields = (operation.parameters || []).map((p) => p.name);

	const ref = operation.requestBody && operation.requestBody.content['application/json'].schema.$ref;
	if (ref) {
		const schema = spec.components.schemas[ref.split('/').pop()];
		fields.push(...Object.keys(schema.properties));
	}

	return fields.sort();
}

test('every API operation has a tool', () => {
	const covered = new Set(Object.values(operations).map(([method, path]) => `${method} ${path}`));

	for (const [path, methods] of Object.entries(spec.paths)) {
		for (const method of Object.keys(methods)) {
			//PUT and POST on an item are the same update as PATCH.
			const isUpdateAlias = path.endsWith('{id}') && (method === 'put' || method === 'post');
			if (!isUpdateAlias) {
				assert.ok(covered.has(`${method} ${path}`), `No tool for ${method.toUpperCase()} ${path}`);
			}
		}
	}
});

for (const [name, [method, path]] of Object.entries(operations)) {
	test(`${name} arguments match the API`, () => {
		const tool = getTools().find((t) => t.name === name);

		assert.deepEqual(Object.keys(tool.inputSchema.shape).sort(), apiFields(method, path));
	});
}
