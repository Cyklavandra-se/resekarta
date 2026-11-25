<?php
/**
 * Overview of all trips (read-only in phase 1).
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Destinations;
use CV_Resekarta\Languages;
use CV_Resekarta\List_Scanner;
use CV_Resekarta\Trips;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$cvrk_filter = isset( $_GET['visa'] ) ? sanitize_key( wp_unslash( $_GET['visa'] ) ) : 'alla';
$cvrk_cat    = isset( $_GET['kat'] ) ? sanitize_key( wp_unslash( $_GET['kat'] ) ) : '';
$cvrk_search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
$cvrk_msg    = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';
// phpcs:enable

$cvrk_rows  = Admin::overview_rows();
$cvrk_langs = Languages::instance();
$cvrk_codes = array_diff( $cvrk_langs->codes(), array( $cvrk_langs->default_code() ) );

$cvrk_counts = array(
	'alla'        => count( $cvrk_rows ),
	'atgard'      => 0,
	'pa_kartan'   => 0,
	'inte_kartan' => 0,
	'kontrollera' => 0,
	'borttagna'   => 0,
);
foreach ( $cvrk_rows as $cvrk_r ) {
	$cvrk_counts['atgard']      += $cvrk_r['action'] ? 1 : 0;
	$cvrk_counts['pa_kartan']   += $cvrk_r['status']['on'] ? 1 : 0;
	$cvrk_counts['inte_kartan'] += $cvrk_r['status']['on'] ? 0 : 1;
	$cvrk_counts['kontrollera'] += '' !== $cvrk_r['trip']['check'] ? 1 : 0;
	$cvrk_counts['borttagna']   += 'removed' === $cvrk_r['status']['code'] ? 1 : 0;
}
$cvrk_filters = array(
	'alla'        => 'Alla',
	'atgard'      => 'Behöver åtgärd',
	'pa_kartan'   => 'På kartan',
	'inte_kartan' => 'Inte på kartan',
	'kontrollera' => 'Kontrollera',
	'borttagna'   => 'Borttagna',
);

$cvrk_visible = array_filter(
	$cvrk_rows,
	function ( $r ) use ( $cvrk_filter, $cvrk_cat, $cvrk_search ) {
		if ( $cvrk_cat && $r['trip']['category'] !== $cvrk_cat ) {
			return false;
		}
		if ( '' !== $cvrk_search ) {
			$hay = $r['trip']['title'] . ' ' . ( $r['dest'] ? $r['dest']['name'] : '' );
			if ( false === mb_stripos( $hay, $cvrk_search ) ) {
				return false;
			}
		}
		switch ( $cvrk_filter ) {
			case 'atgard':
				return $r['action'];
			case 'pa_kartan':
				return $r['status']['on'];
			case 'inte_kartan':
				return ! $r['status']['on'];
			case 'kontrollera':
				return '' !== $r['trip']['check'];
			case 'borttagna':
				return 'removed' === $r['status']['code'];
		}
		return true;
	}
);

$cvrk_scan = get_option( List_Scanner::LAST_SCAN_OPTION );
?>
<div class="wrap cvrk">
	<h1 class="wp-heading-inline">Resekarta – översikt</h1>

	<?php
	if ( 'scanned' === $cvrk_msg ) {
		Admin::notice( 'Listsidorna är lästa.' );
	} elseif ( 'scan_failed' === $cvrk_msg ) {
		Admin::notice( 'Listsidorna kunde inte läsas: ' . esc_html( is_array( $cvrk_scan ) ? $cvrk_scan['error'] : '' ), 'error' );
	}
	if ( ! $cvrk_rows ) {
		Admin::notice(
			current_user_can( Admin::CAP_SETTINGS )
				? 'Inga resor ännu. Börja med <a href="' . esc_url( Admin::url( 'import' ) ) . '">Import</a>.'
				: 'Inga resor ännu.',
			'info'
		);
	}
	?>

	<div class="cvrk-summary">
		<span><strong><?php echo (int) $cvrk_counts['alla']; ?></strong> resor</span>
		<span><strong><?php echo (int) $cvrk_counts['pa_kartan']; ?></strong> på kartan</span>
		<span class="<?php echo $cvrk_counts['atgard'] ? 'cvrk-warn' : ''; ?>"><strong><?php echo (int) $cvrk_counts['atgard']; ?></strong> behöver åtgärd</span>
		<?php if ( is_array( $cvrk_scan ) ) : ?>
			<span class="cvrk-muted">
				Listsidorna lästes <?php echo esc_html( mysql2date( 'j M Y H:i', $cvrk_scan['time'] ) ); ?>:
				<?php
				if ( $cvrk_scan['ok'] ) {
					printf(
						'%d resor, %d nya, %d borttagna, %d tillbaka',
						(int) $cvrk_scan['found'],
						count( $cvrk_scan['new'] ),
						count( $cvrk_scan['removed'] ),
						count( $cvrk_scan['back'] )
					);
					if ( $cvrk_scan['unresolved'] ) {
						echo ', <span class="cvrk-warn">' . count( $cvrk_scan['unresolved'] ) . ' länkar hittades inte i WordPress</span>';
					}
				} else {
					echo '<span class="cvrk-warn">' . esc_html( $cvrk_scan['error'] ) . '</span>';
				}
				?>
			</span>
		<?php endif; ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="cvrk-inline">
			<?php wp_nonce_field( 'cvrk_scan' ); ?>
			<input type="hidden" name="action" value="cvrk_scan">
			<button class="button">Läs listsidorna nu</button>
		</form>
	</div>

	<?php if ( is_array( $cvrk_scan ) && ! empty( $cvrk_scan['unresolved'] ) ) : ?>
		<details class="cvrk-details">
			<summary>Länkar på listsidorna som inte hittades i WordPress</summary>
			<ul>
				<?php foreach ( $cvrk_scan['unresolved'] as $cvrk_url => $cvrk_name ) : ?>
					<li><?php echo esc_html( $cvrk_name ); ?> – <a href="<?php echo esc_url( $cvrk_url ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $cvrk_url ); ?></a></li>
				<?php endforeach; ?>
			</ul>
		</details>
	<?php endif; ?>

	<ul class="subsubsub">
		<?php
		$cvrk_links = array();
		foreach ( $cvrk_filters as $cvrk_key => $cvrk_label ) {
			$cvrk_links[] = sprintf(
				'<li><a href="%s" class="%s">%s <span class="count">(%d)</span></a>',
				esc_url( Admin::url( '', array( 'visa' => $cvrk_key, 'kat' => $cvrk_cat ) ) ),
				$cvrk_key === $cvrk_filter ? 'current' : '',
				esc_html( $cvrk_label ),
				(int) $cvrk_counts[ $cvrk_key ]
			);
		}
		echo implode( ' | </li>', $cvrk_links ) . '</li>'; // phpcs:ignore WordPress.Security.EscapeOutput -- escaped above.
		?>
	</ul>

	<form method="get" class="cvrk-filterform">
		<input type="hidden" name="page" value="<?php echo esc_attr( Admin::SLUG ); ?>">
		<input type="hidden" name="visa" value="<?php echo esc_attr( $cvrk_filter ); ?>">
		<select name="kat">
			<option value="">Alla kategorier</option>
			<?php foreach ( Trips::CATEGORIES as $cvrk_key => $cvrk_label ) : ?>
				<option value="<?php echo esc_attr( $cvrk_key ); ?>" <?php selected( $cvrk_cat, $cvrk_key ); ?>><?php echo esc_html( $cvrk_label ); ?></option>
			<?php endforeach; ?>
		</select>
		<input type="search" name="s" value="<?php echo esc_attr( $cvrk_search ); ?>" placeholder="Sök resa eller destination">
		<button class="button">Filtrera</button>
	</form>

	<table class="widefat striped cvrk-table">
		<thead>
			<tr>
				<th>Resa</th>
				<th>Kategori</th>
				<th>Destination</th>
				<th>Nivå</th>
				<th>Synlighet</th>
				<th>På kartan</th>
				<th>Språk</th>
				<th>Kontrollera</th>
			</tr>
		</thead>
		<tbody>
			<?php if ( ! $cvrk_visible ) : ?>
				<tr><td colspan="8">Inga resor att visa.</td></tr>
			<?php endif; ?>
			<?php foreach ( $cvrk_visible as $cvrk_r ) : ?>
				<?php
				$cvrk_t    = $cvrk_r['trip'];
				$cvrk_edit = get_edit_post_link( $cvrk_t['id'] );
				?>
				<tr class="<?php echo $cvrk_r['action'] ? 'cvrk-row-action' : ''; ?>">
					<td class="cvrk-title">
						<strong><a href="<?php echo esc_url( (string) $cvrk_edit ); ?>"><?php echo esc_html( $cvrk_t['title'] ? $cvrk_t['title'] : '(utan namn)' ); ?></a></strong>
						<div class="row-actions"><a href="<?php echo esc_url( (string) get_permalink( $cvrk_t['id'] ) ); ?>" target="_blank" rel="noopener">Visa sidan</a></div>
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
			<?php endforeach; ?>
		</tbody>
	</table>
	<p class="cvrk-muted">Redigering av resorna kommer i nästa version. Namn, bild, beskrivning och taggar läses alltid direkt från resans sida.</p>
</div>
