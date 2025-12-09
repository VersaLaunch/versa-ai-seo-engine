<?php
/**
 * Lightweight OpenAI client wrapper using WordPress HTTP API.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_OpenAI_Client {
    private const API_BASE = 'https://api.openai.com/v1/chat/completions';

    /**
     * Perform a chat completion request.
     *
     * @param string $api_key     OpenAI API key.
     * @param string $model       Model name.
     * @param array  $messages    Chat messages array per OpenAI format.
     * @param float  $temperature Temperature value.
     * @param int    $max_tokens  Optional max tokens.
     *
     * @return array{success:bool,data:array|null,error:string|null}
     */
    public function chat( string $api_key, string $model, array $messages, float $temperature = 0.4, int $max_tokens = 0 ): array {
        if ( empty( $api_key ) || empty( $model ) ) {
            return [ 'success' => false, 'data' => null, 'error' => 'Missing API key or model.' ];
        }

        $body = [
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ];

        if ( $max_tokens > 0 ) {
            $body['max_tokens'] = $max_tokens;
        }

        $response = wp_remote_post(
            self::API_BASE,
            [
                'headers' => [
                    'Authorization' => 'Bearer ' . $api_key,
                    'Content-Type'  => 'application/json',
                ],
                'timeout' => 60,
                'body'    => wp_json_encode( $body ),
            ]
        );

        if ( is_wp_error( $response ) ) {
            return [ 'success' => false, 'data' => null, 'error' => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $raw  = wp_remote_retrieve_body( $response );
        if ( $code < 200 || $code >= 300 ) {
            return [ 'success' => false, 'data' => null, 'error' => 'HTTP ' . $code . ' ' . $raw ];
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) || empty( $decoded['choices'][0]['message']['content'] ) ) {
            return [ 'success' => false, 'data' => null, 'error' => 'Unexpected OpenAI response.' ];
        }

        return [ 'success' => true, 'data' => $decoded, 'error' => null ];
    }
}
