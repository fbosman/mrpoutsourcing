# MRP Outsourcing — Dolibarr Module

[![Dolibarr](https://img.shields.io/badge/Dolibarr-16%2B-blue)](https://www.dolibarr.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-purple)](https://php.net)
[![License](https://img.shields.io/badge/License-MIT-green)](LICENSE)

Dolibarr-module voor het uitbesteden van MRP-productie-opdrachten aan externe leveranciers. Volledig geïntegreerd in de Dolibarr MRP-workflow.

---

## Functionaliteit

- 📧 **Stuur productie-opdrachten** (MO's) per e-mail naar externe leveranciers — inclusief componentenlijst, hoeveelheden en opleverdatum
- 📦 **Keuze per opdracht**: component-voorraad al dan niet verlagen bij het uitbesteden
- ✅ **Gereedmeldingen ontvangen** via drie kanalen:
  - Klikbare link in de leveranciers-e-mail (geen login vereist)
  - Inkomende e-mail met patroon `GEREED-{MO_REF}-{TOKEN}` — via **IMAP** of **Office365 (Microsoft Graph)**
  - Handmatige gereedmelding in de Dolibarr interface
- 🔄 **Automatisch sluiten** van de MRP Manufacturing Order bij gereedmelding
- 🔒 **Veilige callbacks** via uniek per-opdracht token (192 bits entropie)
- 📋 **Audittrail** — volledige log van alle leverancierscommunicatie

---

## Schermafbeeldingen

| MO-kaart met knop | Opdracht aanmaken | Leveranciers-e-mail | Gereedmelding portaal |
|:-:|:-:|:-:|:-:|
| _Knop "Verstuur naar leverancier"_ | _Formulier met voorraadkeuze_ | _Professionele HTML-e-mail_ | _Branded leverancierspagina_ |

---

## Vereisten

| Component | Versie |
|-----------|--------|
| Dolibarr | 16.0 of hoger (getest op 20.x) |
| PHP | 7.4 of hoger |
| PHP IMAP-extensie | Optioneel — alleen bij provider **IMAP** |
| PHP cURL-extensie | Optioneel — alleen bij provider **Office365** |
| Azure AD app-registratie | Optioneel — alleen bij provider **Office365** (zie hieronder) |
| MRP-module | Actief in Dolibarr |
| SMTP | Geconfigureerd in Dolibarr |

---

## Installatie

### 1. Bestanden plaatsen

```bash
cp -r mrpoutsourcing /pad/naar/dolibarr/custom/
```

### 2. Databasetabel aanmaken

```bash
mysql -u dolibarruser -p dolibarr_db \
  < custom/mrpoutsourcing/sql/llx_mrpoutsourcing_order.sql
```

### 3. Module activeren

**Dolibarr → Instellingen → Modules/Applicaties → zoek "MRP Outsourcing" → Activeren**

### 4. Configureren

Ga naar **MRP → Uitbestede Opdrachten** (tandwiel-icoon) en stel in:
- Afzender e-mailadres (gebruik bij voorkeur dezelfde mailbox die de gereedmeldingen ontvangt)
- **Mailprovider** voor inkomende gereedmeldingen: `IMAP` of `Office365 (Microsoft Graph)`
- De bijbehorende provider-gegevens (zie hieronder)
- Standaard magazijn voor voorraadmutaties

### 5. Cron instellen (optioneel)

```cron
*/5 * * * * php /pad/naar/dolibarr/custom/mrpoutsourcing/scripts/process_inbound_mail.php
```

---

## Inkomende mail — provider configureren

De gereedmeldingen per e-mail (`GEREED-{MO_REF}-{TOKEN}`) worden opgehaald door het cron-script.
De provider wordt gekozen met de instelling **Mailprovider** (`MRPOUTSOURCING_MAIL_PROVIDER`).

### Optie A — IMAP

Vereist de PHP IMAP-extensie. Vul in de setup in:

| Veld | Constante | Voorbeeld |
|------|-----------|-----------|
| IMAP-server | `MRPOUTSOURCING_IMAP_HOST` | `mail.mijnbedrijf.nl` |
| IMAP-poort | `MRPOUTSOURCING_IMAP_PORT` | `993` |
| SSL gebruiken | `MRPOUTSOURCING_IMAP_SSL` | `Ja` |
| Gebruikersnaam | `MRPOUTSOURCING_IMAP_USER` | `productie@mijnbedrijf.nl` |
| Wachtwoord | `MRPOUTSOURCING_IMAP_PASS` | _(versleuteld opgeslagen)_ |
| Map | `MRPOUTSOURCING_IMAP_FOLDER` | `INBOX` |

> ⚠️ **Office365/Exchange Online** ondersteunt geen Basic Authentication meer voor IMAP.
> Gebruik daar provider **Office365** hieronder.

### Optie B — Office365 (Microsoft Graph)

Gebruikt de Microsoft Graph API met de OAuth2 **client-credentials** flow (app-only, geen
gebruikerslogin). Werkt betrouwbaar headless via cron en vereist de PHP cURL-extensie.

**1. App-registratie in Azure**

1. **Azure Portal → Microsoft Entra ID → App-registraties → Nieuwe registratie**.
   Noteer de **Application (client) ID** en de **Directory (tenant) ID**.
2. **Certificaten & geheimen → Nieuw clientgeheim** → kopieer de **waarde** direct
   (deze is later niet meer zichtbaar).
3. **API-machtigingen → Machtiging toevoegen → Microsoft Graph → Toepassingsmachtigingen**
   (let op: *Application*, niet *Delegated*):
   - `Mail.ReadWrite` — berichten lezen en als gelezen markeren
   - `Mail.Send` — automatisch antwoord sturen

   Klik daarna op **"Beheerderstoestemming verlenen"** (admin consent).
4. **Aanbevolen:** beperk de app tot één mailbox met een
   [Application Access Policy](https://learn.microsoft.com/en-us/graph/auth-limit-mailbox-access)
   in Exchange Online — anders heeft de app technisch toegang tot álle mailboxen.

**2. Instellen in Dolibarr**

| Veld | Constante | Voorbeeld |
|------|-----------|-----------|
| Tenant-id of domein | `MRPOUTSOURCING_O365_TENANT` | `bedrijf.onmicrosoft.com` |
| Application (client) ID | `MRPOUTSOURCING_O365_CLIENT_ID` | `00000000-0000-0000-0000-000000000000` |
| Client secret | `MRPOUTSOURCING_O365_CLIENT_SECRET` | _(versleuteld opgeslagen)_ |
| Mailbox (e-mail / UPN) | `MRPOUTSOURCING_O365_MAILBOX` | `productie@mijnbedrijf.nl` |
| Map (well-known naam) | `MRPOUTSOURCING_O365_FOLDER` | `inbox` |

**3. Verbinding testen**

```bash
php /pad/naar/dolibarr/custom/mrpoutsourcing/scripts/process_inbound_mail.php
```

De uitvoer toont `Provider: office365` en óf `No unread messages` (verbinding OK),
óf een duidelijke fout (token/permissie).

---

## Gebruik

### Opdracht uitbesteden

1. Open een MRP-productieorder
2. Klik **"Verstuur naar leverancier"** in de actiebalk
3. Selecteer de leverancier (e-mail wordt automatisch ingevuld)
4. Kies of component-voorraad wordt verlaagd
5. Voeg optioneel een opmerking toe voor de leverancier
6. Klik **"Aanmaken"** → controleer voorvertoning → **"Nu Verzenden"**

### Gereedmelding door leverancier

De leverancier ontvangt een HTML-e-mail met:
- Knop **"Opdracht ontvangen"** → registreert bevestiging
- Knop **"Gereedmelding"** → sluit de MRP-order automatisch

Of per e-mail met onderwerp:
```
GEREED-MO2025-0042-a1b2c3d4e5f6789abc0123456789abcd01234567
```

---

## Statusflow

```
[draft] → [sent] → [confirmed] → [done]
                ↘               ↗
                  direct done
```

| Status | Omschrijving |
|--------|-------------|
| `draft` | Aangemaakt, nog niet verzonden |
| `sent` | E-mail verzonden naar leverancier |
| `confirmed` | Leverancier heeft ontvangst bevestigd |
| `done` | Gereed gemeld — MRP-order gesloten |
| `cancelled` | Geannuleerd |

---

## Bestandsstructuur

```
mrpoutsourcing/
├── core/
│   └── modules/modMrpOutsourcing.class.php       # Module-descriptor
├── class/
│   ├── mrpoutsourcingorder.class.php             # Business logic
│   ├── actions_mrpoutsourcing.class.php          # MO-kaart hook (addMoreActionsButtons)
│   └── office365mailclient.class.php             # Microsoft Graph (Office365) mailclient
├── admin/
│   └── mrpoutsourcing_setup.php                  # Instellingen
├── ajax/
│   └── get_supplier_email.php                    # Leverancier e-mail Ajax
├── public/
│   └── callback.php                              # Leverancier portaal (geen login)
├── scripts/
│   └── process_inbound_mail.php                  # Cron-script (IMAP / Office365)
├── sql/
│   └── llx_mrpoutsourcing_order.sql              # Tabelstructuur
├── langs/
│   ├── nl_NL/mrpoutsourcing.lang
│   └── en_US/mrpoutsourcing.lang
├── list.php                                      # Overzicht
└── send_order.php                                # Aanmaken / versturen
```

---

## Rechten

| Recht | Toegang |
|-------|---------|
| `mrpoutsourcing.read` | Overzicht en details bekijken |
| `mrpoutsourcing.write` | Opdrachten aanmaken en verzenden |
| `mrpoutsourcing.manage` | Gereed melden en annuleren |

---

## Beveiliging

- Elke uitbestedingsopdracht heeft een eigen **uniek token** van 48 hexadecimale tekens (192 bits entropie)
- De leveranciers-callback (`public/callback.php`) vereist geen Dolibarr-login
- Tokens worden nooit hergebruikt

---

## Licentie

MIT — zie [LICENSE](LICENSE)

---

## Auteur

Ontwikkeld voor [Ghee Easy](https://ghee-easy.nl) — Dolibarr 20.x customisatie
