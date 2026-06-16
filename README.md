# Mojito — Label Designer POC

POC di interfaccia per la stampa etichette ZPL su **Citizen CL-S703Z** (famiglia CL-S700), simile a BarTender: posizionamento di testi, barcode con named data sources e immagini.

## Stack

- **Frontend**: Vue 3 + Vite (+ Electron opzionale), come GreenEnergy_Client
- **Backend**: PHP 8.2 — generazione ZPL e invio a CUPS via `lp -o raw`

## Requisiti

- PHP 8.2+ con estensione GD (per immagini ^GF)
- Composer
- Node.js 20+
- CUPS con stampante configurata: `Citizen_CL_S703Z`

### Station GreenEnergyServer

Come **Foto / ET54 / Sorting**, la build web viene pubblicata su GreenEnergy:

```bash
cd Mojito
npm run deploy:green-energy
```

Apri: `http://<server>/stations/apps/mojito/`

L’API Mojito è integrata in Laravel (route `/api/health`, `/api/print`, …) — **non serve** `composer serve` sulla porta 8080 quando usi la station.

Il deploy copia anche il PHP in `GreenEnergyServer/lib/mojito-label/src/` (autoload self-contained nel repo). Dopo deploy esegui `composer dump-autoload -o` sul server.

Template salvati sul server: `GreenEnergyServer/storage/app/mojito/templates/`.

## Avvio rapido (sviluppo standalone)

```bash
# Backend PHP
cd server
composer install
composer serve

# Frontend (altro terminale, dalla root Mojito)
npm install
npm run dev
```

Apri http://localhost:5174

Oppure tutto insieme:

```bash
npm install
cd server && composer install && cd ..
npm run dev:all
```

## Stampa da terminale (test diretto)

```bash
printf '^XA^FO50,50^ADN,36,20^FDTEST CITIZEN^FS^XZ' | lp -d Citizen_CL_S703Z -o raw
```

## Salvataggio layout su file

Hai **3 modi** per salvare un layout:

| Pulsante | Dove finisce |
|----------|--------------|
| **Salva su file** | File `.mojito.json` sul disco (dialog Electron, oppure download nel browser) |
| **Salva server** | `server/storage/templates/{id}.json` |
| **Salva locale** | Browser `localStorage` (solo per riaprire da UI) |

### Formato file `.mojito.json`

Contiene struttura etichetta + data sources + elementi posizionati, **senza** valori di test:

```json
{
  "id": "cavallini-service",
  "name": "Cavallini Service",
  "labelWidth": 600,
  "labelHeight": 400,
  "dpi": 203,
  "dataSources": [
    { "name": "title", "label": "Titolo" },
    { "name": "barcode", "label": "Barcode" }
  ],
  "elements": [ ... ]
}
```

### Electron (dialog nativo)

```bash
npm run dev:electron
```

Salva/apri da `Documenti/Mojito/layouts/` (cartella suggerita nel dialog).

## API

| Metodo | Endpoint | Descrizione |
|--------|----------|-------------|
| GET | `/api/health` | Health check |
| GET | `/api/printers` | Elenco stampanti CUPS |
| GET | `/api/template/default` | Template di esempio |
| GET | `/api/templates` | Elenco layout salvati sul server |
| GET | `/api/templates/{id}` | Carica un layout |
| POST | `/api/templates` | Salva un layout |
| DELETE | `/api/templates/{id}` | Elimina un layout |
| POST | `/api/zpl/preview` | Genera ZPL da template + dati |
| POST | `/api/print` | Stampa etichetta |

### Esempio stampa rapida (JSON)

```json
{
  "title": "CAVALLINI SERVICE",
  "product": "Test",
  "serial": "ABC123",
  "barcode": "ABC123456789",
  "printer": "Citizen_CL_S703Z"
}
```

### Esempio con template salvato (via API)

Dopo aver salvato un layout con **Salva server**, puoi stampare passando solo l'id e i valori:

```json
{
  "templateId": "cavallini-service",
  "printer": "Citizen_CL_S703Z",
  "values": {
    "title": "CAVALLINI SERVICE",
    "product": "Prodotto X",
    "serial": "SN-001",
    "barcode": "1234567890123"
  }
}
```

```bash
curl -X POST http://localhost:8080/api/print \
  -H 'Content-Type: application/json' \
  -d '{"templateId":"cavallini-service","values":{"title":"TEST","product":"A","serial":"1","barcode":"999"}}'
```

### Esempio con template inline

```json
{
  "printer": "Citizen_CL_S703Z",
  "template": { "...": "..." },
  "values": {
    "title": "CAVALLINI SERVICE",
    "barcode": "ABC123456789"
  }
}
```

## LabelPrinterService (PHP)

```php
$service = new \Mojito\Label\LabelPrinterService();
$service->setPrinterName('Citizen_CL_S703Z');
$service->printLabel([
    'title' => 'CAVALLINI SERVICE',
    'product' => 'Test',
    'serial' => 'ABC123',
    'barcode' => 'ABC123456789',
]);
```

Metodi:
- `buildZpl(array $data): string`
- `printZpl(string $zpl): void`
- `printLabel(array $data): void`

## Test e qualità del codice

Allineato a **GreenEnergyServer** / **GreenEnergy_Client** (PHPStan level 9 al posto di Larastan — Mojito non usa Laravel).

### Backend (`server/`)

| Tool | Comando | Descrizione |
|------|---------|-------------|
| PHPUnit | `composer test` | 62 test unitari |
| PHPStan | `composer analyse` | Analisi statica **level 9** |
| Pint | `composer pint` | Code style Laravel (check) |
| Pint fix | `composer pint:fix` | Applica fix automatici |
| Infection | `composer mutation` | Mutation testing (MSI ≥ 80%) |
| Tutto | `composer quality` | pint + analyse + test |

Coverage con Xdebug:

```bash
cd server && composer test:coverage
XDEBUG_MODE=coverage composer mutation
```

### Frontend

| Tool | Comando | Descrizione |
|------|---------|-------------|
| Vitest | `npm test` | 35 test su `src/utils/` |
| Coverage | `npm run test:coverage` | 100% linee su utils |
| Stryker | `npm run mutation` | Mutation testing (≥ 80%) |

### Validazione completa (root)

```bash
npm run validate          # test + coverage frontend + pint/phpstan/phpunit server
npm run validate:full     # validate + Infection + Stryker (opzionale, ~15 min)
```

**Stato attuale:**

| Check | Risultato |
|-------|-----------|
| PHPStan level 9 | ✅ zero errori |
| Pint | ✅ |
| PHPUnit (62 test) | ✅ |
| Vitest (35 test, 100% linee utils) | ✅ |
| Infection MSI | ~42% (target 80%) |
| Stryker MSI | ~62% (target 80%) |

> Mojito non usa Laravel: al posto di **Larastan** usiamo **PHPStan level 9**.
> I mutation test sono configurati come GreenEnergy ma il target 80% richiede ancora più test.

## Note tecniche

- La stampante Citizen CL-S700 supporta emulazione **ZPL2** (Cross-Emulation)
- Su Linux/Kali la stampa funziona in modalità **RAW** via CUPS
- Non vengono generati PDF/HTML — solo ZPL puro
- I file temporanei ZPL vengono eliminati dopo la stampa
- Le immagini vengono convertite in formato `^GFA` (ASCII hex)

## Documentazione di riferimento

- [Citizen CL-S700 Series](https://www.citizen-systems.com/en/products/printer/label/cl-s700)
- [Citizen ZPL Command Reference](https://www.citizen-systems.com/en/support/manuals-and-datasheets/CL-S700)
- [ZPL II Programming Guide (Zebra)](https://support.zebra.com/cpws/docs/zpl/zpl_manual.pdf)
- [Labelary ZPL intro](https://labelary.com/zpl.html)
