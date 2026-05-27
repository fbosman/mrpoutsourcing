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
  - Inkomende e-mail met patroon `GEREED-{MO_REF}-{TOKEN}` (IMAP cron)
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
| PHP IMAP-extensie | Optioneel — alleen voor inkomende mail |
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
- Afzender e-mailadres
- IMAP-gegevens (optioneel, voor inkomende gereedmeldingen)
- Standaard magazijn voor voorraadmutaties

### 5. Cron instellen (optioneel)

```cron
*/5 * * * * php /pad/naar/dolibarr/custom/mrpoutsourcing/scripts/process_inbound_mail.php
```

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
│   ├── modules/modMrpOutsourcing.class.php      # Module-descriptor
│   └── triggers/interface_99_..._Hook.class.php  # MO-kaart hook
├── class/
│   └── mrpoutsourcingorder.class.php             # Business logic
├── ajax/
│   └── get_supplier_email.php                    # Leverancier e-mail Ajax
├── public/
│   └── callback.php                              # Leverancier portaal (geen login)
├── scripts/
│   └── process_inbound_mail.php                  # IMAP cron-script
├── sql/
│   └── llx_mrpoutsourcing_order.sql              # Tabelstructuur
├── langs/
│   ├── nl_NL/mrpoutsourcing.lang
│   └── en_US/mrpoutsourcing.lang
├── list.php                                      # Overzicht
├── send_order.php                                # Aanmaken / versturen
└── mrpoutsourcing_setup.php                      # Instellingen
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
