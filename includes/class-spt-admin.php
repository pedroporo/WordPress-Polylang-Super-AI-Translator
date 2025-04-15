<?php
class SPT_Admin {
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('add_meta_boxes', array($this, 'add_translation_metabox'));
        add_action('wp_ajax_spt_translate_post', array($this, 'handle_translation_request'));
    }

    public function add_admin_menu() {
        add_options_page(
            __('AI Translator Settings', 'super-ai-polylang-translator'),
            __('AI Translator', 'super-ai-polylang-translator'),
            'manage_options',
            'super-ai-polylang-translator',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_admin_scripts($hook) {
        // Load on settings page and post edit screens
        if (!in_array($hook, array('settings_page_super-ai-polylang-translator', 'post.php', 'post-new.php'))) {
            return;
        }

        wp_enqueue_style(
            'spt-admin-css',
            SPT_PLUGIN_URL . 'assets/css/admin.css',
            array(),
            SPT_VERSION
        );

        wp_enqueue_script(
            'spt-admin-js',
            SPT_PLUGIN_URL . 'assets/js/admin.js',
            array('jquery'),
            SPT_VERSION,
            true
        );
        
        wp_localize_script('spt-admin-js', 'sptData', array(
            'nonce' => wp_create_nonce('spt_translate_nonce')
        ));
    }

    public function render_settings_page() {
        require_once SPT_PLUGIN_DIR . 'templates/admin-settings.php';
    }

    public function add_translation_metabox() {
        $post_types = get_post_types(array('public' => true));
        foreach ($post_types as $post_type) {
            add_meta_box(
                'spt_translation_metabox',
                __('AI Translation', 'super-ai-polylang-translator'),
                array($this, 'render_translation_metabox'),
                $post_type,
                'side',
                'high'
            );
        }
    }

    public function render_translation_metabox($post) {
        require_once SPT_PLUGIN_DIR . 'templates/translation-metabox.php';
    }
    
    public function handle_translation_request() {
        check_ajax_referer('spt_translate_nonce', 'nonce');
        
        if (!current_user_can('edit_posts')) {
            wp_send_json_error(array(
                'message' => 'Permission denied',
                'results' => array()
            ));
            return;
        }
        
        $post_id = intval($_POST['post_id']);
        $target_languages = isset($_POST['target_languages']) ? (array)$_POST['target_languages'] : array();
        $model = isset($_POST['model']) ? sanitize_text_field($_POST['model']) : 'gpt-3.5-turbo';

        if (!in_array($model, array('gpt-3.5-turbo', 'gpt-4o'))) {
            wp_send_json_error(array(
                'message' => 'Invalid model selected',
                'results' => array()
            ));
            return;
        }

        if (count($target_languages) > 5) {
            wp_send_json_error(array(
                'message' => 'Please select 5 or fewer languages at a time',
                'results' => array()
            ));
            return;
        }

        if (!$post_id || empty($target_languages) || !get_post($post_id)) {
            wp_send_json_error(array(
                'message' => 'Invalid request parameters',
                'results' => array()
            ));
            return;
        }

        try {
            $polylang = new SPT_Polylang();
            $result = $polylang->translate_post($post_id, $target_languages, $model);
            
            if (is_wp_error($result)) {
                wp_send_json_error(array(
                    'message' => $result->get_error_message(),
                    'results' => array_fill_keys($target_languages, array(
                        'success' => false,
                        'message' => $result->get_error_message()
                    ))
                ));
                return;
            }

            $response_data = array(
                'message' => 'Translation completed',
                'results' => array()
            );

            $has_success = false;
            foreach ($result as $lang => $translation_result) {
                if (is_wp_error($translation_result)) {
                    $response_data['results'][$lang] = array(
                        'success' => false,
                        'message' => $translation_result->get_error_message()
                    );
                } else if (is_array($translation_result) && 
                          (isset($translation_result['success']) || isset($translation_result['post_id']))) {
                    $has_success = true;
                    $response_data['results'][$lang] = array(
                        'success' => true,
                        'edit_link' => isset($translation_result['edit_link']) ? 
                                     $translation_result['edit_link'] : 
                                     get_edit_post_link($translation_result['post_id'], '')
                    );
                } else {
                    $response_data['results'][$lang] = array(
                        'success' => false,
                        'message' => __('Unexpected translation result format', 'super-ai-polylang-translator')
                    );
                }
            }

            if ($has_success) {
                wp_send_json_success($response_data);
            } else {
                wp_send_json_error(array(
                    'message' => __('No translations were successful', 'super-ai-polylang-translator'),
                    'results' => $response_data['results']
                ));
            }

        } catch (Exception $e) {
            error_log('SPT Translation Error: ' . $e->getMessage());
            wp_send_json_error(array(
                'message' => 'Translation failed: ' . $e->getMessage(),
                'results' => array_fill_keys($target_languages, array(
                    'success' => false,
                    'message' => $e->getMessage()
                ))
            ));
        }
    }
}