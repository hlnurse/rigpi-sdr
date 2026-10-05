# Elmer localhost cloud pilot

This bundle installs the current Elmer answer service under `/opt/elmer`. It binds
only to `127.0.0.1:8091`; the installer does not expose it through Apache, enable it
at boot, or start it.

The knowledge database is intentionally not bundled. Transfer the complete
`/home/pi/Elmer/output/knowledge.db` from the working Pi and pass its path to the
installer. The installer verifies database integrity and minimum document counts for
Official Help, Groups.io messages and files, Hamlib, RigPi, and WSJT-X before making
it active.

The OpenAI key is not included. Store it as `/etc/elmer/openai_api_key`, owned by
`root:elmer` with mode `0640`.

After installation, test `/health` locally before adding any Apache proxy. The
current answer endpoint has no public demonstration quota or reviewer-pass check,
so it must remain private until those controls are deployed.

## Public gateway update

`deploy/install_gateway_update.sh` installs a second service on
`127.0.0.1:8092`. It provides:

- one anonymous live question per network identity per UTC day;
- a separate global anonymous daily ceiling;
- named, expiring, revocable reviewer passes with fixed allowances;
- six-character, administrator-approved RigPi station pairing;
- per-station monthly allowances and immediate credential revocation;
- RigPi-branded public and station interfaces with copy-to-clipboard answers;
- searchable-record and cited-evidence counts in answer diagnostics;
- safe Markdown image rendering limited to local or approved RigPi source hosts;
- HMAC-pseudonymized network identities;
- one authorized feedback submission per completed answer;
- full local citation text only for Official RigPi Help;
- security headers, request-size limits, and generic provider errors.

The update installer does not start, enable, or expose the gateway. After local
tests, `deploy/activate_gateway_apache.sh` adds cPanel userdata includes for the HTTP
redirect and HTTPS reverse proxy. `deploy/deactivate_gateway_apache.sh` removes only
those Elmer includes and rebuilds Apache for rollback.

## RigPi station pairing pilot

`deploy/install_station_pairing_client.sh` installs `elmer-pair` on a RigPi. It
does not alter the existing Ask Elmer web application or start a pairing request.

Start pairing on the RigPi:

```text
elmer-pair pair --station-name "Howard’s RigPi"
```

Approve the displayed six-character code on the cloud host:

```text
/opt/elmer/venv/bin/python /opt/elmer/app/station_admin.py approve ABC234
```

The generated station credential is never printed. The Pi retrieves it over HTTPS,
stores it at `/etc/elmer/station_credential` as `root:pi` mode `0640`, confirms it
with the cloud, and removes the temporary pairing state. A credential awaiting
delivery expires after ten minutes. Re-pairing the same station revokes its previous
credential automatically.

Useful commands:

```text
elmer-pair status
elmer-pair disconnect
/opt/elmer/venv/bin/python /opt/elmer/app/station_admin.py pairings
/opt/elmer/venv/bin/python /opt/elmer/app/station_admin.py stations
```

After pairing is validated, `deploy/install_station_cloud_relay.sh` changes the
existing local Elmer service into a secure relay to the paired cloud endpoint. The
browser and nginx paths do not change, Official Help references still resolve from
the Pi, and the station credential never reaches PHP or the browser. Cloud feedback
contains the rating, comment, question, answer, answer request ID, and pseudonymous
station identity; it does not send the RigPi username. The installer preserves both
changed live files and `deploy/rollback_station_cloud_relay.sh` restores them.

Elmer can render a standalone Markdown image when the selected evidence contains an
explicit `image_references` URL. The answer model is instructed never to invent an
image address, and the browser accepts images only from local RigPi Help/assets or
the approved RigPi, Groups.io, and GitHub source hosts. Existing ingestion sources do
not populate `image_references` unless their connector explicitly exports approved
owned images.

## Official Help owned images

`deploy/install_owned_images_builder.sh` installs builder v0.20 on the RigPi. A normal
knowledge rebuild then:

- finds locally referenced PNG, JPEG, GIF, SVG, and WebP assets in Official Help;
- excludes external images, missing files, path traversal, and known Help-system chrome;
- records source page, order, caption/alt text, checksum, media type, size, ownership,
  and approval status;
- writes `owned_images` and `document_images` database tables;
- exports only approved files to `output/owned-images/` with a validated manifest.

After transferring the rebuilt database and `owned-images` directory to rigpi.net,
`deploy/install_owned_knowledge_update.sh` validates every checksum and database row,
keeps timestamped backups, activates both atomically, and restarts the Elmer services.
The gateway serves these files only below `/media/official-help/` and rejects traversal
or non-image paths.

## Two-level administration

Gateway update 0.5 adds two deliberately separate administration scopes.

On each paired RigPi, `deploy/install_station_cloud_relay.sh` installs
`/elmer-stats.php`. RigPi authentication and `Access_Level = 1` protect the page.
Its browser request goes through the local Elmer relay, so the station credential
never reaches PHP or JavaScript. The cloud endpoint derives the station ID from that
credential and returns only aggregate usage, outcome, token, timing, and rating
statistics. It never returns question, answer, or feedback-comment content.

The hosted master service binds only to `127.0.0.1:8093`. It uses a distinct
password-derived administrator session, a `Secure`, `HttpOnly`, `SameSite=Strict`
cookie, inactivity and maximum-session limits, login throttling, CSRF tokens on every
state change, and an append-only administrative audit table. The master dashboard at
`https://elmer.rigpi.net/admin/` can inspect system and station statistics, the
knowledge inventory and voluntary feedback; approve or reject pairing; change
allowances; revoke stations; and create or revoke reviewer invitations.

Install the master files without starting or exposing them:

```text
bash deploy/install_master_admin.sh
```

Then configure the sole initial administrator interactively, start the service, and
activate the Apache route:

```text
elmer-admin-password --username howard
systemctl enable --now elmer-admin
bash /opt/elmer/deploy/activate_admin_apache.sh
```

## Live RigPi callbook connector

Gateway update 0.6 adds the first station-local live connector. When a signed-in
administrator asks a callbook-style question containing one amateur callsign, the
Ask Elmer page calls `/programs/ElmerCallbook.php` on that RigPi. The endpoint uses
the signed-in user's existing RigPi QRZ configuration and QRZ session-key cache; if
QRZ is not configured or unavailable, the existing RigPi callbook routine may fall
back to its onboard FCC data.

QRZ username, password, and session key never leave the RigPi. The cloud receives
only a strict set of returned facts: callsign, name, general location, grid, DXCC and
zone information, license class, station-relative distance and bearing, provider,
retrieval time, and the QRZ callsign-page reference when QRZ supplied the result.
Optional details are purpose-limited by the wording of the question: an exact mailing
address is included only for an address, postal, or QSL-mailing question; a biography
is converted from QRZ HTML to at most 6,000 characters of plain text only for a bio or
“about” question; and the primary image URL is included only for a photo or picture
question. QRZ images are loaded directly over HTTPS and are not cached or proxied by
Elmer. Unrequested profile fields remain omitted.
Email is available only for an explicit email/contact question. Website, QSL manager,
LoTW/eQSL/paper-QSL status, aliases, previous callsign, nickname, IOTA, license dates,
and timezone are likewise requested only when the question names the corresponding
topic. For fields that RigPi does not retain, the endpoint reuses the local QRZ session
key for one current XML read and transmits only the requested, validated result fields.
The answer cites these current facts as `[Live 1]`, states the provider and retrieval
time, and can answer a pure callsign lookup even when no static knowledge record is
relevant. Anonymous and reviewer routes cannot submit live station data.

Install the cloud side first with `deploy/install_gateway_update.sh`, then install
the RigPi side with `deploy/install_station_cloud_relay.sh`. The latter installs the
local JSON endpoint and patches the existing Ask Elmer interface idempotently.
