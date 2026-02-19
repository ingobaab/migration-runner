Architecture – FlyWP Migration Runner
1. Ziel des Systems

Dieses System implementiert einen serverseitig gestarteten Migration Runner, der:

Ein Quell-WordPress autorisiert

Das Plugin flywp-migrator automatisch installiert

Das Plugin aktiviert

Die Migration vollständig per Pull durchführt

Alle Artefakte lokal speichert

Keinen Import durchführt

Keine Ziel-WordPress-Instanz benötigt

Der Runner ist:

CLI-basiert

Ohne eigene WordPress-Installation

Ohne offenen HTTP-Receiver

Deterministisch

Einmalig pro Run

2. Grundarchitektur
High-Level Ablauf
CLI Runner
    │
    ├─ OAuth (Application Password Flow)
    │
    ├─ REST Plugin Install (wordpress.org slug)
    │
    ├─ REST Plugin Activate
    │
    ├─ Pull DB (chunked)
    │
    ├─ Pull Files (ZIP)
    │
    └─ Store Artifacts


Es gibt keinen Push-Receiver.

Die Architektur ist vollständig Pull-basiert.

3. Authentifizierungsmodell
Verwendet wird

WordPress Application Passwords

Kein:

OAuth2

JWT

HMAC

Custom Token

Ablauf

Redirect zu:

/wp-admin/authorize-application.php


Admin bestätigt

WordPress erzeugt Application Password

Redirect mit:

site_url
user_login
password


Runner speichert Credentials

Alle REST Calls erfolgen via:

Authorization: Basic base64(user_login:application_password)

Wichtige Erkenntnisse

Application Password wird nur einmal im Klartext übertragen

Danach nur gehasht in DB gespeichert

Volle Admin-Rechte möglich

Revocable im WordPress Backend

Kein zusätzlicher Auth-Layer notwendig

4. Automatische Plugin-Installation

Nach erfolgreicher Autorisierung:

POST /wp-json/wp/v2/plugins
{
  "slug": "flywp-migrator"
}


WordPress:

Kontaktiert api.wordpress.org

Lädt offizielles ZIP

Installiert Plugin

Prüft Signaturen

Nutzt Plugin_Upgrader

Anschließend:

POST /wp-json/wp/v2/plugins/flywp-migrator/flywp-migrator
{
  "status": "active"
}

Wichtige technische Findings

REST Install nutzt keinen AJAX-Skin

Kein Redirect

Keine JS-Abhängigkeit

Installation erfolgt vollständig serverseitig

Erfordert:

DISALLOW_FILE_MODS = false

Schreibrechte auf wp-content/plugins

FS_METHOD = direct oder korrektes FTP Setup

5. Analyse des unveränderten flywp-migrator Plugins

Das Plugin ist ein reiner Exporter.

Es:

Registriert REST Endpunkte

Nutzt permission_callback

Nutzt WordPress Capability-System

Erzeugt SQL-Dumps tabellenweise

Unterstützt Chunked Table Data

Erzeugt ZIPs für:

Plugins

Themes

Uploads

MU-Plugins

Arbeitet ohne Shell (kein mysqldump)

Nutzt WP_Filesystem API

REST Namespace
/wp-json/flywp-migrator/v1/

Relevante Endpoints
/verify
/tables
/table/{table}/structure
/table/{table}/data
/uploads/manifest
/uploads/download
/plugins/download
/themes/download
/mu-plugins/download

Wichtige Architektur-Eigenschaften

Chunked DB Pull

Kein Server-Push

Pull-basiertes Design

Keine eigene Auth-Implementierung

Vertraut vollständig auf WordPress Auth

Sicherheitsbewertung

Positiv:

Kein Shell Execution

Kein unsicherer SQL Input

Capability Checks vorhanden

WP Filesystem korrekt genutzt

Zu beachten:

Application Password gibt vollständige Admin-Rechte

Kein Rate Limiting im Plugin selbst

Zugriff ist vollständig, sobald Auth erfolgreich ist

6. Server-seitiger Migration Runner
Eigenschaften

CLI-only

Kein dauerhafter HTTP-Server

Kein WordPress erforderlich

Kein Push-Receiver

Keine offene Angriffsfläche

Kein Locking notwendig

Kein Reset-Mechanismus erforderlich

Komponenten
OAuthFlow
WPClient
PluginInstaller
MigrationPuller
Logger
Retry

7. Migration Pull – Datenbank

Ablauf:

/tables

Für jede Tabelle:

/structure

/data?offset=0

/data?offset=1000

fortlaufend bis leer

Ergebnis:

artifacts/{timestamp}/database.sql


Eigenschaften:

Deterministisch

Reproduzierbar

Kein Direkt-Import

Chunk-resistent

Kein Shell-Zugriff

8. Migration Pull – Filesystem

Es werden gezogen:

/plugins/download

/themes/download

/uploads/download

/mu-plugins/download

Ergebnis:

artifacts/{timestamp}/plugins.zip
artifacts/{timestamp}/themes.zip
artifacts/{timestamp}/uploads.zip

9. Logging & Retry
Logging

Zeitgestempelt

In /logs

INFO / ERROR Level

HTTP Statuscodes werden protokolliert

cURL Fehler werden geloggt

Retry

Maximal 3 Versuche

2 Sekunden Abstand

Behandelt:

cURL Fehler

HTTP >= 400

10. Fehlerdiagnose bei Plugin-Install

Typische Fehlerquellen:

HTTP 500 bei /wp-json/wp/v2/plugins

Mögliche Ursachen:

DISALLOW_FILE_MODS = true

Container keine Schreibrechte

WordPress kann api.wordpress.org nicht erreichen

DNS im Container blockiert

PHP Zip Extension fehlt

OpenSSL fehlt

HTTP 401

Falsches Application Password

User keine install_plugins Capability

HTTP 403

REST blockiert

Security Plugin aktiv

IP Restriction

11. Warum CLI und kein HTTP Receiver

Ein HTTP-Receiver würde:

Angriffsfläche öffnen

Eigenes Auth-Handling benötigen

Lock-Management benötigen

Timeout-Handling benötigen

Cleanup-Strategie benötigen

CLI ist:

Einmaliger Prozess

Deterministisch

Kein dauerhaft offener Port

Kein Reset-Mechanismus nötig

Kein zusätzlicher Sicherheitslayer

12. Sicherheitsbewertung Gesamtsystem
Positiv

Keine dauerhafte offene API

Keine Custom Auth

WordPress Core Security genutzt

Keine Push-Endpoints

Keine Shell-Ausführung

Kein root-Zwang technisch notwendig

Minimaler Angriffsvektor

Kritisch

Application Password ist Admin-Level

Muss sicher gespeichert werden

HTTPS zwingend

Logs dürfen Passwort nicht enthalten

Keine parallelen Migrationen ohne zusätzliche Isolation

13. Bewusst nicht implementiert

Import der Daten

Resume bei Abbruch

Parallel Pull

Rate Limiting

Artifact Signing

SHA256 Validierung

Disk Quota Checks

Partial Migration

14. Gesamtbewertung

Das System ist:

Klar getrennt

Pull-basiert

Deterministisch

Minimale Angriffsfläche

Keine WordPress-Abhängigkeit auf Zielseite

Kompatibel mit unverändertem flywp-migrator Plugin

Produktionsnah erweiterbar

15. Finaler Status

Das implementierte System:

Startet serverseitig

Führt OAuth korrekt aus

Installiert Plugin von wordpress.org

Aktiviert Plugin

Zieht vollständige Migration

Speichert Artefakte lokal

Loggt sauber

Nutzt Retry-Mechanismus

Benötigt kein dauerhaftes HTTP-Interface