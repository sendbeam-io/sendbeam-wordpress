<?php
/**
 * The parts of Contact Form 7, Elementor Pro, WPForms, Gravity Forms and
 * Fluent Forms the bridges touch, shaped like the real classes and functions.
 * Loaded part-way through the smoke test, after checking that nothing loads
 * while these plugins are absent.
 */

namespace ElementorPro\Modules\Forms\Classes {
	abstract class Action_Base {}
}

namespace Elementor {
	class Controls_Manager {
		const TEXT   = 'text';
		const SELECT = 'select';
	}
}

namespace {
	define( 'WPCF7_VERSION', '6.0' );
	define( 'ELEMENTOR_PRO_VERSION', '3.30.0' );
	define( 'FLUENTFORM', true );

	/** Contact Form 7's submission singleton. */
	class WPCF7_Submission {
		public static $posted = array();
		public static function get_instance() { return new self(); }
		public function get_posted_data() { return self::$posted; }
	}

	/** Gravity Forms' loader and add-on framework. */
	class GFForms {
		public static function include_feed_addon_framework() { $GLOBALS['stub']['gf_framework'] = true; }
	}
	abstract class GFAddOn {
		public static function register( $class ) { $GLOBALS['stub']['gf_registered'][] = $class; }
	}
	abstract class GFFeedAddOn extends GFAddOn {
		// Gravity Forms stores a field map as "<setting>_<key>" in the feed meta.
		public function get_field_map_fields( $feed, $name ) {
			$out = array();
			foreach ( $feed['meta'] as $key => $value ) {
				if ( 0 === strpos( $key, $name . '_' ) ) { $out[ substr( $key, strlen( $name ) + 1 ) ] = $value; }
			}
			return $out;
		}
		public function get_field_value( $form, $entry, $field_id ) { return isset( $entry[ $field_id ] ) ? $entry[ $field_id ] : ''; }
	}

	/** WPForms. */
	function wpforms() { return true; }
	function wpforms_panel_field( $type, $group, $key, $form_data, $label, $args = array() ) {
		$GLOBALS['stub']['wpforms_fields'][ $key ] = array( 'type' => $type, 'args' => $args );
	}

	/** Elementor Pro's registrar, Form widget and form record. */
	class Sendbeam_Test_Registrar {
		public $actions = array();
		public function register( $action ) { $this->actions[] = $action; }
	}
	class Sendbeam_Test_Widget {
		public $controls = array();
		public function start_controls_section( $id, $args ) { $this->controls[ $id ] = $args; }
		public function add_control( $id, $args ) { $this->controls[ $id ] = $args; }
		public function end_controls_section() {}
	}
	class Sendbeam_Test_Record {
		private $data;
		public function __construct( $data ) { $this->data = $data; }
		public function get( $key ) { return $this->data[ $key ]; }
	}
}
