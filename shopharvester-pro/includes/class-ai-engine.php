<?php
/**
 * Configurable LLM client for SEO/style enrichment.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Talks to OpenAI, GapGPT, Gemini or Perplexity and returns a strict JSON
 * enrichment payload. All providers share one prompt contract.
 */
class AI_Engine {

    /**
     * Enrich a single product's facts.
     *
     * @param array $facts Normalized product facts.
     * @return array|null Enrichment array, or null when AI is disabled/failed.
     */
    public function enrich( array $facts ) {
        if ( ! Settings::get( 'ai_enabled' ) ) {
            return null;
        }

        $provider = Settings::get( 'ai_provider' );

        // AI is enabled but the selected provider has no API key. This is a
        // configuration gap, not a runtime failure: skip enrichment quietly and
        // keep the raw scraped data. Warn only once per hour so the log is not
        // flooded with one entry per imported product.
        if ( ! $this->has_credentials( $provider ) ) {
            if ( ! get_transient( 'shp_ai_no_key_warned' ) ) {
                set_transient( 'shp_ai_no_key_warned', 1, HOUR_IN_SECONDS );
                Logger::warning(
                    sprintf(
                        /* translators: %s: AI provider key. */
                        __( 'هوش مصنوعی فعال است اما کلید API ارائه‌دهنده‌ی «%s» تنظیم نشده؛ محصولات با داده‌ی خام ذخیره می‌شوند. برای غنی‌سازی، کلید را در تنظیمات وارد کنید یا هوش مصنوعی را خاموش کنید.', 'shopharvester-pro' ),
                        (string) $provider
                    ),
                    array(),
                    array( 'channel' => 'ai' )
                );
            }
            return null;
        }

        $prompt = $this->build_prompt( $facts );

        try {
            switch ( $provider ) {
                case 'gapgpt':
                    $raw = $this->call_gapgpt( $prompt );
                    break;
                case 'gemini':
                    $raw = $this->call_gemini( $prompt );
                    break;
                case 'perplexity':
                    $raw = $this->call_perplexity( $prompt );
                    break;
                case 'openai':
                default:
                    $raw = $this->call_openai( $prompt );
                    break;
            }
        } catch ( \Throwable $e ) {
            Logger::exception( $e, array( 'channel' => 'ai' ) );
            return null;
        }

        $data = $this->decode_json( $raw );
        if ( null === $data ) {
            Logger::warning(
                __( 'پاسخ هوش مصنوعی قابل تجزیه به JSON نبود؛ از داده‌های خام استفاده شد.', 'shopharvester-pro' ),
                array( 'raw' => mb_substr( (string) $raw, 0, 800 ) ),
                array( 'channel' => 'ai' )
            );
            return null;
        }

        return $this->normalize_output( $data, $facts );
    }

    /**
     * Live connectivity test for the configured (or overridden) provider.
     *
     * @return array { ok: bool, message: string, raw: string }
     */
    public function test() {
        $provider = Settings::get( 'ai_provider' );
        $prompt   = 'Reply with exactly this JSON and nothing else: {"ok":true}';

        try {
            switch ( $provider ) {
                case 'gapgpt':
                    $raw = $this->call_gapgpt( $prompt );
                    break;
                case 'gemini':
                    $raw = $this->call_gemini( $prompt );
                    break;
                case 'perplexity':
                    $raw = $this->call_perplexity( $prompt );
                    break;
                default:
                    $raw = $this->call_openai( $prompt );
                    break;
            }
        } catch ( \Throwable $e ) {
            return array(
                'ok'      => false,
                'message' => sprintf( __( 'اتصال ناموفق: %s', 'shopharvester-pro' ), $e->getMessage() ),
                'raw'     => '',
            );
        }

        $data = $this->decode_json( $raw );

        return array(
            'ok'      => is_array( $data ),
            'message' => is_array( $data )
                ? sprintf( __( 'اتصال موفق بود ✓  (ارائه‌دهنده: %1$s، مدل: %2$s)', 'shopharvester-pro' ), $provider, Settings::get( 'ai_model' ) )
                : __( 'پاسخ دریافت شد ولی JSON معتبر نبود؛ اتصال برقرار است اما مدل خروجی درست نمی‌دهد.', 'shopharvester-pro' ),
            'raw'     => mb_substr( (string) $raw, 0, 300 ),
        );
    }

    /**
     * Render the prompt template with the product facts.
     *
     * @param array $facts Product facts.
     * @return string
     */
    public function build_prompt( array $facts ) {
        $template = (string) Settings::get( 'ai_prompt_template' );

        $vars = array(
            '{{language}}'        => Settings::get( 'ai_language', 'fa' ),
            '{{title}}'           => $facts['title'] ?? '',
            '{{brand}}'           => $facts['brand'] ?? '',
            '{{price}}'           => $facts['price'] ?? '',
            '{{categories}}'      => implode( '، ', (array) ( $facts['categories'] ?? array() ) ),
            '{{attributes}}'      => $this->flatten_attributes( $facts['attributes'] ?? array() ),
            '{{raw_description}}' => wp_strip_all_tags( (string) ( $facts['description'] ?? '' ) ),
            '{{store_name}}'      => $facts['store_name'] ?? '',
        );

        /**
         * Allow integrators to register extra template variables.
         *
         * @param array $vars  Token => value map.
         * @param array $facts Product facts.
         */
        $vars = apply_filters( 'shp_ai_prompt_vars', $vars, $facts );

        return strtr( $template, $vars );
    }

    /**
     * Whether the given provider has an API key configured.
     *
     * @param string $provider Provider slug.
     * @return bool
     */
    public function has_credentials( $provider ) {
        switch ( $provider ) {
            case 'gapgpt':
                return '' !== trim( (string) Settings::get( 'gapgpt_api_key' ) );
            case 'gemini':
                return '' !== trim( (string) Settings::get( 'gemini_api_key' ) );
            case 'perplexity':
                return '' !== trim( (string) Settings::get( 'perplexity_api_key' ) );
            case 'openai':
            default:
                return '' !== trim( (string) Settings::get( 'openai_api_key' ) );
        }
    }

    /* --------------------------------------------------------------------- *
     *  Provider adapters.
     * --------------------------------------------------------------------- */

    /**
     * @param string $prompt Prompt text.
     * @return string Model text output.
     * @throws \RuntimeException On transport / API error.
     */
    private function call_openai( $prompt ) {
        $key = Settings::get( 'openai_api_key' );
        if ( ! $key ) {
            throw new \RuntimeException( 'openai_api_key_missing' );
        }

        $body = array(
            'model'           => Settings::get( 'ai_model', 'gpt-4o-mini' ),
            'temperature'     => 0.3,
            'response_format' => array( 'type' => 'json_object' ),
            'messages'        => array(
                array( 'role' => 'system', 'content' => 'You return only valid minified JSON. No prose.' ),
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        );

        $response = $this->request(
            'https://api.openai.com/v1/chat/completions',
            array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            $body
        );

        return (string) ( $response['choices'][0]['message']['content'] ?? '' );
    }

    /**
     * GapGPT — an OpenAI-compatible gateway (proxies GPT / Claude / Gemini).
     * Uses the same Chat Completions contract as OpenAI with a custom base URL.
     *
     * @param string $prompt Prompt text.
     * @return string
     * @throws \RuntimeException On error.
     */
    private function call_gapgpt( $prompt ) {
        $key = Settings::get( 'gapgpt_api_key' );
        if ( ! $key ) {
            throw new \RuntimeException( 'gapgpt_api_key_missing' );
        }

        $base  = rtrim( (string) Settings::get( 'gapgpt_base_url', 'https://api.gapgpt.app/v1' ), '/' );
        $model = (string) Settings::get( 'ai_model', 'gpt-4o-mini' );

        $body = array(
            'model'       => $model,
            'temperature' => 0.3,
            'messages'    => array(
                array( 'role' => 'system', 'content' => 'You return only valid minified JSON. No prose, no markdown fences.' ),
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        );

        // Native JSON mode is reliable only for OpenAI-family models.
        if ( preg_match( '/^(gpt-|o1|o3|o4|chatgpt)/i', $model ) ) {
            $body['response_format'] = array( 'type' => 'json_object' );
        }

        $response = $this->request(
            $base . '/chat/completions',
            array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            $body
        );

        return (string) ( $response['choices'][0]['message']['content'] ?? '' );
    }

    /**
     * @param string $prompt Prompt text.
     * @return string
     * @throws \RuntimeException On error.
     */
    private function call_gemini( $prompt ) {
        $key = Settings::get( 'gemini_api_key' );
        if ( ! $key ) {
            throw new \RuntimeException( 'gemini_api_key_missing' );
        }

        $model = Settings::get( 'ai_model', 'gemini-1.5-flash' );
        $url   = sprintf( 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent?key=%s', rawurlencode( $model ), rawurlencode( $key ) );

        $body = array(
            'contents'         => array(
                array( 'parts' => array( array( 'text' => $prompt ) ) ),
            ),
            'generationConfig' => array(
                'temperature'      => 0.3,
                'responseMimeType' => 'application/json',
            ),
        );

        $response = $this->request( $url, array( 'Content-Type' => 'application/json' ), $body );

        return (string) ( $response['candidates'][0]['content']['parts'][0]['text'] ?? '' );
    }

    /**
     * @param string $prompt Prompt text.
     * @return string
     * @throws \RuntimeException On error.
     */
    private function call_perplexity( $prompt ) {
        $key = Settings::get( 'perplexity_api_key' );
        if ( ! $key ) {
            throw new \RuntimeException( 'perplexity_api_key_missing' );
        }

        $body = array(
            'model'       => Settings::get( 'ai_model', 'sonar' ),
            'temperature' => 0.3,
            'messages'    => array(
                array( 'role' => 'system', 'content' => 'Return only valid minified JSON. No prose, no markdown fences.' ),
                array( 'role' => 'user', 'content' => $prompt ),
            ),
        );

        $response = $this->request(
            'https://api.perplexity.ai/chat/completions',
            array(
                'Authorization' => 'Bearer ' . $key,
                'Content-Type'  => 'application/json',
            ),
            $body
        );

        return (string) ( $response['choices'][0]['message']['content'] ?? '' );
    }

    /* --------------------------------------------------------------------- *
     *  HTTP + parsing helpers.
     * --------------------------------------------------------------------- */

    /**
     * Perform a JSON POST and decode the response.
     *
     * @param string $url     Endpoint.
     * @param array  $headers Request headers.
     * @param array  $body    Request body (encoded to JSON here).
     * @return array Decoded response body.
     * @throws \RuntimeException On any failure.
     */
    private function request( $url, array $headers, array $body ) {
        $args = array(
            'timeout' => (int) Settings::get( 'ai_timeout', 45 ),
            'headers' => $headers,
            'body'    => wp_json_encode( $body ),
        );

        // Retry transient transport failures (connection reset / timeout) that
        // gateways like GapGPT intermittently return.
        $res      = null;
        $attempts = 0;
        do {
            $attempts++;
            $res = wp_remote_post( $url, $args );
            if ( ! is_wp_error( $res ) ) {
                break;
            }
            $msg = $res->get_error_message();
            $transient = preg_match( '/(cURL error (7|28|35|52|55|56)|timed out|Connection (reset|closed|refused)|Empty reply|Recv failure|SSL connection)/i', $msg );
            if ( ! $transient || $attempts >= 3 ) {
                break;
            }
            sleep( $attempts );
        } while ( $attempts < 3 );

        if ( is_wp_error( $res ) ) {
            throw new \RuntimeException( 'http_error: ' . $res->get_error_message() );
        }

        $code = (int) wp_remote_retrieve_response_code( $res );
        $raw  = wp_remote_retrieve_body( $res );

        if ( $code < 200 || $code >= 300 ) {
            throw new \RuntimeException( sprintf( 'api_status_%d: %s', $code, mb_substr( $raw, 0, 500 ) ) );
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            throw new \RuntimeException( 'invalid_api_envelope' );
        }

        return $decoded;
    }

    /**
     * Decode a model response that may be wrapped in code fences.
     *
     * @param string $raw Model text.
     * @return array|null
     */
    private function decode_json( $raw ) {
        $raw = trim( (string) $raw );
        if ( '' === $raw ) {
            return null;
        }

        $raw = preg_replace( '/^```(?:json)?|```$/m', '', $raw );
        $raw = trim( $raw );

        $data = json_decode( $raw, true );
        if ( is_array( $data ) ) {
            return $data;
        }

        // Fall back to the first {...} block.
        if ( preg_match( '/\{.*\}/s', $raw, $m ) ) {
            $data = json_decode( $m[0], true );
            if ( is_array( $data ) ) {
                return $data;
            }
        }

        return null;
    }

    /**
     * Coerce the model output into our fixed schema.
     *
     * @param array $data  Raw decoded model output.
     * @param array $facts Original facts (used as fallbacks).
     * @return array
     */
    private function normalize_output( array $data, array $facts ) {
        $slug_source = $data['url_slug'] ?? ( $data['seo_title'] ?? ( $facts['title'] ?? '' ) );

        return array(
            'seo_title'             => sanitize_text_field( $data['seo_title'] ?? ( $facts['title'] ?? '' ) ),
            'short_description'      => wp_kses_post( $data['short_description'] ?? '' ),
            'long_description'       => wp_kses_post( $data['long_description'] ?? ( $facts['description'] ?? '' ) ),
            'yoast_title'            => sanitize_text_field( $data['yoast_title'] ?? ( $data['seo_title'] ?? '' ) ),
            'yoast_metadesc'         => sanitize_text_field( $data['yoast_metadesc'] ?? ( $data['short_description'] ?? '' ) ),
            'yoast_focus_keyphrase'  => sanitize_text_field( $data['yoast_focus_keyphrase'] ?? '' ),
            'url_slug'               => sanitize_title( $slug_source ),
            'tags'                   => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['tags'] ?? array() ) ) ) ),
            'image_alt_texts'        => array_values( array_filter( array_map( 'sanitize_text_field', (array) ( $data['image_alt_texts'] ?? array() ) ) ) ),
        );
    }

    /**
     * @param array $attributes Attribute map.
     * @return string
     */
    private function flatten_attributes( array $attributes ) {
        $parts = array();
        foreach ( $attributes as $key => $value ) {
            $value   = is_array( $value ) ? implode( '/', $value ) : $value;
            $parts[] = $key . ': ' . $value;
        }
        return implode( '، ', $parts );
    }
}
