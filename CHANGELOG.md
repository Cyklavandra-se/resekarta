# Ändringslogg – Resekarta

## 1.0.6
- **Platsuppslag fungerar även när OpenStreetMap spärrar webbhotellet.** OpenStreetMap (Nominatim) vägrar ofta förfrågningar från delade webbhotell (cyklavandra.se fick HTTP 629). Då används **Photon** som reserv – också gratis och byggd på OpenStreetMap – och OpenStreetMap pausas en timme. Photon godtas bara när namnet stämmer (inte "Sorrento" för "Sorteny").
- Knappen **Hämta koordinater** gör uppslaget i din egen webbläsare och fyller i punkten – kontrollera den på kartan och klicka Spara. Går det inte därifrån försöker servern.
- Photon kan också väljas som enda tjänst under Inställningar.

## 1.0.5
- **Bättre automatisk destination från dagsprogrammet.** Ortnamn som förekommer flera dagar prövas först (t.ex. Potsdam på dag 1, 2 och 6), kommatecken skiljer ortnamn ("Caputh, Petzow" är två orter), och bara orter och områden godtas – inte floder, sjöar eller hotell.
- **Flaggan säger varför** ingen destination hittades: inget dagsprogram på sidan, vilka namn som prövades, eller att OpenStreetMap inte svarade.
- **Nytt försök av sig självt:** resor som fortfarande saknar destination prövas igen vid nästa körning (natt, publicering eller "Sök efter nya resor nu").
- Knappen **Hämta koordinater** på destinationssidan slår upp namnet (eller sökordet) direkt.

## 1.0.4
- **Bakgrundskartan försvinner inte längre.** När webbläsaren släpper kartans grafikminne (vanligast på mobil, men även på dator) blev bakgrunden tom medan resorna låg kvar – tills man bytte kartstil eller språk. Nu ritas bakgrunden om av sig själv. Händer det tre gånger på två minuter används den enklare klassiska kartan.
- Bakgrundskartan ritas med högst dubbel upplösning, vilket ungefär halverar grafikminnet på mobiler med skarp skärm (där det här oftast hände).
- Kartbitar som inte gick att hämta (t.ex. vid dålig täckning) prövas igen efter några sekunder.

## 1.0.3
- **Rättar tom karta efter nattkörningen.** När kartan byggdes om av WordPress schemalagda jobb (som anropar sajten via http) pekade kartsidan på biblioteken med http://, och webbläsarna blockerade dem på https-sidorna – kartan visades utan resor. Nu används en adress som fungerar oavsett hur ombyggnaden startas. Kartan byggs om av sig själv när uppdateringen installerats.

## 1.0.2
- Mobil: rutan för en resa ryms alltid i kartan och knappen **Läs mer** syns. Texten går att scrolla, bilden blir lägre (och döljs på de allra minsta skärmarna), rutan blir smalare på smala kartor, och kartstilsvalet och Återställ vy döljs medan rutan är öppen.
- Mobil: kartan är högre (ramen är 85 % av skärmen, minst 560 px, och filtren under kartan tar mindre plats – de små filtren ligger på en rad som går att svepa). På en vanlig mobil blir kartan ungefär 35 % högre. Återställ vy ligger uppe till höger, så att den inte täcker valet av kartstil och språk.
- Texten på knappen Läs mer är vit igen (kartbiblioteket färgade den blå).
- Kartans versionsnummer ändras också när själva kartan ändras, så att webbläsare hämtar den nya kartan efter en uppdatering.

## 1.0.1
- Jämförelsen med dagens karta matchar resorna på sidan i WordPress, inte på länken. En resa som nu länkar till sin norska översättning (där kalkylarket länkade till den svenska sidan) räknas inte längre som saknad – den visas som "Ändrad länk" under fliken Karta. Steg 2 på fliken Övergång blir då grönt.
- Steg 4 på fliken Övergång: en sida flaggas för "gammal kartfil direkt" bara om den gamla kartan faktiskt syns på sidan. Gammal kod som finns sparad i sidan men inte visas markeras i stället "visas inte" och stoppar inte städningen.

## 1.0.0 – fas 5: övergång
- Ny flik **Övergång** med checklistan för bytet från kalkylarket och kodstycket. Stegen bockas av av sig själva: import, jämförelse med dagens karta, bytet av kortkod (med knapp för att slå på – och tillbaka), alla sidor som visar kartan (även i Avadas Code Block) med länkar för kontroll, kalkylarkets nattkörning, och städning av de gamla kartfilerna.
- README med bruksanvisning på svenska.

## 0.4.0 – fas 4: automatik
- **Nattkörning** omkring kl 03 (sajtens tidszon): läser listsidorna, ger nya resor kategori och destination, hämtar saknade koordinater och bygger om kartan. När en ny sida publiceras görs samma sak efter ett par minuter.
- **Destination för nya resor**: först destinationsreglerna, sedan dagsprogrammet ("Dag 4 – Sorteny"; dagarna i mitten först, vid "A till B" prövas B först). Gissade destinationer flaggas Kontrollera. Hittas ingen flaggas resan och visas inte på kartan.
- **Koordinater** hämtas automatiskt från OpenStreetMap (högst ett uppslag per sekund, svaren sparas) – bara för destinationer som en resa behöver. Google Geocoding kan väljas i inställningarna.
- **Mejl** bara när något hänt: nya resor (namn, kategori, destination, "gissad – kontrollera", länk), hämtade koordinater eller fel. Knappen Skicka testmejl i inställningarna.
- Knappen i översikten heter nu **Sök efter nya resor nu** och gör samma sak som nattkörningen, utan mejl.

## 0.3.0 – fas 3: redigering
- Rutan **Resekarta** på resans sida (bara svenska sidor): kategori (Auto eller egen), synlighet, destination (välj eller skapa ny), egen punkt med en liten karta att klicka eller dra i, och knappen Markera som kontrollerad. Visar om resan är på kartan och varför inte. På andra sidor: en ruta för att lägga till sidan på kartan.
- **Snabbredigera** i översikten: kategori, synlighet, destination, egen punkt och Kontrollera – direkt i tabellen.
- **Destinationer**: lägga till, ändra och ta bort, med namn per språk och en karta för punkten. Filter för destinationer som saknar koordinater eller inte används.
- **Destinationsregler**: redigerbar lista – dra för att ändra ordningen, lägg till och ta bort. Prova ett resenamn och se vilken regel som slår till.
- Kartan byggs om automatiskt efter varje ändring.

## 0.2.0 – fas 2: kartan
- Kartdata byggs i tillägget för alla språk i WPML (svenska, norska, danska, finska, engelska – och nya språk automatiskt): namn, länk och SEO-beskrivning från resans sida på språket, annars den svenska. Destinationsnamn per språk.
- Kartan ligger i tillägget med Leaflet, MapLibre och typsnittet buntade – inga skript från andra webbplatser. Knapptexter på danska, finska och engelska; språk utan egna texter visar engelska.
- Förvalen (kat, arstid) förstår orden på alla språken, t.ex. cycling, pyöräily, spring, forår, kevät.
- Kartan byggs om automatiskt när en resa eller dess översättning sparas, och med knappen Bygg om kartan nu.
- Ny flik Karta: antal resor per språk, jämförelse med dagens karta från kalkylarket och förhandsvisning.
- Kortkoden [resekarta] med samma inställningar som förut. Den slås på under Inställningar när kodstycket i Code Snippets är avstängt; till dess kan den nya kartan provas med [resekarta_test]. Varning om båda är aktiva.

## 0.1.3
- Uppdateringar sköts nu av WordPress, som för de andra egna tilläggen: nya versioner hämtas från GitHub och visas under Tillägg och Kontrollpanel → Uppdateringar. Länken "Sök efter uppdatering" finns i tilläggslistan.

## 0.1.2
- Tillägget heter nu **Cykla & Vandra – Resekarta** i listan över tillägg, så det hamnar bredvid de andra egna tilläggen.

## 0.1.1
- Resekarta ligger nu i den gemensamma menyn **Cykla&Vandra**, med ett kort på översiktssidan, som de andra egna tilläggen.
- En rad i menyn; Destinationer, Destinationsregler, Inställningar och Import nås via flikar överst på sidan.

## 0.1.0 – fas 1: grund
- Menyn **Resekarta** i adminpanelen: Översikt, Destinationer, Destinationsregler, Inställningar, Import.
- Resorna hittas via de tre listsidorna (med sidnumrering). En resa på både cykel- och vandringslistan räknas som Cykel.
- Destinationer (en uppsättning för alla språk) med koordinater och namn per språk. Destinationsregler i ordning.
- Engångsimport från kalkylarket (destinationer, namn på andra språk, regler, resor). Kan köras igen utan dubbletter.
- Översikten visar alla resor: kategori, destination, nivå, synlighet, om resan är på kartan och varför inte, språk, Kontrollera.
- Ingen karta eller kortkod ännu – kodstycket i Code Snippets ska vara kvar tills vidare.
