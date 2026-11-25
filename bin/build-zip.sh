#!/usr/bin/env bash
# Bygger resekarta.zip för uppladdning i WordPress (Tillägg → Lägg till nytt → Ladda upp).
# Bara det som behövs följer med: inga tester, referensfiler eller utvecklingsverktyg.
set -euo pipefail
cd "$(dirname "$0")/.."

VERSION=$(sed -n 's/^ \* Version: *//p' resekarta.php | tr -d '[:space:]')
CONST=$(sed -n "s/^const VERSION *= *'\(.*\)';/\1/p" resekarta.php)
if [ "$VERSION" != "$CONST" ]; then
	echo "Versionen i pluginhuvudet ($VERSION) och VERSION-konstanten ($CONST) skiljer sig." >&2
	exit 1
fi

for f in $(find resekarta.php uninstall.php includes -name '*.php'); do
	php -l "$f" > /dev/null
done

rm -rf build && mkdir -p build/resekarta/data
cp resekarta.php uninstall.php cyklavandra-meny.php wpml-config.xml CHANGELOG.md README.md build/resekarta/
cp -r includes assets build/resekarta/
cp data/destinationer.csv data/destinationsregler.csv data/resor.csv data/destinationsnamn.json build/resekarta/data/
(cd build && zip -qr ../resekarta.zip resekarta)
echo "resekarta.zip (version $VERSION) är klar."
