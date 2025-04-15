<?php
class SPT_OpenAI {
    private $api_key;
    private $api_url = 'https://api.openai.com/v1/chat/completions';
    private $max_retries = 3;
    private $timeout = 60;
    private $retry_delay = 2;
    
    // Rate limits per minute
    private $rate_limits = array(
        'gpt-3.5-turbo' => 3500,
        'gpt-4o' => 200
    );
    
    // Token limits with safety margins
    private $max_tokens_map = array(
        'gpt-3.5-turbo' => array(
            'input' => 2500,    // Reduced for safety
            'output' => 4096,   // Reserved for response
            'total' => 4096     // Total context window
        ),
        'gpt-4o' => array(
            'input' => 5000,    // Reduced for safety
            'output' => 3000,   // Reserved for response
            'total' => 8192     // Total context window
        )
    );

    private $chunk_size = 4096; // Reduced chunk size for better reliability

    public function __construct() {
        $this->api_key = get_option('spt_openai_api_key');
    }

    public function translate_text($text, $target_language, $model = null,$is_chunk=false) {
        if (empty($text)) {
            return new WP_Error('empty_text', __('No text provided for translation.', 'super-ai-polylang-translator'));
        }

        if (empty($this->api_key)) {
            error_log('SPT OpenAI Error: API key no existe');
            return new WP_Error('missing_api_key', __('OpenAI API key no esta configurada.', 'super-ai-polylang-translator'));
        }

        // Use provided model or default to gpt-3.5-turbo
        $model = $model ?: 'gpt-3.5-turbo';

        try {
            // Respect rate limits
            $this->respect_rate_limit($model);

            // Calculate text length and decide if chunking is needed
            $text_length = mb_strlen($text);
            
            error_log(sprintf('SPT OpenAI: Procesando traduccion. Length: %d, Model: %s', 
                $text_length,
                $model
            ));

            if ($text_length > $this->chunk_size && !$is_chunk) {
                return $this->translate_long_text($text, $target_language, $model);
            }
            /*if ($is_chunk) {
                error_log("✅ Traducción recibida para el chunk: " . $this->truncate_for_log($result));
            }*/
            $target_name = $this->get_language_name($target_language);
            return $this->make_translation_request($text, $target_name, $model);

        } catch (Exception $e) {
            error_log('SPT OpenAI Error: ' . $e->getMessage());
            return new WP_Error('translation_error', $e->getMessage());
        }
    }

    private function make_translation_request($text, $target_name, $model) {
        $attempt = 0;
        
        do {
            if ($attempt > 0) {
                $delay = $this->retry_delay * pow(2, $attempt - 1); // Exponential backoff
                error_log(sprintf('SPT OpenAI: Retry attempt %d, waiting %d seconds', $attempt + 1, $delay));
                sleep($delay);
            }

            try {
                $response = wp_remote_post($this->api_url, array(
                    'timeout' => $this->timeout,
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $this->api_key,
                        'Content-Type' => 'application/json',
                    ),
                    'body' => json_encode(array(
                        'model' => $model,
                        'messages' => array(
                            array(
                                'role' => 'system',
                                'content' => "Eres traductor profesional. Traduce el siguiente texto a $target_name. Mantén el formato, la estructura y las etiquetas HTML originales, si las hay."
                            ),
                            array(
                                'role' => 'user',
                                'content' => $text
                            )
                        ),
                        'temperature' => 0.3,
                        'max_tokens' => $this->max_tokens_map[$model]['output']
                    ))
                ));

                if (is_wp_error($response)) {
                    throw new Exception($response->get_error_message());
                }

                $response_code = wp_remote_retrieve_response_code($response);
                $body = json_decode(wp_remote_retrieve_body($response), true);
                error_log('SPT OpenAI Raw Response: ' . print_r($body, true));

                if ($response_code === 200 && !empty($body['choices'][0]['message']['content'])) {
                    error_log('SPT OpenAI: Se ha traducido correctamente');
                    $clean_text = $this->remove_lang_spans($body['choices'][0]['message']['content']);
                    return $clean_text;
                }

                if ($response_code === 429) {
                    throw new Exception('Rate limit exceeded');
                }

                throw new Exception(sprintf('Unexpected response (code: %d): %s', 
                    $response_code, 
                    wp_remote_retrieve_body($response)
                ));

            } catch (Exception $e) {
                error_log('SPT OpenAI Error: ' . $e->getMessage());
                if ($attempt >= $this->max_retries - 1) {
                    throw $e;
                }
            }

            $attempt++;
        } while ($attempt < $this->max_retries);

        throw new Exception('Translation failed after multiple attempts');
    }

    private function translate_long_text($text, $target_language, $model) {
        $chunks = $this->smart_text_split($text);
        $this->debug_log_chunks($chunks, 'Antes de traducir');
        $translated_chunks = array();
        
        error_log(sprintf('SPT OpenAI: Starting chunked translation with %d chunks', count($chunks)));
        
        foreach ($chunks as $index => $chunk) {
            error_log(sprintf('SPT OpenAI: Translating chunk %d of %d (length: %d)', 
                $index + 1, 
                count($chunks),
                mb_strlen($chunk)
            ));
            //error_log("SPT OpenAI: Verificando longitud del chunk antes de enviar a translate_text(): " . mb_strlen($chunk));
            $result = $this->translate_text($chunk, $target_language, $model,true);
            if (is_wp_error($result)) {
                return $result;
            }
            if (empty($result) || trim($result) === '') {
                error_log("SPT OpenAI: Chunk $index devolvió una respuesta vacía.");
                return new WP_Error('empty_response', "Chunk $index no se pudo traducir correctamente.");
            }
            
            $translated_chunks[] = $result;
            
            // Add delay between chunks to respect rate limits
            if ($index < count($chunks) - 1) {
                sleep(3); // Increased delay between chunks
            }
        }

        return implode("\n\n", $translated_chunks);
    }
    private function remove_lang_spans($text) {
        return preg_replace('/<span lang="[^"]+">(.+?)<\/span>/is', '$1', $text);
    }
    
    private function smart_text_split($text) {
        if (stripos($text, '</p>') !== false) {
            $paragraphs = preg_split('/(<\/div>|<\/p>|<br\s*\/?>|\n\s*\n)/i', $text, -1, PREG_SPLIT_NO_EMPTY);
        } else {
            $paragraphs = preg_split('/\n\s*\n/', $text);
        }
        $chunks = array();
        //$paragraphs = preg_split('/\n\s*\n/', $text);
        $current_chunk = '';
        
        /*foreach ($paragraphs as $paragraph) {
            if (mb_strlen($current_chunk . "\n\n" . $paragraph) > $this->chunk_size) {
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                }
                $current_chunk = $paragraph;
            } else {
                $current_chunk .= (!empty($current_chunk) ? "\n\n" : '') . $paragraph;
            }
        }*/
        foreach ($paragraphs as $paragraph) {
            $paragraph = trim($paragraph);
            if (empty($paragraph)) continue;
            $paragraph .= '</div>';
            if (mb_strlen($current_chunk . $paragraph) > $this->chunk_size) {
                if (!empty($current_chunk)) {
                    $chunks[] = $current_chunk;
                }
                $current_chunk = $paragraph;
            } else {
                $current_chunk .= $paragraph;
            }
        }
    
        if (!empty($current_chunk)) {
            $chunks[] = $current_chunk;
        }
        
        return $chunks;
    }
    private function debug_log_chunks($chunks, $context = 'Default') {
        error_log("🔍 SPT OpenAI - DEBUG DE CHUNKS ($context)");
        foreach ($chunks as $index => $chunk) {
            $length = mb_strlen($chunk);
            error_log("🧩 Chunk #$index (Longitud: $length):\n" . $this->truncate_for_log($chunk));
        }
    }
    private function truncate_for_log($text, $max_length = 1000) {
        if (mb_strlen($text) <= $max_length) {
            return $text;
        }
        return mb_substr($text, 0, $max_length) . "\n[...contenido truncado...]";
    }
    
    private function get_language_name($language_code) {
        $language_map = array(
            'en' => 'English',
            'es' => 'Spanish',
            'pt' => 'Portuguese',
            'fr' => 'French',
            'de' => 'German',
            'it' => 'Italian'
        );
        
        $code = substr($language_code, 0, 2);
        return isset($language_map[$code]) ? $language_map[$code] : $language_code;
    }

    private function respect_rate_limit($model) {
        static $last_request = array();
        static $request_count = array();
        
        $now = microtime(true);
        $minute_ago = $now - 60;
        
        if (!isset($last_request[$model])) {
            $last_request[$model] = array();
            $request_count[$model] = 0;
        }
        
        // Clean up old requests
        $last_request[$model] = array_filter($last_request[$model], function($time) use ($minute_ago) {
            return $time > $minute_ago;
        });
        
        // Check if we're at the limit
        if (count($last_request[$model]) >= $this->rate_limits[$model]) {
            $sleep_time = 60 - ($now - min($last_request[$model]));
            if ($sleep_time > 0) {
                sleep(ceil($sleep_time));
            }
        }
        
        // Add current request
        $last_request[$model][] = $now;
    }
}