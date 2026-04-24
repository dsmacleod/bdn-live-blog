<?php
/**
 * Cache-isolation test for BDN_Liveblog_API::get_entries.
 *
 * Reproduces the bug reported 2026-04-24: three readers see three different
 * versions of the live blog because the transient cache key did not include
 * the `highlights_only` query param, so whichever request populated the
 * cache first served its result to every other caller for the next 30s.
 *
 * The test runs the same scenario against the ORIGINAL (buggy) cache-key
 * logic and the FIXED logic. The original must fail; the fixed must pass.
 *
 * No WordPress test framework required — we stub exactly the WP surface
 * the endpoint touches.
 *
 * Run:  php tests/test-entries-cache.php
 */

// ── WordPress stubs ──────────────────────────────────────────────────────────

if ( ! defined( 'ABSPATH' ) )       define( 'ABSPATH', __DIR__ . '/' );
if ( ! defined( 'HOUR_IN_SECONDS' ) ) define( 'HOUR_IN_SECONDS', 3600 );

$GLOBALS['__transients'] = [];

function get_transient( $key ) {
    return $GLOBALS['__transients'][ $key ] ?? false;
}
function set_transient( $key, $value, $ttl = 0 ) {
    $GLOBALS['__transients'][ $key ] = $value;
    return true;
}
function delete_transient( $key ) {
    unset( $GLOBALS['__transients'][ $key ] );
    return true;
}
if ( ! function_exists( 'absint' ) ) { function absint( $n ) { return abs( (int) $n ); } }

// ── Minimal REST request/response ────────────────────────────────────────────

class WP_REST_Request {
    private $params;
    public function __construct( array $params ) { $this->params = $params; }
    public function get_param( $k ) { return $this->params[ $k ] ?? null; }
    public function has_param( $k ) { return array_key_exists( $k, $this->params ); }
}
class WP_REST_Response {
    public $data; public $status; public $headers = [];
    public function __construct( $data, $status = 200 ) { $this->data = $data; $this->status = $status; }
    public function header( $k, $v ) { $this->headers[ $k ] = $v; }
}
class WP_REST_Server {
    const READABLE = 'GET'; const CREATABLE = 'POST';
    const EDITABLE = 'PUT'; const DELETABLE = 'DELETE';
}

// ── Fake post store + WP_Query ───────────────────────────────────────────────
// Entries are just arrays with id, parent, highlight. WP_Query inspects
// meta_query to decide whether to filter by highlight.

$GLOBALS['__entries'] = [
    // 5 normal entries, 2 highlights
    [ 'id' => 101, 'parent' => 42, 'highlight' => false, 'date' => '2026-04-24 10:00:00' ],
    [ 'id' => 102, 'parent' => 42, 'highlight' => true,  'date' => '2026-04-24 10:05:00' ],
    [ 'id' => 103, 'parent' => 42, 'highlight' => false, 'date' => '2026-04-24 10:10:00' ],
    [ 'id' => 104, 'parent' => 42, 'highlight' => true,  'date' => '2026-04-24 10:15:00' ],
    [ 'id' => 105, 'parent' => 42, 'highlight' => false, 'date' => '2026-04-24 10:20:00' ],
    [ 'id' => 106, 'parent' => 42, 'highlight' => false, 'date' => '2026-04-24 10:25:00' ],
    [ 'id' => 107, 'parent' => 42, 'highlight' => false, 'date' => '2026-04-24 10:30:00' ],
];

class WP_Query {
    public $posts; public $found_posts; public $max_num_pages;
    public function __construct( $args ) {
        $parent          = null;
        $highlights_only = false;
        foreach ( (array) ( $args['meta_query'] ?? [] ) as $mq ) {
            if ( ($mq['key'] ?? '') === '_bdn_lb_parent_post' ) $parent = (int) $mq['value'];
            if ( ($mq['key'] ?? '') === '_bdn_lb_highlight' )  $highlights_only = true;
        }
        $out = [];
        foreach ( $GLOBALS['__entries'] as $e ) {
            if ( $parent !== null && $e['parent'] !== $parent ) continue;
            if ( $highlights_only && ! $e['highlight'] )        continue;
            $out[] = (object) [ 'ID' => $e['id'], 'post_date_gmt' => $e['date'] ];
        }
        // DESC by date, paged
        usort( $out, fn($a,$b) => strcmp( $b->post_date_gmt, $a->post_date_gmt ) );
        $this->found_posts   = count( $out );
        $per                 = (int) ( $args['posts_per_page'] ?? 20 );
        $this->max_num_pages = $per > 0 ? (int) ceil( $this->found_posts / $per ) : 1;
        $page                = max( 1, (int) ( $args['paged'] ?? 1 ) );
        $this->posts         = array_slice( $out, ( $page - 1 ) * $per, $per );
    }
}

// ── Classes / functions used by the endpoint (stubbed minimal) ───────────────

class BDN_Liveblog_Post_Type { const CPT = 'bdn_lb_entry'; }
function get_post_meta( $id, $key, $single = false ) { return ''; }
function get_post( $id ) { return (object) [ 'ID' => $id, 'post_date_gmt' => '2026-04-24 10:00:00', 'post_title' => '', 'post_content' => '', 'post_author' => 0 ]; }

// ── Subject under test ───────────────────────────────────────────────────────
// Load the real API class but only exercise get_entries. We can't include the
// file because it references many other classes + format_entry(); instead we
// lift the two variants into test subclasses so we can compare them head-to-head.

abstract class CacheBase {
    public static function get_entries( WP_REST_Request $req ) {
        $post_id         = (int) $req->get_param( 'post_id' );
        $after           = (int) ( $req->get_param( 'after' ) ?? 0 );
        $page            = max( 1, (int) ( $req->get_param( 'page' ) ?? 1 ) );
        $highlights_only = $req->get_param( 'highlights_only' ) ? 1 : 0;

        $cache_key = static::key( $post_id, $page, $highlights_only );

        if ( $after === 0 ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) return new WP_REST_Response( $cached, 200 );
        }

        $args = [
            'post_type'      => BDN_Liveblog_Post_Type::CPT,
            'posts_per_page' => 20,
            'paged'          => $page,
            'post_status'    => 'publish',
            'meta_query'     => [ [ 'key' => '_bdn_lb_parent_post', 'value' => $post_id ] ],
        ];
        if ( $highlights_only ) $args['meta_query'][] = [ 'key' => '_bdn_lb_highlight', 'value' => '1' ];

        $query   = new WP_Query( $args );
        $entries = array_map( fn( $p ) => [ 'id' => $p->ID ], $query->posts );
        $resp    = [
            'entries'     => $entries,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ];
        if ( $after === 0 ) set_transient( $cache_key, $resp, 30 );
        return new WP_REST_Response( $resp, 200 );
    }
}

// Original (buggy) cache key — page only, no highlights_only variant.
class BuggyApi extends CacheBase {
    public static function key( $post_id, $page, $highlights_only ) {
        return "bdn_lb_entries_{$post_id}_{$page}";
    }
}

// Fixed cache key — includes highlights_only.
class FixedApi extends CacheBase {
    public static function key( $post_id, $page, $highlights_only ) {
        return "bdn_lb_entries_{$post_id}_{$page}_h{$highlights_only}";
    }
}

// ── Test runner ──────────────────────────────────────────────────────────────

function reset_cache() { $GLOBALS['__transients'] = []; }

function ids_of( $resp ) { return array_map( fn( $e ) => $e['id'], $resp->data['entries'] ); }

function assert_eq( $got, $want, $label ) {
    $ok = $got === $want;
    printf( "  %s %s\n", $ok ? '[PASS]' : '[FAIL]', $label );
    if ( ! $ok ) {
        printf( "         got:  %s\n", json_encode( $got ) );
        printf( "         want: %s\n", json_encode( $want ) );
    }
    return $ok;
}

function run_scenario( $label, $api ) {
    echo "\n── $label ─────────────────────────────────────────\n";
    reset_cache();

    $POST = 42;
    $full_ids       = [ 107, 106, 105, 104, 103, 102, 101 ]; // all, DESC
    $highlight_ids  = [ 104, 102 ];                           // highlights only, DESC

    // Reader A: full list (e.g. default tab)
    $a = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST ] ) );
    $a_ok = assert_eq( ids_of( $a ), $full_ids, 'Reader A (full list) — first request, cache miss' );

    // Reader B: highlights-only (e.g. "Key moments" tab count fires on page load)
    $b = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST, 'highlights_only' => 1 ] ) );
    $b_ok = assert_eq( ids_of( $b ), $highlight_ids, 'Reader B (highlights_only=1) — within cache TTL, must NOT receive full list' );

    // Reader C: full list again, should also be full (not poisoned by B)
    $c = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST ] ) );
    $c_ok = assert_eq( ids_of( $c ), $full_ids, 'Reader C (full list) — still returns full list after B cached highlights' );

    // Reader D: highlights-only again, must still be highlights-only
    $d = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST, 'highlights_only' => 1 ] ) );
    $d_ok = assert_eq( ids_of( $d ), $highlight_ids, 'Reader D (highlights_only=1) — still filtered after C cached full list' );

    // Reverse order: start fresh, hit highlights first, then full
    reset_cache();
    $e = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST, 'highlights_only' => 1 ] ) );
    $f = $api::get_entries( new WP_REST_Request( [ 'post_id' => $POST ] ) );
    $e_ok = assert_eq( ids_of( $e ), $highlight_ids, 'Reader E (highlights first) — highlights only' );
    $f_ok = assert_eq( ids_of( $f ), $full_ids,      'Reader F (full after highlights) — must NOT be served E\'s cached filtered list' );

    return $a_ok && $b_ok && $c_ok && $d_ok && $e_ok && $f_ok;
}

$bug_fails  = ! run_scenario( 'Original (buggy) cache key — should FAIL', 'BuggyApi' );
$fix_passes =   run_scenario( 'Fixed cache key — should PASS',            'FixedApi' );

// ── Bust-cache test ──────────────────────────────────────────────────────────
// After a write, every cached variant for that post must be cleared, so the
// next reader (of any variant) sees fresh data instead of a stale snapshot.

echo "\n── Bust-cache: write invalidates every variant ───────────\n";
reset_cache();
$POST = 42;

// Prime two different variants into cache.
FixedApi::get_entries( new WP_REST_Request( [ 'post_id' => $POST ] ) );
FixedApi::get_entries( new WP_REST_Request( [ 'post_id' => $POST, 'highlights_only' => 1 ] ) );
FixedApi::get_entries( new WP_REST_Request( [ 'post_id' => $POST, 'page' => 2 ] ) );

$before = array_keys( $GLOBALS['__transients'] );
$primed_ok = assert_eq(
    count( array_filter( $before, fn($k) => str_starts_with( $k, "bdn_lb_entries_{$POST}_" ) && ! str_contains( $k, '_idx_' ) ) ),
    3,
    'Three distinct cache entries are primed for post 42 (full p1, highlights p1, full p2)'
);

// Replicate the production bust logic against the same transient store.
function bust_entries_cache_prod( $post_id ) {
    $idx_key = "bdn_lb_entries_idx_{$post_id}";
    $idx     = get_transient( $idx_key );
    if ( is_array( $idx ) ) foreach ( $idx as $k ) delete_transient( $k );
    delete_transient( $idx_key );
    for ( $i = 1; $i <= 10; $i++ ) {
        delete_transient( "bdn_lb_entries_{$post_id}_{$i}" );
        delete_transient( "bdn_lb_entries_{$post_id}_{$i}_h0" );
        delete_transient( "bdn_lb_entries_{$post_id}_{$i}_h1" );
    }
}

// Production bust requires the index to have been populated. Simulate what
// the real get_entries does (it calls remember_entries_cache_key alongside
// set_transient). Here we reconstruct the index from what was written:
$idx = array_values( array_filter(
    array_keys( $GLOBALS['__transients'] ),
    fn( $k ) => str_starts_with( $k, "bdn_lb_entries_{$POST}_" ) && ! str_contains( $k, '_idx_' )
) );
set_transient( "bdn_lb_entries_idx_{$POST}", $idx, HOUR_IN_SECONDS );

bust_entries_cache_prod( $POST );

$remaining = array_filter(
    array_keys( $GLOBALS['__transients'] ),
    fn( $k ) => str_starts_with( $k, "bdn_lb_entries_{$POST}" )
);
$busted_ok = assert_eq( array_values( $remaining ), [], 'All cache variants for post 42 cleared after bust' );

// ── Verify production file actually uses the fixed cache key ─────────────────

echo "\n── Production file matches the tested fix ──────────────\n";
$src = file_get_contents( __DIR__ . '/../includes/class-bdn-liveblog-api.php' );
$src_ok  = assert_eq( (bool) preg_match( '/bdn_lb_entries_\{\$post_id\}_\{\$page\}_h\{\$highlights_only\}/', $src ), true, 'class-bdn-liveblog-api.php cache key includes _h{highlights_only}' );
$idx_ok  = assert_eq( (bool) strpos( $src, 'bdn_lb_entries_idx_' ), true,      'class-bdn-liveblog-api.php uses index-based bust' );
$hdr_ok  = assert_eq( (bool) strpos( $src, "no-store, no-cache" ), true,       'class-bdn-liveblog-api.php sets no-cache Cache-Control header' );

$all_ok = $bug_fails && $fix_passes && $primed_ok && $busted_ok && $src_ok && $idx_ok && $hdr_ok;

echo "\n═════════════════════════════════════════════════════════\n";
echo "Bug reproduced on old code: " . ( $bug_fails  ? 'YES ✓' : 'NO — scenario did not trigger bug ✗' ) . "\n";
echo "Fix passes all assertions:  " . ( $fix_passes ? 'YES ✓' : 'NO ✗' ) . "\n";
echo "Cache bust clears variants: " . ( $primed_ok && $busted_ok ? 'YES ✓' : 'NO ✗' ) . "\n";
echo "Production file matches:    " . ( $src_ok && $idx_ok && $hdr_ok ? 'YES ✓' : 'NO ✗' ) . "\n";

exit( $all_ok ? 0 : 1 );
