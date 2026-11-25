/**
 * Cyklavandra – Resedatabas
 * Apps Script för kalkylarket "Cyklavandra – Resedatabas".
 *
 * Flikar:
 *   Resor               – databasen (en rad per resa, nyckel = URL)
 *   Destinationsregler  – Nyckelord → Destination (översta matchande regel vinner)
 *   Destinationer       – Destination → Lat/Lng (fylls automatiskt, kan ändras) + Norskt namn
 *   Taggar              – CSS-klass → Visningsnamn
 *
 * Manuella ändringar skrivs aldrig över:
 *   - Kategori, Resans namn och Destination fylls bara i när cellen är tom.
 *   - Lat/Lng fylls bara i när cellerna är tomma.
 *   - Lat/Lng (egen punkt) i fliken Resor flyttar en enskild resa på kartan.
 *   - Status: scriptet sätter bara "Aktiv" eller "Borttagen". Skriv något annat,
 *     t.ex. "Dold", så lämnas raden i fred och resan visas inte på kartan.
 */

// ───────────────────────── Inställningar ─────────────────────────

const KATEGORISIDOR = [
  ['Guidad',   'https://cyklavandra.se/vandra/guidade/'],
  ['Cykel',    'https://cyklavandra.se/cykla/cykla-pa-egen-hand/'],
  ['Vandring', 'https://cyklavandra.se/vandra/pa-egen-hand/'],
];

const BAS_URL = 'https://cyklavandra.se';

const FLIK_RESOR   = 'Resor';
const FLIK_REGLER  = 'Destinationsregler';
const FLIK_DEST    = 'Destinationer';
const FLIK_TAGGAR  = 'Taggar';

// Kolumner i fliken Resor (1-baserade)
const K = {
  KATEGORI: 1, NAMN: 2, URL: 3, DESTINATION: 4, BILD: 5,
  BESKRIVNING: 6, TAGGAR: 7, STATUS: 8, UPPDATERAD: 9, ANTECKNING: 10,
  EGEN_LAT: 11, EGEN_LNG: 12, // egen punkt för resan – vinner över destinationens punkt
  NO_URL: 13, NO_NAMN: 14, NO_BESKRIVNING: 15, // norsk version (fylls av "Hämta norska texter")
  ALLA_TAGGAR: 16, // alla WordPress-taggar (kortnamn), för kartadresser som ?tag=donau
};
const NO_RUBRIKER = ['Norsk URL', 'Norskt namn', 'Norsk beskrivning'];
const NO_SAKNAS = 'Saknas'; // skrivs i Norsk URL när sidan saknar norsk översättning
const ANTAL_KOL = 10;

// URL-delar som aldrig är enskilda resor
const INTE_RESA = [
  '/information/', '/hitta-resa/', '/vanliga-fragor/', '/svarighetsgrad/',
  '/bokningsprocess/', '/betalningsvillkor/', '/nyhetsbrev/', '/om-oss/',
  '/konferenser/', '/grupper/', '/sjalvguidade/', '/nyheter/', '/tag/',
  '/destinationer/', '/category/',
];

const CACHE_NYCKEL = 'resor_karta_v2'; // + '_no' för den norska kartan
const CACHE_SEK = 600; // 10 minuter

// Apps Script stoppar en körning efter 6 minuter. Långsamma steg avbryts i tid och
// fortsätter nästa gång (sparat arbete finns kvar).
const KORNING_START = Date.now();
function tidKvar() { return Date.now() - KORNING_START < 4.5 * 60 * 1000; }

const HTTP_HEADERS = {
  'User-Agent': 'Mozilla/5.0 (compatible; CyklavandraResedatabas/1.0)',
  'Accept-Language': 'sv-SE,sv;q=0.9',
};

// ───────────────────────── Meny ─────────────────────────

function onOpen() {
  SpreadsheetApp.getUi()
    .createMenu('Cyklavandra')
    .addItem('Hämta nya resor', 'hamtaNyaResor')
    .addItem('Uppdatera bild, beskrivning och taggar', 'uppdateraAllaResor')
    .addItem('Fyll i koordinater', 'fyllKoordinater')
    .addItem('Hämta norska texter', 'hamtaNorska')
    .addSeparator()
    .addItem('Publicera kartan', 'publiceraKartan')
    .addSeparator()
    .addItem('Kör allt', 'korAllt')
    .addItem('Slå på nattlig körning', 'slaPaNattligKorning')
    .addItem('Slå av nattlig körning', 'slaAvNattligKorning')
    .addItem('Skicka testmejl', 'skickaTestmejl')
    .addSeparator()
    .addItem('Ställ in nyckel för kartan', 'stallInNyckel')
    .addToUi();
}

// Ändringar i arket syns direkt på kartan.
function onEdit() {
  rensaCache();
}

function rensaCache() {
  try { CacheService.getScriptCache().removeAll([CACHE_NYCKEL, CACHE_NYCKEL + '_no']); } catch (e) {}
}

function korAllt() {
  meddela('Klart!\n\n' + korAllaSteg().join('\n\n'));
}

function korAllaSteg() {
  return [
    hamtaNyaResor(true),
    uppdateraAllaResor(true),
    hamtaNorska(true),
    fyllKoordinater(true),
    publiceraKartan(true),
  ].filter(Boolean);
}

// Fylls av hamtaNyaResor: en rad per ny resa ("• Namn (Kategori) → Destination")
let NYA_RESOR = [];

/**
 * Nattlig körning. Skickar mejl när nya resor hittats eller när något gick fel.
 * Mejlet går till kontot som slog på nattlig körning, eller till adressen i
 * Script properties "MEJL_TILL" om den finns (flera adresser: kommaseparerade).
 */
function nattligKorning() {
  let delar = [], fel = '';
  try {
    delar = korAllaSteg();
  } catch (e) {
    fel = e.message;
  }
  // Bara sådant som kräver åtgärd – inte tillfälliga saker som tas om nästa natt
  const problem = delar.filter(t => /kunde inte publiceras|publicerades inte/i.test(t));
  if (!NYA_RESOR.length && !fel && !problem.length) return;

  const amne = NYA_RESOR.length
    ? `Kartan: ${NYA_RESOR.length} ny${NYA_RESOR.length > 1 ? 'a' : ''} resa${NYA_RESOR.length > 1 ? 'or' : ''} på cyklavandra.se`
    : 'Kartan: något behöver kontrolleras';
  const text = [
    NYA_RESOR.length ? `Nya resor som lagts till i arket och på kartan:\n${NYA_RESOR.join('\n')}` : '',
    NYA_RESOR.some(r => r.includes('INGEN DESTINATION'))
      ? 'Resor utan destination visas inte på kartan förrän du fyllt i Destination i arket.' : '',
    fel ? `Nattens körning avbröts med ett fel:\n${fel}` : '',
    problem.length ? `Att kontrollera:\n${problem.join('\n\n')}` : '',
    `Kalkylarket: ${SpreadsheetApp.getActive().getUrl()}`,
    delar.length ? `– – –\nHela sammanfattningen:\n\n${delar.join('\n\n')}` : '',
  ].filter(Boolean).join('\n\n');
  skickaMejl(amne, text);
}

function skickaMejl(amne, text) {
  const till = PropertiesService.getScriptProperties().getProperty('MEJL_TILL') ||
    Session.getEffectiveUser().getEmail();
  MailApp.sendEmail(till, amne, text, { name: 'Cykla & Vandra – resekartan' });
  return till;
}

function skickaTestmejl() {
  const till = skickaMejl('Kartan: testmejl',
    'Det här är ett test. Så här ser det ut när den nattliga körningen hittar en ny resa:\n\n' +
    '• Andorra och östra Pyrenéerna (Guidad) → Andorra & östra Pyrenéerna\n\n' +
    `Kalkylarket: ${SpreadsheetApp.getActive().getUrl()}`);
  meddela(`Testmejl skickat till ${till}.`);
}

function slaPaNattligKorning() {
  slaAvNattligKorning(true);
  ScriptApp.newTrigger('nattligKorning').timeBased().atHour(3).everyDays(1).create();
  meddela('Nattlig körning är påslagen (ca kl 03 varje natt). Du får mejl när nya resor hittas eller något går fel.');
}

function slaAvNattligKorning(tyst) {
  ScriptApp.getProjectTriggers()
    .filter(t => t.getHandlerFunction() === 'nattligKorning')
    .forEach(t => ScriptApp.deleteTrigger(t));
  if (tyst !== true) meddela('Nattlig körning är avslagen.');
}

// ───────────────────────── 1. Hämta nya resor ─────────────────────────

function hamtaNyaResor(tyst) {
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_RESOR);
  const regler = lasRegler();

  const paSajten = new Map(); // url → {kategori, namn}
  const cykelUrl = new Set();

  const lagg = (kategori, sidUrl, html) => {
    hittaResor(html, sidUrl).forEach(({ namn, url }) => {
      if (kategori === 'Cykel') cykelUrl.add(url);
      if (!paSajten.has(url)) paSajten.set(url, { kategori, namn });
    });
  };

  // Om en listsida inte går att hämta avbryts allt, så att inga resor felaktigt
  // markeras som borttagna.
  const kravHtml = (r, url) => {
    if (r.fel) throw new Error(`Kunde inte hämta ${url} (${r.fel}). Inget ändrades – försök igen om en stund.`);
    return r.html;
  };

  // Sida 1 av alla tre kategorierna
  const forsta = hamtaSidor(KATEGORISIDOR.map(([, url]) => url));

  // Listorna visar ett urval åt gången ("More"-knappen). Övriga sidor nås med ?_page=N.
  const fler = [];
  forsta.forEach((r, i) => {
    const [kategori, sidUrl] = KATEGORISIDOR[i];
    const html = kravHtml(r, sidUrl);
    lagg(kategori, sidUrl, html);
    const m = /data-totalpages=["'](\d+)["']/i.exec(html);
    const antalSidor = m ? Math.min(Number(m[1]), 50) : 1;
    for (let p = 2; p <= antalSidor; p++) fler.push({ kategori, sidUrl, url: `${sidUrl}?_page=${p}` });
  });

  if (fler.length) {
    hamtaSidor(fler.map(f => f.url)).forEach((r, j) => {
      lagg(fler[j].kategori, fler[j].sidUrl, kravHtml(r, fler[j].url));
    });
  }

  // Resor på både cykel- och vandringslistan → Cykel
  paSajten.forEach((v, url) => { if (cykelUrl.has(url)) v.kategori = 'Cykel'; });

  // Läs befintliga rader
  const sista = flik.getLastRow();
  const data = sista > 1 ? flik.getRange(2, 1, sista - 1, ANTAL_KOL).getValues() : [];
  const radPerUrl = new Map();
  data.forEach((rad, i) => radPerUrl.set(normUrl(rad[K.URL - 1]), i));

  // En länk på listsidan kan vara en gammal adress som omdirigeras till en resa som redan
  // finns i arket (t.ex. om URL:en rättats där). Räkna den då som samma resa.
  [...paSajten.keys()].filter(url => !radPerUrl.has(url)).forEach(url => {
    const ny = foljOmdirigering(url);
    const mal = ny ? normUrl(ny) : '';
    if (mal && mal !== url && radPerUrl.has(mal) && !paSajten.has(mal)) {
      paSajten.set(mal, paSajten.get(url));
      paSajten.delete(url);
    }
  });

  let nya = 0, borttagna = 0, tillbaka = 0;

  // Uppdatera befintliga rader
  data.forEach(rad => {
    const url = normUrl(rad[K.URL - 1]);
    const finns = paSajten.has(url);
    const status = String(rad[K.STATUS - 1]).trim();
    const autoStatus = status === '' || status === 'Aktiv' || status === 'Borttagen';

    if (finns) {
      const s = paSajten.get(url);
      if (!rad[K.KATEGORI - 1]) rad[K.KATEGORI - 1] = s.kategori;
      if (!rad[K.NAMN - 1]) rad[K.NAMN - 1] = s.namn;
      if (!rad[K.DESTINATION - 1]) sattDestination(rad, regler);
      if (autoStatus && status !== 'Aktiv') { rad[K.STATUS - 1] = 'Aktiv'; if (status === 'Borttagen') tillbaka++; }
    } else if (autoStatus && status !== 'Borttagen') {
      rad[K.STATUS - 1] = 'Borttagen';
      borttagna++;
    }
  });

  // Lägg till nya resor
  const ordning = { Guidad: 0, Cykel: 1, Vandring: 2 };
  const nyaRader = [];
  const nyaNamn = [];
  paSajten.forEach((s, url) => {
    if (radPerUrl.has(url)) return;
    const rad = new Array(ANTAL_KOL).fill('');
    rad[K.KATEGORI - 1] = s.kategori;
    rad[K.NAMN - 1] = s.namn;
    rad[K.URL - 1] = url;
    sattDestination(rad, regler);
    rad[K.STATUS - 1] = 'Aktiv';
    nyaRader.push(rad);
    const gissad = String(rad[K.ANTECKNING - 1]).startsWith(GISSAD_NOT)
      ? ` (från dagsprogrammet, ${String(rad[K.ANTECKNING - 1]).replace(/^.*\((dag \d+)\)$/, '$1')} – kontrollera)` : '';
    nyaNamn.push(`• ${s.namn} (${s.kategori}) → ${rad[K.DESTINATION - 1] || 'INGEN DESTINATION'}${gissad}\n  ${url}`);
    nya++;
  });
  nyaRader.sort((a, b) =>
    (ordning[a[0]] - ordning[b[0]]) || String(a[1]).localeCompare(String(b[1]), 'sv'));

  NYA_RESOR = nyaNamn;
  const alla = data.concat(nyaRader);
  if (alla.length) flik.getRange(2, 1, alla.length, ANTAL_KOL).setValues(alla);

  synkaDestinationslista();
  sparaGissadeKoordinater();
  rensaCache();

  const utanDest = alla.filter(r => !r[K.DESTINATION - 1] && r[K.STATUS - 1] === 'Aktiv').length;
  const text = `Hittade ${paSajten.size} resor på sajten.\n` +
    `Nya: ${nya} · Markerade som borttagna: ${borttagna} · Tillbaka på sajten: ${tillbaka}` +
    (nyaNamn.length ? `\n${nyaNamn.join('\n')}` : '') +
    (utanDest ? `\n${utanDest} aktiva resor saknar destination – fyll i dem eller lägg till en regel.` : '');
  if (tyst !== true) meddela(text);
  return text;
}

/** Plockar ut resor (namn + URL) ur en kategorisidas HTML. */
function hittaResor(html, sidUrl) {
  const resor = new Map();
  let senasteRubrik = '';
  const re = /<(h2|h3)\b[^>]*>([\s\S]*?)<\/\1>|<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/gi;
  let m;
  while ((m = re.exec(html)) !== null) {
    if (m[1]) {
      // En rubrik. Har den en länk direkt i sig?
      const inre = m[2];
      const lank = /<a\b[^>]*href="([^"]+)"[^>]*>([\s\S]*?)<\/a>/i.exec(inre);
      const rubrikText = rensaText(lank ? lank[2] : inre);
      if (lank) {
        const url = normUrl(lank[1]);
        if (arResa(url, sidUrl) && rubrikText.length > 3 && !resor.has(url)) resor.set(url, rubrikText);
      }
      senasteRubrik = rubrikText;
    } else {
      // En vanlig länk – räkna bara "Läs mer"-länkar, med närmaste rubrik som namn
      if (!/läs\s*mer/i.test(rensaText(m[4]))) continue;
      const url = normUrl(m[3]);
      if (arResa(url, sidUrl) && senasteRubrik.length > 3 && !resor.has(url)) resor.set(url, senasteRubrik);
    }
  }
  return Array.from(resor, ([url, namn]) => ({ url, namn }));
}

function arResa(url, sidUrl) {
  if (!url.startsWith(BAS_URL)) return false;
  if (url === normUrl(sidUrl)) return false;
  const delar = url.replace(BAS_URL, '').split('/').filter(Boolean);
  const minDjup = delar[0] === 'ovrigt' ? 2 : 3;
  if (delar.length < minDjup) return false;
  const vag = '/' + delar.join('/') + '/';
  return !INTE_RESA.some(p => vag.includes(p));
}

// ───────────────────────── 2. Uppdatera detaljer ─────────────────────────

function uppdateraAllaResor(tyst) {
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_RESOR);
  const sista = flik.getLastRow();
  if (sista < 2) { if (tyst !== true) meddela('Inga resor i arket ännu. Kör "Hämta nya resor" först.'); return; }

  const data = flik.getRange(2, 1, sista - 1, ANTAL_KOL).getValues();
  if (String(flik.getRange(1, K.ALLA_TAGGAR).getValue()).trim() !== 'Alla taggar (kortnamn)') {
    flik.getRange(1, K.ALLA_TAGGAR).setValue('Alla taggar (kortnamn)').setFontWeight('bold')
      .setBackground(flik.getRange(1, 1).getBackground());
  }
  const allaTaggar = flik.getRange(2, K.ALLA_TAGGAR, data.length, 1).getValues();
  sakerstallTaggar();
  const taggKarta = lasTaggar();
  const idag = Utilities.formatDate(new Date(), 'Europe/Madrid', 'yyyy-MM-dd HH:mm');

  // Alla WordPress-sidor via REST-API:t: länk, utvald bild, beskrivning och taggar.
  const sidor = new Map();
  for (let p = 1; p <= 20; p++) {
    const url = `${BAS_URL}/wp-json/wp/v2/pages?per_page=100&page=${p}` +
      '&_fields=link,featured_media,class_list,aioseo_head_json.description';
    const r = hamtaEnMedForsok(url);
    if (r.fel) {
      if (p === 1) throw new Error(`Kunde inte läsa sajtens REST-API (${r.fel}). Inget ändrades – försök igen om en stund.`);
      break; // sista sidan passerad
    }
    const lista = JSON.parse(r.html);
    if (!Array.isArray(lista) || !lista.length) break;
    lista.forEach(s => sidor.set(normUrl(s.link), s));
    if (lista.length < 100) break;
  }

  // Bild-URL:er för de utvalda bilderna, 100 åt gången
  const bildIds = [...new Set([...sidor.values()].map(s => s.featured_media).filter(Boolean))];
  const bilder = new Map();
  for (let i = 0; i < bildIds.length; i += 100) {
    const url = `${BAS_URL}/wp-json/wp/v2/media?per_page=100&include=${bildIds.slice(i, i + 100).join(',')}` +
      '&_fields=id,source_url';
    const r = hamtaEnMedForsok(url);
    if (!r.fel) JSON.parse(r.html).forEach(m => bilder.set(m.id, m.source_url));
  }

  const taggKlasser = hamtaTaggKlasser();

  let ok = 0, saknas = 0;
  data.forEach((rad, i) => {
    if (!rad[K.URL - 1] || rad[K.STATUS - 1] === 'Borttagen') return;
    let s = sidor.get(normUrl(rad[K.URL - 1]));
    let omdirigerad = '';
    if (!s) {
      // Listsidan kan länka till en gammal adress som omdirigeras
      omdirigerad = foljOmdirigering(rad[K.URL - 1]);
      if (omdirigerad) s = sidor.get(normUrl(omdirigerad));
    }
    if (!s) {
      rad[K.ANTECKNING - 1] = 'Hittades inte i WordPress';
      saknas++;
      return;
    }
    rad[K.BILD - 1] = bilder.get(s.featured_media) || '';
    rad[K.BESKRIVNING - 1] = avkoda((s.aioseo_head_json && s.aioseo_head_json.description) || '').trim();
    const klasser = s.class_list || [];
    rad[K.TAGGAR - 1] = [...taggKarta].filter(([klass]) => klasser.includes(klass)).map(([, namn]) => namn).join(', ');
    allaTaggar[i][0] = klasser.filter(k => /^tag-/.test(k)).map(k => taggKlasser.get(k) || k.slice(4)).join(', ');
    rad[K.UPPDATERAD - 1] = idag;
    if (/^(Fel vid hämtning|Hittades inte|Länken omdirigeras)/.test(String(rad[K.ANTECKNING - 1]))) rad[K.ANTECKNING - 1] = '';
    if (omdirigerad) rad[K.ANTECKNING - 1] = `Länken omdirigeras till ${normUrl(omdirigerad)} – uppdatera länken på listsidan`;
    ok++;
  });

  flik.getRange(2, 1, data.length, ANTAL_KOL).setValues(data);
  flik.getRange(2, K.ALLA_TAGGAR, data.length, 1).setValues(allaTaggar);
  rensaCache();
  const text = `Uppdaterade ${ok} resor.` + (saknas ? ` ${saknas} hittades inte i WordPress (se kolumnen Anteckning).` : '');
  if (tyst !== true) meddela(text);
  return text;
}

/**
 * Alla WordPress-taggar: CSS-klass (som i class_list) → taggens kortnamn.
 * WordPress använder taggens id-nummer i klassen när kortnamnet bara består av
 * siffror (t.ex. "2027") eller av tecken som inte får finnas i en klass (å, ä, ö).
 * Här räknas klassen fram på samma sätt som WordPress gör, så att "tag-381" blir "2027".
 */
function hamtaTaggKlasser() {
  const karta = new Map();
  for (let p = 1; p <= 20; p++) {
    const r = hamtaEnMedForsok(`${BAS_URL}/wp-json/wp/v2/tags?per_page=100&page=${p}&_fields=id,slug`);
    if (r.fel) break;
    let lista;
    try { lista = JSON.parse(r.html); } catch (e) { break; }
    if (!Array.isArray(lista) || !lista.length) break;
    lista.forEach(t => {
      const slug = String(t.slug || '');
      let klass = slug.replace(/%[a-fA-F0-9][a-fA-F0-9]/g, '').replace(/[^A-Za-z0-9_-]/g, '');
      if (/^\d+$/.test(klass) || !klass.replace(/-/g, '')) klass = String(t.id);
      let lasbar = slug;
      try { lasbar = decodeURIComponent(slug); } catch (e) {}
      karta.set('tag-' + klass, lasbar.toLowerCase());
    });
    if (lista.length < 100) break;
  }
  return karta;
}

function hittaBild(html) {
  return hittaMeta(html, 'property', 'og:image') || hittaMeta(html, 'name', 'twitter:image') || '';
}

function hittaMeta(html, attr, varde) {
  const re = new RegExp(`<meta\\b[^>]*${attr}=["']${varde}["'][^>]*>`, 'i');
  const tagg = re.exec(html);
  if (!tagg) return '';
  const c = /content=["']([^"']*)["']/i.exec(tagg[0]);
  return c ? avkoda(c[1]).trim() : '';
}

function hittaTaggar(html, taggKarta) {
  const hittade = [];
  const body = /<body\b[^>]*class=["']([^"']*)["']/i.exec(html);
  const klasser = body ? body[1].split(/\s+/) : [];
  taggKarta.forEach((namn, klass) => {
    const finns = klasser.includes(klass) ||
      new RegExp(`class=["'][^"']*\\b${klass}\\b[^"']*["']`, 'i').test(html);
    if (finns) hittade.push(namn);
  });
  return hittade.join(', ');
}

// ───────────────────────── 3. Koordinater ─────────────────────────

function fyllKoordinater(tyst) {
  synkaDestinationslista();
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_DEST);
  const sista = flik.getLastRow();
  if (sista < 2) return;

  const data = flik.getRange(2, 1, sista - 1, 4).getValues();
  const geo = Maps.newGeocoder().setLanguage('sv');
  let fyllda = 0, kvar = 0;
  const saknas = [];

  for (let i = 0; i < data.length; i++) {
    const rad = data[i];
    if (!rad[0] || (rad[1] !== '' && rad[2] !== '')) continue;
    if (!tidKvar()) { kvar++; continue; }
    const sok = String(rad[3] || rad[0]).replace(/&/g, ',');
    let svar = null;
    try { svar = geo.geocode(sok); } catch (e) { svar = null; }
    if (svar && svar.status === 'OK' && svar.results.length) {
      const loc = svar.results[0].geometry.location;
      rad[1] = Math.round(loc.lat * 10000) / 10000;
      rad[2] = Math.round(loc.lng * 10000) / 10000;
      flik.getRange(i + 2, 2, 1, 2).setValues([[rad[1], rad[2]]]); // spara direkt
      fyllda++;
    } else {
      saknas.push(rad[0]);
    }
    Utilities.sleep(100);
  }

  rensaCache();
  const text = `Fyllde i koordinater för ${fyllda} destinationer.` +
    (kvar ? `\n${kvar} återstår – kör "Fyll i koordinater" igen.` : '') +
    (saknas.length ? `\nHittade inte: ${saknas.join(', ')}.\nSkriv ett tydligare sökord i kolumn D (t.ex. "Vieste, Italien") eller fyll i Lat/Lng själv.` : '');
  if (tyst !== true) meddela(text);
  return text;
}

/** Ser till att varje destination i Resor finns i fliken Destinationer. */
function synkaDestinationslista() {
  const ss = SpreadsheetApp.getActive();
  const resor = ss.getSheetByName(FLIK_RESOR);
  const dest = ss.getSheetByName(FLIK_DEST);
  const sistaR = resor.getLastRow();
  if (sistaR < 2) return;

  const anvanda = new Set(
    resor.getRange(2, K.DESTINATION, sistaR - 1, 1).getValues().map(r => String(r[0]).trim()).filter(Boolean));
  const sistaD = dest.getLastRow();
  const finns = new Set(sistaD > 1
    ? dest.getRange(2, 1, sistaD - 1, 1).getValues().map(r => String(r[0]).trim()) : []);

  const nya = [...anvanda].filter(d => !finns.has(d)).sort((a, b) => a.localeCompare(b, 'sv'));
  if (nya.length) dest.getRange(sistaD + 1, 1, nya.length, 4).setValues(nya.map(d => [d, '', '', '']));
}

// ───────────────────────── 4. Norska texter ─────────────────────────

/**
 * Hittar den norska versionen (WPML) av varje aktiv resa och hämtar dess namn och
 * SEO-beskrivning. Svensk text används på den norska kartan där norsk saknas.
 *   - Norsk URL fylls bara i när cellen är tom eller säger "Saknas". En länk du skrivit
 *     själv skrivs aldrig över.
 *   - Norskt namn fylls bara i när cellen är tom.
 *   - Norsk beskrivning uppdateras varje gång (som den svenska), om en text hittas.
 */
function hamtaNorska(tyst) {
  const ss = SpreadsheetApp.getActive();
  const flik = ss.getSheetByName(FLIK_RESOR);
  forberedNorska();
  const sista = flik.getLastRow();
  if (sista < 2) return '';

  const data = flik.getRange(2, 1, sista - 1, K.NO_BESKRIVNING).getValues();
  const no = data.map(r => [r[K.NO_URL - 1], r[K.NO_NAMN - 1], r[K.NO_BESKRIVNING - 1]]);
  const spara = () => flik.getRange(2, K.NO_URL, no.length, 3).setValues(no);
  const aktiv = r => r[K.URL - 1] && r[K.STATUS - 1] === 'Aktiv';
  const harLank = v => /^https?:\/\//.test(String(v).trim());
  const svUrl = i => normUrl(data[i][K.URL - 1]);

  // Allt som går hämtas via REST-API:t – sajten svarar långsamt på vanliga sidhämtningar.
  const sv = svenskaSidor();   // svensk URL → {media, norsk}
  const noLista = norskaSidor(); // [{link, namn, beskrivning, media, svensk}]
  const noPerUrl = new Map(noLista.map(s => [s.link, s]));
  console.log(`REST: ${sv.size} svenska sidor, ${noLista.length} norska sidor`);

  // 1. Norska adresser för resor som saknar en
  const behover = [];
  data.forEach((r, i) => { if (aktiv(r) && !harLank(no[i][0])) behover.push(i); });
  const upptagna = new Set(no.map(v => String(v[0]).trim()).filter(harLank).map(normUrl));
  let viaWpml = 0, viaBild = 0, viaSida = 0;

  // a) WPML:s översättningslänkar (åt båda hållen)
  const svTillNo = new Map();
  sv.forEach((s, u) => { if (s.norsk) svTillNo.set(u, s.norsk); });
  noLista.forEach(s => { if (s.svensk && !svTillNo.has(s.svensk)) svTillNo.set(s.svensk, s.link); });
  const kvar1 = [];
  behover.forEach(i => {
    const u = svTillNo.get(svUrl(i));
    if (u && !upptagna.has(u)) { no[i][0] = u; upptagna.add(u); viaWpml++; } else kvar1.push(i);
  });

  // b) Samma utvalda bild och samma typ av resa – bara när paret är entydigt åt båda hållen
  const typ = u => /\/cykla\/|\/sykl|\/sykkel/.test(u) ? 'cykel' : /guid/.test(u) ? 'guidad' : 'vandring';
  const kandidater = new Map(); // rad → [norska länkar]
  const tillbaka = new Map();   // norsk länk → [rader]
  kvar1.forEach(i => {
    const s = sv.get(svUrl(i));
    if (!s || !s.media) return;
    const lista = noLista.filter(n => n.media === s.media && !upptagna.has(n.link) && typ(n.link) === typ(svUrl(i)));
    kandidater.set(i, lista.map(n => n.link));
    lista.forEach(n => { if (!tillbaka.has(n.link)) tillbaka.set(n.link, []); tillbaka.get(n.link).push(i); });
  });
  const kvar2 = [];
  kvar1.forEach(i => {
    const k = kandidater.get(i) || [];
    if (k.length === 1 && tillbaka.get(k[0]).length === 1) { no[i][0] = k[0]; upptagna.add(k[0]); viaBild++; }
    else kvar2.push(i);
  });
  spara();

  // c) Resten: läs språkväljaren på den svenska sidan. Ett försök per sida och stopp i tid;
  //    det som inte hinns med tas nästa gång.
  for (let s = 0; s < kvar2.length && tidKvar(); s += 5) {
    const del = kvar2.slice(s, s + 5);
    hamtaSidor(del.map(i => String(data[i][K.URL - 1])), true).forEach((r, j) => {
      if (r.fel) return;
      const u = hittaNorskLank(r.html);
      no[del[j]][0] = u || NO_SAKNAS;
      if (u) viaSida++;
    });
    spara();
  }

  // 2. Norska namn och beskrivningar
  const lasSida = [];
  data.forEach((r, i) => {
    if (!aktiv(r) || !harLank(no[i][0])) return;
    const n = noPerUrl.get(normUrl(no[i][0]));
    if (n) {
      if (!String(no[i][1]).trim() && n.namn) no[i][1] = n.namn;
      if (n.beskrivning) no[i][2] = n.beskrivning;
    }
    if (!String(no[i][1]).trim() || !String(no[i][2]).trim()) lasSida.push(i);
  });
  spara();
  let olasta = 0;
  for (let s = 0; s < lasSida.length; s += 5) {
    if (!tidKvar()) { olasta = lasSida.length - s; break; }
    const del = lasSida.slice(s, s + 5);
    hamtaSidor(del.map(i => String(no[i][0]).trim()), true).forEach((r, j) => {
      const i = del[j];
      if (r.fel) { olasta++; return; }
      const namn = hittaSidnamn(r.html);
      const besk = hittaMeta(r.html, 'name', 'description') || hittaMeta(r.html, 'property', 'og:description');
      if (!String(no[i][1]).trim() && namn) no[i][1] = namn;
      if (!String(no[i][2]).trim() && besk) no[i][2] = besk;
    });
    spara();
  }
  rensaCache();

  const aktiva = data.filter(aktiv).length;
  const medNorsk = data.filter((r, i) => aktiv(r) && harLank(no[i][0])).length;
  const okontrollerade = data.filter((r, i) => aktiv(r) && !String(no[i][0]).trim()).length;
  const utan = data.map((r, i) => aktiv(r) && no[i][0] === NO_SAKNAS ? r[K.NAMN - 1] : null).filter(Boolean);
  const text = `Norska versioner: ${medNorsk} av ${aktiva} aktiva resor.` +
    ` Nya nu: ${viaWpml + viaBild + viaSida} (WPML ${viaWpml}, bild ${viaBild}, sidan ${viaSida}).` +
    ` REST: ${noLista.length} norska sidor.` +
    (okontrollerade ? `\n${okontrollerade} resor är inte kontrollerade än – kör "Hämta norska texter" igen.` : '') +
    (olasta ? `\n${olasta} norska sidor kunde inte läsas än – de tas nästa gång.` : '') +
    (utan.length ? `\nUtan norsk sida (visas med svensk text): ${utan.join(', ')}.` : '');
  console.log(text);
  if (tyst !== true) meddela(text);
  return text;
}

/** Alla svenska sidor via REST: URL → {media, norsk}. norsk kommer från WPML om fältet finns. */
function svenskaSidor() {
  const karta = new Map();
  for (let p = 1; p <= 20; p++) {
    const r = hamtaEnMedForsok(`${BAS_URL}/wp-json/wp/v2/pages?per_page=100&page=${p}&_fields=link,featured_media,wpml_translations`);
    if (r.fel) break;
    let lista;
    try { lista = JSON.parse(r.html); } catch (e) { break; }
    if (!Array.isArray(lista) || !lista.length) break;
    lista.forEach(s => {
      const link = normUrl(s.link);
      if (/\/no\//.test(link)) return;
      karta.set(link, { media: s.featured_media || 0, norsk: wpmlLank(s.wpml_translations, /^(nb|no|nn)/) });
    });
    if (lista.length < 100) break;
  }
  return karta;
}

/** Alla norska sidor via REST (WPML: ?lang=no eller /no/wp-json/). */
function norskaSidor() {
  const falt = '&_fields=link,title,featured_media,aioseo_head_json.description,wpml_translations';
  const varianter = [
    p => `${BAS_URL}/wp-json/wp/v2/pages?lang=no&per_page=100&page=${p}${falt}`,
    p => `${BAS_URL}/no/wp-json/wp/v2/pages?per_page=100&page=${p}${falt}`,
    p => `${BAS_URL}/wp-json/wp/v2/pages?lang=nb&per_page=100&page=${p}${falt}`,
  ];
  for (const variant of varianter) {
    const ut = [];
    for (let p = 1; p <= 20; p++) {
      const r = hamtaEnMedForsok(variant(p));
      if (r.fel) break;
      let lista;
      try { lista = JSON.parse(r.html); } catch (e) { break; }
      if (!Array.isArray(lista) || !lista.length) break;
      const norska = lista.filter(s => /\/no\//.test(String(s.link)));
      if (p === 1 && !norska.length) break; // varianten ger inte norska sidor
      norska.forEach(s => ut.push({
        link: normUrl(s.link),
        namn: rensaText((s.title && s.title.rendered) || ''),
        beskrivning: avkoda((s.aioseo_head_json && s.aioseo_head_json.description) || '').trim(),
        media: s.featured_media || 0,
        svensk: wpmlLank(s.wpml_translations, /^sv/),
      }));
      if (lista.length < 100) break;
    }
    if (ut.length) { console.log('Norska sidor via ' + variant(1)); return ut; }
  }
  console.log('REST-API:t gav inga norska sidor');
  return [];
}

/** Plockar länken för ett språk ur WPML:s wpml_translations-fält (om det finns). */
function wpmlLank(t, sprakRe) {
  if (!t) return '';
  const lista = Array.isArray(t) ? t : Object.values(t);
  for (const o of lista) {
    if (!o) continue;
    const sprak = String(o.locale || o.language_code || o.code || '').toLowerCase();
    const lank = o.href || o.link || o.url || o.permalink || '';
    if (sprakRe.test(sprak) && lank) return normUrl(lank);
  }
  return '';
}

/** Läser länken till den norska versionen ur språkväljaren (WPML) på en svensk sida. */
function hittaNorskLank(html) {
  const prefix = `${BAS_URL}/no/`;
  const kandidater = [];
  // <link rel="alternate" hreflang="nb" href="...">
  const reAlt = /<link\b[^>]*hreflang=["'](?:nb|no|nn)[^"']*["'][^>]*>/gi;
  let m;
  while ((m = reAlt.exec(html)) !== null) {
    const h = /href=["']([^"']+)["']/i.exec(m[0]);
    if (h) kandidater.push(h[1]);
  }
  // Språkväljarens länkar (wpml-ls) eller länkar med texten "Norsk"
  const reA = /<a\b([^>]*)href=["']([^"']+)["']([^>]*)>([\s\S]*?)<\/a>/gi;
  while ((m = reA.exec(html)) !== null) {
    const fore = html.slice(Math.max(0, m.index - 300), m.index);
    if (/wpml-ls/i.test(m[1] + m[3] + fore) || /norsk/i.test(rensaText(m[4]))) kandidater.push(m[2]);
  }
  const traff = kandidater.map(u => avkoda(u)).find(u => {
    const n = normUrl(u);
    return n.startsWith(prefix) && n.replace(prefix, '').split('/').filter(Boolean).length >= 2;
  });
  return traff ? normUrl(traff) : '';
}

/** Resans namn på en sida: h1, annars og:title/title utan sajtnamnet. */
function hittaSidnamn(html) {
  const h1 = /<h1\b[^>]*>([\s\S]*?)<\/h1>/i.exec(html);
  if (h1 && rensaText(h1[1])) return rensaText(h1[1]);
  const t = hittaMeta(html, 'property', 'og:title') ||
    rensaText((/<title>([\s\S]*?)<\/title>/i.exec(html) || [])[1] || '');
  return t.split(/\s[|–-]\s/)[0].trim();
}

/** Skapar rubrikerna för de norska kolumnerna första gången och förifyller norska destinationsnamn. */
function forberedNorska() {
  const ss = SpreadsheetApp.getActive();
  const resor = ss.getSheetByName(FLIK_RESOR);
  if (String(resor.getRange(1, K.NO_URL).getValue()).trim() !== NO_RUBRIKER[0]) {
    resor.getRange(1, K.NO_URL, 1, 3).setValues([NO_RUBRIKER])
      .setFontWeight('bold').setBackground(resor.getRange(1, 1).getBackground());
  }
  const dest = ss.getSheetByName(FLIK_DEST);
  if (String(dest.getRange(1, 5).getValue()).trim() === 'Norskt namn') return;
  dest.getRange(1, 5).setValue('Norskt namn').setFontWeight('bold')
    .setBackground(dest.getRange(1, 1).getBackground());
  const sista = dest.getLastRow();
  if (sista < 2) return;
  const namn = dest.getRange(2, 1, sista - 1, 1).getValues();
  dest.getRange(2, 5, sista - 1, 1).setValues(namn.map(([d]) => [NORSKA_DESTINATIONER[String(d).trim()] || '']));
}

// Norska namn där de skiljer sig från de svenska. Tom cell = samma namn som på svenska.
const NORSKA_DESTINATIONER = {
  'Amalfikusten': 'Amalfikysten',
  'Amsterdam–Brygge': 'Amsterdam–Brugge',
  'Andalusien': 'Andalucía',
  'Andorra & östra Pyrenéerna': 'Andorra & de østlige Pyreneene',
  'Apulien': 'Puglia',
  'Baskien': 'Baskerland',
  'Bodensjön': 'Bodensjøen',
  'Dolomiterna': 'Dolomittene',
  'Donau Bayern': 'Donau i Bayern',
  'Elbsandstensbergen': 'Elbsandsteingebirge',
  'Florens–Rom': 'Firenze–Roma',
  'Galicien': 'Galicia',
  'Gardasjön': 'Gardasjøen',
  'Kanarieöarna': 'Kanariøyene',
  'Kerkinisjön': 'Kerkinisjøen',
  'Kroatien': 'Kroatia',
  'Kuba': 'Cuba',
  'Lilla Bält': 'Lillebælt',
  'Lombardiet': 'Lombardia',
  'Nya Zeeland': 'New Zealand',
  'Patagonien': 'Patagonia',
  'Piemonte & Ligurien': 'Piemonte & Liguria',
  'Pyrenéerna': 'Pyreneene',
  'Rhen & Nahe': 'Rhinen & Nahe',
  'Sardinien': 'Sardinia',
  'Schweiziska sjöar': 'Sveitsiske innsjøer',
  'Slovenien': 'Slovenia',
  'Svenska västkusten': 'Den svenske vestkysten',
  'Sydtyrolen': 'Sør-Tirol',
  'Zugspitze & Sydtyrolen': 'Zugspitze & Sør-Tirol',
  'Övre Donau': 'Øvre Donau',
};

// ───────────────────────── 5. Publicera kartan på sajten ─────────────────────────

/**
 * Laddar upp kartdatan till cyklavandra.se som
 *   /wp-content/uploads/karta/resor-sv.js och resor-no.js
 * Kartan läser de filerna, så besökarna hämtar ingenting från Google.
 * Kräver kodstycket "cyklavandra-karta-snippet.php" i WordPress och samma nyckel här
 * (menyn "Ställ in nyckel för kartan").
 */
function publiceraKartan(tyst) {
  const nyckel = PropertiesService.getScriptProperties().getProperty('KARTA_NYCKEL');
  if (!nyckel) {
    const text = 'Kartan publicerades inte: nyckel saknas. Välj "Ställ in nyckel för kartan" i menyn.';
    if (tyst !== true) meddela(text);
    return text;
  }
  const delar = [];
  ['sv', 'no'].forEach(sprak => {
    const data = byggKartdata(sprak);
    let svar = '';
    try {
      const r = UrlFetchApp.fetch(`${BAS_URL}/wp-json/cyklavandra/v1/karta`, {
        method: 'post',
        contentType: 'application/json',
        headers: { 'X-Karta-Nyckel': nyckel, 'User-Agent': HTTP_HEADERS['User-Agent'] },
        payload: JSON.stringify({ lang: sprak, nyckel, data }),
        muteHttpExceptions: true,
      });
      const kod = r.getResponseCode();
      if (kod === 200) {
        svar = `${sprak === 'sv' ? 'Svenska' : 'Norska'} kartan: ${data.length} resor publicerade.`;
      } else {
        let msg = r.getContentText().slice(0, 300);
        try { msg = JSON.parse(r.getContentText()).message || msg; } catch (e) {}
        svar = `${sprak === 'sv' ? 'Svenska' : 'Norska'} kartan kunde inte publiceras (HTTP ${kod}): ${msg}` +
          (kod === 401 || kod === 403 ? ' – kontrollera att nyckeln är samma här som i kodstycket i WordPress.' : '') +
          (kod === 404 ? ' – är kodstycket installerat och aktiverat i WordPress?' : '');
      }
    } catch (e) {
      svar = `${sprak === 'sv' ? 'Svenska' : 'Norska'} kartan kunde inte publiceras: ${e.message}`;
    }
    delar.push(svar);
  });
  const text = delar.join('\n');
  console.log(text);
  if (tyst !== true) meddela(text);
  return text;
}

function stallInNyckel() {
  const ui = SpreadsheetApp.getUi();
  const svar = ui.prompt('Nyckel för kartan',
    'Klistra in nyckeln från kodstycket i WordPress (texten inom citattecknen efter CYKLAVANDRA_KARTA_NYCKEL):',
    ui.ButtonSet.OK_CANCEL);
  const nyckel = svar.getResponseText().replace(/['"\s]/g, '');
  if (svar.getSelectedButton() !== ui.Button.OK || !nyckel) return;
  PropertiesService.getScriptProperties().setProperty('KARTA_NYCKEL', nyckel);
  publiceraKartan(); // testa direkt
}

// ───────────────────────── Webbadress för kartan (reserv) ─────────────────────────

/**
 * GET-anrop från kartan (reskarta.html).
 *   ?format=js (rekommenderat)  → "window.TRIPS = [...];" – laddas med <script src="...">
 *   utan format                 → samma lista som JSON
 *   &lang=no                    → norska namn, beskrivningar, destinationer och länkar
 *                                 (svensk text används där norsk saknas)
 * Fälten följer reskartans format: id, title, cat, region, url, img, desc, lat, lng,
 * seasons, difficulty (nivå "1"–"4" från hike/bike-taggarna), dog, train, wine, weekend, family,
 * tags (alla WordPress-taggar).
 */
function doGet(e) {
  const p = (e && e.parameter) || {};
  const sprak = p.lang === 'no' ? 'no' : 'sv';
  const nyckel = sprak === 'no' ? CACHE_NYCKEL + '_no' : CACHE_NYCKEL;
  const cache = CacheService.getScriptCache();
  let json = cache.get(nyckel);
  if (!json) {
    json = JSON.stringify(byggKartdata(sprak));
    try { cache.put(nyckel, json, CACHE_SEK); } catch (err) { /* för stort för cache */ }
  }

  if (p.format === 'js') {
    return ContentService.createTextOutput(`window.TRIPS = ${json};`)
      .setMimeType(ContentService.MimeType.JAVASCRIPT);
  }
  return ContentService.createTextOutput(json).setMimeType(ContentService.MimeType.JSON);
}

const ARSTIDER = ['Vår', 'Sommar', 'Höst', 'Vinter'];
// Nivå 1–4 kommer från WordPress-taggarna hike1–hike4 och bike1–bike4
const NIVAER = [1, 2, 3, 4];
const SPRID = 0.035; // grader – resor på exakt samma punkt sprids ut runt den

function byggKartdata(sprak) {
  const norsk = sprak === 'no';
  const ss = SpreadsheetApp.getActive();
  const resor = ss.getSheetByName(FLIK_RESOR);
  const sista = resor.getLastRow();
  if (sista < 2) return [];

  const koord = new Map();
  const destNorsk = new Map();
  const dest = ss.getSheetByName(FLIK_DEST);
  if (dest.getLastRow() > 1) {
    dest.getRange(2, 1, dest.getLastRow() - 1, 5).getValues()
      .forEach(([d, lat, lng, , no]) => {
        if (!d) return;
        if (lat !== '' && lng !== '') koord.set(String(d).trim(), [Number(lat), Number(lng)]);
        if (String(no || '').trim()) destNorsk.set(String(d).trim(), String(no).trim());
      });
  }
  const text = v => String(v == null ? '' : v).trim();

  const lista = [];
  resor.getRange(2, 1, sista - 1, K.ALLA_TAGGAR).getValues().forEach(r => {
    if (!r[K.URL - 1] || r[K.STATUS - 1] !== 'Aktiv') return;
    const region = String(r[K.DESTINATION - 1]).trim();
    const egen = r[K.EGEN_LAT - 1] !== '' && r[K.EGEN_LNG - 1] !== '';
    const punkt = egen ? [Number(r[K.EGEN_LAT - 1]), Number(r[K.EGEN_LNG - 1])] : koord.get(region);
    if (!punkt) return; // utan koordinater kan resan inte visas
    const taggar = String(r[K.TAGGAR - 1]).split(',').map(t => t.trim()).filter(Boolean);
    const allaTaggar = String(r[K.ALLA_TAGGAR - 1] || '').split(',').map(t => t.trim().toLowerCase()).filter(Boolean);
    const noUrl = text(r[K.NO_URL - 1]);
    const titel = (norsk && text(r[K.NO_NAMN - 1])) || text(r[K.NAMN - 1]);
    lista.push({
      id: lista.length + 1,
      title: titel,
      cat: String(r[K.KATEGORI - 1]).toLowerCase(),
      region: (norsk && destNorsk.get(region)) || region || titel,
      url: (norsk && /^https?:\/\//.test(noUrl)) ? noUrl : String(r[K.URL - 1]),
      img: String(r[K.BILD - 1]),
      desc: (norsk && text(r[K.NO_BESKRIVNING - 1])) || text(r[K.BESKRIVNING - 1]),
      lat: punkt[0],
      lng: punkt[1],
      seasons: ARSTIDER.filter(a => taggar.includes(a)),
      difficulty: NIVAER.filter(n => allaTaggar.includes('hike' + n) || allaTaggar.includes('bike' + n)).map(String),
      dog: taggar.includes('Hund'),
      train: taggar.includes('Tåg'),
      wine: taggar.includes('Vin'),
      weekend: taggar.includes('Långhelg'),
      family: taggar.includes('Barn'),
      tags: allaTaggar,
    });
  });

  // Sprid ut resor som har exakt samma punkt, så att markörerna inte täcker varandra
  const grupper = new Map();
  lista.forEach(t => {
    const nyckel = `${t.lat.toFixed(5)},${t.lng.toFixed(5)}`;
    if (!grupper.has(nyckel)) grupper.set(nyckel, []);
    grupper.get(nyckel).push(t);
  });
  grupper.forEach(g => {
    if (g.length < 2) return;
    g.forEach((t, k) => {
      const v = 2 * Math.PI * k / g.length;
      t.lat = Math.round((t.lat - SPRID * Math.cos(v)) * 1e6) / 1e6;
      t.lng = Math.round((t.lng + SPRID * Math.sin(v)) * 1e6) / 1e6;
    });
  });
  return lista;
}

// ───────────────────────── Hjälpfunktioner ─────────────────────────

function lasRegler() {
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_REGLER);
  const sista = flik.getLastRow();
  if (sista < 2) return [];
  return flik.getRange(2, 1, sista - 1, 2).getValues()
    .filter(([n, d]) => n && d)
    .map(([n, d]) => [String(n).toLowerCase().trim(), String(d).trim()]);
}

function gissaDestination(namn, regler) {
  const n = String(namn).toLowerCase();
  const traff = regler.find(([nyckel]) => n.includes(nyckel));
  return traff ? traff[1] : '';
}

const GISSAD_NOT = 'Destination hämtad från dagsprogrammet – kontrollera';

// Koordinater för destinationer som hittats i dagsprogrammet (fylls i Destinationer)
let GISSADE_KOORD = new Map();

/**
 * Regel först. Annars ett ortnamn ur resans dagsprogram ("Dag 4 – Sorteny"):
 * dagarna i mitten prövas först, sedan övriga dagar. Platsen kontrolleras mot Google Maps.
 */
function sattDestination(rad, regler) {
  const regel = gissaDestination(rad[K.NAMN - 1], regler);
  if (regel) { rad[K.DESTINATION - 1] = regel; return; }
  const plats = platsFranDagsprogram(rad[K.URL - 1]);
  if (plats) {
    rad[K.DESTINATION - 1] = plats.namn;
    GISSADE_KOORD.set(plats.namn, [plats.lat, plats.lng]);
    if (!String(rad[K.ANTECKNING - 1]).trim()) rad[K.ANTECKNING - 1] = `${GISSAD_NOT} (dag ${plats.dag})`;
  }
}

/** Fyller i Lat/Lng i Destinationer för platser som hittats i dagsprogrammet. */
function sparaGissadeKoordinater() {
  if (!GISSADE_KOORD.size) return;
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_DEST);
  const sista = flik.getLastRow();
  if (sista < 2) return;
  const data = flik.getRange(2, 1, sista - 1, 3).getValues();
  data.forEach((r, i) => {
    const k = GISSADE_KOORD.get(String(r[0]).trim());
    if (k && r[1] === '' && r[2] === '') flik.getRange(i + 2, 2, 1, 2).setValues([k]);
  });
}

/** Hämtar resans sida via REST-API:t och letar ortnamn i "Dag N – …"-rubrikerna. */
function platsFranDagsprogram(url) {
  const u = normUrl(url);
  const slug = u.replace(/\/$/, '').split('/').pop();
  if (!slug) return null;
  let html = '';
  const r = hamtaEnMedForsok(`${BAS_URL}/wp-json/wp/v2/pages?slug=${encodeURIComponent(slug)}&_fields=link,content`);
  if (!r.fel) {
    try {
      const sida = JSON.parse(r.html).find(s => normUrl(s.link) === u);
      html = sida && sida.content ? sida.content.rendered : '';
    } catch (e) {}
  }
  const dagar = lasDagsprogram(html);
  if (!dagar.length) return null;

  // Dagarna närmast mitten först; första och sista dagen (resdagar) sist
  const forsta = dagar[0].dag, sista = dagar[dagar.length - 1].dag, mitt = (forsta + sista) / 2;
  const ordning = dagar.slice().sort((a, b) => {
    const kant = d => (d.dag === forsta || d.dag === sista) ? 1 : 0;
    return (kant(a) - kant(b)) || (Math.abs(a.dag - mitt) - Math.abs(b.dag - mitt)) || (a.dag - b.dag);
  });
  for (const d of ordning) {
    const p = forstaKandaPlats(platsKandidater(d.titel));
    if (p) return Object.assign(p, { dag: d.dag });
  }
  return null;
}

/** [{dag, titel}] ur sidans HTML, en per dag, i dagordning. */
function lasDagsprogram(html) {
  const text = avkoda(String(html)
    .replace(/<\/(h\d|p|div|li|tr|td|strong|b)>|<br\s*\/?>/gi, '\n')
    .replace(/<[^>]+>/g, ' '));
  const dagar = new Map();
  text.split('\n').forEach(rad => {
    const m = /^\s*Dag\s+(\d{1,2})\s*[–—:\-.]?\s*(.{2,90})$/i.exec(rad.replace(/\s+/g, ' ').trim());
    if (m && !dagar.has(Number(m[1]))) dagar.set(Number(m[1]), m[2].trim());
  });
  return [...dagar].map(([dag, titel]) => ({ dag, titel })).sort((a, b) => a.dag - b.dag);
}

// Ord i dagrubriker och resenamn som inte är platser
const INTE_PLATS = new Set(('cykla cykling vandra vandring vinvandring vincykling familjevandring familjecykling ' +
  'familjeresa långhelg upptäck njutbar njutbara vacker vackra unik härlig härliga drömresa naturresa fågelresa ' +
  'fågelskådning slottsvandring kustvandring gravel norra södra östra västra mellersta övre nedre genuina ' +
  'storslagna vilda vild fantastiska fantastiskt lyxig båtkryssning tre fyra två den det de en ett med och ' +
  'guidad guidade självguidad elcykel ledig vilodag vila hemresa hemfärd ankomst avresa välkommen transfer ' +
  'dag utflykt båttur frukost middag lunch resa slutet start avslutning heldag halvdag rundtur tågresa ' +
  'transferdag egen valfri fri sista första kvällen morgon').split(' '));
const AKTIVITET = /(cykling|cykla|vandring|vandra|resa|resor|turen|tur|skådning|kryssning|helg|semester|dag)$/i;
const BINDEORD = new Set(['de', 'del', 'della', 'di', 'da', 'do', 'dos', 'la', 'le', 'les', 'am', 'an', 'im', 'sur', 'y', "d'"]);
const PREPOSITIONER = new Set(['i', 'på', 'längs', 'vid', 'runt', 'kring', 'genom', 'från', 'till', 'över', 'mot', 'mellan']);

/**
 * Ortnamn i en text: följder av ord med stor bokstav ("Vall d'Incles", "St. Moritz").
 * Målet för en etapp ("A till B") prövas före startpunkten.
 */
function platsKandidater(text) {
  const huvud = String(text || '').replace(/[’`]/g, "'");
  const ord = huvud.split(/\s+/).filter(Boolean);
  const versal = w => /^[A-ZÅÄÖÆØÉÈÁÀÜÑČŠŽ]/.test(w);
  const kandidater = [];
  for (let i = 0; i < ord.length; i++) {
    const w = ord[i].replace(/[,;:!?()]/g, '');
    if (!versal(w) || INTE_PLATS.has(w.toLowerCase()) || AKTIVITET.test(w)) continue;
    const delar = [w];
    let j = i + 1;
    while (j < ord.length) {
      const n = ord[j].replace(/[,;:!?()]/g, '');
      if (versal(n) && !INTE_PLATS.has(n.toLowerCase())) { delar.push(n); j++; continue; }
      if ((BINDEORD.has(n.toLowerCase()) || /^d'/i.test(n)) && j + 1 < ord.length && versal(ord[j + 1])) { delar.push(n); j++; continue; }
      if (/^d'[A-ZÅÄÖ]/.test(n)) { delar.push(n); j++; continue; }
      break;
    }
    const fore = [ord[i - 1], ord[i - 2]].filter(Boolean).map(x => x.toLowerCase());
    const efter = ord[j] || '';
    kandidater.push({
      text: delar.join(' ').replace(/[.,]$/, ''),
      mal: fore.includes('till'),
      prep: fore.some(x => PREPOSITIONER.has(x)),
      genitiv: /[^s]s$/.test(delar[delar.length - 1]) && /^[a-zåäö]/.test(efter),
    });
    i = j - 1;
  }
  return kandidater.sort((a, b) => (Number(b.mal) - Number(a.mal)) || (Number(b.prep) - Number(a.prep)));
}

/** Första kandidaten som Google Maps hittar: {namn, lat, lng}, annars null. */
function forstaKandaPlats(kandidater) {
  const geo = Maps.newGeocoder().setLanguage('sv');
  const OGILTIG = ['route', 'street_address', 'premise', 'postal_code', 'subpremise'];
  for (const k of kandidater) {
    const varianter = k.genitiv ? [k.text.replace(/s$/, ''), k.text] : [k.text];
    for (const v of varianter) {
      if (v.length < 3) continue;
      let svar = null;
      try { svar = geo.geocode(v); } catch (e) { svar = null; }
      const r = svar && svar.status === 'OK' && svar.results[0];
      Utilities.sleep(100);
      if (r && !(r.types || []).some(t => OGILTIG.includes(t))) {
        const loc = r.geometry.location;
        return { namn: v, lat: Math.round(loc.lat * 10000) / 10000, lng: Math.round(loc.lng * 10000) / 10000 };
      }
    }
  }
  return null;
}

// Taggar som kartans filter bygger på. Saknas någon i fliken Taggar läggs den till.
const STANDARDTAGGAR = [['tag-barn', 'Barn']];

function sakerstallTaggar() {
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_TAGGAR);
  const sista = flik.getLastRow();
  const finns = new Set(sista > 1 ? flik.getRange(2, 1, sista - 1, 1).getValues().map(r => String(r[0]).trim()) : []);
  const nya = STANDARDTAGGAR.filter(([klass]) => !finns.has(klass));
  if (nya.length) flik.getRange(sista + 1, 1, nya.length, 2).setValues(nya);
}

function lasTaggar() {
  const flik = SpreadsheetApp.getActive().getSheetByName(FLIK_TAGGAR);
  const karta = new Map();
  const sista = flik.getLastRow();
  if (sista < 2) return karta;
  flik.getRange(2, 1, sista - 1, 2).getValues()
    .forEach(([klass, namn]) => { if (klass) karta.set(String(klass).trim(), String(namn || klass).trim()); });
  return karta;
}

function hamtning(url) {
  return { url, headers: HTTP_HEADERS, muteHttpExceptions: true, followRedirects: true };
}

/**
 * Hämtar flera sidor, några åt gången, med nya försök vid nätverksfel.
 * Returnerar [{html, fel}] i samma ordning som urls. fel är '' när det gick bra.
 * ettForsok = true: inga nya försök (för långsamma sidor – hellre nästa körning).
 */
function hamtaSidor(urls, ettForsok) {
  const BATCH = 5;
  const resultat = new Array(urls.length);
  for (let s = 0; s < urls.length; s += BATCH) {
    const del = urls.slice(s, s + BATCH);
    let svar = null;
    try { svar = UrlFetchApp.fetchAll(del.map(hamtning)); } catch (e) { svar = null; }
    del.forEach((url, j) => {
      const r = svar && svar[j];
      if (r && r.getResponseCode() === 200) {
        resultat[s + j] = { html: r.getContentText(), fel: '' };
      } else if (r && r.getResponseCode() < 500 && r.getResponseCode() !== 429) {
        resultat[s + j] = { html: '', fel: `HTTP ${r.getResponseCode()}` };
      } else if (ettForsok) {
        resultat[s + j] = { html: '', fel: r ? `HTTP ${r.getResponseCode()}` : 'ingen kontakt' };
      } else {
        resultat[s + j] = hamtaEnMedForsok(url);
      }
    });
    if (s + BATCH < urls.length) Utilities.sleep(300);
  }
  return resultat;
}

function hamtaEnMedForsok(url) {
  let senast = '';
  for (let forsok = 1; forsok <= 3; forsok++) {
    try {
      const r = UrlFetchApp.fetch(url, {
        headers: HTTP_HEADERS, muteHttpExceptions: true, followRedirects: true,
      });
      const kod = r.getResponseCode();
      if (kod === 200) return { html: r.getContentText(), fel: '' };
      senast = `HTTP ${kod}`;
      if (kod < 500 && kod !== 429) break;
    } catch (e) {
      senast = e.message;
    }
    Utilities.sleep(1500 * forsok);
  }
  return { html: '', fel: senast };
}

function foljOmdirigering(url) {
  try {
    const r = UrlFetchApp.fetch(url, { headers: HTTP_HEADERS, muteHttpExceptions: true, followRedirects: false });
    const h = r.getHeaders();
    const loc = h.Location || h.location;
    return loc ? String(Array.isArray(loc) ? loc[0] : loc) : '';
  } catch (e) {
    return '';
  }
}

function normUrl(url) {
  let u = String(url || '').trim();
  if (!u) return '';
  if (u.startsWith('/')) u = BAS_URL + u;
  u = u.split('#')[0].split('?')[0];
  return u.replace(/\/+$/, '') + '/';
}

function rensaText(html) {
  return avkoda(String(html).replace(/<[^>]+>/g, ' ')).replace(/\s+/g, ' ').trim();
}

function avkoda(s) {
  return String(s)
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(Number(n)))
    .replace(/&#x([0-9a-f]+);/gi, (_, n) => String.fromCharCode(parseInt(n, 16)))
    .replace(/&amp;/g, '&').replace(/&quot;/g, '"').replace(/&#039;|&apos;/g, "'")
    .replace(/&lt;/g, '<').replace(/&gt;/g, '>').replace(/&nbsp;/g, ' ')
    .replace(/&ndash;/g, '–').replace(/&mdash;/g, '—');
}

function meddela(text) {
  try { SpreadsheetApp.getUi().alert(text); } catch (e) { console.log(text); }
}
