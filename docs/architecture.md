# Migration Runner Architektur (gefroren)

## Freeze / Leitplanken

1. **Das WordPress-Plugin `flywp-migrator` bleibt unverändert.**
2. Runner arbeitet **nur gegen die aktuelle Plugin-API** mit `/database/dumps`.
3. Kein Importer: nur Pull/Transfer und lokale Speicherung.
4. Implementierungsstil Runner: **eine Datei (`runner.php`)**, prozedural, KISS, leicht testbar.

---

## Zielbild

Der Runner startet serverseitig, erhält über WordPress Application Passwords Zugriff und zieht anschließend vollständig die exportierbaren Artefakte:

- Metadaten (`info`, optional `verify`) als JSON
- Datenbankdump (Job-basiert über `/database/dumps`)
- Uploads chunkweise
- `plugins.zip`, `themes.zip`, `mu-plugins.zip`

Ablage erfolgt lokal in einem Domain-Ordner:

```text
exports/<domain>/
  credentials.json
  info.json
  verify.json
  db-status.json
  db-download-meta.json
  db-dump.sql
  plugins.zip
  themes.zip
  mu-plugins.zip
  uploads/
    uploads_chunk_00000.zip
    ...
  run-summary.json
```

---

## Authentifizierung und Endpunkte

### 1) Application Password Flow

- Benutzer wird zu `/wp-admin/authorize-application.php` geführt.
- WordPress liefert per Redirect `site_url`, `user_login`, `password` zurück an `runner.php` (HTTP-Callback-Modus).
- Runner speichert diese Daten in `credentials.json`.

### 2) Plugin Install/Update/Aktivierung

Über Core WP REST API:

- `POST /wp-json/wp/v2/plugins` mit `{"slug":"flywp-migrator"}`
- `POST /wp-json/wp/v2/plugins/flywp-migrator/flywp-migrator` mit `{"update":true}` (falls installiert)
- `POST /wp-json/wp/v2/plugins/flywp-migrator/flywp-migrator` mit `{"status":"active"}`

### 3) `info` und `verify` – Rolle und Reihenfolge

#### `GET /wp-json/flywp-migrator/v1/info`

- Für den App-Password-authentifizierten Kontext.
- Liefert Site- und Runtime-Metadaten inkl. Migration Key.
- **Primärer Einstiegspunkt nach Plugin-Aktivierung.**

#### `POST /wp-json/flywp-migrator/v1/verify` mit `key`

- Validiert einen bereits bekannten Migration Key.
- Ist ein zusätzlicher Konsistenzcheck.
- **Empfohlene Reihenfolge:**
  1. Erst `info` holen (Key erhalten)
  2. Dann optional `verify` mit genau diesem Key

### 4) Migration-Key für weitere Endpunkte

Für DB- und Datei-Endpunkte wird der Key gesetzt als:

- Header `X-FlyWP-Key: <key>`
- optional zusätzlich als Query `?secret=<key>`

---

## Ablauf (verbindlich)

1. CLI-Start: `php runner.php --source=... --callback-url=...`
2. OAuth-URL ausgeben, auf Callback warten.
3. `credentials.json` schreiben.
4. Plugin installieren/aktualisieren/aktivieren.
5. `info` abrufen und als `info.json` speichern.
6. `verify` optional ausführen und `verify.json` speichern.
7. DB-Export:
   - `POST /database/dumps`
   - `GET /database/dumps/{job_id}` pollen bis `complete` oder `failed`
   - `GET /database/dumps/{job_id}/download` (Meta)
   - binären Dump laden (`direct=1` URL aus Meta)
   - optional `DELETE /database/dumps/{job_id}`
8. Uploads:
   - `GET /uploads/manifest`
   - pro Chunk `GET /uploads/download?chunk=n`
9. Nicht-chunked Downloads:
   - `GET /plugins/download`
   - `GET /themes/download`
   - `GET /mu-plugins/download`
10. `run-summary.json` schreiben.

---

## Effizienz / Chunking-Bewertung

### Chunked

- **Uploads:** manifestbasiert und in Chunks aufgeteilt (100MB Zielgröße pro Chunk).
- **DB:** jobbasiert asynchron mit Polling (keine alten table/offset-Endpunkte).

### Nicht chunked

- `plugins`, `themes`, `mu-plugins` werden jeweils als einzelnes ZIP übertragen.

### Gesamtbewertung

- Für große Uploads und große DB robust und praktikabel.
- Bei sehr großen Plugin-/Theme-Bäumen können Einzel-ZIPs größere Lastspitzen erzeugen.
- Für KISS-Runner und unverändertes Plugin ist das akzeptabel.

---

## Implementierungsregeln für `runner.php`

1. Eine Datei, prozedural, keine OOP-Hierarchie.
2. Explizite Fehlerbehandlung, klare Exit-Codes.
3. Jede API-Antwort validieren (HTTP-Status + JSON-Struktur).
4. Keine stillen Fallbacks bei Sicherheitsparametern.
5. Migration Key nicht in Logs ausgeben.
6. Artefakte deterministisch pro Domain ablegen.
7. Keine Importlogik implementieren.
8. Keine Plugin-Änderung.

---

## Teststrategie (später gegen externes WordPress)

1. Runner hosten, sodass `--callback-url` öffentlich erreichbar ist.
2. Testlauf mit echter Quelle starten.
3. Prüfen:
   - OAuth callback angekommen
   - Plugin installiert/aktiv
   - `info.json` und `verify.json` plausibel
   - DB-Job läuft bis `complete`
   - alle ZIPs vorhanden
   - Upload-Chunks vollständig und fortlaufend nummeriert
4. Integritätschecks lokal:
   - ZIP-Validität (`unzip -t`)
   - Dateigrößen > 0 (wo erwartet)
   - JSON-Dateien syntaktisch valide

