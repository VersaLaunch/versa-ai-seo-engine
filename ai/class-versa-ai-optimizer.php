<?php
/**
 * SEO optimizer: scanner + task worker.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Optimizer {
    private Versa_AI_OpenAI_Client $client;
    private Versa_AI_Crawler $crawler;

    public function __construct() {
        $this->client = new Versa_AI_OpenAI_Client();
        $this->crawler = new Versa_AI_Crawler();
    }

    /**
     * Daily scanner: inspects posts and enqueues SEO tasks.
     */
    public function scan_site(): void {
        $posts = $this->get_posts_for_scan( 20 );
        if ( empty( $posts ) ) {
            return;
        }

        $service_urls = Versa_AI_Service_URLs::get_urls();

        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;

            if ( $this->is_locked( $post_id ) ) {
                continue;
            }

            $snapshot = $this->build_snapshot( $post_id, $service_urls );
            Versa_AI_SEO_Snapshot::save( $post_id, $snapshot );

            $this->maybe_create_tasks( $post_id, $snapshot, $service_urls );
        }

        // Also run a lightweight site crawl to draft site-wide recommendations.
        $this->maybe_crawl_site_for_recommendations();
    }

    /**
     * Worker: processes pending SEO tasks.
     */
    public function run_worker(): void {
        $profile = $this->get_profile();
        if ( empty( $profile['openai_api_key'] ) || empty( $profile['openai_model'] ) ) {
            Versa_AI_Logger::log( 'optimizer', 'Missing OpenAI credentials; worker skipped.' );
            return;
        }

        $tasks = Versa_AI_SEO_Tasks::get_pending_tasks( 5 );
        if ( empty( $tasks ) ) {
            return;
        }

        foreach ( $tasks as $task ) {
            $task_id = (int) $task['id'];
            $post_id = (int) $task['post_id'];

            if ( $this->is_locked( $post_id ) ) {
                Versa_AI_SEO_Tasks::update_task( $task_id, 'failed', [ 'message' => 'Post locked; skipping.' ] );
                continue;
            }

            // Mark running.
            Versa_AI_SEO_Tasks::update_task( $task_id, 'running', [] );

            $result = $this->process_task( $task, $profile );
            if ( $result['success'] ) {
                Versa_AI_SEO_Tasks::update_task( $task_id, 'done', $result['details'] );
            } else {
                Versa_AI_SEO_Tasks::update_task( $task_id, 'failed', $result['details'] );
            }
        }
    }

    /**
     * Build snapshot data for a post.
     */
    private function build_snapshot( int $post_id, array $service_urls ): array {
        $content = get_post_field( 'post_content', $post_id );
        $word_count = str_word_count( wp_strip_all_tags( $content ) );

        $seo_title = get_post_meta( $post_id, 'rank_math_title', true );
        if ( ! $seo_title ) {
            $seo_title = get_post_meta( $post_id, '_yoast_wpseo_title', true );
        }

        $seo_desc = get_post_meta( $post_id, 'rank_math_description', true );
        if ( ! $seo_desc ) {
            $seo_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        }

        $has_faq_section = $this->detect_faq_section( $content );
        $has_faq_schema  = ! empty( get_post_meta( $post_id, 'versa_ai_faq_schema', true ) );

        $internal_links = $this->count_internal_service_links( $content, $service_urls );
        $images_without_alt = $this->count_images_without_alt( $content );

        return [
            'word_count'             => $word_count,
            'has_meta_title'         => ! empty( $seo_title ),
            'has_meta_description'   => ! empty( $seo_desc ),
            'has_faq_section'        => $has_faq_section,
            'has_faq_schema'         => $has_faq_schema,
            'internal_links_to_services' => $internal_links,
            'images_without_alt'     => $images_without_alt,
            'last_ai_optimized_at'   => current_time( 'mysql' ),
        ];
    }

    /**
     * Create tasks based on snapshot rules.
     */
    private function maybe_create_tasks( int $post_id, array $snapshot, array $service_urls ): void {
        $require_approval = (bool) ( $this->get_profile()['require_task_approval'] ?? false );
        $initial_status   = $require_approval ? 'awaiting_approval' : 'pending';

        if ( $snapshot['word_count'] > 0 && $snapshot['word_count'] < 700 ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'expand_content', [], $initial_status );
        }

        if ( ! $snapshot['has_meta_description'] ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'write_snippet', [], $initial_status );
        }

        if ( empty( $snapshot['internal_links_to_services'] ) && ! empty( $service_urls ) ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'internal_linking', [ 'service_urls' => $service_urls ], $initial_status );
        }

        if ( $snapshot['has_faq_section'] && ! $snapshot['has_faq_schema'] ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'faq_schema', [], $initial_status );
        }
    }

    /**
     * Process a single task using OpenAI.
     */
    private function process_task( array $task, array $profile ): array {
        $post_id   = (int) $task['post_id'];
        $task_type = $task['task_type'];
        $payload   = json_decode( $task['payload'] ?? '', true ) ?: [];

        $content = get_post_field( 'post_content', $post_id );
        $title   = get_the_title( $post_id );

        switch ( $task_type ) {
            case 'expand_content':
                return $this->handle_expand_content( $post_id, $title, $content, $profile );
            case 'write_snippet':
                return $this->handle_write_snippet( $post_id, $title, $content, $profile );
            case 'internal_linking':
                $service_urls = $payload['service_urls'] ?? Versa_AI_Service_URLs::get_urls();
                return $this->handle_internal_linking( $post_id, $title, $content, $profile, $service_urls );
            case 'faq_schema':
                return $this->handle_faq_schema( $post_id, $content, $profile );
            case 'site_audit':
                // Site-wide tasks are informational; do not auto-apply. Mark done after approval.
                return [ 'success' => true, 'details' => $payload ?: [ 'message' => 'Site audit suggestion' ] ];
            default:
                return [ 'success' => false, 'details' => [ 'message' => 'Unknown task type.' ] ];
        }
    }

    /**
     * Crawl the site and draft site-wide SEO recommendations as tasks.
     */
    private function maybe_crawl_site_for_recommendations(): void {
        // Limit crawl frequency (every 6 hours).
        $transient_key = 'versa_ai_site_crawl_ran';
        if ( get_transient( $transient_key ) ) {
            return;
        }

        $pages = $this->crawler->crawl( 30 );
        if ( empty( $pages ) ) {
            return;
        }

        $issues = [];
        foreach ( $pages as $page ) {
            if ( ! $page['has_title'] || ! $page['has_meta_description'] || $page['word_count'] < 400 ) {
                $issues[] = $page;
            }
        }

        if ( empty( $issues ) ) {
            set_transient( $transient_key, 1, 6 * HOUR_IN_SECONDS );
            return;
        }

        $profile = $this->get_profile();
        if ( empty( $profile['openai_api_key'] ) || empty( $profile['openai_model'] ) ) {
            return;
        }

        $prompt = [];
        $prompt[] = 'You are an SEO auditor. Given these page findings, output JSON array of 3-8 prioritized tasks.';
        $prompt[] = 'Each task: {"summary": string, "priority": "high"|"medium"|"low", "recommended_action": string }';
        $prompt[] = 'Findings:';
        foreach ( $issues as $issue ) {
            $prompt[] = sprintf(
                '- URL: %s | has_title: %s | has_meta_description: %s | word_count: %d',
                $issue['url'],
                $issue['has_title'] ? 'yes' : 'no',
                $issue['has_meta_description'] ? 'yes' : 'no',
                $issue['word_count']
            );
        }

        $messages = [
            [ 'role' => 'system', 'content' => 'Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => implode( "\n", $prompt ) ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.25 );
        if ( ! $resp['success'] ) {
            Versa_AI_Logger::log( 'optimizer', 'Site crawl OpenAI error: ' . $resp['error'] );
            return;
        }

        $tasks = $this->parse_site_tasks_json( $resp['data'] );
        if ( empty( $tasks ) ) {
            return;
        }

        $require_approval = (bool) ( $profile['require_task_approval'] ?? false );
        $initial_status   = $require_approval ? 'awaiting_approval' : 'pending';

        foreach ( $tasks as $t ) {
            Versa_AI_SEO_Tasks::insert_task( 0, 'site_audit', $t, $initial_status );
        }

        set_transient( $transient_key, 1, 6 * HOUR_IN_SECONDS );
    }

    /**
     * Expand content task.
     */
    private function handle_expand_content( int $post_id, string $title, string $content, array $profile ): array {
        $prompt = [];
        $prompt[] = 'Improve and expand the following post HTML to be more in-depth and SEO-optimized.';
        $prompt[] = 'Business name: ' . $profile['business_name'];
        $prompt[] = 'Services: ' . implode( ', ', $profile['services'] );
        $prompt[] = 'Locations: ' . implode( ', ', $profile['locations'] );
        $prompt[] = 'Target audience: ' . $profile['target_audience'];
        $prompt[] = 'Tone: ' . $profile['tone_of_voice'];
        $prompt[] = 'Keep structure with <h2>/<h3>, keep links if present, add internal links if naturally relevant.';
        $prompt[] = 'Include FAQ section with 3-5 Q&A if missing.';
        $prompt[] = 'Word target: 900 to ' . (int) $profile['max_words_per_post'] . ' words.';
        $prompt[] = 'Return JSON ONLY with key content_html.';
        $prompt[] = '--- CURRENT HTML START ---';
        $prompt[] = $content;
        $prompt[] = '--- CURRENT HTML END ---';

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO content improver. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => implode( "\n", $prompt ) ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.35 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $parsed = $this->parse_content_json( $resp['data'] );
        if ( ! $parsed ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        $this->backup_content( $post_id, $content );
        wp_update_post( [ 'ID' => $post_id, 'post_content' => $parsed['content_html'] ] );

        return [ 'success' => true, 'details' => [ 'message' => 'Content expanded.' ] ];
    }

    /**
     * Write snippet task.
     */
    private function handle_write_snippet( int $post_id, string $title, string $content, array $profile ): array {
        $prompt = [];
        $prompt[] = 'Write SEO title and meta description for the post below.';
        $prompt[] = 'Business name: ' . $profile['business_name'];
        $prompt[] = 'Tone: ' . $profile['tone_of_voice'];
        $prompt[] = 'Return JSON ONLY with keys seo_title and seo_description.';
        $prompt[] = '--- TITLE ---';
        $prompt[] = $title;
        $prompt[] = '--- CONTENT ---';
        $prompt[] = wp_strip_all_tags( $content );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO expert. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => implode( "\n", $prompt ) ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.3 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_snippet_json( $resp['data'] );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( ! empty( $data['seo_title'] ) ) {
            update_post_meta( $post_id, 'rank_math_title', wp_strip_all_tags( $data['seo_title'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_title', wp_strip_all_tags( $data['seo_title'] ) );
        }
        if ( ! empty( $data['seo_description'] ) ) {
            update_post_meta( $post_id, 'rank_math_description', wp_strip_all_tags( $data['seo_description'] ) );
            update_post_meta( $post_id, '_yoast_wpseo_metadesc', wp_strip_all_tags( $data['seo_description'] ) );
        }

        return [ 'success' => true, 'details' => [ 'message' => 'SEO snippet updated.' ] ];
    }

    /**
     * Internal linking task.
     */
    private function handle_internal_linking( int $post_id, string $title, string $content, array $profile, array $service_urls ): array {
        $prompt = [];
        $prompt[] = 'Add a few natural internal links to the provided service URLs inside the HTML content.';
        $prompt[] = 'Do not change meaning or add new sections.';
        $prompt[] = 'Use descriptive anchor text. Do not duplicate links excessively.';
        $prompt[] = 'Return JSON ONLY with key content_html.';
        $prompt[] = 'Service URLs:';
        foreach ( $service_urls as $url ) {
            $prompt[] = '- ' . $url;
        }
        $prompt[] = '--- CURRENT HTML START ---';
        $prompt[] = $content;
        $prompt[] = '--- CURRENT HTML END ---';

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO editor. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => implode( "\n", $prompt ) ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.25 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $parsed = $this->parse_content_json( $resp['data'] );
        if ( ! $parsed ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        $this->backup_content( $post_id, $content );
        wp_update_post( [ 'ID' => $post_id, 'post_content' => $parsed['content_html'] ] );

        return [ 'success' => true, 'details' => [ 'message' => 'Internal links added.' ] ];
    }

    /**
     * FAQ schema task.
     */
    private function handle_faq_schema( int $post_id, string $content, array $profile ): array {
        $faq_section = $this->extract_faq_section( $content );
        if ( ! $faq_section ) {
            return [ 'success' => false, 'details' => [ 'message' => 'FAQ section not found.' ] ];
        }

        $prompt = [];
        $prompt[] = 'Generate FAQPage JSON-LD for this FAQ section. Return JSON ONLY with key faq_schema_json (object).';
        $prompt[] = 'FAQ section HTML:';
        $prompt[] = $faq_section;

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => implode( "\n", $prompt ) ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.2 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_faq_schema_json( $resp['data'] );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        update_post_meta( $post_id, 'versa_ai_faq_schema', wp_json_encode( $data['faq_schema_json'] ) );

        return [ 'success' => true, 'details' => [ 'message' => 'FAQ schema updated.' ] ];
    }

    /**
     * Helpers
     */

    private function parse_content_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode( trim( $content ), true );
        if ( ! is_array( $decoded ) || empty( $decoded['content_html'] ) ) {
            return null;
        }
        return [ 'content_html' => $decoded['content_html'] ];
    }

    private function parse_snippet_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode( trim( $content ), true );
        if ( ! is_array( $decoded ) ) {
            return null;
        }
        return [
            'seo_title'       => $decoded['seo_title'] ?? '',
            'seo_description' => $decoded['seo_description'] ?? '',
        ];
    }

    private function parse_faq_schema_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode( trim( $content ), true );
        if ( ! is_array( $decoded ) || ! isset( $decoded['faq_schema_json'] ) ) {
            return null;
        }
        return [ 'faq_schema_json' => $decoded['faq_schema_json'] ];
    }

    private function parse_site_tasks_json( array $openai_response ): array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = json_decode( trim( $content ), true );
        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $tasks = [];
        foreach ( $decoded as $item ) {
            if ( empty( $item['summary'] ) || empty( $item['recommended_action'] ) ) {
                continue;
            }
            $tasks[] = [
                'summary'            => sanitize_text_field( $item['summary'] ),
                'priority'           => sanitize_text_field( $item['priority'] ?? 'medium' ),
                'recommended_action' => sanitize_textarea_field( $item['recommended_action'] ),
            ];
        }
        return $tasks;
    }

    private function get_posts_for_scan( int $limit ): array {
        return get_posts(
            [
                'post_type'       => [ 'post', 'page' ],
                'post_status'     => 'publish',
                'numberposts'     => $limit,
                'orderby'         => 'modified',
                'order'           => 'DESC',
                'fields'          => 'all',
                'suppress_filters'=> true,
            ]
        );
    }

    private function is_locked( int $post_id ): bool {
        return (bool) get_post_meta( $post_id, '_versa_ai_locked', true );
    }

    private function detect_faq_section( string $content ): bool {
        return (bool) preg_match( '/<h[23][^>]*>[^<]*faq[^<]*<\/h[23]>+/i', $content );
    }

    private function extract_faq_section( string $content ): ?string {
        if ( preg_match( '/(<h[23][^>]*>[^<]*faq[^<]*<\/h[23]>)(.*)/is', $content, $m ) ) {
            return $m[0];
        }
        return null;
    }

    private function count_internal_service_links( string $content, array $service_urls ): int {
        if ( empty( $service_urls ) ) {
            return 0;
        }
        $count = 0;
        foreach ( $service_urls as $url ) {
            if ( stripos( $content, $url ) !== false ) {
                $count++;
            }
        }
        return $count;
    }

    private function count_images_without_alt( string $content ): int {
        $count = 0;
        if ( preg_match_all( '/<img\b[^>]*>/i', $content, $matches ) ) {
            foreach ( $matches[0] as $img ) {
                if ( ! preg_match( '/\salt\s*=\s*"[^"]+"/i', $img ) ) {
                    $count++;
                }
            }
        }
        return $count;
    }

    private function backup_content( int $post_id, string $content ): void {
        // Lightweight backup for safety; WP revisions also apply.
        update_post_meta( $post_id, '_versa_ai_content_backup', $content );
    }

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
