<?php
/**
 * The optimize css class.
 *
 * @since      	2.3
 */
namespace LiteSpeed;
defined( 'WPINC' ) || exit;

class CSS extends Trunk {
	const TYPE_GENERATE_CRITICAL = 'generate_critical';
	const TYPE_CLEAR_Q = 'clear_q';

	protected $_summary;

	/**
	 * Init
	 *
	 * @since  3.0
	 */
	public function __construct() {
		$this->_summary = self::get_summary();
	}

	/**
	 * Output critical css
	 *
	 * @since  1.3
	 * @access public
	 */
	public function prepare_ccss() {
		// Get critical css for current page
		// Note: need to consider mobile
		$rules = $this->_ccss();
		if ( ! $rules ) {
			return null;
		}

		// Append default critical css
		$rules .= $this->conf( self::O_OPTM_CCSS_CON );

		return '<style id="litespeed-optm-css-rules">' . $rules . '</style>';
	}

	/**
	 * Generate realpath of ccss
	 *
	 * @since  2.3
	 * @access private
	 */
	private function _ccss_realpath( $ccss_type ) {
		return LITESPEED_STATIC_DIR . "/ccss/$ccss_type.css";
	}

	/**
	 * Delete file-based cache folder
	 *
	 * @since  2.3
	 * @access public
	 */
	public function rm_cache_folder( $subsite_id = false ) {
		if ( $subsite_id ) {
			file_exists( LITESPEED_STATIC_DIR . '/ccss/' . $subsite_id ) && File::rrmdir( LITESPEED_STATIC_DIR . '/ccss/' . $subsite_id );
		}
		else {
			file_exists( LITESPEED_STATIC_DIR . '/ccss' ) && File::rrmdir( LITESPEED_STATIC_DIR . '/ccss' );
		}

		// Clear CCSS in queue too
		$this->_summary[ 'queue' ] = array();
		$this->_summary[ 'curr_request' ] = 0;
		self::save_summary();

		Debug2::debug2( '[CSS] Cleared ccss queue' );
	}

	/**
	 * Generate currnt url
	 * @since  3.7
	 */
	private function _curr_url() {
		global $wp;
		return home_url( $wp->request );
	}

	/**
	 * The critical css content of the current page
	 *
	 * @since  2.3
	 */
	private function _ccss() {
		$ccss_type = $this->_which_css();
		$ccss_file = $this->_ccss_realpath( $ccss_type );

		if ( file_exists( $ccss_file ) ) {
			Debug2::debug2( '[CSS] existing ccss ' . $ccss_file );
			return File::read( $ccss_file );
		}

		$request_url = $this->_curr_url();

		// Store it to prepare for cron
		if ( empty( $this->_summary[ 'queue' ] ) ) {
			$this->_summary[ 'queue' ] = array();
		}
		$this->_summary[ 'queue' ][ $ccss_type ] = array(
			'url'			=> $request_url,
			'user_agent'	=> $_SERVER[ 'HTTP_USER_AGENT' ],
			'is_mobile'		=> $this->_separate_mobile_ccss(),
		);// Current UA will be used to request
		Debug2::debug( '[CSS] Added queue [type] ' . $ccss_type . ' [url] ' . $request_url . ' [UA] ' . $_SERVER[ 'HTTP_USER_AGENT' ] );

		// Prepare cache tag for later purge
		Tag::add( 'CCSS.' . $ccss_type );

		self::save_summary();
		return null;
	}

	/**
	 * Check if need to separate ccss for mobile
	 *
	 * @since  2.6.4
	 * @access private
	 */
	private function _separate_mobile_ccss() {
		return ( wp_is_mobile() || apply_filters( 'litespeed_is_mobile', false ) ) && $this->conf( self::O_CACHE_MOBILE );
	}

	/**
	 * Cron ccss generation
	 *
	 * @since  2.3
	 * @access private
	 */
	public static function cron_ccss( $continue = false ) {
		$_instance = self::cls();
		if ( empty( $_instance->_summary[ 'queue' ] ) ) {
			return;
		}

		// For cron, need to check request interval too
		if ( ! $continue ) {
			if ( ! empty( $_instance->_summary[ 'curr_request' ] ) && time() - $_instance->_summary[ 'curr_request' ] < 300 && ! $_instance->conf( self::O_DEBUG ) ) {
				Debug2::debug( '[CCSS] Last request not done' );
				return;
			}
		}

		foreach ( $_instance->_summary[ 'queue' ] as $k => $v ) {
			Debug2::debug( '[CSS] cron job [type] ' . $k . ' [url] ' . $v[ 'url' ] . ( $v[ 'is_mobile' ] ? ' 📱 ' : '' ) . ' [UA] ' . $v[ 'user_agent' ] );

			$_instance->_generate_ccss( $v[ 'url' ], $k, $v[ 'user_agent' ], $v[ 'is_mobile' ] );

			Purge::add( 'CCSS.' . $k );

			// only request first one
			if ( ! $continue ) {
				return;
			}
		}
	}

	/**
	 * Send to LiteSpeed CCSS API to generate CCSS
	 *
	 * @since  2.3
	 * @access private
	 */
	private function _generate_ccss( $request_url, $ccss_type, $user_agent, $is_mobile ) {
		// Check if has credit to push
		$allowance = Cloud::cls()->allowance( Cloud::SVC_CCSS );
		if ( ! $allowance ) {
			Debug2::debug( '[CCSS] ❌ No credit' );
			Admin_Display::error( Error::msg( 'lack_of_quota' ) );
			return;
		}

		$ccss_file = $this->_ccss_realpath( $ccss_type );

		// Update css request status
		$this->_summary[ 'curr_request' ] = time();
		self::save_summary();

		// Gather guest HTML to send
		$html = $this->_prepare_html( $request_url, $user_agent );

		if ( ! $html ) {
			return false;
		}

		// Parse HTML to gather all CSS content before requesting
		list( $css, $html ) = $this->_prepare_css( $html );

		if ( ! $css ) {
			return false;
		}

		// Generate critical css
		$data = array(
			'url'			=> $request_url,
			'ccss_type'		=> $ccss_type,
			'user_agent'	=> $user_agent,
			'is_mobile'		=> $is_mobile ? 1 : 0,
			'html'			=> $html,
			'css'			=> $css,
			'type'			=> 'CCSS',
		);

		Debug2::debug( '[CSS] Generating: ', $data );

		$json = Cloud::post( Cloud::SVC_CCSS, $data, 30 );
		if ( ! is_array( $json ) ) {
			return false;
		}

		if ( empty( $json[ 'ccss' ] ) ) {
			Debug2::debug( '[CSS] ❌ empty ccss' );
			$this->_popup_and_save( $ccss_type, $request_url );
			return false;
		}

		// Add filters
		$ccss = apply_filters( 'litespeed_ccss', $json[ 'ccss' ], $ccss_type );

		// Write to file
		File::save( $ccss_file, $ccss, true );

		// Save summary data
		$this->_summary[ 'last_spent' ] = time() - $this->_summary[ 'curr_request' ];
		$this->_summary[ 'last_request' ] = $this->_summary[ 'curr_request' ];
		$this->_summary[ 'curr_request' ] = 0;
		$this->_popup_and_save( $ccss_type, $request_url );

		Debug2::debug( '[CSS] saved ccss ' . $ccss_file );

		Debug2::debug2( '[CSS] ccss con: ' . $ccss );

		return $ccss;
	}

	/**
	 * Play for fun
	 *
	 * @since  3.4.3
	 */
	public function test_url( $request_url ) {
		$user_agent = $_SERVER[ 'HTTP_USER_AGENT' ];
		$html = $this->_prepare_html( $request_url, $user_agent );
		list( $css, $html ) = $this->_prepare_css( $html, true );
		// var_dump( $css );
// 		$html = <<<EOT

// EOT;

// 		$css = <<<EOT

// EOT;
		$data = array(
			'url'			=> $request_url,
			'ccss_type'		=> 'test',
			'user_agent'	=> $user_agent,
			'is_mobile'		=> 0,
			'html'			=> $html,
			'css'			=> $css,
			'type'			=> 'CCSS',
		);

		// Debug2::debug( '[CSS] Generating: ', $data );

		$json = Cloud::post( Cloud::SVC_CCSS, $data, 180 );

		var_dump($json);
	}

	/**
	 * Prepare HTML from URL
	 *
	 * @since  3.4.3
	 */
	private function _prepare_html( $request_url, $user_agent ) {
		$html = Crawler::cls()->self_curl( add_query_arg( 'LSCWP_CTRL', 'before_optm', $request_url ), $user_agent );
		Debug2::debug2( '[CSS] self_curl result....', $html );


		$html = Optimizer::cls()->html_min( $html, true );
		// Drop <noscript>xxx</noscript>
		$html = preg_replace( '#<noscript>.*</noscript>#isU', '', $html );

		return $html;
	}

	/**
	 * Prepare CSS from HTML
	 *
	 * @since  3.4.3
	 */
	private function _prepare_css( $html, $dryrun =false ) {
		$css = '';
		preg_match_all( '#<link ([^>]+)/?>|<style[^>]*>([^<]+)</style>#isU', $html, $matches, PREG_SET_ORDER );
		foreach ( $matches as $match ) {
			$attrs = false;
			$debug_info = '';
			if ( strpos( $match[ 0 ], '<link' ) === 0 ) {
				$attrs = Utility::parse_attr( $match[ 1 ] );

				if ( empty( $attrs[ 'rel' ] ) ) {
					continue;
				}

				if ( $attrs[ 'rel' ] != 'stylesheet' ) {
					if ( $attrs[ 'rel' ] != 'preload' || empty( $attrs[ 'as' ] ) || $attrs[ 'as' ] != 'style' ) {
						continue;
					}
				}

				if ( ! empty( $attrs[ 'media' ] ) && strpos( $attrs[ 'media' ], 'print' ) !== false ) {
					continue;
				}
				if ( empty( $attrs[ 'href' ] ) ) {
					continue;
				}

				// Check Google fonts hit
				if ( strpos( $attrs[ 'href' ], 'fonts.googleapis.com' ) !== false ) {
					$html = str_replace( $match[ 0 ], '', $html );
					continue;
				}

				$debug_info = $attrs[ 'href' ];

				// Load CSS content
				if ( ! $dryrun ) { // Dryrun will not load CSS but just drop them
					$con = $this->cls( 'Optimizer' )->load_file( $attrs[ 'href' ] );
					if ( ! $con ) {
						continue;
					}
				}
				else {
					$con = '';
				}
			}
			else { // Inline style
				Debug2::debug2( '[CCSS] Load inline CSS ' . substr( $match[ 2 ], 0, 100 ) . '...' );
				$con = $match[ 2 ];

				$debug_info = '__INLINE__';
			}

			$con = Optimizer::minify_css( $con );

			$con = '/* ' . $debug_info . ' */' . $con;

			if ( ! empty( $attrs[ 'media' ] ) && $attrs[ 'media' ] !== 'all' ) {
				$css .= '@media ' . $attrs[ 'media' ] . '{' . $con . "\n}";
			}
			else {
				$css .= $con . "\n";
			}

			$html = str_replace( $match[ 0 ], '', $html );
		}

		return array( $css, $html );
	}

	public function gen_ucss( $page_url, $ua ) {
		return $this->_generate_ucss( $page_url, $ua );
	}

	/**
	 * Send to QC API to generate UCSS
	 *
	 * @since  3.3
	 * @access private
	 */
	private function _generate_ucss( $request_url, $user_agent ) {
		// Check if has credit to push
		$allowance = Cloud::cls()->allowance( Cloud::SVC_CCSS );
		if ( ! $allowance ) {
			Debug2::debug( '[UCSS] ❌ No credit' );
			Admin_Display::error( Error::msg( 'lack_of_quota' ) );
			return;
		}

		// Update UCSS request status
		$this->_summary[ 'curr_request_ucss' ] = time();
		self::save_summary();

		// Generate UCSS
		$data = array(
			'type'			=> 'UCSS',
			'url'			=> $request_url,
			'whitelist'		=> $this->_filter_whitelist(),
			'user_agent'	=> $user_agent,
			'is_mobile'		=> $this->_separate_mobile_ccss(),
		);

		// Append cookie for roles auth
		if ( $uid = get_current_user_id() ) {
			// Get role simulation vary name
			$vary_name = $this->cls( 'Vary' )->get_vary_name();
			$vary_val = $this->cls( 'Vary' )->finalize_default_vary( $uid );
			$data[ 'cookies' ] = array();
			$data[ 'cookies' ][ $vary_name ] = $vary_val;
			$data[ 'cookies' ][ 'litespeed_role' ] = $uid;
			$data[ 'cookies' ][ 'litespeed_hash' ] = Router::get_hash();
		}

		Debug2::debug( '[UCSS] Generating UCSS: ', $data );

		$json = Cloud::post( Cloud::SVC_CCSS, $data, 180 );
		if ( ! is_array( $json ) ) {
			return false;
		}

		if ( empty( $json[ 'ucss' ] ) ) {
			Debug2::debug( '[UCSS] ❌ empty ucss' );
			// $this->_popup_and_save( $ccss_type, $request_url );
			return false;
		}

		$ucss = $json[ 'ucss' ];
		Debug2::debug2( '[UCSS] ucss con: ' . $ucss );

		if ( substr( $ucss, 0, 2 ) == '/*' && substr( $ucss, -2 ) == '*/' ) {
			$ucss = '';
		}
		// Add filters
		$ucss = apply_filters( 'litespeed_ucss', $ucss, $request_url );

		// Write to file
		// File::save( $ucss_file, $ucss, true );

		// Save summary data
		$this->_summary[ 'last_spent_ucss' ] = time() - $this->_summary[ 'curr_request_ucss' ];
		$this->_summary[ 'last_request_ucss' ] = $this->_summary[ 'curr_request_ucss' ];
		$this->_summary[ 'curr_request_ucss' ] = 0;
		self::save_summary();
		// $this->_popup_and_save( $ccss_type, $request_url );

		// Debug2::debug( '[UCSS] saved ucss ' . $ucss_file );

		return $ucss;
	}

	/**
	 * Filter the comment content, add quotes to selector from whitelist. Return the json
	 *
	 * @since 3.3
	 */
	private function _filter_whitelist() {
		$whitelist = array();
		$val = $this->conf( self::O_OPTM_UCSS_WHITELIST );
		foreach ( $val as $k => $v ) {
			if ( substr( $v, 0, 2 ) === '//' ) {
				continue;
			}
			// Wrap in quotes for selectors
			if ( substr( $v, 0, 1 ) !== '/' && strpos( $v, '"' ) === false && strpos( $v, "'" ) === false ) {
				// $v = "'$v'";
			}
			$whitelist[] = $v;
		}

		return $whitelist;
	}

	/**
	 * Pop up the current request and save
	 *
	 * @since  3.0
	 */
	private function _popup_and_save( $ccss_type, $request_url )
	{
		if ( empty( $this->_summary[ 'ccss_type_history' ] ) ) {
			$this->_summary[ 'ccss_type_history' ] = array();
		}
		$this->_summary[ 'ccss_type_history' ][ $ccss_type ] = $request_url;
		unset( $this->_summary[ 'queue' ][ $ccss_type ] );

		self::save_summary();
	}

	/**
	 * Clear all waiting queues
	 *
	 * @since  3.4
	 */
	public function clear_q() {
		if ( empty( $this->_summary[ 'queue' ] ) ) {
			return;
		}

		$this->_summary[ 'queue' ] = array();
		self::save_summary();

		$msg = __( 'Queue cleared successfully.', 'litespeed-cache' );
		Admin_Display::succeed( $msg );
	}

	/**
	 * The critical css file for current page
	 *
	 * @since  2.3
	 * @access private
	 */
	private function _which_css() {
		// $md5_src = md5( $_SERVER[ 'SCRIPT_URI' ] );
		$md5_src = md5( $this->_curr_url() );
		if ( is_404() ) {
			$md5_src = '404';
		}

		$filename = $md5_src;
		if ( is_user_logged_in() ) {
			$role = Router::get_role();
			$filename = $this->cls( 'Vary' )->in_vary_group( $role ) . '_' . $filename;
		}
		if ( is_multisite() ) {
			$filename = get_current_blog_id() . '/' . $filename;
		}

		if ( $this->_separate_mobile_ccss() ) {
			$filename .= '.mobile';
		}

		return $filename;
	}

	/**
	 * Handle all request actions from main cls
	 *
	 * @since  2.3
	 * @access public
	 */
	public function handler() {
		$type = Router::verify_type();

		switch ( $type ) {
			case self::TYPE_GENERATE_CRITICAL :
				self::cron_ccss( true );
				break;

			case self::TYPE_CLEAR_Q :
				$this->clear_q();
				break;

			default:
				break;
		}

		Admin::redirect();
	}

}
