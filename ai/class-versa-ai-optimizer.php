<?php
/**
 * SEO optimizer: scanner + task worker.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/prompts/class-versa-ai-prompts.php';

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

        // Optionally create missing service pages before scanning.
        $this->maybe_create_missing_service_pages( $service_urls );

        foreach ( $posts as $post ) {
            $post_id = (int) $post->ID;

            if ( $this->is_locked( $post_id ) ) {
                continue;
            }

            $snapshot = $this->build_snapshot( $post_id, $service_urls );
            Versa_AI_SEO_Snapshot::save( $post_id, $snapshot );

            $this->maybe_create_tasks( $post_id, $snapshot, $service_urls );
            $this->maybe_create_schema_tasks( $post_id, $post, $snapshot );
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

            $apply_now = empty( $profile['require_apply_after_edits'] );
            $result    = $this->process_task( $task, $profile, $apply_now );
            if ( $result['success'] ) {
                $status = $apply_now || 'site_audit' === $task['task_type'] ? 'done' : 'awaiting_apply';
                Versa_AI_SEO_Tasks::update_task( $task_id, $status, $result['details'] );
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
            Versa_AI_SEO_Tasks::insert_task(
                $post_id,
                'expand_content',
                [
                    'reason'      => 'Low word count',
                    'word_count'  => $snapshot['word_count'],
                    'target_min'  => 900,
                    'target_max'  => (int) $this->get_profile()['max_words_per_post'],
                ],
                $initial_status
            );
        }

        if ( ! $snapshot['has_meta_description'] ) {
            Versa_AI_SEO_Tasks::insert_task(
                $post_id,
                'write_snippet',
                [ 'reason' => 'Missing meta description' ],
                $initial_status
            );
        }

        if ( empty( $snapshot['internal_links_to_services'] ) && ! empty( $service_urls ) ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'internal_linking', [ 'service_urls' => $service_urls ], $initial_status );
        }

        $profile = $this->get_profile();
        $faq_min_words = isset( $profile['faq_min_word_count'] ) ? (int) $profile['faq_min_word_count'] : 0;
        $enable_faq_tasks = ! empty( $profile['enable_faq_tasks'] );
        $faq_allowed_post_types = array_map( 'sanitize_key', (array) ( $profile['faq_allowed_post_types'] ?? array( 'post', 'page' ) ) );
        $post_type = get_post_type( $post_id );

        $faq_allowed = $enable_faq_tasks
            && $post_type
            && in_array( sanitize_key( $post_type ), $faq_allowed_post_types, true )
            && ( $faq_min_words <= 0 || $snapshot['word_count'] >= $faq_min_words );

        if ( $faq_allowed && $snapshot['has_faq_section'] && ! $snapshot['has_faq_schema'] ) {
            Versa_AI_SEO_Tasks::insert_task(
                $post_id,
                'faq_schema',
                [ 'reason' => 'FAQ section present but missing schema' ],
                $initial_status
            );
        }
    }

    /**
     * Create schema-related tasks based on detected gaps.
     */
    private function maybe_create_schema_tasks( int $post_id, WP_Post $post, array $snapshot ): void {
        $profile = $this->get_profile();
        $require_approval = (bool) ( $profile['require_task_approval'] ?? false );
        $initial_status   = $require_approval ? 'awaiting_approval' : 'pending';

        $content = $post->post_content;
        $title   = get_the_title( $post_id );

        // Article schema for posts/pages missing Article/BlogPosting JSON-LD.
        if ( $this->should_add_article_schema( $post, $content ) && ! $this->has_open_task( $post_id, 'article_schema' ) ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'article_schema', [ 'title' => $title ], $initial_status );
        }

        // BreadcrumbList for content with ancestors and no breadcrumb schema.
        if ( $this->should_add_breadcrumb_schema( $post_id, $content ) && ! $this->has_open_task( $post_id, 'breadcrumb_schema' ) ) {
            $trail = $this->get_breadcrumb_trail( $post_id );
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'breadcrumb_schema', [ 'trail' => $trail ], $initial_status );
        }

        // HowTo when steps detected and missing schema.
        if ( $this->should_add_howto_schema( $post, $content ) && ! $this->has_open_task( $post_id, 'howto_schema' ) ) {
            $steps_html = $this->extract_step_list_html( $content );
            if ( $steps_html ) {
                Versa_AI_SEO_Tasks::insert_task( $post_id, 'howto_schema', [ 'steps_html' => $steps_html, 'title' => $title ], $initial_status );
            }
        }

        // VideoObject when embedded video detected and missing schema.
        $video = $this->detect_video_embed( $content );
        if ( $video && ! $this->content_has_schema_type( $content, 'VideoObject' ) && ! $this->has_open_task( $post_id, 'video_schema' ) ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'video_schema', $video + [ 'title' => $title ], $initial_status );
        }

        // Organization/LocalBusiness schema for front page only.
        $front_page_id = (int) get_option( 'page_on_front' );
        if ( $front_page_id > 0 && $post_id === $front_page_id && ! $this->content_has_schema_type( $content, 'Organization' ) && ! $this->content_has_schema_type( $content, 'LocalBusiness' ) && ! $this->has_open_task( $post_id, 'org_schema' ) ) {
            Versa_AI_SEO_Tasks::insert_task( $post_id, 'org_schema', [], $initial_status );
        }
    }

    /**
     * Process a single task using OpenAI.
     */
    private function process_task( array $task, array $profile, bool $apply_now ): array {
        $post_id   = (int) $task['post_id'];
        $task_type = $task['task_type'];
        $payload   = json_decode( $task['payload'] ?? '', true ) ?: [];

        $content = get_post_field( 'post_content', $post_id );
        $title   = get_the_title( $post_id );

        switch ( $task_type ) {
            case 'expand_content':
                return $this->handle_expand_content( $post_id, $title, $content, $profile, $apply_now );
            case 'write_snippet':
                return $this->handle_write_snippet( $post_id, $title, $content, $profile, $apply_now );
            case 'internal_linking':
                $service_urls = $payload['service_urls'] ?? Versa_AI_Service_URLs::get_urls();
                return $this->handle_internal_linking( $post_id, $title, $content, $profile, $service_urls, $apply_now );
            case 'faq_schema':
                return $this->handle_faq_schema( $post_id, $content, $profile, $apply_now );
            case 'article_schema':
                return $this->handle_article_schema( $post_id, $content, $profile, $apply_now );
            case 'breadcrumb_schema':
                return $this->handle_breadcrumb_schema( $post_id, $content, $profile, $apply_now );
            case 'howto_schema':
                return $this->handle_howto_schema( $post_id, $content, $profile, $apply_now );
            case 'video_schema':
                return $this->handle_video_schema( $post_id, $content, $profile, $payload, $apply_now );
            case 'org_schema':
                return $this->handle_org_schema( $post_id, $profile, $apply_now );
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
        $profile = $this->get_profile();

        $crawl_cooldown_hours = isset( $profile['crawl_cooldown_hours'] ) ? (int) $profile['crawl_cooldown_hours'] : 4;
        if ( $crawl_cooldown_hours < 1 ) {
            $crawl_cooldown_hours = 1;
        }

        // Limit crawl frequency based on configured cooldown.
        $transient_key = 'versa_ai_site_crawl_ran';
        if ( get_transient( $transient_key ) ) {
            return;
        }

        $crawl_limit = isset( $profile['crawl_limit'] ) ? (int) $profile['crawl_limit'] : 120;
        $pages       = $this->crawler->crawl( $crawl_limit );
        if ( empty( $pages ) ) {
            return;
        }

        $issues = [];
        foreach ( $pages as $page ) {
            $post_id = url_to_postid( $page['url'] );
            // Only consider pages we can map to posts/pages.
            if ( $post_id <= 0 ) {
                continue;
            }

        $prompt[] = 'Include FAQ section with 3-5 Q&A if missing.';
            $page['post_slug'] = get_post_field( 'post_name', $post_id );

            if ( isset( $page['status'] ) && $page['status'] >= 400 ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'http_error',

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-expand-content', [
            'business_name'      => $profile['business_name'],
            'services'           => implode( ', ', $profile['services'] ),
            'locations'          => implode( ', ', $profile['locations'] ),
            'target_audience'    => $profile['target_audience'],
            'tone_of_voice'      => $profile['tone_of_voice'],
            'max_words_per_post' => (int) $profile['max_words_per_post'],
            'content'            => $content,
        ], function () use ( $prompt ) {
            return implode( "\n", $prompt );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO content improver. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];
            }

            if ( ! empty( $page['noindex'] ) ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'noindex_tag',
                    'summary'            => 'Page is blocked by noindex',
                    'recommended_action' => 'Remove the noindex directive if this page should rank.',
                    'priority'           => 'high',
                ] );
            }

            if ( ! empty( $page['canonical'] ) && $this->canonical_mismatch( $page['url'], $page['canonical'] ) ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'canonical_mismatch',
                    'summary'            => 'Canonical URL points elsewhere',
                    'recommended_action' => 'Update canonical to the live URL or ensure the canonical target is correct.',
                    'priority'           => 'medium',
                ] );
            }

            if ( ! $page['has_title'] ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'missing_title',
                    'summary'            => 'Missing meta title',
                    'recommended_action' => 'Generate a meta title for this page.',
                    'priority'           => 'high',
                ] );
            }

            if ( ! $page['has_meta_description'] ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'missing_meta_description',
                    'summary'            => 'Missing meta description',
                    'recommended_action' => 'Generate a meta description for this page.',
                    'priority'           => 'medium',
                ] );
            }

            if ( isset( $page['has_h1'] ) && ! $page['has_h1'] ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'missing_h1',
                    'summary'            => 'Missing H1 heading',
                    'recommended_action' => 'Add a single, descriptive H1 heading that matches the topic.',
                    'priority'           => 'medium',
                ] );
            }

            if ( $page['word_count'] > 0 && $page['word_count'] < 600 ) {
                $issues[] = array_merge( $page, [
                    'issue'              => 'thin_content',
                    'summary'            => 'Content is thin',
                    'recommended_action' => 'Expand the content to at least 900 words with better depth.',
                    'priority'           => 'medium',
                ] );
            }
        }

        if ( empty( $issues ) ) {
            set_transient( $transient_key, 1, 6 * HOUR_IN_SECONDS );
            return;
        }

        $require_approval = (bool) ( $profile['require_task_approval'] ?? false );
        $initial_status   = $require_approval ? 'awaiting_approval' : 'pending';

        foreach ( $issues as $issue ) {
            // Store actionable payload including post_id and URL.
            $payload = [
                'post_id'            => $issue['post_id'],
                'post_slug'          => $issue['post_slug'] ?? '',
                'url'                => $issue['url'],
                'issue'              => $issue['issue'],
                'summary'            => $issue['summary'],
                'recommended_action' => $issue['recommended_action'],
                'priority'           => $issue['priority'],
                'word_count'         => $issue['word_count'],
                'status'             => $issue['status'] ?? 200,
                'canonical'          => $issue['canonical'] ?? '',
                'meta_robots'        => $issue['meta_robots'] ?? '',
                'has_h1'             => isset( $issue['has_h1'] ) ? (bool) $issue['has_h1'] : null,
                'noindex'            => isset( $issue['noindex'] ) ? (bool) $issue['noindex'] : null,
            ];

            Versa_AI_SEO_Tasks::insert_task( $issue['post_id'], 'site_audit', $payload, $initial_status );
        }

        set_transient( $transient_key, 1, $crawl_cooldown_hours * HOUR_IN_SECONDS );
    }

    /**
     * Expand content task.
     */
    private function handle_expand_content( int $post_id, string $title, string $content, array $profile, bool $apply_now ): array {
        $prompt = [];
        $prompt[] = 'Improve and expand the following post HTML to be more in-depth and SEO-optimized.';
        $prompt[] = 'Business name: ' . $profile['business_name'];
        $prompt[] = 'Services: ' . implode( ', ', $profile['services'] );
        $prompt[] = 'Locations: ' . implode( ', ', $profile['locations'] );
        $prompt[] = 'Target audience: ' . $profile['target_audience'];
        $prompt[] = 'Tone: ' . $profile['tone_of_voice'];
        $prompt[] = 'Respect existing structure; use clean HTML (<h2>/<h3>, <p>, <ul>/<ol>, <strong>, <em>); no inline styles; no Markdown.';
        $profile = $this->get_profile();
        $enable_faq_tasks = ! empty( $profile['enable_faq_tasks'] );
        $faq_allowed_post_types = array_map( 'sanitize_key', (array) ( $profile['faq_allowed_post_types'] ?? array( 'post', 'page' ) ) );
        $post_type = get_post_type( $post_id );

        if ( $enable_faq_tasks && $post_type && in_array( sanitize_key( $post_type ), $faq_allowed_post_types, true ) ) {
            $prompt[] = 'Only add a FAQ section if it fits the page intent (service/how-to/benefits). Do NOT add for news/announcements.';
            $prompt[] = 'If adding, place the FAQ section near the end before any call-to-action, and keep 3-5 concise Q&A.';
        }
        $prompt[] = 'Depth: fill gaps a top-ranking competitor would cover—add specifics, examples, stats, steps, or checklists where helpful. Keep paragraphs short and scannable; use bullets for steps/lists.';
        $prompt[] = 'Links: retain existing internal links; add up to 5 new natural internal links only when clearly relevant to service/location pages; never invent URLs; no link stuffing.';
        $prompt[] = 'Provide enough depth to satisfy search intent and compete with top results; aim roughly 900 to ' . (int) $profile['max_words_per_post'] . ' words when warranted, but prefer clarity and completeness over padding.';
        $prompt[] = 'Return JSON ONLY with key content_html. Never return Markdown.';
        $prompt[] = '--- CURRENT HTML START ---';
        $prompt[] = $content;
        $prompt[] = '--- CURRENT HTML END ---';

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-expand-content', [
            'business_name'      => $profile['business_name'],
            'services'           => implode( ', ', $profile['services'] ),
            'locations'          => implode( ', ', $profile['locations'] ),
            'target_audience'    => $profile['target_audience'],
            'tone_of_voice'      => $profile['tone_of_voice'],
            'max_words_per_post' => (int) $profile['max_words_per_post'],
            'content'            => $content,
        ], function () use ( $prompt ) {
            return implode( "\n", $prompt );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO content improver. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.35 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $parsed = $this->parse_content_json( $resp['data'] );
        if ( ! $parsed ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            $this->backup_content( $post_id, $content );
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $parsed['content_html'] ] );

            return [ 'success' => true, 'details' => [ 'message' => 'Content expanded.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Content ready to apply.', 'content_html' => $parsed['content_html'] ] ];
    }

    /**
     * Write snippet task.
     */
    private function handle_write_snippet( int $post_id, string $title, string $content, array $profile, bool $apply_now ): array {
        $prompt = [];
        $prompt[] = 'Write SEO title and meta description for the post below.';
        $prompt[] = 'Business name: ' . $profile['business_name'];
        $prompt[] = 'Tone: ' . $profile['tone_of_voice'];
        $prompt[] = 'Constraints: include the primary topic/keyword naturally; title <= 60 chars; description <= 155 chars; no clickbait; include value prop + qualifier + brand if room.';
        $prompt[] = 'Return JSON ONLY with keys seo_title and seo_description. No prose.';
        $prompt[] = '--- TITLE ---';
        $prompt[] = $title;
        $prompt[] = '--- CONTENT ---';
        $prompt[] = wp_strip_all_tags( $content );

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-write-snippet', [
            'business_name' => $profile['business_name'],
            'tone_of_voice' => $profile['tone_of_voice'],
            'title'         => $title,
            'content'       => wp_strip_all_tags( $content ),
        ], function () use ( $prompt ) {
            return implode( "\n", $prompt );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO expert. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.3 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_snippet_json( $resp['data'] );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
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

        return [ 'success' => true, 'details' => [ 'message' => 'SEO snippet ready to apply.', 'seo_title' => $data['seo_title'], 'seo_description' => $data['seo_description'] ] ];
    }

    /**
     * Internal linking task.
     */
    private function handle_internal_linking( int $post_id, string $title, string $content, array $profile, array $service_urls, bool $apply_now ): array {
        $service_urls = $this->filter_existing_service_urls( $service_urls );
        if ( empty( $service_urls ) ) {
            return [ 'success' => true, 'details' => [ 'message' => 'No valid service URLs to link.' ] ];
        }

        $prompt = [];
        $prompt[] = 'Add a few natural internal links to the provided service URLs inside the HTML content.';
        $prompt[] = 'Do not change meaning or add new sections.';
        $prompt[] = 'Use descriptive, varied anchor text; do not invent URLs.';
        $prompt[] = 'Limit to at most 5 inserted links; avoid duplicating the same anchor to the same URL.';
        $prompt[] = 'Keep HTML clean; do not add inline styles; do not convert to Markdown.';
        $prompt[] = 'Return JSON ONLY with key content_html.';
        $prompt[] = 'Service URLs:';
        foreach ( $service_urls as $url ) {
            $prompt[] = '- ' . $url;
        }
        $prompt[] = '--- CURRENT HTML START ---';
        $prompt[] = $content;
        $prompt[] = '--- CURRENT HTML END ---';

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-internal-linking', [
            'service_urls' => implode( "\n- ", $service_urls ),
            'content'      => $content,
        ], function () use ( $prompt ) {
            return implode( "\n", $prompt );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO editor. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.25 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $parsed = $this->parse_content_json( $resp['data'] );
        if ( ! $parsed ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            $this->backup_content( $post_id, $content );
            wp_update_post( [ 'ID' => $post_id, 'post_content' => $parsed['content_html'] ] );

            return [ 'success' => true, 'details' => [ 'message' => 'Internal links added.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Internal links ready to apply.', 'content_html' => $parsed['content_html'] ] ];
    }

    /**
     * FAQ schema task.
     */
    private function handle_faq_schema( int $post_id, string $content, array $profile, bool $apply_now ): array {
        $faq_section = $this->extract_faq_section( $content );
        if ( ! $faq_section ) {
            return [ 'success' => false, 'details' => [ 'message' => 'FAQ section not found.' ] ];
        }

        $prompt = [];
        $prompt[] = 'Generate FAQPage JSON-LD for this FAQ section. Return JSON ONLY with key faq_schema_json (object).';
        $prompt[] = 'Use 3-5 question/answer pairs; keep answers concise and factual; avoid medical/legal/financial claims.';
        $prompt[] = 'Ensure output is valid FAQPage schema JSON-LD.';
        $prompt[] = 'FAQ section HTML:';
        $prompt[] = $faq_section;

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-faq-schema', [
            'faq_section' => $faq_section,
        ], function () use ( $prompt ) {
            return implode( "\n", $prompt );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.2 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_faq_schema_json( $resp['data'] );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_faq_schema', wp_json_encode( $data['faq_schema_json'] ) );

            return [ 'success' => true, 'details' => [ 'message' => 'FAQ schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'FAQ schema ready to apply.', 'faq_schema_json' => $data['faq_schema_json'] ] ];
    }

    /**
     * Article schema task.
     */
    private function handle_article_schema( int $post_id, string $content, array $profile, bool $apply_now ): array {
        $title        = get_the_title( $post_id );
        $description  = $this->extract_meta_description( $post_id, $content );
        $author_name  = get_the_author_meta( 'display_name', (int) get_post_field( 'post_author', $post_id ) ) ?: $profile['business_name'];
        $url          = get_permalink( $post_id );
        $date_pub     = get_the_date( DATE_ATOM, $post_id );
        $date_mod     = get_post_modified_time( DATE_ATOM, false, $post_id );

        $prompt_lines   = [];
        $prompt_lines[] = 'Generate Article/BlogPosting JSON-LD.';
        $prompt_lines[] = 'Return JSON ONLY with key article_schema_json (object).';
        $prompt_lines[] = 'Fields:';
        $prompt_lines[] = 'title: ' . $title;
        $prompt_lines[] = 'description: ' . $description;
        $prompt_lines[] = 'author: ' . $author_name;
        $prompt_lines[] = 'url: ' . $url;
        $prompt_lines[] = 'datePublished: ' . $date_pub;
        $prompt_lines[] = 'dateModified: ' . $date_mod;

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-article-schema', [
            'title'        => $title,
            'description'  => $description,
            'author_name'  => $author_name,
            'url'          => $url,
            'date_pub'     => $date_pub,
            'date_mod'     => $date_mod,
        ], function () use ( $prompt_lines ) {
            return implode( "\n", $prompt_lines );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.2 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_schema_json( $resp['data'], 'article_schema_json' );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_article_schema', wp_json_encode( $data['article_schema_json'] ) );
            return [ 'success' => true, 'details' => [ 'message' => 'Article schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Article schema ready to apply.', 'article_schema_json' => $data['article_schema_json'] ] ];
    }

    /**
     * Breadcrumb schema task.
     */
    private function handle_breadcrumb_schema( int $post_id, string $content, array $profile, bool $apply_now ): array {
        $trail = $this->get_breadcrumb_trail( $post_id );
        if ( empty( $trail ) ) {
            return [ 'success' => false, 'details' => [ 'message' => 'No breadcrumb trail available.' ] ];
        }

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-breadcrumb-schema', [
            'trail' => implode( "\n", array_map( fn( $item ) => $item['label'] . '|' . $item['url'], $trail ) ),
        ], function () use ( $trail ) {
            $lines = [];
            $lines[] = 'Generate BreadcrumbList JSON-LD. Return JSON ONLY with key breadcrumb_schema_json (object).';
            $lines[] = 'Trail (label|url):';
            foreach ( $trail as $item ) {
                $lines[] = $item['label'] . ' | ' . $item['url'];
            }
            return implode( "\n", $lines );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.15 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_schema_json( $resp['data'], 'breadcrumb_schema_json' );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_breadcrumb_schema', wp_json_encode( $data['breadcrumb_schema_json'] ) );
            return [ 'success' => true, 'details' => [ 'message' => 'Breadcrumb schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Breadcrumb schema ready to apply.', 'breadcrumb_schema_json' => $data['breadcrumb_schema_json'] ] ];
    }

    /**
     * HowTo schema task.
     */
    private function handle_howto_schema( int $post_id, string $content, array $profile, bool $apply_now ): array {
        $title = get_the_title( $post_id );
        $steps_html = $this->extract_step_list_html( $content );
        if ( ! $steps_html ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Steps not found.' ] ];
        }

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-howto-schema', [
            'title' => $title,
            'steps_html' => $steps_html,
        ], function () use ( $title, $steps_html ) {
            $lines   = [];
            $lines[] = 'Generate HowTo JSON-LD for the steps below. Return JSON ONLY with key howto_schema_json (object).';
            $lines[] = 'Title: ' . $title;
            $lines[] = 'Steps HTML:';
            $lines[] = $steps_html;
            return implode( "\n", $lines );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.2 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_schema_json( $resp['data'], 'howto_schema_json' );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_howto_schema', wp_json_encode( $data['howto_schema_json'] ) );
            return [ 'success' => true, 'details' => [ 'message' => 'HowTo schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'HowTo schema ready to apply.', 'howto_schema_json' => $data['howto_schema_json'] ] ];
    }

    /**
     * Video schema task.
     */
    private function handle_video_schema( int $post_id, string $content, array $profile, array $payload, bool $apply_now ): array {
        $title = $payload['title'] ?? get_the_title( $post_id );
        $video_url = $payload['video_url'] ?? '';
        if ( ! $video_url ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Video URL missing.' ] ];
        }

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-video-schema', [
            'title'     => $title,
            'video_url' => $video_url,
        ], function () use ( $title, $video_url ) {
            $lines   = [];
            $lines[] = 'Generate VideoObject JSON-LD.';
            $lines[] = 'Return JSON ONLY with key video_schema_json (object).';
            $lines[] = 'Title: ' . $title;
            $lines[] = 'Video URL: ' . $video_url;
            return implode( "\n", $lines );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.15 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_schema_json( $resp['data'], 'video_schema_json' );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_video_schema', wp_json_encode( $data['video_schema_json'] ) );
            return [ 'success' => true, 'details' => [ 'message' => 'Video schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Video schema ready to apply.', 'video_schema_json' => $data['video_schema_json'] ] ];
    }

    /**
     * Organization schema task (front page only).
     */
    private function handle_org_schema( int $post_id, array $profile, bool $apply_now ): array {
        $site_name = get_bloginfo( 'name' );
        $site_url  = home_url( '/' );
        $logo      = function_exists( 'get_site_icon_url' ) ? get_site_icon_url() : '';
        $description = get_bloginfo( 'description' );

        $prompt_text = Versa_AI_Prompts::render( 'optimizer-org-schema', [
            'name'        => $site_name,
            'url'         => $site_url,
            'logo'        => $logo,
            'description' => $description,
        ], function () use ( $site_name, $site_url, $logo, $description ) {
            $lines   = [];
            $lines[] = 'Generate Organization (or LocalBusiness if appropriate) JSON-LD.';
            $lines[] = 'Return JSON ONLY with key org_schema_json (object).';
            $lines[] = 'Name: ' . $site_name;
            $lines[] = 'URL: ' . $site_url;
            $lines[] = 'Logo: ' . $logo;
            $lines[] = 'Description: ' . $description;
            return implode( "\n", $lines );
        } );

        $messages = [
            [ 'role' => 'system', 'content' => 'You are a schema generator. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt_text ],
        ];

        $resp = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.15 );
        if ( ! $resp['success'] ) {
            return [ 'success' => false, 'details' => [ 'message' => $resp['error'] ] ];
        }

        $data = $this->parse_schema_json( $resp['data'], 'org_schema_json' );
        if ( ! $data ) {
            return [ 'success' => false, 'details' => [ 'message' => 'Invalid JSON response.' ] ];
        }

        if ( $apply_now ) {
            update_post_meta( $post_id, 'versa_ai_org_schema', wp_json_encode( $data['org_schema_json'] ) );
            return [ 'success' => true, 'details' => [ 'message' => 'Organization schema updated.' ] ];
        }

        return [ 'success' => true, 'details' => [ 'message' => 'Organization schema ready to apply.', 'org_schema_json' => $data['org_schema_json'] ] ];
    }

    /**
     * Helpers
     */

    private function parse_content_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = $this->decode_json_content( $content );
        if ( ! is_array( $decoded ) || empty( $decoded['content_html'] ) ) {
            Versa_AI_Logger::log( 'optimizer', 'Invalid JSON for content_html: ' . substr( $content, 0, 500 ) );
            return null;
        }
        return [ 'content_html' => $decoded['content_html'] ];
    }

    private function parse_snippet_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = $this->decode_json_content( $content );
        if ( ! is_array( $decoded ) ) {
            Versa_AI_Logger::log( 'optimizer', 'Invalid JSON for snippet: ' . substr( $content, 0, 500 ) );
            return null;
        }
        return [
            'seo_title'       => $decoded['seo_title'] ?? '',
            'seo_description' => $decoded['seo_description'] ?? '',
        ];
    }

    private function parse_faq_schema_json( array $openai_response ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = $this->decode_json_content( $content );
        if ( ! is_array( $decoded ) || ! isset( $decoded['faq_schema_json'] ) ) {
            Versa_AI_Logger::log( 'optimizer', 'Invalid JSON for faq_schema: ' . substr( $content, 0, 500 ) );
            return null;
        }
        return [ 'faq_schema_json' => $decoded['faq_schema_json'] ];
    }

    private function parse_schema_json( array $openai_response, string $key ): ?array {
        $content = $openai_response['choices'][0]['message']['content'] ?? '';
        $decoded = $this->decode_json_content( $content );
        if ( ! is_array( $decoded ) || ! isset( $decoded[ $key ] ) ) {
            Versa_AI_Logger::log( 'optimizer', 'Invalid JSON for ' . $key . ': ' . substr( $content, 0, 500 ) );
            return null;
        }
        return [ $key => $decoded[ $key ] ];
    }

    /**
     * Try to extract JSON from the model response, handling code fences or leading text.
     */
    private function decode_json_content( string $content ): ?array {
        $trimmed = trim( $content );

        $decoded = json_decode( $trimmed, true );
        if ( is_array( $decoded ) ) {
            return $decoded;
        }

        // Extract JSON inside ```json ``` code fences if present.
        if ( preg_match( '/```(?:json)?\s*(\{.*?\})\s*```/s', $trimmed, $matches ) ) {
            $decoded = json_decode( trim( $matches[1] ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        // Fallback: grab first JSON-looking object in the string.
        if ( preg_match( '/(\{.*\})/s', $trimmed, $matches ) ) {
            $decoded = json_decode( trim( $matches[1] ), true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        return null;
    }

    private function content_has_schema_type( string $content, string $type ): bool {
        $needle = 'schema.org/' . $type;
        return false !== stripos( $content, $needle );
    }

    private function should_add_article_schema( WP_Post $post, string $content ): bool {
        $post_type = get_post_type( $post );
        $supported = in_array( $post_type, [ 'post', 'page' ], true );
        if ( ! $supported ) {
            return false;
        }
        if ( $this->content_has_schema_type( $content, 'Article' ) || $this->content_has_schema_type( $content, 'BlogPosting' ) ) {
            return false;
        }
        return true;
    }

    private function should_add_breadcrumb_schema( int $post_id, string $content ): bool {
        if ( $this->content_has_schema_type( $content, 'BreadcrumbList' ) ) {
            return false;
        }
        $ancestors = get_post_ancestors( $post_id );
        return ! empty( $ancestors );
    }

    private function get_breadcrumb_trail( int $post_id ): array {
        $ancestors = array_reverse( get_post_ancestors( $post_id ) );
        $trail = [];
        foreach ( $ancestors as $ancestor_id ) {
            $trail[] = [
                'label' => get_the_title( $ancestor_id ),
                'url'   => get_permalink( $ancestor_id ),
            ];
        }
        $trail[] = [
            'label' => get_the_title( $post_id ),
            'url'   => get_permalink( $post_id ),
        ];
        return array_values( array_filter( $trail, fn( $item ) => ! empty( $item['label'] ) && ! empty( $item['url'] ) ) );
    }

    private function should_add_howto_schema( WP_Post $post, string $content ): bool {
        $title = get_the_title( $post );
        $is_howto_title = stripos( $title, 'how to' ) !== false;
        $has_steps       = false !== stripos( $content, '<ol' ) || false !== stripos( $content, '<ul' );

        if ( $this->content_has_schema_type( $content, 'HowTo' ) ) {
            return false;
        }

        return ( $is_howto_title || $has_steps ) && (bool) $this->extract_step_list_html( $content );
    }

    private function extract_step_list_html( string $content ): ?string {
        // Grab the first ordered or unordered list as steps if present.
        if ( preg_match( '/<(ol|ul)[^>]*>(.*?)<\/\1>/is', $content, $matches ) ) {
            return $matches[0];
        }
        return null;
    }

    private function detect_video_embed( string $content ): ?array {
        if ( preg_match( '/<iframe[^>]+src="([^"]+)"[^>]*>.*?<\/iframe>/is', $content, $matches ) ) {
            return [ 'video_url' => $matches[1] ];
        }
        if ( preg_match( '/<video[^>]+src="([^"]+)"/is', $content, $matches ) ) {
            return [ 'video_url' => $matches[1] ];
        }
        return null;
    }

    private function extract_meta_description( int $post_id, string $content ): string {
        $seo_desc = get_post_meta( $post_id, 'rank_math_description', true );
        if ( ! $seo_desc ) {
            $seo_desc = get_post_meta( $post_id, '_yoast_wpseo_metadesc', true );
        }
        if ( $seo_desc ) {
            return wp_strip_all_tags( $seo_desc );
        }

        $excerpt = wp_trim_words( wp_strip_all_tags( $content ), 40, '' );
        return $excerpt;
    }

    private function has_open_task( int $post_id, string $task_type ): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_seo_tasks';
        $statuses = [ 'pending', 'awaiting_approval', 'running', 'awaiting_apply' ];
        $placeholders = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );

        $query = $wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE post_id = %d AND task_type = %s AND status IN ($placeholders)",
            ...array_merge( [ $post_id, $task_type ], $statuses )
        );

        $count = (int) $wpdb->get_var( $query );
        return $count > 0;
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

    private function filter_existing_service_urls( array $service_urls ): array {
        $valid = [];
        foreach ( $service_urls as $url ) {
            $post_id = url_to_postid( $url );
            if ( $post_id > 0 ) {
                $valid[] = $url;
            }
        }
        return $valid;
    }

    private function maybe_create_missing_service_pages( array $service_urls ): void {
        if ( empty( $service_urls ) ) {
            return;
        }

        $profile = $this->get_profile();
        if ( empty( $profile['auto_create_service_pages'] ) ) {
            return;
        }

        $post_type   = sanitize_key( $profile['auto_service_post_type'] ?? 'page' );
        $auto_publish = ! empty( $profile['auto_service_auto_publish'] );
        $max_per_run = isset( $profile['auto_service_max_per_run'] ) ? (int) $profile['auto_service_max_per_run'] : 3;
        if ( $max_per_run < 0 ) {
            $max_per_run = 0;
        }
        if ( 0 === $max_per_run ) {
            $max_per_run = PHP_INT_MAX;
        }

        $created = 0;
        foreach ( $service_urls as $url ) {
            if ( $created >= $max_per_run ) {
                break;
            }

            $post_id = url_to_postid( $url );
            if ( $post_id > 0 ) {
                continue;
            }

            $page_data = $this->derive_page_data_from_url( $url );
            if ( ! $page_data ) {
                continue;
            }

            $post_status = $auto_publish ? 'publish' : 'draft';

            $new_post_id = wp_insert_post( [
                'post_title'   => $page_data['title'],
                'post_name'    => $page_data['slug'],
                'post_type'    => $post_type,
                'post_status'  => $post_status,
                'post_content' => $this->generate_service_scaffold( $page_data['title'], $profile ),
            ] );

            if ( is_wp_error( $new_post_id ) || ! $new_post_id ) {
                continue;
            }

            add_post_meta( $new_post_id, '_versa_ai_auto_service_page', 1, true );

            $snapshot = $this->build_snapshot( $new_post_id, $service_urls );
            $this->maybe_create_tasks( $new_post_id, $snapshot, $service_urls );

            $created++;
        }
    }

    private function derive_page_data_from_url( string $url ): ?array {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        if ( empty( $path ) ) {
            return null;
        }

        $basename = trim( basename( untrailingslashit( $path ) ) );
        if ( empty( $basename ) ) {
            $basename = trim( trim( $path ), '/' );
        }
        if ( empty( $basename ) ) {
            return null;
        }

        $slug  = sanitize_title( $basename );
        $title = ucwords( str_replace( '-', ' ', $basename ) );

        return [
            'slug'  => $slug,
            'title' => $title,
        ];
    }

    private function generate_service_scaffold( string $title, array $profile ): string {
        $business = isset( $profile['business_name'] ) ? $profile['business_name'] : '';
        $locations = isset( $profile['locations'] ) && is_array( $profile['locations'] ) ? implode( ', ', $profile['locations'] ) : '';

        $lines   = [];
        $lines[] = '<h1>' . esc_html( $title ) . '</h1>';
        if ( $business || $locations ) {
            $lines[] = '<p>' . esc_html( trim( $business . ' ' . $locations ) ) . '</p>';
        }
        $lines[] = '<p>Overview of the service and who it is for.</p>';
        $lines[] = '<h2>What You Get</h2>';
        $lines[] = '<ul><li>Benefit 1</li><li>Benefit 2</li><li>Benefit 3</li></ul>';
        $lines[] = '<h2>Why Choose Us</h2>';
        $lines[] = '<p>Proof points, experience, and differentiators.</p>';
        $lines[] = '<h2>Next Steps</h2>';
        $lines[] = '<p>Call to action: contact, book, or request a quote.</p>';

        return implode( "\n", $lines );
    }

    private function canonical_mismatch( string $url, string $canonical ): bool {
        if ( empty( $url ) || empty( $canonical ) ) {
            return false;
        }

        $normalize = static function ( string $value ): string {
            $parts = wp_parse_url( $value );
            if ( empty( $parts['host'] ) ) {
                return '';
            }

            $scheme = $parts['scheme'] ?? 'https';
            $path   = isset( $parts['path'] ) ? '/' . ltrim( $parts['path'], '/' ) : '/';
            $path   = '/' === $path ? '/' : untrailingslashit( $path );
            $query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';

            return strtolower( $scheme . '://' . $parts['host'] . $path . $query );
        };

        $normalized_url       = $normalize( $url );
        $normalized_canonical = $normalize( $canonical );

        if ( ! $normalized_url || ! $normalized_canonical ) {
            return false;
        }

        return $normalized_url !== $normalized_canonical;
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
            'require_apply_after_edits' => false,
            'openai_api_key'      => '',
            'openai_model'        => 'gpt-4.1-mini',
            'crawl_limit'         => 120,
            'crawl_cooldown_hours'=> 4,
            'enable_faq_tasks'    => true,
            'faq_min_word_count'  => 600,
            'faq_allowed_post_types'=> [ 'post', 'page' ],
            'auto_create_service_pages' => false,
            'auto_service_post_type'    => 'page',
            'auto_service_auto_publish' => false,
            'auto_service_max_per_run'  => 3,
        ];

        $stored = get_option( Versa_AI_Settings_Page::OPTION_KEY, [] );
        if ( ! is_array( $stored ) ) {
            $stored = [];
        }

        return wp_parse_args( $stored, $defaults );
    }
}
