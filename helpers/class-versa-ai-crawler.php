<?php
/**
 * Lightweight internal crawler to audit site pages.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Crawler {
    /**
     * Crawl internal links starting from the home URL.
     *
     * @param int $limit Maximum pages to crawl.
     * @return array<int,array{url:string,title:string,has_title:bool,has_meta_description:bool,word_count:int,status:int}>
     */
    public function crawl( int $limit = 30 ): array {
        $home = home_url( '/' );
        $host = wp_parse_url( $home, PHP_URL_HOST );
        if ( ! $host ) {
            return [];
        }

        $queue   = [ trailingslashit( $home ) ];
        $seen    = [];
        $results = [];

        while ( ! empty( $queue ) && count( $results ) < $limit ) {
            $url = array_shift( $queue );
            if ( isset( $seen[ $url ] ) ) {
                continue;
            }
            $seen[ $url ] = true;

            $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
            if ( is_wp_error( $response ) ) {
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = wp_remote_retrieve_body( $response );
            $ctype = wp_remote_retrieve_header( $response, 'content-type' );

            if ( $code >= 300 || false === stripos( (string) $ctype, 'text/html' ) ) {
                continue;
            }

            $title = $this->extract_title( $body );
            $meta_desc = $this->extract_meta_desc( $body );
            $word_count = str_word_count( wp_strip_all_tags( $body ) );

            $results[] = [
                'url'                  => $url,
                'title'                => $title,
                'has_title'            => ! empty( $title ),
                'has_meta_description' => ! empty( $meta_desc ),
                'word_count'           => $word_count,
                'status'               => $code,
            ];

            // Collect internal links.
            foreach ( $this->extract_links( $body ) as $link ) {
                $parsed = wp_parse_url( $link );
                if ( empty( $parsed['host'] ) || $parsed['host'] !== $host ) {
                    continue;
                }
                $normalized = $this->normalize_url( $link );
                if ( $normalized && ! isset( $seen[ $normalized ] ) && count( $queue ) + count( $results ) < $limit ) {
                    $queue[] = $normalized;
                }
            }
        }

        return $results;
    }

    private function extract_title( string $html ): string {
        if ( preg_match( '/<title[^>]*>(.*?)<\/title>/is', $html, $m ) ) {
            return wp_strip_all_tags( $m[1] );
        }
        return '';
    }

    private function extract_meta_desc( string $html ): string {
        if ( preg_match( '/<meta\s+name="description"\s+content="([^"]*)"[^>]*>/i', $html, $m ) ) {
            return wp_strip_all_tags( $m[1] );
        }
        return '';
    }

    private function extract_links( string $html ): array {
        $links = [];
        if ( preg_match_all( '/<a\s+[^>]*href="([^"]+)"/i', $html, $m ) ) {
            $links = $m[1];
        }
        return array_unique( $links );
    }

    private function normalize_url( string $url ): string {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return '';
        }
        $path = $parsed['path'] ?? '/';
        $query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';
        return trailingslashit( $parsed['scheme'] . '://' . $parsed['host'] . $path ) . ltrim( $query, '/' );
    }
}
