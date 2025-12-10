<?php
/**
 * Admin settings page view.
 *
 * @var array  $profile Current profile settings.
 * @var string $services_text Services as newline separated text.
 * @var string $locations_text Locations as newline separated text.
 *
 * @package VersaAISEOEngine
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="wrap">
    <h1><?php esc_html_e( 'Versa AI Business Profile', 'versa-ai-seo-engine' ); ?></h1>

    <form method="post" action="options.php">
        <?php settings_fields( Versa_AI_Settings_Page::MENU_SLUG ); ?>
        <?php do_settings_sections( Versa_AI_Settings_Page::MENU_SLUG ); ?>

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="versa_ai_business_name"><?php esc_html_e( 'Business Name', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[business_name]" id="versa_ai_business_name" type="text" class="regular-text" value="<?php echo esc_attr( $profile['business_name'] ); ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_services"><?php esc_html_e( 'Services (one per line)', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <textarea name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[services]" id="versa_ai_services" rows="5" class="large-text code"><?php echo esc_textarea( $services_text ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'List primary services or offerings, one per line.', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_locations"><?php esc_html_e( 'Locations (one per line)', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <textarea name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[locations]" id="versa_ai_locations" rows="4" class="large-text code"><?php echo esc_textarea( $locations_text ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Cities or service areas, one per line.', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_target_audience"><?php esc_html_e( 'Target Audience', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[target_audience]" id="versa_ai_target_audience" type="text" class="regular-text" value="<?php echo esc_attr( $profile['target_audience'] ); ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_tone_of_voice"><?php esc_html_e( 'Tone of Voice', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <textarea name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[tone_of_voice]" id="versa_ai_tone_of_voice" rows="3" class="large-text code"><?php echo esc_textarea( $profile['tone_of_voice'] ); ?></textarea>
                        <p class="description"><?php esc_html_e( 'Describe the desired writing tone (e.g., friendly, expert, concise).', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_posts_per_week"><?php esc_html_e( 'Weekly Post Frequency', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[posts_per_week]" id="versa_ai_posts_per_week" type="number" min="0" max="7" value="<?php echo esc_attr( $profile['posts_per_week'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'How many new posts to plan per week (0-7).', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_max_words"><?php esc_html_e( 'Max Words Per Post', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[max_words_per_post]" id="versa_ai_max_words" type="number" min="300" max="5000" value="<?php echo esc_attr( $profile['max_words_per_post'] ); ?>" />
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Auto Publish Posts', 'versa-ai-seo-engine' ); ?></th>
                    <td>
                        <label>
                            <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[auto_publish_posts]" type="checkbox" value="1" <?php checked( $profile['auto_publish_posts'], true ); ?> />
                            <?php esc_html_e( 'Publish immediately after writing (otherwise leave as draft).', 'versa-ai-seo-engine' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Require Approval for AI Edits', 'versa-ai-seo-engine' ); ?></th>
                    <td>
                        <label>
                            <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[require_task_approval]" type="checkbox" value="1" <?php checked( $profile['require_task_approval'], true ); ?> />
                            <?php esc_html_e( 'Hold AI tasks until you approve them in the Tasks screen.', 'versa-ai-seo-engine' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Require Apply After AI Edits', 'versa-ai-seo-engine' ); ?></th>
                    <td>
                        <label>
                            <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[require_apply_after_edits]" type="checkbox" value="1" <?php checked( ! empty( $profile['require_apply_after_edits'] ), true ); ?> />
                            <?php esc_html_e( 'Store AI changes for review; apply or discard them manually in the Tasks screen.', 'versa-ai-seo-engine' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><?php esc_html_e( 'Enable Debug Logging', 'versa-ai-seo-engine' ); ?></th>
                    <td>
                        <label>
                            <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[enable_debug_logging]" type="checkbox" value="1" <?php checked( ! empty( $profile['enable_debug_logging'] ), true ); ?> />
                            <?php esc_html_e( 'Capture plugin events in the Versa AI log table for troubleshooting.', 'versa-ai-seo-engine' ); ?>
                        </label>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_openai_api_key"><?php esc_html_e( 'OpenAI API Key', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[openai_api_key]" id="versa_ai_openai_api_key" type="password" class="regular-text" value="" autocomplete="new-password" placeholder="&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;&#8226;" />
                        <p class="description"><?php esc_html_e( 'Enter a new key to replace. Leave blank to keep the existing key. Stored in your WordPress database.', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_openai_model"><?php esc_html_e( 'OpenAI Model', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[openai_model]" id="versa_ai_openai_model" type="text" class="regular-text" value="<?php echo esc_attr( $profile['openai_model'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Example: gpt-4.1-mini. Ensure the model is available to your API key.', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_crawl_limit"><?php esc_html_e( 'Site Crawl Limit', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[crawl_limit]" id="versa_ai_crawl_limit" type="number" min="0" value="<?php echo esc_attr( $profile['crawl_limit'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Maximum pages per site crawl. Set 0 for no limit (may be heavy on large sites).', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>

                <tr>
                    <th scope="row"><label for="versa_ai_crawl_cooldown"><?php esc_html_e( 'Crawl Cooldown (hours)', 'versa-ai-seo-engine' ); ?></label></th>
                    <td>
                        <input name="<?php echo esc_attr( Versa_AI_Settings_Page::OPTION_KEY ); ?>[crawl_cooldown_hours]" id="versa_ai_crawl_cooldown" type="number" min="1" max="24" value="<?php echo esc_attr( $profile['crawl_cooldown_hours'] ); ?>" />
                        <p class="description"><?php esc_html_e( 'Minimum hours between crawls. Lower values re-crawl more often; higher reduces load.', 'versa-ai-seo-engine' ); ?></p>
                    </td>
                </tr>
            </tbody>
        </table>

        <?php submit_button( __( 'Save Profile', 'versa-ai-seo-engine' ) ); ?>
    </form>
</div>
