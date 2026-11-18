<?php
/**
 * Hierarchical category / collection resolution and mapping.
 *
 * @package ShopHarvesterPro
 */

namespace ShopHarvesterPro\Includes;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Turns the raw category paths / collections scraped from a source store into
 * real WordPress terms — building the parent/child hierarchy, honouring an
 * operator-defined name map, doing fuzzy de-duplication against existing terms,
 * and optionally mirroring everything into the WooCommerce `product_cat`
 * taxonomy when WooCommerce is active.
 */
class Taxonomy_Mapper {

    const MAP_OPTION  = 'shp_category_map';
    const SEEN_OPTION = 'shp_category_seen';
    const SEEN_LIMIT  = 800;

    /**
     * Normalized source-name => canonical-name map.
     *
     * @var array
     */
    private $map;

    /**
     * Per-request cache of sibling terms keyed by "taxonomy:parent_id".
     *
     * @var array<string, array>
     */
    private $children_cache = array();

    /**
     * Per-request cache of every term name in a taxonomy (normalized name =>
     * term ID), used to reuse an operator's own category regardless of where
     * in the tree it lives.
     *
     * @var array<string, array<string,int>>
     */
    private $global_name_cache = array();

    /**
     * "taxonomy:parent:name" keys whose term creation already failed this
     * request — so a broken source category is not retried (and re-logged)
     * once per product.
     *
     * @var array<string, bool>
     */
    private $failed_terms = array();

    /**
     * Names encountered this request but not yet in the map (queued for save).
     *
     * @var array<string, bool>
     */
    private $seen_buffer = array();

    /**
     * Constructor.
     */
    public function __construct() {
        $stored    = get_option( self::MAP_OPTION, array() );
        $this->map = is_array( $stored ) ? $stored : array();
    }

    /**
     * Resolve every taxonomy target for one product.
     *
     * @param array $facts    Normalized product facts.
     * @param int   $store_id Owning store post ID.
     * @return array<string, int[]> taxonomy => leaf term IDs.
     */
    public function resolve( array $facts, $store_id ) {
        $paths = $this->collect_paths( $facts );

        $result = array();

        foreach ( $this->target_taxonomies() as $taxonomy ) {
            $term_ids = array();

            foreach ( $paths as $segments ) {
                $leaf = $this->ensure_path( $taxonomy, $segments );
                if ( $leaf ) {
                    $term_ids[] = $leaf;
                }
            }

            $result[ $taxonomy ] = array_values( array_unique( array_map( 'intval', $term_ids ) ) );
        }

        $this->flush_seen();

        return $result;
    }

    /* --------------------------------------------------------------------- *
     *  Path handling.
     * --------------------------------------------------------------------- */

    /**
     * Gather all category / collection paths from the product facts.
     *
     * @param array $facts Facts.
     * @return array<int, string[]> List of segment arrays (root -> leaf).
     */
    private function collect_paths( array $facts ) {
        $paths = array();

        foreach ( (array) ( $facts['categories_path'] ?? array() ) as $entry ) {
            $segments = is_array( $entry )
                ? array_values( array_filter( array_map( 'trim', $entry ), 'strlen' ) )
                : $this->split_path( $entry );
            if ( $segments ) {
                $paths[] = $segments;
            }
        }

        foreach ( (array) ( $facts['categories'] ?? array() ) as $flat ) {
            $segments = $this->split_path( $flat );
            if ( $segments ) {
                $paths[] = $segments;
            }
        }

        if ( Settings::get( 'import_collections_as_categories' ) ) {
            foreach ( (array) ( $facts['collections'] ?? array() ) as $collection ) {
                $segments = $this->split_path( $collection );
                if ( $segments ) {
                    $paths[] = $segments;
                }
            }
        }

        // De-duplicate identical paths.
        $unique = array();
        foreach ( $paths as $segments ) {
            $key = implode( ' > ', array_map( array( $this, 'normalize' ), $segments ) );
            if ( '' !== $key && ! isset( $unique[ $key ] ) ) {
                $unique[ $key ] = $segments;
            }
        }

        return array_values( $unique );
    }

    /**
     * Split a "a > b / c" style string into ordered segments.
     * Comma is intentionally NOT a separator — it delimits sibling categories,
     * which the importer has already exploded into separate list items.
     *
     * @param string $value Raw path string.
     * @return string[]
     */
    private function split_path( $value ) {
        $parts = preg_split( '~\s*(?:>|»|›|⟩|→|/|\||::|\\\\)\s*~u', (string) $value );
        $parts = array_map( 'trim', (array) $parts );

        return array_values( array_filter( $parts, static function ( $p ) {
            return '' !== $p && '-' !== $p && '#' !== $p;
        } ) );
    }

    /**
     * Ensure a full term path exists, creating missing levels, and return the
     * leaf term ID (or the deepest resolvable term when auto-create is off).
     *
     * @param string   $taxonomy Target taxonomy.
     * @param string[] $segments Ordered segment names.
     * @return int Leaf term ID (0 when nothing could be resolved).
     */
    private function ensure_path( $taxonomy, array $segments ) {
        $parent = 0;
        $leaf   = 0;

        foreach ( $segments as $raw ) {
            $name = trim( (string) $raw );
            if ( '' === $name ) {
                continue;
            }

            $name = $this->apply_map( $name );
            $norm = $this->normalize( $name );

            $term_id = $this->find_child( $taxonomy, $parent, $name, $norm );

            if ( ! $term_id ) {
                $this->seen_buffer[ $name ] = true;

                if ( ! Settings::get( 'category_auto_create' ) ) {
                    break; // Attach to the deepest known ancestor.
                }

                $fail_key = $taxonomy . ':' . $parent . ':' . $name;
                if ( isset( $this->failed_terms[ $fail_key ] ) ) {
                    break; // Already failed this run — do not retry or re-log.
                }

                $created = wp_insert_term(
                    $name,
                    $taxonomy,
                    array( 'parent' => $parent, 'slug' => $this->unique_term_slug( $name, $taxonomy, $parent ) )
                );

                if ( is_wp_error( $created ) ) {
                    $exists = $created->get_error_data( 'term_exists' );
                    if ( $exists ) {
                        $term_id = (int) $exists;
                    } else {
                        // Retry once with a guaranteed-unique hashed slug — on
                        // sites where a filter (e.g. WP-Parsidate) empties the
                        // Persian slug, the first attempt hits the unique-key.
                        $retry = wp_insert_term(
                            $name,
                            $taxonomy,
                            array( 'parent' => $parent, 'slug' => 'shp-' . substr( md5( $taxonomy . '|' . $parent . '|' . $name . '|' . microtime( true ) ), 0, 18 ) )
                        );
                        if ( ! is_wp_error( $retry ) && ! empty( $retry['term_id'] ) ) {
                            $term_id = (int) $retry['term_id'];
                        } else {
                            // A broken sanitize/insert filter (e.g. WP-Parsidate)
                            // can create the row yet still return an error — look
                            // the name up directly before giving up.
                            $term_id = $this->lookup_term_by_name( $taxonomy, $name, $parent );

                            if ( ! $term_id ) {
                                // Write the rows directly, bypassing every
                                // sanitize_title / pre_insert_term filter.
                                $term_id = $this->raw_insert_term( $name, $taxonomy, $parent );
                            }

                            if ( ! $term_id ) {
                                $this->failed_terms[ $fail_key ] = true;
                                $data = $created->get_error_data();
                                Logger::warning(
                                    sprintf( __( 'ساخت دسته «%s» ناموفق بود.', 'shopharvester-pro' ), $name ),
                                    array(
                                        'taxonomy' => $taxonomy,
                                        'error'    => $created->get_error_message(),
                                        'db_error' => is_string( $data ) ? $data : '',
                                    ),
                                    array( 'channel' => 'entities' )
                                );
                                break;
                            }
                        }
                    }
                } else {
                    $term_id = (int) $created['term_id'];
                    Logger::info(
                        sprintf( __( 'دسته جدید ساخته شد: %s', 'shopharvester-pro' ), $name ),
                        array( 'taxonomy' => $taxonomy, 'parent' => $parent, 'term_id' => $term_id ),
                        array( 'channel' => 'entities' )
                    );
                }

                unset( $this->children_cache[ $taxonomy . ':' . $parent ] );
                if ( $term_id && isset( $this->global_name_cache[ $taxonomy ] ) && ! isset( $this->global_name_cache[ $taxonomy ][ $norm ] ) ) {
                    $this->global_name_cache[ $taxonomy ][ $norm ] = $term_id;
                }
            }

            $parent = $term_id;
            $leaf   = $term_id;
        }

        return $leaf;
    }

    /**
     * Create a term by writing straight to wp_terms / wp_term_taxonomy, with an
     * ASCII slug that cannot collide. This is the last resort for sites where a
     * slug filter (WP-Parsidate and similar) makes wp_insert_term() fail with
     * db_insert_error on Persian names.
     *
     * @param string $taxonomy Taxonomy.
     * @param string $name     Term name.
     * @param int    $parent   Parent term ID.
     * @param string $slug     Optional explicit slug.
     * @return int New term ID (0 on failure).
     */
    private function raw_insert_term( $name, $taxonomy, $parent, $slug = '' ) {
        global $wpdb;

        if ( '' === $slug ) {
            $slug = 'shp-' . substr( md5( $taxonomy . '|' . $parent . '|' . $name . '|' . microtime( true ) ), 0, 20 );
        }
        while ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->terms} WHERE slug = %s", $slug ) ) > 0 ) {
            $slug = 'shp-' . substr( md5( $slug . wp_rand() ), 0, 20 );
        }

        if ( false === $wpdb->insert( $wpdb->terms, array( 'name' => $name, 'slug' => $slug, 'term_group' => 0 ), array( '%s', '%s', '%d' ) ) ) {
            return 0;
        }
        $term_id = (int) $wpdb->insert_id;

        $exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy ) );
        if ( ! $exists ) {
            $wpdb->insert(
                $wpdb->term_taxonomy,
                array( 'term_id' => $term_id, 'taxonomy' => $taxonomy, 'description' => '', 'parent' => (int) $parent, 'count' => 0 ),
                array( '%d', '%s', '%s', '%d', '%d' )
            );
        }

        clean_term_cache( $term_id, $taxonomy );
        do_action( 'created_term', $term_id, (int) $wpdb->get_var( $wpdb->prepare( "SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE term_id = %d AND taxonomy = %s", $term_id, $taxonomy ) ), $taxonomy, array() );

        return $term_id;
    }

    /**
     * Direct DB lookup for a term by exact name within a taxonomy (optionally a
     * given parent). Used as a fallback when wp_insert_term() reports an error
     * even though a filter may have inserted the row.
     *
     * @param string $taxonomy Taxonomy.
     * @param string $name     Exact term name.
     * @param int    $parent   Preferred parent term ID.
     * @return int Term ID or 0.
     */
    private function lookup_term_by_name( $taxonomy, $name, $parent ) {
        global $wpdb;

        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT t.term_id, tt.parent
                 FROM {$wpdb->terms} t
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE tt.taxonomy = %s AND t.name = %s",
                $taxonomy,
                $name
            )
        );
        if ( empty( $rows ) ) {
            return 0;
        }

        foreach ( $rows as $row ) {
            if ( (int) $row->parent === (int) $parent ) {
                clean_term_cache( (int) $row->term_id, $taxonomy );
                return (int) $row->term_id;
            }
        }

        clean_term_cache( (int) $rows[0]->term_id, $taxonomy );
        return (int) $rows[0]->term_id;
    }

    /**
     * A non-empty, taxonomy-unique slug for a new term.
     *
     * @param string $name     Term name.
     * @param string $taxonomy Taxonomy.
     * @param int    $parent   Parent term ID (for hash entropy).
     * @return string
     */
    private function unique_term_slug( $name, $taxonomy, $parent ) {
        $slug = sanitize_title( $name );
        if ( '' === $slug ) {
            $slug = sanitize_title( remove_accents( $name ) );
        }
        if ( '' === $slug ) {
            $slug = 'shp-' . substr( md5( $taxonomy . '|' . $parent . '|' . $name ), 0, 16 );
        }

        $base = $slug;
        $n    = 2;
        while ( term_exists( $slug, $taxonomy ) ) {
            $slug = $base . '-' . $n;
            $n++;
        }

        return $slug;
    }

    /**
     * Find a sibling term matching a name: exact-normalized, then by slug,
     * then fuzzy above the configured similarity threshold.
     *
     * @param string $taxonomy Taxonomy.
     * @param int    $parent   Parent term ID.
     * @param string $name     Display name.
     * @param string $norm     Normalized name.
     * @return int Term ID or 0.
     */
    private function find_child( $taxonomy, $parent, $name, $norm ) {
        $children = $this->siblings( $taxonomy, $parent );

        foreach ( $children as $term ) {
            if ( $this->normalize( $term->name ) === $norm ) {
                return (int) $term->term_id;
            }
        }

        $slug = sanitize_title( $name );
        if ( '' !== $slug ) {
            $by_slug = get_term_by( 'slug', $slug, $taxonomy );
            if ( $by_slug && (int) $by_slug->parent === (int) $parent ) {
                return (int) $by_slug->term_id;
            }
        }

        // No sibling matches this exactly — before building a new branch,
        // reuse an identically-named category anywhere else in the tree (the
        // operator's own manually-built categories most commonly, or a
        // source-site path shaped differently from the site's own). An exact
        // name match anywhere beats a fuzzy match among mere siblings.
        $global = $this->find_anywhere( $taxonomy, $norm );
        if ( $global ) {
            return $global;
        }

        $threshold = (float) Settings::get( 'category_fuzzy_threshold', 82 );
        if ( $threshold > 0 && mb_strlen( $norm ) >= 3 ) {
            $best     = 0;
            $best_pct = 0.0;

            foreach ( $children as $term ) {
                $pct = 0.0;
                similar_text( $norm, $this->normalize( $term->name ), $pct );
                if ( $pct > $best_pct ) {
                    $best_pct = $pct;
                    $best     = (int) $term->term_id;
                }
            }

            if ( $best_pct >= $threshold ) {
                return $best;
            }
        }

        return 0;
    }

    /**
     * An existing term anywhere in the taxonomy whose normalized name matches
     * exactly. Cached per taxonomy for the request; when several terms share
     * the name, the lowest term ID (oldest — most likely the operator's own,
     * pre-existing category) wins.
     *
     * @param string $taxonomy Taxonomy.
     * @param string $norm     Normalized name to match.
     * @return int Term ID or 0.
     */
    private function find_anywhere( $taxonomy, $norm ) {
        if ( '' === $norm ) {
            return 0;
        }

        if ( ! isset( $this->global_name_cache[ $taxonomy ] ) ) {
            $terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false, 'orderby' => 'term_id', 'order' => 'ASC' ) );
            $map   = array();
            if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $t ) {
                    $key = $this->normalize( $t->name );
                    if ( '' !== $key && ! isset( $map[ $key ] ) ) {
                        $map[ $key ] = (int) $t->term_id;
                    }
                }
            }
            $this->global_name_cache[ $taxonomy ] = $map;
        }

        return $this->global_name_cache[ $taxonomy ][ $norm ] ?? 0;
    }

    /**
     * Cached sibling lookup.
     *
     * @param string $taxonomy Taxonomy.
     * @param int    $parent   Parent term ID.
     * @return array
     */
    private function siblings( $taxonomy, $parent ) {
        $key = $taxonomy . ':' . (int) $parent;

        if ( ! isset( $this->children_cache[ $key ] ) ) {
            $terms = get_terms(
                array(
                    'taxonomy'   => $taxonomy,
                    'parent'     => (int) $parent,
                    'hide_empty' => false,
                )
            );

            $this->children_cache[ $key ] = is_wp_error( $terms ) ? array() : $terms;
        }

        return $this->children_cache[ $key ];
    }

    /* --------------------------------------------------------------------- *
     *  Targets, mapping, normalization.
     * --------------------------------------------------------------------- */

    /**
     * Which taxonomies the resolved terms should be written to.
     *
     * @return string[]
     */
    private function target_taxonomies() {
        $target = (string) Settings::get( 'category_target', 'shp' );
        $list   = array();

        // Writing real WooCommerce products implies the WooCommerce taxonomy.
        if ( 'woocommerce' === (string) Settings::get( 'product_target' ) && taxonomy_exists( 'product_cat' ) ) {
            return array( 'product_cat' );
        }

        if ( 'shp' === $target || 'both' === $target ) {
            $list[] = Entity_Manager::TAX_CAT;
        }
        if ( ( 'woo' === $target || 'both' === $target ) && taxonomy_exists( 'product_cat' ) ) {
            $list[] = 'product_cat';
        }

        return $list ?: array( Entity_Manager::TAX_CAT );
    }

    /**
     * Replace a segment name via the operator map. The mapped value may itself
     * be a path ("Shoes > Running"), in which case only its LAST segment is
     * used here — deeper structure from the map is not supported per segment.
     *
     * @param string $name Segment name.
     * @return string
     */
    private function apply_map( $name ) {
        $norm = $this->normalize( $name );

        if ( isset( $this->map[ $norm ] ) && '' !== trim( (string) $this->map[ $norm ] ) ) {
            $mapped   = trim( (string) $this->map[ $norm ] );
            $segments = $this->split_path( $mapped );
            return $segments ? end( $segments ) : $mapped;
        }

        return $name;
    }

    /**
     * Normalize a term name for comparison: unify Arabic/Persian letters and
     * digits, strip ZWNJ / punctuation noise, collapse whitespace, lowercase.
     *
     * @param string $value Raw value.
     * @return string
     */
    public function normalize( $value ) {
        $value = (string) $value;

        $replace = array(
            'ي' => 'ی', 'ك' => 'ک', 'ﻷ' => 'لا', 'ة' => 'ه', 'ۀ' => 'ه', 'أ' => 'ا', 'إ' => 'ا', 'آ' => 'ا',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            "\xE2\x80\x8C" => ' ', "\xE2\x80\x8F" => '', "\xE2\x80\x8E" => '', 'ـ' => '',
        );

        $value = strtr( $value, $replace );
        $value = preg_replace( '/[\p{P}\p{S}]+/u', ' ', $value );
        $value = preg_replace( '/\s+/u', ' ', $value );
        $value = trim( $value );

        return function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
    }

    /**
     * Persist newly seen names so the admin mapping screen can list them.
     *
     * @return void
     */
    private function flush_seen() {
        if ( empty( $this->seen_buffer ) ) {
            return;
        }

        $seen = get_option( self::SEEN_OPTION, array() );
        $seen = is_array( $seen ) ? $seen : array();

        foreach ( array_keys( $this->seen_buffer ) as $name ) {
            $seen[ $name ] = true;
        }

        if ( count( $seen ) > self::SEEN_LIMIT ) {
            $seen = array_slice( $seen, -self::SEEN_LIMIT, null, true );
        }

        update_option( self::SEEN_OPTION, $seen, false );
        $this->seen_buffer = array();
    }

    /* --------------------------------------------------------------------- *
     *  Admin helpers.
     * --------------------------------------------------------------------- */

    /**
     * All source names encountered so far (for the mapping UI).
     *
     * @return string[]
     */
    public static function seen_names() {
        $seen = get_option( self::SEEN_OPTION, array() );
        $seen = is_array( $seen ) ? array_keys( $seen ) : array();
        sort( $seen, SORT_NATURAL | SORT_FLAG_CASE );

        return $seen;
    }

    /**
     * Current raw map (normalized source => canonical target).
     *
     * @return array
     */
    public static function current_map() {
        $map = get_option( self::MAP_OPTION, array() );
        return is_array( $map ) ? $map : array();
    }

    /**
     * Persist a source => target map from the admin screen.
     *
     * @param array $pairs Raw { source_name: target_name }.
     * @return int Number of stored rules.
     */
    public static function save_map( array $pairs ) {
        $instance = new self();
        $clean    = array();

        foreach ( $pairs as $source => $target ) {
            $source = sanitize_text_field( (string) $source );
            $target = sanitize_text_field( (string) $target );

            if ( '' === $source || '' === $target ) {
                continue;
            }

            $clean[ $instance->normalize( $source ) ] = $target;
        }

        update_option( self::MAP_OPTION, $clean, false );

        return count( $clean );
    }
}
