<?php
/**
 * Gravity Forms: SendBeam feeds, built on the Gravity Forms feed add-on
 * framework, so each form can have its own field mapping, list, tag and
 * conditional logic under Form Settings → SendBeam.
 *
 * Loaded only after Gravity Forms has loaded its add-on framework.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

// Gravity Forms' add-on framework reads these properties by their underscored names.
// phpcs:disable PSR2.Classes.PropertyDeclaration.Underscore

/**
 * The SendBeam feed add-on.
 */
class Sendbeam_GF_Addon extends GFFeedAddOn {

	/**
	 * Add-on version.
	 *
	 * @var string
	 */
	protected $_version = SENDBEAM_VERSION;

	/**
	 * Oldest Gravity Forms release supported.
	 *
	 * @var string
	 */
	protected $_min_gravityforms_version = '2.5';

	/**
	 * Slug.
	 *
	 * @var string
	 */
	protected $_slug = 'sendbeam';

	/**
	 * Plugin path relative to the plugins folder.
	 *
	 * @var string
	 */
	protected $_path = 'sendbeam/sendbeam.php';

	/**
	 * Full path to the plugin file.
	 *
	 * @var string
	 */
	protected $_full_path = SENDBEAM_FILE;

	/**
	 * Title.
	 *
	 * @var string
	 */
	protected $_title = 'SendBeam';

	/**
	 * Short title, used in menus.
	 *
	 * @var string
	 */
	protected $_short_title = 'SendBeam';

	/**
	 * Who may edit a form's SendBeam feeds: site administrators, because the
	 * feed decides where people's addresses are sent.
	 *
	 * @var string
	 */
	protected $_capabilities_form_settings = 'manage_options';

	/**
	 * The single instance.
	 *
	 * @var Sendbeam_GF_Addon|null
	 */
	private static $instance = null;

	// phpcs:enable PSR2.Classes.PropertyDeclaration.Underscore

	/**
	 * Gravity Forms asks registered add-ons for their instance.
	 *
	 * @return Sendbeam_GF_Addon
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * The feed settings.
	 *
	 * @return array
	 */
	public function feed_settings_fields() {
		$choices = array(
			array(
				'label' => esc_html__( '— No list —', 'sendbeam' ),
				'value' => '',
			),
		);
		foreach ( sendbeam_bridge_list_choices() as $id => $name ) {
			$choices[] = array(
				'label' => $name,
				'value' => $id,
			);
		}

		return array(
			array(
				'title'  => esc_html__( 'SendBeam', 'sendbeam' ),
				'fields' => array(
					array(
						'name'          => 'feedName',
						'label'         => esc_html__( 'Name', 'sendbeam' ),
						'type'          => 'text',
						'required'      => true,
						'class'         => 'medium',
						'default_value' => 'SendBeam',
					),
					array(
						'name'      => 'sendbeamFields',
						'label'     => esc_html__( 'Map fields', 'sendbeam' ),
						'type'      => 'field_map',
						'field_map' => array(
							array(
								'name'       => 'email',
								'label'      => esc_html__( 'Email', 'sendbeam' ),
								'required'   => true,
								'field_type' => array( 'email', 'hidden' ),
							),
							array(
								'name'     => 'first_name',
								'label'    => esc_html__( 'First name', 'sendbeam' ),
								'required' => false,
							),
							array(
								'name'     => 'last_name',
								'label'    => esc_html__( 'Last name', 'sendbeam' ),
								'required' => false,
							),
							array(
								'name'       => 'consent',
								'label'      => esc_html__( 'Consent', 'sendbeam' ),
								'required'   => false,
								'field_type' => array( 'consent', 'checkbox' ),
							),
						),
					),
					array(
						'name'    => 'sendbeamSignup',
						'label'   => esc_html__( 'Signup form', 'sendbeam' ),
						'type'    => 'checkbox',
						'tooltip' => esc_html__( 'Leave this off and map a Consent field, unless the whole point of the form is to subscribe.', 'sendbeam' ),
						'choices' => array(
							array(
								'name'  => 'sendbeamSignup',
								'label' => esc_html__( 'Everyone who submits this form is asking to subscribe, so no consent field is needed', 'sendbeam' ),
							),
						),
					),
					array(
						'name'    => 'sendbeamList',
						'label'   => esc_html__( 'Add them to', 'sendbeam' ),
						'type'    => 'select',
						'choices' => $choices,
					),
					array(
						'name'  => 'sendbeamTag',
						'label' => esc_html__( 'Tag (optional)', 'sendbeam' ),
						'type'  => 'text',
						'class' => 'medium',
					),
					array(
						'name'  => 'feedCondition',
						'label' => esc_html__( 'Conditional logic', 'sendbeam' ),
						'type'  => 'feed_condition',
					),
				),
			),
		);
	}

	/**
	 * Columns in the feed list.
	 *
	 * @return array<string,string>
	 */
	public function feed_list_columns() {
		return array( 'feedName' => esc_html__( 'Name', 'sendbeam' ) );
	}

	/**
	 * Feeds need a key to send with.
	 *
	 * @return bool
	 */
	public function can_create_feed() {
		return '' !== sendbeam_api_key();
	}

	/**
	 * Send one entry.
	 *
	 * @param array $feed  The feed.
	 * @param array $entry The entry.
	 * @param array $form  The form.
	 * @return array The entry, unchanged.
	 */
	public function process_feed( $feed, $entry, $form ) {
		$meta   = isset( $feed['meta'] ) && is_array( $feed['meta'] ) ? $feed['meta'] : array();
		$map    = $this->get_field_map_fields( $feed, 'sendbeamFields' );
		$values = array();
		foreach ( array( 'email', 'first_name', 'last_name', 'consent' ) as $key ) {
			$values[ $key ] = empty( $map[ $key ] ) ? '' : $this->get_field_value( $form, $entry, $map[ $key ] );
		}

		sendbeam_bridge_submit(
			'gravity-forms',
			array(
				'enabled' => 1,
				'list'    => isset( $meta['sendbeamList'] ) ? $meta['sendbeamList'] : '',
				'tag'     => isset( $meta['sendbeamTag'] ) ? $meta['sendbeamTag'] : '',
				'consent' => empty( $meta['sendbeamSignup'] ) ? 'field' : 'signup',
			),
			array(
				'email'      => sendbeam_bridge_text( $values['email'] ),
				'first_name' => sendbeam_bridge_text( $values['first_name'] ),
				'last_name'  => sendbeam_bridge_text( $values['last_name'] ),
				'consented'  => sendbeam_bridge_truthy( $values['consent'] ),
			)
		);

		return $entry;
	}
}
