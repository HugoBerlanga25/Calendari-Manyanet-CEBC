<?php
// Copia de la configuración de la web 'agenda' SIN claves (este repositorio es público). Solo para el actualizador.
/**
 * Configuración · Mis partidos (Benjamí Verd + Infantil)
 * Este archivo no imprime nada si se abre desde el navegador.
 * Opcional: config.local.php (mismo formato) sustituye valores de aquí y no se sube al repositorio.
 */
$cfg = [

    // Contraseña para ENTRAR en la web (protege plantilla, quintetos y disponibilidad). Mínimo 6 caracteres.
    // Si el repositorio es público, cámbiala o ponla en config.local.php.

    // Clave larga SOLO para el cron: actualizar.php?clau=... (no es la de entrar).
    'clau_actualitzacio' => 'no-se-usa-aqui',

    // Fuente de datos del Benjamí Verd (CEBC)
    'base_url'     => 'https://gestioesportiva.cebc.cat',
    'temporada_id' => 'auto',   // detecta sola la temporada más reciente
    'esport_id'    => 1,
    'club_clau'    => 'manyanet',
    'usar_calendari' => 'fallback',

    // Solo se descargan los grupos donde juega el equipo definido abajo
    'filtre_grups'   => '/^benjami masculi/',
    'nomes_definits' => true,
    'auto_equips'    => false,

    'pressupost_segons' => 25,
    'concurrencia'      => 4,
    'timeout_peticio'   => 20,
    'reintents'         => 2,
    'ssl_verify'        => true,
    'user_agent'        => 'Mozilla/5.0 ManyanetBasquet/1.0',

    // Actualización automática al abrir la web (y con la web abierta)
    'auto_visita'         => true,
    'interval_visita_min' => 20,
    'interval_intent_min' => 8,

    // Tu equipo: el Manyanet Verd de Benjamí Masculí
    'equips' => [
        [
            'id' => 'benjami-verd', 'nom' => 'Benjamí Verd', 'color' => 'verd',
            'categoria' => '/^benjami masculi$/', 'equip' => '/\bverd\b/', 'subtitol' => '4t de Primària',
        ],
    ],

    // Valores iniciales de los ajustes (luego se cambian desde la pestaña Plantilla)
    'app' => [
        'nom_infantil' => 'Infantil',
        'quart'        => 10,   // minutos por cuarto
        'durada'       => 90,   // duración estimada de un partido (min)
        'marge'        => 30,   // margen de desplazamiento entre pabellones (min)
    ],
];

$local = __DIR__ . '/config.local.php'; // no existe en el repositorio
if (is_file($local)) {
    $extra = require $local;
    if (is_array($extra)) {
        $cfg = array_replace($cfg, $extra);
    }
}
return $cfg;
