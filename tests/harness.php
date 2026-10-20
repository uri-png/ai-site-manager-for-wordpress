<?php
/**
 * Mock-WordPress harness: stubs the WP functions the plugin uses, loads the
 * plugin classes, and runs an end-to-end simulated session. Not a substitute
 * for a real install - it proves the plugin's own logic (routing, auth,
 * validation, capabilities, approval queue, audit log, MCP protocol).
 *
 * Run: php tests/harness.php
 */

error_reporting( E_ALL & ~E_DEPRECATED );

define( 'ABSPATH', '/tmp/fake-wp/' );
define( 'MB_IN_BYTES', 1048576 );
define( 'WPMCP_VERSION', '1.2.1' );
define( 'WPSEO_VERSION', 'test' );
define( 'WPMCP_TESTING', true );
class WpmcpTestExit extends Exception {}
define( 'WPMCP_PLUGIN_DIR', dirname( __DIR__ ) . '/' );
define( 'WPMCP_PLUGIN_URL', 'https://example.test/wp-content/plugins/wp-mcp-gateway/' );

/* ---------------- state ---------------- */
$GLOBALS['opts']       = array();
$GLOBALS['users']      = array(
	1 => (object) array( 'ID' => 1, 'user_login' => 'admin', 'display_name' => 'Admin', 'user_email' => 'a@b.c', 'user_registered' => '2020-01-01 00:00:00', 'roles' => array( 'administrator' ) ),
	2 => (object) array( 'ID' => 2, 'user_login' => 'editor', 'display_name' => 'Ed', 'user_email' => 'e@b.c', 'user_registered' => '2020-01-01 00:00:00', 'roles' => array( 'editor' ) ),
);
$GLOBALS['caps']       = array( // user_id => cap => bool
	1 => array( 'read' => true, 'edit_post' => true, 'edit_posts' => true, 'publish_posts' => true, 'list_users' => true, 'upload_files' => true, 'manage_options' => true, 'manage_categories' => true ),
	2 => array( 'read' => true, 'edit_posts' => true, 'publish_posts' => false, 'list_users' => false, 'upload_files' => true ),
);
$GLOBALS['current_uid'] = 0;
$GLOBALS['posts']      = array();
$GLOBALS['next_post']  = 1;
$GLOBALS['db_tables']  = array(); // table => rows[]
$GLOBALS['routes']     = array();

/* ---------------- wpdb ---------------- */
final class FakeWpdb {
	public string $prefix = 'wp_';
	public int $insert_id = 0;

	public function get_charset_collate() { return ''; }
	public function esc_like( $s ) { return addcslashes( $s, '_%\\' ); }

	public function prepare( $query, ...$args ) {
		$out = '';
		$parts = preg_split( '/(%[sd])/', $query, -1, PREG_SPLIT_DELIM_CAPTURE );
		foreach ( $parts as $p ) {
			if ( '%s' === $p ) {
				$out .= "'" . addslashes( (string) array_shift( $args ) ) . "'";
			} elseif ( '%d' === $p ) {
				$out .= (int) array_shift( $args );
			} else {
				$out .= $p;
			}
		}
		return $out;
	}

	public function insert( $table, $data, $format = null ) {
		$id = count( $GLOBALS['db_tables'][ $table ] ?? array() ) + 1;
		$data['id'] = $id;
		$GLOBALS['db_tables'][ $table ][ $id ] = (object) $data;
		$this->insert_id = $id;
		return 1;
	}

	public function update( $table, $data, $where, $f1 = null, $f2 = null ) {
		if ( isset( $where['token_hash'] ) ) {
			$hit = false;
			foreach ( $GLOBALS['db_tables'][ $table ] as $row ) {
				if ( $row->token_hash === $where['token_hash'] ) { foreach ( $data as $k => $v ) { $row->$k = $v; } $hit = true; }
			}
			return $hit ? 1 : false;
		}
		$row = $GLOBALS['db_tables'][ $table ][ $where['id'] ?? 0 ] ?? null;
		if ( ! $row ) { return false; }
		if ( isset( $where['status'] ) && $row->status !== $where['status'] ) { return false; }
		foreach ( $data as $k => $v ) { $row->$k = $v; }
		return 1;
	}

	private function select( $sql, $single ) {
		if ( ! preg_match( '/FROM (\S+)/', $sql, $m ) ) { return $single ? null : array(); }
		$table = $m[1];
		$rows  = array_values( $GLOBALS['db_tables'][ $table ] ?? array() );
		if ( preg_match( "/WHERE key_hash = '([^']+)'/", $sql, $w ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => $r->key_hash === stripslashes( $w[1] ) ) );
		} elseif ( preg_match( '/WHERE id = (\d+)/', $sql, $w ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => (int) $r->id === (int) $w[1] ) );
		} elseif ( preg_match( "/WHERE token_hash = '([^']+)'/", $sql, $w ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => $r->token_hash === $w[1] ) );
			if ( preg_match( "/AND token_type = '(\w+)'/", $sql, $w2 ) ) {
				$rows = array_values( array_filter( $rows, fn( $r ) => $r->token_type === $w2[1] ) );
			}
		} elseif ( preg_match( "/status = '(\w+)'/", $sql, $w ) ) {
			$rows = array_values( array_filter( $rows, fn( $r ) => $r->status === $w[1] ) );
		}
		if ( str_contains( $sql, 'ORDER BY id DESC' ) ) { $rows = array_reverse( $rows ); }
		if ( $single ) { return $rows[0] ?? null; }
		return $rows;
	}

	public function get_row( $sql ) { return $this->select( $sql, true ); }
	public function get_results( $sql ) { return $this->select( $sql, false ); }
	public function get_var( $sql ) {
		if ( str_contains( $sql, 'COUNT(*)' ) ) { return count( $this->select( $sql, false ) ); }
		return null;
	}
	public function get_col( $sql ) {
		return array_values( array_unique( array_map( fn( $r ) => $r->tool_name, $this->select( $sql, false ) ) ) );
	}
	public function query( $sql ) {
		if ( preg_match( "/DELETE FROM (\w+) WHERE expires_at < '([^']+)'/", $sql, $m ) ) {
			$t =& $GLOBALS['db_tables'][ $m[1] ];
			$t = array_values( array_filter( $t, fn( $r ) => $r->expires_at >= $m[2] ) );
			return 1;
		}
		return 1;
	}
	public function delete( $table, $where, $fmt = null ) {
		$t =& $GLOBALS['db_tables'][ $table ];
		$t = array_values( array_filter( $t, function ( $r ) use ( $where ) {
			foreach ( $where as $k => $v ) { if ( (string) $r->$k !== (string) $v ) { return true; } }
			return false;
		} ) );
		return 1;
	}
}
$wpdb = new FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;

/* ---------------- WP function stubs ---------------- */
function plugin_dir_path( $f ) { return dirname( $f ) . '/'; }
function plugin_dir_url( $f ) { return 'https://example.test/wp-content/plugins/' . basename( dirname( $f ) ) . '/'; }
function register_activation_hook( $f, $c ) {}
function register_deactivation_hook( $f, $c ) {}
function add_action( ...$a ) {}
function add_option( $k, $v, $x = null, $y = null ) { $GLOBALS['opts'][ $k ] = $v; }
function get_option( $k, $d = false ) { return $GLOBALS['opts'][ $k ] ?? $d; }
function update_option( $k, $v, $a = null ) { $GLOBALS['opts'][ $k ] = $v; }
function delete_option( $k ) { unset( $GLOBALS['opts'][ $k ] ); }
function apply_filters( $tag, $value ) { return $value; }
function current_time( $type, $gmt = false ) { return gmdate( 'Y-m-d H:i:s' ); }
function wp_salt( $scheme = 'auth' ) { return 'harness-salt'; }
function wp_json_encode( $v ) { return json_encode( $v ); }
function is_wp_error( $t ) { return $t instanceof WP_Error; }
function __( $s, $d = null ) { return $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html_e( $s, $d = null ) { echo $s; }
function _e( $s, $d = null ) { echo $s; }
function wp_unslash( $v ) { return $v; }
function sanitize_text_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_textarea_field( $s ) { return trim( strip_tags( (string) $s ) ); }
function sanitize_key( $s ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $s ) ); }
function esc_url_raw( $u ) { return filter_var( $u, FILTER_VALIDATE_URL ) ? $u : ''; }
function esc_url( $u ) { return $u; }
function esc_attr( $s ) { return htmlspecialchars( (string) $s, ENT_QUOTES ); }
function esc_attr__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return htmlspecialchars( (string) $s ); }
function esc_js( $s ) { return $s; }
function wp_kses_post( $s ) { return strip_tags( (string) $s, '<p><a><strong><em><ul><ol><li><h1><h2><h3><h4><blockquote><code><pre><img><br>' ); }
function wp_slash( $v ) { return $v; }
function wp_parse_url( $u, $c = -1 ) { return -1 === $c ? parse_url( $u ) : ( parse_url( $u )[ $c === PHP_URL_SCHEME ? 'scheme' : 'path' ] ?? null ); }
function mysql2date( $f, $d ) { return gmdate( 'c', strtotime( $d ) ); }
function sanitize_title( $v ) { return trim( preg_replace( '/[^a-z0-9]+/', '-', strtolower( (string) $v ) ), '-' ); }
function get_taxonomy( $name ) {
	$taxes = array(
		'category' => (object) array( 'name' => 'category', 'public' => true, 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) ),
		'post_tag' => (object) array( 'name' => 'post_tag', 'public' => true, 'cap' => (object) array( 'manage_terms' => 'manage_categories' ) ),
	);
	return $taxes[ $name ] ?? false;
}
function get_terms( $q ) { return $GLOBALS['terms'][ $q['taxonomy'] ] ?? array(); }
function wp_insert_term( $name, $tax, $args = array() ) {
	$id = count( $GLOBALS['terms'][ $tax ] ?? array() ) + 1;
	$GLOBALS['terms'][ $tax ][] = (object) array( 'term_id' => $id, 'name' => $name, 'slug' => $args['slug'] ?? sanitize_title( $name ), 'count' => 0, 'description' => $args['description'] ?? '' );
	return array( 'term_id' => $id );
}
function wp_set_object_terms( $post_id, $ids, $tax, $append = false ) { $GLOBALS['object_terms'][ $post_id ][ $tax ] = $ids; return true; }
function sanitize_file_name( $f ) { $f = preg_replace( '/[^a-zA-Z0-9._-]/', '', (string) $f ); return '' === $f ? 'upload' : $f; }
function wp_upload_bits( $name, $deprecated, $bits ) { $GLOBALS['uploads_bits'][ $name ] = $bits; return array( 'file' => '/tmp/' . $name, 'url' => 'https://example.test/uploads/' . $name, 'error' => false ); }
function wp_insert_attachment( $args, $file = '', $parent = 0 ) {
	$id = wp_insert_post( array( 'post_title' => $args['post_title'] ?? '', 'post_status' => 'inherit', 'post_type' => 'attachment', 'post_parent' => $args['post_parent'] ?? 0 ) );
	$GLOBALS['att_mime'][ $id ] = $args['post_mime_type'] ?? '';
	return $id;
}
function wp_generate_attachment_metadata( $id, $file ) { return array( 'file' => $file ); }
function wp_update_attachment_metadata( $id, $meta ) { update_post_meta( $id, '_wp_attachment_metadata', $meta ); return true; }
function wp_delete_file( $f ) { $GLOBALS['deleted_files'][] = $f; return true; }
function set_post_thumbnail( $post_id, $att_id ) { update_post_meta( $post_id, '_thumbnail_id', $att_id ); return true; }
function maybe_unserialize( $v ) { return $v; }
function get_userdata( $id ) { return $GLOBALS['users'][ $id ] ?? false; }
function wp_set_current_user( $id ) { $GLOBALS['current_uid'] = (int) $id; return $GLOBALS['users'][ $id ] ?? null; }
function get_current_user_id() { return $GLOBALS['current_uid']; }
function is_user_logged_in() { return $GLOBALS['current_uid'] > 0; }
function current_user_can( $cap, ...$args ) { return ! empty( $GLOBALS['caps'][ $GLOBALS['current_uid'] ][ $cap ] ); }
function get_post_type_object( $pt ) {
	$objs = array(
		'post' => (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'delete_post' => 'edit_posts', 'publish_posts' => 'publish_posts', 'publish_post' => 'publish_posts' ), 'labels' => (object) array( 'name' => 'Posts' ) ),
		'page' => (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'delete_post' => 'edit_posts', 'publish_posts' => 'publish_posts', 'publish_post' => 'publish_posts' ), 'labels' => (object) array( 'name' => 'Pages' ) ),
		'book' => (object) array( 'cap' => (object) array( 'edit_posts' => 'edit_posts', 'edit_post' => 'edit_posts', 'delete_post' => 'edit_posts', 'publish_posts' => 'publish_posts', 'publish_post' => 'publish_posts' ), 'labels' => (object) array( 'name' => 'Books' ) ),
	);
	return $objs[ $pt ] ?? null;
}
function get_post_types( $args = array(), $out = 'names' ) {
	if ( 'objects' === $out ) {
		return array( 'post' => get_post_type_object( 'post' ), 'page' => get_post_type_object( 'page' ), 'book' => get_post_type_object( 'book' ) );
	}
	return array( 'post', 'page', 'book' );
}
function post_type_exists( $pt ) { return false; }
function get_post( $id ) { return $GLOBALS['posts'][ $id ] ?? null; }
function get_post_status( $id ) { return $GLOBALS['posts'][ $id ]->post_status ?? false; }
function get_post_type( $id ) { return $GLOBALS['posts'][ $id ]->post_type ?? false; }
function get_the_title( $p ) { $p = is_object( $p ) ? $p : ( $GLOBALS['posts'][ $p ] ?? null ); return $p ? $p->post_title : ''; }
function get_permalink( $p ) { $id = is_object( $p ) ? $p->ID : $p; return "https://example.test/?p={$id}"; }
function get_post_meta( $id, $key = '', $single = false ) {
	$all = $GLOBALS['meta'][ $id ] ?? array();
	if ( '' === $key ) { return $all; }
	$v = $all[ $key ] ?? array();
	return $single ? ( $v[0] ?? '' ) : $v;
}
function update_post_meta( $id, $k, $v ) { $GLOBALS['meta'][ $id ][ $k ] = array( $v ); return true; }
function delete_post_meta( $id, $key ) { unset( $GLOBALS['meta'][ $id ][ $key ] ); return true; }
function get_post_mime_type( $id ) { return 'image/png'; }
function wp_get_attachment_url( $id ) { return "https://example.test/uploads/{$id}.png"; }
function wp_get_attachment_caption( $id ) { return ''; }
function wp_insert_post( $arr, $wp_error = false ) {
	$id = $GLOBALS['next_post']++;
	$arr = (array) $arr;
	$arr['ID'] = $id;
	$arr['post_date_gmt'] = gmdate( 'Y-m-d H:i:s' );
	$arr['post_excerpt'] = $arr['post_excerpt'] ?? '';
	$arr['post_content'] = $arr['post_content'] ?? '';
	$post = new WP_Post();
	foreach ( $arr as $k => $v ) { $post->$k = $v; }
	$GLOBALS['posts'][ $id ] = $post;
	return $id;
}
function wp_update_post( $arr, $wp_error = false ) {
	$arr = (array) $arr;
	$id = $arr['ID'];
	if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return new WP_Error( 'bad_id', 'Invalid post ID.' ); }
	foreach ( $arr as $k => $v ) { $GLOBALS['posts'][ $id ]->$k = $v; }
	return $id;
}
function wp_delete_post( $id, $force = false ) {
	if ( ! isset( $GLOBALS['posts'][ $id ] ) ) { return false; }
	if ( $force ) { unset( $GLOBALS['posts'][ $id ] ); } else { $GLOBALS['posts'][ $id ]->post_status = 'trash'; }
	return (object) array( 'ID' => $id );
}
class WP_Post {}
class WP_Query {
	public array $posts = array();
	public int $found_posts = 0;
	public function __construct( $args ) {
		$items = array_filter(
			$GLOBALS['posts'],
			fn( $p ) => $p->post_type === $args['post_type']
				&& ( 'any' === ( $args['post_status'] ?? 'publish' ) || $p->post_status === ( $args['post_status'] ?? 'publish' ) )
				&& ( empty( $args['s'] ) || false !== stripos( $p->post_title, $args['s'] ) )
		);
		$this->found_posts = count( $items );
		$this->posts = array_slice( array_keys( $items ), ( ( $args['paged'] ?? 1 ) - 1 ) * $args['posts_per_page'], $args['posts_per_page'] );
	}
}
class WP_User_Query {
	private array $results;
	public function __construct( $args ) {
		$this->results = array_values( $GLOBALS['users'] );
	}
	public function get_results() { return $this->results; }
	public function get_total() { return count( $this->results ); }
}
class WP_Error {
	private array $errors = array();
	public function __construct( $code = '', $message = '' ) { if ( $code ) { $this->errors[ $code ] = $message; } }
	public function get_error_message() { return (string) reset( $this->errors ); }
}
class WP_REST_Request {
	private array $params = array();
	private array $headers = array();
	private string $body = '';
	private string $route;
	public function __construct( string $body = '', array $headers = array(), string $route = '/wp-mcp/v1/mcp' ) {
		$this->body = $body;
		$this->route = $route;
		foreach ( $headers as $k => $v ) { $this->headers[ strtolower( $k ) ] = $v; }
	}
	public function get_route() { return $this->route; }
	public function get_body() { return $this->body; }
	public function get_header( $k ) { return $this->headers[ strtolower( $k ) ] ?? ''; }
	public function get_param( $k ) { return $this->params[ $k ] ?? null; }
	public function set_param( $k, $v ) { $this->params[ $k ] = $v; }
	public function get_json_params() { return json_decode( $this->body, true ); }
	public function get_uri() { return 'https://example.test' . $this->route; }
	public function get_body_params() { parse_str( $this->body, $out ); return array_merge( $out, array_filter( $this->params, fn( $k ) => ! str_starts_with( (string) $k, '_' ), ARRAY_FILTER_USE_KEY ) ); }
}
class WP_REST_Response {
	private array $headers = array();
	public function __construct( private $data = null, private int $status = 200 ) {}
	public function get_data() { return $this->data; }
	public function get_status() { return $this->status; }
	public function header( $k, $v ) { $this->headers[ strtolower( $k ) ] = $v; }
	public function get_headers() { return $this->headers; }
}
function add_filter( ...$a ) {}
class WP_REST_Server {
	const CREATABLE = 'POST';
	const READABLE = 'GET';
	const DELETABLE = 'DELETE';
}
function register_rest_route( $ns, $route, $args ) { $GLOBALS['routes'][] = $ns . $route; }
function submit_button( $text = '', $type = '', $name = '', $wrap = false ) { echo '<button>' . $text . '</button>'; }
function wp_dropdown_users( $args = array() ) { echo '<select name="user_id"></select>'; }
function selected( $a, $b = true, $e = true ) { $r = $a === $b ? ' selected' : ''; if ( $e ) { echo $r; } return $r; }
function checked( $a, $b = true, $e = true ) { $r = $a === $b ? ' checked' : ''; if ( $e ) { echo $r; } return $r; }
function get_transient( $k ) { return $GLOBALS['transients'][ $k ] ?? false; }
function set_transient( $k, $v, $ttl = 0 ) { $GLOBALS['transients'][ $k ] = $v; return true; }
function wp_next_scheduled( $h ) { return $GLOBALS['cron'][ $h ] ?? false; }
function wp_schedule_event( $ts, $rec, $h ) { $GLOBALS['cron'][ $h ] = $ts; return true; }
function wp_clear_scheduled_hook( $h ) { unset( $GLOBALS['cron'][ $h ] ); }
define( 'MINUTE_IN_SECONDS', 60 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'DAY_IN_SECONDS', 86400 );
function wp_generate_uuid4() { return bin2hex( random_bytes( 16 ) ); }
function wp_login_url( $redirect = '' ) { return 'https://example.test/wp-login.php?redirect_to=' . urlencode( $redirect ); }
class RedirectException extends Exception { public string $url; }
function status_header( $code ) { $GLOBALS['sent_headers'][] = 'status:' . $code; }
function wp_safe_redirect( $url ) { $e = new RedirectException( 'redirect' ); $e->url = $url; $e->via = 'safe'; throw $e; }
function wp_redirect( $url ) { $e = new RedirectException( 'redirect' ); $e->url = $url; $e->via = 'plain'; throw $e; }
function check_admin_referer( $a ) { return true; }
function wp_nonce_field( $a, $n = '_wpnonce', $r = true, $e = true ) { return ''; }
function wp_get_current_user() { return $GLOBALS['users'][ $GLOBALS['current_uid'] ]; }
function get_query_var( $k ) { return $GLOBALS['qvars'][ $k ] ?? ''; }
function untrailingslashit( $v ) { return rtrim( $v, '/' ); }
function flush_rewrite_rules() {}
function add_rewrite_rule( ...$a ) {}
function add_query_arg( $args, $url = '' ) {
	if ( is_string( $args ) ) { $args = array( $args => func_get_arg( 1 ) ); $url = func_num_args() > 2 ? func_get_arg( 2 ) : ''; }
	$sep = str_contains( $url, '?' ) ? '&' : '?';
	return $url . $sep . http_build_query( $args );
}
function get_bloginfo( $show ) { return 'version' === $show ? '6.7' : 'Harness Site'; }
function home_url( $p = '' ) { return 'https://example.test' . $p; }
function rest_url( $path = '' ) { return 'https://example.test/wp-json/' . $path; }
function admin_url( $p = '' ) { return 'https://example.test/wp-admin/' . $p; }
function is_admin() { return false; }
function is_ssl() { return true; }
function dbDelta( $sql ) {
	if ( preg_match( '/CREATE TABLE (\S+)/', $sql, $m ) ) { $GLOBALS['db_tables'][ $m[1] ] = $GLOBALS['db_tables'][ $m[1] ] ?? array(); }
	return true;
}


/* ---------------- Elementor / Abilities API mock (inactive unless $GLOBALS['mock_abilities'] is set) ---------------- */
if ( ! function_exists( 'wp_slash' ) ) { function wp_slash( $v ) { return is_string( $v ) ? addslashes( $v ) : $v; } }
class MockAbility {
	public $calls = array();
	public function __construct( public string $name, public bool $ro, public array $schema, public $result, public bool $allowed = true ) {}
	public function get_name() { return $this->name; }
	public function get_label() { return 'Label ' . $this->name; }
	public function get_description() { return 'Desc ' . $this->name; }
	public function get_meta() { return array( 'annotations' => array( 'readonly' => $this->ro ) ); }
	public function get_input_schema() { return $this->schema; }
	public function check_permissions( $input = null ) { return $this->allowed; }
	public function execute( $input = null ) {
		$this->calls[] = $input;
		if ( $this->result instanceof WP_Error ) { return $this->result; }
		if ( ! $this->ro && ! empty( $input['post_id'] ) ) { $GLOBALS['meta'][ $input['post_id'] ]['_elementor_data'] = array( '[{"id":"changed"}]' ); }
		return $this->result;
	}
}
function wp_get_abilities() { return $GLOBALS['mock_abilities'] ?? array(); }
function wp_get_ability( $id ) { return $GLOBALS['mock_abilities'][ $id ] ?? null; }

/* ---------------- load plugin ---------------- */
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-db.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-logger.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-keys.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-tool.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-registry.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-tools-core.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-tools-yoast.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-tools-elementor.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-executor.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-mcp.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-rest-server.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-oauth.php';
require WPMCP_PLUGIN_DIR . 'includes/class-mcp-plugin.php';
require WPMCP_PLUGIN_DIR . 'admin/class-mcp-admin.php';

/* ---------------- assertions ---------------- */
$failures = 0;
function check( $label, $cond ) {
	global $failures;
	if ( $cond ) { echo "PASS  {$label}\n"; } else { $failures++; echo "FAIL  {$label}\n"; }
}

MCP_DB::activate();
check( 'activation creates audit table', isset( $GLOBALS['db_tables']['wp_mcp_audit_logs'] ) );

// Key lifecycle.
$key = MCP_Keys::create( 1, 'n8n test' );
check( 'key created for admin user', is_array( $key ) && str_starts_with( $key['key'], 'wpmcp_' ) );
$bad = MCP_Keys::create( 99, 'ghost' );
check( 'key creation rejects unknown user', is_wp_error( $bad ) );
check( 'key resolves', null !== MCP_Keys::resolve( $key['key'] ) );
check( 'garbage token rejected', null === MCP_Keys::resolve( 'wpmcp_nope' ) );

// REST auth via permission callback.
$req = new WP_REST_Request( '{}' );
check( 'missing token => 401', is_wp_error( MCP_REST_Server::authenticate( $req ) ) );
$req = new WP_REST_Request( '{}', array( 'Authorization' => 'Bearer wpmcp_wrong' ) );
check( 'wrong token => 401', is_wp_error( MCP_REST_Server::authenticate( $req ) ) );
$req = new WP_REST_Request( '{}', array( 'Authorization' => 'Bearer ' . $key['key'] ) );
check( 'good token authenticates', true === MCP_REST_Server::authenticate( $req ) );
check( 'current user became key user', 1 === get_current_user_id() );
$api_key = $req->get_param( '_wpmcp_api_key' );

// Routes registered.
MCP_REST_Server::register_routes();
check( 'routes registered', in_array( 'wp-mcp/v1/mcp', $GLOBALS['routes'], true ) && in_array( 'wp-mcp/v1/tools/execute', $GLOBALS['routes'], true ) );

// Tool discovery: core + CPT, Woo skipped (inactive).
$tools = MCP_Registry::all();
check( 'posts tools discovered', isset( $tools['posts_create'], $tools['posts_delete'] ) );
check( 'CPT (book) discovered', isset( $tools['cpt_book_create'] ) );
check( 'no user-creation tool (priv-esc guard)', ! isset( $tools['users_create'] ) );
check( 'Woo tools absent when Woo inactive', ! isset( $tools['woo_orders_list'] ) );
check( 'destructive default OFF', ! MCP_Registry::is_enabled( 'posts_delete' ) );
check( 'standard default ON', MCP_Registry::is_enabled( 'posts_create' ) );

// MCP protocol: initialize.
$mcp_req = function ( array $msg ) use ( $key ) {
	$r = new WP_REST_Request( json_encode( $msg ), array( 'Authorization' => 'Bearer ' . $key['key'] ) );
	MCP_REST_Server::authenticate( $r );
	return MCP_REST_Server::handle_mcp( $r );
};

$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'test', 'version' => '0' ) ) ) );
$data = $res->get_data();
check( 'initialize echoes supported version', '2025-06-18' === ( $data['result']['protocolVersion'] ?? '' ) );
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '1999-01-01' ) ) );
check( 'unknown version negotiated to latest supported', '2025-11-25' === ( $res->get_data()['result']['protocolVersion'] ?? '' ) );

// Notification => 202 no body.
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
check( 'notification => 202 empty', 202 === $res->get_status() && null === $res->get_data() );

// Ping.
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping' ) );
check( 'ping ok', 200 === $res->get_status() && isset( $res->get_data()['result'] ) );

// tools/list: destructive hidden (disabled).
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/list' ) );
$listed = array_column( $res->get_data()['result']['tools'], 'name' );
check( 'tools/list has posts_create', in_array( 'posts_create', $listed, true ) );
check( 'tools/list hides disabled posts_delete', ! in_array( 'posts_delete', $listed, true ) );

// tools/call: create a post.
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'tools/call', 'params' => array( 'name' => 'posts_create', 'arguments' => array( 'title' => 'Hello MCP', 'content' => '<p>Hi</p><script>x()</script>' ) ) ) );
$call = $res->get_data()['result'];
check( 'posts_create ok', false === $call['isError'] && 1 === ( $call['structuredContent']['id'] ?? 0 ) );
check( 'content sanitized (script stripped)', '<p>Hi</p>x()' === $GLOBALS['posts'][1]->post_content );

// Validation failures.
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'tools/call', 'params' => array( 'name' => 'posts_create', 'arguments' => array() ) ) );
check( 'missing required arg => isError', true === $res->get_data()['result']['isError'] );
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'tools/call', 'params' => array( 'name' => 'posts_create', 'arguments' => array( 'title' => 'x', 'bogus' => 1 ) ) ) );
check( 'unknown arg rejected', true === $res->get_data()['result']['isError'] );
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'tools/call', 'params' => array( 'name' => 'posts_create', 'arguments' => array( 'title' => 'x', 'status' => 'bogus' ) ) ) );
check( 'bad enum rejected', true === $res->get_data()['result']['isError'] );

// Capability enforcement: editor (user 2) cannot publish.
$key2 = MCP_Keys::create( 2, 'editor key' );
$req2 = new WP_REST_Request( '{}', array( 'Authorization' => 'Bearer ' . $key2['key'] ) );
MCP_REST_Server::authenticate( $req2 );
$k2 = $req2->get_param( '_wpmcp_api_key' );
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'Sneaky', 'status' => 'publish' ), $k2 );
check( 'editor cannot publish (capability bound to key user)', false === $r['ok'] );
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'Draft ok' ), $k2 );
check( 'editor can create draft', true === $r['ok'] );
$r = MCP_Executor::execute( 'users_list', array(), $k2 );
check( 'editor cannot list users', false === $r['ok'] );

// Back to admin. Destructive tool: enable it, approval queue catches it.
wp_set_current_user( 1 );
MCP_Registry::set_enabled( 'posts_delete', true );
check( 'destructive tool enabled by admin override', MCP_Registry::is_enabled( 'posts_delete' ) );
$r = MCP_Executor::execute( 'posts_delete', array( 'id' => 1 ), $api_key );
check( 'destructive call queued, not executed', true === $r['ok'] && isset( $r['pending_approval'] ) && isset( $GLOBALS['posts'][1] ) && 'trash' !== $GLOBALS['posts'][1]->post_status );
$qid = $r['pending_approval'];
$status = MCP_Executor::approval_status( $qid );
check( 'approval status pollable', 'pending' === ( $status['status'] ?? '' ) );
$approved = MCP_Executor::approve( $qid, 1 );
check( 'approval executes the delete', true === $approved['ok'] && 'trash' === $GLOBALS['posts'][1]->post_status );
check( 'approval recorded', 'executed' === MCP_Executor::approval_status( $qid )['status'] );

// Approval off => destructive executes immediately.
update_option( 'wpmcp_require_approval', 0 );
$r = MCP_Executor::execute( 'posts_delete', array( 'id' => 1, 'force' => true ), $api_key );
check( 'approval off => direct execution', true === $r['ok'] && ! isset( $GLOBALS['posts'][1] ) );

// Meta protections.
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'Meta test', 'meta' => array( 'rating' => '5', '_edit_lock' => 'hax' ) ), $api_key );
check( 'protected meta keys rejected loudly (no partial write)', false === $r['ok'] && str_contains( (string) $r['error'], 'Protected meta keys rejected' ) && ! isset( $GLOBALS['meta'][3] ) );
check( 'error message is raw JSON-safe text (no HTML entities)', ! str_contains( (string) $r['error'], '&' ) || ! str_contains( (string) $r['error'], '&quot;' ) );
check( 'error message names the offending key plainly', str_contains( (string) $r['error'], '_edit_lock' ) );
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'Meta ok', 'meta' => array( 'rating' => '5' ) ), $api_key );
check( 'public meta written fine', true === $r['ok'] && '5' === ( $GLOBALS['meta'][ $r['data']['id'] ?? 0 ]['rating'][0] ?? '' ) );

// Unknown method / parse errors.
$res = $mcp_req( array( 'jsonrpc' => '2.0', 'id' => 9, 'method' => 'resources/list' ) );
check( 'unknown method => -32601', -32601 === ( $res->get_data()['error']['code'] ?? 0 ) && 404 === $res->get_status() );
$bad_req = new WP_REST_Request( 'not json', array( 'Authorization' => 'Bearer ' . $key['key'] ) );
MCP_REST_Server::authenticate( $bad_req );
check( 'garbage body => -32700', -32700 === ( MCP_REST_Server::handle_mcp( $bad_req )->get_data()['error']['code'] ?? 0 ) );

// Plain REST endpoints.
$req = new WP_REST_Request( '{}', array( 'Authorization' => 'Bearer ' . $key['key'] ) );
MCP_REST_Server::authenticate( $req );
$list = MCP_REST_Server::handle_tools_list( $req )->get_data();
check( 'REST /tools/list works', count( $list['tools'] ) > 5 && isset( $list['tools'][0]['inputSchema'] ) );
$req = new WP_REST_Request( json_encode( array( 'tool' => 'posts_list', 'arguments' => array( 'status' => 'any' ) ) ), array( 'Authorization' => 'Bearer ' . $key['key'] ) );
MCP_REST_Server::authenticate( $req );
$exec = MCP_REST_Server::handle_tools_execute( $req )->get_data();
check( 'REST /tools/execute lists posts', true === $exec['ok'] && count( $exec['data']['items'] ) >= 1 );

// v1.0.1: GET/DELETE /mcp => 405 with Allow: POST.
$r405 = MCP_REST_Server::method_not_allowed( new WP_REST_Request( '' ) );
check( 'GET /mcp => 405 with Allow: POST', 405 === $r405->get_status() && 'POST' === ( $r405->get_headers()['allow'] ?? '' ) && -32601 === ( $r405->get_data()['error']['code'] ?? 0 ) );

// v1.0.1: 401 responses carry a Bearer challenge; other statuses/routes untouched.
$resp401 = new WP_REST_Response( array( 'code' => 'wpmcp_missing_token' ), 401 );
$resp401 = MCP_REST_Server::add_auth_challenge( $resp401, new WP_REST_Server(), new WP_REST_Request( '' ) );
check( '401 carries WWW-Authenticate Bearer', str_contains( $resp401->get_headers()['www-authenticate'] ?? '', 'Bearer' ) );
$resp200 = MCP_REST_Server::add_auth_challenge( new WP_REST_Response( array(), 200 ), new WP_REST_Server(), new WP_REST_Request( '' ) );
check( '200 response untouched by challenge filter', empty( $resp200->get_headers() ) );

/* ---------------- OAuth 2.1 server ---------------- */
MCP_OAuth::activate();
check( 'oauth tables created', isset( $GLOBALS['db_tables']['wp_mcp_oauth_clients'], $GLOBALS['db_tables']['wp_mcp_oauth_tokens'], $GLOBALS['db_tables']['wp_mcp_oauth_codes'] ) );

// DCR.
$req = new WP_REST_Request( json_encode( array( 'client_name' => 'Claude', 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ) ) );
$reg = MCP_OAuth::handle_register( $req )->get_data();
check( 'DCR issues client_id (201 handled as data)', ! empty( $reg['client_id'] ) );
$cid = $reg['client_id'];
$req = new WP_REST_Request( json_encode( array( 'redirect_uris' => array( 'http://evil.example.com/cb' ) ) ) );
check( 'non-loopback http redirect rejected', 'invalid_redirect_uri' === ( MCP_OAuth::handle_register( $req )->get_data()['error'] ?? '' ) );

// Authorize: logged out => bounced to wp-login.
wp_set_current_user( 0 );
$authz_params = array( 'response_type' => 'code', 'client_id' => $cid, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'state' => 'st', 'code_challenge' => 'CHAL', 'code_challenge_method' => 'S256' );

// v1.1.4: the REST authorize route no longer runs the flow (REST drops cookie
// auth without X-WP-Nonce); it 302s to the front-end browser endpoint.
$req = new WP_REST_Request( '' );
foreach ( $authz_params as $k => $v ) { $req->set_param( $k, $v ); }
$r302 = MCP_OAuth::handle_authorize( $req );
check( 'REST authorize 302s to the browser endpoint', 302 === $r302->get_status() && str_contains( (string) ( $r302->get_headers()['location'] ?? '' ), 'https://example.test/oauth/authorize?' ) );
check( 'REST authorize redirect preserves the client params', str_contains( (string) ( $r302->get_headers()['location'] ?? '' ), 'client_id=' . $cid ) );
$doc = MCP_OAuth::discovery_doc( 'authorization-server' );
check( 'authorization_endpoint advertises the browser path', 'https://example.test/oauth/authorize' === $doc['authorization_endpoint'] );

// Cookie-only navigation, logged out: bounce to wp-login.
wp_set_current_user( 0 );
$bounced = false;
try { MCP_OAuth::authorize_flow( $authz_params ); } catch ( RedirectException $e ) { $bounced = str_contains( $e->url, 'wp-login.php' ) && str_contains( $e->url, 'redirect_to=' ); }
check( 'logged-out browser authorize bounces to wp-login with redirect_to', $bounced );

// Cookie-only navigation, LOGGED IN (the v1.1.3 bug): consent screen renders.
wp_set_current_user( 1 );
ob_start();
try { MCP_OAuth::authorize_flow( $authz_params ); } catch ( WpmcpTestExit $e ) { /* unexpected */ }
$html = ob_get_clean();
check( 'logged-in cookie-only navigation reaches the consent screen', str_contains( $html, 'wpmcp_consent' ) && str_contains( $html, 'Allow' ) );

// Bad redirect_uri rejected with a browser error page (400).
$GLOBALS['sent_headers'] = array();
ob_start();
try { MCP_OAuth::authorize_flow( array_merge( $authz_params, array( 'redirect_uri' => 'https://evil.example.com/' ) ) ); } catch ( WpmcpTestExit $e ) { /* expected */ }
$err_html = ob_get_clean();
check( 'unregistered redirect_uri rejected with 400 error page', in_array( 'status:400', $GLOBALS['sent_headers'], true ) && str_contains( $err_html, 'invalid_request' ) );

// Consent allow => redirect with code.
$verifier = bin2hex( random_bytes( 32 ) );
$challenge = rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' );
$code = null; $via = ''; $host = '';
try { MCP_OAuth::authorize_flow( array_merge( $authz_params, array( 'code_challenge' => $challenge, 'wpmcp_consent' => '1', 'decision' => 'allow' ) ) ); } catch ( RedirectException $e ) { parse_str( (string) parse_url( $e->url, PHP_URL_QUERY ), $q ); $code = $q['code'] ?? null; $via = $e->via ?? ''; $host = (string) parse_url( $e->url, PHP_URL_HOST ); }
check( 'consent issues code via redirect', is_string( $code ) );
check( 'consent redirect uses wp_redirect (external callback host survives)', 'plain' === $via );
check( 'consent redirect targets the registered external callback', 'claude.ai' === $host );

// Deny path => error redirect.
$dvia = '';
try { MCP_OAuth::authorize_flow( array_merge( $authz_params, array( 'wpmcp_consent' => '1', 'decision' => 'deny' ) ) ); $denied = false; } catch ( RedirectException $e ) { $denied = str_contains( $e->url, 'error=access_denied' ); $dvia = $e->via ?? ''; }
check( 'deny redirects with access_denied', $denied );
check( 'deny redirect also uses wp_redirect', 'plain' === $dvia );

// maybe_serve_authorize: raw-path matching drives the flow (logged-in).
$_SERVER['REQUEST_URI'] = '/oauth/authorize?response_type=code';
$_GET = $authz_params;
ob_start();
try { MCP_OAuth::maybe_serve_authorize(); } catch ( WpmcpTestExit $e ) { /* expected */ }
$html2 = ob_get_clean();
check( 'maybe_serve_authorize serves consent for /oauth/authorize', str_contains( $html2, 'wpmcp_consent' ) );
$_SERVER['REQUEST_URI'] = '/wp-admin/';
ob_start();
MCP_OAuth::maybe_serve_authorize();
$other = ob_get_clean();
check( 'maybe_serve_authorize ignores other paths', '' === $other );
unset( $_GET['response_type'], $_GET['client_id'], $_GET['redirect_uri'], $_GET['state'], $_GET['code_challenge'], $_GET['code_challenge_method'] );
$_SERVER['REQUEST_URI'] = '/';

// Token exchange: bad verifier fails, good verifier issues tokens.
$tok_req = function ( array $params ) {
	$r = new WP_REST_Request( http_build_query( $params ) );
	foreach ( $params as $k => $v ) { $r->set_param( $k, $v ); }
	// get_body_params: our stub parses the urlencoded body.
	return MCP_OAuth::handle_token( $r );
};
// Patch: give the request body params via get_body_params stub behavior.
$r = MCP_OAuth::handle_token( ( function () use ( $cid, $code ) { $r = new WP_REST_Request( '' ); foreach ( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'client_id' => $cid, 'code_verifier' => 'wrong' ) as $k => $v ) { $r->set_param( $k, $v ); } return $r; } )() );
check( 'bad PKCE verifier rejected', 'invalid_grant' === ( $r->get_data()['error'] ?? '' ) );
$mk = function ( $verifier ) use ( $cid, $code ) { $r = new WP_REST_Request( '' ); foreach ( array( 'grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'client_id' => $cid, 'code_verifier' => $verifier ) as $k => $v ) { $r->set_param( $k, $v ); } return $r; };
$r = MCP_OAuth::handle_token( $mk( $verifier ) );
$tok = $r->get_data();
check( 'code exchange issues access+refresh', str_starts_with( (string) ( $tok['access_token'] ?? '' ), 'mcpat_' ) && str_starts_with( (string) ( $tok['refresh_token'] ?? '' ), 'mcprt_' ) );
$r = MCP_OAuth::handle_token( $mk( $verifier ) );
check( 'code is single-use', 'invalid_grant' === ( $r->get_data()['error'] ?? '' ) );

// Gateway accepts the OAuth access token.
$req = new WP_REST_Request( json_encode( array( 'jsonrpc' => '2.0', 'id' => 50, 'method' => 'ping' ) ), array( 'Authorization' => 'Bearer ' . $tok['access_token'] ) );
check( 'oauth access token authenticates at gateway', true === MCP_REST_Server::authenticate( $req ) && 1 === get_current_user_id() );

// Refresh rotation.
$mk2 = function ( $rt ) use ( $cid ) { $r = new WP_REST_Request( '' ); foreach ( array( 'grant_type' => 'refresh_token', 'refresh_token' => $rt, 'client_id' => $cid ) as $k => $v ) { $r->set_param( $k, $v ); } return $r; };
$r = MCP_OAuth::handle_token( $mk2( $tok['refresh_token'] ) );
$tok2 = $r->get_data();
check( 'refresh rotates to a new pair', str_starts_with( (string) ( $tok2['access_token'] ?? '' ), 'mcpat_' ) );
check( 'old refresh token is revoked after rotation', 'invalid_grant' === ( MCP_OAuth::handle_token( $mk2( $tok['refresh_token'] ) )->get_data()['error'] ?? '' ) );

// ---- v1.2.1: hardening (throttle, revoke, cleanup, client admin) ----
$GLOBALS['transients'] = array();

// Throttle: register limited to 10/hour per IP.
$_SERVER['REMOTE_ADDR'] = '203.0.113.9';
$ok429 = null;
for ( $i = 0; $i < 11; $i++ ) {
	$rr = new WP_REST_Request( json_encode( array( 'client_name' => 'flood', 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ) ) );
	$res = MCP_OAuth::handle_register( $rr );
	if ( 429 === $res->get_status() ) { $ok429 = $res; break; }
}
check( 'DCR register is rate-limited (429 after 10/hour per IP)', null !== $ok429 );
$_SERVER['REMOTE_ADDR'] = '198.51.100.7';
$rr = new WP_REST_Request( json_encode( array( 'client_name' => 'fresh-ip', 'redirect_uris' => array( 'https://claude.ai/api/mcp/auth_callback' ) ) ) );
check( 'a different IP can still register', 201 === MCP_OAuth::handle_register( $rr )->get_status() );

// Revocation endpoint: 200 for unknown tokens, and actually revokes a known one.
$r = MCP_OAuth::handle_revoke( ( function () { $r = new WP_REST_Request( '' ); $r->set_param( 'token', 'mcpat_nonexistent' ); return $r; } )() );
check( 'revoke returns 200 for unknown token (RFC 7009)', 200 === $r->get_status() );
// Mint a real token pair via refresh grant, then revoke the new refresh token.
$reftok = null;
{
	$rr = new WP_REST_Request( '' );
	foreach ( array( 'grant_type' => 'refresh_token', 'refresh_token' => $tok2['refresh_token'], 'client_id' => $cid ) as $k => $v ) { $rr->set_param( $k, $v ); }
	$pair = MCP_OAuth::handle_token( $rr )->get_data();
	$reftok = $pair['refresh_token'] ?? null;
}
check( 'refresh grant rotates a new pair', is_string( $reftok ) );
$r = MCP_OAuth::handle_revoke( ( function () use ( $reftok ) { $r = new WP_REST_Request( '' ); $r->set_param( 'token', $reftok ); return $r; } )() );
check( 'revoke accepts a real refresh token', 200 === $r->get_status() );
$rr = new WP_REST_Request( '' );
foreach ( array( 'grant_type' => 'refresh_token', 'refresh_token' => $reftok, 'client_id' => $cid ) as $k => $v ) { $rr->set_param( $k, $v ); }
check( 'revoked refresh token is rejected', 'invalid_grant' === ( MCP_OAuth::handle_token( $rr )->get_data()['error'] ?? '' ) );

// Admin client revocation cascades to codes and tokens.
MCP_OAuth::revoke_client( $cid );
check( 'revoke_client removes the client row', 0 === count( array_filter( $GLOBALS['db_tables']['wp_mcp_oauth_clients'] ?? array(), fn( $c ) => $c->client_id === $cid ) ) );
check( 'revoke_client removes client tokens', 0 === count( array_filter( $GLOBALS['db_tables']['wp_mcp_oauth_tokens'] ?? array(), fn( $t ) => $t->client_id === $cid ) ) );

// Cleanup cron deletes expired rows only.
$GLOBALS['db_tables']['wp_mcp_oauth_codes'][] = (object) array( 'id' => 999, 'code_hash' => 'x', 'client_id' => 'c', 'user_id' => 1, 'redirect_uri' => 'https://x', 'code_challenge' => 'c', 'expires_at' => '2000-01-01 00:00:00', 'used' => 0 );
$GLOBALS['db_tables']['wp_mcp_oauth_codes'][] = (object) array( 'id' => 1000, 'code_hash' => 'y', 'client_id' => 'c', 'user_id' => 1, 'redirect_uri' => 'https://x', 'code_challenge' => 'c', 'expires_at' => '2999-01-01 00:00:00', 'used' => 0 );
MCP_OAuth::cleanup();
$left = array_column( $GLOBALS['db_tables']['wp_mcp_oauth_codes'], 'id' );
check( 'cleanup deletes expired codes, keeps valid ones', ! in_array( 999, $left, true ) && in_array( 1000, $left, true ) );

// Cron scheduled on activate, cleared on deactivate.
MCP_Plugin::activate();
check( 'daily cleanup cron scheduled on activation', isset( $GLOBALS['cron']['wpmcp_daily_cleanup'] ) );
MCP_Plugin::deactivate();
check( 'daily cleanup cron cleared on deactivation', ! isset( $GLOBALS['cron']['wpmcp_daily_cleanup'] ) );

// ---- v1.1.7 regressions (Uri's live findings via Claude) ----
wp_set_current_user( 1 );

// 1. Draft with zero post_date_gmt falls back to post_date (no -001-11-30).
$draft_id = wp_insert_post( array( 'post_title' => 'D', 'post_type' => 'post', 'post_status' => 'draft' ) );
$GLOBALS['posts'][ $draft_id ]->post_date = '2026-10-18 10:00:00';
$GLOBALS['posts'][ $draft_id ]->post_date_gmt = '0000-00-00 00:00:00';
$r = MCP_Executor::execute( 'posts_get', array( 'id' => $draft_id ), $api_key );
$got_date = $r['data']['date'] ?? '';
check( 'draft date falls back to post_date when GMT is zero', ! str_contains( $got_date, '-001-' ) && str_starts_with( $got_date, '2026-10-18' ) );

// 2. users_list returns populated roles.
$r = MCP_Executor::execute( 'users_list', array(), $api_key );
check( 'users_list includes roles', ! empty( $r['data']['items'][0]['roles'] ) );

// 4. Missing ID reports not-found, not a permission error.
$r = MCP_Executor::execute( 'posts_get', array( 'id' => 99999 ), $api_key );
check( 'missing post returns not-found (not permission)', str_contains( strtolower( (string) ( $r['error'] ?? '' ) ), 'not found' ) && ! str_contains( strtolower( (string) ( $r['error'] ?? '' ) ), 'permission' ) );
$r = MCP_Executor::execute( 'posts_delete', array( 'id' => 99999 ), $api_key );
check( 'missing post on delete returns not-found too', str_contains( strtolower( (string) ( $r['error'] ?? '' ) ), 'not found' ) );

// 5. Terms tools + featured image.
$r = MCP_Executor::execute( 'terms_create', array( 'taxonomy' => 'category', 'name' => 'News' ), $api_key );
$cat_id = $r['data']['id'] ?? 0;
check( 'terms_create creates a category', $cat_id > 0 );
$r = MCP_Executor::execute( 'terms_list', array( 'taxonomy' => 'category' ), $api_key );
check( 'terms_list returns the new category', in_array( 'News', array_column( $r['data']['items'] ?? array(), 'name' ), true ) );
$r = MCP_Executor::execute( 'terms_create', array( 'taxonomy' => 'bogus', 'name' => 'X' ), $api_key );
check( 'terms_create rejects unknown taxonomy', str_contains( (string) ( $r['error'] ?? '' ), 'Unknown or non-public taxonomy' ) );
$att_id = wp_insert_post( array( 'post_title' => 'img', 'post_type' => 'attachment', 'post_status' => 'inherit' ) );
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'with image', 'featured_media' => $att_id, 'terms' => array( 'category' => array( $cat_id ) ) ), $api_key );
$new_id = $r['data']['id'] ?? 0;
check( 'featured_media sets the thumbnail', (int) ( $GLOBALS['meta'][ $new_id ]['_thumbnail_id'][0] ?? 0 ) === $att_id );
check( 'terms arg assigns the category', in_array( $cat_id, $GLOBALS['object_terms'][ $new_id ]['category'] ?? array(), true ) );
$r = MCP_Executor::execute( 'posts_create', array( 'title' => 'bad image', 'featured_media' => 99999 ), $api_key );
check( 'featured_media with a non-attachment ID errors', str_contains( (string) ( $r['error'] ?? '' ), 'featured_media' ) );

// ---- v1.1.6 regression: every admin tab renders without a fatal ----
$GLOBALS['db_tables']['wp_mcp_oauth_clients'][] = (object) array( 'id' => 1, 'client_id' => 'test-client-1', 'client_name' => 'Claude', 'redirect_uris' => json_encode( array( 'https://claude.ai/api/mcp/auth_callback' ) ), 'created_at' => '2026-10-18 14:00:00' );
foreach ( array( 'ai-site-manager' => 'API Keys', 'ai-site-manager-tools' => 'Tools', 'ai-site-manager-approvals' => 'Approvals', 'ai-site-manager-clients' => 'OAuth Clients', 'ai-site-manager-logs' => 'Audit Log', 'ai-site-manager-settings' => 'Settings' ) as $_page => $_label ) {
	$_GET = array( 'page' => $_page );
	ob_start();
	$_fatal = null;
	try { MCP_Admin::render(); } catch ( Throwable $_e ) { $_fatal = $_e->getMessage(); }
	$_out = ob_get_clean();
	if ( $_fatal ) { fwrite( STDERR, "TABERR $_label: $_fatal\n" ); }
	check( "admin tab renders: $_label", null === $_fatal );
}
$_GET = array();

// Unknown access token rejected.
check( 'unknown access token rejected', null === MCP_OAuth::resolve_access_token( 'mcpat_nope' ) );

// v1.1.1: discovery served from the raw request path (no rewrite flush needed).
$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';
check( 'protected-resource detected from REQUEST_URI', 'protected-resource' === MCP_OAuth::discovery_request() );
$doc = MCP_OAuth::discovery_doc( 'protected-resource' );
check( 'protected-resource doc shape', 'https://example.test/wp-json/wp-mcp/v1/mcp' === ( $doc['resource'] ?? '' ) && array( 'https://example.test' ) === ( $doc['authorization_servers'] ?? array() ) );
$_SERVER['REQUEST_URI'] = '/.well-known/oauth-authorization-server';
$doc = MCP_OAuth::discovery_doc( MCP_OAuth::discovery_request() );
check( 'authorization-server doc has endpoints', ! empty( $doc['registration_endpoint'] ) && in_array( 'S256', $doc['code_challenge_methods_supported'] ?? array(), true ) );
$_SERVER['REQUEST_URI'] = '/blog/.well-known/oauth-protected-resource';
check( 'subdirectory installs not falsely matched (home at root)', '' === MCP_OAuth::discovery_request() );
$_SERVER['REQUEST_URI'] = '/';
check( 'plain request not matched', '' === MCP_OAuth::discovery_request() );

// v1.1.3: discovery responses carry CORS headers (raw init handler bypasses REST CORS).
$GLOBALS['wpmcp_test_headers'] = array();
$_SERVER['REQUEST_URI'] = '/.well-known/oauth-protected-resource';
ob_start();
try { MCP_OAuth::maybe_serve_discovery(); } catch ( Throwable $e ) { /* exits via die stub */ }
ob_end_clean();
$joined = implode( "\n", $GLOBALS['wpmcp_test_headers'] );
check( 'discovery response carries Access-Control-Allow-Origin', str_contains( $joined, 'Access-Control-Allow-Origin: *' ) );


// v1.1.1: upgrade routine runs activation when the stored version differs.
update_option( 'wpmcp_version', '1.0.0' );
unset( $GLOBALS['db_tables']['wp_mcp_oauth_clients'] );
MCP_Plugin::maybe_upgrade();
check( 'maybe_upgrade re-creates tables and stamps version', isset( $GLOBALS['db_tables']['wp_mcp_oauth_clients'] ) && WPMCP_VERSION === get_option( 'wpmcp_version' ) );

// Audit log captured everything.
$logs = MCP_Logger::query();
check( 'audit log has entries', $logs['total'] >= 10 );
$failed = MCP_Logger::query( array( 'status' => 'failed' ) );
check( 'failed filter works', $failed['total'] >= 4 );
$by_tool = MCP_Logger::query( array( 'tool_name' => 'posts_create' ) );
check( 'tool filter works', $by_tool['total'] >= 3 );

// Revocation.
MCP_Keys::revoke( $key2['id'] );
$req = new WP_REST_Request( '{}', array( 'Authorization' => 'Bearer ' . $key2['key'] ) );
check( 'revoked key rejected', is_wp_error( MCP_REST_Server::authenticate( $req ) ) );


// --- media_upload (base64) ---
$png1x1 = base64_encode( base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==' ) );
$jpeg_magic = base64_encode( "\xFF\xD8\xFF\xE0" . str_repeat( "\x00", 100 ) );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => $png1x1, 'filename' => 'dot.png', 'title' => 'Dot', 'alt' => 'a dot' ), $api_key );
check( 'media_upload: PNG uploads and returns attachment', ! empty( $r['ok'] ) && isset( $r['data']['id'] ) && 'image/png' === $r['data']['mime'] );
check( 'media_upload: alt text stored', get_post_meta( $r['data']['id'], '_wp_attachment_image_alt', true ) === 'a dot' );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => 'data:image/png;base64,' . $png1x1, 'filename' => 'dot2.png' ), $api_key );
check( 'media_upload: data-URI prefix accepted', ! empty( $r['ok'] ) && isset( $r['data']['id'] ) );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => $jpeg_magic, 'filename' => 'evil.php' ), $api_key );
check( 'media_upload: extension forced from content, not filename', ! empty( $r['ok'] ) && isset( $GLOBALS['uploads_bits']['evil.jpg'] ) );

$post_for_feat = wp_insert_post( array( 'post_title' => 'feat target', 'post_status' => 'draft', 'post_type' => 'post' ) );
$r = MCP_Executor::execute( 'media_upload', array( 'data' => $png1x1, 'filename' => 'f.png', 'post_id' => $post_for_feat, 'set_featured' => true ), $api_key );
check( 'media_upload: set_featured sets thumbnail', ! empty( $r['ok'] ) && (int) get_post_meta( $post_for_feat, '_thumbnail_id', true ) === (int) $r['data']['id'] );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => base64_encode( '<?php echo 1;' ), 'filename' => 'x.png' ), $api_key );
check( 'media_upload: non-image content rejected', str_contains( (string) ( $r['error'] ?? '' ), 'Unsupported file type' ) );

$big = base64_encode( str_repeat( 'A', 5 * 1024 * 1024 + 16 ) );
$r = MCP_Executor::execute( 'media_upload', array( 'data' => $big, 'filename' => 'big.png' ), $api_key );
check( 'media_upload: >5MB rejected', str_contains( (string) ( $r['error'] ?? '' ), 'too large' ) );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => '!!!notbase64!!!', 'filename' => 'x.png' ), $api_key );
check( 'media_upload: invalid base64 rejected', str_contains( (string) ( $r['error'] ?? '' ), 'base64' ) );

$r = MCP_Executor::execute( 'media_upload', array( 'data' => $png1x1, 'filename' => 'x.png', 'post_id' => 99999 ), $api_key );
check( 'media_upload: missing post_id rejected before upload', str_contains( (string) ( $r['error'] ?? '' ), 'post_id' ) );

$GLOBALS['caps'][2]['upload_files'] = false;
wp_set_current_user( 2 );
$api_key_user2 = (object) array( 'id' => 2, 'key_prefix' => 'wpmcp_test2', 'user_id' => 2, 'label' => 'editor' );
$r = MCP_Executor::execute( 'media_upload', array( 'data' => $png1x1, 'filename' => 'x.png' ), $api_key_user2 );
check( 'media_upload: upload_files capability enforced', str_contains( (string) ( $r['error'] ?? '' ), 'not allowed' ) );
$GLOBALS['caps'][2]['upload_files'] = true;
wp_set_current_user( 1 );


// --- type_matches hardening (union types, null, anyOf/oneOf) ---
$tm = new ReflectionMethod( 'MCP_Executor', 'type_matches' );
$tm->setAccessible( true );
check( 'type_matches: union ["string","array"] accepts string', $tm->invoke( null, array( 'string', 'array' ), 'hello' ) === true );
check( 'type_matches: union ["string","array"] accepts list', $tm->invoke( null, array( 'string', 'array' ), array( 'a', 'b' ) ) === true );
check( 'type_matches: union ["string","array"] rejects int', $tm->invoke( null, array( 'string', 'array' ), 42 ) === false );
check( 'type_matches: "null" accepts null only', $tm->invoke( null, 'null', null ) === true && $tm->invoke( null, 'null', '' ) === false );
check( 'type_matches: non-string constraint does not reject', $tm->invoke( null, null, 'x' ) === true && $tm->invoke( null, 123, 'x' ) === true );

$val = new ReflectionMethod( 'MCP_Executor', 'validate' );
$val->setAccessible( true );
$schema_union = array( 'properties' => array( 'v' => array( 'type' => array( 'string', 'array' ) ) ) );
check( 'validate: union type no longer TypeErrors, accepts string', $val->invoke( null, $schema_union, array( 'v' => 'x' ) ) === true );
check( 'validate: union type rejects bad value', is_wp_error( $val->invoke( null, $schema_union, array( 'v' => 4.5 ) ) ) );
$schema_anyof = array( 'properties' => array( 'v' => array( 'anyOf' => array( array( 'type' => 'integer' ), array( 'type' => 'string' ) ) ) ) );
check( 'validate: anyOf accepts a matching branch', $val->invoke( null, $schema_anyof, array( 'v' => 'x' ) ) === true );
check( 'validate: anyOf rejects when no branch matches', is_wp_error( $val->invoke( null, $schema_anyof, array( 'v' => array() ) ) ) );

// --- Yoast adapter ---
$yoast_post = wp_insert_post( array( 'post_title' => 'seo target', 'post_status' => 'publish', 'post_type' => 'post' ) );
$r = MCP_Executor::execute( 'yoast_meta_set', array( 'id' => $yoast_post, 'title' => 'SEO Title', 'meta_description' => 'SEO desc', 'focus_keyphrase' => 'mcp', 'noindex' => true ), $api_key );
check( 'yoast_meta_set: writes Yoast fields', ! empty( $r['ok'] ) && in_array( 'title', $r['data']['updated'], true ) );
check( 'yoast_meta_set: title stored under Yoast key', get_post_meta( $yoast_post, '_yoast_wpseo_title', true ) === 'SEO Title' );
check( 'yoast_meta_set: noindex stored', get_post_meta( $yoast_post, '_yoast_wpseo_meta-robots-noindex', true ) === '1' );

$r = MCP_Executor::execute( 'yoast_meta_get', array( 'id' => $yoast_post ), $api_key );
check( 'yoast_meta_get: reads back fields', ! empty( $r['ok'] ) && 'SEO desc' === $r['data']['meta_description'] && 'mcp' === $r['data']['focus_keyphrase'] && true === $r['data']['noindex'] );

$r = MCP_Executor::execute( 'yoast_meta_set', array( 'id' => $yoast_post, 'noindex' => false ), $api_key );
check( 'yoast_meta_set: noindex=false restores indexing', ! empty( $r['ok'] ) && '' === get_post_meta( $yoast_post, '_yoast_wpseo_meta-robots-noindex', true ) );

$r = MCP_Executor::execute( 'yoast_meta_set', array( 'id' => $yoast_post, 'canonical' => 'notaurl' ), $api_key );
check( 'yoast_meta_set: invalid canonical rejected', str_contains( (string) ( $r['error'] ?? '' ), 'canonical' ) );

$r = MCP_Executor::execute( 'yoast_meta_set', array( 'id' => $yoast_post, 'canonical' => 'https://example.com/canonical' ), $api_key );
check( 'yoast_meta_set: valid canonical stored', get_post_meta( $yoast_post, '_yoast_wpseo_canonical', true ) === 'https://example.com/canonical' );

$r = MCP_Executor::execute( 'yoast_meta_set', array( 'id' => $yoast_post ), $api_key );
check( 'yoast_meta_set: empty update rejected', str_contains( (string) ( $r['error'] ?? '' ), 'Nothing to update' ) );

$r = MCP_Executor::execute( 'yoast_meta_get', array( 'id' => 99999 ), $api_key );
check( 'yoast_meta_get: missing post errors', str_contains( (string) ( $r['error'] ?? '' ), 'not found' ) );


// --- Elementor bridge ---
$el_post = wp_insert_post( array( 'post_title' => 'el page', 'post_status' => 'publish', 'post_type' => 'page' ) );
update_post_meta( $el_post, '_elementor_data', '[{"id":"orig"}]' );
$ab_read  = new MockAbility( 'elementor/get-page-structure', true, array( 'type' => 'object', 'required' => array( 'post_id' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ) ) ), array( 'tree' => array() ) );
$ab_write = new MockAbility( 'elementor/manage-elements', false, array( 'type' => 'object', 'required' => array( 'post_id', 'operations' ), 'properties' => array( 'post_id' => array( 'type' => 'integer' ), 'operations' => array( 'type' => 'array', 'oneOf' => array() ) ) ), array( 'status' => 'ok' ) );
$ab_deny  = new MockAbility( 'elementor/clear-cache', false, array(), array( 'cleared' => true ), false );
$ab_other = new MockAbility( 'woocommerce/do-thing', true, array(), array() );
$GLOBALS['mock_abilities'] = array( $ab_read->name => $ab_read, $ab_write->name => $ab_write, $ab_deny->name => $ab_deny, $ab_other->name => $ab_other );
$rp = new ReflectionProperty( 'MCP_Registry', 'tools' ); $rp->setAccessible( true ); $rp->setValue( null, null );
update_option( 'wpmcp_ability_namespaces', 'elementor' );

check( 'elementor: bridged tool names', null !== MCP_Registry::get( 'elementor_get_page_structure' ) && null !== MCP_Registry::get( 'elementor_manage_elements' ) );
check( 'elementor: non-elementor ability not bridged', null === MCP_Registry::get( 'ability_woocommerce_do_thing' ) && null === MCP_Registry::get( 'woocommerce_do_thing' ) );
check( 'elementor: read tool is non-destructive and enabled', ! MCP_Registry::get( 'elementor_get_page_structure' )->destructive && MCP_Registry::is_enabled( 'elementor_get_page_structure' ) );
check( 'elementor: write tool is destructive and off by default', MCP_Registry::get( 'elementor_manage_elements' )->destructive && ! MCP_Registry::is_enabled( 'elementor_manage_elements' ) );
check( 'elementor: real schema shown to clients', isset( MCP_Registry::get( 'elementor_manage_elements' )->to_mcp_schema()['inputSchema']['properties']['operations'] ) );

$r = MCP_Executor::execute( 'elementor_status', array(), $api_key );
check( 'elementor_status: reports problems when Elementor missing', ! empty( $r['ok'] ) && 2 <= count( $r['data']['problems'] ) && 3 === $r['data']['bridged_abilities'] );

$r = MCP_Executor::execute( 'elementor_get_page_structure', array( 'post_id' => $el_post ), $api_key );
check( 'elementor read: runs ability, no backup', ! empty( $r['ok'] ) && ! isset( $r['data']['_backup_id'] ) && 1 === count( $ab_read->calls ) );

$r = MCP_Executor::execute( 'elementor_manage_elements', array( 'post_id' => $el_post, 'operations' => array() ), $api_key );
check( 'elementor write: blocked while tool is off', empty( $r['ok'] ) && 0 === count( $ab_write->calls ) );
MCP_Registry::set_enabled( 'elementor_manage_elements', true );
update_option( 'wpmcp_require_approval', 1 );
$r = MCP_Executor::execute( 'elementor_manage_elements', array( 'post_id' => $el_post, 'operations' => array() ), $api_key );
check( 'elementor write: queued for approval, not executed', ! empty( $r['pending_approval'] ) && 0 === count( $ab_write->calls ) );
$qid = $r['pending_approval'];
$ap = MCP_Executor::approve( (int) $qid, 1 );
check( 'elementor write: runs after approval', ! empty( $ap['ok'] ) && 1 === count( $ab_write->calls ) );
check( 'elementor write: backup taken before write', is_array( get_post_meta( $el_post, '_asm_elementor_backups', true ) ) && 1 === count( get_post_meta( $el_post, '_asm_elementor_backups', true ) ) );

$r = MCP_Executor::execute( 'elementor_backups_list', array( 'post_id' => $el_post ), $api_key );
$bid = $r['data']['backups'][0]['backup_id'] ?? '';
check( 'elementor_backups_list: lists backup', ! empty( $r['ok'] ) && '' !== $bid );
check( 'elementor: data was changed by write', str_contains( (string) get_post_meta( $el_post, '_elementor_data', true ), 'changed' ) );
MCP_Registry::set_enabled( 'elementor_backup_restore', true );
$r = MCP_Executor::execute( 'elementor_backup_restore', array( 'post_id' => $el_post, 'backup_id' => $bid ), $api_key );
check( 'elementor_backup_restore: queued', ! empty( $r['pending_approval'] ) );
MCP_Executor::approve( (int) $r['pending_approval'], 1 );
check( 'elementor_backup_restore: original data back', str_contains( (string) get_post_meta( $el_post, '_elementor_data', true ), 'orig' ) );
$r = MCP_Executor::execute( 'elementor_backup_restore', array( 'post_id' => $el_post, 'backup_id' => 'nope' ), $api_key );
MCP_Executor::approve( (int) ( $r['pending_approval'] ?? 0 ), 1 );
check( 'elementor_backup_restore: unknown backup id does not change data', str_contains( (string) get_post_meta( $el_post, '_elementor_data', true ), 'orig' ) );

MCP_Registry::set_enabled( 'elementor_clear_cache', true );
$r = MCP_Executor::execute( 'elementor_clear_cache', array(), $api_key );
check( 'elementor: ability permission denial blocks tool', empty( $r['ok'] ) && empty( $r['pending_approval'] ) && 0 === count( $ab_deny->calls ) );

$ab_err = new MockAbility( 'elementor/create-page', true, array(), new WP_Error( 'bad', 'Bad input from Elementor' ) );
$GLOBALS['mock_abilities'][ $ab_err->name ] = $ab_err; $rp->setValue( null, null );
$r = MCP_Executor::execute( 'elementor_create_page', array(), $api_key );
check( 'elementor: WP_Error from ability becomes tool error', empty( $r['ok'] ) && str_contains( (string) ( $r['error'] ?? '' ), 'Bad input from Elementor' ) );
unset( $GLOBALS['mock_abilities'] ); $rp->setValue( null, null );

// --- v1.2.1: namespaces setting + meta tools ---
$GLOBALS['mock_abilities'] = array( $ab_read->name => $ab_read, $ab_write->name => $ab_write, $ab_other->name => $ab_other );
$ab_fw = new MockAbility( 'focusweb-ai-visibility/search-content', true, array( 'type' => 'object', 'properties' => array( 'q' => array( 'type' => 'string' ) ), 'required' => array( 'q' ) ), array( 'count' => 1 ) );
$ab_fw_w = new MockAbility( 'acme/change-thing', false, array(), array( 'done' => true ) );
$GLOBALS['mock_abilities'][ $ab_fw->name ] = $ab_fw; $GLOBALS['mock_abilities'][ $ab_fw_w->name ] = $ab_fw_w;
update_option( 'wpmcp_ability_namespaces', 'elementor' ); $rp->setValue( null, null );
check( 'ns list: other namespace not bridged', null === MCP_Registry::get( 'ability_focusweb_ai_visibility_search_content' ) );
update_option( 'wpmcp_ability_namespaces', 'elementor, focusweb-ai-visibility' ); $rp->setValue( null, null );
check( 'ns list: listed namespace bridged', null !== MCP_Registry::get( 'ability_focusweb_ai_visibility_search_content' ) && null === MCP_Registry::get( 'ability_acme_change_thing' ) );
update_option( 'wpmcp_ability_namespaces', '*' ); $rp->setValue( null, null );
check( 'ns *: every namespace bridged', null !== MCP_Registry::get( 'ability_acme_change_thing' ) && null !== MCP_Registry::get( 'ability_woocommerce_do_thing' ) );
check( 'ns *: read-only ability on, write ability off', MCP_Registry::is_enabled( 'ability_focusweb_ai_visibility_search_content' ) && ! MCP_Registry::is_enabled( 'ability_acme_change_thing' ) );
delete_option( 'wpmcp_ability_namespaces' ); $rp->setValue( null, null );
check( 'ns default is * when option unset', null !== MCP_Registry::get( 'ability_acme_change_thing' ) );

$r = MCP_Executor::execute( 'abilities_list', array( 'namespace' => 'acme' ), $api_key );
check( 'abilities_list: filters namespace', ! empty( $r['ok'] ) && 1 === $r['data']['count'] && false === $r['data']['abilities'][0]['read_only'] );
$r = MCP_Executor::execute( 'abilities_list', array(), $api_key );
check( 'abilities_list: lists all', ! empty( $r['ok'] ) && 5 === $r['data']['count'] );
$r = MCP_Executor::execute( 'ability_read', array( 'id' => 'focusweb-ai-visibility/search-content', 'input' => array( 'q' => 'x' ) ), $api_key );
check( 'ability_read: runs read-only ability', ! empty( $r['ok'] ) && 1 === $r['data']['count'] );
$r = MCP_Executor::execute( 'ability_read', array( 'id' => 'acme/change-thing' ), $api_key );
check( 'ability_read: refuses non-read-only ability', empty( $r['ok'] ) && 0 === count( $ab_fw_w->calls ) );
$r = MCP_Executor::execute( 'ability_read', array( 'id' => 'nope/none' ), $api_key );
check( 'ability_read: unknown id errors', empty( $r['ok'] ) && str_contains( (string) $r['error'], 'Unknown ability' ) );
$r = MCP_Executor::execute( 'ability_execute', array( 'id' => 'acme/change-thing' ), $api_key );
check( 'ability_execute: off by default', empty( $r['ok'] ) && 0 === count( $ab_fw_w->calls ) );
MCP_Registry::set_enabled( 'ability_execute', true );
$r = MCP_Executor::execute( 'ability_execute', array( 'id' => 'acme/change-thing' ), $api_key );
check( 'ability_execute: queued for approval', ! empty( $r['pending_approval'] ) && 0 === count( $ab_fw_w->calls ) );
$ap = MCP_Executor::approve( (int) $r['pending_approval'], 1 );
check( 'ability_execute: runs after approval', ! empty( $ap['ok'] ) && 1 === count( $ab_fw_w->calls ) );
$r = MCP_Executor::execute( 'elementor_status', array(), $api_key );
check( 'elementor_status: lists all registered abilities', ! empty( $r['ok'] ) && 5 === count( $r['data']['all_registered_abilities'] ) && array( '*' ) === $r['data']['bridged_namespaces'] );
$GLOBALS['mock_abilities'] = array(); $rp->setValue( null, null );

echo $failures ? "\n{$failures} FAILURES\n" : "\nALL TESTS PASSED\n";
exit( $failures ? 1 : 0 );


