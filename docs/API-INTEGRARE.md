# API de integrare (aplicații terțe) — `/api/v1/`

API-ul public permite aplicațiilor terțe (servere / backend-uri) să **exporte**
(ci­tire) și să **importe** (scriere) date din TableManager. Toate răspunsurile
sunt JSON (`JSON_UNESCAPED_UNICODE`).

## Autentificare

- Se folosește o **singură cheie globală**, trimisă în headerul `X-Api-Key`
  (acceptat și `Authorization: Bearer <cheie>`).
- Cheia se generează din **Setări → Integrare** (pornirea API-ului: `ApiEnabled = DA`).
  Se afișează o singură dată; în baza de date se păstrează doar SHA‑256.
- Opțional, se pot limita IP-urile (`ApiAllowedIps`) și numărul de cereri/minut
  per IP (`ApiRateLimit`).

```bash
curl -s -H "X-Api-Key: tm_..." http://<statie>/rual/api/v1/version
```

## Format răspuns

Succes:
```json
{ "status": "success", "data": { ... }, "meta": { ... } }
```
Eroare: `{ "status": "error", "message": "..." }` cu cod HTTP 400/401/403/404/409/429/500/503.

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

### Sincronizare incremententă (`events`)

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

| Metodă | Rută | Câmpuri |
|---|---|---|
| POST | `/api/v1/products` | `prodId`, `nrGrp`, `denumire` (obligatorii); `barCod`, `um`, `kp`, `sectie`, `pv`, `nrTva`, `mz`, `magId`, `poz`, culori/font, `bold`. |
| POST | `/api/v1/groups` | `nrGrp`, `denumire` (obligatorii); `poz`, culori/font, `bold`. |
| POST | `/api/v1/tables` | `nrMasa` (1‑80, obligatoriu); `backColor`, `foreColor`, `bold`, `afisez`, `obs`. |
| POST | `/api/v1/waiters` | `nrOsp` (obligatoriu); `nume`, `expl`, `parola`, `blocat`. |
| POST | `/api/v1/payment-forms` | `fpid` (obligatoriu); `denumire`, `status`, `poz`. |
| POST | `/api/v1/vat` | `nrTva`, `cota` (obligatorii). |

Importul de **produse/grupe** este refuzat (409) când `tblSet.Server = 1`
(meniul este administrat de serverul extern prin `ImportProd`).

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

### Alternativă: Tailscale / ZeroTier

Rețele mesh bazate pe WireGuard, cu NAT traversal: nu necesită IP public,
port forwarding sau un server dedicat. Atât stația POS cât și serverul cloud
intră în aceeași rețea virtuală, iar API-ul se accesează la IP-ul Tailscale /
ZeroTier al stației. Setați `ApiAllowedIps` pe subrețeaua mesh corespunzătoare.

## Note

- CORS nu este emis (consumatorii țintă sunt servere/backend-uri, nu browsere).
- Audit: fiecare cerere se scrie în `tblApiLog` (endpoint, metodă, cod, IP, cheie
  de idempotență).
