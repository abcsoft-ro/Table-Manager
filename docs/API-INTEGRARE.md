# API de integrare (aplicații terțe) — `/api/v1/`

API-ul public permite aplicațiilor terțe (servere / backend-uri) să **exporte**
(citire) și să **importe** (scriere) date din TableManager. Toate răspunsurile
sunt JSON (`JSON_UNESCAPED_UNICODE`).

> Documente însoțitoare:
> - `docs/openapi.json` — specificația **OpenAPI 3.0** (machine-readable; import în Postman/Insomnia).
> - `docs/api-reference.html` — referință HTML self-contained (se deschide în browser).

## Autentificare

- Se folosește o **singură cheie globală**, trimisă în headerul `X-Api-Key`
  (acceptat și `Authorization: Bearer <cheie>`).
- Cheia se generează din **Setări → Integrare** (pornirea API-ului: `ApiEnabled = DA`).
  Se afișează o singură dată; în baza de date se păstrează doar SHA-256.
- Opțional, se pot limita IP-urile (`ApiAllowedIps`) și numărul de cereri/minut
  per IP (`ApiRateLimit`).

```bash
curl -s -H "X-Api-Key: tm_..." http://<statie>/rual/api/v1/version
```

## Format răspuns

Succes:
```json
{ "status": "success", "data": { }, "meta": { } }
```
Eroare: `{ "status": "error", "message": "..." }` cu cod HTTP 400/401/403/404/405/409/429/500/503.

Paginare: `?limit=` (implicit 100, maxim 500) și `?offset=`; `meta` conține
`limit`, `offset`, `count`, `hasMore` (și `total` unde există).

## Export (citire)

| Metodă | Rută | Descriere |
|---|---|---|
| GET | `/api/v1/version` | Versiunea aplicației (human + commit + build). |
| GET | `/api/v1/products?nrgrp=&mz=&kp=&limit=&offset=` | Produse (tblProd). |
| GET | `/api/v1/groups` | Grupe din meniu (tblGrp). |
| GET | `/api/v1/tables` | Starea celor 80 de mese. |
| GET | `/api/v1/orders?stare=D\|I&from=&to=&source=current\|archive&limit=&offset=` | Bonuri (sesiunea curentă sau arhiva după Z). |
| GET | `/api/v1/orders/{docId}?source=current\|archive` | Un bon: antet + linii + plăți. |
| GET | `/api/v1/reports/{plu\|grupe\|sectii\|casieri\|general}` | Rapoarte X (doar bonuri închise). |
| GET | `/api/v1/events?since=&limit=` | Flux incremental de bonuri închise (pentru polling). |

`from`/`to` sunt date (ex. `2026-10-15`); `to` este exclusiv până la sfârșitul zilei.

### Structuri de răspuns

**Produs** (`data[]` la `/products`):
`prodId` int, `barCod` string, `denumire` string, `um` string, `nrGrp` int,
`kp` int|null, `sectie` int|null, `pv` number, `nrTva` int|null, `mz` 0/1,
`magId` int|null, `poz` int, `backColor`/`fontColor`/`fontSize` int|null,
`fontType` string, `bold` bool, `imagine` string, `dataUM` string|null.

**Grupă** (`data[]` la `/groups`):
`nrGrp` int, `denumire` string, `poz` int, `backColor`/`fontColor`/`fontSize` int|null,
`fontType` string, `bold` bool.

**Masă** (`data[]` la `/tables`):
`nrMasa` int, `isOccupied` bool, `present` bool, `casier` string|null,
`nrOp` int|null, `total` number, `docId` int|null, `backColor`/`foreColor` int|null,
`bold` bool, `afisez` bool.

**Antet bon** (`data[]` la `/orders`, `data[]` la `/events`):
`docId` int, `nrDoc` int, `nrZ` int|null, `data` string|null, `ora` string|null,
`nrMasa` int|null, `nrOp` int|null, `casier` string, `stare` string, `total` number,
`magId` int|null, `clientId` string|null, `codFiscBon` string|null,
`source` `"current"|"archive"`.

**Bon complet** (`data` la `/orders/{docId}`): antetul de mai sus +
`lines[]` și `payments[]`.
- linie: `ecrId` int, `prodId` int, `denumire` string, `cantitate` number,
  `pretUnitar` number, `pvc` number, `valoare` number, `valoareOriginala` number,
  `tva` int, `comment` string, `stornoRef` int|null, `isModifier` bool,
  `isStorno` bool.
- plată: `fpid` int, `denumire` string, `suma` number.

**Rapoarte** (`data`):
- `plu`: `sectii[]{sectie, rows[]{prodId, produs, cant, valoare}, subtotalCant, subtotalValoare}`, `totalCant`, `totalValoare`.
- `grupe`: `grupe[]{nrGrp, denumire, valoare}`, `totalValoare`.
- `sectii`: `sectii[]{sectie, denumire, valoare}`, `totalValoare`.
- `casieri`: `casieri[]{nrOp, nume, nrBonuri, reducere, stornari, total}` + `totalBonuri`/`totalReducere`/`totalStornari`/`totalValoare`.
- `general`: `totalBonuri`, `totalDiscount`, `totalStornari`, `totalValoare`, `plati[]{fpid,denumire,suma}`, `tva[]{cota,total,baza,suma}`, `note[]{nrNota,nrMasa,ospatar,total}`.

### Sincronizare incrementală (`events`)

Rețineți `meta.nextSince` și trimiteți-l la următoarea cerere ca `since`.
Se returnează doar bonurile închise cu `DocID > since` (sesiunea curentă +
arhiva), deci fără dubluri.

```bash
curl -s -H "X-Api-Key: tm_..." "http://<statie>/rual/api/v1/events?since=1042"
```

## Import (scriere)

Corpul acceptă un singur obiect, `{ "items": [ ... ] }` sau un array de obiecte.
Se scrie în tranzacție. Pentru reîncercări sigure, trimiteți headerul
`Idempotency-Key`; un POST cu aceeași cheie returnează răspunsul original.
Răspuns de succes: `{ "status":"success", "data": { "saved": N } }`.

Importul de **produse/grupe** este refuzat (409) când `tblSet.Server = 1`
(meniul este administrat de serverul extern prin `ImportProd`).

### `POST /api/v1/products` (upsert după `prodId`)

| Câmp | Tip | Obligatoriu | Implicit |
|---|---|---|---|
| `prodId` | int | **da** | — |
| `nrGrp` | int | **da** | — |
| `denumire` | string | **da** | — |
| `barCod` | string(15) | nu | `""` |
| `um` | string(3) | nu | `"buc"` |
| `kp` | int\|null | nu | null |
| `sectie` | int\|null | nu | null |
| `pv` | number | nu | 0 |
| `nrTva` | int\|null | nu | null |
| `mz` | 0/1 | nu | 0 |
| `magId` | int\|null | nu | null |
| `poz` | int\|null | nu | null |
| `backColor`/`fontColor`/`fontSize` | int\|null | nu | null |
| `fontType` | string | nu | `""` |
| `bold` | bool | nu | false |

### `POST /api/v1/groups` (upsert după `nrGrp`)

`nrGrp` (int, **obligatoriu**), `denumire` (string, **obligatoriu**), `poz`,
`backColor`/`fontColor`/`fontSize`, `fontType`, `bold`.

### `POST /api/v1/tables` (upsert după `nrMasa`)

`nrMasa` (int 1‑80, **obligatoriu**), `backColor`, `foreColor`, `bold` (implicit
true), `afisez` (implicit true), `obs` string(10).

### `POST /api/v1/waiters` (upsert după `nrOsp`)

`nrOsp` (string(2), **obligatoriu**), `nume`, `expl`, `parola`, `blocat` bool.

### `POST /api/v1/payment-forms` (upsert după `fpid`)

`fpid` (int, **obligatoriu**; `fpid=1` = card), `denumire`, `status` int
(implicit 1), `poz`.

### `POST /api/v1/vat` (upsert după `nrTva`)

`nrTva` (int, **obligatoriu**), `cota` (int, **obligatoriu**).

```bash
curl -s -X POST -H "X-Api-Key: tm_..." -H "Content-Type: application/json" \
  -H "Idempotency-Key: prod-2026-10-15" \
  -d '{"items":[{"prodId":101,"nrGrp":3,"denumire":"Cafea","pv":9.5,"nrTva":2}]}' \
  http://<statie>/rual/api/v1/products
```

## Acces extern (VPN)

Aplicația rulează pe **HTTP** și **nu se expune pe internet**. Accesul din
exterior se face **exclusiv prin VPN** — fără domeniu, certificat sau TLS la
nivel de aplicație.

### Recomandat: SoftEther VPN Azure + SecureNAT

- Pe stația POS (Windows) rulează **SoftEther VPN Server** cu un Virtual Hub
  dedicat (ex. `POS`), **SecureNAT** activat și **VPN Azure** activat. VPN Azure
  atribuie un hostname fix de forma `vpn…vpnazure.net`; nu este nevoie de IP
  public sau de port forwarding pe router.
- Clientul (serverul cloud) se conectează cu **SoftEther VPN Client** la acel
  hostname și primește un IP din subrețeaua virtuală (implicit
  `192.168.30.0/24`).
- API-ul se accesează prin **IP-ul virtual al gateway-ului** (chiar stația POS),
  în interiorul tunelului criptat SoftEther:

```bash
curl -s -H "X-Api-Key: tm_..." http://192.168.30.1/rual/api/v1/version
```

- **Router: zero porturi deschise.** POS-ul inițiază conexiunea spre exterior.
- Setați `ApiAllowedIps = 192.168.30.0/24` (sau doar IP-ul virtual al
  clientului) ca al doilea strat de apărare, peste VPN.
- SoftEther VPN se descarcă de la <https://www.softether.org/5-download>.

### Alternativă: Tailscale / ZeroTier

Rețele mesh bazate pe WireGuard, cu NAT traversal: nu necesită IP public,
port forwarding sau un server dedicat. Atât stația POS cât și serverul cloud
intră în aceeași rețea virtuală, iar API-ul se accesează la IP-ul Tailscale /
ZeroTier al stației. Setați `ApiAllowedIps` pe subrețeaua mesh corespunzătoare.

## Note

- CORS nu este emis (consumatorii țintă sunt servere/backend-uri, nu browsere).
- Audit: fiecare cerere se scrie în `tblApiLog` (endpoint, metodă, cod, IP, cheie
  de idempotență).
