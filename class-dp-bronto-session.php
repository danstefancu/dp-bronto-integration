<?php
class DP_Bronto_Session {
	private $api_url = "http://api.bronto.com/v4";
	private $token;

	/**
	 * @param $token string
	 */
	public function __construct( $token ) {
		$this->wsdl = $this->api_url . '?wsdl';
		$this->token = $token;

		if ( extension_loaded( 'openssl' ) ) {
			$this->wsdl = set_url_scheme( $this->wsdl, 'https' );
		}

		if ( ! extension_loaded( 'soap' ) ) {
			wp_die( __( 'SOAP PHP extension must be installed in order to interact with Bronto API' ), __( 'Server Error' ) );
		}
	}

	/**
	 * @param $func string
	 * @param $args array
	 *
	 * @return bool|mixed false on failure
	 */
	public function __call( $func, $args ) {

		if ( false === ( $session = $this->get_session() ) ) {
			return false;
		}

		try {
			return $session->$func( $args[0] );
		} catch( Exception $e ) {
			wp_die( $e->getMessage(), __( 'Server Error' ) );
			return false;
		}
	}

	/**
	 * @return SoapClient
	 */
	function get_session() {
		static $local_session = false;

		if ( $local_session ) {
			return $local_session;
		}

		$client = new SoapClient( $this->wsdl,
			array(
				'trace' => true,
				'encoding' => 'UTF-8',
				'compression' => true,
				'cache_wsdl' => WSDL_CACHE_BOTH
			)
		);

		$session_id = $client->__soapCall( 'login', array( 'parameters' => array( 'apiToken' => $this->token ) ) )->return;
		$client->__setSoapHeaders( array(
			new SoapHeader( $this->api_url, 'sessionHeader', array( 'sessionId' => $session_id ) )
		) );

		$local_session = $client;

		return $local_session;
	}
}