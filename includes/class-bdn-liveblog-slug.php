<?php
/**
 * BDN_Liveblog_Slug
 *
 * Generates SEO-optimized slugs for live blog entries.
 *
 * Slug generation priority:
 *   1. Anthropic API (Claude Haiku) — when an API key is configured.
 *   2. Local NLP algorithm — pure PHP, zero external dependencies.
 *
 * The local algorithm runs three passes over the text:
 *   Pass 1 — Proper nouns: capitalized sequences not at sentence starts,
 *             with connector words (of/the) allowed mid-sequence so
 *             "University of Maine" stays together.
 *   Pass 2 — News-value tokens: bare numbers, honorific-prefixed names.
 *   Pass 3 — Action/topic words: verbs and nouns that carry the event.
 *
 * Results are merged in priority order, de-duplicated at word level,
 * and trimmed to TARGET_WORDS (7) or MAX_SLUG_LEN (60) chars.
 *
 * URL pattern: bangordailynews.com/YYYY/MM/DD/liveblog/{slug}/
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class BDN_Liveblog_Slug {

    const API_KEY_OPTION = 'bdn_liveblog_anthropic_key';
    const MODEL_OPTION   = 'bdn_liveblog_anthropic_model';
    const DEFAULT_MODEL  = 'claude-haiku-4-5-20251001';
    const CACHE_PREFIX   = 'bdn_lb_slug_';
    const CACHE_TTL      = DAY_IN_SECONDS * 30;
    const MAX_SLUG_LEN   = 60;
    const TARGET_WORDS   = 7;

    /**
     * Title-like prefixes that should not anchor a proper noun sequence.
     * "Director Nirav Shah" → slug uses "nirav-shah", not "director-nirav-shah".
     */
    private static array $honorifics = [
        'governor','director','senator','representative','commissioner',
        'superintendent','officer','chief','attorney','judge','president',
        'secretary','lieutenant','colonel','captain','sergeant','detective',
    ];

    // ── Public API ────────────────────────────────────────────────────────────

    public static function get_entry_url( int $entry_id, string $content = '', string $title = '' ): string {
        $slug = self::get_or_generate( $entry_id, $content, $title );
        $date = get_post_time( 'Y/m/d', true, $entry_id );
        return home_url( trailingslashit( "{$date}/liveblog/{$slug}" ) );
    }

    public static function get_anchor_url( int $entry_id ): string {
        $parent_id  = (int) get_post_meta( $entry_id, '_bdn_lb_parent_post', true );
        $parent_url = $parent_id ? get_permalink( $parent_id ) : home_url();
        return trailingslashit( $parent_url ) . '#entry-' . $entry_id;
    }

    public static function regenerate_slug( int $entry_id, string $content, string $title = '' ): string {
        delete_transient( self::CACHE_PREFIX . $entry_id );
        delete_post_meta( $entry_id, '_bdn_lb_seo_slug' );
        return self::get_or_generate( $entry_id, $content, $title );
    }

    // ── Cache / resolution ────────────────────────────────────────────────────

    private static function get_or_generate( int $entry_id, string $content, string $title ): string {
        $cached = get_transient( self::CACHE_PREFIX . $entry_id );
        if ( $cached ) return $cached;

        $stored = get_post_meta( $entry_id, '_bdn_lb_seo_slug', true );
        if ( $stored ) {
            set_transient( self::CACHE_PREFIX . $entry_id, $stored, self::CACHE_TTL );
            return $stored;
        }

        $slug = self::generate( $content, $title );
        update_post_meta( $entry_id, '_bdn_lb_seo_slug', $slug );
        set_transient( self::CACHE_PREFIX . $entry_id, $slug, self::CACHE_TTL );
        return $slug;
    }

    private static function generate( string $content, string $title ): string {
        // Priority 1: Anthropic API
        $api_key = get_option( self::API_KEY_OPTION, '' );
        if ( $api_key ) {
            $ai = self::call_anthropic( $api_key, $content, $title );
            if ( $ai ) return self::sanitize_slug( $ai );
        }

        // Priority 2: NOTA SUM API
        if ( BDN_Liveblog_Nota::is_available() ) {
            $full_text = $title ? $title . '. ' . $content : $content;
            $response = BDN_Liveblog_Nota::call( 'slugs', $full_text );
            if ( $response ) {
                $slug = BDN_Liveblog_Nota::extract_first( $response, 'slugs' )
                     ?: BDN_Liveblog_Nota::extract_first( $response, 'slug' );
                if ( $slug ) return self::sanitize_slug( $slug );
            }
        }

        // Priority 3: Local NLP
        return self::local_slug( $content, $title );
    }

    // ── Anthropic API ─────────────────────────────────────────────────────────

    private static function call_anthropic( string $api_key, string $content, string $title ): string {
        $text    = mb_substr( wp_strip_all_tags( $content ), 0, 800 );
        $context = $title ? "Title: {$title}\nContent: {$text}" : "Content: {$text}";
        $prompt  = <<<PROMPT
You are an SEO editor at the Bangor Daily News, a Maine regional news outlet.

Given the live blog entry below, write ONE URL slug that:
- Is 4-8 words, all lowercase, hyphen-separated
- Leads with the most newsworthy proper nouns (people, places, organizations)
- Includes the key action or event
- Omits stop words unless essential to meaning
- Is under 60 characters total
- Contains NO punctuation other than hyphens

Reply with ONLY the slug — no explanation, no quotes, no extra text.

{$context}
PROMPT;

        $response = wp_remote_post( 'https://api.anthropic.com/v1/messages', [
            'timeout' => 12,
            'headers' => [
                'Content-Type'      => 'application/json',
                'x-api-key'         => $api_key,
                'anthropic-version' => '2023-06-01',
            ],
            'body' => wp_json_encode( [
                'model'      => get_option( self::MODEL_OPTION, self::DEFAULT_MODEL ),
                'max_tokens' => 30,
                'messages'   => [ [ 'role' => 'user', 'content' => $prompt ] ],
            ] ),
        ] );

        if ( is_wp_error( $response ) ) {
            error_log( 'BDN LiveBlog slug API error: ' . $response->get_error_message() );
            return '';
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $slug = trim( $body['content'][0]['text'] ?? '' );
        if ( ! $slug || str_word_count( $slug ) > 10 || strlen( $slug ) > 80 ) return '';
        return $slug;
    }

    // ── Local NLP slug algorithm ──────────────────────────────────────────────

    public static function local_slug( string $content, string $title = '' ): string {
        // Title appears first (single occurrence) followed by body.
        // A trailing period after the title prevents it being parsed as
        // sentence-initial for the body's first word.
        $title_part = $title ? ( rtrim( $title, '.' ) . '. ' ) : '';
        $raw        = trim( $title_part . wp_strip_all_tags( $content ) );
        if ( ! $raw ) return 'entry-' . time();

        $sentence_starts = self::get_sentence_starts( $raw );
        $tokens          = self::tokenize( $raw );

        $proper = self::extract_proper_nouns( $tokens, $sentence_starts );
        $news   = self::extract_news_tokens( $tokens );
        $action = self::extract_action_words( $tokens );

        $parts = self::merge_parts( $proper, $news, $action );
        if ( empty( $parts ) ) {
            $parts = self::bare_fallback( $raw );
        }

        return self::sanitize_slug( implode( '-', $parts ) );
    }

    // ── Tokenizer ─────────────────────────────────────────────────────────────

    private static function tokenize( string $text ): array {
        $tokens = [];
        preg_match_all( '/\b([A-Za-z0-9]+)\b/', $text, $matches, PREG_OFFSET_CAPTURE );
        foreach ( $matches[1] as $m ) {
            $tokens[] = [
                'word'  => $m[0],
                'pos'   => (int) $m[1],
                'lower' => strtolower( $m[0] ),
            ];
        }
        return $tokens;
    }

    /**
     * Returns character positions that begin a new sentence.
     * Uses a lookahead: after [.!?] and whitespace, the next char is a sentence start.
     */
    private static function get_sentence_starts( string $text ): array {
        $positions = [ 0 => true ];
        preg_match_all( '/(?<=[.!?])\s+/', $text, $matches, PREG_OFFSET_CAPTURE );
        foreach ( $matches[0] as $m ) {
            // The position immediately after the whitespace gap is a sentence start.
            $positions[ (int) $m[1] + strlen( $m[0] ) ] = true;
        }
        return $positions;
    }

    // ── Pass 1: Proper nouns ──────────────────────────────────────────────────

    private static function extract_proper_nouns( array $tokens, array $sentence_starts ): array {
        $sequences = [];
        $i         = 0;
        $n         = count( $tokens );

        while ( $i < $n ) {
            $t = $tokens[ $i ];

            if ( self::is_capitalized( $t['word'] ) && ! isset( $sentence_starts[ $t['pos'] ] ) ) {
                // Consume a run of capitalized words, allowing 'of'/'the' connectors.
                $seq = [ $t['lower'] ];
                $j   = $i + 1;

                while ( $j < $n ) {
                    $next = $tokens[ $j ];
                    if ( self::is_capitalized( $next['word'] ) ) {
                        $seq[] = $next['lower'];
                        $j++;
                    } elseif (
                        in_array( $next['lower'], [ 'of', 'the' ], true ) &&
                        ( $j + 1 ) < $n &&
                        self::is_capitalized( $tokens[ $j + 1 ]['word'] )
                    ) {
                        // Connector word mid-sequence — skip it, take the next cap word.
                        $seq[] = $tokens[ $j + 1 ]['lower'];
                        $j    += 2;
                    } else {
                        break;
                    }
                }

                // Drop leading honorific words so the person's name leads.
                while ( ! empty( $seq ) && in_array( rtrim( $seq[0], '.' ), self::$honorifics, true ) ) {
                    array_shift( $seq );
                }

                $cleaned = array_values( array_filter( array_map( [ __CLASS__, 'clean_word' ], $seq ) ) );
                if ( ! empty( $cleaned ) && ( count( $cleaned ) > 1 || strlen( $cleaned[0] ) > 2 ) ) {
                    $sequences[] = implode( '-', $cleaned );
                }

                $i = $j;
            } else {
                $i++;
            }
        }

        // De-duplicate at word level: discard a short sequence if all its words
        // are already covered by a longer one already in the list.
        $seen_words = [];
        $deduped    = [];
        // Process longest sequences first.
        usort( $sequences, fn( $a, $b ) => substr_count( $b, '-' ) <=> substr_count( $a, '-' ) );

        foreach ( $sequences as $seq ) {
            $words = explode( '-', $seq );
            $new_words = array_diff( $words, $seen_words );
            if ( ! empty( $new_words ) ) {
                $deduped[]  = $seq;
                $seen_words = array_merge( $seen_words, $words );
            }
        }

        return $deduped;
    }

    private static function is_capitalized( string $word ): bool {
        return isset( $word[0] ) && ctype_upper( $word[0] ) && ! ctype_upper( $word );
    }

    // ── Pass 2: News-value tokens ─────────────────────────────────────────────

    private static function extract_news_tokens( array $tokens ): array {
        static $title_words = [
            'gov', 'sen', 'rep', 'dr', 'mr', 'mrs', 'ms',
            'chief', 'officer', 'commissioner', 'attorney', 'judge',
        ];

        $news = [];
        $seen = [];

        for ( $i = 0, $n = count( $tokens ); $i < $n; $i++ ) {
            $word  = $tokens[ $i ]['word'];
            $lower = $tokens[ $i ]['lower'];

            // Bare numbers > 1 (case counts, dollar amounts, percentages).
            if ( preg_match( '/^\d+$/', $word ) && (int) $word > 1 ) {
                if ( ! isset( $seen[ $word ] ) ) { $news[] = $word; $seen[ $word ] = true; }
            }

            // Title abbreviation followed by a person name — grab the name word.
            if ( in_array( rtrim( $lower, '.' ), $title_words, true ) && isset( $tokens[ $i + 1 ] ) ) {
                $name = self::clean_word( $tokens[ $i + 1 ]['lower'] );
                if ( $name && ! isset( $seen[ $name ] ) ) {
                    $news[]        = $name;
                    $seen[ $name ] = true;
                }
            }
        }

        return $news;
    }

    // ── Pass 3: Action / topic words ──────────────────────────────────────────

    private static function extract_action_words( array $tokens ): array {
        static $action_words = [
            // Verbs
            'announces','announced','signs','signed','approves','approved',
            'votes','voted','passes','passed','fails','failed',
            'resigns','resigned','dies','died','arrested','charges','charged',
            'indicts','indicted','sues','sued','wins','won','loses','lost',
            'confirms','confirmed','denies','denied','warns','warned',
            'closes','closed','opens','opened','launches','launched',
            'cancels','cancelled','suspends','suspended','fires','fired',
            'hires','hired','releases','released','increases','decreased',
            'cuts','raises','raised','urged','urges','meets','met',
            'opposes','opposed','blocks','blocked','overturns','overturned',
            // Nouns
            'shooting','death','arrest','fire','flood','storm','outbreak',
            'crisis','verdict','trial','sentence','lawsuit','investigation',
            'shutdown','merger','bankruptcy','strike','protest','election',
            'vote','bill','order','budget','agreement','recall','ban',
            'ruling','decision','report','accident','fatal','overdose',
            'injury','injuries','evacuation','rescue','search',
        ];

        $found = [];
        $seen  = [];

        foreach ( $tokens as $t ) {
            if ( in_array( $t['lower'], $action_words, true ) ) {
                $c = self::clean_word( $t['lower'] );
                if ( $c && ! isset( $seen[ $c ] ) ) {
                    $found[]    = $c;
                    $seen[ $c ] = true;
                }
            }
        }

        return $found;
    }

    // ── Merge ─────────────────────────────────────────────────────────────────

    private static function merge_parts( array $proper, array $news, array $action ): array {
        $result     = [];
        $seen_words = [];
        $budget     = self::TARGET_WORDS;
        $char_len   = 0;

        $try_add = function( string $segment ) use ( &$result, &$seen_words, &$budget, &$char_len ): bool {
            // Segments that come in as multi-word already have hyphens.
            $seg   = trim( $segment, '-' );
            if ( ! $seg ) return false;

            $words = explode( '-', $seg );
            // Skip if all words already covered.
            if ( empty( array_diff( $words, $seen_words ) ) ) return false;

            $word_count = count( $words );
            $seg_len    = strlen( $seg );
            $gap        = $char_len > 0 ? 1 : 0;

            if ( $budget < 1 || ( $char_len + $gap + $seg_len ) > self::MAX_SLUG_LEN ) return false;

            $result[]   = $seg;
            $seen_words = array_merge( $seen_words, $words );
            $budget    -= $word_count;
            $char_len  += $gap + $seg_len;
            return true;
        };

        foreach ( $proper as $p ) $try_add( $p );
        foreach ( $news   as $n ) $try_add( $n );
        foreach ( $action as $a ) $try_add( $a );

        return $result;
    }

    // ── Stop-word fallback ────────────────────────────────────────────────────

    private static function bare_fallback( string $text ): array {
        static $stop = [
            'a','an','the','and','or','but','in','on','at','to','for','of',
            'with','by','from','is','was','are','were','be','been','has','have',
            'had','will','would','could','should','may','might','that','this',
            'it','its','he','she','they','we','his','her','their','our',
            'as','so','if','when','then','than','not','no','do','did','does',
            'said','says','according','told','asked','made','got','get',
        ];

        $words  = preg_split( '/\s+/', strtolower( $text ), -1, PREG_SPLIT_NO_EMPTY );
        $result = [];
        foreach ( $words as $w ) {
            $clean = preg_replace( '/[^a-z0-9]/', '', $w );
            if ( $clean && strlen( $clean ) > 2 && ! in_array( $clean, $stop, true ) ) {
                $result[] = $clean;
                if ( count( $result ) >= self::TARGET_WORDS ) break;
            }
        }
        return $result;
    }

    // ── Utilities ─────────────────────────────────────────────────────────────

    /** Strip non-alphanumeric chars, lowercase, discard single chars. */
    private static function clean_word( string $word ): string {
        $clean = preg_replace( '/[^a-z0-9]/', '', strtolower( $word ) );
        return strlen( $clean ) > 1 ? $clean : '';
    }

    /** Final WordPress-compatible sanitization + length enforcement. */
    private static function sanitize_slug( string $raw ): string {
        $slug = sanitize_title( strtolower( trim( $raw ) ) );
        $slug = trim( $slug, '-' );

        if ( strlen( $slug ) > self::MAX_SLUG_LEN ) {
            $trimmed = substr( $slug, 0, self::MAX_SLUG_LEN );
            $last    = strrpos( $trimmed, '-' );
            $slug    = $last ? substr( $trimmed, 0, $last ) : $trimmed;
        }

        return $slug ?: 'entry-' . time();
    }
}
