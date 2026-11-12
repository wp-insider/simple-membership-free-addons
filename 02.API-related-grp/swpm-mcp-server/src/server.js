import { McpServer } from '@modelcontextprotocol/server';
import { getTools, toToolResult } from './tools.js';

export const SERVER_NAME = 'simple-membership';
export const SERVER_VERSION = '1.0.0';

/**
 * Builds the MCP server. serveStdio() calls this for each connection.
 *
 * @param {object} config From readConfig().
 * @param {import('./api-client.js').SwpmApiClient} client
 */
export function createServer(config, client) {
	const server = new McpServer({ name: SERVER_NAME, version: SERVER_VERSION });

	for (const tool of getTools()) {
		//With SWPM_READ_ONLY set, the write tools are not offered at all.
		if (config.readOnly && tool.write) {
			continue;
		}

		server.registerTool(
			tool.name,
			{
				title: tool.title,
				description: tool.description,
				inputSchema: tool.inputSchema,
				annotations: tool.annotations,
			},
			async (args) => toToolResult(await tool.call(client, args))
		);
	}

	return server;
}
