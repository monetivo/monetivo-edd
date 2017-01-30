<?php
/**
 * /**
 * Monetivo Easy Digital Downloads payment module
 *
 * @author Monetivo
 *
 * Plugin Name: Monetivo Easy Digital Downloads payment gateway
 * Plugin URI: https://monetivo.com
 * Description: Bramka płatności Monetivo do Easy Digital Downloads.
 * Author: Monetivo
 * Author URI: https://monetivo.com
 * Version: 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

require_once plugin_dir_path( __FILE__ ) . 'includes/client/autoload.php';
require_once plugin_dir_path( __FILE__ ) . 'includes/class-edd-monetivo-gateway.php';

/**
 * Activation hook
 */
function activate_monetivo_edd()
{

    if ( is_admin() && current_user_can( 'activate_plugins' ) ) {
        //checking if Easy Digital Downloads plugin is installed

        if ( ! is_plugin_active( 'easy-digital-downloads/easy-digital-downloads.php' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Wtyczka wymaga instalacji oraz aktywacji Easy Digital Downloads', 'Monetivo', [ 'back_link' => true ] );
            return;
        }

        // checking if curl extension is installed
        if ( ! extension_loaded( 'curl' ) || ! function_exists( 'curl_init' ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( 'Wtyczka wymaga instalacji rozszerzenia curl na serwerze', 'Monetivo', [ 'back_link' => true ] );
            return;
        }

        // checking if curl extension is installed
        if ( function_exists( 'edd_get_currency' ) && ! in_array( edd_get_currency(), edd_monetivo_gateway::get_supported_currencies() ) ) {
            deactivate_plugins( plugin_basename( __FILE__ ) );
            wp_die( sprintf( 'Aktualnie ustawiona waluta (%s) nie jest wspierana. Przejdź do ustawień wtyczki Easy Digital Downloads i zmień walutę', edd_get_currency() ), 'Monetivo', [ 'back_link' => true ] );
            return;
        }

        return;
    }

}

/**
 * Deactivation hook
 */
function uninstall_monetivo_edd()
{
    delete_transient( 'mvo_edd_auth_token' );
    wp_cache_flush();
}

/**
 * Initialization hook
 */
function load_monetivo_edd()
{
    if ( ! class_exists( 'Easy_Digital_Downloads' ) )
        return;

    (new edd_monetivo_gateway())->init();

}

register_activation_hook( __FILE__, 'activate_monetivo_edd' );
register_uninstall_hook( __FILE__, 'uninstall_monetivo_edd' );
add_action( 'plugins_loaded', 'load_monetivo_edd', 0 );
define( 'EDD_MONETIVO_PLUGIN_PATH', __FILE__ );
define( 'EDD_MONETIVO_URI', plugin_dir_url( __FILE__ ) );



