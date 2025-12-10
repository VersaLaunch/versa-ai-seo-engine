<?php
/**
 * Prompt loader/renderer with simple {{placeholder}} substitution.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Prompts {
    /**
     * Render a prompt template if available, otherwise fall back.
     *
     * @param string   $name     Template base name (without extension).
     * @param array    $context  Key/value pairs for {{placeholders}}.
     * @param callable $fallback Callback returning the fallback string.
     */
    public static function render( string $name, array $context, callable $fallback ): string {
        $path = VERSA_AI_SEO_ENGINE_PLUGIN_DIR . 'ai/prompts/' . $name . '.txt';

        if ( is_readable( $path ) ) {
            $raw = file_get_contents( $path );
            if ( false !== $raw && '' !== $raw ) {
                $rendered = $raw;
                foreach ( $context as $key => $value ) {
                    if ( is_array( $value ) ) {
                        $value = implode( ', ', $value );
                    }
                    $rendered = str_replace( '{{' . $key . '}}', (string) $value, $rendered );
                }
                return trim( $rendered );
            }
        }

        return $fallback();
    }
}
