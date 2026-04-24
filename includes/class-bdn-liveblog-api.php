<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_API {

    const NS = 'bdn-liveblog/v1';

    public static function register_routes() {
        // GET  /entries?post_id=X&after=TIMESTAMP
        register_rest_route( self::NS, '/entries', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_entries' ],
                'permission_callback' => '__return_true',
                'args'                => [
                    'post_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                    'after'   => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
                    'page'    => [ 'required' => false, 'type' => 'integer', 'default' => 1 ],
                    'highlights_only' => [ 'required' => false, 'type' => 'integer', 'default' => 0 ],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [ __CLASS__, 'create_entry' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::entry_args(),
            ],
        ]);

        // GET|PUT|DELETE /entries/{id}
        register_rest_route( self::NS, '/entries/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [ __CLASS__, 'get_entry' ],
                'permission_callback' => '__return_true',
                'args'                => [ 'id' => [ 'required' => true, 'type' => 'integer' ] ],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [ __CLASS__, 'update_entry' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
                'args'                => self::entry_args( false ),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [ __CLASS__, 'delete_entry' ],
                'permission_callback' => [ __CLASS__, 'can_edit' ],
            ],
        ]);

        // GET /status?post_id=X  — lightweight endpoint, just returns live status + entry count + last_modified
        register_rest_route( self::NS, '/status', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_status' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'post_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ]);

        // POST /entries/{id}/regenerate-slug — force a fresh AI slug
        register_rest_route( self::NS, '/entries/(?P<id>\d+)/regenerate-slug', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'regenerate_slug' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ],
        ]);

        // POST /status  — update live status
        register_rest_route( self::NS, '/status', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'set_status' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ],
            'args'                => [
                'post_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
                'status'  => [ 'required' => true, 'type' => 'string', 'enum' => [ 'live', 'ended', 'scheduled' ] ],
            ],
        ]);

        // GET /summary?post_id=X — AI-generated "story so far" from all entries
        register_rest_route( self::NS, '/summary', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [ __CLASS__, 'get_summary' ],
            'permission_callback' => '__return_true',
            'args'                => [
                'post_id' => [ 'required' => true, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            ],
        ]);

        // POST /upload-inline-image — sideload an inline image from the composer
        // into the media library. Exists because Newspack/hardened WP often
        // blocks /wp/v2/media for Editors with a 403 rest_cannot_create, even
        // when the same user can upload via the Media Library modal. We gate
        // on our own edit_posts check (same as every other write route).
        register_rest_route( self::NS, '/upload-inline-image', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [ __CLASS__, 'upload_inline_image' ],
            'permission_callback' => [ __CLASS__, 'can_edit' ],
        ]);
    }

    // ── POST /upload-inline-image ──────────────────────────────────────────────

    public static function upload_inline_image( WP_REST_Request $req ) {
        if ( ! current_user_can( 'upload_files' ) ) {
            return new WP_Error( 'rest_forbidden', 'You do not have permission to upload files.', [ 'status' => 403 ] );
        }
        $files = $req->get_file_params();
        if ( empty( $files['file'] ) ) {
            return new WP_Error( 'no_file', 'No file uploaded.', [ 'status' => 400 ] );
        }
        // WordPress needs these for media_handle_sideload.
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        // Allow only image types.
        $file     = $files['file'];
        $mime     = $file['type'] ?? '';
        $allowed  = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ];
        if ( ! in_array( $mime, $allowed, true ) ) {
            return new WP_Error( 'bad_mime', 'Only JPEG, PNG, GIF, and WebP images are allowed.', [ 'status' => 400 ] );
        }

        $_FILES = [ 'file' => $file ];
        $attachment_id = media_handle_upload( 'file', 0 );
        if ( is_wp_error( $attachment_id ) ) {
            return new WP_Error( 'upload_failed', $attachment_id->get_error_message(), [ 'status' => 500 ] );
        }

        $url = wp_get_attachment_url( $attachment_id );
        return new WP_REST_Response( [ 'id' => (int) $attachment_id, 'url' => $url ], 201 );
    }

    // ── Permissions ────────────────────────────────────────────────────────────

    public static function can_edit() {
        return current_user_can( 'edit_posts' );
    }

    // ── Args ───────────────────────────────────────────────────────────────────

    private static function entry_args( $post_id_required = true ) {
        return [
            'post_id'       => [ 'required' => $post_id_required, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            'content'       => [ 'required' => $post_id_required, 'type' => 'string', 'sanitize_callback' => 'wp_kses_post' ],
            'title'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'byline'        => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'label'         => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'image_id'      => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            'image_caption' => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'image_credit'  => [ 'required' => false, 'type' => 'string', 'sanitize_callback' => 'sanitize_text_field' ],
            'pinned'        => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
            'highlight'     => [ 'required' => false, 'type' => 'integer', 'sanitize_callback' => 'absint' ],
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function format_entry( WP_Post $post ) {
        $author_id = (int) $post->post_author;
        $title     = get_the_title( $post );

        // Resolve byline: entry-level override first, then the reporter's BDN profile.
        $byline_override = get_post_meta( $post->ID, '_bdn_lb_byline', true );
        if ( $byline_override ) {
            $byline    = $byline_override;
            $photo_url = get_avatar_url( $author_id, [ 'size' => 80 ] );
        } else {
            $profile   = BDN_Liveblog_Profiles::get_byline_data( $author_id );
            $byline    = $profile['name'];
            $photo_url = $profile['photo_url'];
        }

        // Image — resolve attachment to URLs at two sizes.
        $image_id      = (int) get_post_meta( $post->ID, '_bdn_lb_image_id', true );
        $image_full    = $image_id ? wp_get_attachment_image_url( $image_id, 'large' )     : '';
        $image_thumb   = $image_id ? wp_get_attachment_image_url( $image_id, 'medium' )    : '';
        $image_srcset  = $image_id ? wp_get_attachment_image_srcset( $image_id, 'large' )  : '';
        $image_alt     = $image_id ? get_post_meta( $image_id, '_wp_attachment_image_alt', true ) : '';
        $image_caption = get_post_meta( $post->ID, '_bdn_lb_image_caption', true )
                         ?: ( $image_id ? wp_get_attachment_caption( $image_id ) : '' );
        $image_credit  = get_post_meta( $post->ID, '_bdn_lb_image_credit', true );

        return [
            'id'            => $post->ID,
            'title'         => $title,
            'content'       => self::render_entry_content( $post->post_content ),
            'byline'        => $byline,
            'label'         => get_post_meta( $post->ID, '_bdn_lb_label', true ),
            'author_avatar' => $photo_url,
            'published'     => get_post_time( 'c', true, $post ),
            'modified'      => get_post_modified_time( 'c', true, $post ),
            'timestamp'     => get_post_time( 'U', true, $post ),
            'image_id'      => $image_id ?: null,
            'image_url'     => $image_full,
            'image_thumb'   => $image_thumb,
            'image_srcset'  => $image_srcset,
            'image_alt'     => $image_alt,
            'image_caption' => $image_caption,
            'image_credit'  => $image_credit,
            'pinned'        => (bool) get_post_meta( $post->ID, '_bdn_lb_pinned', true ),
            'highlight'     => (bool) get_post_meta( $post->ID, '_bdn_lb_highlight', true ),
            'entry_url'     => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, $title ),
            'anchor_url'    => BDN_Liveblog_Slug::get_anchor_url( $post->ID ),
            'seo_slug'      => get_post_meta( $post->ID, '_bdn_lb_seo_slug', true ),
            'meta_description'    => get_post_meta( $post->ID, '_bdn_lb_meta_description', true ),
            'keywords'            => get_post_meta( $post->ID, '_bdn_lb_keywords', true ),
            'entities'            => json_decode( get_post_meta( $post->ID, '_bdn_lb_entities', true ) ?: '[]', true ),
        ];
    }

    /**
     * Lightweight content rendering for entries — avoids the full the_content
     * filter chain which can trigger oEmbed HTTP requests, shortcode recursion,
     * and the liveblog auto-inject filter, all of which hang or bloat the API.
     */
    public static function render_entry_content( string $raw ): string {
        global $wp_embed;
        if ( $wp_embed ) {
            $raw = $wp_embed->autoembed( $raw );
        }
        $content = wptexturize( $raw );
        $content = wpautop( $content );
        $content = shortcode_unautop( $content );
        $content = wp_filter_content_tags( $content );
        $content = do_shortcode( $content );
        $content = convert_smilies( $content );
        return $content;
    }

    // Cache key must include every filter that changes the result set, otherwise
    // the first caller's response gets served to readers asking for a different
    // view (e.g. ?highlights_only=1 vs. full list) within the 30s TTL.
    private static function entries_cache_key( int $post_id, int $page, int $highlights_only = 0 ): string {
        return "bdn_lb_entries_{$post_id}_{$page}_h{$highlights_only}";
    }

    // Track every cache key we've written for a given parent post so we can
    // bust them all on write without hard-coding page/variant counts.
    private static function entries_cache_index_key( int $post_id ): string {
        return "bdn_lb_entries_idx_{$post_id}";
    }

    private static function remember_entries_cache_key( int $post_id, string $key ) {
        $idx_key = self::entries_cache_index_key( $post_id );
        $idx     = get_transient( $idx_key );
        if ( ! is_array( $idx ) ) $idx = [];
        if ( ! in_array( $key, $idx, true ) ) {
            $idx[] = $key;
            set_transient( $idx_key, $idx, HOUR_IN_SECONDS );
        }
    }

    private static function bust_entries_cache( int $post_id ) {
        $idx_key = self::entries_cache_index_key( $post_id );
        $idx     = get_transient( $idx_key );
        if ( is_array( $idx ) ) {
            foreach ( $idx as $key ) delete_transient( $key );
        }
        delete_transient( $idx_key );
        // Belt-and-braces for old unindexed keys from pre-1.2.2 deploys.
        for ( $i = 1; $i <= 10; $i++ ) {
            delete_transient( "bdn_lb_entries_{$post_id}_{$i}" );
            delete_transient( "bdn_lb_entries_{$post_id}_{$i}_h0" );
            delete_transient( "bdn_lb_entries_{$post_id}_{$i}_h1" );
        }
    }

    // ── GET /entries ───────────────────────────────────────────────────────────

    public static function get_entries( WP_REST_Request $req ) {
        $post_id         = $req->get_param( 'post_id' );
        $after           = $req->get_param( 'after' );
        $page            = max( 1, $req->get_param( 'page' ) );
        $highlights_only = $req->get_param( 'highlights_only' ) ? 1 : 0;

        $cache_key = self::entries_cache_key( $post_id, $page, $highlights_only );

        if ( $after == 0 ) {
            $cached = get_transient( $cache_key );
            if ( $cached !== false ) {
                return new WP_REST_Response( $cached, 200 );
            }
        }

        $args = [
            'post_type'      => BDN_Liveblog_Post_Type::CPT,
            'posts_per_page' => 20,
            'paged'          => $page,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'DESC',
            'meta_query'     => [
                [ 'key' => '_bdn_lb_parent_post', 'value' => $post_id, 'type' => 'NUMERIC' ],
            ],
        ];

        if ( $highlights_only ) {
            $args['meta_query'][] = [ 'key' => '_bdn_lb_highlight', 'value' => '1' ];
        }

        if ( $after > 0 ) {
            $args['date_query'] = [ [ 'after' => gmdate( 'Y-m-d H:i:s', $after ), 'column' => 'post_date_gmt' ] ];
            $args['posts_per_page'] = 100; // polling — return all new
            unset( $args['paged'] );
        }

        $query   = new WP_Query( $args );
        $entries = array_map( [ __CLASS__, 'format_entry' ], $query->posts );

        $response_data = [
            'entries'     => $entries,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ];

        if ( $after == 0 ) {
            set_transient( $cache_key, $response_data, 30 );
            self::remember_entries_cache_key( $post_id, $cache_key );
        }

        $response = new WP_REST_Response( $response_data, 200 );
        // Prevent upstream caches (Cloudflare, WP Rocket page cache, etc.) from
        // re-serving one visitor's view — the 30s transient handles server-side
        // reuse; every HTTP request should still hit PHP.
        $response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0' );
        $response->header( 'Pragma', 'no-cache' );
        return $response;
    }

    // ── GET /entries/{id} ──────────────────────────────────────────────────────

    public static function get_entry( WP_REST_Request $req ) {
        $post = get_post( $req->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
            return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
        }
        return new WP_REST_Response( self::format_entry( $post ), 200 );
    }

    // ── POST /entries ──────────────────────────────────────────────────────────

    public static function create_entry( WP_REST_Request $req ) {
        $post_id = $req->get_param( 'post_id' );
        if ( ! get_post( $post_id ) ) {
            return new WP_Error( 'invalid_post', 'Parent post not found.', [ 'status' => 400 ] );
        }

        $entry_id = wp_insert_post( [
            'post_type'    => BDN_Liveblog_Post_Type::CPT,
            'post_status'  => 'publish',
            'post_title'   => $req->get_param( 'title' ) ?: '',
            'post_content' => $req->get_param( 'content' ),
            'post_author'  => get_current_user_id(),
        ], true );

        if ( is_wp_error( $entry_id ) ) {
            return new WP_Error( 'insert_failed', $entry_id->get_error_message(), [ 'status' => 500 ] );
        }

        update_post_meta( $entry_id, '_bdn_lb_parent_post', $post_id );
        if ( $req->get_param( 'byline' ) )        update_post_meta( $entry_id, '_bdn_lb_byline',         $req->get_param( 'byline' ) );
        if ( $req->get_param( 'label' ) )          update_post_meta( $entry_id, '_bdn_lb_label',          $req->get_param( 'label' ) );
        if ( $req->get_param( 'image_id' ) )       update_post_meta( $entry_id, '_bdn_lb_image_id',       absint( $req->get_param( 'image_id' ) ) );
        if ( $req->get_param( 'image_caption' ) )  update_post_meta( $entry_id, '_bdn_lb_image_caption',  $req->get_param( 'image_caption' ) );
        if ( $req->get_param( 'image_credit' ) )   update_post_meta( $entry_id, '_bdn_lb_image_credit',   $req->get_param( 'image_credit' ) );
        if ( $req->has_param( 'pinned' ) ) { self::set_pinned( $entry_id, absint( $req->get_param( 'pinned' ) ), (int) $post_id ); }
        if ( $req->has_param( 'highlight' ) ) {
            if ( absint( $req->get_param( 'highlight' ) ) ) {
                update_post_meta( $entry_id, '_bdn_lb_highlight', 1 );
            } else {
                delete_post_meta( $entry_id, '_bdn_lb_highlight' );
            }
        }

        // Touch the parent post so caches know to refresh
        wp_update_post( [ 'ID' => $post_id, 'post_modified' => current_time( 'mysql' ) ] );
        self::bust_entries_cache( $post_id );

        return new WP_REST_Response( self::format_entry( get_post( $entry_id ) ), 201 );
    }

    // ── PUT /entries/{id} ──────────────────────────────────────────────────────

    public static function update_entry( WP_REST_Request $req ) {
        $post = get_post( $req->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
            return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
        }

        $update = [ 'ID' => $post->ID ];
        if ( $req->get_param( 'title' ) )   $update['post_title']   = $req->get_param( 'title' );
        if ( $req->get_param( 'content' ) ) $update['post_content'] = $req->get_param( 'content' );

        wp_update_post( $update );
        $parent_id = (int) get_post_meta( $post->ID, '_bdn_lb_parent_post', true );
        if ( $parent_id ) self::bust_entries_cache( $parent_id );
        if ( $req->get_param( 'byline' ) )        update_post_meta( $post->ID, '_bdn_lb_byline',        $req->get_param( 'byline' ) );
        if ( $req->get_param( 'label' ) )          update_post_meta( $post->ID, '_bdn_lb_label',         $req->get_param( 'label' ) );
        // image_id of 0 means "remove image"
        if ( $req->has_param( 'image_id' ) ) {
            $img = absint( $req->get_param( 'image_id' ) );
            if ( $img ) { update_post_meta( $post->ID, '_bdn_lb_image_id', $img ); }
            else        { delete_post_meta( $post->ID, '_bdn_lb_image_id' ); }
        }
        if ( $req->get_param( 'image_caption' ) )  update_post_meta( $post->ID, '_bdn_lb_image_caption', $req->get_param( 'image_caption' ) );
        if ( $req->get_param( 'image_credit' ) )   update_post_meta( $post->ID, '_bdn_lb_image_credit',  $req->get_param( 'image_credit' ) );
        if ( $req->has_param( 'pinned' ) ) { $parent = (int) get_post_meta( $post->ID, '_bdn_lb_parent_post', true ); self::set_pinned( $post->ID, absint( $req->get_param( 'pinned' ) ), $parent ); }
        if ( $req->has_param( 'highlight' ) ) {
            if ( absint( $req->get_param( 'highlight' ) ) ) {
                update_post_meta( $post->ID, '_bdn_lb_highlight', 1 );
            } else {
                delete_post_meta( $post->ID, '_bdn_lb_highlight' );
            }
        }

        return new WP_REST_Response( self::format_entry( get_post( $post->ID ) ), 200 );
    }

    // ── POST /entries/{id}/regenerate-slug ────────────────────────────────────

    public static function regenerate_slug( WP_REST_Request $req ) {
        $post = get_post( $req->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
            return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
        }

        $throttle_key = 'bdn_lb_slug_regen_' . $post->ID;
        if ( get_transient( $throttle_key ) ) {
            return new WP_REST_Response( [
                'id'        => $post->ID,
                'seo_slug'  => get_post_meta( $post->ID, '_bdn_lb_seo_slug', true ),
                'entry_url' => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, get_the_title( $post ) ),
                'throttled' => true,
            ], 200 );
        }
        set_transient( $throttle_key, 1, 30 );

        $new_slug = BDN_Liveblog_Slug::regenerate_slug(
            $post->ID,
            $post->post_content,
            get_the_title( $post )
        );

        return new WP_REST_Response( [
            'id'        => $post->ID,
            'seo_slug'  => $new_slug,
            'entry_url' => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, get_the_title( $post ) ),
        ], 200 );
    }

    // ── DELETE /entries/{id} ───────────────────────────────────────────────────

    public static function delete_entry( WP_REST_Request $req ) {
        $post = get_post( $req->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
            return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
        }
        $parent_id = (int) get_post_meta( $post->ID, '_bdn_lb_parent_post', true );
        if ( $parent_id ) self::bust_entries_cache( $parent_id );
        wp_delete_post( $post->ID, true );
        return new WP_REST_Response( [ 'deleted' => true ], 200 );
    }

    // ── GET /status ────────────────────────────────────────────────────────────

    public static function get_status( WP_REST_Request $req ) {
        $post_id = $req->get_param( 'post_id' );
        return new WP_REST_Response( [
            'status'        => get_post_meta( $post_id, '_bdn_liveblog_status', true ) ?: 'ended',
            'enabled'       => (bool) get_post_meta( $post_id, '_bdn_liveblog_enabled', true ),
            'last_modified' => get_post_modified_time( 'U', true, $post_id ),
        ], 200 );
    }

    // ── POST /status ───────────────────────────────────────────────────────────

    public static function set_status( WP_REST_Request $req ) {
        $post_id = $req->get_param( 'post_id' );
        $status  = $req->get_param( 'status' );
        update_post_meta( $post_id, '_bdn_liveblog_status', $status );
        update_post_meta( $post_id, '_bdn_liveblog_enabled', true );
        return new WP_REST_Response( [ 'status' => $status ], 200 );
    }

    // ── Pin helper ─────────────────────────────────────────────────────────────
    // Only one entry per parent post can be pinned at a time.

    // ── GET /summary ──────────────────────────────────────────────────────────

    public static function get_summary( WP_REST_Request $req ) {
        $post_id = $req->get_param( 'post_id' );

        if ( ! BDN_Liveblog_Nota::is_available() ) {
            return new WP_Error( 'nota_unavailable', 'NOTA API not configured.', [ 'status' => 503 ] );
        }

        $cache_key = 'bdn_lb_summary_' . $post_id;
        $cached = get_transient( $cache_key );
        if ( $cached !== false ) {
            return new WP_REST_Response( $cached, 200 );
        }

        $entries = get_posts( [
            'post_type'      => BDN_Liveblog_Post_Type::CPT,
            'posts_per_page' => 50,
            'post_status'    => 'publish',
            'orderby'        => 'date',
            'order'          => 'ASC',
            'meta_query'     => [
                [ 'key' => '_bdn_lb_parent_post', 'value' => $post_id, 'type' => 'NUMERIC' ],
            ],
        ] );

        if ( empty( $entries ) ) {
            return new WP_REST_Response( [ 'summary' => '', 'entry_count' => 0 ], 200 );
        }

        $combined = '';
        foreach ( $entries as $entry ) {
            $time = get_post_time( 'g:i A', false, $entry );
            $text = wp_strip_all_tags( $entry->post_content );
            $title = get_the_title( $entry );
            $combined .= $time . ': ' . ( $title ? $title . '. ' : '' ) . $text . "\n\n";
        }

        $response = BDN_Liveblog_Nota::call( 'summary', $combined );
        if ( ! $response ) {
            return new WP_Error( 'nota_failed', 'Summary generation failed.', [ 'status' => 502 ] );
        }

        $summary = BDN_Liveblog_Nota::extract_first( $response, 'summary' )
                ?: BDN_Liveblog_Nota::extract_first( $response, 'summaries' );

        $result = [
            'summary'     => $summary,
            'entry_count' => count( $entries ),
        ];

        set_transient( $cache_key, $result, 300 );

        return new WP_REST_Response( $result, 200 );
    }

    private static function set_pinned( int $entry_id, int $pin, int $parent_id ) {
        if ( $pin ) {
            // Unpin any currently pinned entry for this parent first.
            $existing = get_posts( [
                'post_type'      => BDN_Liveblog_Post_Type::CPT,
                'posts_per_page' => 1,
                'meta_query'     => [
                    [ 'key' => '_bdn_lb_pinned',      'value' => '1' ],
                    [ 'key' => '_bdn_lb_parent_post', 'value' => $parent_id, 'type' => 'NUMERIC' ],
                ],
            ] );
            foreach ( $existing as $old ) {
                if ( $old->ID !== $entry_id ) delete_post_meta( $old->ID, '_bdn_lb_pinned' );
            }
            update_post_meta( $entry_id, '_bdn_lb_pinned', 1 );
        } else {
            delete_post_meta( $entry_id, '_bdn_lb_pinned' );
        }
    }
}