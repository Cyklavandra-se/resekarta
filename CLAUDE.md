# Resekarta – WordPress-plugin för cyklavandra.se

Det här repot ska bli en WordPress-plugin som visar alla resor från cyklavandra.se på en
interaktiv karta, på alla språk som finns i WPML. Pluginen ersätter dagens lösning:
ett Google-kalkylark med Apps Script, ett kodstycke i Code Snippets och en kartfil i uploads.

Den här filen är kravspecifikationen. Läs den hela innan du börjar, och följ faserna längst ner.

## Namn

| Vad | Namn |
| --- | --- |
| Repository | `Cyklavandra-se/resekarta` |
| Pluginnamn (visas i WordPress) | **Cykla & Vandra – Resekarta** (som de andra egna tilläggen; i menyn Cykla&Vandra heter den bara Resekarta) |
| Beskrivning | Interaktiv karta över Cykla & Vandras resor på alla språk |
| Mapp, slug och text domain | `resekarta` (huvudfil `resekarta.php`) |
| Prefix i kod | `cvrk_` för funktioner, options och meta; namespace/klasser `CV_Resekarta` |
| Kortkod | `[resekarta]` (oförändrad) |

## Om beställaren och arbetssättet

- Beställare: Pär, Cykla & Vandra (svenska/norska kunder, guidade och självguidade cykel- och vandringsresor).
- Skriv till Pär på **svenska**. Allt som syns i WordPress-admin ska vara på svenska.
  Koden och kodkommentarerna får vara på engelska eller svenska, men var konsekvent.
- Pär är inte utvecklare. Ge honom korta, konkreta steg (vad han klickar på, vilken fil han laddar upp),
  och testa så mycket som möjligt själv innan du lämnar över.
- Repot ligger i GitHub-organisationen Cyklavandra-se (github.com/Cyklavandra-se). Där finns redan
  andra plugins, till exempel AI-sök och Kanalpublicering. Följ samma upplägg som de om du kan se dem.
- Sajten körs på ett delat webbhotell hos one.com. Ingen SSH, inget WP-CLI på servern. Pluginen
  installeras som zip via Tillägg → Lägg till nytt → Ladda upp.

## Sajten i korthet

- WordPress med temat **Avada** (sidbyggare), **WPML** (svenska som huvudspråk; norska under `/no/`,
  danska `/da/`, finska `/fi/`, engelska `/en/` – alla upplagda i WPML), **AIOSEO** (SEO-beskrivningar),
  **Code Snippets**, **The Events Calendar** (guidade resedatum), Contact Form 7.
- Varje resa är en **WordPress-sida** (post type `page`), inte ett inlägg. Exempel:
  - Guidade: `https://cyklavandra.se/vandra/guidade/andorra/`
  - Självguidade cykelresor: `https://cyklavandra.se/cykla/cykla-pa-egen-hand/rioja/`
  - Självguidade vandringar: `https://cyklavandra.se/vandra/pa-egen-hand/andorra/`
  - Övriga (fågelresor m.m., räknas som Guidad): `https://cyklavandra.se/ovrigt/kuba-fagel/`
  - Bygg inte på adresserna – en resa kan ligga under en annan förälder än sin lista. Listorna avgör.
- Resorna listas på tre listsidor (byggda med tillägget Content Views, 9 resor per sida, `?_page=N`):
  - Guidad: `/vandra/guidade/` · Cykel: `/cykla/cykla-pa-egen-hand/` · Vandring: `/vandra/pa-egen-hand/`
  - En resa som står på både cykel- och vandringslistan räknas som **Cykel**.
- Resesidorna har **taggar** (WordPress-taggar på sidor). Viktiga kortnamn (slug):
  - Nivå: `hike1`–`hike4` och `bike1`–`bike4` (i november 2026 hade alla aktiva resor minst en,
    utom "Cykla längs La Garonne"; resor utan nivåtagg ska ändå visas, bara utan nivå)
  - Säsong: `var`, `sommar`, `host`, `vinter`
  - Övrigt: `hund` (hundvänligt), `tag` (tåg), `vin`, `langhelg` (långhelg), `barn` (familjeresa)
  - Dessutom många andra (land, region, månad, `sjalvguidad`, `costa-brava` …) – alla ska följa med
    till kartan för urval med `tag=`.
  - OBS: WordPress `class_list` använder taggens id i stället för kortnamnet när kortnamnet bara är
    siffror (t.ex. `2027`) eller bara tecken som å/ä/ö. Använd taggarnas `slug` direkt, inte CSS-klasser.
- Dagsprogrammet på resesidorna står som rubriker "Dag 1 – Välkommen till Pyrenéerna", "Dag 2 – Vall d'Incles"
  (ibland "Dag 2: A till B") under rubriken "Tidsplan".

## Dagens lösning (referens – ska ersättas)

Allt finns i `referens/` och `data/`:

| Fil | Vad det är |
| --- | --- |
| `referens/Kod.gs` | Apps Script i kalkylarket. Hittar resor via listsidorna, hämtar bild/beskrivning/taggar via REST, matchar översättningar, gissar destination, geokodar, publicerar kartdata, nattkörning med mejl. **Logiken ska portas till PHP.** |
| `referens/reskarta.html` | Kartan (Leaflet + MapLibre/OpenFreeMap-vektorkarta, svensk/norsk text, alla filter och URL-parametrar). **Ska återanvändas nästan oförändrad.** |
| `referens/cyklavandra-karta-snippet.php` | Kodstycket i Code Snippets: tar emot kartdata (REST + hemlig nyckel) och kortkoden `[resekarta]`. **Kortkoden ska finnas kvar med samma namn och attribut.** |
| `data/resor.csv` | Fliken Resor: 166 resor (160 Aktiv, 6 Borttagen). Pärs manuella arbete: Destination, egna kartpunkter (49 st), Status, Anteckning. |
| `data/destinationer.csv` | 111 destinationer med Lat/Lng och norskt namn. |
| `data/destinationsregler.csv` | 124 regler: nyckelord i resans namn → destination (översta träffen vinner). |
| `data/taggar.csv` | Gamla taggmappningen (CSS-klass → namn). Nivåerna latt/medel/svar är ersatta av hike/bike1–4. |
| `data/destinationsnamn.json` | Destinationsnamn på no/da/fi/en där de skiljer sig från svenska (färdigöversatta). |

Det mesta i dagens lösning finns bara för att komma runt att Apps Script inte kör inne i WordPress:
gissning av översättningar via utvald bild och språkväljaren, REST-anrop mot den egna sajten, hemlig nyckel
för uppladdning, Google-adress som reserv. **Allt det ska bort.** Inne i WordPress frågar du WPML direkt.

## Mål

1. Kartan visar alla aktiva resor på svenska, norska, danska, finska och engelska, med rätt namn,
   SEO-beskrivning, länk och destinationsnamn på varje språk. Saknas en översättning visas den svenska.
2. Ett nytt språk i WPML ska följa med automatiskt. Bara kartans knapptexter behöver läggas till.
3. Pär redigerar allt i WordPress: en ruta på varje resesida och en översiktssida i adminpanelen.
4. Nya resor kommer med automatiskt (när de publiceras eller i nattkörningen), får kategori och
   destination av sig själva, och Pär får ett mejl om dem.
5. Allt som Pär gjort i kalkylarket flyttas över en gång (import), utan att något går förlorat.
6. Befintliga sidor med `[resekarta …]` fungerar utan ändring när pluginen ersätter kodstycket.

## Funktioner

### 1. Vilka sidor är resor

- Börja med att undersöka hur Content Views-listorna väljer sina sidor (förälder, taggar, kategori,
  manuellt urval). Om urvalet går att återskapa med `WP_Query` – gör det. Annars: läs listsidornas
  egen HTML via intern rendering eller `wp_remote_get` (som `hamtaNyaResor` i `Kod.gs`, inklusive
  sidnumrering och "Cykel vinner").
- Kategori sätts automatiskt (Guidad/Cykel/Vandring) men kan ändras manuellt per resa.
- En resa som inte längre finns på listorna eller avpubliceras döljs från kartan automatiskt
  (motsvarar status Borttagen) och kommer tillbaka av sig själv om den läggs upp igen.
- Manuellt val per resa: **Auto / Dölj alltid / Visa alltid**. Dölj motsvarar dagens "Dold".
- Bara den svenska originalsidan är "resan". Översättningarna hämtas från WPML.

### 2. Data per resa (post meta på den svenska sidan)

| Fält | Beskrivning |
| --- | --- |
| Kategori | Guidad / Cykel / Vandring (auto, kan ändras) |
| Synlighet | Auto / Dölj / Visa |
| Destination | Koppling till en destination (se nedan) |
| Egen punkt | Lat/Lng som vinner över destinationens punkt (för att flytta en enskild resa) |
| Kontrollera | Flagga + text när något gissats, t.ex. "Destination hämtad från dagsprogrammet (dag 4)" |

Namn, bild (utvald bild), SEO-beskrivning (AIOSEO) och taggar läses alltid direkt från sidan –
de sparas inte som kopior.

### 3. Destinationer

- En icke-publik taxonomi (t.ex. `cv_destination`) eller egen tabell. Per destination:
  namn (svenska), Lat, Lng, sökord för geokodning, och namn per språk (`no`, `da`, `fi`, `en` …).
  Tomt namn på ett språk = svenska namnet används.
- Om du väljer taxonomi: registrera den så att WPML **inte** översätter den (en uppsättning destinationer),
  och lagra namnen per språk som term meta. Annars blir det dubbla termer per språk.
- Destinationer utan koordinater geokodas (se 6). Pär måste kunna ändra Lat/Lng manuellt.
- Destinationsnamnen på andra språk förifylls från `data/destinationsnamn.json` vid import.

### 4. Destinationsregler

- Lista med nyckelord → destination. Den översta regeln vars nyckelord finns i resans svenska namn
  (skiftlägesokänsligt) vinner. Ordningen ska gå att ändra.
- Används bara när en resa saknar destination.

### 5. Automatisk destination för nya resor

I den här ordningen, bara om destination saknas:
1. Destinationsreglerna.
2. **Dagsprogrammet**: läs rubrikerna "Dag N – …" / "Dag N: …" i sidans innehåll. Pröva dagarna i mitten
   först (på en 8-dagarsresa: 4, 5, 3, 6 …), första och sista dagen sist. Plocka ortnamn ur rubriken
   (ord med stor bokstav; vid "A till B" prövas B först; hoppa över ord som Ledig, Hemresa, Ankomst,
   Välkommen, Transfer, Avresa). Första namn som geokodningen hittar blir destinationen, med koordinater.
   Logiken finns färdig i `Kod.gs`: `platsFranDagsprogram`, `lasDagsprogram`, `platsKandidater`,
   `forstaKandaPlats`.
3. Annars: ingen destination, resan visas inte på kartan, och den flaggas.
- Gissade destinationer flaggas "Kontrollera" och syns tydligt i översikten och i mejlet.

### 6. Geokodning

- Använd OpenStreetMap Nominatim (gratis, ingen nyckel): max 1 anrop per sekund, en riktig `User-Agent`
  med kontaktadress från inställningarna, cacha svaren. Inga massanrop – bara nya destinationer.
- Gör geokodningen så att den går att byta (t.ex. till Google Geocoding med nyckel) via en inställning.

### 7. Översättningar (WPML)

- Aktiva språk: `apply_filters( 'wpml_active_languages', null, … )`. Svenska är huvudspråk.
- Översatt sida: `apply_filters( 'wpml_object_id', $post_id, 'page', false, $lang )`.
  Därifrån: titel, permalänk på språket och AIOSEO-beskrivningen (ta reda på hur AIOSEO lagrar den –
  tabellen `wp_aioseo_posts` eller dess API – och fall tillbaka på utdrag om den saknas).
- Saknas översättning: svensk titel, beskrivning och länk.
- Kontrollera WPML:s språkkod för norska (`no` eller `nb`). Kartans datafiler och URL-prefix använder `no`.

### 8. Kartdata

- En JSON-lista per språk med samma fält som idag (se `byggKartdata` i `Kod.gs` och `doGet`-kommentaren):
  `id, title, cat, region, url, img, desc, lat, lng, seasons, difficulty, dog, train, wine, weekend, family, tags`
  - `cat`: `guidad` / `cykel` / `vandring`
  - `seasons`: delmängd av `["Vår","Sommar","Höst","Vinter"]` från taggarna `var/sommar/host/vinter`
  - `difficulty`: `["1"]`…`["4"]` från `hike1–4` / `bike1–4` (en resa kan ha flera)
  - `dog`/`train`/`wine`/`weekend`/`family`: taggarna `hund`/`tag`/`vin`/`langhelg`/`barn`
  - `tags`: alla taggars slug, gemener
  - Resor med exakt samma punkt sprids ut i en liten cirkel (0,035°), som i `byggKartdata`.
- Kartdatan byggs om när en resesida eller dess översättning sparas, när något ändras i pluginens
  adminsidor, och i nattkörningen. Spara den som statiska filer (`uploads/karta/resor-{lang}.js` med
  `window.TRIPS = …;`, samma namn som idag) **och/eller** servera via en cachad REST-route. Statiska filer
  är snabbast på one.com.

**Beslut (fas 2):** tillägget skriver sina filer i `uploads/resekarta/` (`resor-{lang}.js`, `karta.html`) i
stället för `uploads/karta/`, så att kalkylarkets filer kan ligga kvar och jämföras under övergången
(fliken Karta). Kartsidan byggs från `assets/map/karta.html` med biblioteken i `assets/map/vendor/`.
Tilläggets `[resekarta]` slås på under Inställningar; `[resekarta_test]` fungerar alltid.

### 9. Kartan och kortkoden

- Kartan i `referens/reskarta.html` återanvänds. Den visas i en iframe (isolerar den från Avadas CSS).
  Lägg den i pluginen och servera den därifrån. Bunta gärna Leaflet, MapLibre och tilläggen lokalt i
  stället för unpkg (snabbare, inga externa skript).
- Kortkod `[resekarta]` med samma attribut som idag, så att befintliga sidor fungerar:
  `tag` (`+` = alla, `,` = någon), `kat`, `arstid`, `niva` (1–4), `filters="no"`, `hojd`.
  Se kommentaren i `referens/cyklavandra-karta-snippet.php`.
- Kortkoden skickar språket explicit (`wpml_current_language`) i iframe-adressen, och ett versionsnummer
  som ändras när kartfilen eller kartdatan ändras (så att webbläsare inte visar gamla versioner).
- URL-parametrarna i sidans egen adress (`?tag=…&kat=…&arstid=…&niva=…&filters=no`) ska fortsätta fungera.
- Kartan ska stödja alla WPML-språk. Lägg till knapptexter för `da`, `fi` och `en` (nedan). Ett språk
  utan knapptexter använder engelska. Ortnamnen i vektorkartan ska visas på språket (`name:da`, `name:fi`,
  `name:en`; för norska `name:nb`, `name:no`), med lokalt namn som reserv – se `NAMN_FALT` i kartan.
- Förvalen (`kat`, `arstid`) ska förstå orden på alla språk (t.ex. `cycling`, `pyöräily`, `spring`,
  `forår`, `kevät`), som de redan gör för norska.
- Standardstil: Ljus färgad (OpenFreeMap `bright`). Övrigt beteende som idag.

Knapptexter för de nya språken (samma nycklar som `TEXTER.sv` i kartan). Visa dem för Pär innan
du publicerar – han kan vilja ändra ordval:

```js
da: {
  sidtitel: 'Vores rejser – interaktivt kort | Cykla & Vandra',
  guidad: 'Guidet', cykel: 'Cykel', vandring: 'Vandring',
  chip_guidad: 'Guidede', chip_cykel: 'Cykling', chip_vandring: 'Vandring',
  alla_arstider: 'Alle årstider', 'Vår': 'Forår', 'Sommar': 'Sommer', 'Höst': 'Efterår', 'Vinter': 'Vinter',
  alla_nivaer: 'Alle niveauer', niva: 'Niveau', niva1: 'Niveau 1', niva2: 'Niveau 2', niva3: 'Niveau 3', niva4: 'Niveau 4',
  hund: 'Hundevenligt', tag: 'Tog', vin: 'Vin', langhelg: 'Forlænget weekend', familj: 'Familierejser',
  rensa: 'Nulstil filtre', rensa_titel: 'Nulstil alle filtre',
  vara_resor: 'Vores rejser', resor: 'rejser',
  aterstall: 'Nulstil visning', aterstall_titel: 'Vis alle rejser',
  las_mer: 'Læs mere', svarighetsgrad: 'Sværhedsgrad', basta_tid: 'Bedste tid', hund_titel: 'Hunde er velkomne',
  karta: 'Kort over vores rejser', f_kat: 'Filtrer kategorier', f_ars: 'Vælg årstid',
  f_niva: 'Vælg sværhedsgrad', f_ovr: 'Andre filtre',
  stil_positron: 'Lys', stil_liberty: 'Farvet', stil_bright: 'Lys farvet', stil_klassisk: 'Klassisk',
  sprak: 'Sprog', kartstil: 'Kortstil',
},
fi: {
  sidtitel: 'Matkamme – interaktiivinen kartta | Cykla & Vandra',
  guidad: 'Opastettu', cykel: 'Pyöräily', vandring: 'Vaellus',
  chip_guidad: 'Opastetut', chip_cykel: 'Pyöräily', chip_vandring: 'Vaellus',
  alla_arstider: 'Kaikki vuodenajat', 'Vår': 'Kevät', 'Sommar': 'Kesä', 'Höst': 'Syksy', 'Vinter': 'Talvi',
  alla_nivaer: 'Kaikki tasot', niva: 'Taso', niva1: 'Taso 1', niva2: 'Taso 2', niva3: 'Taso 3', niva4: 'Taso 4',
  hund: 'Koiraystävällinen', tag: 'Juna', vin: 'Viini', langhelg: 'Pitkä viikonloppu', familj: 'Perhematkat',
  rensa: 'Tyhjennä suodattimet', rensa_titel: 'Tyhjennä kaikki suodattimet',
  vara_resor: 'Matkamme', resor: 'matkaa',
  aterstall: 'Palauta näkymä', aterstall_titel: 'Näytä kaikki matkat',
  las_mer: 'Lue lisää', svarighetsgrad: 'Vaativuus', basta_tid: 'Paras aika', hund_titel: 'Koirat ovat tervetulleita',
  karta: 'Kartta matkoistamme', f_kat: 'Suodata luokat', f_ars: 'Valitse vuodenaika',
  f_niva: 'Valitse vaativuus', f_ovr: 'Muut suodattimet',
  stil_positron: 'Vaalea', stil_liberty: 'Värikäs', stil_bright: 'Vaalea värikäs', stil_klassisk: 'Klassinen',
  sprak: 'Kieli', kartstil: 'Karttatyyli',
},
en: {
  sidtitel: 'Our tours – interactive map | Cykla & Vandra',
  guidad: 'Guided', cykel: 'Cycling', vandring: 'Walking',
  chip_guidad: 'Guided', chip_cykel: 'Cycling', chip_vandring: 'Walking',
  alla_arstider: 'All seasons', 'Vår': 'Spring', 'Sommar': 'Summer', 'Höst': 'Autumn', 'Vinter': 'Winter',
  alla_nivaer: 'All levels', niva: 'Level', niva1: 'Level 1', niva2: 'Level 2', niva3: 'Level 3', niva4: 'Level 4',
  hund: 'Dog-friendly', tag: 'Train', vin: 'Wine', langhelg: 'Long weekend', familj: 'Family tours',
  rensa: 'Clear filters', rensa_titel: 'Clear all filters',
  vara_resor: 'Our tours', resor: 'tours',
  aterstall: 'Reset view', aterstall_titel: 'Show all tours',
  las_mer: 'Read more', svarighetsgrad: 'Difficulty', basta_tid: 'Best time', hund_titel: 'Dogs welcome',
  karta: 'Map of our tours', f_kat: 'Filter categories', f_ars: 'Choose season',
  f_niva: 'Choose difficulty', f_ovr: 'Other filters',
  stil_positron: 'Light', stil_liberty: 'Colourful', stil_bright: 'Light colourful', stil_klassisk: 'Classic',
  sprak: 'Language', kartstil: 'Map style',
},
```

### 10. Adminpanelen (på svenska)

Meny **Resekarta** med:
- **Översikt**: tabell med alla resor – namn (länk till redigering), kategori, destination, nivå, synlighet,
  "på kartan" ja/nej och varför inte (saknar destination, saknar koordinater, dold, borttagen),
  språktäckning (vilka språk som har översättning), Kontrollera-flagga. Filter för "behöver åtgärd".
  Snabbredigering av destination, kategori, synlighet och egen punkt. Knapp **Bygg om kartan nu**.
- **Destinationer**: lista med namn, Lat/Lng, namn per språk, antal resor; lägga till, ändra, ta bort.
  Gärna en liten karta för att se och dra punkten.
- **Destinationsregler**: redigerbar lista i ordning.
- **Inställningar**: mejladress för notiser, kontaktadress för geokodning, standard kartstil.
- **Import**: engångsimport av CSV-filerna i `data/` (se Flytten).
- En **ruta på resesidans redigeringsvy** (bara svenska sidor): kategori, synlighet, destination
  (välj/skapa), egen punkt, Kontrollera-flagga med knapp "Markera som kontrollerad".

### 11. Nattkörning och mejl

- WP-Cron ungefär kl 03 (sajtens tidszon Europe/Madrid): hitta nya resor, sätt kategori och destination,
  geokoda nya destinationer, bygg om kartdatan.
- Mejl (wp_mail) bara när något hänt: nya resor (namn, kategori, destination, "gissad – kontrollera",
  länk) eller fel. Inget mejl tysta nätter. Mallen finns i `nattligKorning` i `Kod.gs`.
- One.com: WP-Cron körs bara vid besök. Det räcker, men nämn för Pär att en riktig cron kan sättas upp
  i one.com:s kontrollpanel om han vill.

### 12. Säkerhet och kvalitet

- Kapabilitet `edit_pages` för redigering, `manage_options` för inställningar och import.
- Nonces på alla formulär och AJAX, sanering in, escaping ut. Inga hemliga nycklar behövs.
- Prefix eller namespace på allt (`cvrk_`, `CV_Resekarta`, se Namn). Inga globala funktioner utan prefix.
- Avinstallation tar inte bort data om inte Pär uttryckligen väljer det.

## Flytten från kalkylarket

1. Importera `data/destinationer.csv` (Destination, Lat, Lng, Sökord för karta, Norskt namn) och
   namnen i `data/destinationsnamn.json` (no/da/fi/en).
2. Importera `data/destinationsregler.csv` i samma ordning.
3. Importera `data/resor.csv` – matcha på kolumnen URL mot den svenska sidan (`url_to_postid`):
   - Kategori, Destination, Lat/Lng (egen punkt) → resans fält.
   - Status `Dold` → Synlighet Dölj. `Aktiv`/`Borttagen` → Auto.
   - Anteckning som börjar med "Destination gissad" eller "Destination hämtad" → Kontrollera-flagga.
   - Övriga kolumner (namn, bild, beskrivning, taggar, norska texter) importeras inte – de läses från sidorna.
4. Visa en rapport: hur många som matchades, vilka URL:er som inte hittades, vilka destinationer som saknas.
5. Importen ska gå att köra igen utan dubbletter.

## Övergången på sajten

Kodstycket i Code Snippets registrerar också `[resekarta]`. De får inte vara aktiva samtidigt.
1. Installera pluginen, kör importen, kontrollera översikten.
2. Jämför pluginens kartdata med dagens filer i `uploads/karta/` (antal resor per språk, stickprov).
3. Avaktivera kodstycket för kartan i Code Snippets (det med `CYKLAVANDRA_KARTA_NYCKEL` och `add_shortcode( 'resekarta'`),
   aktivera sedan pluginens kortkod. Pluginen ska själv varna i adminpanelen om kortkoden redan är registrerad av något annat.
4. Kontrollera några sidor på varje språk.
5. Pär stänger av den nattliga körningen i kalkylarket (menyn Cyklavandra → Slå av nattlig körning).
   Kalkylarket och Apps Script-webbappen får finnas kvar en tid som reserv.

## Faser

Gör en fas i taget, testa den, och låt Pär prova innan nästa. Committa ofta med tydliga meddelanden.

1. **Grund**: pluginskelett, adminmeny, inställningar, datamodell (resefält, destinationer, regler),
   identifiering av resesidor, import från CSV, översiktssida (bara läsning). Klart när importen visar
   samma 160 aktiva resor som kalkylarket och alla 49 egna punkter finns.
2. **Kartdata och karta**: bygg JSON per språk via WPML, kortkoden, kartan i pluginen med fem språk.
   Klart när kartan på svenska och norska visar samma resor som idag, och danska/finska/engelska fungerar.
3. **Redigering**: rutan på resesidan, snabbredigering i översikten, destinationer och regler.
4. **Automatik**: nya resor, destination via regler och dagsprogram, geokodning, nattkörning, mejl.
5. **Övergång**: checklistan ovan, uppstädning, README för Pär.

## Test

- Sätt upp en lokal WordPress (t.ex. `wp-env` eller Docker) med WPML-liknande testdata om WPML inte
  finns tillgängligt lokalt – kapsla in alla WPML-anrop i en egen klass så att de går att ersätta i test.
- PHPUnit för den rena logiken: identifiering av resor, regler, dagsprogram/ortnamn, nivåer och taggar,
  kartdatans format, importen.
- Kör kartan i en riktig webbläsare (t.ex. Playwright) på alla språk och kontrollera filter, förval,
  `tag=`-urval och popup-rutor.
- Kör `php -l` på alla filer och testa med samma PHP-version som one.com kör (fråga Pär eller läs av
  i Verktyg → Webbplatshälsa).

## Driftsättning

- Bygg en zip (`resekarta.zip`) med bara det som behövs (inga test- eller referensfiler) via ett skript
  eller en GitHub Action på taggade versioner.
- Pär installerar via Tillägg → Lägg till nytt → Ladda upp. Ange alltid versionsnummer i pluginhuvudet
  och en kort ändringslogg.
