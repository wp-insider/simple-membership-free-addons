<?php

class SwpmWpImport {
    public function __construct() {
        if (class_exists('SimpleWpMembership')) {
            add_action('swpm_after_main_admin_menu', array(&$this, 'user_import_do_admin_menu' ) );
            add_action('load-wp-membership_page_swpm-wp-import', array(&$this, 'library'));
        }
    }
    public function library(){
	wp_register_style('swpm-jquery-ui', SIMPLE_WP_MEMBERSHIP_URL . '/css/jquery-ui.min.css');
        wp_enqueue_style('swpm-jquery-ui');
	wp_enqueue_script('jquery-ui-datepicker');
    }
    private function get_management_permission(){
        $permission = 'manage_options';
        if (defined('SWPM_MANAGEMENT_PERMISSION')){
            $permission = SWPM_MANAGEMENT_PERMISSION;
        }
        return $permission;
    }
    private function check_permission(){
        if (!current_user_can($this->get_management_permission())){
            wp_die(__("You do not have permission to perform this action.", 'simple-membership'));
        }
    }
    public function user_import_do_admin_menu($menu_parent_slug){
        $permission = $this->get_management_permission();
        add_submenu_page($menu_parent_slug, __("WP User Import", 'simple-membership'), __("WP User Import", 'simple-membership'), $permission, 'swpm-wp-import', array(&$this, 'import_admin_menu'));
    }
    public function import_admin_menu(){
        $this->add();
        $this->show_list();
    }
    private function add(){
        $action = filter_input(INPUT_POST, 'add_all');
        if (!empty($action)){
			check_admin_referer('swpm-wp-import-all-users');
			$this->check_permission();

            $this->add_all();
        }
        $action = filter_input(INPUT_POST, 'action');
        $action2 = filter_input(INPUT_POST, 'action2');
        if ('add_selective' == $action || 'add_selective' == $action2){
            check_admin_referer('swpm-wp-import-selective-users', 'swpm_import_selective_nonce');
            $this->check_permission();

            if (empty($_POST['wp_users']) || !is_array($_POST['wp_users'])){
                echo '<div id="message" class="error"><p>No users were selected for import.</p></div>';
                return;
            }
            $skipped = 0;
            foreach ($_POST['wp_users'] as $key=>$ID){
                $user = new stdClass();
                $user->ID = absint($ID);
                $user->preserve_wp_role = isset($_POST['preserve_role']) && isset($_POST['preserve_role'][$key]) ? 1: null;
                $user->subscription_starts = isset($_POST['subscription_starts'][$key]) ? $this->sanitize_date($_POST['subscription_starts'][$key]) : '';
                $user->membership_level = isset($_POST['membership_level'][$key]) ? absint($_POST['membership_level'][$key]) : 0;
                $user->account_state = isset($_POST['account_state'][$key]) ? sanitize_text_field(wp_unslash($_POST['account_state'][$key])) : '';
                if (!$this->is_valid_import_row($user)){
                    $skipped++;
                    continue;
                }
                $this->add_one($user);
            }
            echo '<div id="message" class="updated fade"><p>Import operation performed!</p></div>';
            if ($skipped > 0){
                echo '<div id="message" class="error"><p>' . esc_html(sprintf('%d user(s) were skipped because of an invalid user, membership level or account state.', $skipped)) . '</p></div>';
            }
        }
    }
    private function sanitize_date($date){
        $date = sanitize_text_field(wp_unslash($date));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)){
            return $date;
        }
        return date('Y-m-d');
    }
    private function get_allowed_account_states(){
        $states = array('active', 'inactive', 'activation_required', 'pending', 'expired');
        if (method_exists('SwpmUtils', 'get_account_state_options')){
            $states = array_keys(SwpmUtils::get_account_state_options());
        }
        //The "Import All" form also offers the blocked state.
        $states[] = 'blocked';
        return $states;
    }
    private function is_valid_membership_level($level_id){
        global $wpdb;
        $level_id = absint($level_id);
        //Level 1 is the internal content protection level and can't be assigned to members.
        if ($level_id <= 1){
            return false;
        }
        $found = $wpdb->get_var($wpdb->prepare("SELECT id FROM " . $wpdb->prefix . "swpm_membership_tbl WHERE id = %d", $level_id));
        return !empty($found);
    }
    private function is_valid_import_row($row){
        if (empty($row->ID) || !get_userdata($row->ID)){
            return false;
        }
        if (!in_array($row->account_state, $this->get_allowed_account_states(), true)){
            return false;
        }
        return $this->is_valid_membership_level($row->membership_level);
    }
    private function show_list(){
        $wp_user = new SwpmWpUserList();
        include_once (SWPM_WP_IMPORT_PATH . 'views/wp_user_list.php');
    }
    private function add_all(){
        global $wpdb;
        $preserve_role = filter_input(INPUT_POST, 'wp_users_preserve_wp_role');
        $subscription_starts = $this->sanitize_date((string) filter_input(INPUT_POST, 'wp_users_subscription_starts'));
        $membership_level = absint(filter_input(INPUT_POST, 'wp_users_membership_level'));
        $account_state = sanitize_text_field((string) filter_input(INPUT_POST, 'wp_users_account_state'));

        $import_all = filter_input(INPUT_POST, 'wp_add_wp_member_to_swpm');
        if ($import_all){
            if (!$this->is_valid_membership_level($membership_level) || !in_array($account_state, $this->get_allowed_account_states(), true)){
                echo '<div id="message" class="error"><p>Invalid membership level or account state. No users were imported.</p></div>';
                return;
            }
            $users = $wpdb->get_results("SELECT ID FROM $wpdb->users");
            foreach ($users as $user){
                $user->membership_level = $membership_level;
                $user->account_state = $account_state;
                $user->subscription_starts = $subscription_starts;
                $user->preserve_wp_role = $preserve_role;
                $this->add_one($user);
            }
            echo '<div id="message" class="updated fade"><p>Import all users operation performed!</p></div>';
        }
    }
    private function add_one($row) {
        global $wpdb;
        $user_info = get_userdata($row->ID);
        if (!$user_info){
            return false;
        }
        //Use the roles property so this works with any database table prefix.
        $user_cap = is_array($user_info->roles) ? $user_info->roles : array();
        $fields = array();
        $fields['user_name'] = $user_info->user_login;
        $fields['first_name'] = $user_info->user_firstname;
        $fields['last_name'] = $user_info->user_lastname;
        $fields['password'] = $user_info->user_pass;
        $fields['member_since'] = date('Y-m-d H:i:s');
        $fields['membership_level'] = $row->membership_level;
        $fields['account_state'] = $row->account_state;
        $fields['email'] = $user_info->user_email;
        $fields['address_street'] = '';
        $fields['address_city'] = '';
        $fields['address_state'] = '';
        $fields['address_zipcode'] = '';
        $fields['country'] = '';
        $fields['gender'] = 'not specified';
        $fields['referrer'] = '';
        $fields['last_accessed_from_ip'] = SwpmUtils::get_user_ip_address();
        $fields['subscription_starts'] = $row->subscription_starts;
        $fields['extra_info'] = '';

        if (!empty($row->preserve_wp_role)) {
            $fields['flags'] = 1;
        } else {
            $fields['flags'] = 0;
            if (($row->account_state === 'active') && !in_array('administrator', $user_cap)){
                if(method_exists("SwpmMemberUtils", "update_wp_user_role_with_level_id")){
                    SwpmMemberUtils::update_wp_user_role_with_level_id($row->ID, $row->membership_level);
                }                
            }
        }
        $user_exists = BUtils::swpm_username_exists($fields['user_name']);
        if ($user_exists) {
            return $wpdb->update($wpdb->prefix . "swpm_members_tbl",  $fields, array('member_id'=>$user_exists));
        } else {
            //Insert a new user in SWPM
            $wpdb->insert($wpdb->prefix . "swpm_members_tbl",  $fields);
            
            //Trigger action hook
            $args = array('first_name' => $fields['first_name'], 'last_name' => $fields['last_name'], 'email' => $fields['email'], 'membership_level' => $fields['membership_level']);
            do_action('swmp_wpimport_user_imported', $args);
            return true;
       }
    }
}
