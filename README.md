# Resekarta – bruksanvisning

**Cykla & Vandra – Resekarta** är tillägget som visar alla resor på cyklavandra.se på en interaktiv
karta, på alla språk som finns i WPML (svenska, norska, danska, finska, engelska – och nya språk av sig själva).
Det ersätter kalkylarket "Cykla & Vandra - resedatabas karta", dess Apps Script och kodstycket i Code Snippets.

Allt finns under **Cykla&Vandra → Resekarta** i WordPress, med flikarna Översikt, Karta, Destinationer,
Destinationsregler, Inställningar, Import och Övergång.

---

## Så fungerar det

1. **Vilka resor som finns** avgörs av de tre listsidorna – Guidade (`/vandra/guidade/`), Cykla på egen hand
   (`/cykla/cykla-pa-egen-hand/`) och Vandra på egen hand (`/vandra/pa-egen-hand/`). En sida som står på en
   lista är en resa. Står den på både cykel- och vandringslistan räknas den som Cykel.
2. **Namn, bild, beskrivning och taggar** läses alltid direkt från resans sida (titeln, utvald bild,
   AIOSEO-beskrivningen och WordPress-taggarna). På andra språk används översättningen i WPML – saknas den
   visas den svenska texten.
3. **Det du sköter i Resekarta** är det som inte står på sidan: kategori, synlighet, destination och ibland en
   egen punkt på kartan.
4. **Kartan byggs om av sig själv** när du sparar en resa eller en översättning, när du ändrar något i
   Resekarta, och varje natt.

Taggarna styr filtren på kartan: `hike1`–`hike4`/`bike1`–`bike4` (nivå), `var`, `sommar`, `host`, `vinter`
(årstid), `hund`, `tag` (tåg), `vin`, `langhelg` och `barn` (familjeresa). Alla taggar kan användas för urval
med `tag=` (se Kortkoden nedan).

---

## Det vanliga arbetet

### Översikt
En rad per resa: kategori, destination, nivå, synlighet, om resan är på kartan (och varför inte), vilka språk
som har översättning, och Kontrollera-flaggan.

- **Behöver åtgärd** visar resor som är flaggade eller som borde synas men inte kan (saknar destination eller
  koordinater). Börja där.
- **Snabbredigera** (håll musen över en resa): ändra kategori, synlighet, destination och egen punkt direkt
  i tabellen.
- **Sök efter nya resor nu** gör samma sak som nattkörningen (utan mejl).
- **Bygg om kartan nu** behövs sällan – kartan byggs om av sig själv.

### Rutan Resekarta på resans sida
När du redigerar en svensk resesida finns rutan **Resekarta** i högerspalten:

- **Kategori** – Auto (enligt listsidorna) eller Guidad/Cykel/Vandring.
- **Synlighet** – *Auto*: på kartan när resan står på en listsida och är publicerad. *Dölj alltid*: aldrig på
  kartan. *Visa alltid*: på kartan även om den inte står på en listsida.
- **Destination** – välj i listan, eller *+ Ny destination…* och skriv ett namn.
- **Egen punkt** – flyttar bara den här resan på kartan. Klicka eller dra på den lilla kartan. Den grå
  punkten är destinationens. *Ta bort egen punkt* gör att destinationens punkt används igen.
- **Markera som kontrollerad** – tar bort Kontrollera-flaggan när du har sett att en gissning stämmer.

Klicka **Uppdatera** på sidan som vanligt för att spara.

### Destinationer
Alla platser resorna kan kopplas till, med punkt (Lat/Lng) och namn på varje språk. Tomt namn på ett språk =
det svenska namnet visas. Klicka på en destination för att ändra den – sätt punkten genom att klicka på kartan.
Filtret **Saknar koordinater** visar de som inte kan visas ännu.

### Destinationsregler
När en ny resa saknar destination prövas reglerna uppifrån: den första regel vars nyckelord finns i resans
svenska namn avgör. Dra raderna för att ändra ordningen – mer specifika regler ("till Wien") ska stå före
allmänna ("Donau"). Skriv ett resenamn i **Prova ett namn** för att se vilken regel som slår till.

---

## Nya resor och mejl

Varje natt omkring kl 03 (och ett par minuter efter att du publicerat en ny svensk sida):

1. Listsidorna läses. Nya resor kommer med på kartan och får kategori efter listan.
2. Nya resor får destination: först genom **destinationsreglerna**, annars genom **dagsprogrammet**
   ("Dag 4 – Sorteny"; dagarna i mitten av resan prövas först). En destination från dagsprogrammet är en
   gissning och flaggas **Kontrollera**. Hittas ingen destination flaggas resan och visas inte på kartan förrän
   du valt en.
3. Destinationer som saknar koordinater slås upp hos OpenStreetMap (med Photon som reserv när OpenStreetMap
   spärrar webbhotellet) – bara de som en resa behöver.
   Även de flaggas Kontrollera.
4. Kartan byggs om.

**Om en ny resa saknar destination** (flaggan "Ingen destination hittades – …" står i översikten och i mejlet,
med orsaken inom parentes):

1. Öppna resan → rutan **Resekarta** → **Destination**. Välj en befintlig destination, eller *+ Ny destination…*
   och skriv namnet (t.ex. "Potsdam"). Klicka **Uppdatera**.
2. Är destinationen ny saknar den koordinater: gå till **Destinationer**, klicka på den och sedan
   **Hämta koordinater** (eller klicka på kartan). Spara.
3. Klicka **Markera som kontrollerad** i rutan Resekarta.

Snabbare: Snabbredigera i översikten gör steg 1. Och ofta räcker det att vänta – resan prövas igen
vid nästa körning (eller klicka **Sök efter nya resor nu**). Skriv gärna en **destinationsregel**
(t.ex. nyckelordet "Potsdam" → Potsdam) så får liknande resor rätt destination direkt.

**Mejl** kommer bara när något hänt: nya resor (namn, kategori, destination, "gissad – kontrollera", länk),
automatiskt hämtade koordinater, eller något som gick fel. Tysta nätter kommer inget mejl. Adressen ställer du
in under **Inställningar**, där finns också **Skicka testmejl** och när nattkörningen gick senast.

En resa som tas bort från listsidorna eller avpubliceras försvinner från kartan av sig själv – och kommer
tillbaka om den läggs upp igen.

> **One.com:** WordPress schemalagda jobb körs när någon besöker sajten, vilket räcker. Vill du vara helt säker
> kan du lägga in ett schemalagt anrop (cron) i one.com:s kontrollpanel till adressen som står under
> Inställningar → Nattkörning.

---

## Kortkoden

Kartan visas med kortkoden `[resekarta]` – samma som förut, så befintliga sidor fungerar som de är:

| Exempel | Visar |
| --- | --- |
| `[resekarta]` | alla resor |
| `[resekarta tag="donau"]` | resor med taggen donau |
| `[resekarta tag="vandra+spanien+sjalvguidad" filters="no"]` | resor med *alla* taggarna, utan filterrad |
| `[resekarta tag="donau,rhen" hojd="600"]` | resor med *någon* av taggarna, högst 600 px hög |
| `[resekarta kat="guidad" arstid="var" niva="2"]` | med förval: guidade, vår, nivå 2 |

- `tag` – taggarnas kortnamn. `+` = alla måste finnas, `,` = det räcker med någon.
- `kat` – guidad, cykel eller vandring (flera: `guidad,cykel`). `arstid` – var, sommar, host eller vinter.
  `niva` – 1–4. Orden fungerar också på de andra språken (t.ex. `cycling`, `spring`, `forår`, `kevät`).
- `filters="no"` döljer filterraden. `hojd` – högsta höjd i pixlar (standard 750).
- Samma inställningar fungerar i adressen till sidan, t.ex. `…/karta/?tag=donau&kat=cykel`.

Kortkoden är densamma på alla språk – kartan visas på sidans språk.
`[resekarta_test]` fungerar alltid och är till för att prova kartan innan bytet (se Övergång).

---

## Övergång från kalkylarket

Följ fliken **Övergång** – den visar själv vilka steg som är klara:

1. Importera (fliken **Import**) och kontrollera översikten.
2. Jämför med dagens karta (fliken **Karta** – samma resor på svenska och norska?).
3. Avaktivera kodstycket för kartan i Code Snippets och klicka **Slå på tilläggets kortkod**.
4. Kontrollera några sidor med kartan på varje språk (listan finns på fliken).
5. Stäng av kalkylarkets nattkörning: i kalkylarket, menyn **Cyklavandra → Slå av nattlig körning**.
6. När allt fungerat ett tag: ta bort de gamla kartfilerna, radera kodstycket och arkivera kalkylarket.

**Gick något fel?** Klicka *Stäng av igen* på fliken Övergång och aktivera kodstycket igen – då visas den
gamla kartan som förut.

---

## Uppdateringar

Nya versioner kommer som vanliga uppdateringar under **Tillägg** (och Kontrollpanel → Uppdateringar).
Länken **Sök efter uppdatering** under Resekarta i tilläggslistan kollar direkt. Vad som ändrats står i
`CHANGELOG.md` och på releasen på GitHub.

Avinstallation tar inte bort något om du inte kryssat i *Ta bort all data* under Inställningar.

---

## Om något inte stämmer

| Problem | Gör så här |
| --- | --- |
| En resa syns inte på kartan | Översikten visar varför i kolumnen *På kartan*. Oftast saknas destination eller koordinater. |
| Resan hamnar på fel ställe | Ändra destinationens punkt (gäller alla resor dit) eller ge resan en *egen punkt*. |
| Fel namn eller beskrivning | Ändra på resans sida (titel, AIOSEO-beskrivning) eller i översättningen i WPML. |
| En ny resa kom inte med | Står den på en listsida? Klicka *Sök efter nya resor nu* i översikten. |
| "Listsidorna kunde inte läsas" | Tillfälligt fel – försök igen. Inget ändras när listorna inte går att läsa. |
| Kartan på en sida är gammal | Ladda om sidan. Kartan har ett versionsnummer som ändras när den byggs om. |

---

## För utvecklare

- Kravspecifikation: `CLAUDE.md`. Dagens lösning som referens: `referens/`. Data från kalkylarket: `data/`.
- Koden: `includes/` (namespace `CV_Resekarta`, prefix `cvrk_`), kartan: `assets/map/karta.html` med
  biblioteken i `assets/map/vendor/`. Kartfilerna skrivs till `uploads/resekarta/`.
- Tester: `composer install && vendor/bin/phpunit`. Lokal testmiljö: `tests/local/README.md`.
- Bygga zip: `bin/build-zip.sh`. Release: Actions → **Bygg release** på `main` (taggen tas från `Version:` i
  `resekarta.php`, releasetexten från `CHANGELOG.md`).
