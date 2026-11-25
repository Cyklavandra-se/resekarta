<?php
/**
 * One-time import from the spreadsheet.
 *
 * @package CV_Resekarta
 */

namespace CV_Resekarta\Admin;

use CV_Resekarta\Importer;

defined( 'ABSPATH' ) || exit;

$cvrk_files  = Importer::bundled_files();
$cvrk_labels = array(
	'destinations' => array( 'Destinationer', 'destinationer.csv', 'Destination, Lat, Lng, Sökord för karta, Norskt namn' ),
	'names'        => array( 'Destinationsnamn på andra språk', 'destinationsnamn.json', 'no/da/fi/en' ),
	'rules'        => array( 'Destinationsregler', 'destinationsregler.csv', 'Nyckelord, Destination – i ordning' ),
	'trips'        => array( 'Resor', 'resor.csv', 'Fliken Resor' ),
);
$cvrk_report = get_option( Importer::REPORT_OPTION );
// phpcs:ignore WordPress.Security.NonceVerification.Recommended
$cvrk_msg = isset( $_GET['cvrk_msg'] ) ? sanitize_key( wp_unslash( $_GET['cvrk_msg'] ) ) : '';

/**
 * Print a list of names, collapsed when long.
 */
$cvrk_list = function ( array $items, $title ) {
	if ( ! $items ) {
		return;
	}
	echo '<details' . ( count( $items ) <= 12 ? ' open' : '' ) . '><summary>' . esc_html( $title ) . ' (' . count( $items ) . ')</summary><ul class="cvrk-list">';
	foreach ( $items as $key => $value ) {
		if ( is_string( $key ) && preg_match( '~^https?://~', $key ) ) {
			echo '<li>' . esc_html( $value ) . ' – <a href="' . esc_url( $key ) . '" target="_blank" rel="noopener">' . esc_html( $key ) . '</a></li>';
		} elseif ( is_array( $value ) ) {
			echo '<li>' . esc_html( $key . ': ' . implode( ', ', $value ) ) . '</li>';
		} else {
			echo '<li>' . esc_html( $value ) . '</li>';
		}
	}
	echo '</ul></details>';
};
?>
<div class="wrap cvrk">
	<h1>Resekarta – import från kalkylarket</h1>
	<?php
	if ( 'imported' === $cvrk_msg ) {
		Admin::notice( 'Importen är klar. Se rapporten nedan.' );
	}
	?>
	<p>Flyttar över allt du gjort i kalkylarket: destinationer med koordinater och namn på andra språk, destinationsreglerna i ordning, och för varje resa kategori, destination, egen punkt, status och anteckning. Namn, bild, beskrivning och taggar importeras inte – de läses direkt från resornas sidor.</p>
	<p>Importen kan köras flera gånger utan att något dubbleras. Som standard fylls bara tomma fält i, så det du ändrat i WordPress finns kvar.</p>

	<form method="post" enctype="multipart/form-data" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<?php wp_nonce_field( 'cvrk_import' ); ?>
		<input type="hidden" name="action" value="cvrk_import">
		<table class="widefat cvrk-table cvrk-narrow">
			<thead><tr><th>Importera</th><th>Fil</th><th>Egen fil (valfritt)</th></tr></thead>
			<tbody>
			<?php foreach ( $cvrk_labels as $cvrk_key => $cvrk_l ) : ?>
				<tr>
					<td>
						<label><input type="checkbox" name="cvrk_use_<?php echo esc_attr( $cvrk_key ); ?>" value="1" checked> <?php echo esc_html( $cvrk_l[0] ); ?></label>
						<div class="cvrk-muted"><?php echo esc_html( $cvrk_l[2] ); ?></div>
					</td>
					<td>
						<code><?php echo esc_html( $cvrk_l[1] ); ?></code>
						<?php if ( is_readable( $cvrk_files[ $cvrk_key ] ) ) : ?>
							<div class="cvrk-muted">medföljer tillägget</div>
						<?php else : ?>
							<div class="cvrk-warn">saknas – ladda upp</div>
						<?php endif; ?>
					</td>
					<td><input type="file" name="cvrk_file_<?php echo esc_attr( $cvrk_key ); ?>" accept="<?php echo 'names' === $cvrk_key ? '.json' : '.csv'; ?>"></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<p class="cvrk-muted">Vill du använda en nyare version av kalkylarket: Arkiv → Ladda ned → Kommaavgränsade värden (.csv) för respektive flik, och välj filen ovan. En uppladdad fil används i stället för den som medföljer.</p>
		<p><label><input type="checkbox" name="cvrk_scan_after" value="1" checked> Läs listsidorna på sajten efter importen (rekommenderas – avgör vilka resor som är aktiva)</label></p>
		<p><label><input type="checkbox" name="cvrk_overwrite" value="1"> Skriv över värden som redan finns i WordPress</label></p>
		<?php submit_button( 'Importera' ); ?>
	</form>

	<?php if ( is_array( $cvrk_report ) ) : ?>
		<hr>
		<h2>Rapport från senaste importen (<?php echo esc_html( mysql2date( 'j M Y H:i', $cvrk_report['time'] ) ); ?>)</h2>
		<?php foreach ( (array) $cvrk_report['errors'] as $cvrk_e ) : ?>
			<p class="cvrk-warn"><?php echo esc_html( $cvrk_e ); ?></p>
		<?php endforeach; ?>

		<?php if ( $cvrk_report['destinations'] ) : ?>
			<?php $cvrk_d = $cvrk_report['destinations']; ?>
			<h3>Destinationer</h3>
			<p><?php printf( '%d rader i filen. %d nya, %d uppdaterade.', (int) $cvrk_d['rows'], count( $cvrk_d['created'] ), count( $cvrk_d['updated'] ) ); ?></p>
			<?php
			$cvrk_list( $cvrk_d['updated'], 'Uppdaterade' );
			$cvrk_list( $cvrk_d['errors'], 'Fel' );
			?>
		<?php endif; ?>

		<?php if ( $cvrk_report['rules'] ) : ?>
			<?php $cvrk_rr = $cvrk_report['rules']; ?>
			<h3>Destinationsregler</h3>
			<p><?php printf( '%d regler sparade i samma ordning som i filen.', (int) $cvrk_rr['saved'] ); ?></p>
			<?php $cvrk_list( $cvrk_rr['created_destinations'], 'Destinationer som bara fanns i reglerna (skapade utan koordinater)' ); ?>
		<?php endif; ?>

		<?php if ( $cvrk_report['trips'] ) : ?>
			<?php $cvrk_t = $cvrk_report['trips']; ?>
			<h3>Resor</h3>
			<ul class="cvrk-list">
				<li><?php printf( '%d rader i filen', (int) $cvrk_t['rows'] ); ?>
					<?php
					$cvrk_st = array();
					foreach ( $cvrk_t['by_status'] as $cvrk_k => $cvrk_n ) {
						$cvrk_st[] = ( '' === $cvrk_k ? '(tom)' : $cvrk_k ) . ' ' . (int) $cvrk_n;
					}
					echo $cvrk_st ? '(' . esc_html( implode( ', ', $cvrk_st ) ) . ')' : '';
					?>
				</li>
				<li><strong><?php echo (int) $cvrk_t['matched']; ?></strong> matchade mot sidor i WordPress, varav <strong><?php echo (int) $cvrk_t['active']; ?></strong> med status Aktiv</li>
				<li><strong><?php echo (int) $cvrk_t['own_points']; ?></strong> med egen punkt</li>
				<li><?php echo (int) $cvrk_t['hidden']; ?> dolda, <?php echo (int) $cvrk_t['flagged']; ?> markerade Kontrollera</li>
			</ul>
			<?php
			$cvrk_list( $cvrk_t['not_found'], 'Adresser som inte hittades i WordPress' );
			$cvrk_list( $cvrk_t['duplicates'], 'Dubbletter (samma sida på flera rader – bara första raden användes)' );
			$cvrk_list( $cvrk_t['created_destinations'], 'Destinationer som saknades och skapades (utan koordinater)' );
			?>
		<?php endif; ?>

		<?php if ( ! empty( $cvrk_report['scan'] ) ) : ?>
			<?php $cvrk_sc = $cvrk_report['scan']; ?>
			<h3>Listsidorna</h3>
			<?php if ( $cvrk_sc['ok'] ) : ?>
				<p><?php printf( '%d resor på listsidorna. %d nya som inte fanns i kalkylarket, %d som inte längre finns på listorna, %d tillbaka.', (int) $cvrk_sc['found'], count( $cvrk_sc['new'] ), count( $cvrk_sc['removed'] ), count( $cvrk_sc['back'] ) ); ?></p>
				<?php
				$cvrk_list( $cvrk_sc['new'], 'Nya resor' );
				$cvrk_list( $cvrk_sc['removed'], 'Inte längre på listorna' );
				$cvrk_list( $cvrk_sc['back'], 'Tillbaka på listorna' );
				$cvrk_list( $cvrk_sc['unresolved'], 'Länkar på listsidorna som inte hittades i WordPress' );
				?>
			<?php else : ?>
				<p class="cvrk-warn"><?php echo esc_html( $cvrk_sc['error'] ); ?></p>
			<?php endif; ?>
		<?php endif; ?>

		<?php if ( ! empty( $cvrk_report['no_point'] ) ) : ?>
			<h3>Destinationer utan koordinater</h3>
			<p>Resor med de här destinationerna visas inte på kartan förrän destinationen har en punkt (eller resan en egen punkt).</p>
			<?php $cvrk_list( $cvrk_report['no_point'], 'Saknar koordinater' ); ?>
		<?php endif; ?>

		<p><a class="button button-primary" href="<?php echo esc_url( Admin::url() ); ?>">Till översikten</a></p>
	<?php endif; ?>
</div>
