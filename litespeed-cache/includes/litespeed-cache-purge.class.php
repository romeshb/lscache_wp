<?php
/**
 * The plugin purge class for X-LiteSpeed-Purge
 *
 * @since      1.1.3
 * @package    LiteSpeed_Cache
 * @subpackage LiteSpeed_Cache/includes
 * @author     LiteSpeed Technologies <info@litespeedtech.com>
 */
class LiteSpeed_Cache_Purge
{
	private static $_instance ;
	protected static $_pub_purge = array() ;
	protected static $_priv_purge = array() ;
	protected static $_purge_related = false ;
	protected static $_purge_single = false ;

	const X_HEADER = 'X-LiteSpeed-Purge' ;

	/**
	 * Adds new public purge tags to the array of purge tags for the request.
	 *
	 * @since 1.1.3
	 * @access public
	 * @param mixed $tags Tags to add to the list.
	 */
	public static function add( $tags )
	{
		if ( ! is_array( $tags ) ) {
			$tags = array( $tags ) ;
		}
		self::$_pub_purge = array_merge( self::$_pub_purge, $tags ) ;
	}

	/**
	 * Adds new private purge tags to the array of purge tags for the request.
	 *
	 * @since 1.1.3
	 * @access public
	 * @param mixed $tags Tags to add to the list.
	 */
	public static function add_private( $tags )
	{
		if ( ! is_array( $tags ) ) {
			$tags = array( $tags ) ;
		}
		self::$_priv_purge = array_merge( self::$_priv_purge, $tags ) ;
	}

	/**
	 * Activate `purge related tags` for Admin QS.
	 *
	 * @since    1.1.3
	 * @access   public
	 */
	public static function set_purge_related()
	{
		self::$_purge_related = true ;
	}

	/**
	 * Activate `purge single url tag` for Admin QS.
	 *
	 * @since    1.1.3
	 * @access   public
	 */
	public static function set_purge_single()
	{
		self::$_purge_single = true ;
	}

	/**
	 * Check qs purge status
	 *
	 * @since    1.1.3
	 * @access   public
	 */
	public static function get_qs_purge()
	{
		return self::$_purge_single || self::$_purge_related ;
	}

	/**
	 * Alerts LiteSpeed Web Server to purge all pages.
	 *
	 * For multisite installs, if this is called by a site admin (not network admin),
	 * it will only purge all posts associated with that site.
	 *
	 * @since 1.0.0
	 * @access public
	 */
	public static function purge_all()
	{
		self::add( '*' ) ;
		if ( LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ) {
			self::add_private( '*' ) ;
		}

		// check if need to reset crawler
		if ( LiteSpeed_Cache::config( LiteSpeed_Cache_Config::CRWL_CRON_ACTIVE ) ) {
			LiteSpeed_Cache_Crawler::get_instance()->reset_pos() ;
		}
	}

	/**
	 * Alerts LiteSpeed Web Server to purge the front page.
	 *
	 * @since    1.0.3
	 * @access   public
	 */
	public static function purge_front()
	{
		self::add( LiteSpeed_Cache_Tag::TYPE_FRONTPAGE ) ;
		if ( LITESPEED_SERVER_TYPE !== 'LITESPEED_SERVER_OLS' ) {
			self::add_private( LiteSpeed_Cache_Tag::TYPE_FRONTPAGE ) ;
		}
	}

	/**
	 * Alerts LiteSpeed Web Server to purge pages.
	 *
	 * @since    1.0.15
	 * @access   public
	 */
	public static function purge_pages()
	{
		self::add( LiteSpeed_Cache_Tag::TYPE_PAGES ) ;
	}

	/**
	 * Alerts LiteSpeed Web Server to purge error pages.
	 *
	 * @since    1.0.14
	 * @access   public
	 */
	public static function purge_errors()
	{
		self::add( LiteSpeed_Cache_Tag::TYPE_ERROR ) ;
		if ( ! isset( $_POST[LiteSpeed_Cache_Config::OPTION_NAME] ) ) {
			return ;
		}
		$input = $_POST[LiteSpeed_Cache_Config::OPTION_NAME] ;
		if ( isset( $input['include_403'] ) ) {
			self::add( LiteSpeed_Cache_Tag::TYPE_ERROR . '403' ) ;
		}
		if ( isset( $input['include_404'] ) ) {
			self::add( LiteSpeed_Cache_Tag::TYPE_ERROR . '404' ) ;
		}
		if ( isset( $input['include_500'] ) ) {
			self::add( LiteSpeed_Cache_Tag::TYPE_ERROR . '500' ) ;
		}
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected category pages.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The category slug.
	 * @param string $key Unused.
	 */
	public function purgeby_cat_cb( $value, $key )
	{
		$val = trim( $value ) ;
		if ( empty( $val ) ) {
			return ;
		}
		if ( preg_match( '/^[a-zA-Z0-9-]+$/', $val ) == 0 ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_CAT_INV ) ;
			return ;
		}
		$cat = get_category_by_slug( $val ) ;
		if ( $cat == false ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_CAT_DNE, $val ) ;
			return ;
		}

		LiteSpeed_Cache_Admin_Display::add_notice( LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf( __( 'Purge category %s', 'litespeed-cache' ), $val ) ) ;

		self::add( LiteSpeed_Cache_Tag::TYPE_ARCHIVE_TERM . $cat->term_id ) ;
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected post IDs.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The post ID.
	 * @param string $key Unused.
	 */
	public function purgeby_pid_cb( $value, $key )
	{
		$val = trim( $value ) ;
		if ( empty( $val ) ) {
			return ;
		}
		if ( ! is_numeric( $val ) ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_PID_NUM, $val ) ;
			return ;
		}
		elseif ( get_post_status( $val ) !== 'publish' ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_PID_DNE, $val ) ;
			return ;
		}
		LiteSpeed_Cache_Admin_Display::add_notice( LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf( __( 'Purge Post ID %s', 'litespeed-cache' ), $val ) ) ;

		self::add( LiteSpeed_Cache_Tag::TYPE_POST . $val ) ;
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected tag pages.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value The tag slug.
	 * @param string $key Unused.
	 */
	public function purgeby_tag_cb( $value, $key )
	{
		$val = trim( $value ) ;
		if ( empty( $val ) ) {
			return ;
		}
		if ( preg_match( '/^[a-zA-Z0-9-]+$/', $val ) == 0 ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_TAG_INV ) ;
			return ;
		}
		$term = get_term_by( 'slug', $val, 'post_tag' ) ;
		if ( $term == 0 ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_TAG_DNE, $val ) ;
			return ;
		}

		LiteSpeed_Cache_Admin_Display::add_notice( LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf( __( 'Purge tag %s', 'litespeed-cache' ), $val ) ) ;

		self::add( LiteSpeed_Cache_Tag::TYPE_ARCHIVE_TERM . $term->term_id ) ;
	}

	/**
	 * Callback to add purge tags if admin selects to purge selected urls.
	 *
	 * @since 1.0.7
	 * @access public
	 * @param string $value A url to purge.
	 * @param string $key Unused.
	 */
	public function purgeby_url_cb( $value, $key )
	{
		$val = trim( $value ) ;
		if ( empty( $val ) ) {
			return ;
		}

		if ( strpos( $val, '<' ) !== false ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_URL_BAD ) ;
			return ;
		}

		require_once LSWCP_DIR . 'lib/litespeed-php-compatibility.func.php' ;

		// replace site_url if the url is full url
		// NOTE: for subfolder site_url, need to strip subfolder part (strip anything but scheme and host)
		$site_url_domain = http_build_url( LiteSpeed_Cache_Router::get_siteurl(), array(), HTTP_URL_STRIP_ALL ) ;
		if ( strpos( $val, $site_url_domain ) === 0 ) {
			$val = substr( $val, strlen( $site_url_domain ) ) ;
		}

		$hash = LiteSpeed_Cache_Tag::get_uri_tag( $val ) ;

		if ( $hash === false ) {
			LiteSpeed_Cache_Admin_Display::add_error( LiteSpeed_Cache_Admin_Error::E_PURGEBY_URL_INV, $val ) ;
			return ;
		}

		LiteSpeed_Cache_Admin_Display::add_notice( LiteSpeed_Cache_Admin_Display::NOTICE_GREEN, sprintf( __( 'Purge url %s', 'litespeed-cache' ), $val ) ) ;

		self::add( $hash ) ;
		return ;
	}

	/**
	 * Purge a list of pages when selected by admin. This method will
	 * look at the post arguments to determine how and what to purge.
	 *
	 * @since 1.0.7
	 * @access public
	 */
	public function purge_list()
	{
		if ( ! isset($_REQUEST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT]) || ! isset($_REQUEST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_LIST]) ) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGE_FORM) ;
			return ;
		}
		$sel = $_REQUEST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT] ;
		$list_buf = $_REQUEST[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_LIST] ;
		if ( empty($list_buf) ) {
			LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_EMPTY) ;
			return ;
		}
		$list_buf = str_replace(",", "\n", $list_buf) ;// for cli
		$list = explode("\n", $list_buf) ;
		switch($sel) {
			case LiteSpeed_Cache_Admin_Display::PURGEBY_CAT:
				$cb = 'purgeby_cat_cb' ;
				break ;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_PID:
				$cb = 'purgeby_pid_cb' ;
				break ;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_TAG:
				$cb = 'purgeby_tag_cb' ;
				break ;
			case LiteSpeed_Cache_Admin_Display::PURGEBY_URL:
				$cb = 'purgeby_url_cb' ;
				break ;
			default:
				LiteSpeed_Cache_Admin_Display::add_error(LiteSpeed_Cache_Admin_Error::E_PURGEBY_BAD) ;
				return ;
		}
		array_walk($list, Array($this, $cb)) ;

		// for redirection
		$_GET[LiteSpeed_Cache_Admin_Display::PURGEBYOPT_SELECT] = $sel ;
	}

	/**
	 * Purge a post on update.
	 *
	 * This function will get the relevant purge tags to add to the response
	 * as well.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param integer $id The post id to purge.
	 */
	public static function purge_post( $id )
	{
		$post_id = intval($id) ;
		// ignore the status we don't care
		if ( ! in_array(get_post_status($post_id), array( 'publish', 'trash', 'private', 'draft' )) ) {
			return ;
		}

		$purge_tags = self::get_purge_tags_by_post($post_id) ;
		if ( empty($purge_tags) ) {
			return ;
		}
		if ( in_array('*', $purge_tags) ) {
			self::purge_all() ;
		}
		else {
			self::add($purge_tags) ;
		}
		LiteSpeed_Cache_Control::set_stale() ;
	}

	/**
	 * Hooked to the load-widgets.php action.
	 * Attempts to purge a single widget from cache.
	 * If no widget id is passed in, the method will attempt to find the widget id.
	 *
	 * @since 1.1.3
	 * @access public
	 * @param type $widget_id The id of the widget to purge.
	 */
	public static function purge_widget($widget_id = null)
	{
		if ( is_null($widget_id) ) {
			$widget_id = $_POST['widget-id'] ;
			if ( is_null($widget_id) ) {
				return ;
			}
		}
		self::add(LiteSpeed_Cache_Tag::TYPE_WIDGET . $widget_id) ;
		self::add_private(LiteSpeed_Cache_Tag::TYPE_WIDGET . $widget_id) ;
	}

	/**
	 * Hooked to the wp_update_comment_count action.
	 * Purges the comment widget when the count is updated.
	 *
	 * @access public
	 * @since 1.1.3
	 * @global type $wp_widget_factory
	 */
	public static function purge_comment_widget()
	{
		global $wp_widget_factory ;
		$recent_comments = $wp_widget_factory->widgets['WP_Widget_Recent_Comments'] ;
		if ( !is_null($recent_comments) ) {
			self::add(LiteSpeed_Cache_Tag::TYPE_WIDGET . $recent_comments->id) ;
			self::add_private(LiteSpeed_Cache_Tag::TYPE_WIDGET . $recent_comments->id) ;
		}
	}

	/**
	 * Purges feeds on comment count update.
	 *
	 * @since 1.0.9
	 * @access public
	 */
	public static function purge_feeds()
	{
		if ( LiteSpeed_Cache::config(LiteSpeed_Cache_Config::OPID_FEED_TTL) > 0 ) {
			self::add(LiteSpeed_Cache_Tag::TYPE_FEED) ;
		}
	}

	/**
	 * Purges all private cache entries when the user logs out.
	 *
	 * @access public
	 * @since 1.1.3
	 */
	public static function purge_on_logout()
	{
		self::add_private('*') ;
	}

	/**
	 * Generate all purge tags before output
	 *
	 * @access private
	 * @since 1.1.3
	 */
	private static function _finalize()
	{
		do_action('litespeed_cache_api_purge') ;

		// Append unique uri purge tags if Admin QS is `PURGESINGLE`
		if ( self::$_purge_single ) {
			self::$_pub_purge[] = LiteSpeed_Cache_Tag::build_uri_tag() ; // TODO: add private tag too
		}
		// Append related purge tags if Admin QS is `PURGE`
		if ( self::$_purge_related ) {
			// Before this, tags need to be finalized
			$tags_related = LiteSpeed_Cache_Tag::output_tags() ;
			// NOTE: need to remove the empty item `B1_` to avoid purging all
			$tags_related = array_filter($tags_related) ;
			if ( $tags_related ) {
				self::$_pub_purge = array_merge(self::$_pub_purge, $tags_related) ;
			}
		}

		if ( ! empty(self::$_pub_purge) ) {
			self::$_pub_purge = array_unique(self::$_pub_purge) ;
		}

		if ( ! empty(self::$_priv_purge) ) {
			self::$_priv_purge = array_unique(self::$_priv_purge) ;
		}
	}

	/**
	 * Gathers all the purge headers.
	 *
	 * This will collect all site wide purge tags as well as third party plugin defined purge tags.
	 *
	 * @since 1.1.0
	 * @access public
	 * @return string the built purge header
	 */
	public static function output()
	{
		self::_finalize() ;

		if ( empty(self::$_pub_purge) && empty(self::$_priv_purge) ) {
			return '' ;
		}

		$purge_header = '' ;
		$private_prefix = self::X_HEADER . ': private,' ;

		if ( ! empty(self::$_pub_purge) ) {
			$public_tags = self::_build(self::$_pub_purge) ;
			if ( empty($public_tags) ) {
				// If this ends up empty, private will also end up empty
				return '' ;
			}
			$purge_header = self::X_HEADER . ': public,' ;
			if ( LiteSpeed_Cache_Control::is_stale() ) {
				$purge_header .= 'stale,' ;
			}
			$purge_header .= 'tag=' . implode(',', $public_tags) ;
			$private_prefix = ';private,' ;
		}

		if ( empty(self::$_priv_purge) ) {
			return $purge_header ;
		}
		elseif ( in_array('*', self::$_priv_purge) ) {
			$purge_header .= $private_prefix . '*' ;
		}
		else {
			$private_tags = self::_build(self::$_priv_purge) ;
			if ( ! empty($private_tags) ) {
				$purge_header .= $private_prefix . 'tag=' . implode(',', $private_tags) ;
			}
		}

		return $purge_header ;
	}

	/**
	 * Builds an array of purge headers
	 *
	 * @since 1.1.0
	 * @access private
	 * @param array $purge_tags The purge tags to apply the prefix to.
	 * @return array The array of built purge tags.
	 */
	private static function _build($purge_tags)
	{
		$curr_bid = get_current_blog_id() ;

		if ( ! in_array('*', $purge_tags) ) {
			$tags = array() ;
			foreach ($purge_tags as $val) {
				$tags[] = LSWCP_TAG_PREFIX . $curr_bid . '_' . $val ;
			}
			return $tags ;
		}

		if ( defined('LSWCP_EMPTYCACHE') ) {
			return array('*') ;
		}

		// Would only use multisite and network admin except is_network_admin
		// is false for ajax calls, which is used by wordpress updates v4.6+
		if ( is_multisite() && (is_network_admin() || (
				LiteSpeed_Cache_Router::is_ajax() && (check_ajax_referer('updates', false, false) || check_ajax_referer('litespeed-purgeall-network', false, false))
				)) ) {
			$blogs = LiteSpeed_Cache_Activation::get_network_ids() ;
			if ( empty($blogs) ) {
				LiteSpeed_Cache_Log::debug('build_purge_headers: blog list is empty') ;
				return '' ;
			}
			$tags = array() ;
			foreach ($blogs as $blog_id) {
				$tags[] = LSWCP_TAG_PREFIX . $blog_id . '_' ;
			}
			return $tags ;
		}
		else {
			return array(LSWCP_TAG_PREFIX . $curr_bid . '_') ;
		}
	}

	/**
	 * Gets all the purge tags correlated with the post about to be purged.
	 *
	 * If the purge all pages configuration is set, all pages will be purged.
	 *
	 * This includes site wide post types (e.g. front page) as well as
	 * any third party plugin specific post tags.
	 *
	 * @since 1.0.0
	 * @access public
	 * @param integer $post_id The id of the post about to be purged.
	 * @return array The list of purge tags correlated with the post.
	 */
	public static function get_purge_tags_by_post( $post_id )
	{
		// If this is a valid post we want to purge the post, the home page and any associated tags & cats
		// If not, purge everything on the site.

		$purge_tags = array() ;
		$config = LiteSpeed_Cache_Config::get_instance() ;

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_ALL_PAGES) ) {
			// ignore the rest if purge all
			return array( '*' ) ;
		}

		// now do API hook action for post purge
		do_action('litespeed_cache_api_purge_post', $post_id) ;

		// post
		$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_POST . $post_id ;
		$purge_tags[] = LiteSpeed_Cache_Tag::get_uri_tag(wp_make_link_relative(get_permalink($post_id))) ;

		// for archive of categories|tags|custom tax
		global $post ;
		$post = get_post($post_id) ;
		$post_type = $post->post_type ;

		global $wp_widget_factory ;
		$recent_posts = $wp_widget_factory->widgets['WP_Widget_Recent_Posts'] ;
		if ( ! is_null($recent_posts) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_WIDGET . $recent_posts->id ;
		}

		// get adjacent posts id as related post tag
		if( $post_type == 'post' ){
			$prev_post = get_previous_post() ;
			$next_post = get_next_post() ;
			if( ! empty($prev_post->ID) ) {
				$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_POST . $prev_post->ID ;
				LiteSpeed_Cache_Log::debug('--------purge_tags prev is: '.$prev_post->ID) ;
			}
			if( ! empty($next_post->ID) ) {
				$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_POST . $next_post->ID ;
				LiteSpeed_Cache_Log::debug('--------purge_tags next is: '.$next_post->ID) ;
			}
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_TERM) ) {
			$taxonomies = get_object_taxonomies($post_type) ;
			//LiteSpeed_Cache_Log::push('purge by post, check tax = ' . print_r($taxonomies, true)) ;
			foreach ( $taxonomies as $tax ) {
				$terms = get_the_terms($post_id, $tax) ;
				if ( ! empty($terms) ) {
					foreach ( $terms as $term ) {
						$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_ARCHIVE_TERM . $term->term_id ;
					}
				}
			}
		}

		if ( $config->get_option(LiteSpeed_Cache_Config::OPID_FEED_TTL) > 0 ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_FEED ;
		}

		// author, for author posts and feed list
		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_AUTHOR) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_AUTHOR . get_post_field('post_author', $post_id) ;
		}

		// archive and feed of post type
		// todo: check if type contains space
		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_POST_TYPE) ) {
			if ( get_post_type_archive_link($post_type) ) {
				$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_ARCHIVE_POSTTYPE . $post_type ;
			}
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_FRONT_PAGE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_FRONTPAGE ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_HOME_PAGE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_HOME ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_PAGES) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_PAGES ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_PAGES_WITH_RECENT_POSTS) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_PAGES_WITH_RECENT_POSTS ;
		}

		// if configured to have archived by date
		$date = $post->post_date ;
		$date = strtotime($date) ;

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_DATE) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_ARCHIVE_DATE . date('Ymd', $date) ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_MONTH) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_ARCHIVE_DATE . date('Ym', $date) ;
		}

		if ( $config->purge_by_post(LiteSpeed_Cache_Config::PURGE_YEAR) ) {
			$purge_tags[] = LiteSpeed_Cache_Tag::TYPE_ARCHIVE_DATE . date('Y', $date) ;
		}

		return array_unique($purge_tags) ;
	}

	/**
	 * Get the current instance object.
	 *
	 * @since 1.1.3
	 * @access public
	 * @return Current class instance.
	 */
	public static function get_instance()
	{
		$cls = get_called_class() ;
		if ( ! isset(self::$_instance) ) {
			self::$_instance = new $cls() ;
		}

		return self::$_instance ;
	}
}