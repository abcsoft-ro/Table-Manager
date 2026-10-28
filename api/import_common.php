<?php
/**
 * Importul produselor/grupelor de pe serverul extern (procedura dbo.ImportProd).
 *
 * Procedura are o singura sursa: api/sql/import_prod_procedure.sql. Este
 * (re)aplicata cu CREATE OR ALTER inainte de fiecare import, ca o statie care
 * are o versiune veche sa primeasca automat varianta corecta:
 *   - @user / @PassWord nvarchar(128)  -> parola nu mai e trunchiata la 10;
 *   - mapping explicit pe linked server (nu anonim) si "data access" = true;
 *   - SET XACT_ABORT ON + CATCH fara usp_GetErrorInfo (nu mai masca eroarea cu
 *     3930).
 *
 * Nu arunca exceptii: intoarce null la succes sau un mesaj de eroare.
 */

/**
 * @param resource $conn
 * @return string|null
 */
function ensureImportProdProcedure($conn) {
    static $state = null; // null = neincercat, true = ok, string = eroare
    if ($state === true) { return null; }
    if (is_string($state)) { return $state; }

    $file = __DIR__ . '/sql/import_prod_procedure.sql';
    $sql = @file_get_contents($file);
    if ($sql === false || trim($sql) === '') {
        $state = "Nu pot citi $file";
        return $state;
    }

    $stmt = @sqlsrv_query($conn, $sql);
    if ($stmt === false) {
        $err = sqlsrv_errors();
        $state = 'Nu pot actualiza procedura ImportProd: '
            . ($err ? $err[0]['message'] : 'eroare necunoscuta');
        return $state;
    }
    @sqlsrv_free_stmt($stmt);

    $state = true;
    return null;
}
