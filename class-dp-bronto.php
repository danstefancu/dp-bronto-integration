<?php

/**
 * Class DP_Bronto
 *
 * @class    DP_Bronto
 * @version  1.0.0
 */
class DP_Bronto {

	/**
	 * @var DP_Bronto The single instance of the class
	 */
	protected static $_instance = null;

	/**
	 * Main DP_Bronto instance
	 *
	 * Ensures only one instance of DP_Bronto is loaded or can be loaded.
	 *
	 * @static
	 * @see dp_bronto()
	 * @return DP_Bronto - Main instance
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * DP_Bronto constructor
	 * @access public
	 */
	final public function __construct() {

		// An option in general settings
		add_action( 'admin_init', array( $this, 'setup_plugin_options' ), 11 );

		$token = get_option( 'bronto_token', false );

		if ( ! $token ) {
			$this->log( 'Plugin halted. No token provided' );
			add_action( 'admin_notices', array( $this, 'notice_no_token' ) );
			return;
		}

		if ( ! class_exists( 'Groups_User' ) ) {
			$this->log( 'No Groups plugin activated. Will sync with first created Bronto list.' );
		}

		// Only a client call will initiate a Bronto API SOAP connection
		$this->client = new DP_Bronto_Session( $token );

		$this->bronto_fields = $this->get_fields();
		$this->bronto_lists = $this->get_lists();

		do_action( 'dp_bronto_construct', 'before_own_actions' );

		add_action( 'user_register', array( $this, 'add_user' ), 10, 1 );
		add_action( 'profile_update', array( $this, 'update_user' ), 10, 2 );

		add_action( 'groups_created_user_group', array( $this, 'update_user' ), 10, 1 );
		add_action( 'groups_deleted_user_group', array( $this, 'update_user' ), 10, 1 );

		add_action( 'delete_user', array( $this, 'delete_user' ), 10, 1 );

		do_action( 'dp_bronto_construct', 'after_own_actions' );
	}

	/**
	 * Return Bronto fields cached locally for one day.
	 *
	 * @return array
	 */
	protected function get_fields() {
		$fields = get_transient( 'bronto_fields' );

		if ( ! $fields ) {
			$fields = $this->client->readFields( array( 'pageNumber' => 1, 'filter' => array() ) )->return;

			set_transient( 'bronto_fields', $fields, 60 * 60 * 24 );
		}

		return $fields;
	}

	/**
	 * Return Bronto contact lists cached locally for one day.
	 *
	 * @return array
	 */
	protected function get_lists() {
		$lists = get_transient( 'bronto_lists' );

		if ( ! $lists ) {
			$lists = $this->client->readLists( array( 'pageNumber' => 1, 'filter' => array() ) )->return;

			set_transient( 'bronto_lists', $lists, 60 * 60 * 24 );
		}

		return $lists;
	}

	/**
	 * The exact fields that will be sent to bronto are mapped in the fieldIDs variable.
	 * Bronto only accepts his internal ids to save data so here we are preparing it for the save.
	 *
	 * @param $user_id
	 *
	 * @return array
	 */
	protected function prepare_fields( $user_id ) {
		$fields = array();
		foreach( $this->bronto_fields as $field ) {
			$field_value = get_user_meta( $user_id, $field->name, true );
			if ( $field_value ) {
				$fields[] = array(
					"fieldId" => $field->id,
					"content" => $field_value
				);
			}
		}
		return $fields;
	}

	/**
	 * Getting the groups of which a user is member
	 *
	 * @param $user_id
	 *
	 * @return array
	 */
	protected function prepare_lists( $user_id ) {
		$lists = array();

		if ( class_exists( 'Groups_User' ) ) {
			// map lists to groups
			$usr = new Groups_User( $user_id );
			$groups = $usr->__get('groups');

			foreach ( $this->bronto_lists as $list ) {
				foreach( $groups as $group ) {
					if ( $list->name == $group->group->name ) {
						$lists[] = $list->id;
					}
				}
			}
		} else {
			// default to first bronto list
			$lists[] = end( $this->bronto_lists )->id;
			reset( $this->bronto_lists );
		}

		return $lists;
	}

	/**
	 * @param $user_data
	 *
	 * @internal param $user_id
	 *
	 * @return object|stdClass
	 */
	public function get_user( $user_data ) {
		// Don't get user data here, as the info is already saved when we access this
		//$user_data = get_userdata( $user_id );

		if ( ! $user_data->user_email ) {
			$this->log( sprintf( 'Failed get - ID: %d, Message: %s', $user_data->ID, 'No email' ) );
		} else {
			return $this->get_contact( $user_data );
		}

		return new stdClass();
	}

	/**
	 * @param int $user_id
	 */
	public function add_user( $user_id ) {
		$user_data = get_userdata( $user_id );

		if ( ! $user_data->user_email ) {
			$this->log( sprintf( 'Failed Add - ID: %d, Message: %s', $user_id, 'No email' ) );
		} else {
			$this->add_or_update_contact( $user_data );
		}
	}

	/**
	 * @param int $user_id
	 * @param $old_user_data string|object
	 */
	public function update_user( $user_id, $old_user_data = '' ) {
		$user_data = get_userdata( $user_id );

		if ( ! $user_data->user_email ) {

			$this->log( sprintf( 'Failed Update - ID: %d, Message: %s', $user_id, 'No email' ) );

		} elseif ( $old_user_data && $old_user_data->user_email && $old_user_data->user_email != $user_data->user_email ) {
			// has email, change email
			$this->add_or_update_contact( $user_data, $old_user_data );

		} else {

			// has mail, same email, update
			$this->add_or_update_contact( $user_data );
		}
	}

	/**
	 * @param int $user_id
	 */
	public function delete_user( $user_id ) {
		$user_data = get_userdata( $user_id );

		// There are users that have no email address. We will not delete them because they are not added in Bronto
		if ( $user_data->user_email ) {
			$this->log( sprintf( 'Failed Delete - ID: %d, Message: %s', $user_id, 'No email' ) );
		} else {
			$this->delete_contact( $user_data );
		}
	}

	/**
	 * Bronto has internal ids for elements, here we get the internal id for a contact based on the email
	 *
	 * @param WP_User $user_data
	 *
	 * @return object Empty string when not found
	 */
	protected function get_contact( $user_data ) {
		$filter = array(
			'email' => array(
				array(
					'operator' => 'EqualTo',
					'value' => $user_data->user_email
				),
			),
		);

		$result = $this->client->readContacts(
			array(
				'pageNumber' => 1,
				'includeLists' => false,
				'filter' => $filter,
			)
		);

		$message = 'OK Bronto';

		if ( $result->return->results->isError === true )
			$message = $result->return->results->errorString;

		$this->log( sprintf( 'Get - ID: %d, Email: %s, Message: %s', $user_data->ID, $user_data->user_email, $message ) );

		if ( $result->return )
			return $result->return;

		return new stdClass();
	}

	/**
	 * @param $user_data
	 * @param string $old_user_data
	 */
	protected function add_or_update_contact( $user_data, $old_user_data = '' ) {
		$old_user_data = $old_user_data ? $old_user_data : new stdClass();

		$contacts = array(
			"email" => $user_data->user_email,
			"fields" => $this->prepare_fields( $user_data->ID ),
			"listIds" => $this->prepare_lists( $user_data->ID )
		);

		$bronto_id = $old_user_data ? $this->get_user( $old_user_data )->id : $this->get_user( $user_data )->id;

		if ( $bronto_id )
			$contacts['id'] = $bronto_id;

		$result = $this->client->addOrUpdateContacts( array( $contacts ) );

		$message = 'OK Bronto';

		if ( $result->return->results->isError === true )
			$message = $result->return->results->errorString;

		$this->log( sprintf( 'Save - ID: %d, Email: %s, Message: %s', $user_data->ID, $user_data->user_email, $message ) );
	}

	/**
	 * @param $user_data
	 */
	protected function delete_contact( $user_data ) {

		$contact = array(
			"id" => $this->get_contact( $user_data->user_email )
		);
		$result = $this->client->deleteContacts( array( $contact ) );

		$message = 'OK Bronto';

		if ( $result->return->results->isError === true )
			$message = $result->return->results->errorString;

		$this->log( sprintf( 'Delete - ID: %d, Email: %s, Message: %s', $user_data->ID, $user_data->user_email, $message ) );
	}

	/**
	 * Register fields for general options page.
	 */
	public function setup_plugin_options() {
		add_settings_field( 'bronto_token' , __( 'Bronto API Token', 'dp' ), array( $this, 'display_options' ) , 'general' , 'default' );
		register_setting( 'general', 'bronto_token' );
	}

	/**
	 * Display markup for options.
	 */
	public function display_options() {
		include( 'views/options.php' );
	}

	/**
	 * Display markup for missing token admin notice.
	 */
	public function notice_no_token() {
		include( 'views/token.php' );
	}

	/**
	 * @param string $message
	 */
	private function log( $message ) {
		$upload_dir = wp_upload_dir();
		$date = date_i18n( 'Y-m-d H:i:s' ) . " | ";
		error_log( $date . $message . "\r\n", 3, $upload_dir['basedir'] . '/bronto.log' );
	}
}