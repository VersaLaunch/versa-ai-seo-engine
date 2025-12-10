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
     * Crawl internal links starting from sitemap/home and return page signals.
     *
    * @param int $limit Maximum pages to crawl (0 = unlimited).
     * @return array<int,array{url:string,title:string,has_title:bool,has_meta_description:bool,word_count:int,status:int,canonical:string,noindex:bool,has_h1:bool,meta_robots:string}>
     */
    public function crawl( int $limit = 120 ): array {
        $home = trailingslashit( home_url( '/' ) );
        $host = wp_parse_url( $home, PHP_URL_HOST );
        if ( ! $host ) {
            return [];
        }

        if ( $limit <= 0 ) {
            $limit = PHP_INT_MAX; // Effectively unlimited, bounded by queue exhaustion.
        }

        $queue   = $this->seed_queue( $home, $host, $limit );
        if ( empty( $queue ) ) {
            $queue = [ $home ];
        }

        $seen    = [];
        $results = [];

        while ( ! empty( $queue ) && count( $results ) < $limit ) {
            $url = array_shift( $queue );
            $normalized = $this->normalize_url( $url, $host );
            if ( ! $normalized || isset( $seen[ $normalized ] ) ) {
                continue;
            }
            $seen[ $normalized ] = true;

            $response = wp_remote_get( $normalized, [ 'timeout' => 15, 'redirection' => 3 ] );
            if ( is_wp_error( $response ) ) {
                continue;
            }

            $code  = (int) wp_remote_retrieve_response_code( $response );
            $body  = (string) wp_remote_retrieve_body( $response );
            $ctype = (string) wp_remote_retrieve_header( $response, 'content-type' );

            // Record HTTP errors even if not HTML so the audit can flag them.
            if ( $code >= 400 ) {
                $results[] = [
                    'url'                  => $normalized,
                    'title'                => '',
                    'has_title'            => false,
                    'has_meta_description' => false,
                    'word_count'           => 0,
                    'status'               => $code,
                    'canonical'            => '',
                    'noindex'              => false,
                    'has_h1'               => false,
                    'meta_robots'          => '',
                ];
                continue;
            }

            if ( $code >= 300 || false === stripos( $ctype, 'text/html' ) ) {
                continue;
            }

            $title       = $this->extract_title( $body );
            $meta_desc   = $this->extract_meta_desc( $body );
            $meta_robots = $this->extract_meta_robots( $body );
            $canonical   = $this->extract_canonical( $body );
            if ( $canonical && $this->starts_with( $canonical, '/' ) ) {
                $canonical = rtrim( $home, '/' ) . $canonical;
            }
            $normalized_canonical = $this->normalize_url( $canonical, $host );
            if ( $normalized_canonical ) {
                $canonical = $normalized_canonical;
            }
            $h1          = $this->extract_h1( $body );
            $word_count  = str_word_count( wp_strip_all_tags( $body ) );

            $results[] = [
                'url'                  => $normalized,
                'title'                => $title,
                'has_title'            => ! empty( $title ),
                'has_meta_description' => ! empty( $meta_desc ),
                'word_count'           => $word_count,
                'status'               => $code,
                'canonical'            => $canonical,
                'noindex'              => $this->is_noindex( $meta_robots ),
                'has_h1'               => ! empty( $h1 ),
                'meta_robots'          => $meta_robots,
            ];

            foreach ( $this->extract_links( $body, $home, $host ) as $link ) {
                $normalized_link = $this->normalize_url( $link, $host );
                if ( $normalized_link && ! isset( $seen[ $normalized_link ] ) && ( count( $queue ) + count( $results ) ) < $limit ) {
                    $queue[] = $normalized_link;
                }
            }
        }

        return $results;
    }

    private function seed_queue( string $home, string $host, int $limit ): array {
        $candidates = [
            rtrim( $home, '/' ) . '/sitemap_index.xml',
            rtrim( $home, '/' ) . '/sitemap.xml',
            rtrim( $home, '/' ) . '/wp-sitemap.xml',
        ];

        $urls = [];
        foreach ( $candidates as $sitemap_url ) {
            $response = wp_remote_get( $sitemap_url, [ 'timeout' => 10 ] );
            if ( is_wp_error( $response ) ) {
                continue;
            }

            $code = (int) wp_remote_retrieve_response_code( $response );
            $body = (string) wp_remote_retrieve_body( $response );
            if ( $code >= 300 || empty( $body ) ) {
                continue;
            }

            $xml = simplexml_load_string( $body );
            if ( false === $xml ) {
                continue;
            }

            $locs = [];
            if ( isset( $xml->sitemap ) ) {
                foreach ( $xml->sitemap as $sitemap ) {
                    if ( isset( $sitemap->loc ) ) {
                        $locs[] = (string) $sitemap->loc;
                    }
                }
            }
            foreach ( $xml->url ?? [] as $url_node ) {
                if ( isset( $url_node->loc ) ) {
                    $locs[] = (string) $url_node->loc;
                }
            }

            foreach ( $locs as $loc ) {
                $normalized = $this->normalize_url( $loc, $host );
                if ( $normalized ) {
                    $urls[] = $normalized;
                }
            }
        }

        $urls[] = $home;
        $unique = array_values( array_unique( $urls ) );
        return array_slice( $unique, 0, $limit );
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

    private function extract_meta_robots( string $html ): string {
        if ( preg_match( '/<meta\s+name="robots"\s+content="([^"]*)"[^>]*>/i', $html, $m ) ) {
            return strtolower( trim( wp_strip_all_tags( $m[1] ) ) );
        }
        return '';
    }

    private function extract_canonical( string $html ): string {
        if ( preg_match( '/<link\s+rel="canonical"\s+href="([^"]+)"[^>]*>/i', $html, $m ) ) {
            return trim( $m[1] );
        }
        return '';
    }

    private function extract_h1( string $html ): string {
        if ( preg_match( '/<h1[^>]*>(.*?)<\/h1>/is', $html, $m ) ) {
            return wp_strip_all_tags( $m[1] );
        }
        return '';
    }

    private function extract_links( string $html, string $home, string $host ): array {
        $links = [];
        if ( preg_match_all( '/<a\s+[^>]*href="([^"]+)"/i', $html, $m ) ) {
            foreach ( $m[1] as $href ) {
                if ( empty( $href ) || $this->starts_with( $href, '#' ) || $this->starts_with( $href, 'mailto:' ) || $this->starts_with( $href, 'tel:' ) ) {
                    continue;
                }

                // Resolve relative links to absolute using home.
                if ( $this->starts_with( $href, '/' ) ) {
                    $href = rtrim( $home, '/' ) . $href;
                }

                $parsed = wp_parse_url( $href );
                if ( empty( $parsed['host'] ) || $parsed['host'] !== $host ) {
                    continue;
                }

                $links[] = $href;
            }
        }

        return array_values( array_unique( $links ) );
    }

    private function normalize_url( string $url, string $host = '' ): string {
        $parsed = wp_parse_url( $url );
        if ( empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
            return '';
        }
        if ( $host && $parsed['host'] !== $host ) {
            return '';
        }

        $path  = isset( $parsed['path'] ) ? '/' . ltrim( $parsed['path'], '/' ) : '/';
        $path  = preg_replace( '/\/+/', '/', $path );
        $path  = '/' === $path ? '/' : untrailingslashit( $path );
        $query = isset( $parsed['query'] ) ? '?' . $parsed['query'] : '';

        return $parsed['scheme'] . '://' . $parsed['host'] . $path . $query;
    }

    private function is_noindex( string $meta_robots ): bool {
        if ( empty( $meta_robots ) ) {
            return false;
        }
        return false !== stripos( $meta_robots, 'noindex' );
    }

    private function starts_with( string $haystack, string $needle ): bool {
        return 0 === strpos( $haystack, $needle );
    }
}
