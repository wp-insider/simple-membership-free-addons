import * as z from 'zod/v4';

/*
 * One tool per REST API operation.
 *
 * The argument names are the API's own field names, so a tool call maps straight onto a request.
 * test/drift.test.js fails if they fall out of step with swpm-rest-api/docs/openapi.yaml.
 */

const ACCOUNT_STATES = ['active', 'inactive', 'activation_required', 'expired', 'pending', 'unsubscribed'];

const id = (what) => z.number().int().positive().describe(`The ${what} id.`);

const pagination = {
	page: z.number().int().min(1).optional().describe('Page number, starting at 1. Check meta.has_more in the result to see whether there is another page.'),
	per_page: z.number().int().min(1).max(100).optional().describe('Results per page, 1 to 100 (default 20).'),
	search: z.string().optional(),
	order: z.enum(['asc', 'desc']).optional(),
};

const date = (what) => z.string().regex(/^\d{4}-\d{2}-\d{2}$/, 'Use YYYY-MM-DD.').describe(`${what}, as YYYY-MM-DD.`);

/** Fields shared by member create and update. */
const memberFields = {
	email: z.string().describe('Email address. Must not be used by another member or by a site administrator.'),
	password: z.string().describe('New password. When the site forces strong passwords it needs 8+ characters with an uppercase letter, a lowercase letter and a digit.'),
	membership_level: z.number().int().positive().describe('Membership level id (see list_levels). Level 1 is internal and cannot be assigned.'),
	first_name: z.string(),
	last_name: z.string(),
	account_state: z.enum(ACCOUNT_STATES).describe('Account status. Use inactive to suspend a member without deleting them.'),
	member_since: date('Date the member joined'),
	subscription_starts: date('Start of the access period; expiry is calculated from it'),
	gender: z.enum(['male', 'female', 'not specified']),
	phone: z.string(),
	address_street: z.string(),
	address_city: z.string(),
	address_state: z.string(),
	address_zipcode: z.string(),
	country: z.string().describe('Country name from the site\'s list, or a two letter code such as US. An empty string clears it.'),
	company_name: z.string(),
	referrer: z.string(),
	subscr_id: z.string().describe('Subscription id from the payment gateway.'),
	txn_id: z.string().describe('Transaction id from the payment gateway.'),
	home_page: z.string().describe('URL.'),
	notes: z.string().describe('Admin notes about the member.'),
};

const optional = (fields) => Object.fromEntries(Object.entries(fields).map(([key, schema]) => [key, schema.optional()]));

/** Fields shared by level create and update. */
const levelFields = {
	name: z.string().describe('Display name of the level.'),
	role: z.string().describe('WordPress role given to members of this level, e.g. subscriber. Must exist on the site.'),
	subscription_duration_type: z.number().int().min(0).max(6).describe('How access is limited: 0 no expiry, 1 days, 2 weeks, 3 months, 4 years, 5 fixed date, 6 annual fixed date.'),
	subscription_period: z.string().describe('For types 1-4 a positive number (e.g. "30"); for 5 and 6 a date YYYY-MM-DD (for 6 only month and day matter). Required when the type is not 0, including when you change the type.'),
	annual_fixed_date_min_period: z.number().int().min(0).describe('For type 6: minimum days of access; members joining closer to the date than this expire the following year.'),
	default_account_status: z.enum(['', 'active', 'inactive', 'activation_required', 'pending', 'expired']).describe('Status for new members of this level. Empty string means use the site-wide default.'),
	email_activation: z.boolean().describe('New members must confirm their email address before they can log in.'),
	after_activation_redirect_page: z.string().describe('URL to send members to after they activate by email. Empty string clears it.'),
	loginredirect_page: z.string().describe('URL to send members of this level to after they log in.'),
	campaign_name: z.string(),
	protect_older_posts: z.boolean().describe('Hide posts published before the member joined.'),
};

const READ = { readOnlyHint: true, openWorldHint: false };
const WRITE = { readOnlyHint: false, destructiveHint: false, openWorldHint: false };
const UPDATE = { readOnlyHint: false, destructiveHint: false, idempotentHint: true, openWorldHint: false };
const DELETE = { readOnlyHint: false, destructiveHint: true, idempotentHint: true, openWorldHint: false };

/**
 * @returns {Array<{name: string, title: string, description: string, write: boolean, annotations: object, inputSchema: z.ZodObject, call: (client: import('./api-client.js').SwpmApiClient, args: object) => Promise<object>}>}
 */
export function getTools() {
	return [
		/*
		 * Members
		 */
		{
			name: 'list_members',
			title: 'List members',
			description: 'List and search membership profiles. Filters combine. Returns data (the members), meta (total, total_pages, has_more) and links.',
			write: false,
			annotations: READ,
			inputSchema: z.object({
				...pagination,
				search: z.string().optional().describe('Matches username, first name, last name or email.'),
				orderby: z.enum(['member_id', 'user_name', 'first_name', 'last_name', 'email', 'member_since', 'last_accessed', 'membership_level', 'account_state']).optional(),
				membership_level: z.number().int().positive().optional().describe('Only members of this level id.'),
				account_state: z.enum(ACCOUNT_STATES).optional(),
				email: z.string().optional().describe('Exact email address.'),
			}),
			call: (client, args) => client.request('GET', '/members', { query: args }),
		},
		{
			name: 'get_member',
			title: 'Get member',
			description: 'Get one membership profile by member id, including its level name, expiry date and linked WordPress user id.',
			write: false,
			annotations: READ,
			inputSchema: z.object({ id: id('member') }),
			call: (client, { id: memberId }) => client.request('GET', `/members/${memberId}`),
		},
		{
			name: 'create_member',
			title: 'Create member',
			description: 'Create a membership profile and the WordPress user account behind it. The username cannot be changed later. Without account_state, the level decides the status (email activation gives activation_required and sends the activation email).',
			write: true,
			annotations: WRITE,
			inputSchema: z.object({
				...optional(memberFields),
				user_name: z.string().describe('Username. Letters, numbers and . - _ @ only. Cannot be changed later.'),
				email: memberFields.email,
				password: memberFields.password,
				membership_level: memberFields.membership_level,
				send_notification: z.boolean().optional().describe('Email the member about their new account. Leave unset to use the site\'s setting.'),
			}),
			call: (client, args) => client.request('POST', '/members', { body: args }),
		},
		{
			name: 'update_member',
			title: 'Update member',
			description: 'Change fields on a membership profile. Only the fields you pass are changed. Email, name and password changes also update the WordPress user; a level change also updates their WordPress role. The username cannot be changed.',
			write: true,
			annotations: UPDATE,
			inputSchema: z.object({ id: id('member'), ...optional(memberFields) }),
			call: (client, { id: memberId, ...fields }) => client.request('PATCH', `/members/${memberId}`, { body: fields }),
		},
		{
			name: 'delete_member',
			title: 'Delete member',
			description: 'Permanently delete a membership profile and its WordPress user account. This cannot be undone. An administrator\'s WordPress account is never deleted. To only stop access, use update_member with account_state inactive instead.',
			write: true,
			annotations: DELETE,
			inputSchema: z.object({ id: id('member') }),
			call: (client, { id: memberId }) => client.request('DELETE', `/members/${memberId}`),
		},

		/*
		 * Membership levels
		 */
		{
			name: 'list_levels',
			title: 'List membership levels',
			description: 'List the membership levels, with their expiry settings and how many members each has.',
			write: false,
			annotations: READ,
			inputSchema: z.object({
				...pagination,
				search: z.string().optional().describe('Matches the level name.'),
				orderby: z.enum(['id', 'name', 'role']).optional(),
			}),
			call: (client, args) => client.request('GET', '/levels', { query: args }),
		},
		{
			name: 'get_level',
			title: 'Get membership level',
			description: 'Get one membership level by id.',
			write: false,
			annotations: READ,
			inputSchema: z.object({ id: id('membership level') }),
			call: (client, { id: levelId }) => client.request('GET', `/levels/${levelId}`),
		},
		{
			name: 'create_level',
			title: 'Create membership level',
			description: 'Create a membership level. Without subscription_duration_type the level never expires.',
			write: true,
			annotations: WRITE,
			inputSchema: z.object({ ...optional(levelFields), name: levelFields.name }),
			call: (client, args) => client.request('POST', '/levels', { body: args }),
		},
		{
			name: 'update_level',
			title: 'Update membership level',
			description: 'Change fields on a membership level. Only the fields you pass are changed.',
			write: true,
			annotations: UPDATE,
			inputSchema: z.object({ id: id('membership level'), ...optional(levelFields) }),
			call: (client, { id: levelId, ...fields }) => client.request('PATCH', `/levels/${levelId}`, { body: fields }),
		},
		{
			name: 'delete_level',
			title: 'Delete membership level',
			description: 'Permanently delete a membership level. Refused while members are assigned to it: move them to another level with update_member first. Only use force when the user explicitly accepts that those members will be left on a level that no longer exists, which breaks their access.',
			write: true,
			annotations: DELETE,
			inputSchema: z.object({
				id: id('membership level'),
				force: z.boolean().optional().describe('Delete even though members are assigned. Leaves them broken; avoid.'),
			}),
			call: (client, { id: levelId, force }) => client.request('DELETE', `/levels/${levelId}`, { query: { force } }),
		},

		/*
		 * Transactions
		 */
		{
			name: 'list_transactions',
			title: 'List payment transactions',
			description: 'List and search payment transactions (read-only). Filters combine.',
			write: false,
			annotations: READ,
			inputSchema: z.object({
				...pagination,
				search: z.string().optional().describe('Matches email, name, transaction id or subscription id.'),
				orderby: z.enum(['id', 'txn_date']).optional(),
				member_id: z.number().int().positive().optional(),
				email: z.string().optional().describe('Exact email address.'),
				gateway: z.string().optional().describe('e.g. stripe or paypal.'),
				status: z.string().optional().describe('e.g. Completed or Refunded.'),
				subscr_id: z.string().optional(),
				after: date('Only transactions on or after this date').optional(),
				before: date('Only transactions on or before this date').optional(),
			}),
			call: (client, args) => client.request('GET', '/transactions', { query: args }),
		},
		{
			name: 'get_transaction',
			title: 'Get payment transaction',
			description: 'Get one payment transaction by id.',
			write: false,
			annotations: READ,
			inputSchema: z.object({ id: id('transaction') }),
			call: (client, { id: transactionId }) => client.request('GET', `/transactions/${transactionId}`),
		},
	];
}

/**
 * Turns an API response into an MCP tool result.
 *
 * Failures come back as isError results whose text tells the model what went wrong and what to do,
 * since that text is all it has to work with.
 */
export function toToolResult(response) {
	if (response.ok) {
		return { content: [{ type: 'text', text: JSON.stringify(response.body, null, 2) }] };
	}

	return { isError: true, content: [{ type: 'text', text: describeError(response) }] };
}

function describeError(response) {
	if (response.networkError) {
		return `Could not reach the site: ${response.networkError} Check that SWPM_SITE_URL is correct and the site is up.`;
	}

	const body = response.body || {};
	const code = body.code || 'unknown_error';
	const lines = [`The site returned HTTP ${response.status} (${code}): ${body.message || 'no message'}`];

	const fieldErrors = body.data && body.data.errors;
	if (fieldErrors && typeof fieldErrors === 'object') {
		for (const [field, message] of Object.entries(fieldErrors)) {
			lines.push(`- ${field}: ${message}`);
		}
	}

	const params = body.data && body.data.params;
	if (params && typeof params === 'object') {
		for (const [field, message] of Object.entries(params)) {
			lines.push(`- ${field}: ${message}`);
		}
	}

	const hints = {
		swpm_rest_api_disabled: 'The REST API is switched off. The site owner can turn it on under WP Membership > Settings > Addon Settings.',
		swpm_rest_api_unauthorized: 'The API key was rejected. Check SWPM_API_KEY against the key in the add-on settings; it changes if the key is regenerated.',
		swpm_rest_api_write_disabled: 'The site only allows reading. The site owner can tick "Allow write access" in the add-on settings. Do not retry.',
		swpm_rest_api_rate_limited: `Too many requests. Wait ${(body.data && body.data.retry_after) || 60} seconds before trying again.`,
		swpm_rest_level_in_use: 'Move the members to another level with update_member first, then delete the level.',
		rest_no_route: 'The add-on is not active on the site, or the site URL is wrong.',
	};

	if (hints[code]) {
		lines.push(hints[code]);
	}

	return lines.join('\n');
}
