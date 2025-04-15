<?php
class SPT_Core {
    public function __construct() {
        add_action('init', array($this, 'init'));
        add_action('admin_init', array($this, 'register_settings'));
    }

    public function init() {
        load_plugin_textdomain('super-ai-polylang-translator', false, dirname(plugin_basename(__FILE__)) . '/languages');
    }

    public function register_settings() {
        register_setting('spt_settings', 'spt_openai_api_key');
        register_setting('spt_settings', 'spt_translation_model', array(
            'default' => 'gpt-4o'
        ));
        register_setting('spt_settings', 'spt_auto_translate', array(
            'type' => 'boolean',
            'default' => false
        ));
    }
}