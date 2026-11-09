<?php
/**
 * Dades que consumeix el navegador (index.php les incrusta i dades.php les serveix en viu).
 */

/** Llegeix data.json; retorna null si no existeix o no té equips. */
function cebc_carrega_data($file)
{
    if (!is_file($file)) {
        return null;
    }
    $d = json_decode((string)file_get_contents($file), true);
    return (is_array($d) && !empty($d['equips'])) ? $d : null;
}

/** Dorsal de la categoria: "3r", "4t"... o les inicials ("BF"). */
function cebc_insignia(array $eq)
{
    if (!empty($eq['subtitol']) && preg_match('/^(\d)\s?(r|n|t)\b/u', $eq['subtitol'], $m)) {
        return $m[1] . $m[2];
    }
    $ini = '';
    foreach (preg_split('/\s+/u', trim((string)$eq['nom'])) as $p) {
        $ini .= mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8');
        if (mb_strlen($ini, 'UTF-8') >= 2) {
            break;
        }
    }
    return $ini;
}

/** "8 de novembre de 2026 a les 19:51" */
function cebc_data_text($iso)
{
    $mesos = ['gener', 'febrer', 'març', 'abril', 'maig', 'juny', 'juliol', 'agost', 'setembre', 'octubre', 'novembre', 'desembre'];
    $ts = strtotime((string)$iso);
    if (!$ts) {
        return '';
    }
    return date('j', $ts) . ' de ' . $mesos[(int)date('n', $ts) - 1] . ' de ' . date('Y', $ts) . ' a les ' . date('H:i', $ts);
}

/** Estructura mínima per al navegador. */
function cebc_payload($data, array $cfg)
{
    $p = [
        'meta'   => ['actualitzat' => '', 'text' => '', 'temporada' => ''],
        'config' => ['auto' => !empty($cfg['auto_visita']) && empty($cfg['dades_remotes']), 'minuts' => (int)$cfg['interval_visita_min'],
                     'remot' => !empty($cfg['dades_remotes']) ? rtrim($cfg['dades_remotes'], '/') . '/basquet.json' : ''],
        'equips' => [],
        'grups'  => [],
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
    foreach ($data['equips'] as $id => $eq) {
        $eq['id'] = $id;
        $eq['insignia'] = cebc_insignia($eq);
        $p['equips'][$id] = $eq;
        $gid = (string)$eq['grup_id'];
        if (!isset($p['grups'][$gid]) && isset($data['grups'][$gid])) {
            $g = $data['grups'][$gid];
            foreach ($g['partits'] as &$partit) {
                unset($partit['pagina']);
            }
            unset($partit);
            $p['grups'][$gid] = $g;
        }
    }
    return $p;
}

function cebc_json(array $payload)
{
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
}
