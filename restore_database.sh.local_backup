#!/bin/bash
# Script para restaurar la base de datos de Moodle desde el backup

echo "🔄 Restaurando base de datos de Moodle..."

# Verificar que el contenedor de base de datos esté corriendo
if ! docker ps | grep -q moodle_db; then
    echo "⚠️  El contenedor moodle_db no está corriendo. Iniciando..."
    docker compose up -d db
    echo "⏳ Esperando que la base de datos esté lista..."
    sleep 10
fi

# Copiar el backup al contenedor
echo "📋 Copiando backup al contenedor..."
docker cp database_backup.dump moodle_db:/tmp/moodle_backup.dump

# Restaurar la base de datos
echo "🔧 Restaurando base de datos..."
docker exec moodle_db pg_restore -U dbuser -d moodle -c -F c /tmp/moodle_backup.dump 2>&1 | grep -v "ERROR.*already exists"

echo "✅ Base de datos restaurada exitosamente"
echo ""
echo "Credenciales de acceso:"
echo "  URL: http://localhost:8080"
echo "  Usuario admin: admin"
echo "  Contraseña: password"
