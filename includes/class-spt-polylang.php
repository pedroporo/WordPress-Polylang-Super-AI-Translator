<?php
class SPT_Polylang {
    public function __construct() {
        add_action('save_post', array($this, 'maybe_auto_translate'), 10, 3);
    }

    public function get_available_languages() {
        if (!function_exists('pll_languages_list')) {
            return array();
        }
        return pll_languages_list();
    }

    public function maybe_auto_translate($post_id, $post, $update) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!get_option('spt_auto_translate', false)) {
            return;
        }

        $this->translate_post($post_id);
    }

    public function translate_post($post_id, $target_languages = array(), $model = 'gpt-3.5-turbo') {
        $post = get_post($post_id);
        $source_language = pll_get_post_language($post_id);
        $all_translations = pll_get_post_translations($post_id);
        
        if (!$source_language) {
            return new WP_Error('no_source_language', __('Please set a language for this post first', 'super-ai-polylang-translator'));
        }
        
        if (empty($target_languages)) {
            $all_languages = pll_languages_list(array('fields' => 'locale'));
            $target_languages = array_diff($all_languages, array($source_language));
        }

        $openai = new SPT_OpenAI();
        $translation_results = array();

        foreach ($target_languages as $target_language) {
            $target_code = substr($target_language, 0, 2);
            if ($target_language === $source_language || isset($all_translations[$target_code])) {
                continue;
            }

            error_log("Starting translation to: " . $target_language);
            
            // First translate the title
            $translated_title = $openai->translate_text($post->post_title, $target_language, $model);
            if (is_wp_error($translated_title)) {
                error_log("Title translation failed: " . $translated_title->get_error_message());
                $translation_results[$target_language] = $translated_title;
                continue;
            }
            
            // Then translate the content
            $translated_content = $openai->translate_text($post->post_content, $target_language, $model);
            if (is_wp_error($translated_content)) {
                error_log("Content translation failed: " . $translated_content->get_error_message());
                $translation_results[$target_language] = $translated_content;
                continue;
            }

            error_log("Translation completed successfully for: " . $target_language);

            $translated_post = array(
                'post_title' => $translated_title,
                'post_content' => $translated_content,
                'post_status' => 'draft',
                'post_type' => $post->post_type,
                'post_author' => $post->post_author,
            );

            $translated_post_id = wp_insert_post($translated_post);

            if (!is_wp_error($translated_post_id)) {
                pll_set_post_language($translated_post_id, $target_language);
                
                // Merge new translation with existing ones
                $all_translations[substr($source_language, 0, 2)] = $post_id;
                $all_translations[$target_code] = $translated_post_id;
                
                // Save updated translations
                pll_save_post_translations($all_translations);
                
                $translation_results[$target_language] = array(
                    'success' => true,
                    'post_id' => $translated_post_id,
                    'edit_link' => get_edit_post_link($translated_post_id, '')
                );
                error_log("Successfully created translation in: " . $target_language);
            } else {
                error_log("Failed to create translation post in: " . $target_language . " - " . $translated_post_id->get_error_message());
                $translation_results[$target_language] = new WP_Error(
                    'post_creation_failed',
                    sprintf(
                        __('Failed to create translated post for %s', 'super-ai-polylang-translator'),
                        $target_language
                    )
                );
            }
        }
        
        return $translation_results;
    }
}