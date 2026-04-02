<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Post_Type {

    const CPT = 'bdn_lb_entry';

    public static function activate() {
        self::register();
        flush_rewrite_rules();
    }

    public static function register() {
        register_post_type( self::CPT, [
            'label'           => 'Live Blog Entries',
            'public'          => false,
            'show_ui'         => false,
            'capability_type' => 'post',
            'hierarchical'    => false,
            'supports'        => [ 'title', 'editor', 'author', 'thumbnail' ],
            'show_in_rest'    => true,
        ]);

        // Meta: link an entry to a parent post
        register_post_meta( self::CPT, '_bdn_lb_parent_post', [
            'type'              => 'integer',
            'single'            => true,
            'show_in_rest'      => true,
            'auth_callback'     => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: optional author display name override
        register_post_meta( self::CPT, '_bdn_lb_byline', [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: optional label (e.g. "BREAKING", "UPDATE")
        register_post_meta( self::CPT, '_bdn_lb_label', [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: featured image attachment ID for a live blog entry
        register_post_meta( self::CPT, '_bdn_lb_image_id', [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: optional image caption
        register_post_meta( self::CPT, '_bdn_lb_image_caption', [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: optional image credit / photographer
        register_post_meta( self::CPT, '_bdn_lb_image_credit', [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: pinned — one entry floated to the top of the feed
        register_post_meta( self::CPT, '_bdn_lb_pinned', [
            'type'          => 'integer',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: is live-blogging enabled on a standard post?
        register_post_meta( 'post', '_bdn_liveblog_enabled', [
            'type'          => 'boolean',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);

        // Meta: live status of the parent post
        register_post_meta( 'post', '_bdn_liveblog_status', [
            'type'          => 'string',
            'single'        => true,
            'show_in_rest'  => true,
            'auth_callback' => function() { return current_user_can( 'edit_posts' ); },
        ]);
    }
}
