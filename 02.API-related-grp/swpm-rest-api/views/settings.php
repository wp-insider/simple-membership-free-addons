<?php
//Direct access to this file is not permitted
if ( ! defined( 'ABSPATH' ) ) {
	exit; //Exit if accessed directly
}
?>
<h3><?php echo SwpmUtils::_( 'REST API Addon Settings' ); ?></h3>
<p>
	<?php echo SwpmUtils::_( 'This addon exposes your membership data over a REST API so external applications, automation tools and AI assistants can work with it.' ); ?>
	<a href="https://simple-membership-plugin.com/" target="_blank"><?php echo SwpmUtils::_( 'Read the usage documentation' ); ?></a>
</p>

<table class="form-table">
	<tbody>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Enable REST API' ); ?></th>
			<td>
				<input type="checkbox" name="swpm-rest-api-enable" value="1" <?php checked( $enable_api ); ?> />
				<p class="description">
					<?php echo SwpmUtils::_( 'Turn the REST API on. While this is off, every endpoint of this addon returns a 403 response.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Allow write access' ); ?></th>
			<td>
				<input type="checkbox" name="swpm-rest-api-enable-write" value="1" <?php checked( $enable_write ); ?> />
				<p class="description">
					<?php echo SwpmUtils::_( 'Allow API clients to create, update and delete data. Leave this off unless you need it. With it off the API is read-only, so a misconfigured or misbehaving application cannot modify or remove your members.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'API Key' ); ?></th>
			<td>
				<input type="text" size="70" value="<?php echo esc_attr( $api_key ); ?>" readonly="readonly" onfocus="this.select();" />
				<p class="description">
					<?php echo SwpmUtils::_( 'Send this key in the <code>X-SWPM-API-Key</code> request header. Treat it like a password: anyone holding it has the same access to your membership data that you granted above.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Regenerate API Key' ); ?></th>
			<td>
				<input type="checkbox" name="swpm-rest-api-regenerate-key" value="1" />
				<p class="description">
					<?php echo SwpmUtils::_( 'Tick this and save to issue a new API key. Any application still using the old key will stop working immediately.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Read Rate Limit' ); ?></th>
			<td>
				<input type="number" min="0" name="swpm-rest-api-rate-limit-read" value="<?php echo esc_attr( $rate_limit_read ); ?>" />
				<p class="description">
					<?php echo SwpmUtils::_( 'Read requests allowed per minute for each API client. Use 0 for no limit, or leave blank to restore the default.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Write Rate Limit' ); ?></th>
			<td>
				<input type="number" min="0" name="swpm-rest-api-rate-limit-write" value="<?php echo esc_attr( $rate_limit_write ); ?>" />
				<p class="description">
					<?php echo SwpmUtils::_( 'Create, update and delete requests allowed per minute for each API client. Use 0 for no limit, or leave blank to restore the default.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'Failed API Key Limit' ); ?></th>
			<td>
				<input type="number" min="0" name="swpm-rest-api-rate-limit-failed-auth" value="<?php echo esc_attr( $rate_limit_failed_auth ); ?>" />
				<p class="description">
					<?php echo SwpmUtils::_( 'Wrong API keys allowed per minute from one IP address before further API key requests from it are refused until the minute is up. Use 0 for no limit, or leave blank to restore the default.' ); ?>
				</p>
			</td>
		</tr>
		<tr>
			<th scope="row"><?php echo SwpmUtils::_( 'API Base URL' ); ?></th>
			<td>
				<code><?php echo esc_html( $api_base_url ); ?></code>
				<p class="description">
					<?php echo SwpmUtils::_( 'The base address of the API. For example, append <code>/members</code> to it to list your member profiles.' ); ?>
				</p>
			</td>
		</tr>
	</tbody>
</table>
