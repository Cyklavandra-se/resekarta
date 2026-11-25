# Resekarta

WordPress-plugin för cyklavandra.se: interaktiv karta över Cykla & Vandras resor på alla språk i WPML.

Under utveckling – se `CHANGELOG.md` för vad som finns i varje version och `CLAUDE.md` för kravspecifikationen.
`referens/` innehåller dagens lösning och `data/` data från kalkylarket "Cykla & Vandra - resedatabas karta".

## Bygga zip-filen

```bash
bin/build-zip.sh
```

Ger `resekarta.zip`, som installeras via Tillägg → Lägg till nytt → Ladda upp tillägg. En tagg `vX.Y.Z`
på GitHub bygger samma fil automatiskt och lägger den på releasen.

## Tester

```bash
composer install
vendor/bin/phpunit
```
