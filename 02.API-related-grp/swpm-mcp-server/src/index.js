#!/usr/bin/env node
/**
 * Starts the Simple Membership MCP server over stdio.
 *
 * stdout carries the MCP protocol, so everything else is written to stderr.
 */
import { serveStdio } from '@modelcontextprotocol/server/stdio';
import { readConfig } from './config.js';
import { SwpmApiClient } from './api-client.js';
import { createServer } from './server.js';

const config = readConfig();

if (config.errors.length) {
	console.error('simple-membership MCP server is not configured:');
	for (const error of config.errors) {
		console.error(`  - ${error}`);
	}
	process.exit(1);
}

const client = new SwpmApiClient(config);
const handle = await serveStdio(() => createServer(config, client));

console.error(`simple-membership MCP server running against ${config.apiUrl}${config.readOnly ? ' (read-only)' : ''}`);

process.on('SIGINT', () => {
	void handle.close();
});
process.on('SIGTERM', () => {
	void handle.close();
});
