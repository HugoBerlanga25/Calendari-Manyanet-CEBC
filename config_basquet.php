<?php
// Copia de la configuración de la web 'basquet' SIN claves (este repositorio es público). Solo para el actualizador.
/**
 * Configuració de la plataforma · Bàsquet Pare Manyanet (Reus)
 *
 * Aquest fitxer no imprimeix res si algú l'obre pel navegador.
 *
 * Opcional: si crees un fitxer config.local.php (que retorni un array) al costat d'aquest,
 * els seus valors substitueixen els d'aquí. Serveix per guardar la clau fora del repositori
 * (config.local.php ja és al .gitignore).
 */
$cfg = [

    /* ---------------------------------------------------------------
     * SEGURETAT
     * Clau secreta per llançar actualizar.php des del cron.
     * Si el repositori és públic, canvia-la o posa-la a config.local.php.
     * Mínim 12 caràcters; amb la clau per defecte (CANVIA_AQUESTA_CLAU) no funciona.
     * --------------------------------------------------------------- */
    'clau_actualitzacio' => 'no-se-usa-aqui',

    /* ---------------------------------------------------------------
     * FONT DE DADES (CEBC)
     * season=2 correspon a 2026-2027 i sport=1 a Bàsquet, tal com
     * apareix a la URL oficial de resultats.
     * --------------------------------------------------------------- */
    'base_url'     => 'https://gestioesportiva.cebc.cat',
    // 'auto' detecta sola la temporada més recent del desplegable del CEBC (ara 2026-2027 = 2).
    // Es pot fixar a mà amb un número. Si la temporada nova encara no té cap equip del club,
    // es conserven les dades de l'anterior fins que n'aparegui un.
    'temporada_id' => 'auto',
    'esport_id'    => 1,   // 1 = Bàsquet

    // Paraula que identifica el club dins dels noms d'equip (sense accents ni majúscules).
    'club_clau' => 'manyanet',

    /* Calendaris:
     *  'fallback' -> només es consulten si la pàgina de resultats falla o ve buida (recomanat:
     *                les pàgines de resultats ja inclouen lloc, data i hora de cada partit).
     *  'sempre'   -> es consulten sempre i es fusionen amb els resultats (duplica les peticions).
     *  'mai'      -> no es consulten.
     */
    'usar_calendari' => 'fallback',

    /* ---------------------------------------------------------------
     * RENDIMENT I CORTESIA AMB EL SERVIDOR DEL CEBC
     * --------------------------------------------------------------- */
    'pressupost_segons'    => 25,   // temps màxim d'una actualització (els allotjaments gratuïts en limiten l'execució)
    'concurrencia'         => 4,    // peticions simultànies màximes
    'timeout_peticio'      => 20,   // segons per petició
    'reintents'            => 2,
    'ssl_verify'           => true, // deixa-ho a true; només a false si l'allotjament no té bundle de CA
    // Identifica el club. Compte: el servidor del CEBC rebutja els User-Agent que contenen "(compatible; ...)".
    'user_agent'           => 'Mozilla/5.0 ManyanetBasquet/1.0',

    // Actualització "per visita" (xarxa de seguretat si el cron falla).
    'auto_visita'          => true,
    'interval_visita_min'  => 30,   // no refresca si les dades tenen menys minuts que això
    'interval_intent_min'  => 10,   // ni reintenta si l'últim intent (fallit o no) és més recent

    /* ---------------------------------------------------------------
     * EQUIPS DEL CLUB
     * Es recorren tots els grups de la temporada i s'assignen els equips del Pare Manyanet
     * segons categoria / grup / nom d'equip. Les expressions regulars s'apliquen sobre
     * text en minúscules i SENSE accents.
     *
     *   categoria : regex sobre el nom de la categoria  (p. ex. "benjami masculi")
     *   grup      : regex opcional sobre el nom del grup (p. ex. "1a fase bm - grup 2")
     *   equip     : regex opcional sobre el nom de l'equip del club (p. ex. "verd")
     *   subtitol  : text fix, 'auto' (el treu del nom de l'equip: 1r, 2n...) o null
     *   color     : 'verd' | 'groc' | 'mixt'
     *
     * Si apareix un equip del club que no encaixa amb cap regla (p. ex. una nova categoria
     * a la segona fase), es crea automàticament i surt igualment a la web.
     * --------------------------------------------------------------- */
    'equips' => [
        [
            'id' => 'prebenjami-groc', 'nom' => 'Prebenjamí Groc', 'color' => 'groc',
            'categoria' => '/^prebenjami$/', 'equip' => '/\bgroc\b/', 'subtitol' => 'auto',
        ],
        [
            'id' => 'prebenjami-verd', 'nom' => 'Prebenjamí Verd', 'color' => 'verd',
            'categoria' => '/^prebenjami$/', 'equip' => '/\bverd\b/', 'subtitol' => 'auto',
        ],
        [
            // Benjamí Grup 1 = Benjamí Groc (alumnes de 3r)
            'id' => 'benjami-groc', 'nom' => 'Benjamí Groc', 'color' => 'groc',
            'categoria' => '/^benjami masculi$/', 'grup' => '/grup\s*1\b/', 'subtitol' => '3r de Primària',
        ],
        [
            // Benjamí Grup 2 = Benjamí Verd (alumnes de 4t)
            'id' => 'benjami-verd', 'nom' => 'Benjamí Verd', 'color' => 'verd',
            'categoria' => '/^benjami masculi$/', 'grup' => '/grup\s*2\b/', 'subtitol' => '4t de Primària',
        ],
        [
            'id' => 'benjami-femeni', 'nom' => 'Benjamí Femení', 'color' => 'mixt',
            'categoria' => '/^benjami femeni$/', 'subtitol' => null,
        ],
    ],
];

// Valors locals opcionals (no es pugen al repositori)
$local = __DIR__ . '/config.local.php'; // no existe en el repositorio
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) {
        $cfg = array_replace($cfg, $extra);
    }
}

return $cfg;
