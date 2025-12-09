<?php
/**
 * Daily writer that turns queued topics into full posts via OpenAI.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Writer {
    private Versa_AI_OpenAI_Client $client;

    public function __construct() {
        $this->client = new Versa_AI_OpenAI_Client();
    }

    /**
     * Entry point for the daily writer cron.
     */
    public function run(): void {
        $profile = $this->get_profile();
        if ( empty( $profile['openai_api_key'] ) || empty( $profile['openai_model'] ) ) {
            Versa_AI_Logger::log( 'writer', 'Missing OpenAI credentials; skipping writer.' );
            return;
        }

        $queue_item = $this->fetch_next_queue_item();
        if ( ! $queue_item ) {
            return; // nothing to do.
        }

        $this->mark_status( (int) $queue_item['id'], 'writing' );

        $prompt   = $this->build_prompt( $profile, $queue_item );
        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO content writer. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt ],
        ];

        $response = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.35 );
        if ( ! $response['success'] ) {
            $this->mark_status( (int) $queue_item['id'], 'queued' );
            Versa_AI_Logger::log( 'writer', 'OpenAI error: ' . $response['error'] );
            return;
        }

        $content = $response['data']['choices'][0]['message']['content'] ?? '';
        $parsed  = $this->decode_writer_payload( $content );
        if ( ! $parsed ) {
            $this->mark_status( (int) $queue_item['id'], 'queued' );
            Versa_AI_Logger::log( 'writer', 'Failed to parse writer JSON response.' );
            return;
        }

        $post_id = $this->create_post_from_payload( $queue_item, $parsed, $profile );
        if ( ! $post_id ) {
            $this->mark_status( (int) $queue_item['id'], 'queued' );
            return;
        }

        $this->mark_published( (int) $queue_item['id'], (int) $post_id );
    }

    /**
     * Fetch the next queued item due today or earlier.
     */
    private function fetch_next_queue_item(): ?array {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_content_queue';

        $today = gmdate( 'Y-m-d' );
        $row   = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE status = %s AND (scheduled_for_date IS NULL OR scheduled_for_date <= %s) ORDER BY scheduled_for_date ASC, created_at ASC LIMIT 1",
                'queued',
                $today
            ),
            ARRAY_A
        );

        return $row ?: null;
    }

    private function mark_status( int $id, string $status ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_content_queue';

        $wpdb->update(
            $table,
            [
                'status'     => sanitize_text_field( $status ),
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%s' ],
            [ '%d' ]
        );
    }

    private function mark_published( int $id, int $post_id ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_content_queue';

        $wpdb->update(
            $table,
            [
                'status'     => 'published',
                'post_id'    => $post_id,
                'updated_at' => current_time( 'mysql' ),
            ],
            [ 'id' => $id ],
            [ '%s', '%d', '%s' ],
            [ '%d' ]
        );
    }

    /**
     * Build writer prompt.
     */
    private function build_prompt( array $profile, array $queue_item ): string {
        $outline = [];
        $decoded_outline = json_decode( $queue_item['outline_json'] ?? '', true );
        if ( is_array( $decoded_outline ) ) {
            $outline = $decoded_outline;
        }

        $lines   = [];
        $lines[] = 'Write a full HTML blog post.';
        $lines[] = 'Business name: ' . $profile['business_name'];
        $lines[] = 'Services: ' . implode( ', ', $profile['services'] );
        $lines[] = 'Locations: ' . implode( ', ', $profile['locations'] );
        $lines[] = 'Target audience: ' . $profile['target_audience'];
        $lines[] = 'Tone: ' . $profile['tone_of_voice'];
        $lines[] = 'Primary keyword: ' . $queue_item['target_keyword'];
        $lines[] = 'Topic (title): ' . $queue_item['post_title'];
        if ( $outline ) {
            $lines[] = 'Outline to follow: ' . implode( ' | ', $outline );
        }
        $lines[] = 'Word count target: 900 to ' . (int) $profile['max_words_per_post'] . ' words.';
        $lines[] = 'Include introduction and conclusion.';
        $lines[] = 'Use <h2> and <h3> headings, paragraphs, unordered lists as needed.';
        $lines[] = 'Include internal links to relevant service or location pages when naturally fitting (do not invent URLs).';
        $lines[] = 'Add an FAQ section near the end with 3-5 Q&A.';
        $lines[] = 'Do not include prices, legal claims, or fake certifications.';
        $lines[] = 'Return JSON ONLY with keys: content_html, seo_title, seo_description, faq_schema_json (object).';

        return implode( "\n", $lines );
    }

    /**
     * Parse writer response JSON.
     */
    private function decode_writer_payload( string $content ): ?array {
        $content = trim( $content );
        $decoded = json_decode( $content, true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }

        if ( empty( $decoded['content_html'] ) ) {
            return null;
        }

        return [
            'content_html'     => $decoded['content_html'],
            'seo_title'        => $decoded['seo_title'] ?? '',
            'seo_description'  => $decoded['seo_description'] ?? '',
            'faq_schema_json'  => isset( $decoded['faq_schema_json'] ) ? $decoded['faq_schema_json'] : null,
        ];
    }

    /**
     * Create the WordPress post and apply metadata.
     */
    private function create_post_from_payload( array $queue_item, array $payload, array $profile ): ?int {
        $post_status = ! empty( $profile['auto_publish_posts'] ) ? 'publish' : 'draft';

        $postarr = [
            'post_title'   => $queue_item['post_title'],
            'post_content' => $payload['content_html'],
            'post_status'  => $post_status,
            'post_type'    => 'post',
        ];

        $post_id = wp_insert_post( $postarr, true );
        if ( is_wp_error( $post_id ) || ! $post_id ) {
            Versa_AI_Logger::log( 'writer', 'Failed to insert post: ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'unknown' ) );
            return null;
        }

        // Rank Math meta.
        if ( ! empty( $payload['seo_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_title', wp_strip_all_tags( $payload['seo_title'] ) );
        }
        if ( ! empty( $payload['seo_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', wp_strip_all_tags( $payload['seo_description'] ) );
        }

        // Yoast meta.
        if ( ! empty( $payload['seo_title'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_title', wp_strip_all_tags( $payload['seo_title'] ) );
        }
        if ( ! empty( $payload['seo_description'] ) ) {
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags( $payload['seo_description'] ) );
        }

        // FAQ schema storage.
        if ( ! empty( $payload['faq_schema_json'] ) ) {
            update_post_meta( $post_id, 'versa_ai_faq_schema', wp_json_encode( $payload['faq_schema_json'] ) );
        }

        return (int) $post_id;
    }

    /**
     * Retrieve business profile with defaults.
     */
    private function get_profile(): array {
        $defaults = [
            'business_name'       => '',
            'services'            => [],
            'locations'           => [],
            'target_audience'     => '',
            'tone_of_voice'       => '',
            'posts_per_week'      => 0,
            'max_words_per_post'  => 1300,
            'auto_publish_posts'  => false,
            'openai_api_key'      => '',
            'openai_model'        => 'gpt-4.1-mini',
        ];

        $stored = get_option( Versa_AI_Settings_Page::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return wp_parse_args( $stored, $defaults );
    }
}
