# 🗄️ Base de Datos - Backup y Restauración

Este repositorio incluye un backup completo de la base de datos PostgreSQL de Moodle con todos los usuarios, cursos y contenido.

## 📦 Contenido del Backup

- **Archivo:** `database_backup.dump` (6.7 MB)
- **Formato:** PostgreSQL custom format (comprimido)
- **Contenido:** 
  - Usuarios y roles
  - Cursos y estructura
  - Actividades y calificaciones
  - Configuraciones del sistema
  - Datos del plugin RubricAI

## 🚀 Restaurar la Base de Datos

### Opción 1: Usar el script automático (Recomendado)

```bash
./restore_database.sh
```

### Opción 2: Restauración manual

```bash
# 1. Iniciar el contenedor de base de datos
docker compose up -d db

# 2. Esperar que esté listo
sleep 10

# 3. Copiar el backup al contenedor
docker cp database_backup.dump moodle_db:/tmp/moodle_backup.dump

# 4. Restaurar
docker exec moodle_db pg_restore -U dbuser -d moodle -c -F c /tmp/moodle_backup.dump
```

## 🔐 Credenciales de Acceso

Después de restaurar la base de datos, puedes acceder a Moodle con:

- **URL:** http://localhost:8080
- **Usuario Admin:** `admin`
- **Contraseña:** `password`
- **Email:** email@example.com

## 📝 Crear un Nuevo Backup

Si necesitas actualizar el backup:

```bash
# Crear backup
docker exec moodle_db pg_dump -U dbuser -d moodle -F c -f /tmp/moodle_backup.dump

# Copiar al repositorio
docker cp moodle_db:/tmp/moodle_backup.dump ./database_backup.dump
```

## ⚠️ Notas Importantes

1. **Archivos Multimedia:** Este backup NO incluye los archivos subidos a Moodle (videos, PDFs, imágenes). Esos están en `moodledata/filedir/` y deben ser respaldados por separado si son necesarios.

2. **Variables de Entorno:** Asegúrate de tener el archivo `.env` configurado correctamente antes de restaurar.

3. **Primera Vez:** Si es la primera vez que clonas el repositorio:
   ```bash
   # 1. Clonar el repo
   git clone https://github.com/dracero/rubricAI.git
   cd rubricAI
   
   # 2. Copiar y configurar .env
   cp .env.example .env
   # Editar .env si es necesario
   
   # 3. Crear volúmenes Docker
   docker volume create areteia_db_data
   docker volume create areteia_redis_data
   docker volume create areteia_npm_data
   docker volume create areteia_npm_letsencrypt
   docker volume create areteia_moodle_core
   
   # 4. Restaurar la base de datos
   ./restore_database.sh
   
   # 5. Iniciar todos los servicios
   docker compose up -d
   ```

## 🔍 Verificar la Restauración

```bash
# Ver tablas en la base de datos
docker exec -it moodle_db psql -U dbuser -d moodle -c "\dt" | head -20

# Verificar usuarios
docker exec -it moodle_db psql -U dbuser -d moodle -c "SELECT COUNT(*) FROM mdl_user;"
```

## 🆘 Solución de Problemas

### Error: "database moodle does not exist"

```bash
# Crear la base de datos manualmente
docker exec -it moodle_db psql -U dbuser -c "CREATE DATABASE moodle;"
```

### Error: "role does not exist"

Verifica que las credenciales en `.env` coincidan con las del backup:
- DB_USER=dbuser
- DB_PASS=password
- DB_NAME=moodle
