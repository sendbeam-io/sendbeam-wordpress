<?php
/**
 * The Email log list table.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * What this site has recently sent through SendBeam.
 *
 * The log is an option holding the last twenty attempts: enough to answer
 * "did my password reset go out?" without turning a settings screen into a
 * mail archive. There is no Resend row action because the log keeps what was
 * sent *to* and *about*, never the message body — a Resend button that could
 * only send a different email is worse than no button.
 */
class SendBeam_Mail_Log_Table extends WP_List_Table {

	/** Set up singular/plural nouns and turn off AJAX. */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'sendbeam_message',
				'plural'   => 'sendbeam_messages',
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
			'cb'      => '<input type="checkbox" />',
			'date'    => __( 'Date', 'sendbeam' ),
			'to'      => __( 'To', 'sendbeam' ),
			'subject' => __( 'Subject', 'sendbeam' ),
			'result'  => __( 'Result', 'sendbeam' ),
			'note'    => __( 'Note', 'sendbeam' ),
		);
	}

	/**
	 * Date is what identifies a row here; every other column repeats.
	 *
	 * @return string
	 */
	protected function get_default_primary_column_name() {
		return 'date';
	}

	/**
	 * Clearing the log is the one thing that can be done to it.
	 *
	 * @return array<string,string>
	 */
	protected function get_bulk_actions() {
		return array( 'delete' => __( 'Delete', 'sendbeam' ) );
	}

	/**
	 * Delete the ticked rows.
	 *
	 * Runs before the rows are read, on `load-<hook>`, so the table the
	 * screen renders is the one the delete left behind rather than the one
	 * it started with. The nonce is core's own `bulk-<plural>`, which
	 * `display_tablenav()` prints for us.
	 */
	public function process_bulk_action() {
		if ( 'delete' !== $this->current_action() ) {
			return;
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$chosen = isset( $_REQUEST['message'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['message'] ) ) : array();
		if ( ! $chosen ) {
			return;
		}

		$log = get_option( 'sendbeam_mail_log', array() );
		if ( ! is_array( $log ) ) {
			return;
		}
		foreach ( $chosen as $index ) {
			unset( $log[ $index ] );
		}
		update_option( 'sendbeam_mail_log', array_values( $log ), false );

		$GLOBALS['sendbeam_deleted'] = count( $chosen );
	}

	/** Load, filter and page the rows. */
	public function prepare_items() {
		$log = get_option( 'sendbeam_mail_log', array() );
		$log = is_array( $log ) ? array_values( $log ) : array();

		// Each row carries its position in the stored log, because that is
		// what a bulk delete has to name: two messages can share a second,
		// an address and a subject.
		foreach ( $log as $i => $row ) {
			$log[ $i ]['index'] = $i;
		}

		$search = sendbeam_list_search();
		if ( '' !== $search ) {
			$needle = strtolower( $search );
			$log    = array_values(
				array_filter(
					$log,
					function ( $row ) use ( $needle ) {
						$hay = strtolower( (string) $row['to'] . ' ' . (string) $row['subject'] . ' ' . (string) $row['note'] );
						return false !== strpos( $hay, $needle );
					}
				)
			);
		}

		$per_page = sendbeam_per_page( 'sendbeam_mail_log_per_page' );
		$page     = max( 1, (int) $this->get_pagenum() );
		$total    = count( $log );

		$this->items           = array_slice( $log, ( $page - 1 ) * $per_page, $per_page );
		$this->_column_headers = array( $this->get_columns(), array(), array(), $this->get_default_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $total / max( 1, $per_page ) ),
			)
		);
	}

	/** What an empty log says. */
	public function no_items() {
		if ( '' !== sendbeam_list_search() ) {
			esc_html_e( 'No message here matches that.', 'sendbeam' );
			return;
		}
		esc_html_e( 'Nothing has been sent through SendBeam from this site yet. Send yourself a test above and it will show up here.', 'sendbeam' );
	}

	/**
	 * The row's checkbox.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="message[]" value="%d" />', (int) $item['index'] );
	}

	/**
	 * When it went, in the site's own format.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_date( $item ) {
		return '<span class="sb-mono">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $item['at'] ) ) . '</span>';
	}

	/**
	 * Sent, fell back, or failed.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_result( $item ) {
		$map = array(
			'sent'     => array( 'moss', __( 'Sent via SendBeam', 'sendbeam' ) ),
			'fallback' => array( 'amber', __( 'Server mailer', 'sendbeam' ) ),
			'failed'   => array( 'vermilion', __( 'Failed', 'sendbeam' ) ),
		);
		$key = isset( $map[ $item['result'] ] ) ? $item['result'] : 'failed';
		return '<span class="sb-state sb-state--' . esc_attr( $map[ $key ][0] ) . '">' . esc_html( $map[ $key ][1] ) . '</span>';
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
