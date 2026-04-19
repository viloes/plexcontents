# Plex Contents Web

Aplicación web para visualizar y gestionar un catalogo de películas y series importado desde exportaciones JSON de Tautulli. Incluye autenticación, administración de usuarios, importaciones separadas para películas y series, y persistencia local en SQLite.

## Funcionalidad principal

- Login con usuarios locales.
- Panel de administración para:
  - Gestión de usuarios (alta, borrado y cambio de password).
  - Importación de catálogo de películas.
  - Importación de catálogo de series.
  - Reinicio completo de base de datos.
- Perfil de usuario:
  - Cambio de idioma.
  - Cambio de password propio.
- Registro de eventos en log rotado.

## Credenciales admin por defecto

En una base de datos nueva, se crea automáticamente:

- Usuario: admin
- Password: admin123

Si el fichero SQLite no existe o el volumen `data/` llega vacío, la aplicación inicializa automáticamente la base de datos en el primer acceso.

Importante: cambia la password de admin tras el primer acceso.

## Requisitos

- Docker Engine 24+ (recomendado)
- Docker Compose plugin (`docker compose`)
- Carpetas locales creadas en este proyecto:
  - `data/`
  - `storage/log/`
  - `tautulli-exports/`

## Archivo docker_compose.yml

Se incluye un ejemplo operativo en `docker_compose.yml` con:

- Imagen: `viloes/plexcontents:latest`
- Exposición de puerto: `8080:80`
- Volumen de exportaciones Tautulli en sólo lectura:
  - `./tautulli-exports:/var/www/html/tautulli-exports:ro`

### Parámetros clave del compose

- `ports`
  - `8080:80`: publica la app en `http://localhost:8080`.
- `volumes`
  - `./data:/var/www/html/data`: persistencia de SQLite.
  - `./storage/log:/storage/log`: persistencia de logs.
  - `./tautulli-exports:/var/www/html/tautulli-exports:ro`: origen de importación (sólo lectura).
- `environment`
  - `EXPORT_ROOT=/var/www/html/tautulli-exports`
  - `LOG_LEVEL=DEBUG`
  - `LOG_PATH=/storage/log/plexcontents.log`
  - `LOG_MAX_SIZE_MB=10`
  - `LOG_MAX_FILES=5`

La aplicación resuelve la configuración con esta precedencia:

- Variables de entorno reales del proceso o del contenedor.
- Archivo `.env` en la raíz del proyecto, útil para ejecuciones fuera de Docker.
- Valores por defecto internos de la aplicación.

En Docker no hace falta `.env` para los logs si ya defines `LOG_PATH`, `LOG_LEVEL` y el resto en `environment`.

## Arranque del contenedor

Desde la raiz del proyecto:

```bash
docker compose -f docker_compose.yml up -d
```

Ver estado:

```bash
docker compose -f docker_compose.yml ps
```

Ver logs del contenedor:

```bash
docker compose -f docker_compose.yml logs -f web
```

Parar servicio:

```bash
docker compose -f docker_compose.yml down
```

Acceso web:

- http://localhost:8080/login.php

## Importación de listas de películas y series

La app no sube ficheros manualmente. Importa desde subcarpetas existentes bajo `tautulli-exports/`.

### Estructura esperada

Cada subcarpeta de exportación debe incluir:

- Un único `.json` en la raiz de esa subcarpeta.
- Al menos una subcarpeta (normalmente `*.resources/`) con imagenes/recursos.

Ejemplo:

```text
tautulli-exports/
  Library - NAS Peliculas - All [1].20260418160540/
    Library - NAS Peliculas - All [1].json
    Movie - 12 monos [14262].resources/
  Library - NAS Series - All [2].20260418160328/
    Library - NAS Series - All [2].json
    Series - Example [1].resources/
```

### Pasos de importación

1. Inicia sesión como admin.
2. Entra en `Settings` (panel admin).
3. En `Catalog Sources`, verifica que la ruta raiz sea `/var/www/html/tautulli-exports`.
4. En `Movies source folder`, selecciona la carpeta de películas y pulsa `Import`.
5. En `Series source folder`, selecciona la carpeta de series y pulsa `Import`.

La importación de peliculas y series es independiente. Puedes actualizar una sin tocar la otra.

## Gestión de usuarios

Disponible en el panel de administración:

- Crear usuario:
  - `Username`
  - `Password`
  - opcion `Admin`
- Cambiar password de cualquier usuario.
- Borrar usuario (el admin autenticado no puede auto-eliminarse).

Disponible para cada usuario en `Profile`:

- Cambiar su propio password (con confirmación).
- Cambiar idioma.

## Troubleshooting

- No aparecen carpetas para importar:
  - Verifica que `./tautulli-exports` exista en host y esté montada en compose.
  - Verifica permisos de lectura.
- Error de importación por JSON:
  - Debe existir un único `.json` por carpeta seleccionada.
  - Si hay 0 o más de 1 JSON, la app rechaza la importación.
- Error por estructura incompleta:
  - La carpeta seleccionada debe contener subdirectorios de recursos.
- No se guardan datos tras reiniciar:
  - Revisa que `./data` esté montada correctamente en `/var/www/html/data`.
- No se crea `plexcontents.log`:
  - Verifica que `LOG_PATH` apunte a una ruta válida y que el directorio padre exista o pueda crearse.
  - Verifica permisos de escritura sobre `./storage/log` en host o sobre la ruta configurada fuera de Docker.
  - Si el fichero principal no puede crearse, la aplicación deja diagnóstico en el `error_log` de PHP/Apache.
  - Si la ruta configurada falla, la aplicación usa como respaldo `storage/log/plexcontents.log` dentro del proyecto para no perder trazas.
- La app corre fuera de Docker:
  - Define `LOG_PATH`, `LOG_LEVEL`, `LOG_MAX_SIZE_MB` y `LOG_MAX_FILES` como variables de entorno del proceso o en un `.env` en la raíz del proyecto.
- Revisar diagnóstico:
  - Log de aplicación en `./storage/log/plexcontents.log`.

## Hardening (recomendado)

- Cambia inmediatamente `admin123` después del primer login.
- Mantén `tautulli-exports` en sólo lectura (`:ro`).
- No expongas el servicio directamente a Internet sin proxy/TLS.
- Limita acceso de red al puerto publicado.
- Mantén la imagen actualizada periódicamente.

## Actualización de imagen sin perder datos

1. Descargar última imagen:

```bash
docker compose -f docker_compose.yml pull
```

2. Recrear contenedor con la nueva imagen:

```bash
docker compose -f docker_compose.yml up -d
```

3. Verificar estado:

```bash
docker compose -f docker_compose.yml ps
```

Los datos persisten porque base de datos y logs están en volúmenes montados (`./data` y `./storage/log`).

## Uso con reverse proxy (Nginx/Caddy)

Para publicación externa, usa un reverse proxy con TLS delante de la app.

### Ejemplo conceptual con Caddy

```caddy
plex.midominio.com {
  reverse_proxy 127.0.0.1:8080
}
```

Con eso, Caddy termina TLS y reenvía tráfico al contenedor en `localhost:8080`.

## Notas operativas

- Si necesitas reiniciar por completo la base de datos, existe una acción desde el panel admin (`Reset database`).
- Esa acción elimina todo el catálogo y usuarios y recrea el admin por defecto.
