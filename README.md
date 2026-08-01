Script met cron job om spam map van ziggo webmail leeg te maken.
Logging van de verwijderde mails

# Spam Cleanup Dashboard – installatie

Twee onderdelen: een WordPress-plugin (ontvangt en toont data) en een aangepast
Python-script (blijft draaien in GitHub Actions, stuurt nu ook data door).

## 1. WordPress-plugin installeren

1. Zip de map `spamcleanup-dashboard.php` (of upload het bestand direct) naar
   `wp-content/plugins/spamcleanup-dashboard/spamcleanup-dashboard.php`.
2. Activeer de plugin via **Plugins** in het WP-adminmenu.
   Bij activatie wordt automatisch een database-tabel aangemaakt en een
   API key gegenereerd.
3. Ga naar **Spam Cleanup → Instellingen** in het menu.
   Daar vind je:
   - het REST endpoint (iets als `https://jouwsite.nl/wp-json/spamcleanup/v1/report`)
   - de API key (nodig in GitHub)

## 2. GitHub Actions bijwerken

Voeg twee secrets toe aan je repository
(**Settings → Secrets and variables → Actions → New repository secret**):

| Secret naam     | Waarde                                              |
|------------------|------------------------------------------------------|
| `WP_REPORT_URL`  | Het REST endpoint uit stap 1 (bv. `https://jouwsite.nl/wp-json/spamcleanup/v1/report`) |
| `WP_API_KEY`     | De API key uit stap 1                                |

Zorg dat je workflow-YAML deze secrets doorgeeft als environment-variabelen
aan de Python-stap, bijvoorbeeld:

```yaml
- name: Run cleanup script
  env:
    EMAIL_USERNAME: ${{ secrets.EMAIL_USERNAME }}
    EMAIL_PASSWORD: ${{ secrets.EMAIL_PASSWORD }}
    WP_REPORT_URL: ${{ secrets.WP_REPORT_URL }}
    WP_API_KEY: ${{ secrets.WP_API_KEY }}
  run: python imap_cleanup.py
```

En zorg dat `requirements.txt` (bevat nu ook `requests`) geïnstalleerd wordt
vóór die stap, bv. `pip install -r requirements.txt`.

Vervang je bestaande scriptbestand door `imap_cleanup.py` uit deze levering
(zelfde logica, alleen met de `push_to_wordpress()`-stap toegevoegd).

## 3. Resultaat bekijken

- **WordPress-dashboard widget**: verschijnt automatisch op het standaard
  WP-dashboard (Dashboard → Home) na de eerste succesvolle run.
- **Volledig overzicht met filter op datum**: menu **Spam Cleanup** in de
  sidebar.

## Hoe het werkt

- Het Python-script blijft draaien via GitHub Actions (betrouwbaarder dan
  WordPress' eigen `wp-cron`, dat afhankelijk is van bezoekersverkeer).
- Na elke run stuurt het script alléén de *nieuw verwijderde* berichten van
  die run naar WordPress (niet de hele dag-historie opnieuw), zodat er geen
  duplicaten in het dashboard verschijnen.
- WordPress slaat elke ontvangen run op in een eigen databasetabel
  (`wp_spamcleanup_history`), zodat je historie behouden blijft — ook na een
  plugin-update of server-herstart, in tegenstelling tot het huidige
  JSON-bestand dat in de GitHub-runner verloren gaat.

## Beveiliging

- De REST-route accepteert alleen requests met de juiste `X-API-Key` header.
- Regenereer de key via **Spam Cleanup → Instellingen** als je vermoedt dat
  hij gelekt is; werk dan ook het GitHub secret bij.
