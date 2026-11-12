# LoxBerry-Plugin: 3D-Drucker (Creality2Lox)

Version 0.9.2 · LoxBerry ab 3.0 · PHP 7.4, 8.4 und 8.5 · gemessen am Creality K2 Plus (Moonraker), nur lesend

Bindet einen Creality-Drucker mit Klipper und **Moonraker** an Loxone an. Das Plugin fragt die HTTP-Schnittstelle von
Moonraker im Heimnetz ab (Port 7125, ohne Anmeldung) und gibt Druckzustand, Fortschritt, Restzeit, Schicht,
Temperaturen, Lüfter, Licht und die Materialboxen (CFS) an Loxone weiter – über das **MQTT-Gateway** (Regelweg) und
wahlweise über einen **HTTP-Lesezweig**. Keine Cloud, kein Konto, keine Anmeldung am Drucker.

Befehle (Pause, Fortsetzen, Druck abbrechen, Notaus, Licht) sind eingebaut, **ab Werk aus** und **nicht am Gerät
erprobt**.

## Neu in 0.9.2

- Im Reiter „Einbindung in Loxone“ steht unter der Baustein-Liste das Bild der Seite „Creality2Lox“ aus dem LoxBerry-Plugins Musterprojekt. Es ersetzt den Satz, dass es ein solches Bild noch nicht gibt.
- Am Gerät nicht gemessen; geprüft unter PHP 7.4 und 8.4 auf dem Arbeitsrechner.

## Stand dieser Fassung

- Neu gebaut am 12.11.2026, noch auf keinem LoxBerry installiert.
- Am Drucker gemessen wurde nur lesend und nur im Ruhezustand (`print_stats.state = standby`). Die Zustände
  „druckt“, „pausiert“, „fertig“, „abgebrochen“ und „Fehler“ sowie alle Befehlswege stehen in der
  Moonraker-Dokumentation; im Prüfstand sind sie gegen eine Moonraker-Attrappe geprüft, am Gerät nicht.
- Gemessen am K2 Plus: Moonraker-Schnittstelle 1.0.5, Klipper-Zustand `ready`, 143 Druckerobjekte, darunter
  `print_stats`, `virtual_sdcard` (mit `layer`/`layer_count`), `heater_bed`, `extruder`,
  `temperature_sensor chamber_temp`, `heater_generic chamber_heater`, `output_pin LED` und `box` (Creality CFS).

## Einrichtung

1. Plugin über die LoxBerry-Oberfläche installieren.
2. Reiter **Einstellungen**: Adresse des Druckers eintragen (IPv4-Adresse oder Rechnername, etwa `192.0.2.24`),
   Port 7125, Abfragetakt 60, 30, 20, 15 oder 10 Sekunden.
3. Reiter **MQTT**: „Werte über das MQTT-Gateway veröffentlichen“ einschalten. Das Abo lautet `creality/#`; unter
   Gateway V1 trägt das Plugin es selbst in seine `mqtt_subscriptions.cfg` ein.
4. Reiter **Einbindung in Loxone**: Namenstabelle der Gateway-Eingänge, Baustein-Liste, Vorlagen für die Befehle.
5. Reiter **Test**: die Selbstprüfung sagt mit Haken und Kreuzen, ob die Einrichtung trägt.

## Was an Loxone geht

Zustände gehen zurückbehalten (retained) hinaus, Messwerte nicht, das Lebenszeichen nie. Was der Drucker in einer
Messung nicht liefert, wird nicht gesendet; in der HTTP-Antwortzeile steht dann der Fehlwert (-1, bei Temperaturen
-999). Bei einer Störung (Drucker aus, keine Antwort) geht über MQTT nur `status/ok = 0` mit Zeitstempel und Zähler
hinaus; die übrigen Werte bleiben auf dem zuletzt gemessenen Stand, der HTTP-Lesezweig antwortet mit 503.

| Feld (HTTP) | MQTT-Thema unter `creality/` | Gateway-Eingang | Einheit | retained | Bedeutung |
|---|---|---|---|---|---|
| OK | `status/ok` | `creality_status_ok` | | nein | 1 = der letzte Abruf hat den Drucker erreicht |
| – | `status/ts` | `creality_status_ts` | s | nein | Zeitpunkt des letzten Abrufs (Unix-Sekunden) |
| ZAEHLER | `status/zaehler` | `creality_status_zaehler` | | nein | Laufzähler 0 bis 999, läuft um |
| – | `status/befehle` | `creality_status_befehle` | | nein | Befehlsbrücke: 1 läuft, 0 aus, -1 Fehler |
| ALTER | – | – | s | – | Alter des letzten Abrufs in Sekunden (-1 = noch keiner). Gehört auf eine Überwachung. |
| KLIPPER | `klipper_code` | `creality_klipper_code` |  | ja | Klipper: 1 bereit, 2 startet, 3 abgeschaltet, 4 Fehler, 0 unbekannt |
| STATUS | `status_code` | `creality_status_code` |  | ja | Druckzustand: 0 bereit, 1 druckt, 2 pausiert, 3 fertig, 4 abgebrochen, 5 Fehler, -1 unbekannt |
| BEREIT | `zustand/bereit` | `creality_zustand_bereit` |  | ja | 1 = bereit (kein Auftrag) |
| DRUCKT | `zustand/druckt` | `creality_zustand_druckt` |  | ja | 1 = druckt |
| PAUSIERT | `zustand/pausiert` | `creality_zustand_pausiert` |  | ja | 1 = Druck pausiert |
| FERTIG | `zustand/fertig` | `creality_zustand_fertig` |  | ja | 1 = Druck fertig (bis zum nächsten Auftrag) |
| ABGEBROCHEN | `zustand/abgebrochen` | `creality_zustand_abgebrochen` |  | ja | 1 = Druck abgebrochen |
| FEHLER | `zustand/fehler` | `creality_zustand_fehler` |  | ja | 1 = Fehler (Druckzustand Fehler oder Klipper abgeschaltet) |
| FORTSCHRITT | `fortschritt_prozent` | `creality_fortschritt_prozent` | % | nein | Fortschritt der Druckdatei in Prozent |
| RESTZEIT | `restzeit_min` | `creality_restzeit_min` | min | nein | Restzeit, geschätzt aus Fortschritt und Druckzeit (0 ohne Druck, -1 noch nicht schätzbar) |
| DRUCKZEIT | `druckzeit_min` | `creality_druckzeit_min` | min | nein | Reine Druckzeit des Auftrags (ohne Pausen) |
| GESAMTZEIT | `gesamtzeit_min` | `creality_gesamtzeit_min` | min | nein | Gesamtzeit des Auftrags (mit Pausen) |
| SCHICHT | `schicht` | `creality_schicht` |  | nein | Aktuelle Schicht |
| SCHICHTEN | `schichten_gesamt` | `creality_schichten_gesamt` |  | ja | Schichten gesamt |
| FILAMENT | `filament_m` | `creality_filament_m` | m | nein | Verbrauchtes Filament des Auftrags |
| DUESE | `duese_c` | `creality_duese_c` | °C | nein | Düse Ist |
| DUESESOLL | `duese_soll_c` | `creality_duese_soll_c` | °C | ja | Düse Soll (0 = aus) |
| DUESELEISTUNG | `duese_leistung_prozent` | `creality_duese_leistung_prozent` | % | nein | Heizleistung der Düse |
| BETT | `bett_c` | `creality_bett_c` | °C | nein | Druckbett Ist |
| BETTSOLL | `bett_soll_c` | `creality_bett_soll_c` | °C | ja | Druckbett Soll (0 = aus) |
| BETTLEISTUNG | `bett_leistung_prozent` | `creality_bett_leistung_prozent` | % | nein | Heizleistung des Druckbetts |
| KAMMER | `kammer_c` | `creality_kammer_c` | °C | nein | Kammer Ist (Fühler chamber_temp) |
| KAMMERSOLL | `kammer_soll_c` | `creality_kammer_soll_c` | °C | ja | Kammerheizung Soll (0 = aus) |
| KAMMERHEIZUNG | `kammerheizung_prozent` | `creality_kammerheizung_prozent` | % | nein | Leistung der Kammerheizung |
| LFAN0 | `luefter/fan0_prozent` | `creality_luefter_fan0_prozent` | % | nein | Lüfter fan0 (Klipper-Name; Zuordnung am Drucker nicht gemessen) |
| LFAN1 | `luefter/fan1_prozent` | `creality_luefter_fan1_prozent` | % | nein | Lüfter fan1 (Klipper-Name; Zuordnung am Drucker nicht gemessen) |
| LFAN2 | `luefter/fan2_prozent` | `creality_luefter_fan2_prozent` | % | nein | Lüfter fan2 (Klipper-Name; Zuordnung am Drucker nicht gemessen) |
| LHOTEND | `luefter/hotend_prozent` | `creality_luefter_hotend_prozent` | % | nein | Lüfter des Hotends |
| LKAMMER | `luefter/kammer_prozent` | `creality_luefter_kammer_prozent` | % | nein | Kammerlüfter (temperaturgeführt) |
| LICHT | `licht` | `creality_licht` |  | ja | Licht: 1 an, 0 aus (Ausgang LED) |
| CFS | `cfs/anzahl` | `creality_cfs_anzahl` |  | ja | Zahl der verbundenen Materialboxen (CFS) |
| – | `status_roh` | Text, kein Eingang |  | ja | Druckzustand, wie Moonraker ihn meldet |
| – | `status_text` | Text, kein Eingang |  | ja | Druckzustand als Klartext |
| – | `klipper_text` | Text, kein Eingang |  | nein | Meldung von Klipper |
| – | `auftrag` | Text, kein Eingang |  | ja | Dateiname des Auftrags |
| – | `meldung` | Text, kein Eingang |  | nein | Meldung des Auftrags |
| – | `fehler_text` | Text, kein Eingang |  | nein | Fehlertext (leer ohne Fehler) |
| CFS1VERB | `cfs/1/verbunden` | `creality_cfs_1_verbunden` |  | ja | Materialbox 1: 1 verbunden |
| CFS1TEMP | `cfs/1/temperatur_c` | `creality_cfs_1_temperatur_c` | °C | nein | Materialbox 1: Temperatur |
| CFS1FEUCHTE | `cfs/1/feuchte_prozent` | `creality_cfs_1_feuchte_prozent` | % | nein | Materialbox 1: Luftfeuchte (Feld dry_and_humidity, als Prozent gedeutet) |
| – | `cfs/1/fach/a/belegt` | `creality_cfs_1_fach_a_belegt` |  | ja | Materialbox 1, Fach A: 1 belegt |
| – | `cfs/1/fach/a/rest_roh` | `creality_cfs_1_fach_a_rest_roh` |  | ja | Materialbox 1, Fach A: Rest, Rohwert des Druckers (Einheit nicht belegt) |
| – | `cfs/1/fach/a/material` | Text, kein Eingang |  | ja | Materialbox 1, Fach A: Material |
| – | `cfs/1/fach/a/farbe` | Text, kein Eingang |  | ja | Materialbox 1, Fach A: Farbe als #RRGGBB (gedeutet) |

Die Materialboxen 2 bis 4 und die Fächer B bis D tragen dieselben Themen mit ihrer Nummer bzw. ihrem Buchstaben
(`cfs/2/…`, `cfs/1/fach/b/…`). Eine nicht verbundene Box meldet nur `cfs/<n>/verbunden = 0`.

Gedeutet, nicht gemessen: `dry_and_humidity` als Luftfeuchte in Prozent (gemessen 40 und 43), die Farbe `0C12E1F` als
`#C12E1F` (die letzten sechs Stellen), `remain_len` als Rohwert ohne Einheit (gemessen 100 bei zwei belegten
Fächern). Die Zuordnung der Lüfter `fan0`, `fan1`, `fan2` zu Bauteil-, Hilfs- und Gehäuselüfter ist am Drucker nicht
gemessen; die Themen tragen deshalb die Klipper-Namen.

Restzeit: Moonraker meldet keine Restzeit, und die gemessene Druckdatei trägt keine Schätzung des Slicers
(`/server/files/metadata` ohne `estimated_time`). Das Plugin schätzt sie aus Fortschritt und reiner Druckzeit:
`Druckzeit × (1 − Fortschritt) / Fortschritt`, erst ab 1 Prozent Fortschritt.

## Befehle (ab Werk aus, nicht am Gerät erprobt)

| Befehl | MQTT-Thema | HTTP | Moonraker | Stufe | Vorbedingung |
|---|---|---|---|---|---|
| Pause | `creality/befehl/pause` 1 | `?cmd=pause&token=…` | `POST /printer/print/pause` | Pause/Fortsetzen | druckt |
| Fortsetzen | `creality/befehl/fortsetzen` 1 | `?cmd=fortsetzen&token=…` | `POST /printer/print/resume` | Pause/Fortsetzen | pausiert |
| Druck abbrechen | `creality/befehl/abbrechen` 1 | `?cmd=abbrechen&token=…` | `POST /printer/print/cancel` | Abbrechen | druckt oder pausiert |
| Notaus | `creality/befehl/notaus` 1 | `?cmd=notaus&token=…` | `POST /printer/emergency_stop` | Notaus | – |
| Licht | `creality/befehl/licht` 1/0 | `?cmd=licht&wert=1&token=…` | `POST /printer/gcode/script` mit `SET_PIN PIN=LED VALUE=1` | Licht | – |

Freigabe in zwei Ebenen: der Weg (Haken „Befehle über MQTT annehmen“ und für die eingreifenden Befehle „Eingreifende
Befehle über MQTT annehmen“ im Reiter MQTT, „Befehle über den HTTP-Endpunkt annehmen“ im Reiter Einstellungen) und die
Stufe (Licht, Pause/Fortsetzen, Abbrechen, Notaus). Vor jedem Befehl fragt das Plugin den Zustand neu ab; derselbe
Befehl mit demselben Wert binnen 5 s wird abgewiesen. Nach einem angenommenen Befehl sieht es bis zu 8 s nach, ob die
Wirkung eintritt (`WIRKUNG=1` gesehen, `0` in der Frist nicht gesehen, `-1` nicht feststellbar). Jeder MQTT-Befehl
bekommt eine Quittung unter `creality/befehl/<name>/quittung`.

Zum Licht: der Drucker überschreibt `SET_PIN` mit einem eigenen Makro (gemessen in `configfile`: `gcode_macro
set_pin`, `rename_existing: SET_PIN_BASE`, Sonderfall nur für `fan0`). Ob das Licht damit schaltet, ist nicht gemessen.

## HTTP-Endpunkt

`http://<LoxBerry>/plugins/creality2lox/drucker.php` – Antwortzeile `CREALITY;OK=…;ALTER=…;ZAEHLER=…;STATUS=…;…`
(nur mit Haken „HTTP-Lesezweig“), `?json=1` mit allen Werten, `?selftest=1&token=…` (200 / 403), Befehle wie oben.
Der Endpunkt fragt den Drucker nicht selbst ab, sondern liest den Stand des Minutenlaufs, und legt nichts an.

## Nicht benutzt

- Die Creality-eigene Schnittstelle auf Port 9999. Gemessen: nach dem Websocket-Handschlag schickt sie ohne Anfrage
  einen vollen Zustand (u. a. `printProgress`, `printLeftTime`, `lightSw`, `err`, `boxTemp`) und danach etwa alle
  1 bis 5 Sekunden Änderungen der Temperaturen. Sie ist nicht dokumentiert; das Plugin sendet ihr nichts und hört ihr
  nicht zu.
- Die Kamera: Port 8000 antwortet auf jeden Pfad mit leerem `text/html`, `/webcam/` auf 4408 mit 502, Moonraker kennt
  keine Kamera (`/server/webcams/list` leer); die Creality-Schnittstelle meldet `webrtcSupport: 1`. Einen
  MJPEG-Strom gibt es nach dieser Messung nicht.

## Prüfung

Prüfstand, Messungen und Eichung liegen im Arbeitsordner unter `Pruefung-Creality2Lox-0.9.1/` (nicht im Plugin).

## Lizenz

MIT, siehe `LICENSE`. Creality ist eine Marke ihres Inhabers; dieses Plugin ist inoffiziell und nicht mit dem
Hersteller verbunden. Ideen aus dem Plugin Bambulab2Lox (MIT) sind eingeflossen, Code ist keiner übernommen.
