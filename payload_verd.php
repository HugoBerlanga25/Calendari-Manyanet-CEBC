<?php
/**
 * Payload del grupo completo del Benjamí Verd (verd.json). Se usa junto a payload_agenda.php, que aporta
 * cebc_carrega_data(), cebc_data_text() y cebc_json().
 */
if (!function_exists('verd_ultim_intent')) {
    function verd_ultim_intent()
    {
        return null;
    }
}

/**
 * Payload completo del grupo del Verd: TODOS los partidos del grupo (para estadísticas de rivales) y la clasificación.
 * Es el mismo formato que publica GitHub en verd.json.
 */
function verd_payload($data, array $cfg)
{
    $p = [
        'meta'   => ['actualitzat' => '', 'text' => '', 'temporada' => ''],
        'config' => ['auto' => !empty($cfg['auto_visita']) && empty($cfg['dades_remotes']), 'minuts' => (int)$cfg['interval_visita_min'], 'app' => $cfg['app'],
                     'remot' => !empty($cfg['dades_remotes']) ? rtrim($cfg['dades_remotes'], '/') . '/verd.json' : ''],
        'equip'  => ['nom' => 'Benjamí Verd', 'equip' => '', 'grup' => '', 'jornades' => 0],
        'partits' => [],
        'classificacio' => [],
        'intent' => verd_ultim_intent(),
    ];
    if (!$data) {
        return $p;
    }
    $meta = isset($data['meta']) ? $data['meta'] : [];
    $p['meta'] = [
        'actualitzat' => isset($meta['actualitzat']) ? $meta['actualitzat'] : '',
        'text'        => isset($meta['actualitzat']) ? cebc_data_text($meta['actualitzat']) : '',
        'temporada'   => isset($meta['temporada']) ? $meta['temporada'] : '',
    ];
    $eq = isset($data['equips']['benjami-verd']) ? $data['equips']['benjami-verd'] : reset($data['equips']);
    $g  = isset($data['grups'][(string)$eq['grup_id']]) ? $data['grups'][(string)$eq['grup_id']] : null;
    if (!$g) {
        return $p;
    }
    $out = [];
    foreach ($g['partits'] as $m) {
        $out[] = [
            'jornada' => (int)$m['jornada'], 'volta' => (int)$m['volta'],
            'data' => $m['data'], 'hora' => $m['hora'], 'lloc' => $m['lloc'],
            'local' => $m['local'], 'visitant' => $m['visitant'],
            'gl' => $m['gl'], 'gv' => $m['gv'], 'estat' => $m['estat'],
            'canvi' => !empty($m['canvi_data']) || !empty($m['canvi_hora']),
        ];
    }
    $p['equip'] = ['nom' => $eq['nom'], 'equip' => $eq['equip_nom'], 'grup' => $g['nom'], 'jornades' => isset($g['jornades_total']) ? (int)$g['jornades_total'] : 0];
    $p['partits'] = $out;
    $p['classificacio'] = $g['classificacio'];
    return $p;
}
