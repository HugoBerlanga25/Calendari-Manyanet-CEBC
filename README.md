# Datos del CEBC para tus webs (GitHub)

InfinityFree no permite cron ni peticiones automáticas, así que **GitHub hace la descarga** cada 20 minutos y tus dos webs (Mis partidos y la del club) leen el resultado al abrirse. No hay que tocar nada en el hosting, salvo subir una vez las webs actualizadas.

## Puesta en marcha
Este repositorio contiene el actualizador (`run.php` y los archivos que usa) y el workflow `.github/workflows/dades.yml`, que lo ejecuta cada 20 minutos y publica los resultados en la rama `dades`.
Los datos quedan en `https://raw.githubusercontent.com/HugoBerlanga25/Calendari-Manyanet-CEBC/dades/agenda.json` y `.../basquet.json`, que son las direcciones que leen las dos webs (`dades_remotes` en su `config.php`).

## Cosas a saber
- Si en 60 días no hay actividad en el repositorio, GitHub pausa las tareas programadas. Si pasa, entra en Actions y pulsa **Enable workflow**.
- Si el CEBC cambia su web y falla la descarga, se sigue mostrando lo último publicado.
- El cambio de temporada y los equipos nuevos se detectan igual que antes.
- Las claves de tus webs **no** están en este repositorio. No subas aquí `config.php` de las webs.
