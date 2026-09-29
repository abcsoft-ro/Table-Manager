# Baza de date - reconstrucție

Aplicația folosește baza de date MSSQL `Rual`. Acest director conține tot ce este
necesar pentru a o reconstrui de la zero.

| Fișier | Rol |
|---|---|
| `schema.sql` | structura tabelelor, indecși, constrângeri, chei străine și procedurile stocate |
| `seed.sql` | datele de referință minime + un meniu demo (fără date reale) |

## Cerințe

- Microsoft SQL Server.
- Un client SQL (SQL Server Management Studio, Azure Data Studio sau `sqlcmd`).

## Reconstrucție în 3 pași

1. **Creați structura** — rulați `schema.sql`. Scriptul creează baza `Rual` dacă nu
   există, apoi tabelele, indecșii, constrângerile, cheile străine și procedurile.
2. **Adăugați datele de referință** — rulați `seed.sql` (după `schema.sql`, pe o bază
   goală). Aduce setările, cotele TVA, formele de plată, secțiile, mesele, șablonul de
   notă, un casier demo și un meniu demo, astfel încât aplicația să pornească imediat.
3. **Configurați conexiunea** — copiați `api/db.local.example.php` în `api/db.local.php`
   și completați serverul, baza de date, utilizatorul și parola.

Ambele scripturi sunt idempotente: le puteți rula din nou fără să apară erori
(`schema.sql` folosește `IF ... IS NULL`, `seed.sql` șterge și re-inserează doar
tabelele de referință).

### Din SSMS / Azure Data Studio

Deschideți `schema.sql`, executați-l, apoi deschideți și executați `seed.sql`.

### Din linia de comandă (`sqlcmd`)

```bat
sqlcmd -S <server> -U sa -P <parola> -i db\schema.sql
sqlcmd -S <server> -U sa -P <parola> -i db\seed.sql
```

## Obiecte create automat de aplicație

La runtime, aplicația își creează singură, dacă lipsesc:

- tabela `tblPrintQueue` (coada durabilă de printare) - `ensurePrintQueueTable()`;
- tabela `temp_Send_Sql` (coada durabilă de export către serverul extern) - `ensureSendSqlTable()`;
- procedura `RealizeazaZ` (închiderea Z) - `ensureZProcedure()`.

Sunt incluse oricum în `schema.sql` pentru o instalare completă, dar nu este o problemă
dacă lipseau.

## Tabele incluse

`tblTVA`, `tblSectii`, `tblFP`, `tblKP`, `tblSet`, `tblParola`, `tblOsp`, `tblAntet`,
`tblMese`, `tblMesaj`, `tblGrp`, `tblProd`, `tblBonCurent`, `tblNoteD`, `tblBon`,
`tempECR`, `tblNrZ`, `trelDocIDFpID`, `trelArhZDocIDFpID`, `tblPrintQueue`,
`temp_Send_Sql`, `tblConectare`, `ERRORLOG`.

Proceduri stocate: `RealizeazaZ`, `ImportProd`, `usp_GetErrorInfo`.

## Date demo, nu date reale

`seed.sql` este sanitizat: nu conține date reale de vânzări, meniu real, date de firmă,
parole sau credențiale.

- `tblParola` - toate parolele sunt goale (fără parolă). Setați-le din ecranul Programare.
- `tblOsp` - casieri demo (`Ospatar N`), parola demo `1`.
- `tblAntet` - firmă demo (`RESTAURANT DEMO`), fără date fiscale reale.
- `tblConectare` și cheile sensibile din `tblSet` (`ConsumerKey`, `ConsumerSecret`,
  `SiteUrlApi`, căile locale) sunt golite / puse pe valori demo.
- `tblGrp` și `tblProd` conțin un meniu demo (8 grupe, 18 produse), nu meniul real.
