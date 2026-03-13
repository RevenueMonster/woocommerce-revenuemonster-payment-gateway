<?php
/**
 * Plugin Name: WooCommerce RevenueMonster Payment Gateway
 * Description: Accept all major Malaysia e-wallet, such as TnG eWallet, Boost, Maybank QRPay & credit cards. Fast, seamless, and flexible.
 * Author: RevenueMonster
 * Author URI: https://revenuemonster.my/
 * Version: 1.0.9
 * WC requires at least: 3.0
 * WC tested up to: 8.2
 * Requires Plugins: woocommerce
 *
 * @package WooCommerce_RevenueMonster_Payment_Gateway
 */

if ( ! function_exists( 'array_ksort' ) ) {
	/**
	 * Function array_ksort
	 *
	 * @param array $array Array.
	 */
	function array_ksort( &$array ) {
		if ( count( $array ) > 0 ) {
			foreach ( $array as $k => $v ) {
				if ( is_array( $v ) ) {
					$array[ $k ] = array_ksort( $v );
				}
			}

			ksort( $array );
		}
		return $array;
	}
}

if ( ! function_exists( 'random_str' ) ) {
	/**
	 * Function random_str
	 *
	 * @param string $length Length.
	 * @param string $type Seed.
	 */
	function random_str( $length = 8, $type = 'alphanum' ) {
		switch ( $type ) {
			case 'basic':
				return mt_rand();
				break;
			case 'alpha':
			case 'alphanum':
			case 'num':
			case 'nozero':
				$seedings             = array();
				$seedings['alpha']    = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
				$seedings['alphanum'] = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
				$seedings['num']      = '0123456789';
				$seedings['nozero']   = '123456789';

				$pool = $seedings[ $type ];

				$str = '';
				for ( $i = 0; $i < $length; $i++ ) {
					$str .= substr( $pool, mt_rand( 0, strlen( $pool ) - 1 ), 1 );
				}
				return $str;
				break;
			case 'unique':
			case 'md5':
				return md5( uniqid( mt_rand() ) );
				break;
		}
	}
}

if ( ! function_exists( 'escape_url' ) ) {
	/**
	 * Function escape_url
	 *
	 * @param string $url URL.
	 */
	function escape_url( $url = '' ) {
		$url     = parse_url( $url );
		$fulluri = '';
		if ( array_key_exists( 'scheme', $url ) ) {
			$fulluri = $fulluri . $url['scheme'] . '://';
		}
		if ( array_key_exists( 'host', $url ) ) {
			$fulluri = $fulluri . $url['host'];
		}
		if ( array_key_exists( 'path', $url ) ) {
			$fulluri = $fulluri . $url['path'];
		}
		if ( array_key_exists( 'query', $url ) ) {
			$query   = urldecode( $url['query'] );
			$fulluri = $fulluri . '?' . urlencode( $query );
		}

		return $fulluri;
	}
}

/**
 * Class RevenueMonster
 */
class RevenueMonster {
	/**
	 * Domains
	 *
	 * @var oauth
	 */
	private static $domains = array(
		'oauth' => 'oauth.revenuemonster.my',
		'api'   => 'open.revenuemonster.my',
	);
	/**
	 * Instance
	 *
	 * @var Instance
	 */
	private static $instance = null;
	/**
	 * ClientId
	 *
	 * @var client_id
	 */
	private $client_id = '';
	/**
	 * ClientSecret
	 *
	 * @var client_secret
	 */
	private $client_secret = '';
	/**
	 * AccessToken
	 *
	 * @var access_token
	 */
	public $access_token = '';
	/**
	 * RefreshToken
	 *
	 * @var refresh_token
	 */
	public $refresh_token = '';
	/**
	 * PrivateKey
	 *
	 * @var private_key
	 */
	private $private_key = '';
	/**
	 * PublicKey
	 *
	 * @var public_key
	 */
	private $public_key = '';
	/**
	 * IsSandbox
	 *
	 * @var is_sandbox
	 */
	private $is_sandbox = true;
	/**
	 * RefreshTime
	 *
	 * @var refresh_time
	 */
	private $refresh_time;

	/**
	 * Construct
	 *
	 * @param array $arguments Arguments.
	 */
	private function __construct( $arguments = array() ) {
		foreach ( $arguments as $property => $argument ) {
			if ( ! property_exists( $this, $property ) ) {
				continue;
			}
			if ( gettype( $this->{$property} ) != gettype( $argument ) ) {
				continue;
			}
			$this->{$property} = $argument;
		}

		$this->oauth();
	}

	/**
	 * Static function get_instance
	 *
	 * @param array $arguments Arguments.
	 */
	public static function get_instance( $arguments = array() ) {
		if ( null == self::$instance ) {
			self::$instance = new RevenueMonster( $arguments );
		}

		return self::$instance;
	}

	/**
	 * Function oauth
	 */
	private function oauth() {
		$transient_key = 'revenuemonster_oauth_' . md5( $this->client_id );
		$cache = get_transient($transient_key);

		if (is_array($cache) && !empty($cache['access_token']) && !empty($cache['expires_at']) && time() < intval($cache['expires_at'])) {
			error_log('[RM] Using cached OAuth token');

			$this->access_token = $cache['access_token'];
			$this->refresh_token = isset($cache['refresh_token']) ? $cache['refresh_token'] : '';
			return;
		}

		error_log('[RM] Requesting NEW OAuth token from API');

		$uri  = $this->get_open_api_url( 'v1', '/token', 'oauth' );
		$hash = base64_encode( $this->client_id . ':' . $this->client_secret );

		$response = wp_remote_post(
			$uri,
			array(
				'headers'   => array(
					'Authorization' => "Basic $hash",
					'Content-Type'  => 'application/json',
				),
				'body'      => json_encode(
					array(
						'grantType' => 'client_credentials',
					)
				),
				'timeout'   => 90,
				'sslverify' => false,
			)
		);

		if ( is_wp_error( $response ) ) {
			error_log('[RM] OAuth request failed');
			return;
		}

		$body = wp_remote_retrieve_body($response);
		$body = json_decode($body, true);

		if (!is_array($body) || empty($body['accessToken'])) {
			return;
		}

		$accessToken = $body['accessToken'];
		$refreshToken = isset($body['refreshToken']) ? $body['refreshToken'] : '';
		$expiresIn = isset($body['expiresIn']) ? intval($body['expiresIn']) : 86400;

		error_log('[RM] Received new OAuth token. Expires in: ' . $expiresIn . ' seconds');

		// Add buffer before actual expiry
		$buffer_seconds = 60;
		$cached_for = max(60, $expiresIn - $buffer_seconds);
		$expires_at = time() + $cached_for;

		$this->access_token  = $accessToken;
		$this->refresh_token = $refreshToken;

		$cache_value = array(
			'access_token' => $accessToken,
			'refresh_token' => $refreshToken,
			'expires_at' => $expires_at,
		);

		set_transient($transient_key, $cache_value, $cached_for);
	}

	/**
	 * Function get_domain
	 *
	 * @param string $usage Usage.
	 */
	public function get_domain( $usage ) {
		$domain = self::$domains['api'];
		if ( array_key_exists( $usage, self::$domains ) ) {
			$domain = self::$domains[ $usage ];
		}
		return $domain;
	}

	/**
	 * Function get_open_api_url
	 *
	 * @param string $version Version.
	 * @param string $usage Usage.
	 */
	public function get_open_api_url( $version = 'v1', $url, $usage = 'api' ) {
		$url = trim( $url, '/' );
		$uri = "{$this->get_domain($usage)}/$version/$url";
		if ( $this->is_sandbox ) {
			$uri = "sb-$uri";
		}
		return "https://$uri";
	}

	/**
	 * Function get_access_token
	 */
	public function get_access_token() {
		return $this->access_token;
	}

	/**
	 * Function get_private_key
	 */
	public function get_private_key() {
		return $this->private_key;
	}

	/**
	 * Function call_api
	 *
	 * @param string $method Method.
	 * @param string $url Url.
	 * @param string $payload Payload.
	 */
	private function call_api( $method, $url, $payload = null ) {
		$method      = strtoupper( $method );
		$access_token = $this->get_access_token();
		$nonce_str    = random_str( 32 );
		$timestamp   = time();
		$signature   = $this->generate_signature( $method, $url, $nonce_str, $timestamp, $payload );

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Content-Type'  => 'application/json',
				'Authorization' => "Bearer $access_token",
				'X-Signature'   => "sha256 $signature",
				'X-Nonce-Str'   => strval( $nonce_str ),
				'X-Timestamp'   => strval( $timestamp ),
			),
			'timeout' => 90,
		);

		if ( 'GET' === $method && ! empty( $payload ) ) {
			$args['body'] = http_build_query( $payload );
		} else if ( 'GET' !== $method ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );

		$http_code = null;
		if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
			$http_code = intval( $response['response']['code'] );
		}

		if ( $http_code === 401 ) {
			error_log('[RM] Token expired or invalid. Refreshing OAuth token.');

			$transient_key = 'revenuemonster_oauth_' . md5($this->client_id);
			delete_transient($transient_key);
			$this->oauth();

			error_log('[RM] Retrying API request with refreshed token');

			$args['headers']['Authorization'] = 'Bearer ' . $this->get_access_token();
			$response = wp_remote_request( $url, $args );
	
			if ( is_array( $response ) && isset( $response['response']['code'] ) ) {
				$http_code = intval( $response['response']['code'] );
			}
		}

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( empty( $response['body'] ) ) {
			return new WP_Error( 'revenuemonster-api', 'Empty Response' );
		}

		$body = wp_remote_retrieve_body( $response );
		return json_decode( $body );
	}

	/**
	 * Function create_order
	 *
	 * @param string $payload Payload.
	 * @throws \Exception Error code.
	 */
	public function create_order( $payload ) {
		$response = $this->call_api(
			'POST',
			$this->get_open_api_url( 'v3', '/payment/online', 'api' ),
			$payload
		);

		if ( ! isset( $response ) ) {
			throw new Exception( 'empty response' );
		}

		if ( isset( $response->error ) ) {
			throw new Exception( $response->error->code . print_r( $response->error ) );
		}

		return $response->item;
	}

	/**
	 * Function query_order
	 *
	 * @param string $order_id Order ID.
	 * @throws \Exception Error code.
	 */
	public function query_order( $order_id ) {
		$response = $this->call_api(
			'GET',
			$this->get_open_api_url( 'v3', "/payment/transaction/order/$order_id", 'api' )
		);

		if ( ! isset( $response ) ) {
			throw new Exception( 'empty response' );
		}

		if ( isset( $response->error ) ) {
			throw new Exception( $response->error->code );
		}

		return $response->item;
	}

	/**
	 * Function generate_signature
	 *
	 * @param string $method Method.
	 * @param string $url Url.
	 * @param string $nonce_str Nonce str.
	 * @param string $timestamp Timestamp.
	 * @param string $payload Payload.
	 */
	public function generate_signature( $method, $url, $nonce_str, $timestamp, $payload = null ) {
		$method   = strtolower( $method );
		$res      = openssl_pkey_get_private( $this->private_key );
		$sign_type = 'sha256';

		$arr = array();
		if ( is_array( $payload ) && ! empty( $payload ) ) {
			$data = '';
			if ( ! empty( $payload ) ) {
				array_ksort( $payload );
				$data = base64_encode( json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_TAG | JSON_HEX_AMP ) );
			}
			array_push( $arr, "data=$data" );
		}

		array_push( $arr, "method=$method" );
		array_push( $arr, "nonceStr=$nonce_str" );
		array_push( $arr, "requestUrl=$url" );
		array_push( $arr, "signType=$sign_type" );
		array_push( $arr, "timestamp=$timestamp" );

		$signature = '';
		openssl_sign( join( '&', $arr ), $signature, $res, OPENSSL_ALGO_SHA256 );
		openssl_free_key( $res );
		$signature = base64_encode( $signature );
		return $signature;
	}
}
