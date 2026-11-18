<?php
/**
 * Remote image ingestion into the WordPress Media Library.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Downloads remote product images, sideloads them as attachments,
 * assigns alt texts and sets the featured image.
 */
class Media_Handler {

    /**
     * Import a list of image URLs and attach them to a product.
     *
     * @param int    $product_id Target product post ID.
     * @param array  $urls       Ordered image URLs (first == featured).
     * @param array  $alt_texts  Optional parallel array of alt texts.
     * @return int[] Attachment IDs in import order.
     */
    public function import_for_product( $product_id, array $urls, array $alt_texts = array() ) {
        if ( ! Settings::get( 'download_images' ) ) {
            return array();
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $urls  = array_values( array_unique( array_filter( array_map(
            function ( $u ) {
                return esc_url_raw( $this->repair_url( (string) $u ) );
            },
            $urls
        ) ) ) );
        $limit = (int) Settings::get( 'max_images_per_product', 8 );
        $urls  = array_slice( $urls, 0, $limit );

        $attachment_ids = array();

        foreach ( $urls as $index => $url ) {
            $existing = $this->find_existing_attachment( $url );
            if ( $existing ) {
                $attachment_ids[] = $existing;
                $this->maybe_set_alt( $existing, $alt_texts[ $index ] ?? '' );
                continue;
            }

            $attachment_id = $this->sideload( $url, $product_id );
            if ( is_wp_error( $attachment_id ) ) {
                Logger::warning(
                    __( 'دانلود تصویر محصول ناموفق بود.', 'shopharvester-pro' ),
                    array( 'url' => $url, 'error' => $attachment_id->get_error_message() ),
                    array( 'channel' => 'media', 'source_url' => $url )
                );
                continue;
            }

            update_post_meta( $attachment_id, '_shp_source_url', $url );
            $this->maybe_set_alt( $attachment_id, $alt_texts[ $index ] ?? '' );
            $attachment_ids[] = $attachment_id;
        }

        if ( ! empty( $attachment_ids ) ) {
            set_post_thumbnail( $product_id, $attachment_ids[0] );
            update_post_meta( $product_id, '_shp_gallery_ids', $attachment_ids );
        }

        return $attachment_ids;
    }

    /**
     * Repair a broken absolute URL where the source site prepended its own
     * host or a stray slash (e.g. "http://a.com/http://dl.a.com/x.jpg" or
     * "/http://dl.a.com/x.jpg").
     *
     * @param string $url Raw URL.
     * @return string
     */
    private function repair_url( $url ) {
        $url = trim( $url );
        if ( preg_match( '#https?://[^/]+/+(https?://.+)$#i', $url, $m ) ) {
            return $m[1];
        }
        if ( preg_match( '#^/+(https?://.+)$#i', $url, $m ) ) {
            return $m[1];
        }
        return $url;
    }

    /**
     * Import one remote image (with URL de-duplication) and return its
     * attachment ID. Used for store logos and other one-off images.
     *
     * @param string $url       Remote URL.
     * @param int    $parent_id Parent post ID (0 for none).
     * @return int Attachment ID, or 0 on failure / when downloads are disabled.
     */
    public function import_single( $url, $parent_id = 0 ) {
        if ( ! Settings::get( 'download_images' ) ) {
            return 0;
        }

        $url = esc_url_raw( $this->repair_url( (string) $url ) );
        if ( '' === $url ) {
            return 0;
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $existing = $this->find_existing_attachment( $url );
        if ( $existing ) {
            return (int) $existing;
        }

        $attachment_id = $this->sideload( $url, (int) $parent_id );
        if ( is_wp_error( $attachment_id ) ) {
            Logger::warning(
                __( 'دانلود تصویر ناموفق بود.', 'shopharvester-pro' ),
                array( 'url' => $url, 'error' => $attachment_id->get_error_message() ),
                array( 'channel' => 'media', 'source_url' => $url )
            );
            return 0;
        }

        update_post_meta( $attachment_id, '_shp_source_url', $url );

        return (int) $attachment_id;
    }

    /**
     * Sideload a single remote file.
     *
     * @param string $url        Remote URL.
     * @param int    $product_id Parent post.
     * @return int|\WP_Error Attachment ID or error.
     */
    private function sideload( $url, $product_id ) {
        add_filter( 'http_request_timeout', array( $this, 'image_timeout' ) );
        add_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );
        Http_Client::begin();

        $tmp = download_url( $url, (int) Settings::get( 'image_timeout', 30 ) );

        Http_Client::end();
        remove_filter( 'http_request_timeout', array( $this, 'image_timeout' ) );

        if ( is_wp_error( $tmp ) ) {
            remove_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );
            return $tmp;
        }

        $filename = $this->filename_from_url( $url );

        // Reject non-image payloads early (e.g. an HTML 404 page).
        $type = function_exists( 'mime_content_type' ) ? @mime_content_type( $tmp ) : ''; // phpcs:ignore
        if ( $type && 0 !== strpos( $type, 'image/' ) ) {
            @unlink( $tmp ); // phpcs:ignore
            remove_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );
            return new \WP_Error( 'shp_not_image', __( 'محتوای دریافتی تصویر نبود.', 'shopharvester-pro' ) );
        }

        // Transcode WebP / AVIF to JPEG before sideloading. Several shared hosts
        // can store the original but silently fail to build some intermediate
        // WebP sizes, leaving broken thumbnails on shop and store pages; JPEG
        // resizing is universally supported. Override with the
        // `shp_transcode_images` filter.
        if ( apply_filters( 'shp_transcode_images', true ) ) {
            $converted = $this->to_jpeg( $tmp );
            if ( $converted && $converted !== $tmp ) {
                @unlink( $tmp ); // phpcs:ignore
                $tmp      = $converted;
                $filename = preg_replace( '/\.(webp|avif)$/i', '.jpg', $filename );
                if ( ! preg_match( '/\.jpe?g$/i', $filename ) ) {
                    $filename .= '.jpg';
                }
            }
        }

        $file_array = array(
            'name'     => $filename,
            'tmp_name' => $tmp,
        );

        $attachment_id = media_handle_sideload( $file_array, $product_id );

        remove_filter( 'upload_mimes', array( $this, 'allow_image_mimes' ) );

        if ( is_wp_error( $attachment_id ) && file_exists( $tmp ) ) {
            @unlink( $tmp ); // phpcs:ignore
        }

        return $attachment_id;
    }

    /**
     * Convert a downloaded WebP/AVIF file to a JPEG next to it. Returns the new
     * path, or the original path when conversion is not possible / not needed.
     *
     * @param string $tmp Downloaded file path.
     * @return string
     */
    private function to_jpeg( $tmp ) {
        if ( ! is_string( $tmp ) || ! file_exists( $tmp ) ) {
            return $tmp;
        }

        $type = function_exists( 'mime_content_type' ) ? @mime_content_type( $tmp ) : ''; // phpcs:ignore
        if ( 'image/webp' !== $type && 'image/avif' !== $type ) {
            return $tmp;
        }

        $src = null;
        if ( 'image/webp' === $type && function_exists( 'imagecreatefromwebp' ) ) {
            $src = @imagecreatefromwebp( $tmp ); // phpcs:ignore
        } elseif ( 'image/avif' === $type && function_exists( 'imagecreatefromavif' ) ) {
            $src = @imagecreatefromavif( $tmp ); // phpcs:ignore
        }

        if ( ! $src ) {
            // Try Imagick as a fallback.
            if ( class_exists( '\Imagick' ) ) {
                try {
                    $im = new \Imagick( $tmp );
                    $im->setImageFormat( 'jpeg' );
                    $im->setImageCompressionQuality( 90 );
                    $flat = $im->flattenImages();
                    $out  = $tmp . '.jpg';
                    ( $flat ?: $im )->writeImage( $out );
                    $im->clear();
                    return file_exists( $out ) ? $out : $tmp;
                } catch ( \Exception $e ) {
                    return $tmp;
                }
            }
            return $tmp;
        }

        $w   = imagesx( $src );
        $h   = imagesy( $src );
        $dst = imagecreatetruecolor( $w, $h );
        $white = imagecolorallocate( $dst, 255, 255, 255 );
        imagefilledrectangle( $dst, 0, 0, $w, $h, $white );
        imagecopy( $dst, $src, 0, 0, 0, 0, $w, $h );

        $out = $tmp . '.jpg';
        $ok  = imagejpeg( $dst, $out, 90 );

        imagedestroy( $src );
        imagedestroy( $dst );

        return ( $ok && file_exists( $out ) ) ? $out : $tmp;
    }

    /**
     * Filter callback: make sure common web image types are uploadable.
     *
     * @param array $mimes Allowed mime types.
     * @return array
     */
    public function allow_image_mimes( $mimes ) {
        $mimes['jpg|jpeg|jpe'] = 'image/jpeg';
        $mimes['png']         = 'image/png';
        $mimes['gif']         = 'image/gif';
        $mimes['webp']        = 'image/webp';
        $mimes['avif']        = 'image/avif';
        $mimes['bmp']         = 'image/bmp';

        return $mimes;
    }

    /**
     * Filter callback: bump HTTP timeout for image downloads.
     *
     * @return int
     */
    public function image_timeout() {
        return (int) Settings::get( 'image_timeout', 30 );
    }

    /**
     * Derive a safe filename (with extension) from a URL.
     *
     * @param string $url Remote URL.
     * @return string
     */
    private function filename_from_url( $url ) {
        $path = wp_parse_url( $url, PHP_URL_PATH );
        $name = $path ? wp_basename( $path ) : '';
        $name = sanitize_file_name( $name );

        if ( ! $name || ! preg_match( '/\.(jpe?g|png|gif|webp|avif)$/i', $name ) ) {
            $name = 'product-image-' . substr( md5( $url ), 0, 10 ) . '.jpg';
        }

        return $name;
    }

    /**
     * Look up an attachment we previously imported from the same URL.
     *
     * @param string $url Remote URL.
     * @return int|null
     */
    private function find_existing_attachment( $url ) {
        $found = get_posts(
            array(
                'post_type'   => 'attachment',
                'post_status' => 'inherit',
                'numberposts' => 1,
                'fields'      => 'ids',
                'meta_key'    => '_shp_source_url',
                'meta_value'  => esc_url_raw( $url ),
            )
        );

        return $found ? (int) $found[0] : null;
    }

    /**
     * Set the alt text on an attachment if one is provided and none exists.
     *
     * @param int    $attachment_id Attachment ID.
     * @param string $alt           Alt text.
     * @return void
     */
    private function maybe_set_alt( $attachment_id, $alt ) {
        $alt = sanitize_text_field( $alt );
        if ( '' === $alt ) {
            return;
        }
        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
    }
}
