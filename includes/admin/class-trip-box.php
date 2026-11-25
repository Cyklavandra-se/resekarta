<?php
/**
 * The box "Resekarta" on the edit screen of Swedish pages.
 *
 * Trips: category, visibility, destination (choose or create), own point with a small map,
 * and the "Kontrollera" flag with a button "Markera som kontrollerad".
 * Other Swedish pages: one checkbox to add the page to the map as a trip.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Languages;
use CV_Resekarta\Trips;

defined( 'ABSPATH' ) || exit;

final class Trip_Box {

	const NONCE = 'cvrk_trip_box';

	public static function boot() {
		add_action( 'add_meta_boxes_page', array( __CLASS__, 'add' ) );
		add_action( 'save_post_page', array( __CLASS__, 'save' ), 10, 2 );
		add_action( 'wp_ajax_cvrk_mark_checked', array( __CLASS__, 'ajax_mark_checked' ) );
	}

	public static function add( $post ) {
		if ( ! current_user_can( Admin::CAP_EDIT ) || ! Languages::instance()->is_original( $post->ID ) ) {
			return;
		}
		add_meta_box( 'cvrk-trip', 'Resekarta', array( __CLASS__, 'render' ), 'page', 'side', Trips::is_trip( $post->ID ) ? 'high' : 'low' );
		Admin::enqueue_picker();
	}

	public static function render( $post ) {
		wp_nonce_field( self::NONCE, 'cvrk_trip_nonce' );
		if ( ! Trips::is_trip( $post->ID ) ) {
			?>
			<p class="cvrk-muted">Sidan finns inte på någon av listsidorna och är inte en resa på kartan.</p>
			<label><input type="checkbox" name="cvrk_trip[add]" value="1"> Visa den här sidan på kartan som en resa</label>
			<?php
			return;
		}
		$trip   = Trips::get( $post->ID );
		$dests  = Destinations::all();
		$dest   = isset( $dests[ $trip['destination'] ] ) ? $dests[ $trip['destination'] ] : null;
		$status = Trips::map_status( $trip, $dest );
		$error  = get_transient( 'cvrk_trip_error_' . get_current_user_id() );
		if ( $error ) {
			delete_transient( 'cvrk_trip_error_' . get_current_user_id() );
		}
		?>
		<div class="cvrk cvrk-box">
			<?php if ( $error ) : ?>
				<p class="cvrk-warn"><?php echo esc_html( $error ); ?></p>
			<?php endif; ?>
			<p class="cvrk-status">
				På kartan:
				<?php if ( $status['on'] ) : ?>
					<span class="cvrk-yes">Ja</span>
				<?php else : ?>
					<span class="cvrk-no">Nej</span> – <?php echo esc_html( $status['reason'] ); ?>
				<?php endif; ?>
			</p>

			<?php if ( '' !== $trip['check'] ) : ?>
				<div class="cvrk-check" data-post="<?php echo (int) $post->ID; ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( 'cvrk_mark_checked_' . $post->ID ) ); ?>">
					<p class="cvrk-flag">⚠ <?php echo esc_html( $trip['check'] ); ?></p>
					<button type="button" class="button cvrk-mark-checked">Markera som kontrollerad</button>
				</div>
			<?php endif; ?>

			<p>
				<label for="cvrk-cat"><strong>Kategori</strong></label><br>
				<select id="cvrk-cat" name="cvrk_trip[category]" class="widefat">
					<option value=""><?php echo esc_html( 'Auto' . ( $trip['category_auto'] ? ' (' . Trips::CATEGORIES[ $trip['category_auto'] ] . ' enligt listsidorna)' : '' ) ); ?></option>
					<?php foreach ( Trips::CATEGORIES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $trip['category_manual'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</p>
			<p>
				<label for="cvrk-vis"><strong>Synlighet</strong></label><br>
				<select id="cvrk-vis" name="cvrk_trip[visibility]" class="widefat">
					<?php foreach ( Trips::VISIBILITIES as $key => $label ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>" <?php selected( $trip['visibility'], $key ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
				<span class="description">Auto: på kartan när resan finns på en listsida och är publicerad.</span>
			</p>
			<p>
				<label for="cvrk-dest"><strong>Destination</strong></label><br>
				<?php Admin::destination_select( 'cvrk_trip[destination]', $trip['destination'], 'cvrk-dest', true ); ?>
			</p>
			<div class="cvrk-new-dest" hidden>
				<p>
					<label>Namn på ny destination<br><input type="text" name="cvrk_trip[new_destination]" class="widefat"></label>
				</p>
				<p class="cvrk-muted">Koordinaterna fyller du i under Resekarta → Destinationer (eller ge resan en egen punkt nedan).</p>
			</div>

			<fieldset class="cvrk-point">
				<legend><strong>Egen punkt</strong> <span class="cvrk-muted">(valfri – flyttar bara den här resan)</span></legend>
				<label>Lat <input type="text" name="cvrk_trip[lat]" class="cvrk-lat" value="<?php echo esc_attr( $trip['own_point'] ? $trip['own_point'][0] : '' ); ?>" size="9" inputmode="decimal"></label>
				<label>Lng <input type="text" name="cvrk_trip[lng]" class="cvrk-lng" value="<?php echo esc_attr( $trip['own_point'] ? $trip['own_point'][1] : '' ); ?>" size="9" inputmode="decimal"></label>
				<?php
				Admin::picker(
					Destinations::has_point( $dest ) ? array( $dest['lat'], $dest['lng'] ) : null,
					'Klicka eller dra för att sätta en egen punkt. Den grå punkten är destinationens.'
				);
				?>
				<button type="button" class="button-link cvrk-clear-point">Ta bort egen punkt</button>
			</fieldset>
		</div>
		<?php
	}

	public static function save( $post_id, $post ) {
		if ( ! isset( $_POST['cvrk_trip_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['cvrk_trip_nonce'] ) ), self::NONCE ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! current_user_can( 'edit_page', $post_id ) ) {
			return;
		}
		$in = isset( $_POST['cvrk_trip'] ) && is_array( $_POST['cvrk_trip'] ) ? wp_unslash( $_POST['cvrk_trip'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- validated in Trips::update().

		if ( ! Trips::is_trip( $post_id ) ) {
			if ( ! empty( $in['add'] ) ) {
				Trips::register( $post_id );
				Trips::update( $post_id, array( 'visibility' => 'show' ) );
			}
			return;
		}

		$data = array(
			'category'    => isset( $in['category'] ) ? $in['category'] : '',
			'visibility'  => isset( $in['visibility'] ) ? $in['visibility'] : 'auto',
			'destination' => isset( $in['destination'] ) ? $in['destination'] : 0,
			'lat'         => isset( $in['lat'] ) ? $in['lat'] : '',
			'lng'         => isset( $in['lng'] ) ? $in['lng'] : '',
		);
		if ( 'new' === $data['destination'] ) {
			$id = Admin::create_destination_from_input( isset( $in['new_destination'] ) ? $in['new_destination'] : '' );
			if ( is_wp_error( $id ) ) {
				self::error( $id->get_error_message() );
				unset( $data['destination'] );
			} else {
				$data['destination'] = $id;
			}
		}
		$res = Trips::update( $post_id, $data );
		if ( is_wp_error( $res ) ) {
			self::error( $res->get_error_message() . ' Inget i rutan Resekarta sparades.' );
		}
	}

	private static function error( $message ) {
		set_transient( 'cvrk_trip_error_' . get_current_user_id(), $message, 300 );
	}

	public static function ajax_mark_checked() {
		$id = isset( $_POST['post'] ) ? (int) $_POST['post'] : 0;
		check_ajax_referer( 'cvrk_mark_checked_' . $id, 'nonce' );
		if ( ! $id || ! current_user_can( 'edit_page', $id ) || ! Trips::is_trip( $id ) ) {
			wp_send_json_error( array( 'message' => 'Behörighet saknas.' ), 403 );
		}
		Trips::update( $id, array( 'check_done' => true ) );
		wp_send_json_success( array( 'message' => 'Markerad som kontrollerad.' ) );
	}
}
