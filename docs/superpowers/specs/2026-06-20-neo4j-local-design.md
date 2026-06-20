# Migración Neo4j Aura Cloud → Neo4j Local (Docker)

**Fecha:** 2026-06-20  
**Estado:** Aprobado

## Objetivo

Reemplazar Neo4j Aura Cloud por una instancia local de Neo4j Community Edition corriendo en Docker en el servidor `chimuelo`, eliminando la dependencia a un servicio cloud externo y su costo asociado.

## Decisiones tomadas

- **Edición:** Neo4j Community (gratuita) — el código ya tiene fallback para single-database
- **Datos:** migrar desde Aura (no arrancar con base limpia)
- **Método de migración:** script Python standalone (no requiere APOC ni herramientas de Aura)

## Componentes

### 1. Servicio Neo4j en Docker

Agregado en `docker-compose.python.yml` junto a `python_rag` y `frontend`.

```yaml
neo4j:
  image: neo4j:5-community
  container_name: rubricai_neo4j
  restart: unless-stopped
  ports:
    - "7474:7474"   # Neo4j Browser (UI de inspección)
    - "7687:7687"   # Bolt protocol
  environment:
    - NEO4J_AUTH=${NEO4J_AUTH}
  volumes:
    - neo4j_data:/data
  healthcheck:
    test: ["CMD-SHELL", "wget -qO- localhost:7474 > /dev/null 2>&1"]
    interval: 10s
    timeout: 10s
    retries: 10
    start_period: 40s
```

`python_rag` agrega `depends_on: neo4j (condition: service_healthy)`.

### 2. Script de migración

Archivo: `rubricai_ai/migrate_neo4j.py` — script standalone, se ejecuta una sola vez.

**Flujo:**
1. Conecta a Aura (source) y Neo4j local (target) con drivers independientes
2. Exporta todos los nodos: labels + propiedades + `elementId()` como clave temporal
3. Exporta todas las relaciones: tipo + propiedades + `elementId` de source/target
4. Importa nodos al local con atributo `_mig_id` temporal
5. Importa relaciones usando `_mig_id` para reconstruir los extremos
6. Limpia `_mig_id` de todos los nodos

**Uso:**
```bash
python migrate_neo4j.py \
  --source "neo4j+s://aa211074.databases.neo4j.io" \
  --source-user "aa211074" --source-pass "xxx" \
  --target "bolt://localhost:7687" \
  --target-user "neo4j" --target-pass "password"
```

### 3. Variables de entorno

Cambios en `.env` y `.env.example`:

| Variable | Antes (Aura) | Después (local) |
|---|---|---|
| `NEO4J_URI` | `neo4j+s://aa211074.databases.neo4j.io` | `bolt://neo4j:7687` |
| `NEO4J_USERNAME` | `aa211074` | `neo4j` |
| `NEO4J_PASSWORD` | *(credencial Aura)* | *(password local a elegir)* |
| `NEO4J_DATABASE` | `aa211074` | `neo4j` |
| `NEO4J_AUTH` | *(no existía)* | `neo4j/<password>` |
| `NEO4J_RUBRICS_DATABASE` | `rubricas` | *(eliminar — fallback automático)* |

`NEO4J_URI` usa el nombre del servicio Docker (`neo4j`) para funcionar dentro de la red interna.

## Orden de ejecución en chimuelo

1. Agregar servicio Neo4j al docker-compose y levantar solo ese contenedor
2. Correr el script de migración (Neo4j Aura todavía activo, NEO4J_* todavía apuntando a Aura)
3. Verificar datos en Neo4j Browser (`:7474`)
4. Actualizar `.env` para apuntar al local
5. Reiniciar `python_rag` y `frontend`
6. Verificar que todo funcione
7. Desactivar Aura (opcional)

## Archivos a modificar

- `docker-compose.python.yml` — agregar servicio `neo4j`, `depends_on`, volumen
- `.env` — actualizar variables NEO4J_*
- `.env.example` — actualizar a valores locales de ejemplo
- `rubricai_ai/migrate_neo4j.py` — nuevo archivo (script de migración)
