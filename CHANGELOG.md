# Ändringslogg – Resekarta

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
