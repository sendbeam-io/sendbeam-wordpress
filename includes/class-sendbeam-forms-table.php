<?php
/**
 * The Forms list table.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * The workspace's forms: what each one is, and how to put it on a page.
 *
 * Read-only: the forms live in SendBeam and are edited there. That is why
 * there are no bulk actions — an empty `get_bulk_actions()` is core's own way
 * of saying a list has none, and it leaves the bulk controls off the screen
 * rather than offering a dropdown with nothing in it.
 */
class SendBeam_Forms_Table extends WP_List_Table {

	/**
	 * Where a SendBeam form was found on this site, or an empty string.
	 *
	 * @var string
	 */
	private $placement = '';

	/** Set up singular/plural nouns and turn off AJAX. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sendbeam_form',
				'plural'   => 'sendbeam_forms',
				'ajax'     => false,
			)
		);
		$this->placement = sendbeam_form_placement();
	}

	/**
	 * The columns, in order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'name'      => __( 'Form', 'sendbeam' ),
			'kind'      => __( 'Kind', 'sendbeam' ),
			'shortcode' => __( 'Shortcode', 'sendbeam' ),
			'placed'    => __( 'Placed on', 'sendbeam' ),
		);
	}

	/**
	 * Name is the column that survives on a phone.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Nothing here can be changed in bulk, so nothing is offered.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array();
	}

	/**
	 * There are no bulk actions, so there is nothing to process.
	 *
	 * Defined all the same: the loader calls it on every list, and a list
	 * that quietly has no such method is a fatal error waiting for whoever
	 * adds a bulk action to one of the others.
	 */
	public function process_bulk_action() {}

	/** Load, filter and page the rows. */
	public function prepare_items() {
		$forms  = sendbeam_remote_forms();
		$forms  = is_array( $forms ) ? $forms : array();
		$search = sendbeam_list_search();

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$forms  = array_values(
				array_filter(
					$forms,
					function ( $form ) use ( $needle ) {
						$hay = strtolower( (string) ( isset( $form['name'] ) ? $form['name'] : '' ) . ' ' . (string) $form['id'] );
						return false !== strpos( $hay, $needle );
					}
				)
			);
		}

		$per_page = sendbeam_per_page( 'sendbeam_forms_per_page' );
		$page     = max( 1, (int) $this->get_pagenum() );
		$total    = count( $forms );

		$this->items           = array_slice( $forms, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/** What an empty list says, and what to do about it. */
	public function no_items() {
		$forms = sendbeam_remote_forms();
		if ( null === $forms ) {
			// null answers two questions at once — "the key was refused" and
			// "there is no key" — and blaming the permissions of a key that
			// does not exist sends the owner to SendBeam to edit nothing.
			if ( '' === sendbeam_api_key() ) {
				esc_html_e( 'This site is not connected to SendBeam yet, so there are no forms to show. Connect it on the Overview.', 'sendbeam' );
				return;
			}
			esc_html_e( 'Your forms cannot be read with the current key. Give it the Forms (read) permission in SendBeam, or reconnect this site.', 'sendbeam' );
			return;
		}
		if ( '' !== sendbeam_list_search() ) {
			esc_html_e( 'No form here matches that.', 'sendbeam' );
			return;
		}
		echo wp_kses(
			sprintf(
				/* translators: %s: link to create a form in SendBeam */
				__( 'No forms in this workspace yet. %s, and it will appear here.', 'sendbeam' ),
				'<a href="' . esc_url( sendbeam_app_link( '/forms' ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Create your first form', 'sendbeam' ) . '</a>'
			),
			array(
				'a' => array(
					'href'   => array(),
					'target' => array(),
					'rel'    => array(),
				),
			)
		);
	}

	/**
	 * The form's name, and the row actions under it.
	 *
	 * @param array $item One form.
	 * @return string
	 */
	public function column_name( $item ) {
		$id   = (string) $item['id'];
		$name = isset( $item['name'] ) && '' !== $item['name'] ? (string) $item['name'] : __( '(untitled form)', 'sendbeam' );

		$actions = array(
			'preview' => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( sendbeam_form_url( $id, false ) ),
				esc_html__( 'Preview', 'sendbeam' )
			),
			'edit'    => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( sendbeam_app_link( '/forms/' . rawurlencode( $id ) ) ),
				esc_html__( 'Edit in SendBeam', 'sendbeam' )
			),
		);

		return '<strong>' . esc_html( $name ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * Signup or contact.
	 *
	 * @param array $item One form.
	 * @return string
	 */
	public function column_kind( $item ) {
		$kind = isset( $item['kind'] ) ? (string) $item['kind'] : 'signup';
		return '<span class="sb-chip sb-chip--' . esc_attr( 'contact' === $kind ? 'contact' : 'signup' ) . '">' . esc_html( $kind ) . '</span>';
	}

	/**
	 * The shortcode, one click from the clipboard.
	 *
	 * @param array $item One form.
	 * @return string
	 */
	public function column_shortcode( $item ) {
		// Named, both kinds. The column tells the owner to place this form by
		// shortcode, and a shortcode that places a different form cannot
		// carry out its own instruction.
		$kind      = isset( $item['kind'] ) ? (string) $item['kind'] : 'signup';
		$tag       = 'contact' === $kind ? 'sendbeam_contact' : 'sendbeam_form';
		$shortcode = '[' . $tag . ' id="' . (string) $item['id'] . '"]';
		return '<code>' . esc_html( $shortcode ) . '</code> '
			. sprintf(
				'<button type="button" class="sb-btn sb-btn--small sb-btn--ghost sb-copy" data-copy="%1$s" data-done="%2$s">%3$s</button>',
				esc_attr( $shortcode ),
				esc_attr__( 'Copied', 'sendbeam' ),
				esc_html__( 'Copy', 'sendbeam' )
			);
	}

	/**
	 * Whether this site is showing a SendBeam form anywhere.
	 *
	 * The answer is about the site, not about this particular form: a search
	 * for the block name and the shortcodes cannot tell which form a
	 * `[sendbeam_form]` with no id will render. Saying so is better than a
	 * per-row tick that would be wrong half the time.
	 *
	 * @param array $item One form.
	 * @return string
	 */
	public function column_placed( $item ) {
		$settings = sendbeam_settings();
		$id       = strtolower( (string) $item['id'] );
		$default  = strtolower( (string) $settings['default_form'] ) === $id;
		$contact  = strtolower( (string) $settings['contact_form'] ) === $id;

		$where = array(
			'manual'   => __( 'Somewhere you told us about', 'sendbeam' ),
			'popup'    => __( 'A pop-up', 'sendbeam' ),
			'content'  => __( 'A page or post', 'sendbeam' ),
			'template' => __( 'A theme template', 'sendbeam' ),
			'widget'   => __( 'A widget', 'sendbeam' ),
			'meta'     => __( 'A page builder layout', 'sendbeam' ),
		);

		$out = array();
		if ( $default ) {
			$out[] = '<span class="sb-chip">' . esc_html__( 'Default signup form', 'sendbeam' ) . '</span>';
		}
		if ( $contact ) {
			$out[] = '<span class="sb-chip">' . esc_html__( 'Default contact form', 'sendbeam' ) . '</span>';
		}
		if ( ( $default || $contact ) && isset( $where[ $this->placement ] ) ) {
			$out[] = '<span class="sb-note">' . esc_html( $where[ $this->placement ] ) . '</span>';
		}
		if ( ! $out ) {
			$out[] = '<span class="sb-note">' . esc_html__( 'Not a default — place it by shortcode', 'sendbeam' ) . '</span>';
		}
		return implode( ' ', $out );
	}

	/**
	 * Anything without a column_* method.
	 *
	 * @param array  $item   Row.
	 * @param string $column Column name.
	 * @return string
	 */
	public function column_default( $item, $column ) {
		return isset( $item[ $column ] ) ? esc_html( (string) $item[ $column ] ) : '';
	}
}
