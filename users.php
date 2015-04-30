<?php

class WP_MU_Users
{
    protected $_is_local_table = false;

    public function register()
    {
        if( wp_using_ext_object_cache() && function_exists( 'wp_cache_add_non_persistent_groups' ) ) {
            wp_cache_add_non_persistent_groups( array( 'user', 'user_meta', 'users' ) );
        }

        add_filter( 'wpmu_drop_tables', function( $tables ) {
            global $wpdb;
            return array_merge( $tables, array( $wpdb->prefix . 'users', $wpdb->prefix . 'usermeta' ) );
        });

        global $blog_id;

        if( $blog_id <= 1 ) {
            return;
        }

        add_action( 'muplugins_loaded', function() {
            $this->muplugins_loaded();
        });

        add_action( 'admin_print_styles-user-new.php', function() {
            $this->print_styles();
        });

        add_filter( 'network_site_url', function( $url, $path, $scheme ) {
            if( in_array( $scheme, array( 'login', 'login_post' ) ) ) {
                return get_site_url( null, $path, $scheme );
            }
            return $url;
        }, 10, 3);

        add_filter( 'retrieve_password_message', function( $message ) {
            return str_replace( network_home_url( '/' ), home_url(), $message );
        });

        add_filter( 'update_user_metadata', function( $null, $object_id, $meta_key, $meta_value, $prev_value ) {
            return $this->update_user_metadata( $null, $object_id, $meta_key, $meta_value, $prev_value );
        }, 99, 5);

        add_action( 'remove_user_from_blog', function( $user_id ) {
            $this->remove_user( $user_id );
        });

        add_action( 'wpmu_new_user', function( $user_id ) {
            global $wpdb;
            $user = get_user_by( 'id', $user_id );
            $wpdb->delete( $wpdb->signups, array( 'user_login' => $user->user_login ) );
        });

        add_filter( 'pre_user_login', function( $user_login ) {
            global $wpdb;
            $key = 'local_users_ids_' . $wpdb->blogid;
            wp_cache_delete( $key, 'users' );
            return $user_login;
        } );

        $this->_change_admin_capabilities();
        $this->create_function_get_user_by();
    }

    private function muplugins_loaded()
    {
        global $wpdb;
        define('GLOBAL_TABLE_USERS', $wpdb->users);
        define('GLOBAL_TABLE_USERMETA', $wpdb->usermeta);

        WP_MU_Users::switch_to_local_tables();
        $this->create_tables();
    }

    private function switch_to_local_tables()
    {
        global $wpdb;
        $wpdb->users = $wpdb->prefix . 'users';
        $wpdb->usermeta = $wpdb->prefix . 'usermeta';

        if( in_array( 'users', $wpdb->global_tables ) ) {
            unset( $wpdb->global_tables[ array_search( 'users', $wpdb->global_tables ) ] );
        }

        if( in_array( 'usermeta', $wpdb->global_tables ) ) {
            unset( $wpdb->global_tables[ array_search( 'usermeta', $wpdb->global_tables ) ] );
        }

        if( ! in_array( 'users', $wpdb->tables ) ) {
            $wpdb->tables[] = 'users';
        }

        if( ! in_array( 'usermeta', $wpdb->tables ) ) {
            $wpdb->tables[] = 'usermeta';
        }

        $this->_is_local_table = true;
    }

    private function switch_to_global_tables()
    {
        global $wpdb;
        $wpdb->users = GLOBAL_TABLE_USERS;
        $wpdb->usermeta = GLOBAL_TABLE_USERMETA;

        if( ! in_array( 'users', $wpdb->global_tables ) ) {
            $wpdb->global_tables[] = 'users';
        }

        if( ! in_array( 'usermeta', $wpdb->global_tables ) ) {
            $wpdb->global_tables[] = 'usermeta';
        }

        if( in_array( 'users', $wpdb->tables ) ) {
            unset( $wpdb->tables[ array_search( 'users', $wpdb->tables ) ] );
        }

        if( in_array( 'usermeta', $wpdb->tables ) ) {
            unset( $wpdb->tables[ array_search( 'usermeta', $wpdb->tables ) ] );
        }
        $this->_is_local_table = false;
    }

    private function create_tables()
    {
        global $wpdb;

        $users_table = $wpdb->users;
        $meta_table = $wpdb->usermeta;
        if ( get_option( 'user_table_created' ) || $wpdb->get_var( "SHOW TABLES LIKE '$users_table'" ) == $users_table) {
            return;
        }

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );

        $meta_table_query = "CREATE TABLE IF NOT EXISTS `$meta_table` (
          `umeta_id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `user_id` bigint(20) unsigned NOT NULL DEFAULT '0',
          `meta_key` varchar(255) DEFAULT NULL,
          `meta_value` longtext,
          PRIMARY KEY (`umeta_id`),
          KEY `user_id` (`user_id`),
          KEY `meta_key` (`meta_key`)
        ) ENGINE=InnoDB AUTO_INCREMENT=1 DEFAULT CHARSET=utf8 ;";

        $users_table_query = "CREATE TABLE IF NOT EXISTS `$users_table` (
          `ID` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
          `user_login` varchar(60) NOT NULL DEFAULT '',
          `user_pass` varchar(64) NOT NULL DEFAULT '',
          `user_nicename` varchar(50) NOT NULL DEFAULT '',
          `user_email` varchar(100) NOT NULL DEFAULT '',
          `user_url` varchar(100) NOT NULL DEFAULT '',
          `user_registered` datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
          `user_activation_key` varchar(60) NOT NULL DEFAULT '',
          `user_status` int(11) NOT NULL DEFAULT '0',
          `display_name` varchar(250) NOT NULL DEFAULT '',
          `spam` tinyint(2) NOT NULL DEFAULT '0',
          `deleted` tinyint(2) NOT NULL DEFAULT '0',
          PRIMARY KEY (`ID`),
          KEY `user_login_key` (`user_login`),
          KEY `user_nicename` (`user_nicename`)
        ) ENGINE=InnoDB AUTO_INCREMENT=10000 DEFAULT CHARSET=utf8;";

        dbDelta( $meta_table_query );
        dbDelta( $users_table_query );

        update_option( 'user_table_created', 'true' );
    }


    public function load_all_super_users()
    {
        if( wp_cache_get( 'super_users_loaded', 'users' ) ) {
            return;
        }

        $this->switch_to_global_tables();

        global $wpdb;
        $users = $wpdb->get_results( "SELECT * FROM $wpdb->users WHERE 1 = 1;");
        if($users) {
            foreach($users as $user) {
                update_user_caches( $user );
            }
            update_meta_cache( 'user', wp_list_pluck( $users, 'ID' ) );
        }

        $this->switch_to_local_tables();

        wp_cache_set( 'super_users_loaded', true , 'users', MINUTE_IN_SECONDS );
    }


    private function print_styles()
    {
        if( ! is_super_admin() ) {
            echo "<style> #add-existing-user, #add-existing-user + p, form#adduser { display: none; } </style>";
        }
    }

    private function update_user_metadata( $null, $object_id, $meta_key, $meta_value, $prev_value )
    {
        if( ! $this->_is_local_user( $object_id ) ) {
            global $wpdb;

            $this->switch_to_global_tables();

            $where = array( 'user_id' => $object_id, 'meta_key' => $meta_key );

            if ( !empty( $prev_value ) ) {
                $prev_value = maybe_serialize($prev_value);
                $where['meta_value'] = $prev_value;
            }

            if ( ! $meta_id = $wpdb->get_var( $wpdb->prepare( "SELECT umeta_id FROM $wpdb->usermeta WHERE meta_key = %s AND user_id = %d", $meta_key, $object_id ) ) ) {
                $result = add_metadata( 'user', $object_id, $meta_key, $meta_value);
                $this->switch_to_local_tables();
                return $result;
            }


            $wpdb->update( $wpdb->usermeta, array( 'meta_value' => maybe_serialize( $meta_value ) ), $where );

            update_meta_cache( 'user', array($object_id) );

            $this->switch_to_local_tables();

            return true;
        }
        return null;
    }

    private function _is_local_user( $object_id )
    {
        if( $object_id >= 10000 ) {
            return true;
        }

        global $wpdb;
        if( $wpdb->blogid <= 1 ) {
            return false;
        }

        $key = 'local_users_ids_' . $wpdb->blogid;
        $ids = wp_cache_get( $key, 'users' );
        if( empty( $ids ) || ! is_array( $ids ) ) {

            $switch = ! $this->_is_local_table;
            if( $switch ) {
                $this->switch_to_local_tables();
            }
            $ids = (array) $wpdb->get_col( "SELECT ID FROM $wpdb->users WHERE 1 = 1" );
            wp_cache_set( $key, $ids, 'users', MINUTE_IN_SECONDS );
            if( $switch ) {
                $this->switch_to_global_tables();
            }
        }
        return in_array( $object_id, $ids );
    }

    private function create_function_get_user_by()
    {
        if ( ! function_exists('get_user_by') ) {

            function get_user_by($field, $value)
            {
                global $wp_mu_users;
                $wp_mu_users->load_all_super_users();
                $userdata = WP_User::get_data_by($field, $value);

                if (!$userdata)
                    return false;

                $user = new WP_User;
                $user->init($userdata);

                return $user;
            }
        }
    }

    private function remove_user( $user_id )
    {
        if( $this->_is_local_user( $user_id ) ) {
            get_user_by( 'id', $user_id ); // load to cache
            global $wpdb;

            $wpdb->delete( $wpdb->usermeta, array( 'user_id' => $user_id) );
            $wpdb->delete( $wpdb->users, array( 'ID' => $user_id) );
        }
    }


    private function _change_admin_capabilities()
    {
        add_filter( 'map_meta_cap', function($caps, $cap, $user_id, $args) {
            foreach( $caps as $key => $capability ) {
                if( $capability == 'do_not_allow' && in_array( $cap, array( 'edit_user', 'edit_users' ) ) ) {
                    $user = get_user_by( 'id', $user_id );
                    if( $user && isset( $user->roles ) && isset( $user->roles[0] ) && $user->roles[0] == 'administrator' ) {
                        $caps[$key] = 'edit_users';
                    }
                }
            }

            return $caps;
        }, 1, 4 );

        remove_all_filters( 'enable_edit_any_user_configuration' );
        add_filter( 'enable_edit_any_user_configuration', function() {
            global $user_id;
            return $this->_is_local_user($user_id);
        });
    }

}

$wp_mu_users = new WP_MU_Users();
$wp_mu_users->register();


