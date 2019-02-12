<?php
/**
 * The core plugin config class.
 *
 * This maintains all the options and settings for this plugin.
 *
 * @since      	1.0.0
 * @since  		1.5 Moved into /inc
 * @package    	LiteSpeed_Cache
 * @subpackage 	LiteSpeed_Cache/inc
 * @author     	LiteSpeed Technologies <info@litespeedtech.com>
 */
defined( 'WPINC' ) || exit ;


class LiteSpeed_Cache_Config extends LiteSpeed_Cache_Const
{
	private static $_instance ;

	const TYPE_SET = 'set' ;

	private $_options = array() ;
	private $_site_options = array() ;
	private $_default_options = array() ;
	private $_default_site_options = array() ;

	protected $vary_groups ;
	protected $optm_exc_roles ;
	protected $cache_exc_roles ;
	protected $purge_options ;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since 1.0.0
	 * @access private
	 */
	private function __construct()
	{
		$this->_default_options = $this->default_keys() ;

		// Check if conf exists or not. If not, create them in DB (won't change version if is converting v2.9- data)
		// Conf may be stale, upgrade later
		$this->_conf_db_init() ;

		// Load options first, network sites can override this later
		$this->load_options() ;

		// Override conf if is network subsites and chose `Use Primary Config`
		$this->_try_load_site_options() ;

		// Check advanced_cache set (compabible for both network and single site)
		$this->_define_adv_cache() ;

		// Init global const cache on set
		if ( $this->_options[ self::O_CACHE ] === self::VAL_ON ) {
			$this->_options[ self::_CACHE ] = true ;
		}

		// Set cache on
		if ( $this->_options[ self::_CACHE ] ) {
			$this->define_cache_on() ;
		}

		$this->purge_options = explode('.', $this->_options[ self::O_PURGE_BY_POST xx ] ) ;

		// Vary group settings
		$this->vary_groups = $this->get_item( self::O_CACHE_VARY_GROUP ) ;

		// Exclude optimization role setting
		$this->optm_exc_roles = $this->get_item( self::O_OPTM_EXC_ROLES ) ;

		// Exclude cache role setting
		$this->cache_exc_roles = $this->get_item( self::O_CACHE_EXC_ROLES ) ;

		// Hook to options
		add_action( 'litespeed_init', array( $this, 'hook_options' ) ) ;

	}

	/**
	 *
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _conf_db_init()
	{
		$ver = get_option( self::conf_name( self::_VERSION ) ) ;

		/**
		 * Version is less than v3.0, or, is a new installation
		 */
		if ( ! $ver ) {
			// Try upgrade first (network will upgrade inside too)
			LiteSpeed_Cache_Data::get_instance()->try_upgrade_conf_3_0() ;
		}

		/**
		 * Upgrade conf
		 */
		if ( $ver && $ver != LiteSpeed_Cache::PLUGIN_VERSION ) {
			LiteSpeed_Cache_Data::get_instance()->conf_upgrade( $ver ) ;
		}

		if ( ! $ver || $ver != LiteSpeed_Cache::PLUGIN_VERSION ) {
			// Load default values
			$this->_default_options = $this->default_vals() ;

			// Init new default/missing options
			foreach ( $this->_default_options as $k => $v ) {
				// If the option existed, bypass updating
				add_option( self::conf_name( $k ), $v ) ;
			}
		}
	}

	/**
	 * Load all latest options from DB
	 *
	 * Already load the lacking options with default values, won't insert them into DB. Inserting will be done on setting saving.
	 *
	 * @since  3.0
	 * @access public
	 */
	public function load_options( $blog_id = null, $dry_run = false )
	{
		$options = array() ;
		// No need to consider items yet as they won't be gotten directly from $this->_options but used in $this->get_item()
		foreach ( $this->_default_options as $k => $v ) {
			if ( ! is_null( $blog_id ) ) {
				$options[ $k ] = get_blog_option( $blog_id, self::conf_name( $k ), $v ) ;
			}
			else {
				$options[ $k ] = get_option( self::conf_name( $k ), $v ) ;
			}
		}

		if ( ! $dry_run ) {
			$this->_options = $options ;
		}

		return $options ;
	}

	/**
	 * For multisite installations, the single site options need to be updated with the network wide options.
	 *
	 * @since 1.0.13
	 * @access private
	 * @return array The updated options.
	 */
	private function _try_load_site_options()
	{
		if ( ! $this->_if_need_site_options() ) {
			return ;
		}

		$this->get_site_options() ;

		// $this->_define_adv_cache( $this->_site_options ) ;

		// If network set to use primary setting
		if ( ! empty ( $this->_site_options[ self::NETWORK_O_USE_PRIMARY ] ) ) {

			// save temparary cron setting as cron settings are per site
			$CRWL_CRON_ACTIVE = $this->_options[ self::O_CRWL ] ;

			// Get the primary site settings
			// If it's just upgraded, 2nd blog is being visited before primary blog, can just load default config (won't hurt as this could only happen shortly)
			$this->load_options( BLOG_ID_CURRENT_SITE ) ;

			// crawler cron activation is separated
			$this->_options[ self::O_CRWL ] = $CRWL_CRON_ACTIVE ;
		}

		// If use network setting
		if ( $this->_options[ self::O_CACHE ] === self::VAL_ON2 && $this->_site_options[ self::NETWORK_O_ENABLED ] ) {
			$this->_options[ self::_CACHE ] = true ;
		}
		// Set network eanble to on
		if ( $this->_site_options[ self::NETWORK_O_ENABLED ] ) {
			! defined( 'LITESPEED_NETWORK_ON' ) && define( 'LITESPEED_NETWORK_ON', true ) ;
		}

		// Append site options to single blog options
		foreach ( $this->_default_options as $k => $v ) {
			if ( isset( $this->_site_options[ $k ] ) ) {
				$this->_options[ $k ] = $this->_site_options[ $k ] ;
			}
		}
	}

	/**
	 * Check if needs to load site_options for network sites
	 *
	 * @since  3.0
	 * @access private
	 */
	private function _if_need_site_options()
	{
		if ( ! is_multisite() ) {
			return false ;
		}

		// Check if needs to use site_options or not

		/**
		 * In case this is called outside the admin page
		 * @see  https://codex.wordpress.org/Function_Reference/is_plugin_active_for_network
		 * @since  2.0
		 */
		if ( ! function_exists( 'is_plugin_active_for_network' ) ) {
			require_once( ABSPATH . '/wp-admin/includes/plugin.php' ) ;
		}
		// If is not activated on network, it will not have site options
		if ( ! is_plugin_active_for_network( LiteSpeed_Cache::PLUGIN_FILE ) ) {
			if ( $this->_options[ self::O_CACHE ] === self::VAL_ON2 ) { // Default to cache on
				$this->_options[ self::_CACHE ] = true ;
			}
			return false ;
		}

		return true ;
	}


	/**
	 * Get the plugin's site wide options.
	 *
	 * If the site wide options are not set yet, set it to default.
	 *
	 * @since 1.0.2
	 * @access public
	 * @return array Returns the current site options.
	 */
	public function get_site_options()
	{
		if ( ! is_multisite() ) {
			return null ;
		}

		if ( $this->_site_options ) {
			return $this->_site_options ;
		}

		$this->_default_site_options = $this->default_site_keys() ;

		$ver = get_site_option( self::conf_name( self::_VERSION ) ) ;

		/**
		 * Is a new installation
		 */
		if ( ! $ver || $ver != LiteSpeed_Cache::PLUGIN_VERSION ) {
			// Load default values
			$this->_default_site_options = $this->default_site_vals() ;

			// Init new default/missing options
			foreach ( $this->_default_site_options as $k => $v ) {
				// If the option existed, bypass updating
				add_site_option( self::conf_name( $k ), $v ) ;
			}
		}

		// Load all site options
		foreach ( $this->_default_site_options as $k => $v ) {
			$this->_site_options[ $k ] = get_site_option( self::conf_name( $k ), $v ) ;
		}

		return $this->_site_options ;
	}


	/**
	 * Give an API to change all options val
	 * All hooks need to be added before `after_setup_theme`
	 *
	 * @since  2.6
	 * @access public
	 */
	public function hook_options()
	{
		foreach ( $this->_options as $k => $v ) {
			$new_v = apply_filters( "litespeed_option_$k", $v ) ;

			if ( $new_v !== $v ) {
				LiteSpeed_Cache_Log::debug( "[Conf] ** $k changed by hook [litespeed_option_$k] from " . var_export( $v, true ) . ' to ' . var_export( $new_v, true ) ) ;
				$this->_options[ $k ] = $new_v ;
			}
		}
	}

	/**
	 * Force an option to a certain value
	 *
	 * @since  2.6
	 * @access public
	 */
	public function force_option( $k, $v )
	{
		if ( array_key_exists( $k, $this->_options ) ) {
			LiteSpeed_Cache_Log::debug( "[Conf] ** $k forced value to " . var_export( $v, true ) ) ;
			$this->_options[ $k ] = $v ;
		}
	}

	/**
	 * Define `LSCACHE_ADV_CACHE` based on options setting
	 *
	 * NOTE: this must be before `LITESPEED_ON` defination
	 *
	 * @since 2.1
	 * @access private
	 */
	private function _define_adv_cache()
	{
		if ( isset( $this->_options[ self::O_UTIL_CHECK_ADVCACHE ] ) && ! $this->_options[ self::O_UTIL_CHECK_ADVCACHE ] ) {
			! defined( 'LSCACHE_ADV_CACHE' ) && define( 'LSCACHE_ADV_CACHE', true ) ;
		}
	}

	/**
	 * Define `LITESPEED_ON`
	 *
	 * @since 2.1
	 * @access public
	 */
	public function define_cache_on()
	{
		defined( 'LITESPEED_ALLOWED' ) && defined( 'LSCACHE_ADV_CACHE' ) && ! defined( 'LITESPEED_ON' ) && define( 'LITESPEED_ON', true ) ;

		// Use this for cache enabled setting check
		! defined( 'LITESPEED_ON_IN_SETTING' ) && define( 'LITESPEED_ON_IN_SETTING', true ) ;
	}

	/**
	 * Get the list of configured options for the blog.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return array The list of configured options.
	 */
	public function get_options()
	{
		return $this->_options ;
	}

	/**
	 * Get the selected configuration option.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param string $id Configuration ID.
	 * @return mixed Selected option if set, NULL if not.
	 */
	public function get_option( $id )
	{
		if ( isset( $this->_options[ $id ] ) ) {
			return $this->_options[ $id ] ;
		}

		defined( 'LSCWP_LOG' ) && LiteSpeed_Cache_Log::debug( '[Conf] Invalid option ID ' . $id ) ;

		return NULL ;
	}

	/**
	 * Set the configured options.
	 *
	 * NOTE: No validation here. Do validate before use this function with LiteSpeed_Cache_Admin_Settings->validate_plugin_settings().
	 *
	 * @since 1.1.3
	 * @access public
	 * @param array $new_cfg The new settings to update, which will be update $this->_options too.
	 * @return array The result of update.
	 */
	public function update_options( $new_cfg = array() )xx
	{
		if ( ! empty( $new_cfg ) ) {
			$this->_options = array_merge( $this->_options, $new_cfg ) ;
		}
		return update_option( self::OPTION_NAME, $this->_options ) ;
	}

	/**
	 * Check if one user role is in vary group settings
	 *
	 * @since 1.2.0
	 * @access public
	 * @param  string $role The user role
	 * @return int       The set value if already set
	 */
	public function in_vary_group( $role )
	{
		$group = 0 ;
		if ( array_key_exists( $role, $this->vary_groups ) ) {
			$group = $this->vary_groups[ $role ] ;
		}
		elseif ( $role === 'administrator' ) {
			$group = 99 ;
		}

		if ( $group ) {
			LiteSpeed_Cache_Log::debug2( '[Conf] role in vary_group [group] ' . $group ) ;
		}

		return $group ;
	}

	/**
	 * Check if one user role is in exclude optimization group settings
	 *
	 * @since 1.6
	 * @access public
	 * @param  string $role The user role
	 * @return int       The set value if already set
	 */
	public function in_optm_exc_roles( $role = null )
	{
		// Get user role
		if ( $role === null ) {
			$role = LiteSpeed_Cache_Router::get_role() ;
		}

		if ( ! $role ) {
			return false ;
		}

		return in_array( $role, $this->optm_exc_roles ) ? $role : false ;
	}

	/**
	 * Check if one user role is in exclude cache group settings
	 *
	 * @since 1.6.2
	 * @access public
	 * @param  string $role The user role
	 * @return int       The set value if already set
	 */
	public function in_cache_exc_roles( $role = null )
	{
		// Get user role
		if ( $role === null ) {
			$role = LiteSpeed_Cache_Router::get_role() ;
		}

		if ( ! $role ) {
			return false ;
		}

		return in_array( $role, $this->cache_exc_roles ) ? $role : false ;
	}

	/**
	 * Get the configured purge options.
	 *
	 * @since 1.0.0
	 * @access public
	 * @return array The list of purge options.
	 */
	public function get_purge_options()
	{
		return $this->purge_options ;
	}

	/**
	 * Check if the flag type of posts should be purged on updates.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param string $flag Post type. Refer to LiteSpeed_Cache_Config::PURGE_*
	 * @return boolean True if the post type should be purged, false otherwise.
	 */
	public function purge_by_post( $flag )
	{
		return in_array( $flag, $this->purge_options ) ;
	}

	/**
	 * Get item val
	 *
	 * @since 2.2.1
	 * @access public
	 */
	public function get_item( $k, $return_string = false )
	{
		$val = get_option( $k ) ;
		// Separately call default_item() to improve performance
		if ( ! $val ) {
			$val = $this->default_item( $k ) ;
		}

		if ( ! $return_string && ! is_array( $val ) ) {
			$val = $val ? explode( "\n", $val ) : array() ;
		}
		elseif ( $return_string && is_array( $val ) ) {
			$val = implode( "\n", $val ) ;
		}

		return $val ;
	}

	/**
	 * Helper function to convert the options to replicate the input format.
	 *
	 * The only difference is the checkboxes.
	 *
	 * @since 1.0.15
	 * @access public
	 * @param array $options The options array to port to input format.
	 * @return array $options The options array with input format.
	 */
	public static function convert_options_to_input($options)
	{
		foreach ( $options as $key => $val ) {
			if ( $val === true ) {
				$options[$key] = self::VAL_ON ;
			}
			elseif ( $val === false ) {
				$options[$key] = self::VAL_OFF ;
			}
		}
		if ( isset($options[self::O_PURGE_BY_POST xx]) ) {
			$purge_opts = explode('.', $options[self::O_PURGE_BY_POST xx]) ;

			foreach ($purge_opts as $purge_opt) {
				$options['purge_' . $purge_opt] = self::VAL_ON ;
			}
		}

		// Convert CDN settings
		$mapping_fields = array(
			LiteSpeed_Cache_Config::CDN_MAPPING_URL,
			LiteSpeed_Cache_Config::CDN_MAPPING_INC_IMG,
			LiteSpeed_Cache_Config::CDN_MAPPING_INC_CSS,
			LiteSpeed_Cache_Config::CDN_MAPPING_INC_JS,
			LiteSpeed_Cache_Config::CDN_MAPPING_FILETYPE
		) ;
		$cdn_mapping = array() ;
		if ( isset( $options[ self::O_CDN_MAPPING ] ) && is_array( $options[ self::O_CDN_MAPPING ] ) ) {
			foreach ( $options[ self::O_CDN_MAPPING ] as $k => $v ) {// $k is numeric
				foreach ( $mapping_fields as $v2 ) {
					if ( empty( $cdn_mapping[ $v2 ] ) ) {
						$cdn_mapping[ $v2 ] = array() ;
					}
					$cdn_mapping[ $v2 ][ $k ] = ! empty( $v[ $v2 ] ) ? $v[ $v2 ] : false ;
				}
			}
		}
		if ( empty( $cdn_mapping ) ) {
			// At least it has one item same as in setting page
			foreach ( $mapping_fields as $v2 ) {
				$cdn_mapping[ $v2 ] = array( 0 => false ) ;
			}
		}
		$options[ self::O_CDN_MAPPING ] = $cdn_mapping ;

		/**
		 * Convert Cookie Simulation in Crawler settings
		 * @since 2.8.1 Fixed warning and lost cfg when deactivate->reactivate in v2.8
		 */
		$id = self::O_CRWL_COOKIES ;
		$crawler_cookies = array() ;
		if ( isset( $options[ $id ] ) && is_array( $options[ $id ] ) ) {
			$i = 0 ;
			foreach ( $options[ $id ] as $k => $v ) {
				$crawler_cookies[ 'name' ][ $i ] = $k ;
				$crawler_cookies[ 'vals' ][ $i ] = $v ;
				$i ++ ;
			}
		}
		$options[ $id ] = $crawler_cookies ;

		return $options ;
	}

	/**
	 * Get the difference between the current options and the default options.
	 *
	 * @since 1.0.11
	 * @access public
	 * @param array $default_options The default options.
	 * @param array $options The current options.
	 * @return array New options.
	 */
	public static function option_diff($default_options, $options)
	{
		$dkeys = array_keys($default_options) ;
		$keys = array_keys($options) ;
		$newkeys = array_diff($dkeys, $keys) ;
		if ( ! empty($newkeys) ) {
			foreach ( $newkeys as $newkey ) {
				$options[$newkey] = $default_options[$newkey]  ;

				$log = '[Added] ' . $newkey . ' = ' . $default_options[$newkey]  ;
				LiteSpeed_Cache_Log::debug( "[Conf] option_diff $log" ) ;
			}
		}
		$retiredkeys = array_diff($keys, $dkeys)  ;
		if ( ! empty($retiredkeys) ) {
			foreach ( $retiredkeys as $retired ) {
				unset($options[$retired])  ;

				$log = '[Removed] ' . $retired  ;
				LiteSpeed_Cache_Log::debug( "[Conf] option_diff $log" ) ;
			}
		}
		$options[self::_VERSION] = LiteSpeed_Cache::PLUGIN_VERSION ;

		return $options ;
	}

	/**
	 * Upgrade network options when the plugin is upgraded.
	 *
	 * @since 1.0.11
	 * @access public
	 */
	public function plugin_site_upgrade()
	{
		$default_options = $this->get_default_site_options() ;
		$options = $this->get_site_options() ;

		if ( $options[ self::_VERSION ] == $default_options[ self::_VERSION ] && count( $default_options ) == count( $options ) ) {
			return ;
		}

		$options = self::option_diff( $default_options, $options ) ;

		$res = update_site_option( self::OPTION_NAME, $options ) ;

		LiteSpeed_Cache_Log::debug( "[Conf] plugin_upgrade option changed = $res\n" ) ;
	}


	/**
	 * Update the WP_CACHE variable in the wp-config.php file.
	 *
	 * If enabling, check if the variable is defined, and if not, define it.
	 * Vice versa for disabling.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param boolean $enable True if enabling, false if disabling.
	 * @return boolean True if the variable is the correct value, false if something went wrong.
	 */
	public static function wp_cache_var_setter( $enable )
	{
		if ( $enable ) {
			if ( defined( 'WP_CACHE' ) && WP_CACHE ) {
				return true ;
			}
		}
		elseif ( ! defined( 'WP_CACHE' ) || ( defined( 'WP_CACHE' ) && ! WP_CACHE ) ) {
				return true ;
		}

		$file = ABSPATH . 'wp-config.php' ;

		if ( ! is_writeable( $file ) ) {
			$file = dirname( ABSPATH ) . '/wp-config.php' ; // todo: is the path correct?
			if ( ! is_writeable( $file ) ) {
				error_log( 'wp-config file not writable for \'WP_CACHE\'' ) ;
				return LiteSpeed_Cache_Admin_Error::E_CONF_WRITE ;
			}
		}

		$file_content = file_get_contents( $file ) ;

		if ( $enable ) {
			$count = 0 ;

			$new_file_content = preg_replace( '/[\/]*define\(.*\'WP_CACHE\'.+;/', "define('WP_CACHE', true);", $file_content, -1, $count ) ;
			if ( $count == 0 ) {
				$new_file_content = preg_replace( '/(\$table_prefix)/', "define('WP_CACHE', true);\n$1", $file_content ) ;
				if ( $count == 0 ) {
					$new_file_content = preg_replace( '/(\<\?php)/', "$1\ndefine('WP_CACHE', true);", $file_content, -1, $count ) ;
				}

				if ( $count == 0 ) {
					error_log( 'wp-config file did not find a place to insert define.' ) ;
					return LiteSpeed_Cache_Admin_Error::E_CONF_FIND ;
				}
			}
		}
		else {
			$new_file_content = preg_replace( '/define\(.*\'WP_CACHE\'.+;/', "define('WP_CACHE', false);", $file_content ) ;
		}

		file_put_contents( $file, $new_file_content ) ;
		return true ;
	}

	/**
	 * On plugin activation, load the default options.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param int $count The count of blogs active in multisite.
	 */
	public function plugin_activation( $count )
	{


	}

	/**
	 * Set one config value directly
	 *
	 * @since  2.9
	 * @access private
	 */
	private function _set_conf()
	{
		if ( empty( $_GET[ self::TYPE_SET ] ) || ! is_array( $_GET[ self::TYPE_SET ] ) ) {
			return ;
		}

		$options = $this->_options ;
		// Get items
		foreach ( $this->stored_items() xx as $v ) {
			$options[ $v ] = $this->get_item( $v ) ;
		}

		$changed = false ;
		foreach ( $_GET[ self::TYPE_SET ] as $k => $v ) {
			if ( ! isset( $options[ $k ] ) ) {
				continue ;
			}

			if ( is_bool( $options[ $k ] ) ) {
				$v = (bool) $v ;
			}

			// Change for items
			if ( is_array( $v ) && is_array( $options[ $k ] ) ) {
				$changed = true ;

				$options[ $k ] = array_merge( $options[ $k ], $v ) ;

				LiteSpeed_Cache_Log::debug( '[Conf] Appended to item [' . $k . ']: ' . var_export( $v, true ) ) ;
			}

			// Chnage for single option
			if ( ! is_array( $v ) ) {
				$changed = true ;

				$options[ $k ] = $v ;

				LiteSpeed_Cache_Log::debug( '[Conf] Changed [' . $k . '] to ' . var_export( $v, true ) ) ;
			}

		}

		if ( ! $changed ) {
			return ;
		}

		$output = LiteSpeed_Cache_Admin_Settings::get_instance()->validate_plugin_settings( $options, true ) ; // Purge will be auto run in validating items when found diff
		// Save settings now (options & items)
		foreach ( $output as $k => $v ) {
			update_option( self::conf_name( $k ), $v ) ;
		}

		$msg = __( 'Changed setting successfully.', 'litespeed-cache' ) ;
		LiteSpeed_Cache_Admin_Display::succeed( $msg ) ;

		// Redirect if changed frontend URL
		if ( ! empty( $_GET[ 'redirect' ] ) ) {
			wp_redirect( $_GET[ 'redirect' ] ) ;
			exit() ;
		}
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  2.9
	 * @access public
	 */
	public static function handler()
	{
		$instance = self::get_instance() ;

		$type = LiteSpeed_Cache_Router::verify_type() ;

		switch ( $type ) {
			case self::TYPE_SET :
				$instance->_set_conf() ;
				break ;

			default:
				break ;
		}

		LiteSpeed_Cache_Admin::redirect() ;
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 1.1.0
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		if ( ! isset( self::$_instance ) ) {
			self::$_instance = new self() ;
		}

		return self::$_instance ;
	}
}
