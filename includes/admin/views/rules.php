<?php
/**
 * Destination rules (read-only in phase 1).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Rules;

defined( 'ABSPATH' ) || exit;

$cvrk_rules = Rules::all();
$cvrk_dests = Destinations::all();
?>
<div class="wrap cvrk">
	<h1>Resekarta – destinationsregler</h1>
	<p>När en ny resa saknar destination prövas reglerna uppifrån. Den första regel vars nyckelord finns i resans svenska namn (stora och små bokstäver spelar ingen roll) avgör destinationen.</p>
	<table class="widefat striped cvrk-table cvrk-narrow">
		<thead><tr><th>#</th><th>Nyckelord</th><th>Destination</th></tr></thead>
		<tbody>
			<?php if ( ! $cvrk_rules ) : ?>
				<tr><td colspan="3">Inga regler ännu.</td></tr>
			<?php endif; ?>
			<?php foreach ( $cvrk_rules as $cvrk_i => $cvrk_rule ) : ?>
				<tr>
					<td><?php echo (int) $cvrk_i + 1; ?></td>
					<td><?php echo esc_html( $cvrk_rule['keyword'] ); ?></td>
					<td>
						<?php
						if ( isset( $cvrk_dests[ $cvrk_rule['destination'] ] ) ) {
							echo esc_html( $cvrk_dests[ $cvrk_rule['destination'] ]['name'] );
							if ( ! Destinations::has_point( $cvrk_dests[ $cvrk_rule['destination'] ] ) ) {
								echo ' <span class="cvrk-warn">(saknar koordinater)</span>';
							}
						} else {
							echo '<span class="cvrk-warn">Destinationen finns inte längre</span>';
						}
						?>
					</td>
				</tr>
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="cvrk-muted">Att ändra reglerna och deras ordning kommer i nästa version.</p>
</div>
