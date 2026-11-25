<?php
/**
 * One row in the overview. Variables: $cvrk_r (from Admin::overview_row()), $cvrk_codes.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Trips;

defined( 'ABSPATH' ) || exit;

$cvrk_t    = $cvrk_r['trip'];
$cvrk_edit = get_edit_post_link( $cvrk_t['id'] );
?>
<tr id="cvrk-trip-<?php echo (int) $cvrk_t['id']; ?>" class="<?php echo $cvrk_r['action'] ? 'cvrk-row-action' : ''; ?>"
	data-id="<?php echo (int) $cvrk_t['id']; ?>"
	data-category="<?php echo esc_attr( $cvrk_t['category_manual'] ); ?>"
	data-visibility="<?php echo esc_attr( $cvrk_t['visibility'] ); ?>"
	data-destination="<?php echo (int) $cvrk_t['destination']; ?>"
	data-lat="<?php echo esc_attr( $cvrk_t['own_point'] ? $cvrk_t['own_point'][0] : '' ); ?>"
	data-lng="<?php echo esc_attr( $cvrk_t['own_point'] ? $cvrk_t['own_point'][1] : '' ); ?>"
	data-check="<?php echo '' !== $cvrk_t['check'] ? '1' : ''; ?>">
	<td class="cvrk-title">
		<strong><a href="<?php echo esc_url( (string) $cvrk_edit ); ?>"><?php echo esc_html( $cvrk_t['title'] ? $cvrk_t['title'] : '(utan namn)' ); ?></a></strong>
		<div class="row-actions">
			<a href="#" class="cvrk-qe-open">Snabbredigera</a> |
			<a href="<?php echo esc_url( (string) $cvrk_edit ); ?>">Redigera sidan</a> |
			<a href="<?php echo esc_url( (string) get_permalink( $cvrk_t['id'] ) ); ?>" target="_blank" rel="noopener">Visa sidan</a>
		</div>
	</td>
	<td>
		<?php echo esc_html( $cvrk_t['category'] ? Trips::CATEGORIES[ $cvrk_t['category'] ] : '–' ); ?>
		<?php if ( $cvrk_t['category_manual'] && $cvrk_t['category_manual'] !== $cvrk_t['category_auto'] ) : ?>
			<span class="cvrk-muted" title="Ändrad manuellt. Listsidorna säger <?php echo esc_attr( $cvrk_t['category_auto'] ? Trips::CATEGORIES[ $cvrk_t['category_auto'] ] : 'ingen' ); ?>.">(manuell)</span>
		<?php endif; ?>
	</td>
	<td>
		<?php
		if ( $cvrk_r['dest'] ) {
			echo esc_html( $cvrk_r['dest']['name'] );
			if ( ! Destinations::has_point( $cvrk_r['dest'] ) ) {
				echo ' <span class="cvrk-warn">(saknar koordinater)</span>';
			}
		} else {
			echo '<span class="cvrk-muted">–</span>';
		}
		if ( $cvrk_t['own_point'] ) {
			printf(
				'<div class="cvrk-muted" title="Egen punkt">📍 %s, %s</div>',
				esc_html( $cvrk_t['own_point'][0] ),
				esc_html( $cvrk_t['own_point'][1] )
			);
		}
		?>
	</td>
	<td><?php echo $cvrk_r['levels'] ? esc_html( implode( ', ', $cvrk_r['levels'] ) ) : '<span class="cvrk-muted">–</span>'; ?></td>
	<td><?php echo esc_html( Trips::VISIBILITIES[ $cvrk_t['visibility'] ] ); ?></td>
	<td>
		<?php if ( $cvrk_r['status']['on'] ) : ?>
			<span class="cvrk-yes">Ja</span>
		<?php else : ?>
			<span class="cvrk-no">Nej</span> <span class="cvrk-reason"><?php echo esc_html( $cvrk_r['status']['reason'] ); ?></span>
		<?php endif; ?>
	</td>
	<td class="cvrk-langs">
		<?php foreach ( $cvrk_codes as $cvrk_code ) : ?>
			<?php if ( $cvrk_r['langs'][ $cvrk_code ] ) : ?>
				<a class="cvrk-lang cvrk-lang-yes" href="<?php echo esc_url( (string) get_permalink( $cvrk_r['langs'][ $cvrk_code ] ) ); ?>" target="_blank" rel="noopener" title="Översatt"><?php echo esc_html( $cvrk_code ); ?></a>
			<?php else : ?>
				<span class="cvrk-lang cvrk-lang-no" title="Ingen översättning – svensk text visas"><?php echo esc_html( $cvrk_code ); ?></span>
			<?php endif; ?>
		<?php endforeach; ?>
	</td>
	<td><?php echo '' !== $cvrk_t['check'] ? '<span class="cvrk-flag">⚠ ' . esc_html( $cvrk_t['check'] ) . '</span>' : ''; ?></td>
</tr>
