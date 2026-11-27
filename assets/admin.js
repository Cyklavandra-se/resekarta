/* Resekarta – admin: point pickers, quick edit, destinations, rules. */
(function ($) {
	'use strict';
	var C = window.cvrkAdmin || { texts: {} };

	/* ───────────── Destination select: "+ Ny destination…" shows a name field ───────────── */
	function syncNewDest(select) {
		var box = $(select).closest('.cvrk-box, .cvrk-qe, form').find('.cvrk-new-dest').first();
		var isNew = $(select).val() === 'new';
		box.prop('hidden', !isNew);
		if (isNew) { box.find('input').trigger('focus'); }
	}
	$(document).on('change', '.cvrk-dest-select', function () { syncNewDest(this); });

	/* ───────────── Point picker (Leaflet) ───────────── */
	function num(v) {
		v = String(v || '').replace(',', '.').trim();
		return v === '' || isNaN(+v) ? null : +v;
	}

	function initPicker(el) {
		if (!window.L || el._cvrk) { return; }
		var wrap = $(el).closest('.cvrk-point');
		var lat = wrap.find('.cvrk-lat');
		var lng = wrap.find('.cvrk-lng');
		var fb = [num(el.dataset.fallbackLat), num(el.dataset.fallbackLng)];
		var hasFb = fb[0] !== null && fb[1] !== null;

		var map = L.map(el, { scrollWheelZoom: false, attributionControl: true });
		L.tileLayer(C.tiles, { maxZoom: 18, attribution: C.attrib }).addTo(map);
		el._cvrk = map;

		var grey = hasFb ? L.circleMarker(fb, { radius: 6, color: '#646970', fillColor: '#a7aaad', fillOpacity: 0.9, weight: 2 }).addTo(map) : null;
		var marker = null;

		function current() {
			var a = num(lat.val()), b = num(lng.val());
			return a !== null && b !== null ? [a, b] : null;
		}
		function write(ll) {
			lat.val(ll ? (Math.round(ll.lat * 1e4) / 1e4) : '');
			lng.val(ll ? (Math.round(ll.lng * 1e4) / 1e4) : '');
		}
		function place(p, pan) {
			if (!p) {
				if (marker) { map.removeLayer(marker); marker = null; }
				return;
			}
			if (!marker) {
				marker = L.marker(p, { draggable: true }).addTo(map);
				marker.on('dragend', function () { write(marker.getLatLng()); });
			} else {
				marker.setLatLng(p);
			}
			if (pan) { map.setView(p, Math.max(map.getZoom() || 0, 6)); }
		}

		var start = current();
		if (start) { map.setView(start, 6); } else if (hasFb) { map.setView(fb, 6); } else { map.setView([50, 10], 3); }
		place(start, false);

		map.on('click', function (e) { place(e.latlng, false); write(e.latlng); });
		lat.add(lng).on('change input', function () { place(current(), true); });
		wrap.find('.cvrk-clear-point').on('click', function (e) {
			e.preventDefault();
			write(null);
			place(null);
		});
		// Leaflet needs a size; meta boxes can start collapsed or hidden.
		setTimeout(function () { map.invalidateSize(); }, 200);
		$(document).on('postbox-toggled', function () { map.invalidateSize(); });
	}

	function initPickers(ctx) {
		$(ctx || document).find('.cvrk-picker').each(function () { initPicker(this); });
	}
	$(function () { initPickers(); });

	/* ───────────── Trip box: "Markera som kontrollerad" ───────────── */
	$(document).on('click', '.cvrk-mark-checked', function () {
		var box = $(this).closest('.cvrk-check');
		var btn = $(this).prop('disabled', true);
		$.post(C.ajax, { action: 'cvrk_mark_checked', post: box.data('post'), nonce: box.data('nonce') })
			.done(function (r) {
				if (r && r.success) {
					box.html('<p class="cvrk-yes">' + C.texts.checked + '</p>');
				} else {
					btn.prop('disabled', false);
					window.alert((r && r.data && r.data.message) || C.texts.saveError);
				}
			})
			.fail(function () { btn.prop('disabled', false); window.alert(C.texts.saveError); });
	});

	/* ───────────── Overview: quick edit ───────────── */
	$(document).on('click', '.cvrk-qe-open', function (e) {
		e.preventDefault();
		var row = $(this).closest('tr');
		if (row.next().hasClass('cvrk-qe-row')) { return; }
		var tpl = document.getElementById('cvrk-qe-template');
		var edit = $(tpl.content.firstElementChild.cloneNode(true));
		edit.find('.cvrk-qe-title').text(row.find('.cvrk-title strong').text());
		edit.find('[name=category]').val(String(row.data('category') || ''));
		edit.find('[name=visibility]').val(String(row.data('visibility') || 'auto'));
		edit.find('[name=destination]').val(String(row.data('destination') || '0'));
		edit.find('[name=lat]').val(row.attr('data-lat'));
		edit.find('[name=lng]').val(row.attr('data-lng'));
		edit.find('.cvrk-qe-check').prop('hidden', !row.data('check'));
		row.hide().after(edit);
		edit.find('select').first().trigger('focus');
	});

	$(document).on('click', '.cvrk-qe-cancel', function () {
		var edit = $(this).closest('tr');
		edit.prev().show();
		edit.remove();
	});

	$(document).on('keydown', '.cvrk-qe input', function (e) {
		if (e.key === 'Enter') { e.preventDefault(); $(this).closest('.cvrk-qe').find('.cvrk-qe-save').trigger('click'); }
		if (e.key === 'Escape') { $(this).closest('.cvrk-qe').find('.cvrk-qe-cancel').trigger('click'); }
	});

	$(document).on('click', '.cvrk-qe-save', function () {
		var edit = $(this).closest('tr');
		var row = edit.prev();
		var q = edit.find('.cvrk-qe');
		var data = {
			action: 'cvrk_quick_edit',
			nonce: $('.cvrk-overview').data('nonce'),
			id: row.data('id'),
			category: q.find('[name=category]').val(),
			visibility: q.find('[name=visibility]').val(),
			destination: q.find('[name=destination]').val(),
			new_destination: q.find('[name=new_destination]').val(),
			lat: q.find('[name=lat]').val(),
			lng: q.find('[name=lng]').val(),
			check_done: q.find('[name=check_done]').is(':checked') ? 1 : ''
		};
		var spinner = q.find('.spinner').addClass('is-active');
		q.find('button').prop('disabled', true);
		q.find('.cvrk-qe-error').text('');
		$.post(C.ajax, data)
			.done(function (r) {
				if (r && r.success) {
					var fresh = $(r.data.html);
					row.replaceWith(fresh);
					edit.remove();
					fresh.addClass('cvrk-saved');
					setTimeout(function () { fresh.removeClass('cvrk-saved'); }, 1500);
				} else {
					q.find('.cvrk-qe-error').text((r && r.data && r.data.message) || C.texts.saveError);
				}
			})
			.fail(function () { q.find('.cvrk-qe-error').text(C.texts.saveError); })
			.always(function () { spinner.removeClass('is-active'); q.find('button').prop('disabled', false); });
	});

	/* ───────────── Destinations: "Hämta koordinater" in the visitor's own browser ─────────────
	   The web host shares its IP address with many sites, and OpenStreetMap often refuses it.
	   From the browser the lookup comes from Pär's own connection. If it cannot be done here
	   (no network, blocked), the form is sent and the server tries (with Photon as reserve). */
	var NOT_PLACE = ['highway', 'building', 'amenity', 'shop', 'office', 'craft', 'emergency', 'railway', 'aeroway'];
	$(document).on('click', 'button[name="cvrk_lookup"]', function (e) {
		var btn = $(this);
		if (btn.data('server')) { return; } // Second click after a failed browser lookup: let the server try.
		e.preventDefault();
		var form = btn.closest('form');
		var q = $.trim(form.find('#cvrk-dest-kw').val()) || $.trim(form.find('#cvrk-dest-name').val());
		var out = form.find('.cvrk-lookup-result');
		if (!out.length) { out = $('<span class="cvrk-lookup-result" aria-live="polite"></span>').insertAfter(btn.siblings('.cvrk-clear-point')); }
		if (!q) { out.text(C.texts.lookupEmpty).addClass('cvrk-warn'); return; }
		btn.prop('disabled', true);
		out.removeClass('cvrk-warn cvrk-yes').text(C.texts.lookupBusy);
		$.ajax({
			url: 'https://nominatim.openstreetmap.org/search',
			data: { format: 'jsonv2', limit: 5, 'accept-language': 'sv', q: q },
			dataType: 'json',
			timeout: 15000
		}).done(function (list) {
			var hit = null;
			$.each(list || [], function (i, r) {
				var cls = r.category || r['class'] || '';
				var typ = r.addresstype || r.type || '';
				if (NOT_PLACE.indexOf(cls) >= 0 || ['road', 'house', 'house_number', 'postcode', 'building'].indexOf(typ) >= 0) { return; }
				hit = r;
				return false;
			});
			if (!hit) {
				out.text(C.texts.lookupNone.replace('%s', q)).addClass('cvrk-warn');
				return;
			}
			var wrap = btn.closest('.cvrk-point');
			wrap.find('.cvrk-lat').val(Math.round(hit.lat * 1e4) / 1e4);
			wrap.find('.cvrk-lng').val(Math.round(hit.lon * 1e4) / 1e4).trigger('change');
			out.text(C.texts.lookupFound.replace('%s', hit.display_name)).addClass('cvrk-yes');
		}).fail(function () {
			// Blocked or offline here: let the server try instead.
			btn.data('server', 1).prop('disabled', false);
			btn.trigger('click');
		}).always(function () { btn.prop('disabled', false); });
	});

	/* ───────────── Destinations: confirm delete ───────────── */
	$(document).on('submit', '.cvrk-delete-dest', function (e) {
		if (!window.confirm(C.texts.confirmDel)) { e.preventDefault(); }
	});

	/* ───────────── Rules: sortable list, add/remove rows, test a name ───────────── */
	function renumber() {
		$('#cvrk-rules tbody tr').each(function (i) {
			$(this).find('.cvrk-rule-nr').text(i + 1);
			$(this).find('input, select').each(function () {
				this.name = this.name.replace(/cvrk_rules\[[^\]]*\]/, 'cvrk_rules[' + i + ']');
			});
		});
		testRule();
	}

	$(function () {
		var body = $('#cvrk-rules tbody');
		if (!body.length) { return; }
		body.sortable({ handle: '.cvrk-handle', axis: 'y', update: renumber, helper: function (e, tr) {
			tr.children().each(function () { $(this).width($(this).width()); });
			return tr;
		} });
		renumber();
	});

	$(document).on('click', '.cvrk-rule-add', function () {
		var tpl = document.getElementById('cvrk-rule-template');
		var tr = $(tpl.content.firstElementChild.cloneNode(true));
		$('#cvrk-rules tbody').append(tr);
		renumber();
		tr.find('input').trigger('focus');
	});
	$(document).on('click', '.cvrk-rule-remove', function () { $(this).closest('tr').remove(); renumber(); });
	$(document).on('click', '.cvrk-rule-up, .cvrk-rule-down', function () {
		var tr = $(this).closest('tr');
		if ($(this).hasClass('cvrk-rule-up')) { tr.prev().before(tr); } else { tr.next().after(tr); }
		renumber();
		$(this).trigger('focus');
	});
	$(document).on('input change', '#cvrk-rules input, #cvrk-rules select, #cvrk-rule-test', testRule);

	function testRule() {
		var out = $('#cvrk-rule-test-result');
		var name = String($('#cvrk-rule-test').val() || '').toLowerCase().trim();
		if (!out.length) { return; }
		if (!name) { out.text(''); return; }
		var hit = null;
		$('#cvrk-rules tbody tr').each(function (i) {
			var kw = String($(this).find('.cvrk-rule-keyword').val() || '').toLowerCase().trim();
			if (kw && name.indexOf(kw) >= 0) {
				hit = [i + 1, $(this).find('.cvrk-rule-keyword').val(), $(this).find('select option:selected').text()];
				return false;
			}
		});
		out.text(hit ? C.texts.match.replace('%1$s', hit[0]).replace('%2$s', hit[1]).replace('%3$s', hit[2]) : C.texts.noMatch);
	}
})(jQuery);
