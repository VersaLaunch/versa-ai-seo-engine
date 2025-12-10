<?php
/**
 * Weekly AI planner that proposes topics and queues them for writing.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/prompts/class-versa-ai-prompts.php';

class Versa_AI_Planner {
    private Versa_AI_OpenAI_Client $client;

    public function __construct() {
        $this->client = new Versa_AI_OpenAI_Client();
    }

    /**
     * Entry point for the weekly planner cron.
     */
    public function run(): void {
        $profile = $this->get_profile();
        if ( empty( $profile['openai_api_key'] ) || empty( $profile['openai_model'] ) ) {
            Versa_AI_Logger::log( 'planner', 'Missing OpenAI credentials; skipping planner.' );
            return;
        }

        $count = (int) $profile['posts_per_week'];
        if ( $count <= 0 ) {
            return;
        }

        $count = min( 14, $count ); // hard cap safety.

        $existing_titles = $this->get_existing_titles( 150 );
        $existing_queue  = $this->get_queued_titles( 150 );

        $prompt = $this->build_prompt( $profile, $existing_titles, $existing_queue, $count );
        $messages = [
            [ 'role' => 'system', 'content' => 'You are an SEO strategist. Reply with strict JSON only.' ],
            [ 'role' => 'user', 'content' => $prompt ],
        ];

        $response = $this->client->chat( $profile['openai_api_key'], $profile['openai_model'], $messages, 0.35 );
        if ( ! $response['success'] ) {
            Versa_AI_Logger::log( 'planner', 'OpenAI error: ' . $response['error'] );
            return;
        }

        $content = $response['data']['choices'][0]['message']['content'] ?? '';
        $ideas   = $this->decode_json_ideas( $content );
        if ( empty( $ideas ) ) {
            Versa_AI_Logger::log( 'planner', 'No ideas parsed from OpenAI response.' );
            return;
        }

        $unique_ideas = $this->filter_duplicates( $ideas, $existing_titles, $existing_queue );
        if ( empty( $unique_ideas ) ) {
            Versa_AI_Logger::log( 'planner', 'All proposed ideas were duplicates; none queued.' );
            return;
        }

        $this->store_ideas( $unique_ideas );
    }

    /**
     * Build user prompt string.
     */
    private function build_prompt( array $profile, array $existing_titles, array $existing_queue, int $count ): string {
        $context = [
            'business_name'  => $profile['business_name'],
            'services'       => implode( ', ', $profile['services'] ),
            'locations'      => implode( ', ', $profile['locations'] ),
            'target_audience'=> $profile['target_audience'],
            'tone_of_voice'  => $profile['tone_of_voice'],
            'max_words'      => $profile['max_words_per_post'],
            'count'          => $count,
            'existing_titles'=> implode( '; ', $existing_titles ),
            'queued_titles'  => implode( '; ', $existing_queue ),
        ];

        return Versa_AI_Prompts::render( 'planner', $context, function () use ( $profile, $existing_titles, $existing_queue, $count ) {
            $lines = [];
            $lines[] = 'Business name: ' . $profile['business_name'];
            $lines[] = 'Services: ' . implode( ', ', $profile['services'] );
            $lines[] = 'Locations: ' . implode( ', ', $profile['locations'] );
            $lines[] = 'Target audience: ' . $profile['target_audience'];
            $lines[] = 'Tone: ' . $profile['tone_of_voice'];
            $lines[] = 'Max words: ' . $profile['max_words_per_post'];
            $lines[] = 'Generate exactly ' . $count . ' new blog topics with primary keyword and outline.';
            if ( $existing_titles ) {
                $lines[] = 'Avoid duplicate or similar topics to these existing posts: ' . implode( '; ', $existing_titles );
            }
            if ( $existing_queue ) {
                $lines[] = 'Do not repeat topics already queued: ' . implode( '; ', $existing_queue );
            }
            $lines[] = 'Each topic and keyword must be unique.';
            $lines[] = 'Cover a mix of TOFU/MOFU/BOFU intents across services and locations; avoid repeating the same angle.';
            $lines[] = 'Pair services with locations naturally where it makes sense; include local modifiers.';
            $lines[] = 'Return JSON array only, no prose. Each item: {"topic": string, "keyword": string, "outline": [5-7 strings], "search_intent": "TOFU|MOFU|BOFU", "angle": string}';
            return implode( "\n", $lines );
        } );
    }

    /**
     * Fetch existing post titles to avoid duplicates.
     */
    private function get_existing_titles( int $limit ): array {
        $posts = get_posts(
            [
                'post_type'      => 'post',
                'post_status'    => 'publish',
                'numberposts'    => $limit,
                'orderby'        => 'date',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'suppress_filters' => true,
            ]
        );

        $titles = [];
        foreach ( $posts as $post_id ) {
            $title = get_the_title( $post_id );
            if ( $title ) {
                $titles[] = $title;
            }
        }

        return $titles;
    }

    private function get_queued_titles( int $limit ): array {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_content_queue';

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT post_title FROM {$table} WHERE status IN (%s,%s,%s) ORDER BY created_at DESC LIMIT %d",
                'queued',
                'writing',
                'published',
                $limit
            ),
            ARRAY_A
        );

        $titles = [];
        foreach ( (array) $rows as $row ) {
            if ( ! empty( $row['post_title'] ) ) {
                $titles[] = $row['post_title'];
            }
        }

        return $titles;
    }

    /**
     * Decode OpenAI JSON string into array of ideas.
     */
    private function decode_json_ideas( string $content ): array {
        $content = trim( $content );
        $decoded = json_decode( $content, true );

        if ( ! is_array( $decoded ) ) {
            return [];
        }

        $ideas = [];
        foreach ( $decoded as $item ) {
            if ( empty( $item['topic'] ) || empty( $item['keyword'] ) || empty( $item['outline'] ) ) {
                continue;
            }

            $ideas[] = [
                'topic'   => sanitize_text_field( $item['topic'] ),
                'keyword' => sanitize_text_field( $item['keyword'] ),
                'outline' => array_values( array_filter( array_map( 'sanitize_text_field', (array) $item['outline'] ) ) ),
            ];
        }

        return $ideas;
    }

    private function filter_duplicates( array $ideas, array $existing_titles, array $queued_titles ): array {
        $seen_slugs = [];
        foreach ( array_merge( $existing_titles, $queued_titles ) as $title ) {
            $slug = sanitize_title( $title );
            if ( $slug ) {
                $seen_slugs[ $slug ] = true;
            }
        }

        $unique = [];
        foreach ( $ideas as $idea ) {
            $slug = sanitize_title( $idea['topic'] );
            if ( ! $slug ) {
                continue;
            }
            if ( isset( $seen_slugs[ $slug ] ) ) {
                continue;
            }
            $seen_slugs[ $slug ] = true;
            $unique[] = $idea;
        }

        return $unique;
    }

    /**
     * Persist ideas into the content queue table.
     */
    private function store_ideas( array $ideas ): void {
        global $wpdb;
        $table = $wpdb->prefix . 'versa_ai_content_queue';

        $offset_days = 0;
        foreach ( $ideas as $idea ) {
            $scheduled_ts = current_time( 'timestamp' ) + ( $offset_days * DAY_IN_SECONDS );
            $scheduled    = gmdate( 'Y-m-d', $scheduled_ts );

            $wpdb->insert(
                $table,
                [
                    'post_title'         => $idea['topic'],
                    'target_keyword'    => $idea['keyword'],
                    'outline_json'      => wp_json_encode( $idea['outline'] ),
                    'status'            => 'queued',
                    'scheduled_for_date'=> $scheduled,
                    'created_at'        => current_time( 'mysql' ),
                    'updated_at'        => current_time( 'mysql' ),
                ],
                [ '%s', '%s', '%s', '%s', '%s', '%s', '%s' ]
            );

            $offset_days++;
        }

        Versa_AI_Logger::log( 'planner', 'Queued ' . count( $ideas ) . ' topics.' );
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
