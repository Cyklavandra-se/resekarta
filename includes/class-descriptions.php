<?php
/**
 * The SEO description of a page, as shown by AIOSEO (with the excerpt as fallback).
 *
 * AIOSEO keeps its fields in the table {prefix}aioseo_posts (column description, which can
 * contain smart tags such as #post_excerpt). When AIOSEO is active we ask its own API, which
 * resolves the tags and falls back to the post type default – the same text as in the page's
 * <meta name="description">, which the old spreadsheet read via the REST API.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta;

defined( 'ABSPATH' ) || exit;

final class Descriptions {

	/** @var bool|null */
	private static $has_table = null;

	public static function get( $post_id ) {
		$post_id = (int) $post_id;
		$pre     = apply_filters( 'cvrk_pre_description', null, $post_id );
		if ( null !== $pre ) {
			return self::clean( $pre );
		}

		$desc = self::from_aioseo_api( $post_id );
		if ( '' === $desc ) {
			$desc = self::from_aioseo_table( $post_id );
		}
		if ( '' === $desc ) {
			$desc = self::from_post( $post_id );
		}
		return $desc;
	}

	private static function from_aioseo_api( $post_id ) {
		if ( ! function_exists( 'aioseo' ) ) {
			return '';
		}
		try {
			$aioseo = aioseo();
			if ( isset( $aioseo->meta->description ) && method_exists( $aioseo->meta->description, 'getDescription' ) ) {
				$post = get_post( $post_id );
				return $post ? self::clean( $aioseo->meta->description->getDescription( $post ) ) : '';
			}
		} catch ( \Throwable $e ) {
			unset( $e ); // Fall back to the table.
		}
		return '';
	}

	private static function from_aioseo_table( $post_id ) {
		global $wpdb;
		$table = $wpdb->prefix . 'aioseo_posts';
		if ( null === self::$has_table ) {
			self::$has_table = ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		}
		if ( ! self::$has_table ) {
			return '';
		}
		$raw = (string) $wpdb->get_var( $wpdb->prepare( "SELECT description FROM {$table} WHERE post_id = %d", $post_id ) ); // phpcs:ignore WordPress.DB
		if ( '' === trim( $raw ) ) {
			return '';
		}
		// Smart tags without AIOSEO's API: the common ones by hand, the rest removed.
		$post = get_post( $post_id );
		$raw  = str_replace(
			array( '#post_excerpt', '#post_title', '#site_title', '#separator_sa' ),
			array( $post ? $post->post_excerpt : '', $post ? $post->post_title : '', get_bloginfo( 'name' ), '-' ),
			$raw
		);
		return self::clean( preg_replace( '/#[a-z_]+/', '', $raw ) );
	}

	private static function from_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post ) {
			return '';
		}
		if ( '' !== trim( $post->post_excerpt ) ) {
			return self::clean( $post->post_excerpt );
		}
		$content = preg_replace( '/\[[^\]]*\]/', ' ', strip_shortcodes( $post->post_content ) ); // Avada leaves its own.
		return self::clean( wp_trim_words( wp_strip_all_tags( $content ), 30, '…' ) );
	}

	/** Plain text: no tags, decoded entities, single spaces. */
	public static function clean( $text ) {
		$text = html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		return trim( preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}
}
