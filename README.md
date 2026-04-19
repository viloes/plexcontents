# Plex Contents Web

Aplicacion web para visualizar y gestionar un catalogo de peliculas y series importado desde exportaciones JSON de Tautulli. Incluye autenticacion, administracion de usuarios, importaciones separadas para peliculas y series, y persistencia local en SQLite.

## Funcionalidad principal

- Login con usuarios locales.
- Panel de administracion para:
  - Gestion de usuarios (alta, borrado y cambio de password).
  - Importacion de catalogo de peliculas.
  - Importacion de catalogo de series.
  - Reinicio completo de base de datos.
- Perfil de usuario:
  - Cambio de idioma.
  - Cambio de password propio.
- Registro de eventos en log rotado.

## Credenciales admin por defecto

En una base de datos nueva, se crea automaticamente:

- Usuario: admin
- Password: admin123

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
- Exposicion de puerto: `8080:80`
- Volumen de exportaciones Tautulli en solo lectura:
  - `./tautulli-exports:/var/www/html/tautulli-exports:ro`

### Parametros clave del compose

- `ports`
  - `8080:80`: publica la app en `http://localhost:8080`.
- `volumes`
  - `./data:/var/www/html/data`: persistencia de SQLite.
  - `./storage/log:/storage/log`: persistencia de logs.
  - `./tautulli-exports:/var/www/html/tautulli-exports:ro`: origen de importacion (solo lectura).
- `environment`
  - `EXPORT_ROOT=/var/www/html/tautulli-exports`
  - `LOG_LEVEL=DEBUG`
  - `LOG_PATH=/storage/log/plexcontents.log`
  - `LOG_MAX_SIZE_MB=10`
  - `LOG_MAX_FILES=5`

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

## Importacion de listas de peliculas y series

La app no sube ficheros manualmente. Importa desde subcarpetas existentes bajo `tautulli-exports/`.

### Estructura esperada

Cada subcarpeta de exportacion debe incluir:

- Un unico `.json` en la raiz de esa subcarpeta.
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

### Pasos de importacion

1. Inicia sesion como admin.
2. Entra en `Settings` (panel admin).
3. En `Catalog Sources`, verifica que la ruta raiz sea `/var/www/html/tautulli-exports`.
4. En `Movies source folder`, selecciona la carpeta de peliculas y pulsa `Import`.
5. En `Series source folder`, selecciona la carpeta de series y pulsa `Import`.

La importacion de peliculas y series es independiente. Puedes actualizar una sin tocar la otra.

## Gestion de usuarios

Disponible en el panel de administracion:

- Crear usuario:
  - `Username`
  - `Password`
  - opcion `Admin`
- Cambiar password de cualquier usuario.
- Borrar usuario (el admin autenticado no puede auto-eliminarse).

Disponible para cada usuario en `Profile`:

- Cambiar su propio password (con confirmacion).
- Cambiar idioma.

## Troubleshooting

- No aparecen carpetas para importar:
  - Verifica que `./tautulli-exports` exista en host y este montada en compose.
  - Verifica permisos de lectura.
- Error de importacion por JSON:
  - Debe existir un unico `.json` por carpeta seleccionada.
  - Si hay 0 o mas de 1 JSON, la app rechaza la importacion.
- Error por estructura incompleta:
  - La carpeta seleccionada debe contener subdirectorios de recursos.
- No se guardan datos tras reiniciar:
  - Revisa que `./data` este montada correctamente en `/var/www/html/data`.
- Revisar diagnostico:
  - Log de aplicacion en `./storage/log/plexcontents.log`.

## Hardening (recomendado)

- Cambia inmediatamente `admin123` despues del primer login.
- Mantén `tautulli-exports` en solo lectura (`:ro`).
- No expongas el servicio directamente a Internet sin proxy/TLS.
- Limita acceso de red al puerto publicado.
- Mantén la imagen actualizada periodicamente.

## Actualizacion de imagen sin perder datos

1. Descargar ultima imagen:

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

Los datos persisten porque base de datos y logs estan en volumenes montados (`./data` y `./storage/log`).

## Uso con reverse proxy (Nginx/Caddy)

Para publicacion externa, usa un reverse proxy con TLS delante de la app.

### Ejemplo conceptual con Caddy

```caddy
plex.midominio.com {
  reverse_proxy 127.0.0.1:8080
}
```

Con eso, Caddy termina TLS y reenvia trafico al contenedor en `localhost:8080`.

## Notas operativas

- Si necesitas reiniciar por completo la base de datos, existe una accion desde el panel admin (`Reset database`).
- Esa accion elimina todo el catalogo y usuarios y recrea el admin por defecto.
