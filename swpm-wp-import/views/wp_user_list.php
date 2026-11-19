<div class="wrap"><h2>Simple Membership WP User Import v<?php echo  SWPM_WP_IMPORT_VERSION ?></h2>
<div class="wrap">
    <p>You can either import all of your WordPress users to Simple Membership in one go or selectively import users from this interface.</p>
    
    <h3>Import All WordPress Users as Members</h3>
    <form method="post" action="">
        <table class="widefat" >
            <thead>
                <tr>
                    <th scope="col" colspan="3">Import All Users to Simple Membership</th>
                    <th scope="col">Membership Level</th>
                    <th scope="col">Subscription Starts From</th>
                    <th scope="col">Account State</th>
                    <th scope="col">Preserve Role</th>
                </tr>
            </thead>
            <tbody>
                <tr valign="top">
                    <td class="check-column" colspan="3" scope="row">
                        <div style="margin-left: 15px;">
                            <input type="checkbox" value="1" name="wp_add_wp_member_to_swpm">
                        </div>
                    </td>
                    <td>
                        <select name="wp_users_membership_level">
                            <?php echo  BUtils::membership_level_dropdown(); ?>
                        </select>
                    </td>
                    <td>
                        <input type="text" value="<?php echo date('Y-m-d'); ?>" name="wp_users_subscription_starts" id="wp_users_subscription_starts" class="date_field" >
                    </td>
                    <td>
                        <select name="wp_users_account_state">
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                            <option value="blocked">Blocked</option>
                        </select>
                    </td>
                    <td>
                        <input type="checkbox" value="1" checked="checked" name="wp_users_preserve_wp_role">
                    </td>
                </tr>
            </tbody>
        </table>
        <p class="submit">
            <input name="add_all" type="submit" class="button" value="Import All Users" />
            <?php wp_nonce_field('swpm-wp-import-all-users') ?>
        </p>
    </form>
    <hr>
    <h3>Selectively Import Some WordPress Users as Members</h3>
    <form method="post">
        <p class="search-box">
            <label class="screen-reader-text" for="search_id-search-input">
                search:</label>
            <input id="search_id-search-input" type="text" name="s" value="" />
            <input id="search-submit" class="button" type="submit" name="" value="<?php echo  BUtils::_('search')?>" />
            <input type="hidden" name="page" value="my_list_test" />
        </p>
    </form>
    <?php $wp_user->prepare_items(); ?>
    <form method="post">
        <?php wp_nonce_field('swpm-wp-import-selective-users', 'swpm_import_selective_nonce'); ?>
        <?php $wp_user->display(); ?>
    </form>

</div>
</div>
<script>
jQuery(document).ready(function($){
	$('.date_field').datepicker({dateFormat: 'yy-mm-dd', changeMonth: true, changeYear: true, yearRange: "-100:+100"});
});
</script>