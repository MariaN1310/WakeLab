<div align="center">
  <img src="assets/icons/web-app-manifest-192x192.png" alt="WakeLab" width="120"><br><br>
  <h1>WakeLab</h1>
  <p><strong>Dashboard para homelab</strong> — monitoreo, Wake-on-LAN y control de servidores desde una sola interfaz.</p>

  ![Docker](https://img.shields.io/badge/docker-ghcr.io-blue?logo=docker)
  ![PHP](https://img.shields.io/badge/php-8.2-777bb4?logo=php)
  ![MySQL](https://img.shields.io/badge/mysql-8.0-4479a1?logo=mysql)
  ![License](https://img.shields.io/badge/license-MIT-green)
</div>

---

WakeLab te permite ver el estado de todos tus servidores en tiempo real, encenderlos remotamente con Wake-on-LAN, apagarlos por SSH, recibir notificaciones cuando un servidor cae o vuelve, y programar encendidos y apagados automáticos. Diseñado para homelabs — sin dependencias externas, sin nube.

## Características

- Monitoreo en tiempo real — Proxmox, TrueNAS, OMV, Linux, Windows
- Wake-on-LAN desde el dashboard
- Apagado remoto por SSH
- Wake Proxy — despertá servicios al acceder a ellos vía navegador
- Notificaciones por Telegram, email y push (navegador)
- Programación de encendido/apagado automático
- Cifrado AES-256-GCM para datos sensibles en DB
- Autenticación con sesiones seguras

## Instalación

```yaml
services:
  web:
    image: ghcr.io/marian1310/wakelab:latest
    container_name: webserver
    restart: unless-stopped
    ports:
      - "${WEB_PORT:-8472}:80"
    environment:
      WAKELAB_SECRET: ${WAKELAB_SECRET}
      WAKELAB_DB_HOST: db
      WAKELAB_DB_NAME: wakelab
      WAKELAB_DB_USER: ${WAKELAB_DB_USER}
      WAKELAB_DB_PASS: ${WAKELAB_DB_PASS}
    volumes:
      - ${SSH_KEYS_PATH:-ssh_keys}:/var/www/.ssh
    depends_on:
      db:
        condition: service_healthy

  db:
    image: ghcr.io/marian1310/wakelab-db:latest
    container_name: wakelab-db
    restart: unless-stopped
    environment:
      MYSQL_DATABASE: wakelab
      MYSQL_USER: ${WAKELAB_DB_USER}
      MYSQL_PASSWORD: ${WAKELAB_DB_PASS}
      MYSQL_ROOT_PASSWORD: ${MYSQL_ROOT_PASSWORD}
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 10s
      timeout: 5s
      retries: 5

volumes:
  db_data:
  ssh_keys:
```

### Portainer

1. **Stacks → Add stack**, pegá el compose y completá las variables de entorno
2. **Deploy the stack**

Entrá a `http://IP:8472` y creá tu cuenta en el primer acceso.

## Variables de entorno

| Variable | Descripción | Default |
|---|---|---|
| `WEB_PORT` | Puerto del dashboard | `8472` |
| `WAKELAB_SECRET` | Clave de cifrado AES — `openssl rand -hex 32` | — |
| `MYSQL_ROOT_PASSWORD` | Contraseña root de MySQL | — |
| `WAKELAB_DB_USER` | Usuario de la base de datos | `wakelab` |
| `WAKELAB_DB_PASS` | Contraseña de la base de datos | — |
| `SSH_KEYS_PATH` | Ruta del host para persistir las claves SSH | volumen interno |

## SSH

Al primer arranque WakeLab genera automáticamente un par de claves SSH (ed25519). Al agregar un servidor Linux con contraseña, copia su clave pública automáticamente — de ahí en adelante se conecta sin contraseña.

```bash
# Ver la clave pública generada
docker logs webserver | grep -A1 "SSH key generada"
```

## Recuperar contraseña

```bash
docker exec -it webserver php /var/www/html/WakeLab/php/reset-cli.php 'nueva-contraseña'
```

## Resetear base de datos

```bash
docker compose down
docker volume rm wakelab_db_data
docker compose up -d
```

## En desarrollo

- **Integración UPS** — webhook para Nutify/NUT con apagado ordenado por prioridad y timer configurable
- **Wake-on-SMB** — detectar accesos a shares SMB de servidores apagados y despertarlos automáticamente antes de que el cliente reciba un error
- **Apagado ordenado** — definir dependencias entre servidores y apagarlos en secuencia correcta sin dejar servicios colgados
- Mejoras generales de código y corrección de errores
