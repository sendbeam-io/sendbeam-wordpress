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
 * A table of its own, one row per attempt, kept for as long as the site owner
 * asked. There is no Resend row action because the log keeps what was sent
 * *to* and *about*, never the message body — a Resend button that could only
 * send a different email is worse than no button.
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
			'source'  => __( 'Sent by', 'sendbeam' ),
			'note'    => __( 'Note', 'sendbeam' ),
		);
	}

	/**
	 * The two columns worth ordering by.
	 *
	 * @return array<string,array>
	 */
	protected function get_sortable_columns() {
		return array(
			'date'   => array( 'sent_at', true ),
			'result' => array( 'result', false ),
		);
	}

	/**
	 * All / Sent / Server mailer / Failed, with counts.
	 *
	 * The counts are the point: "did anything fail?" is the question this
	 * screen exists to answer, and a list of two hundred rows does not answer
	 * it at a glance.
	 *
	 * @return array<string,string>
	 */
	protected function get_views() {
		$counts = sendbeam_mail_log_counts();
		$now    = $this->current_view();
		$base   = sendbeam_page_url( 'sendbeam-mail' );

		$labels = array(
			''         => __( 'All', 'sendbeam' ),
			'sent'     => __( 'Sent', 'sendbeam' ),
			'fallback' => __( 'Server mailer', 'sendbeam' ),
			'failed'   => __( 'Failed', 'sendbeam' ),
		);

		$views = array();
		foreach ( $labels as $key => $label ) {
			$count = '' === $key ? $counts['all'] : $counts[ $key ];
			// A view nobody has any rows for is a link to an empty screen.
			if ( '' !== $key && 0 === $count ) {
				continue;
			}
			$url           = '' === $key ? $base : add_query_arg( 'result', $key, $base );
			$views[ $key ] = sprintf(
				'<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
				esc_url( $url ),
				$key === $now ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				esc_html( number_format_i18n( $count ) )
			);
		}
		return $views;
	}

	/**
	 * Which view is showing.
	 *
	 * @return string
	 */
	private function current_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only navigation between views of a list.
		$view = isset( $_REQUEST['result'] ) ? sanitize_key( wp_unslash( $_REQUEST['result'] ) ) : '';
		return in_array( $view, array( 'sent', 'fallback', 'failed' ), true ) ? $view : '';
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
	 * Deleting the rows somebody ticked, and nothing else.
	 *
	 * Emptying the whole log is a button of its own on the screen, behind its
	 * own confirmation. It is not in here, because "Delete" sitting one
	 * mis-click from "Delete all" in the same dropdown is how a log gets lost.
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
			wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'bulk-' . $this->_args['plural'] );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- checked immediately above.
		$chosen = isset( $_REQUEST['message'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['message'] ) ) : array();
		if ( ! $chosen ) {
			return;
		}

		$GLOBALS['sendbeam_deleted'] = sendbeam_mail_log_delete( $chosen );
	}

	/** Load, filter and page the rows. */
	public function prepare_items() {
		$per_page = sendbeam_per_page( 'sendbeam_mail_log_per_page' );

		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- sorting a list is a GET; core's own list tables read these the same way.
		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : 'sent_at';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : 'desc';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$page = sendbeam_mail_log_query(
			array(
				'search'   => sendbeam_list_search(),
				'view'     => $this->current_view(),
				'orderby'  => $orderby,
				'order'    => $order,
				'per_page' => $per_page,
				'page'     => max( 1, (int) $this->get_pagenum() ),
			)
		);

		$this->items           = $page['rows'];
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), $this->get_default_primary_column_name() );
		$this->set_pagination_args(
			array(
				'total_items' => $page['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $page['total'] / max( 1, $per_page ) ),
			)
		);
	}

	/** What an empty log says. */
	public function no_items() {
		if ( '' !== sendbeam_list_search() ) {
			esc_html_e( 'No message here matches that.', 'sendbeam' );
			return;
		}
		if ( '' !== $this->current_view() ) {
			esc_html_e( 'Nothing in the log ended that way.', 'sendbeam' );
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
		return sprintf( '<input type="checkbox" name="message[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * When it went, in the site's own format.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_date( $item ) {
		$at = sendbeam_mail_log_stamp( $item );
		return '<span class="sb-mono">' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $at ) ) . '</span>';
	}

	/**
	 * What asked for the message, when the plugin could tell.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_source( $item ) {
		$source = isset( $item['source'] ) ? (string) $item['source'] : '';
		if ( '' === $source ) {
			return '<span class="sb-note">&mdash;</span>';
		}
		if ( 'wp-core' === $source ) {
			return esc_html__( 'WordPress', 'sendbeam' );
		}
		return '<code>' . esc_html( $source ) . '</code>';
	}

	/**
	 * Who it went to.
	 *
	 * @param array $item Row.
	 * @return string
	 */
	public function column_to( $item ) {
		return esc_html( (string) $item['to_addr'] );
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
