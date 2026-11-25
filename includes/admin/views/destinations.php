<?php
/**
 * Destinations (read-only in phase 1).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Languages;

defined( 'ABSPATH' ) || exit;

$cvrk_dests  = Destinations::all();
$cvrk_counts = Destinations::trip_counts();
$cvrk_langs  = Languages::instance();
$cvrk_codes  = array_unique(
	array_merge(
		array_map( array( Languages::class, 'map_code' ), array_diff( $cvrk_langs->codes(), array( $cvrk_langs->default_code() ) ) ),
		array( 'no', 'da', 'fi', 'en' )
	)
);
?>
<div class="wrap cvrk">
	<h1>Resekarta – destinationer</h1>
	<?php Admin::tabs(); ?>
	<p><?php echo count( $cvrk_dests ); ?> destinationer. Ett tomt namn på ett språk betyder att det svenska namnet används på kartan.</p>
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
				<tr><td colspan="<?php echo 5 + count( $cvrk_codes ); ?>">Inga destinationer ännu.</td></tr>
			<?php endif; ?>
			<?php foreach ( $cvrk_dests as $cvrk_d ) : ?>
				<tr>
					<td><strong><?php echo esc_html( $cvrk_d['name'] ); ?></strong></td>
					<?php if ( Destinations::has_point( $cvrk_d ) ) : ?>
						<td><?php echo esc_html( $cvrk_d['lat'] ); ?></td>
						<td><?php echo esc_html( $cvrk_d['lng'] ); ?></td>
					<?php else : ?>
						<td colspan="2"><span class="cvrk-warn">Saknar koordinater</span></td>
					<?php endif; ?>
					<?php foreach ( $cvrk_codes as $cvrk_code ) : ?>
						<td><?php echo isset( $cvrk_d['names'][ $cvrk_code ] ) ? esc_html( $cvrk_d['names'][ $cvrk_code ] ) : '<span class="cvrk-muted">–</span>'; ?></td>
					<?php endforeach; ?>
					<td><?php echo esc_html( $cvrk_d['keywords'] ); ?></td>
					<td><?php echo isset( $cvrk_counts[ $cvrk_d['id'] ] ) ? (int) $cvrk_counts[ $cvrk_d['id'] ] : 0; ?></td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="cvrk-muted">Att lägga till, ändra och ta bort destinationer kommer i nästa version.</p>
</div>
