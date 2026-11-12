<?php

/**
 * Gives the REST controllers access to the main plugin's registration email routine.
 *
 * SwpmRegistration is abstract and its send_reg_email() method is protected, so a thin subclass
 * is the supported way to reuse the plugin's own email templates, merge tag handling and email
 * activation logic rather than duplicating any of it here. The older API add-on reaches the same
 * method the same way.
 */
class SWPM_REST_API_Registration_Notifier extends SwpmRegistration {

	/**
	 * Sends the "registration complete" email for a newly created member.
	 *
	 * @param array $member_info The member row, including a 'plain_password' key.
	 * @return bool
	 */
	public function send_registration_email( $member_info ) {
		$this->member_info = $member_info;

		//When the account still needs activating, send_reg_email() swaps in the activation email
		//and generates the activation link instead of the standard welcome message.
		if ( isset( $member_info['account_state'] ) && 'activation_required' === $member_info['account_state'] ) {
			$this->email_activation = true;
		}

		return $this->send_reg_email();
	}
}
