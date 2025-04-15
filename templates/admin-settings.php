<?php
if (!defined('ABSPATH')) {
    exit;
}
?>

<div class="wrap">
    <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

    <form method="post" action="options.php">
        <?php
        settings_fields('spt_settings');
        do_settings_sections('spt_settings');
        ?>

        <table class="form-table">
            <tr>
                <th scope="row">
                    <label for="spt_openai_api_key"><?php _e('OpenAI API Key', 'super-ai-polylang-translator'); ?></label>
                </th>
                <td>
                    <input type="password" 
                           id="spt_openai_api_key" 
                           name="spt_openai_api_key" 
                           value="<?php echo esc_attr(get_option('spt_openai_api_key')); ?>" 
                           class="regular-text">
                    <p class="description">
                        <?php _e('Pon tu OpenAI API key. Puedes obtenerla desde OpenAI dashboard.', 'super-ai-polylang-translator'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <label for="spt_translation_model"><?php _e('AI Model', 'super-ai-polylang-translator'); ?></label>
                </th>
                <td>
                    <select id="spt_translation_model" name="spt_translation_model">
                        <option value="gpt-4o" <?php selected(get_option('spt_translation_model'), 'gpt-4o'); ?>>
                            gpt-4o (<?php _e('Recommended', 'super-ai-polylang-translator'); ?>)
                        </option>
                        <option value="gpt-3.5-turbo" <?php selected(get_option('spt_translation_model'), 'gpt-3.5-turbo'); ?>>
                            GPT-3.5 Turbo
                        </option>
                    </select>
                    <p class="description">
                        <?php _e('Select the AI model to use for translations.', 'super-ai-polylang-translator'); ?>
                    </p>
                </td>
            </tr>

            <tr>
                <th scope="row">
                    <?php _e('Auto Translation', 'super-ai-polylang-translator'); ?>
                </th>
                <td>
                    <label for="spt_auto_translate">
                        <input type="checkbox" 
                               id="spt_auto_translate" 
                               name="spt_auto_translate" 
                               value="1" 
                               <?php checked(get_option('spt_auto_translate'), 1); ?>>
                        <?php _e('Automatically translate posts when published', 'super-ai-polylang-translator'); ?>
                    </label>
                </td>
            </tr>
        </table>

        <?php submit_button(); ?>
    </form>
</div>