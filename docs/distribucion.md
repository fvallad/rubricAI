# RubricAI — Guía de Distribución e Instalación

Guía para instalar RubricAI en una institución que ya cuenta con Moodle propio.

---

## 1. Requisitos previos

Antes de iniciar la instalación, la institución debe contar con:

- **Moodle propio con acceso de administrador** *(requisito bloqueante — si el Moodle está tercerizado y no se tiene acceso admin, no es posible instalar el plugin)*
- **Servidor Linux** con Docker y Docker Compose instalados, accesible desde internet (para que Moodle pueda comunicarse con el backend)
- **Cuenta en Neo4j Aura Cloud** — el tier gratuito es suficiente para comenzar ([aura.neo4j.io](https://aura.neo4j.io))
- **API key de OpenAI** — se recomienda como proveedor por defecto; es posible configurar otros proveedores de LLM según las necesidades de la institución

---

## 2. Componentes a instalar

RubricAI se compone de tres piezas que deben deployarse juntas:

| Componente | Qué es | Dónde va |
|---|---|---|
| **Plugin Moodle** (`rubricai_plugin.zip`) | Integración nativa en Moodle — agrega el flujo docente y la auditoría | En el Moodle de la institución |
| **Backend Python** (`rubricai_ai`) | Servicio FastAPI que corre los agentes de IA, el RAG y se conecta a OpenAI y Neo4j | En el servidor propio (Docker) |
| **Frontend Astro** (`frontend`) | Panel web para cargar y gestionar las rúbricas institucionales | En el mismo servidor (Docker) |

Los tres componentes se obtienen clonando el repositorio oficial de RubricAI.

---

## 3. Paso a paso técnico

### 3.1 Obtener los archivos

```bash
git clone https://github.com/dracero/rubricAI.git
cd rubricAI
```

### 3.2 Configurar el entorno

Copiar el archivo de ejemplo y completar los valores:

```bash
cp .env.example .env
```

Variables obligatorias en `.env`:

```ini
# LLM
LLM_PROVIDER=openai
OPENAI_API_KEY=sk-...
OPENAI_MODEL=gpt-4o

# Neo4j Aura Cloud (credenciales del panel de aura.neo4j.io)
NEO4J_URI=neo4j+s://xxxxxxxx.databases.neo4j.io
NEO4J_USER=neo4j
NEO4J_PASSWORD=...

# Base de datos Moodle
DB_NAME=moodle
DB_USER=...
DB_PASS=...
```

### 3.3 Levantar el backend y el frontend

```bash
docker compose up -d python_rag astro_frontend
```

Verificar que estén corriendo:

- Backend: `http://tu-servidor:8000/health`
- Frontend Astro: `http://tu-servidor:4321`

### 3.4 Instalar el plugin en Moodle

1. Ingresar como administrador al Moodle de la institución
2. Ir a *Administración del sitio → Plugins → Instalar plugin*
3. Subir `rubricai_plugin.zip` y seguir el asistente

### 3.5 Apuntar el plugin al backend

Editar `rubricai.ini` dentro del plugin instalado:

```ini
rubricai_ai_url = "http://tu-servidor:8000"
```

### 3.6 Cargar la primera rúbrica

1. Abrir el frontend Astro en `http://tu-servidor:4321`
2. Subir el JSON de la rúbrica institucional
3. Verificar que aparece listada antes de continuar

---

## 4. Verificación

Confirmar que el sistema funciona de punta a punta:

1. **Backend activo** — `GET http://tu-servidor:8000/health` debe responder `{"status": "ok"}`
2. **Plugin visible en Moodle** — ingresar a un curso como profesor editor y verificar que aparece la pestaña "RubricAI" en la navegación superior
3. **Rúbrica disponible** — dentro del plugin, en la pestaña "Auditoría RubricAI", el dropdown debe mostrar la rúbrica cargada vía Astro
4. **Flujo completo** — ir a "Crear Biblioteca" en un curso con recursos, indexar el contenido, luego correr una auditoría y verificar que devuelve un score y reporte

Si algún paso falla, revisar los logs del backend:

```bash
docker compose logs python_rag --tail=50
```

---

## 5. Brief para coordinador

*Texto para enviar a un proveedor externo al solicitar cotización:*

---

Nos interesa implementar **RubricAI**, una plataforma de auditoría pedagógica que se integra con Moodle mediante inteligencia artificial. Necesitamos cotizar el trabajo de instalación y configuración inicial.

El sistema tiene dos partes:

**1. Plugin para Moodle**
Se instala directamente en nuestro Moodle institucional. Requiere acceso de administrador. El archivo de instalación (`.zip`) lo provee el equipo de RubricAI.

**2. Servidor de inteligencia artificial**
Requiere un servidor Linux propio con Docker. El proveedor debe encargarse de:
- Levantar el backend Python y el panel de gestión (ambos vía Docker Compose)
- Configurar la conexión a Neo4j Aura Cloud (base de datos en la nube, tier gratuito disponible)
- Configurar nuestra API key de OpenAI
- Conectar el plugin de Moodle con el servidor

**Lo que debemos proveer nosotros:**
- Acceso admin al Moodle institucional
- Un servidor Linux con Docker (o contratar uno)
- Una API key de OpenAI
- Las rúbricas institucionales en formato JSON

**Resultado esperado:** docentes con rol de profesor editor deben poder ver la pestaña "RubricAI" dentro de cada curso y correr auditorías pedagógicas.
