<?php
/**
 * Conversie culori stocate in baza (Windows COLORREF 0x00BBGGRR) -> hex #rrggbb.
 *
 * Gestioneaza si culorile de sistem VBA / SystemColorConstants (Access/VB), care
 * NU sunt COLORREF reale: au bitul 0x80000000 set (deci valoare negativa), ex.
 * vbButtonFace = &H8000000F = -2147483633. Fara mapare, astfel de valori sunt
 * interpretate gresit si ies aproape negre (negru pe negru).
 */

/**
 * Mapeaza un index de culoare de sistem VBA la o culoare concreta.
 * Indexuri: SystemColorConstants (0x00..0x18). Implicit: gri neutru.
 */
function vbaSystemColorToHex($index) {
    static $map = [
        0x00 => "#000000", // ScrollBars
        0x01 => "#000000", // Desktop
        0x02 => "#000080", // ActiveTitleBar
        0x03 => "#808080", // InactiveTitleBar
        0x04 => "#c0c0c0", // MenuBar
        0x05 => "#ffffff", // WindowBackground
        0x06 => "#000000", // WindowFrame
        0x07 => "#000000", // MenuText
        0x08 => "#000000", // WindowText
        0x09 => "#ffffff", // TitleBarText
        0x0A => "#c0c0c0", // ActiveBorder
        0x0B => "#c0c0c0", // InactiveBorder
        0x0C => "#c0c0c0", // ApplicationWorkspace
        0x0D => "#000080", // Highlight
        0x0E => "#ffffff", // HighlightText
        0x0F => "#d4d0c8", // ButtonFace (gri)
        0x10 => "#808080", // ButtonShadow
        0x11 => "#808080", // GrayText
        0x12 => "#000000", // ButtonText
        0x13 => "#c0c0c0", // InactiveCaptionText
        0x14 => "#ffffff", // 3DHighlight
        0x15 => "#000000", // 3DDKShadow
        0x16 => "#d4d0c8", // 3DLight
        0x17 => "#000000", // InfoText
        0x18 => "#ffffe1", // InfoBackground
    ];
    return $map[$index] ?? "#d4d0c8";
}

/**
 * Converteste o valoare de culoare stocata in baza la hex #rrggbb.
 * Intoarce null pentru null / string gol.
 */
function winColorToHex($colorVal) {
    if ($colorVal === null || $colorVal === '') return null;
    $val = (int)$colorVal;
    $u = $val < 0 ? ($val + 4294967296) : $val;
    // Culoare de sistem VBA: 0x80000000 | index (index <= 0x18).
    if (($u & 0xFFFFFF00) === 0x80000000) {
        return vbaSystemColorToHex($u & 0xFF);
    }
    $r = $val & 0xFF;
    $g = ($val >> 8) & 0xFF;
    $b = ($val >> 16) & 0xFF;
    return sprintf("#%02x%02x%02x", $r, $g, $b);
}
