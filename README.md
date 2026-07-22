# 🍹 Mojito — Label Designer

**Progetta, anteprima e stampa etichette industriali ZPL direttamente dal browser.**
Un editor drag‑and‑drop tipo BarTender, ma leggero, self‑hosted e open: niente driver proprietari, niente licenze a postazione, niente PDF intermedi — solo ZPL puro inviato alla stampante.

> Pensato per stampanti **Citizen CL‑S700 / CL‑S703Z** (emulazione ZPL II) e compatibile con qualsiasi stampante termica che parla ZPL (Zebra, ecc.).

---

## Perché Mojito

Stampare etichette in produzione è quasi sempre un dolore: software desktop costosi e legati a una singola postazione, template chiusi, driver capricciosi, nessuna API per automatizzare. Mojito ribalta il problema:

| Il solito modo | Con Mojito |
|----------------|-----------|
| Licenza per PC, software desktop | Gira nel **browser**, una sola installazione sul server |
| Template proprietari e chiusi | Layout in **JSON versionabile** (git‑friendly) |
| "Stampa" = PDF → driver → stampante | **ZPL diretto** in RAW: veloce e deterministico |
| Nessuna automazione | **API REST**: stampi da qualsiasi gestionale/PLC/linea |
| Anteprima "circa" | Anteprima **fedele al pixel** calibrata sul font reale della stampante |

Il risultato: chiunque in reparto disegna un'etichetta in 2 minuti, la salva sul server, e la linea la stampa via API passando solo i dati variabili.

## A chi serve

- **Produzione manifatturiera** — etichette prodotto, lotto, matricola, barcode
- **Logistica e magazzino** — etichette collo, ubicazione, spedizione
- **Laboratori e assemblaggio** — targhette tecniche, dati di targa, avvertenze
- **Chiunque abbia una stampante ZPL** e voglia un designer web + API senza vendor lock‑in

## Funzionalità principali

- 🎨 **Editor drag‑and‑drop** — testo, barcode (Code 128 / Code 39), QR e immagini
- 🔤 **Anteprima fedele** — font calibrato sul CG Triumvirate della stampante, dimensioni in dots/mm reali
- 🧩 **Named data sources** — separi il *layout* dai *dati*: lo stesso template stampa infiniti valori
- 📐 **Formati e DPI** — 203/300 dpi, formati standard o etichetta personalizzata, ridimensionamento automatico
- 🖱️ **Multi‑selezione, duplica, sposta** — anche elementi sovrapposti (Alt+clic per raggiungere quello sotto)
- 💾 **3 modi per salvare** — file `.mojito.json`, server, o `localStorage`
- 🔌 **API REST** — anteprima ZPL e stampa da qualsiasi sistema esterno
- 🖨️ **Stampa RAW** — CUPS `lp -o raw` su Linux, spooling raw su Windows
- 🖥️ **Desktop opzionale** — shell Electron con dialog di file nativi
- ✅ **Qualità industriale** — PHPStan level 9, test unitari front+back, mutation testing

## Come funziona

```
┌──────────────┐    JSON template     ┌──────────────┐    ZPL (^XA…^XZ)   ┌────────────┐
│  Designer web │ ───────────────────▶ │  Backend PHP  │ ─────────────────▶ │  Stampante │
│  (Vue 3)      │   + valori dati      │  ZplBuilder   │   RAW / CUPS       │  ZPL       │
└──────────────┘                       └──────────────┘                    └────────────┘
```

Un **template** (`{labelWidth, labelHeight, dpi, dataSources[], elements[]}`) è l'unica fonte di verità. Gli elementi referenziano un *data source* per nome invece di incorporare un valore fisso: così lo stesso layout stampa dati sempre diversi. Il backend trasforma template + valori in ZPL e lo invia alla stampante.

- **Frontend**: Vue 3 + Vite (Electron opzionale)
- **Backend**: PHP 8.2 senza framework, PSR‑4 `Mojito\Label\`

## Avvio rapido

```bash
# 1) Dipendenze
npm install
cd server && composer install && cd ..

# 2) Dev: frontend + backend PHP insieme (porta 5174)
npm run dev:all
```

Apri **http://localhost:5174** e inizia a progettare.

Solo frontend / solo backend:

```bash
npm run dev          # frontend (Vite)
npm run dev:server   # backend (php -S localhost:8080)
npm run dev:electron # shell desktop Electron
```

Test di stampa diretto da terminale:

```bash
printf '^XA^FO50,50^ADN,36,20^FDTEST^FS^XZ' | lp -d Citizen_CL_S703Z -o raw
```

### Requisiti

- Node.js 20+
- PHP 8.2+ con estensione GD (immagini `^GF`)
- Composer
- Una stampante ZPL configurata (es. CUPS `Citizen_CL_S703Z`)

## API

| Metodo | Endpoint | Descrizione |
|--------|----------|-------------|
| GET | `/api/health` | Health check |
| GET | `/api/printers` | Elenco stampanti |
| GET | `/api/template/default` | Template di esempio |
| GET | `/api/templates` | Elenco layout salvati |
| GET | `/api/templates/{id}` | Carica un layout |
| POST | `/api/templates` | Salva un layout |
| DELETE | `/api/templates/{id}` | Elimina un layout |
| POST | `/api/zpl/preview` | Genera ZPL da template + dati |
| POST | `/api/print` | Stampa etichetta |

**Stampare un layout salvato passando solo i dati variabili:**

```bash
curl -X POST http://localhost:8080/api/print \
  -H 'Content-Type: application/json' \
  -d '{"templateId":"mio-template","printer":"Citizen_CL_S703Z",
       "values":{"title":"PRODOTTO X","serial":"SN-001","barcode":"1234567890123"}}'
```

**Uso diretto della libreria PHP:**

```php
$service = new \Mojito\Label\LabelPrinterService();
$service->setPrinterName('Citizen_CL_S703Z');
$service->printLabel([
    'title'   => 'PRODOTTO X',
    'serial'  => 'SN-001',
    'barcode' => '1234567890123',
]);
```

## Integrazione come "station" (opzionale)

Mojito nasce come app‑station del gestionale **GreenEnergyServer** (Laravel), ma resta un progetto autonomo. Il deploy pubblica la build web e la libreria PHP dentro il server host:

```bash
npm run deploy:green-energy   # build + copia in public/stations/apps/mojito/
                              # poi sul server: composer dump-autoload -o
```

L'API Mojito viene servita dalle route Laravel (`/api/health`, `/api/print`, …): nessun processo PHP separato in produzione.

## Salvataggio layout

| Modo | Dove finisce |
|------|--------------|
| **Salva su file** | `.mojito.json` sul disco (dialog Electron o download browser) |
| **Salva server** | `server/storage/templates/{id}.json` |
| **Salva locale** | `localStorage` del browser (riapertura rapida da UI) |

Il file `.mojito.json` contiene struttura + data sources + elementi, **senza** valori di test — versionabile in git.

## Qualità del codice

| Check | Comando | Stato |
|-------|---------|-------|
| PHPStan **level 9** | `npm run analyse:server` | ✅ zero errori |
| Pint (code style) | `npm run pint:server:fix` | ✅ |
| PHPUnit (backend) | `npm run test:server` | ✅ |
| Vitest (frontend) | `npm test` | ✅ |
| Validazione completa | `npm run validate` | test + coverage + analisi |
| + Mutation testing | `npm run validate:full` | Infection + Stryker (~15 min) |

Mojito non usa Laravel, quindi al posto di Larastan usa **PHPStan level 9**. La logica di dominio (manipolazione template, geometria canvas, encoding barcode) è isolata in funzioni pure con copertura al 100%, target dei mutation test.

## Note tecniche

- Citizen CL‑S700 supporta emulazione **ZPL II** (Cross‑Emulation)
- Stampa in modalità **RAW** — nessun PDF/HTML intermedio, solo ZPL
- Immagini convertite in `^GFA` (ASCII‑hex)
- File temporanei ZPL rimossi dopo la stampa
- Anteprima calibrata contro [Labelary](https://labelary.com/zpl.html)

## Documentazione di riferimento

- [Citizen CL‑S700 Series](https://www.citizen-systems.com/en/products/printer/label/cl-s700)
- [ZPL II Programming Guide (Zebra)](https://support.zebra.com/cpws/docs/zpl/zpl_manual.pdf)
- [Labelary — anteprima ZPL online](https://labelary.com/zpl.html)

Per l'architettura interna (moduli, classi backend, flusso dati) vedi [`CLAUDE.md`](CLAUDE.md).

---

<p align="center"><sub>🍹 Mojito Label Designer · POC ZPL per Citizen CL‑S700 / CL‑S703Z</sub></p>
