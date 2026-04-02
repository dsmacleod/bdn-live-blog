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
            'entry_url'     => BDN_Liveblog_Slug::get_entry_url( $post->ID, $post->post_content, $title ),
            'anchor_url'    => BDN_Liveblog_Slug::get_anchor_url( $post->ID ),
            'seo_slug'      => get_post_meta( $post->ID, '_bdn_lb_seo_slug', true ),
        ];
    }

    /**
     * Lightweight content rendering for entries — avoids the full the_content
     * filter chain which can trigger oEmbed HTTP requests, shortcode recursion,
     * and the liveblog auto-inject filter, all of which hang or bloat the API.
     */
    public static function render_entry_content( string $raw ): string {
        $content = wptexturize( $raw );
        $content = wpautop( $content );
        $content = shortcode_unautop( $content );
        $content = wp_filter_content_tags( $content );
        $content = do_shortcode( $content );
        $content = convert_smilies( $content );
        return $content;
    }

    // ── GET /entries ───────────────────────────────────────────────────────────

    public static function get_entries( WP_REST_Request $req ) {
        $post_id = $req->get_param( 'post_id' );
        $after   = $req->get_param( 'after' );
        $page    = max( 1, $req->get_param( 'page' ) );

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

        if ( $after > 0 ) {
            $args['date_query'] = [ [ 'after' => gmdate( 'Y-m-d H:i:s', $after ), 'column' => 'post_date_gmt' ] ];
            $args['posts_per_page'] = 100; // polling — return all new
            unset( $args['paged'] );
        }

        $query   = new WP_Query( $args );
        $entries = array_map( [ __CLASS__, 'format_entry' ], $query->posts );

        return new WP_REST_Response( [
            'entries'     => $entries,
            'total'       => (int) $query->found_posts,
            'total_pages' => (int) $query->max_num_pages,
        ], 200 );
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

        // Touch the parent post so caches know to refresh
        wp_update_post( [ 'ID' => $post_id, 'post_modified' => current_time( 'mysql' ) ] );

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

        return new WP_REST_Response( self::format_entry( get_post( $post->ID ) ), 200 );
    }

    // ── POST /entries/{id}/regenerate-slug ────────────────────────────────────

    public static function regenerate_slug( WP_REST_Request $req ) {
        $post = get_post( $req->get_param( 'id' ) );
        if ( ! $post || $post->post_type !== BDN_Liveblog_Post_Type::CPT ) {
            return new WP_Error( 'not_found', 'Entry not found.', [ 'status' => 404 ] );
        }

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