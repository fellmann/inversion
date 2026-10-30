# Wolken 3D (ICON-D2)

3D-Visualisierung der ICON-D2-Wolkenschichten (DWD) um einen frei wählbaren Ort (Standard: Freiburg),
20 × 20 km, mit Gelände und Zeitstrahl.
Backend PHP (≥ 8.1, curl, zlib; `bzip2` im PATH oder PHP-bz2), Frontend statisch (three.js via CDN).

## Start
    docker compose up          # Image php:apache, http://localhost:8080
    # oder ohne Docker:
    php -S localhost:8080 -t public

Ein manueller Build ist nicht nötig: Beim ersten Aufruf lädt der Server den aktuellen ICON-D2-Lauf im Hintergrund
(ca. 1–3 min, ~150 MB Cache), das Frontend zeigt den Fortschritt. Danach prüft jeder Aufruf höchstens alle
10 Minuten, ob ein neuer Lauf vorliegt, und lädt ihn bei Bedarf im Hintergrund; bis dahin wird der alte Lauf angezeigt
(mit Statusanzeige in der UI). Alte Daten werden nach erfolgreichem Update gelöscht.
Optional: `php bin/update.php` füllt den Cache manuell (`INVERSION_CACHE=/pfad` ändert das Cache-Verzeichnis).

## Ortsauswahl
Oben links: Suchfeld (Open-Meteo-Geocoding) und Button „Aktueller Standort". Der Ort steht in der URL
(`?lat=&lon=&name=`) und wird im Browser gemerkt. Orte außerhalb des ICON-D2-Gebiets (Deutschland und Umgebung)
werden mit einer Meldung abgelehnt. Marker (Orte, Gipfel, Täler) erscheinen nur, wenn sie im Kartenbereich liegen.

## Datenhaltung
Der letzte Lauf wird für das **gesamte** Modellgebiet kompakt zwischengespeichert (je Schicht und Stunde 1 Byte pro
Gitterpunkt, deflate, ~50 MB; dazu einmalig die Schichthöhen `hhl`). Das Zwischenformat für einen Ort wird erst bei der
Anfrage daraus berechnet (~1,5 s) und pro Ort/Lauf gecacht (`cache/loc/`, max. 40 Orte). Ein Vorberechnen des
Zwischenformats für alle Orte wäre mit ~1,3 Mrd. Werten deutlich größer.

## Aufbau
- `src/config.php` – Standardort, Gebiet (±10 km), Zellgröße, Vorhersagestunden, Höhenlimit
- `src/CloudBuilder.php` – lädt `clc` (je Modellschicht) + `hhl` vom DWD (regular-lat-lon, GRIB2, bzip2) und speichert den Lauf
- `src/Grib2.php` – eigener GRIB2-Decoder (Simple Packing, Bitmap)
- `src/LocationBuilder.php` – schneidet das Gebiet um den Ort aus und interpoliert auf ein 1-km-Raster
- `src/CloudService.php` – Lauf-/Ortscache, Prüfung auf neuen Lauf, Hintergrund-Build mit Lock
- `src/Terrain.php` – Gelände 100 m aus Terrarium-DEM-Kacheln (eigener PNG-Decoder), pro Ort gecacht
- `public/api/clouds.php?lat=&lon=`, `terrain.php?lat=&lon=`, `status.php` – JSON-Endpunkte
- `public/place.js` – Ortssuche; `public/app.js` – 3D-Szene

## Zwischenformat (`cloud3d/1`)
`nx, ny, nz`, `times[]`, `heights` ((nz+1)·ny·nx Halbflächenhöhen in m ü. NN, unten→oben),
`clc[t]` (nz·ny·nx Wolkenbedeckung 0–100 %, Index `(l·ny + j)·nx + i`, i = West→Ost, j = Süd→Nord), `center`.

## Test-Parameter (URL)
- `?simulate=update` – spielt die Anzeige „neuer Modelllauf wird geladen → verfügbar" nach (ca. 15 s), ohne Server-Update
- `?t=<n>` – Startzeitschritt, `?sub=<1..6>` – horizontale Feinunterteilung der Wolken
