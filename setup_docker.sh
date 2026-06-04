#!/bin/bash
# setup_docker.sh
# Script para configurar y levantar la infraestructura de RubricAI con Docker.

set -e

# Colores para salida
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0;40m' # Sin color

echo -e "${GREEN}=== Configuración de RubricAI Docker ===${NC}\n"

# 1. Comprobar si Docker está instalado
if ! command -v docker &> /dev/null; then
    echo -e "${RED}[ERROR] Docker no está instalado en este sistema.${NC}"
    echo -e "${YELLOW}Por favor, abre una terminal en tu sistema y ejecuta los siguientes comandos para instalar Docker y Docker Compose:${NC}\n"
    
    echo -e "  ${GREEN}# 1. Actualizar el índice de paquetes e instalar Docker${NC}"
    echo -e "  sudo apt-get update"
    echo -e "  sudo apt-get install -y docker.io docker-compose-v2\n"
    
    echo -e "  ${GREEN}# 2. Iniciar y habilitar el servicio de Docker${NC}"
    echo -e "  sudo systemctl start docker"
    echo -e "  sudo systemctl enable docker\n"
    
    echo -e "  ${GREEN}# 3. Agregar tu usuario al grupo 'docker' (para ejecutar sin 'sudo')${NC}"
    echo -e "  sudo usermod -aG docker \$USER\n"
    
    echo -e "${YELLOW}Nota: Después de agregar tu usuario al grupo 'docker', recuerda reiniciar tu sesión de terminal o ejecutar 'newgrp docker' antes de volver a correr este script.${NC}"
    exit 1
fi

echo -e "${GREEN}[OK] Docker está instalado.${NC}"

# 1b. Comprobar permisos del socket de Docker
if ! docker ps &> /dev/null; then
    echo -e "${RED}[ERROR] No tienes permisos para comunicarte con Docker (Permission Denied).${NC}"
    echo -e "${YELLOW}La membresía al grupo 'docker' no se ha activado en esta sesión de terminal.${NC}"
    echo -e "${YELLOW}Tienes dos opciones para resolver esto:${NC}\n"
    
    echo -e "  ${GREEN}Opción A (Recomendada): Activar el grupo en tu terminal actual y ejecutar de nuevo:${NC}"
    echo -e "  newgrp docker"
    echo -e "  ./setup_docker.sh\n"
    
    echo -e "  ${GREEN}Opción B: Ejecutar el script usando sudo:${NC}"
    echo -e "  sudo ./setup_docker.sh\n"
    exit 1
fi
VOLUMES=(
    "areteia_db_data"
    "areteia_redis_data"
    "areteia_npm_data"
    "areteia_npm_letsencrypt"
    "areteia_moodle_core"
)

echo -e "\n${YELLOW}Comprobando volúmenes externos requeridos...${NC}"
for vol in "${VOLUMES[@]}"; do
    if docker volume inspect "$vol" &> /dev/null; then
        echo -e "  - Volumen '$vol': ${GREEN}Ya existe${NC}"
    else
        echo -e "  - Volumen '$vol': ${YELLOW}No existe. Creándolo...${NC}"
        docker volume create "$vol"
        echo -e "    ${GREEN}[OK] Creado '$vol'${NC}"
    fi
done

# 2b. Ajustar permisos de la carpeta local 'moodledata' en el host
echo -e "\n${YELLOW}Ajustando permisos de la carpeta local './moodledata' para Moodle...${NC}"
mkdir -p ./moodledata
if [ "$EUID" -ne 0 ]; then
    # Si no es root, intentamos usar sudo
    sudo chown -R 33:33 ./moodledata || sudo chmod -R 777 ./moodledata
else
    # Si ya es root
    chown -R 33:33 ./moodledata || chmod -R 777 ./moodledata
fi
echo -e "${GREEN}[OK] Permisos de './moodledata' listos.${NC}"

# 3. Levantar la infraestructura
echo -e "\n${YELLOW}Levantando contenedores de Docker (Moodle, FastAPI Python, Astro Frontend)...${NC}"
docker compose up -d --build

echo -e "\n${GREEN}=== ¡Despliegue de Docker completado con éxito! ===${NC}"
echo -e "Estado de los contenedores:"
docker compose ps
