<?php
/**
 * The Audience lists list table.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * The workspace's lists, and how many people are on each.
 *
 * Read-only for the same reason the Forms list is: lists live in SendBeam.
 */
class SendBeam_Lists_Table extends WP_List_Table {

	/** Set up singular/plural nouns and turn off AJAX. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sendbeam_list',
				'plural'   => 'sendbeam_lists',
				'ajax'     => false,
			)
		);
	}

	/**
	 * The columns, in order.
	 *
	 * @return array<string,string>
	 */
	public function get_columns() {
		return array(
			'name'         => __( 'List', 'sendbeam' ),
			'count'        => __( 'Subscribers', 'sendbeam' ),
			'confirmation' => __( 'Confirmation', 'sendbeam' ),
		);
	}

	/**
	 * The list's name.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'name';
	}

	/**
	 * Nothing here can be changed in bulk.
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
		$lists  = sendbeam_lists();
		$lists  = is_array( $lists ) ? $lists : array();
		$search = sendbeam_list_search();

		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$lists  = array_values(
				array_filter(
					$lists,
					function ( $list ) use ( $needle ) {
						return false !== strpos( strtolower( (string) $list['name'] ), $needle );
					}
				)
			);
		}

		$per_page = sendbeam_per_page( 'sendbeam_lists_per_page' );
		$page     = max( 1, (int) $this->get_pagenum() );
		$total    = count( $lists );

		$this->items           = array_slice( $lists, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/** What an empty list of lists says. */
	public function no_items() {
		$lists = sendbeam_lists();
		if ( null === $lists ) {
			// See the note on the same branch in the forms table: a site with
			// no key is not a site with a key missing a permission.
			if ( '' === sendbeam_api_key() ) {
				esc_html_e( 'This site is not connected to SendBeam yet, so there are no lists to show. Connect it on the Overview.', 'sendbeam' );
				return;
			}
			esc_html_e( 'Your lists cannot be read with the current key. Give it the Lists (read) permission in SendBeam, or reconnect this site.', 'sendbeam' );
			return;
		}
		if ( '' !== sendbeam_list_search() ) {
			esc_html_e( 'No list here matches that.', 'sendbeam' );
			return;
		}
		esc_html_e( 'This workspace has no lists yet. Connecting a site creates one called Subscribers; you can add more in SendBeam.', 'sendbeam' );
	}

	/**
	 * The list's name, with a link to it in SendBeam.
	 *
	 * @param array $item One list.
	 * @return string
	 */
	public function column_name( $item ) {
		$actions = array(
			'open' => sprintf(
				'<a href="%s" target="_blank" rel="noopener">%s</a>',
				esc_url( sendbeam_app_link( '/lists/' . rawurlencode( (string) $item['id'] ) ) ),
				esc_html__( 'Open in SendBeam', 'sendbeam' )
			),
		);
		return '<strong>' . esc_html( (string) $item['name'] ) . '</strong>' . $this->row_actions( $actions );
	}

	/**
	 * How many people are on it.
	 *
	 * @param array $item One list.
	 * @return string
	 */
	public function column_count( $item ) {
		return '<span class="sb-mono">' . ( null === $item['count'] ? '&mdash;' : esc_html( number_format_i18n( $item['count'] ) ) ) . '</span>';
	}

	/**
	 * Single or double opt-in.
	 *
	 * @param array $item One list.
	 * @return string
	 */
	public function column_confirmation( $item ) {
		if ( ! empty( $item['double_optin'] ) ) {
			return '<span class="sb-chip">' . esc_html__( 'Double opt-in', 'sendbeam' ) . '</span>';
		}
		return '<span class="sb-note">' . esc_html__( 'Single opt-in', 'sendbeam' ) . '</span>';
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
