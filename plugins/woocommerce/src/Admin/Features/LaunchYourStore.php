<?php

namespace Automattic\WooCommerce\Admin\Features;

use Automattic\WooCommerce\Admin\PageController;
use Automattic\WooCommerce\Blocks\Utils\BlockTemplateUtils;
use Automattic\WooCommerce\Admin\WCAdminHelper;

/**
 * Takes care of Launch Your Store related actions.
 */
class LaunchYourStore {
	const BANNER_DISMISS_USER_META_KEY = 'woocommerce_coming_soon_banner_dismissed';
	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'woocommerce_update_options_site-visibility', array( $this, 'save_site_visibility_options' ) );
		add_filter( 'woocommerce_admin_shared_settings', array( $this, 'preload_settings' ) );
		add_action( 'wp_footer', array( $this, 'maybe_add_coming_soon_banner_on_frontend' ) );
		add_action( 'init', array( $this, 'register_launch_your_store_user_meta_fields' ) );
		add_action( 'wp_login', array( $this, 'reset_woocommerce_coming_soon_banner_dismissed' ), 10, 2 );
	}

	/**
	 * Save values submitted from WooCommerce -> Settings -> General.
	 *
	 * @return void
	 */
	public function save_site_visibility_options() {
		$nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'woocommerce-settings' ) ) {
			return;
		}

		// options to allowed update and their allowed values.
		$options = array(
			'woocommerce_coming_soon'      => array( 'yes', 'no' ),
			'woocommerce_store_pages_only' => array( 'yes', 'no' ),
			'woocommerce_private_link'     => array( 'yes', 'no' ),
		);

		$event_data = array();

		foreach ( $options as $name => $allowed_values ) {
			$current_value = get_option( $name, 'not set' );
			$new_value     = $current_value;

			if ( isset( $_POST[ $name ] ) ) {
				$input_value = sanitize_text_field( wp_unslash( $_POST[ $name ] ) );

				// no-op if input value is invalid.
				if ( in_array( $input_value, $allowed_values, true ) ) {
					update_option( $name, $input_value );
					$new_value = $input_value;

					// log the transition if there is one.
					if ( $current_value !== $new_value ) {
						$enabled_or_disabled              = 'yes' === $new_value ? 'enabled' : 'disabled';
						$event_data[ $name . '_toggled' ] = $enabled_or_disabled;
					}
				}
			}
			$event_data[ $name ] = $new_value;
		}
		wc_admin_record_tracks_event( 'site_visibility_saved', $event_data );
	}

	/**
	 * Preload settings for Site Visibility.
	 *
	 * @param array $settings settings array.
	 *
	 * @return mixed
	 */
	public function preload_settings( $settings ) {
		if ( ! is_admin() ) {
			return $settings;
		}

		$current_screen  = get_current_screen();
		$is_setting_page = $current_screen && 'woocommerce_page_wc-settings' === $current_screen->id;

		if ( $is_setting_page ) {
			// Regnerate the share key if it's not set.
			add_option( 'woocommerce_share_key', wp_generate_password( 32, false ) );

			$settings['siteVisibilitySettings'] = array(
				'shop_permalink'               => get_permalink( wc_get_page_id( 'shop' ) ),
				'woocommerce_coming_soon'      => get_option( 'woocommerce_coming_soon' ),
				'woocommerce_store_pages_only' => get_option( 'woocommerce_store_pages_only' ),
				'woocommerce_private_link'     => get_option( 'woocommerce_private_link' ),
				'woocommerce_share_key'        => get_option( 'woocommerce_share_key' ),
			);
		}

		return $settings;
	}

	/**
	 * User must be an admin or editor.
	 *
	 * @return bool
	 */
	private function is_manager_or_admin() {
		// phpcs:ignore
		if ( ! current_user_can( 'shop_manager' ) && ! current_user_can( 'administrator' ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Add 'coming soon' banner on the frontend when the following conditions met.
	 *
	 * - User must be either an admin or store editor (must be logged in).
	 * - 'woocommerce_coming_soon' option value must be 'yes'
	 * - The page must not be the Coming soon page itself.
	 */
	public function maybe_add_coming_soon_banner_on_frontend() {
		// Do not show the banner if the site is being previewed.
		if ( isset( $_GET['site-preview'] ) ) { // @phpcs:ignore
			return false;
		}

		$current_user_id = get_current_user_id();
		if ( ! $current_user_id ) {
			return false;
		}

		if ( get_user_meta( $current_user_id, self::BANNER_DISMISS_USER_META_KEY, true ) === 'yes' ) {
			return false;
		}

		if ( ! $this->is_manager_or_admin() ) {
			return false;
		}

		// 'woocommerce_coming_soon' must be 'yes'
		if ( get_option( 'woocommerce_coming_soon', 'no' ) !== 'yes' ) {
			return false;
		}

		$store_pages_only = get_option( 'woocommerce_store_pages_only' ) === 'yes';
		if ( $store_pages_only && ! WCAdminHelper::is_store_page() ) {
			return false;
		}

		$link       = admin_url( 'admin.php?page=wc-settings&tab=site-visibility' );
		$rest_url   = rest_url( 'wp/v2/users/' . $current_user_id );
		$rest_nonce = wp_create_nonce( 'wp_rest' );

		$text = sprintf(
			// translators: no need to translate it. It's a link.
			__(
				"
			This page is in \"Coming soon\" mode and is only visible to you and those who have permission. To make it public to everyone,&nbsp;<a href='%s'>change visibility settings</a>
		",
				'woocommerce'
			),
			$link
		);
		// phpcs:ignore
		echo "<div id='coming-soon-footer-banner'>$text<a class='coming-soon-footer-banner-dismiss' data-rest-url='$rest_url' data-rest-nonce='$rest_nonce'></a></div>";
	}

	/**
	 * Register user meta fields for Launch Your Store.
	 */
	public function register_launch_your_store_user_meta_fields() {
		if ( ! $this->is_manager_or_admin() ) {
			return;
		}

		register_meta(
			'user',
			'woocommerce_launch_your_store_tour_hidden',
			array(
				'type'         => 'string',
				'description'  => 'Indicate whether the user has dismissed the site visibility tour on the home screen.',
				'single'       => true,
				'show_in_rest' => true,
			)
		);

		register_meta(
			'user',
			self::BANNER_DISMISS_USER_META_KEY,
			array(
				'type'         => 'string',
				'description'  => 'Indicate whether the user has dismissed the coming soon notice or not.',
				'single'       => true,
				'show_in_rest' => true,
			)
		);
	}

	/**
	 * Reset 'woocommerce_coming_soon_banner_dismissed' user meta to 'no'.
	 *
	 * Runs when a user logs-in successfully.
	 *
	 * @param string $user_login user login.
	 * @param object $user user object.
	 */
	public function reset_woocommerce_coming_soon_banner_dismissed( $user_login, $user ) {
		$existing_meta = get_user_meta( $user->ID, self::BANNER_DISMISS_USER_META_KEY, true );
		if ( 'yes' === $existing_meta ) {
			update_user_meta( $user->ID, self::BANNER_DISMISS_USER_META_KEY, 'no' );
		}
	}
}
