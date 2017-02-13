<?php

/**
 * Class Monetivo
 * @author Jakub Jasiulewicz <jjasiulewicz@monetivo.com>
 */
class edd_monetivo_gateway
{
    protected $plugin_file = 'monetivo/monetivo-edd.php';
    protected $gateway_id = 'monetivo';
    protected $woocommerce;

    public static $supported_currencies = array( 'PLN' );
    private $plugin_version = '1.0.1';
    private $token_cache = 60 * 4;

    public function init()
    {
        // register gateway
        add_filter( 'edd_payment_gateways', array( $this, 'register_gateway' ) );

        // register gateway settings
        add_filter( 'edd_settings_sections_gateways', array( $this, 'add_settings_section' ) );
        add_filter( 'edd_settings_gateways', array( $this, 'add_settings' ) );

        // disable gateway if certain conditions are met
        add_filter( 'edd_enabled_payment_gateways', array( $this, 'disable_gateway' ) );
        add_filter( 'edd_settings_gateways-monetivo_sanitize', array( $this, 'check_settings' ) );

        // disable credit card form
        add_action( "edd_{$this->gateway_id}_cc_form", '__return_false' );

        // setup callbacks handling
        add_action( "edd_gateway_{$this->gateway_id}", array( $this, 'process_payment' ) );
        add_action( 'process_pingback', array( $this, 'process_pingback' ) );
        add_action( 'init', array( $this, 'listen_pingback' ) );
    }

    /** Disable gateway if certain conditions are met
     * Invoked by the 'edd_enabled_payment_gateways' filter
     * @param $gateways
     * @return mixed
     */
    public function disable_gateway( $gateways )
    {
        $errors = 0;

        // check if options were defined
        if ( ! edd_get_option( 'mvo_edd_app_token' ) || ! edd_get_option( 'mvo_edd_login' ) || ! edd_get_option( 'mvo_edd_password' ) ) {
            $this->add_admin_notice( 'Wtyczka Monetivo dla Easy Digital Downloads nie jest skonfigurowana' );
            $errors++;
        }

        // check if plugin was configured in test mode
        if ( false !== edd_get_option( 'mvo_edd_app_token' ) && false !== strpos( edd_get_option( 'mvo_edd_app_token' ), 'test' ) ) {
            $this->add_admin_notice( 'Wtyczka Monetivo dla Easy Digital Downloads została skonfigurowana w trybie testowym! Pamiętaj o zmianie danych logowania by móc przyjmować rzeczywiste płatności.' );
        }

        if ( $errors > 0 ) {
            unset( $gateways[ $this->gateway_id ] );
        }

        return $gateways;
    }

    /** Checks settings while saving
     * This method also tries to establish connection to Monetivo API thus it validates the credentials
     * Invoked by the 'edd_settings_gateways-monetivo_sanitize' filter
     * @param $settings
     * @return mixed
     */
    public function check_settings( $settings )
    {
        // check settings and add errors
        $settings = array_map( 'trim', $settings );
        $settings = array_map( 'sanitize_text_field', $settings );

        if ( empty( $settings[ 'mvo_edd_app_token' ] ) ) {
            add_settings_error( 'edd-notices', '', 'Monetivo: token aplikacji jest wymagany' );
            unset( $settings[ 'mvo_edd_app_token' ] );
        }

        if ( empty( $settings[ 'mvo_edd_login' ] ) ) {
            add_settings_error( 'edd-notices', '', 'Monetivo: login użytkownika jest wymagany' );
            unset( $settings[ 'mvo_edd_login' ] );
        }

        if ( empty( $settings[ 'mvo_edd_password' ] ) ) {
            add_settings_error( 'edd-notices', '', 'Monetivo: hasło użytkownika jest wymagane' );
            unset( $settings[ 'mvo_edd_password' ] );
        }

        // if some setting was ommited, it was probably invalid. In that case, just return to the Settings page and do not invoke credentials validation
        if ( count( $settings ) < 4 ) {
            return $settings;
        }

        // delete auth_token from cache
        delete_transient( 'mvo_edd_auth_token' );

        // try to establish connection to API with provided settings
        try {
            $client = $this->init_api_client( $settings[ 'mvo_edd_app_token' ], $settings[ 'mvo_edd_login' ], $settings[ 'mvo_edd_password' ] );
            $client->call( 'get', 'auth/check_token' );
            add_settings_error( 'edd-notices', '', 'Monetivo: dane logowania prawidłowe', 'updated' );
        } catch ( \Monetivo\Exceptions\MonetivoException $exception ) {
            $this->write_log( $exception );
            if ( $exception->getHttpCode() === 401 ) {
                add_settings_error( 'edd-notices', '', 'Monetivo: dane logowania są nieprawidłowe' );
            } else {
                add_settings_error( 'edd-notices', '', 'Monetivo: Wystąpił błąd połączenia (' . $exception->getHttpCode() . ')' );
            }
        } catch ( Exception $exception ) {
            $this->write_log( $exception );
            add_settings_error( 'edd-notices', '', 'Wystąpił nieznany błąd' );
        }

        return $settings;
    }

    /** Registers gateway
     * @param $gateways
     * @return mixed
     */
    public function register_gateway( $gateways )
    {
        $gateways[ $this->gateway_id ] = array(
            'admin_label' => 'Monetivo',
            'checkout_label' => edd_get_option( 'mvo_edd_title', __( 'Monetivo', 'monetivo' ) )
        );
        return $gateways;
    }

    /** Adds settings section for a gateway
     * @param $gateway_sections
     * @return array
     */
    public function add_settings_section( $gateway_sections )
    {
        $gateway_sections[ $this->gateway_id ] = __( 'Monetivo', 'monetivo' );

        return $gateway_sections;
    }

    /** Adds settings to the gateway's section
     * @param $gateway_settings
     * @return array
     */
    public function add_settings( $gateway_settings )
    {
        if ( file_exists( __DIR__ . '/form-fields.php' ) ) {
            $gateway_settings[ $this->gateway_id ] = include __DIR__ . '/form-fields.php';
            return $gateway_settings;
        }
    }

    /** Returns supported currencies
     * @return array
     */
    public static function get_supported_currencies()
    {
        return self::$supported_currencies;
    }

    /** Initializes API client
     * @param null $app_token
     * @param null $login
     * @param null $password
     * @return \Monetivo\MerchantApi
     * @throws \Monetivo\Exceptions\MonetivoException
     */
    private function init_api_client( $app_token = null, $login = null, $password = null )
    {
        // fill default settings
        if ( empty( $app_token ) ) {
            $app_token = edd_get_option( 'mvo_edd_app_token' );
        }
        if ( empty( $login ) ) {
            $login = edd_get_option( 'mvo_edd_login' );
        }
        if ( empty( $password ) ) {
            $password = edd_get_option( 'mvo_edd_password' );
        }

        $client = new Monetivo\MerchantApi( $app_token );
        // set platform name with format: monetivo-edd-<WP_version>-<EDD_version>-<Plugin_version>
        $client->setPlatform( sprintf( 'monetivo-edd-%s-%s-%s', get_bloginfo( 'version' ), EDD_VERSION, $this->plugin_version ) );

        // use custom API endpoint if provided as env variable
        $custom_endpoint = getenv( 'MONETIVO_API_ENDPOINT' );
        if ( ! empty( $custom_endpoint ) ) {
            $client->setBaseAPIEndpoint( $custom_endpoint );
        }

        // override API endpoint if sandbox mode was enabled as env variable or test mode was enabled in EDD settings
        $sandbox_mode = getenv( 'MONETIVO_API_SANDBOX' );
        if ( $sandbox_mode ) {
            $client->setSandboxMode();
        }

        // save curl requests to the log if debug was enabled
        if ( WP_DEBUG ) {
            $client->enableLogging( WP_CONTENT_DIR . '/debug.log' );
        }

        // authenticate and save auth_token in Transient API cache unless WP_DEBUG is enabled
        if ( WP_DEBUG || false === ($auth_token = get_transient( 'mvo_edd_auth_token' )) ) {
            $auth_token = $client->auth( $login, $password );
            set_transient( 'mvo_edd_auth_token', $auth_token, $this->token_cache );
        }
        $client->setAuthToken( $auth_token );

        return $client;
    }


    /**
     * Handles callbacks from Monetivo
     */
    public function process_pingback()
    {
        if ( ! empty( $_POST[ 'identifier' ] ) ) {
            $identifier = sanitize_text_field( $_POST[ 'identifier' ] );
            try {
                // obtain details about transaction from Monetivo
                $transaction = $this->init_api_client()->transactions()->details( $identifier );
                $payment_id = $transaction[ 'order_data' ][ 'order_id' ];

                // complete transaction if status is ACCEPTED
                if ( $transaction[ 'status' ] == \Monetivo\Api\Transactions::TRAN_STATUS_ACCEPTED ) {
                    edd_update_payment_status( $payment_id, 'publish' );
                    edd_insert_payment_note( $payment_id, 'Odebrano powiadomienie o płatności z systemu Monetivo' );
                    status_header( 200 );
                    exit;
                }
            } catch ( Exception $e ) {
                $this->write_log( 'Monetivo process_pingback: ' . $identifier . ': ' . $e->getMessage() );
            }
            status_header( 500 );
            exit;
        }

    }

    /**
     * Listen a wp action to process callback
     */
    public function listen_pingback()
    {
        if ( isset( $_GET[ 'edd-listener' ] ) && $_GET[ 'edd-listener' ] == $this->gateway_id ) {
            do_action( 'process_pingback' );
        }
    }


    /** Registers new payment
     * @param array $data
     * @return array
     */
    public function process_payment( $data )
    {
        // check the wp noonce for security reasons
        if ( ! wp_verify_nonce( $data[ 'gateway_nonce' ], 'edd-gateway' ) ) {
            wp_die( __( 'Nonce verification has failed', 'easy-digital-downloads' ), __( 'Error', 'easy-digital-downloads' ), array( 'response' => 403 ) );
        }

        // check the currency
        if ( ! in_array( edd_get_currency(), self::$supported_currencies ) ) {
            edd_set_error( 'unsupported_currency', __( 'Wybrana waluta nie jest obecnie wspierana przez system Monetivo', 'monetivo' ) );
        }

        if ( false !== edd_get_errors() ) {
            edd_send_back_to_checkout( '?payment-mode=' . $data[ 'post_data' ][ 'edd-gateway' ] );
        }

        // collect payment data
        $payment_data = array(
            'price' => $data[ 'price' ],
            'date' => $data[ 'date' ],
            'user_email' => $data[ 'user_email' ],
            'purchase_key' => $data[ 'purchase_key' ],
            'currency' => edd_get_currency(),
            'downloads' => $data[ 'downloads' ],
            'user_info' => $data[ 'user_info' ],
            'cart_details' => $data[ 'cart_details' ],
            'gateway' => $this->gateway_id,
            'status' => 'pending'
        );

        // record the pending payment
        $payment = edd_insert_payment( $payment_data );

        // check payment
        if ( ! $payment ) {
            edd_record_gateway_error( __( 'Payment Error', 'monetivo' ), sprintf( __( 'Payment creation failed before sending buyer to Monetivo. Payment data: %s', 'monetivo' ), json_encode( $payment_data ) ), $payment );
            edd_send_back_to_checkout( '?payment-mode=' . $data[ 'post_data' ][ 'edd-gateway' ] );
        }

        // clear cart
        edd_empty_cart();

        // prepare amount
        $amount = str_replace( ',', '.', $data[ 'price' ] );
        $amount = number_format( $amount, 2, '.', '' );

        // setup urls
        $return_url = add_query_arg( array( 'payment-confirmation' => $this->gateway_id, 'payment-id' => $payment ), get_permalink( edd_get_option( 'success_page', false ) ) );
        $notify_url = add_query_arg( 'edd-listener', $this->gateway_id, trailingslashit( home_url() ) );

        $this->write_log( $notify_url );

        // check client name
        $client_name = (isset( $payment_data[ 'user_info' ][ 'first_name' ] ) ? $payment_data[ 'user_info' ][ 'first_name' ] : '') . ' ' . (isset( $payment_data[ 'user_info' ][ 'last_name' ] ) ? $payment_data[ 'user_info' ][ 'last_name' ] : '');

        // prepare order description
        $desc = __( 'Zamówienie', 'monetivo' ) . ' #' . $payment . ', ' . $client_name . ', ' . date( 'Ymdhi' );

        // gather all parameters together according to the docs
        $params = array(
            'order_data' => [
                'description' => $desc,
                'order_id' => $payment ],
            'buyer' => [
                'name' => $client_name,
                'email' => $payment_data[ 'user_email' ] ],
            'language' => 'pl',
            'currency' => strtoupper( $payment_data[ 'currency' ] ),
            'amount' => $amount,
            'return_url' => $return_url, // GET requests
            'notify_url' => $notify_url // POST requests, handling callback
        );

        try {
            // create transaction in Monetivo
            $transaction = $this->init_api_client()->transactions()->create( $params );
            edd_insert_payment_note( $payment, 'Identyfikator płatności w systemie Monetivo: ' . $transaction[ 'identifier' ] );
            edd_insert_payment_note( $payment, 'Link do dokonania płatności w systemie Monetivo: ' . $transaction[ 'redirect_url' ] );
            wp_redirect( $transaction[ 'redirect_url' ] );
            exit;
        } catch ( Exception $exception ) {
            // something went wrong
            $this->write_log( $exception );
            edd_set_error( 1, __( 'Wystąpił błąd. Prosimy spróbować ponownie później', 'monetivo' ) );
            edd_record_gateway_error( __( 'Payment Error', 'monetivo' ), sprintf( __( 'Payment creation failed before sending buyer to Monetivo. Payment data: %s, Monetivo response: %s', 'monetivo' ), json_encode( $payment_data ), $exception->getMessage() ), $payment );
            edd_send_back_to_checkout( '?payment-mode=' . $data[ 'post_data' ][ 'edd-gateway' ] );
        }
        exit;

    }


    /** Adds admin notice
     * @param $message
     * @param string $class
     */
    public function add_admin_notice( $message, $class = 'notice notice-error' )
    {
        add_action( 'admin_init', function () use ( $class, $message ) {
            $func = function () use ( $class, $message ) {
                $message = __( $message, 'sample-text-domain' );

                printf( '<div class="%1$s"><p>%2$s</p></div>', $class, $message );
            };
            add_action( 'admin_notices', $func );
        } );
    }

    /** Writes messages to log
     * @param $log
     */
    public function write_log( $log )
    {
        if ( ! WP_DEBUG ) {
            return;
        }

        if ( is_array( $log ) || is_object( $log ) ) {
            error_log( 'Monetivo: ' . print_r( $log, true ) );
        } else {
            error_log( 'Monetivo: ' . $log );
        }

    }
}