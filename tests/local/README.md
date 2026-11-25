# Lokal testmiljö

Används bara under utvecklingen – följer inte med i `resekarta.zip`.

- `fake-wpml.php` – en minimal ersättare för WPML (språk sv/no/da/fi/en, översättningar via post meta).
- `cvrk-test-fixtures.php` – serverar sparade kopior av listsidorna, så att "Läs listsidorna" fungerar utan nät.
- `build-site.php` – bygger en kopia av sajtens sidstruktur (sökvägar, titlar, utdrag, taggar, norska
  översättningar) från en ögonblicksbild av sajtens publika REST-API.

Ögonblicksbilden (`pages-sv.json`, `pages-no.json`, `tags.json`) hämtas från
`https://cyklavandra.se/wp-json/wp/v2/pages?lang=sv&per_page=100&page=N&_fields=id,link,slug,parent,title,tags,featured_media,excerpt,menu_order,aioseo_head_json.description`
(och `lang=no`) samt `/wp-json/wp/v2/tags?per_page=100`. Listsidorna sparas som
`vandra_guidade_p1.html`, `cykla_cykla-pa-egen-hand_pN.html`, `vandra_pa-egen-hand_pN.html`.

```bash
php tests/local/build-site.php /path/to/wordpress /path/to/snapshot
```

Enhetstesterna (ren logik, ingen WordPress) körs med `composer install && vendor/bin/phpunit`.
