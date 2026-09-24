<?php
/**
 * The email log: a table, not twenty rows in an option.
 *
 * `sendbeam_mail_log` was an array of the last twenty attempts kept in
 * wp_options. That is a reasonable shape for "did my test send work?" and a
 * useless one for a real site: a shop doing thirty order emails an hour loses
 * the morning by lunchtime, the list table can page through at most one
 * screen, and every write rewrites and re-autoloads the whole array.
 *
 * So: one row per message in a table of its own, with indexes on the two
 * columns anything actually filters by. Bodies are never stored — a log is
 * for answering "did it go, and if not why", and keeping the contents of
 * every password reset a site has ever sent is a liability nobody asked for.
 *
 * Retention is the site owner's choice, pruned daily, with a hard cap on top
 * of it so a forgotten site cannot fill its own disk.
 *
 * @package SendBeam
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bumped whenever the table's shape changes, so dbDelta runs again.
 */
const SENDBEAM_DB_VERSION = 1;

/** The daily tidy-up. */
const SENDBEAM_PRUNE_HOOK = 'sendbeam_mail_log_prune';

add_action( 'admin_init', 'sendbeam_mail_log_maybe_upgrade' );
add_action( SENDBEAM_PRUNE_HOOK, 'sendbeam_mail_log_prune' );
add_action( 'admin_post_sendbeam_clear_log', 'sendbeam_handle_clear_log' );

/**
 * The ceiling, whatever the retention setting says.
 *
 * "Forever" is a promise about time, not about size. A site that sends a
 * thousand messages a day and is never looked at again would otherwise grow
 * this table without limit, on somebody else's hosting.
 */
const SENDBEAM_MAIL_LOG_MAX = 20000;

/**
 * The log table for this site.
 *
 * `$wpdb->prefix` is per-site on multisite, so each site in a network keeps
 * its own log — which is what a network administrator would expect, since
 * each site has its own key, workspace and sending domain.
 *
 * @return string
 */
function sendbeam_mail_log_table() {
	global $wpdb;
	return $wpdb->prefix . 'sendbeam_mail_log';
}

/**
 * The same name, backticked, for the statements that interpolate it.
 *
 * A table name cannot be a placeholder before WordPress 6.2's `%i`, and this
 * plugin supports 6.1. Nothing about the name comes from anywhere a request
 * could reach — it is `$wpdb->prefix` and a literal — but escaping it anyway
 * means a reviewer does not have to take that on trust, and the review
 * tooling does not have to either.
 *
 * @return string
 */
function sendbeam_mail_log_table_sql() {
	return '`' . str_replace( '`', '``', sendbeam_mail_log_table() ) . '`';
}

/**
 * Is the table there to be read?
 *
 * Every read goes through this. A screen that queries a table an upgrade has
 * not created yet is a database error printed into wp-admin.
 *
 * @return bool
 */
function sendbeam_mail_log_ready() {
	global $wpdb;
	return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_results' );
}

/**
 * Create or update the table.
 *
 * The SQL is written the way dbDelta insists on and not one character
 * otherwise: two spaces after PRIMARY KEY, one field per line, lower-case
 * types, and KEY names that match what it finds. Getting any of that wrong
 * makes it silently try to add a column that already exists, on every load.
 */
function sendbeam_mail_log_install() {
	global $wpdb;
	if ( ! isset( $wpdb ) || ! method_exists( $wpdb, 'get_charset_collate' ) ) {
		return;
	}

	$table   = sendbeam_mail_log_table();
	$collate = $wpdb->get_charset_collate();

	// dbDelta parses the name itself, so this one is the bare form.
	$sql = "CREATE TABLE {$table} (
		id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
		sent_at datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
		to_addr varchar(255) NOT NULL DEFAULT '',
		subject text NOT NULL,
		result varchar(16) NOT NULL DEFAULT '',
		note text NOT NULL,
		source varchar(64) NOT NULL DEFAULT '',
		PRIMARY KEY  (id),
		KEY sent_at (sent_at),
		KEY result (result)
	) {$collate};";

	if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	}
	if ( function_exists( 'dbDelta' ) ) {
		dbDelta( $sql );
	}

	update_option( 'sendbeam_db_version', SENDBEAM_DB_VERSION, false );
}

/**
 * Run the install when the stored version is behind, and not otherwise.
 *
 * On `admin_init` rather than on activation alone: a plugin updated by
 * uploading a zip over the top, or by WP-CLI, never fires an activation hook,
 * and a site whose table was never created would otherwise just fail quietly
 * on every send.
 */
function sendbeam_mail_log_maybe_upgrade() {
	if ( (int) get_option( 'sendbeam_db_version' ) === SENDBEAM_DB_VERSION ) {
		return;
	}
	sendbeam_mail_log_install();
	sendbeam_mail_log_migrate();

	/*
	 * And the tidy-up, here as well as on activation: a plugin updated by
	 * uploading a zip over the top fires no activation hook, so a site that
	 * upgraded that way would keep a log for ever with nothing pruning it.
	 */
	sendbeam_mail_log_schedule_prune();
}

/**
 * Move the twenty rows the option holds into the table, once.
 *
 * Idempotent by construction: the option is deleted as part of the move, so
 * a second run has nothing to find. Timestamps are preserved — a log that
 * says every message arrived at the moment of the upgrade is worse than no
 * log at all.
 */
function sendbeam_mail_log_migrate() {
	$log = get_option( 'sendbeam_mail_log' );
	if ( ! is_array( $log ) ) {
		delete_option( 'sendbeam_mail_log' );
		return 0;
	}

	$moved = 0;
	// Oldest first, so the table's ids run the same way its timestamps do.
	foreach ( array_reverse( $log ) as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$moved += sendbeam_mail_log_insert(
			isset( $row['to'] ) ? $row['to'] : '',
			isset( $row['subject'] ) ? $row['subject'] : '',
			isset( $row['result'] ) ? $row['result'] : 'failed',
			isset( $row['note'] ) ? $row['note'] : '',
			'',
			isset( $row['at'] ) ? (int) $row['at'] : 0
		) ? 1 : 0;
	}

	delete_option( 'sendbeam_mail_log' );
	return $moved;
}

/**
 * Record one attempt.
 *
 * @param string|string[] $to      Recipient(s).
 * @param string          $subject Subject.
 * @param string          $result  'sent', 'fallback' or 'failed'.
 * @param string          $note    Error or reason.
 * @param string          $source  What asked for the send, when it is knowable.
 * @param int             $at      Unix timestamp; now when omitted.
 * @return bool
 */
function sendbeam_mail_log_insert( $to, $subject, $result, $note = '', $source = '', $at = 0 ) {
	global $wpdb;
	if ( ! sendbeam_mail_log_ready() || ! method_exists( $wpdb, 'insert' ) ) {
		return false;
	}

	$allowed = array( 'sent', 'fallback', 'failed' );
	$result  = in_array( $result, $allowed, true ) ? $result : 'failed';
	$at      = $at > 0 ? $at : time();

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- the plugin's own table; $wpdb->insert prepares every value, and a log write is not a thing to cache.
	$ok = $wpdb->insert(
		sendbeam_mail_log_table(),
		array(
			'sent_at' => gmdate( 'Y-m-d H:i:s', $at ),
			'to_addr' => mb_substr( is_array( $to ) ? implode( ', ', $to ) : (string) $to, 0, 255 ),
			'subject' => (string) $subject,
			'result'  => $result,
			'note'    => (string) $note,
			'source'  => mb_substr( (string) $source, 0, 64 ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s' )
	);

	return false !== $ok;
}

/**
 * The `WHERE` a search and a view add up to, ready for prepare().
 *
 * @param string $search Search term.
 * @param string $view   One of the result values, or '' for all.
 * @return array{sql:string,args:array}
 */
function sendbeam_mail_log_where( $search = '', $view = '' ) {
	global $wpdb;
	$where = array( '1=1' );
	$args  = array();

	if ( '' !== $view && in_array( $view, array( 'sent', 'fallback', 'failed' ), true ) ) {
		$where[] = 'result = %s';
		$args[]  = $view;
	}
	if ( '' !== $search && method_exists( $wpdb, 'esc_like' ) ) {
		$like    = '%' . $wpdb->esc_like( $search ) . '%';
		$where[] = '( to_addr LIKE %s OR subject LIKE %s OR note LIKE %s )';
		$args[]  = $like;
		$args[]  = $like;
		$args[]  = $like;
	}

	return array(
		'sql'  => implode( ' AND ', $where ),
		'args' => $args,
	);
}

/**
 * A page of the log.
 *
 * @param array $args search, view, orderby, order, per_page, page.
 * @return array{rows:array,total:int}
 */
function sendbeam_mail_log_query( $args = array() ) {
	global $wpdb;
	$empty = array(
		'rows'  => array(),
		'total' => 0,
	);
	if ( ! sendbeam_mail_log_ready() ) {
		return $empty;
	}

	$args = array_merge(
		array(
			'search'   => '',
			'view'     => '',
			'orderby'  => 'sent_at',
			'order'    => 'DESC',
			'per_page' => 20,
			'page'     => 1,
		),
		$args
	);

	// Neither of these may come from the request without passing through a
	// list this file owns: they are the two parts of a query that cannot be
	// a placeholder.
	$orderby = in_array( $args['orderby'], array( 'sent_at', 'result' ), true ) ? $args['orderby'] : 'sent_at';
	$order   = 'ASC' === strtoupper( (string) $args['order'] ) ? 'ASC' : 'DESC';

	$per_page = max( 1, min( 500, (int) $args['per_page'] ) );
	$offset   = max( 0, ( max( 1, (int) $args['page'] ) - 1 ) * $per_page );
	$table    = sendbeam_mail_log_table_sql();
	$where    = sendbeam_mail_log_where( $args['search'], $args['view'] );

	/*
	 * Both statements are built the same way: a table name taken from
	 * $wpdb->prefix, an ORDER BY from the two allow-lists above, and every
	 * value a placeholder filled by prepare(). Neither the table nor the
	 * order column can be a placeholder — `%i` would do it, but that is
	 * WordPress 6.2 and this plugin supports 6.1.
	 *
	 * A count with nothing to filter by has no values at all, and
	 * $wpdb->prepare() refuses a statement with no placeholders, so that one
	 * branch runs the literal.
	 */
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; the only interpolations are the escaped table name and a WHERE of literal placeholders.
	$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where['sql']}";
	if ( $where['args'] ) {
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- see the block comment above: the only interpolations are the plugin's own table name and a WHERE of literal placeholders.
		$count_sql = $wpdb->prepare( $count_sql, $where['args'] );
	}
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; an admin screen reading its own log has nothing to cache.
	$total = (int) $wpdb->get_var( $count_sql );

	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- see the block comment above: the table name is backtick-escaped and the two ORDER BY fragments come from the allow-lists a dozen lines up.
	$select = "SELECT * FROM {$table} WHERE {$where['sql']} ORDER BY {$orderby} {$order}, id {$order} LIMIT %d OFFSET %d";
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- every value in it is a placeholder, filled here.
	$rows_sql = $wpdb->prepare( $select, array_merge( $where['args'], array( $per_page, $offset ) ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above.
	$rows = $wpdb->get_results( $rows_sql, ARRAY_A );

	return array(
		'rows'  => is_array( $rows ) ? $rows : array(),
		'total' => $total,
	);
}

/**
 * How many of each result there are, for the view links.
 *
 * @return array{all:int,sent:int,fallback:int,failed:int}
 */
function sendbeam_mail_log_counts() {
	global $wpdb;
	$counts = array(
		'all'      => 0,
		'sent'     => 0,
		'fallback' => 0,
		'failed'   => 0,
	);
	if ( ! sendbeam_mail_log_ready() ) {
		return $counts;
	}

	$table = sendbeam_mail_log_table_sql();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own table, backtick-escaped above; no user input reaches this statement.
	$rows = $wpdb->get_results( "SELECT result, COUNT(*) AS n FROM {$table} GROUP BY result", ARRAY_A );

	foreach ( (array) $rows as $row ) {
		$key = isset( $row['result'] ) ? (string) $row['result'] : '';
		if ( isset( $counts[ $key ] ) ) {
			$counts[ $key ] = (int) $row['n'];
		}
		$counts['all'] += (int) $row['n'];
	}
	return $counts;
}

/**
 * The newest few, for the Overview.
 *
 * @param int $limit How many.
 * @return array<int,array<string,mixed>>
 */
function sendbeam_mail_log_recent( $limit = 5 ) {
	$page = sendbeam_mail_log_query(
		array(
			'per_page' => max( 1, (int) $limit ),
			'page'     => 1,
		)
	);
	return $page['rows'];
}

/**
 * A row's Unix timestamp, from the UTC datetime it is stored as.
 *
 * Stored in UTC so the log does not move when a site changes timezone;
 * rendered in the site's own timezone by wp_date(), which is what somebody
 * comparing it against "when did that order come in" needs.
 *
 * @param array $row A log row.
 * @return int
 */
function sendbeam_mail_log_stamp( $row ) {
	if ( isset( $row['sent_at'] ) && '' !== $row['sent_at'] ) {
		$at = strtotime( $row['sent_at'] . ' UTC' );
		if ( $at ) {
			return (int) $at;
		}
	}
	return isset( $row['at'] ) ? (int) $row['at'] : 0;
}

/**
 * Delete the rows somebody ticked.
 *
 * @param int[] $ids Row ids.
 * @return int How many went.
 */
function sendbeam_mail_log_delete( $ids ) {
	global $wpdb;
	$ids = array_values( array_filter( array_map( 'absint', (array) $ids ) ) );
	if ( ! $ids || ! sendbeam_mail_log_ready() ) {
		return 0;
	}

	$table = sendbeam_mail_log_table_sql();
	$in    = implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the table is backtick-escaped above and {$in} is a run of %d placeholders built here, which the sniff cannot see; every id goes through prepare().
	$sql = $wpdb->prepare( "DELETE FROM {$table} WHERE id IN ( {$in} )", $ids );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared immediately above; deleting the plugin's own log rows has nothing to cache.
	return (int) $wpdb->query( $sql );
}

/**
 * Empty the log.
 *
 * @return int How many went.
 */
function sendbeam_mail_log_clear() {
	global $wpdb;
	if ( ! sendbeam_mail_log_ready() || ! method_exists( $wpdb, 'query' ) ) {
		return 0;
	}
	$table = sendbeam_mail_log_table_sql();
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own table, backtick-escaped above. DELETE rather than TRUNCATE so the row count comes back.
	return (int) $wpdb->query( "DELETE FROM {$table}" );
}

/**
 * How long this site keeps its log, in days. Zero means for ever.
 *
 * @return int
 */
function sendbeam_mail_log_days() {
	$settings = sendbeam_settings();
	$days     = (int) $settings['mail_log_days'];
	return in_array( $days, array( 0, 7, 30, 90 ), true ) ? $days : 30;
}

/**
 * The choices, in the order they are offered.
 *
 * @return array<int,string>
 */
function sendbeam_mail_log_retentions() {
	return array(
		7  => __( '7 days', 'sendbeam' ),
		30 => __( '30 days', 'sendbeam' ),
		90 => __( '90 days', 'sendbeam' ),
		0  => __( 'For ever', 'sendbeam' ),
	);
}

/**
 * The daily tidy-up: anything past its date, then anything past the ceiling.
 *
 * Both halves matter. The setting is what the owner asked for; the cap is
 * what stops "for ever" meaning "until the disk fills", on hosting that is
 * very often not theirs.
 *
 * @return int How many rows went.
 */
function sendbeam_mail_log_prune() {
	global $wpdb;
	if ( ! sendbeam_mail_log_ready() || ! method_exists( $wpdb, 'query' ) ) {
		return 0;
	}

	$table = sendbeam_mail_log_table_sql();
	$gone  = 0;
	$days  = sendbeam_mail_log_days();

	if ( $days > 0 ) {
		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- the plugin's own table, backtick-escaped above; the cutoff goes through prepare().
		$gone += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE sent_at < %s", $cutoff ) );
	}

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; no user input reaches this statement.
	$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
	if ( $total > SENDBEAM_MAIL_LOG_MAX ) {
		// Oldest first, and by id after the timestamp so two messages in the
		// same second still have an order to be deleted in.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- as above; the limit goes through prepare().
		$gone += (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} ORDER BY sent_at ASC, id ASC LIMIT %d", $total - SENDBEAM_MAIL_LOG_MAX ) );
	}

	return $gone;
}

/** Start the daily tidy-up. */
function sendbeam_mail_log_schedule_prune() {
	if ( ! wp_next_scheduled( SENDBEAM_PRUNE_HOOK ) ) {
		wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', SENDBEAM_PRUNE_HOOK );
	}
}

/** Stop it. A deactivated plugin that leaves a cron behind is a site doing work nobody can see. */
function sendbeam_mail_log_unschedule_prune() {
	wp_clear_scheduled_hook( SENDBEAM_PRUNE_HOOK );
}

/**
 * "Clear the log" — everything, on purpose, confirmed first.
 */
function sendbeam_handle_clear_log() {
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_die( esc_html__( 'You do not have permission to do that.', 'sendbeam' ) );
	}
	check_admin_referer( 'sendbeam_clear_log' );

	$gone = sendbeam_mail_log_clear();

	wp_safe_redirect( add_query_arg( 'sendbeam_log_cleared', max( 0, $gone ), sendbeam_tab_url( 'mail' ) ) );
	exit;
}
