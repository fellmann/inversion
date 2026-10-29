# Wolken über Freiburg (ICON-D2 3D)

Backend PHP (≥ 8.1, curl, zlib; `bzip2` im PATH oder PHP-bz2), Frontend statisch (three.js via CDN).

## Start
    php bin/update.php          # optional: Cache vorab füllen (~35 s, ~1,3 MB)
    php -S localhost:8080 -t public

Dann http://localhost:8080 öffnen. Beim ersten Aufruf ohne Cache startet der Server den Download im Hintergrund,
das Frontend zeigt den Fortschritt. Ein Cron-Job ist nicht nötig: Nur beim Aufruf der API wird (höchstens alle 10 Minuten) geprüft, ob ein neuer Lauf vorliegt, und der Cache ggf. im Hintergrund aktualisiert. `bin/update.php` kann den Cache auch manuell füllen.

## Aufbau
- `src/config.php` – Mittelpunkt, Gebiet (±10 km), Zellgröße, Vorhersagestunden, Höhenlimit
- `src/CloudBuilder.php` – lädt `clc` (je Modellschicht) + `hhl` (Schichthöhen) vom DWD (regular-lat-lon, GRIB2, bzip2),
  schneidet das Gebiet aus (`Grib2.php`, eigener Decoder) und interpoliert auf ein 1-km-Raster
- `src/CloudService.php` – Cache (`cache/`), Prüfung auf neuen Lauf alle 10 min, Build im Hintergrund mit Lock
- `src/Terrain.php` – Gelände 100 m aus Terrarium-DEM-Kacheln (eigener PNG-Decoder), dauerhaft gecacht
- `public/api/clouds.php`, `terrain.php`, `status.php` – JSON-Endpunkte

## Zwischenformat (`cloud3d/1`)
`nx, ny, nz`, `times[]`, `heights` ((nz+1)·ny·nx Halbflächenhöhen in m ü. NN, unten→oben),
`clc[t]` (nz·ny·nx Wolkenbedeckung 0–100 %, Index `(l·ny + j)·nx + i`, i = West→Ost, j = Süd→Nord).

## Docker
    docker compose up

Läuft auf dem offiziellen `php:8-cli`-Image (Projektordner als Volume, Cache in `./cache`). Dann http://localhost:8080 öffnen.

## Test-Parameter (URL)
- `?simulate=update` – spielt die Anzeige „neuer Modelllauf wird geladen → verfügbar" nach (ca. 15 s), ohne Server-Update
- `?t=<n>` – Startzeitschritt, `?sub=<1..6>` – horizontale Feinunterteilung der Wolken
