<?php
/**
 * Registers and runs Versa AI cron events.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Versa_AI_Cron {
    public const SCHEDULE_TEN_MINUTES = 'versa_ai_every_10_minutes';

    public function __construct() {
        add_filter( 'cron_schedules', [ $this, 'add_custom_schedules' ] );

        add_action( 'versa_ai_weekly_planner', [ $this, 'run_weekly_planner' ] );
        add_action( 'versa_ai_daily_writer', [ $this, 'run_daily_writer' ] );
        add_action( 'versa_ai_daily_seo_scan', [ $this, 'run_daily_seo_scan' ] );
        add_action( 'versa_ai_seo_worker', [ $this, 'run_seo_worker' ] );

        // Ensure events are queued whenever plugin loads.
        $this->maybe_schedule_events();
    }

    /**
     * Add custom schedules.
     */
    public function add_custom_schedules( array $schedules ): array {
        if ( ! isset( $schedules[ self::SCHEDULE_TEN_MINUTES ] ) ) {
            $schedules[ self::SCHEDULE_TEN_MINUTES ] = [
                'interval' => 10 * MINUTE_IN_SECONDS,
                'display'  => __( 'Every 10 Minutes (Versa AI)', 'versa-ai-seo-engine' ),
            ];
        }

        return $schedules;
    }

    /**
     * Schedule events if missing.
     */
    public function maybe_schedule_events(): void {
        if ( ! wp_next_scheduled( 'versa_ai_weekly_planner' ) ) {
            wp_schedule_event( time(), 'weekly', 'versa_ai_weekly_planner' );
        }

        if ( ! wp_next_scheduled( 'versa_ai_daily_writer' ) ) {
            wp_schedule_event( time(), 'daily', 'versa_ai_daily_writer' );
        }

        if ( ! wp_next_scheduled( 'versa_ai_daily_seo_scan' ) ) {
            wp_schedule_event( time(), 'daily', 'versa_ai_daily_seo_scan' );
        }

        if ( ! wp_next_scheduled( 'versa_ai_seo_worker' ) ) {
            wp_schedule_event( time(), self::SCHEDULE_TEN_MINUTES, 'versa_ai_seo_worker' );
        }
    }

    /**
     * Planner handler.
     */
    public function run_weekly_planner(): void {
        if ( ! class_exists( 'Versa_AI_Planner' ) ) {
            return;
        }

        $planner = new Versa_AI_Planner();
        $planner->run();
    }

    /**
     * Writer handler.
     */
    public function run_daily_writer(): void {
        if ( ! class_exists( 'Versa_AI_Writer' ) ) {
            return;
        }

        $writer = new Versa_AI_Writer();
        $writer->run();
    }

    /**
     * SEO scanner handler.
     */
    public function run_daily_seo_scan(): void {
        if ( ! class_exists( 'Versa_AI_Optimizer' ) ) {
            return;
        }

        $optimizer = new Versa_AI_Optimizer();
        $optimizer->scan_site();
    }

    /**
     * SEO worker handler.
     */
    public function run_seo_worker(): void {
        if ( ! class_exists( 'Versa_AI_Optimizer' ) ) {
            return;
        }

        $optimizer = new Versa_AI_Optimizer();
        $optimizer->run_worker();
    }
}
