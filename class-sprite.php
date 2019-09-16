<?php
/**
 * Filename class-sprite.php
 *
 * @package clean50
 * @author  Peter Toi <peter@petertoi.com>
 */

namespace Toi\ToiBox;

use ParagonIE\Sodium\Core\Curve25519\Ge\P1p1;

add_action( 'init', function () {
    register_post_type(
        'sprite',
        [
            'public' => 'false',
        ]
    );
} );

/**
 * Class Sprite
 *
 * Summary
 *
 * @package Toi\ToiBox
 * @author  Peter Toi <peter@petertoi.com>
 * @version
 */
class Sprite {

    public $ids;

    public $hash;

    public $size;

    public $image_url;

    public $image_w;

    public $image_h;

    public $map;

    public function __construct( $ids, $size = 'thumbnail' ) {

        sort( $ids, SORT_NUMERIC );

        $this->ids = $ids;

        $this->hash = hash( 'md5', $size . '-' . implode( '|', $this->ids ) );

        $this->size = $size;
        // @TODO confirm image size exists.

        if ( $this->exists() ) {
            $this->load();
        } else {
            $this->create();
        }

        return $this;
    }

    /**
     * @param string $name
     *
     * @return false|int
     */
    private function exists() {
        $existing_query = new \WP_Query( [
            'post_type'      => 'sprite',
            'posts_per_page' => 1,
            'name'           => $this->hash,
            'fields'         => 'ids',
        ] );

        if ( $existing_query->have_posts() ) {
            return (int) $existing_query->posts[0];
        }

        return false;
    }

    /**
     * Create a Sprite
     *
     * @param $name        string Sprite name
     * @param $ids         array Attachment IDs
     * @param $size        string Image size
     */
    private function create() {

        global $wpdb;

        /** @noinspection SqlResolve */
        $results = $wpdb->get_results(
            sprintf(
                "SELECT post_id, meta_value as attachment_id FROM {$wpdb->prefix}postmeta WHERE meta_key = '_thumbnail_id' AND post_id IN (%s)",
                implode( ',', array_map( 'absint', $this->ids ) )
            )
        );

        /**
         * Populate the Sprite & Sprite Map
         */
        $this->map  = [];
        $offset     = 0;
        $imagick    = new \Imagick();
        $upload_dir = wp_get_upload_dir();

        foreach ( $results as $result ) {
            $metadata = wp_get_attachment_metadata( $result->attachment_id );
            if ( ! isset( $metadata['sizes'][ $this->size ] ) ) {
                continue;
                //TODO handle missing image size better
            }

            try {
                $image_path = implode(
                    '/',
                    [
                        $upload_dir['basedir'],
                        dirname( $metadata['file'] ),
                        $metadata['sizes'][ $this->size ]['file']
                    ]
                );

                $imagick->readImage( $image_path );

                $this->map[ $result->post_id ] = [
                    'id'        => $result->attachment_id,
                    'filepath'  => $image_path,
                    'width'     => $metadata['sizes'][ $this->size ]['width'],
                    'height'    => $metadata['sizes'][ $this->size ]['height'],
                    'mime-type' => $metadata['sizes'][ $this->size ]['mime-type'],
                    'offset'    => $offset,
                ];

                $offset += $metadata['sizes'][ $this->size ]['height'];

            } catch ( \ImagickException $e ) {
                return new \WP_Error( 'sprite-error', 'Imagick exception', $e );
            }
        }

        $imagick->resetIterator();
        $imagick_sprite = $imagick->appendImages( true );

        /**
         * Write
         * - Image
         * - Sprite Post
         */
        $sprite_dir = trailingslashit( $upload_dir['basedir'] ) . 'sprites/';

        wp_mkdir_p( $sprite_dir );

        $hash = $this->hash;

        $filepath = sprintf( '%s%s.jpg',
            trailingslashit( $sprite_dir ),
            $hash
        );

        $imagick_sprite->writeImage( $filepath );

        $this->image_url = wp_make_link_relative( home_url( str_replace( ABSPATH, '', $filepath ) ) );
        $this->image_w   = $imagick_sprite->getImageWidth();
        $this->image_h   = $imagick_sprite->getImageHeight();

        $data = [
            'image_url' => $this->image_url,
            'image_w'   => $this->image_w,
            'image_h'   => $this->image_h,
            'map'       => $this->map,
        ];

        $status = wp_insert_post( [
            'ID'           => $this->exists() ?: 0,
            'post_title'   => $hash,
            'post_content' => wp_json_encode( $data, JSON_PRETTY_PRINT ),
            'post_status'  => 'publish',
            'post_type'    => 'sprite',
        ] );

        return $status;
    }

    /**
     * Load a Sprite from the Database
     *
     * @param $name string Sprite name
     */
    private function load() {

        $query = new \WP_Query( [
            'post_type'      => 'sprite',
            'posts_per_page' => 1,
            'name'           => $this->hash,
        ] );

        if ( ! $query->have_posts() ) {
            return false;
        }

        $data = json_decode( $query->posts[0]->post_content, true );

        $this->image_url = $data['image_url'];
        $this->image_w   = $data['image_w'];
        $this->image_h   = $data['image_h'];
        $this->map       = $data['map'];

        return $this;
    }

    public function render_style( $class = '' ) {
        if ( empty( $class ) ) {
            $class = "sprite-{$this->hash}";
        }

        /** @noinspection CssUnknownTarget */
        printf( '<style>.%s { background-image: url(%s); }</style>', esc_attr( $class ), esc_attr( $this->image_url ) );
    }

    public function sprite_style( $id ) {
        $offset = $this->map[ $id ]['offset'];
        $height = $this->image_h - $this->map[ array_key_last( $this->map ) ]['height'];
        printf( 'background-position: 0 %.3F%%', ( 100 * $offset / $height ) );
    }

}
