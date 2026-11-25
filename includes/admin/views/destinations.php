<?php
/**
 * Destinations: list, add, edit (with a map to place the point) and delete.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Languages;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only parameters.
$cvrk_edit_id = isset( $_GET['redigera'] ) ? (int) $_GET['redigera'] : 0;
$cvrk_new     = ! empty( $_GET['ny'] );
$cvrk_msg     = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';
$cvrk_marked  = isset( $_GET['markerad'] ) ? (int) $_GET['markerad'] : 0;
$cvrk_search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$cvrk_filter  = isset( $_GET['visa'] ) ? sanitize_key( wp_unslash( $_GET['visa'] ) ) : '';
// phpcs:enable

$cvrk_langs = Languages::instance();
$cvrk_codes = array_values(
	array_unique(
		array_merge(
			array_map( array( Languages::class, 'map_code' ), array_diff( $cvrk_langs->codes(), array( $cvrk_langs->default_code() ) ) ),
			array( 'no', 'da', 'fi', 'en' )
		)
	)
);
$cvrk_lang_names = array();
foreach ( $cvrk_langs->active() as $cvrk_wpml => $cvrk_l ) {
	$cvrk_lang_names[ Languages::map_code( $cvrk_wpml ) ] = $cvrk_l['name'];
}

$cvrk_error = get_transient( 'cvrk_form_error_' . get_current_user_id() );
if ( $cvrk_error ) {
	delete_transient( 'cvrk_form_error_' . get_current_user_id() );
}
?>
<div class="wrap cvrk">
	<h1 class="wp-heading-inline">Resekarta – destinationer</h1>
	<?php if ( ! $cvrk_edit_id && ! $cvrk_new ) : ?>
		<a href="<?php echo esc_url( Admin::url( 'destinationer', array( 'ny' => 1 ) ) ); ?>" class="page-title-action">Lägg till destination</a>
	<?php endif; ?>
	<?php Admin::tabs(); ?>
	<?php
	if ( 'saved' === $cvrk_msg ) {
		Admin::notice( 'Destinationen är sparad. Kartan byggs om.' );
	} elseif ( 'deleted' === $cvrk_msg ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cvrk_n = isset( $_GET['resor'] ) ? (int) $_GET['resor'] : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$cvrk_rn = isset( $_GET['regler'] ) ? (int) $_GET['regler'] : 0;
		Admin::notice( sprintf( 'Destinationen är borttagen. %d resor saknar nu destination, %d regler togs bort.', $cvrk_n, $cvrk_rn ) );
	} elseif ( 'delete_failed' === $cvrk_msg ) {
		Admin::notice( 'Destinationen kunde inte tas bort.', 'error' );
	}
	if ( $cvrk_error ) {
		Admin::notice( esc_html( $cvrk_error['message'] ), 'error' );
	}
	?>

	<?php
	if ( $cvrk_edit_id || $cvrk_new ) :
		$cvrk_d = $cvrk_edit_id ? Destinations::get( $cvrk_edit_id ) : array(
			'id'       => 0,
			'name'     => '',
			'lat'      => null,
			'lng'      => null,
			'keywords' => '',
			'names'    => array(),
		);
		if ( ! $cvrk_d ) {
			Admin::notice( 'Destinationen finns inte.', 'error' );
			echo '</div>';
			return;
		}
		// After a failed save: show what was typed.
		$cvrk_in   = $cvrk_error && isset( $cvrk_error['input'] ) ? $cvrk_error['input'] : null;
		$cvrk_val  = function ( $key, $fallback ) use ( $cvrk_in ) {
			return ( $cvrk_in && isset( $cvrk_in[ $key ] ) && ! is_array( $cvrk_in[ $key ] ) ) ? (string) $cvrk_in[ $key ] : (string) $fallback;
		};
		$cvrk_count = $cvrk_edit_id ? Destinations::trip_counts() : array();
		?>
		<h2><?php echo $cvrk_edit_id ? 'Ändra ' . esc_html( $cvrk_d['name'] ) : 'Ny destination'; ?></h2>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvrk-dest-form">
			<?php wp_nonce_field( 'cvrk_save_destination' ); ?>
			<input type="hidden" name="action" value="cvrk_save_destination">
			<input type="hidden" name="cvrk_dest[id]" value="<?php echo (int) $cvrk_d['id']; ?>">
			<div class="cvrk-dest-cols">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="cvrk-dest-name">Namn (svenska)</label></th>
						<td><input type="text" id="cvrk-dest-name" name="cvrk_dest[name]" class="regular-text" required value="<?php echo esc_attr( $cvrk_val( 'name', $cvrk_d['name'] ) ); ?>"></td>
					</tr>
					<tr class="cvrk-point">
						<th scope="row">Punkt</th>
						<td>
							<label>Lat <input type="text" name="cvrk_dest[lat]" class="cvrk-lat" size="10" inputmode="decimal" value="<?php echo esc_attr( $cvrk_val( 'lat', null === $cvrk_d['lat'] ? '' : $cvrk_d['lat'] ) ); ?>"></label>
							<label>Lng <input type="text" name="cvrk_dest[lng]" class="cvrk-lng" size="10" inputmode="decimal" value="<?php echo esc_attr( $cvrk_val( 'lng', null === $cvrk_d['lng'] ? '' : $cvrk_d['lng'] ) ); ?>"></label>
							<button type="button" class="button-link cvrk-clear-point">Töm</button>
							<?php Admin::picker( null, 'Klicka på kartan eller dra nålen. Decimaltal, t.ex. 42.5063 och 1.5218.' ); ?>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="cvrk-dest-kw">Sökord för geokodning</label></th>
						<td>
							<input type="text" id="cvrk-dest-kw" name="cvrk_dest[keywords]" class="regular-text" value="<?php echo esc_attr( $cvrk_val( 'keywords', $cvrk_d['keywords'] ) ); ?>">
							<p class="description">Valfritt. Används i stället för namnet när en punkt ska slås upp automatiskt, t.ex. "Sorteny, Andorra".</p>
						</td>
					</tr>
					<?php foreach ( $cvrk_codes as $cvrk_code ) : ?>
						<?php $cvrk_typed = $cvrk_in && isset( $cvrk_in['names'][ $cvrk_code ] ) ? (string) $cvrk_in['names'][ $cvrk_code ] : null; ?>
						<tr>
							<th scope="row"><label for="cvrk-dest-<?php echo esc_attr( $cvrk_code ); ?>">Namn – <?php echo esc_html( isset( $cvrk_lang_names[ $cvrk_code ] ) ? $cvrk_lang_names[ $cvrk_code ] : $cvrk_code ); ?></label></th>
							<td><input type="text" id="cvrk-dest-<?php echo esc_attr( $cvrk_code ); ?>" name="cvrk_dest[names][<?php echo esc_attr( $cvrk_code ); ?>]" class="regular-text" placeholder="Samma som svenska" value="<?php echo esc_attr( null !== $cvrk_typed ? $cvrk_typed : ( isset( $cvrk_d['names'][ $cvrk_code ] ) ? $cvrk_d['names'][ $cvrk_code ] : '' ) ); ?>"></td>
						</tr>
					<?php endforeach; ?>
				</table>
			</div>
			<p class="submit">
				<button class="button button-primary"><?php echo $cvrk_edit_id ? 'Spara ändringar' : 'Lägg till destination'; ?></button>
				<a class="button" href="<?php echo esc_url( Admin::url( 'destinationer' ) ); ?>">Avbryt</a>
			</p>
		</form>
		<?php if ( $cvrk_edit_id ) : ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvrk-delete-dest">
				<?php wp_nonce_field( 'cvrk_delete_destination_' . $cvrk_edit_id ); ?>
				<input type="hidden" name="action" value="cvrk_delete_destination">
				<input type="hidden" name="id" value="<?php echo (int) $cvrk_edit_id; ?>">
				<button class="button-link cvrk-delete">Ta bort destinationen</button>
				<span class="cvrk-muted"><?php printf( '(används av %d resor)', isset( $cvrk_count[ $cvrk_edit_id ] ) ? (int) $cvrk_count[ $cvrk_edit_id ] : 0 ); ?></span>
			</form>
		<?php endif; ?>
	</div>
		<?php
		return;
	endif;

	$cvrk_dests  = Destinations::all();
	$cvrk_counts = Destinations::trip_counts();
	$cvrk_total  = count( $cvrk_dests );
	$cvrk_missing = 0;
	foreach ( $cvrk_dests as $cvrk_d ) {
		$cvrk_missing += Destinations::has_point( $cvrk_d ) ? 0 : 1;
	}
	$cvrk_dests = array_filter(
		$cvrk_dests,
		function ( $d ) use ( $cvrk_search, $cvrk_filter, $cvrk_counts ) {
			if ( 'utan_punkt' === $cvrk_filter && Destinations::has_point( $d ) ) {
				return false;
			}
			if ( 'oanvanda' === $cvrk_filter && ! empty( $cvrk_counts[ $d['id'] ] ) ) {
				return false;
			}
			if ( '' !== $cvrk_search ) {
				$hay = $d['name'] . ' ' . implode( ' ', $d['names'] );
				return false !== mb_stripos( $hay, $cvrk_search );
			}
			return true;
		}
	);
	?>
	<p>
		<?php echo (int) $cvrk_total; ?> destinationer.
		Ett tomt namn på ett språk betyder att det svenska namnet används på kartan.
	</p>
	<ul class="subsubsub">
		<li><a href="<?php echo esc_url( Admin::url( 'destinationer' ) ); ?>" class="<?php echo '' === $cvrk_filter ? 'current' : ''; ?>">Alla <span class="count">(<?php echo (int) $cvrk_total; ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( Admin::url( 'destinationer', array( 'visa' => 'utan_punkt' ) ) ); ?>" class="<?php echo 'utan_punkt' === $cvrk_filter ? 'current' : ''; ?>">Saknar koordinater <span class="count">(<?php echo (int) $cvrk_missing; ?>)</span></a> |</li>
		<li><a href="<?php echo esc_url( Admin::url( 'destinationer', array( 'visa' => 'oanvanda' ) ) ); ?>" class="<?php echo 'oanvanda' === $cvrk_filter ? 'current' : ''; ?>">Används inte av någon resa</a></li>
	</ul>
	<form method="get" class="cvrk-filterform">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG . '-destinationer' ); ?>">
		<input type="search" name="s" value="<?php echo esc_attr( $cvrk_search ); ?>" placeholder="Sök destination">
		<button class="button">Sök</button>
	</form>
	<table class="widefat striped cvrk-table">
		<thead>
			<tr>
				<th>Destination</th>
				<th>Lat</th>
				<th>Lng</th>
				<?php foreach ( $cvrk_codes as $cvrk_code ) : ?>
					<th><?php echo esc_html( $cvrk_code ); ?></th>
				<?php endforeach; ?>
				<th>Sökord</th>
				<th>Resor</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $cvrk_dests ) : ?>
				<tr><td colspan="<?php echo 5 + count( $cvrk_codes ); ?>">Inga destinationer att visa.</td></tr>
			<?php endif; ?>
			<?php foreach ( $cvrk_dests as $cvrk_d ) : ?>
				<?php $cvrk_edit_url = Admin::url( 'destinationer', array( 'redigera' => $cvrk_d['id'] ) ); ?>
				<tr id="cvrk-dest-<?php echo (int) $cvrk_d['id']; ?>" class="<?php echo $cvrk_marked === $cvrk_d['id'] ? 'cvrk-saved' : ''; ?>">
					<td>
						<strong><a href="<?php echo esc_url( $cvrk_edit_url ); ?>"><?php echo esc_html( $cvrk_d['name'] ); ?></a></strong>
						<div class="row-actions"><a href="<?php echo esc_url( $cvrk_edit_url ); ?>">Ändra</a></div>
					</td>
					<?php if ( Destinations::has_point( $cvrk_d ) ) : ?>
						<td><?php echo esc_html( $cvrk_d['lat'] ); ?></td>
						<td><?php echo esc_html( $cvrk_d['lng'] ); ?></td>
					<?php else : ?>
						<td colspan="2"><a class="cvrk-warn" href="<?php echo esc_url( $cvrk_edit_url ); ?>">Saknar koordinater</a></td>
					<?php endif; ?>
					<?php foreach ( $cvrk_codes as $cvrk_code ) : ?>
						<td><?php echo isset( $cvrk_d['names'][ $cvrk_code ] ) ? esc_html( $cvrk_d['names'][ $cvrk_code ] ) : '<span class="cvrk-muted">–</span>'; ?></td>
					<?php endforeach; ?>
					<td><?php echo esc_html( $cvrk_d['keywords'] ); ?></td>
					<td>
						<?php
						$cvrk_n = isset( $cvrk_counts[ $cvrk_d['id'] ] ) ? (int) $cvrk_counts[ $cvrk_d['id'] ] : 0;
						echo $cvrk_n ? '<a href="' . esc_url( Admin::url( '', array( 's' => $cvrk_d['name'] ) ) ) . '">' . (int) $cvrk_n . '</a>' : '0';
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
</div>
