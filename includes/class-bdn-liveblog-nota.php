<?php
/**
 * BDN_Liveblog_Nota
 *
 * Shared client for NOTA's SUM API. Reads credentials from the WP options
 * set by the Nota WordPress plugin (nota_api_url, nota_api_key).
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Nota {

    public static function is_available(): bool {
        return (bool) get_option( 'nota_api_url', '' ) && (bool) get_option( 'nota_api_key', '' );
    }

    public static function call( string $endpoint, string $text, array $extra = [] ): ?array {
        $nota_url = (string) get_option( 'nota_api_url', '' );
        $nota_key = (string) get_option( 'nota_api_key', '' );

        if ( ! $nota_url || ! $nota_key ) {
            return null;
        }

        $text = wp_strip_all_tags( $text );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
        $text = (string) preg_replace( '/\s+/', ' ', trim( $text ) );
        $text = mb_substr( $text, 0, 12000 );

        $body = array_merge( [ 'text' => $text ], $extra );

        $response = wp_remote_post(
            trailingslashit( $nota_url ) . 'wordpress/v1/sum/' . $endpoint,
            [
                'headers' => [
                    'nota-subscription-key' => $nota_key,
                    'Content-Type'          => 'application/json',
                ],
                'body'    => wp_json_encode( $body ),
                'timeout' => 30,
            ]
        );

        if ( is_wp_error( $response ) ) {
            error_log( '[bdn-liveblog-nota] ' . $endpoint . ' error: ' . $response->get_error_message() );
            return null;
        }

        $status = wp_remote_retrieve_response_code( $response );
        if ( $status < 200 || $status >= 300 ) {
            error_log( "[bdn-liveblog-nota] {$endpoint} returned HTTP {$status}" );
            return null;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( ! is_array( $body ) ) {
            error_log( '[bdn-liveblog-nota] ' . $endpoint . ' returned invalid JSON' );
            return null;
        }

        return $body;
    }

    public static function extract( array $body, string $key ) {
        return $body['result'][ $key ] ?? $body[ $key ] ?? null;
    }

    public static function extract_first( array $body, string $key ): string {
        $val = self::extract( $body, $key );
        if ( is_string( $val ) ) return trim( $val );
        if ( is_array( $val ) && ! empty( $val ) ) {
            $first = reset( $val );
            return is_string( $first ) ? trim( $first ) : trim( (string) ( $first['text'] ?? '' ) );
        }
        return '';
    }

    public static function extract_all( array $body, string $key ): array {
        $val = self::extract( $body, $key );
        if ( ! is_array( $val ) ) return [];
        return array_values( array_filter( array_map(
            fn( $item ) => is_string( $item ) ? trim( $item ) : trim( (string) ( $item['text'] ?? '' ) ),
            $val
        ), fn( $s ) => $s !== '' ) );
    }
}
