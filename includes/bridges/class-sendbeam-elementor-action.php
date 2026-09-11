<?php
/**
 * Elementor Pro: a "SendBeam" action after submit for the Form widget.
 *
 * Loaded only when Elementor Pro registers its form actions. The controls are
 * saved with the widget by Elementor itself.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * The SendBeam form action.
 */
class Sendbeam_Elementor_Action extends \ElementorPro\Modules\Forms\Classes\Action_Base {

	/**
	 * Action ID.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'sendbeam';
	}

	/**
	 * Action label in "Actions After Submit".
	 *
	 * @return string
	 */
	public function get_label() {
		return esc_html__( 'SendBeam', 'sendbeam' );
	}

	/**
	 * The action's settings section in the widget.
	 *
	 * @param object $widget The Form widget.
	 */
	public function register_settings_section( $widget ) {
		$widget->start_controls_section(
			'section_sendbeam',
			array(
				'label'     => esc_html__( 'SendBeam', 'sendbeam' ),
				'condition' => array( 'submit_actions' => $this->get_name() ),
			)
		);

		$widget->add_control(
			'sendbeam_email_field',
			array(
				'label'       => esc_html__( 'Email field ID', 'sendbeam' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => 'email',
				'description' => esc_html__( 'The ID from the field\'s Advanced tab.', 'sendbeam' ),
			)
		);
		$widget->add_control(
			'sendbeam_first_field',
			array(
				'label'   => esc_html__( 'Name or first name field ID', 'sendbeam' ),
				'type'    => \Elementor\Controls_Manager::TEXT,
				'default' => 'name',
			)
		);
		$widget->add_control(
			'sendbeam_last_field',
			array(
				'label' => esc_html__( 'Last name field ID (optional)', 'sendbeam' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);
		$widget->add_control(
			'sendbeam_consent',
			array(
				'label'   => esc_html__( 'Consent', 'sendbeam' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'field',
				'options' => array(
					'field'  => esc_html__( 'Only when a consent field is ticked', 'sendbeam' ),
					'signup' => esc_html__( 'Everyone who submits — this is a signup form', 'sendbeam' ),
				),
			)
		);
		$widget->add_control(
			'sendbeam_consent_field',
			array(
				'label'       => esc_html__( 'Consent field ID', 'sendbeam' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'description' => esc_html__( 'An Acceptance or checkbox field.', 'sendbeam' ),
				'condition'   => array( 'sendbeam_consent' => 'field' ),
			)
		);
		$widget->add_control(
			'sendbeam_list',
			array(
				'label'   => esc_html__( 'Add them to', 'sendbeam' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => '',
				'options' => array( '' => esc_html__( '— No list —', 'sendbeam' ) ) + sendbeam_bridge_list_choices(),
			)
		);
		$widget->add_control(
			'sendbeam_tag',
			array(
				'label' => esc_html__( 'Tag (optional)', 'sendbeam' ),
				'type'  => \Elementor\Controls_Manager::TEXT,
			)
		);

		$widget->end_controls_section();
	}

	/**
	 * A submitted form.
	 *
	 * @param object $record       The form record.
	 * @param object $ajax_handler Elementor's response handler; unused, so the visitor's result is never changed.
	 */
	public function run( $record, $ajax_handler ) {
		$settings = (array) $record->get( 'form_settings' );
		$fields   = array();
		foreach ( (array) $record->get( 'fields' ) as $id => $field ) {
			$fields[ (string) $id ] = isset( $field['value'] ) ? $field['value'] : '';
		}

		$config = sendbeam_bridge_config(
			array(
				'enabled'       => 1,
				'list'          => isset( $settings['sendbeam_list'] ) ? $settings['sendbeam_list'] : '',
				'tag'           => isset( $settings['sendbeam_tag'] ) ? $settings['sendbeam_tag'] : '',
				'consent'       => isset( $settings['sendbeam_consent'] ) ? $settings['sendbeam_consent'] : 'field',
				'email_field'   => isset( $settings['sendbeam_email_field'] ) ? $settings['sendbeam_email_field'] : '',
				'first_field'   => isset( $settings['sendbeam_first_field'] ) ? $settings['sendbeam_first_field'] : '',
				'last_field'    => isset( $settings['sendbeam_last_field'] ) ? $settings['sendbeam_last_field'] : '',
				'consent_field' => isset( $settings['sendbeam_consent_field'] ) ? $settings['sendbeam_consent_field'] : '',
			)
		);

		sendbeam_bridge_submit( 'elementor-form', $config, sendbeam_bridge_values_from_map( $fields, $config ) );
	}

	/**
	 * Leave workspace-specific choices out of exported templates.
	 *
	 * @param array $element The exported element.
	 * @return array
	 */
	public function on_export( $element ) {
		unset( $element['settings']['sendbeam_list'], $element['settings']['sendbeam_tag'] );
		return $element;
	}
}
