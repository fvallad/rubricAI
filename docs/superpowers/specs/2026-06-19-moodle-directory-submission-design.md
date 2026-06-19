# RubricAI — Moodle Plugin Directory Submission

**Date:** 2026-06-19  
**Authors:** Fernando Valladares, Diego Racero  
**Status:** Approved

---

## Objetivo

Preparar el plugin `local_rubricai` para ser publicado en el directorio oficial de Moodle (moodle.org/plugins), cumpliendo todos los requisitos técnicos, legales y de calidad que el equipo de revisión de Moodle exige.

---

## Decisiones de diseño

### Modelo de integración con el backend Python

El plugin se integra como **cliente de servicio externo**, siguiendo el patrón de BigBlueButton y Turnitin. El backend Python (que incluye los agentes LLM, el pipeline RAG y la base de grafos) corre de forma independiente — en el servidor de la institución o en la nube — y el plugin se conecta vía HTTP.

El admin configura la URL y credenciales desde **Administración del sitio → Plugins → Local → RubricAI**, reemplazando el archivo `rubricai.ini` actual.

### Nombre del plugin

`local_rubricai` — nombre de componente sin cambios.  
Display name: **"RubricAI — AI-Powered Pedagogical Audit Assistant"** (en inglés para el directorio).  
Con traducción `lang/es/` incluida.

### Copyright

```
@copyright 2024 Vicente Astorga (areteIA original)
@copyright 2026 Fernando Valladares, Diego Racero
```

Vicente debe ser contactado **antes del submit final** al directorio oficial.

### Nivel de madurez

`MATURITY_BETA` — el plugin tiene uso real en producción (chimuelo.fi.uba.ar) pero aún no tiene cobertura de tests completa al momento del submit de Fase 1.

---

## Fases de implementación

### Fase 1 — Compliance (requisitos obligatorios para el directorio)

**1. Admin settings** (`settings.php`, `db/access.php`)

Nueva página de configuración en Administración del sitio → Plugins → Local → RubricAI:
- `service_url` — URL del backend Python (default: `http://localhost:8000`)
- `llm_provider` — proveedor LLM: `openai` | `google` | `ollama`
- `api_key` — API key del proveedor LLM (almacenada como `password` en Moodle)

`rag_client.php` migra de leer `rubricai.ini` a leer `get_config('local_rubricai', 'service_url')`.

**2. Privacy Provider** (`privacy/provider.php`)

El plugin almacena resultados de auditoría en `mdl_config_plugins` con claves `compare_score_{courseid}`, `compare_holistic_{courseid}`, etc. Estos datos son por curso, no identifican a usuarios individuales.

Implementa `\core_privacy\local\metadata\null_provider` si no se procesan datos personales, o `metadata\provider` documentando los datos de curso almacenados. Se requiere análisis para determinar cuál aplica.

**3. Lang strings completos**

- `lang/en/local_rubricai.php` — todos los strings actualmente hardcodeados en PHP pasan a este archivo
- `lang/es/local_rubricai.php` — traducción completa al español

Strings a extraer: títulos de pasos, mensajes de error, labels de botones, mensajes de estado de la biblioteca y auditoría.

**4. db/access.php**

```php
$capabilities = [
    'local/rubricai:use' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'local/rubricai:viewaudit' => [
        'captype'      => 'read',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW],
    ],
];
```

**5. Copyright headers**

Todos los archivos PHP actualizados con:
```
@copyright 2024 Vicente Astorga (areteIA)
@copyright 2026 Fernando Valladares, Diego Racero
```

**6. version.php**

```php
$plugin->maturity = MATURITY_BETA;
$plugin->release  = '0.2.0';
$plugin->requires = 2024100700; // Moodle 4.5 (sin cambios)
```

**7. Proceso: contactar a Vicente Astorga**

Antes del submit al directorio oficial, notificar a Vicente Astorga sobre el uso de areteIA como base del proyecto. Esto no es un requisito legal (la GPL lo permite) pero es correcto éticamente y evita malentendidos. Objetivo: obtener su conocimiento y potencialmente su bendición o colaboración.

---

### Fase 2 — Tests

**PHPUnit** (`tests/`)

| Archivo | Qué testea |
|---------|-----------|
| `rag_client_test.php` | Todos los métodos con mocks de curl: `status()`, `ingest()`, `evaluate()`, `delete()`, `sync()`. Verifica URLs, payloads JSON, manejo de respuestas nulas y errores de conexión. |
| `session_manager_test.php` | `save_audit_results()`, `load_audit_results()`, aislamiento por `course_id`, `cascading_invalidation()`. |
| `data_provider_test.php` | `get_course_files()`, `get_course_materials_tree()`, `get_activity_text()` con cursos de fixture en DB. |
| `lock_manager_test.php` | `is_locked()`, `lock()`, `unlock()` con estados válidos e inválidos. |

**Behat** (`tests/behat/`)

| Feature | Escenario principal |
|---------|-------------------|
| `library.feature` | Profesor construye biblioteca: selecciona recursos → click en "Confirmar y Construir" → botón se deshabilita → barra de progreso aparece → biblioteca creada con éxito. |
| `audit.feature` | Profesor corre auditoría: selecciona rúbrica → click en "Iniciar Auditoría" → botón se deshabilita → card de progreso → informe con score visible. |
| `ui_protection.feature` | Mientras auditoría corre, intenta reconstruir biblioteca → botón deshabilitado + banner de advertencia visible. |

Los pasos Behat que invocan el backend Python se ejecutan contra un servidor HTTP stub (WireMock o servidor PHP de fixtures) que simula las respuestas del Python real.

---

### Fase 3 — CI + Polish + Submit

**CI Pipeline** (`.github/workflows/ci.yml`)

Usa `moodlehq/moodle-plugin-ci`. Corre en push y PR:
- PHP lint + Moodle code checker (`moodle-cs`)
- PHPUnit contra Moodle 4.5 + PostgreSQL
- Behat con headless Chrome
- Matriz de versiones: Moodle 4.5 y 4.4 (LTS)

**Documentación**

- `README.md` — descripción, arquitectura, requisitos, instalación del plugin, instalación del backend Python, configuración del admin settings, screenshots
- `CHANGES.md` — changelog desde v0.1.0
- `CONTRIBUTING.md` — guía para contribuidores

**Polish**

- `pix/icon.svg` — ícono propio del plugin (reemplaza genérico de Moodle)
- Revisión de `TODO` y `FIXME` en el código
- Verificar que pase `moodle-cs` sin warnings

**Submit**

1. Contactar a Vicente (si no se hizo en Fase 1)
2. Crear cuenta en moodle.org
3. Registrar plugin en el directorio con descripción, screenshots, URL del repo
4. Esperar revisión del equipo de Moodle (2-4 semanas típicamente)
5. Iterar según feedback del reviewer

---

## Archivos nuevos por fase

### Fase 1
```
local/rubricai/settings.php
local/rubricai/db/access.php
local/rubricai/privacy/provider.php
local/rubricai/lang/es/local_rubricai.php
```

### Fase 2
```
local/rubricai/tests/rag_client_test.php
local/rubricai/tests/session_manager_test.php
local/rubricai/tests/data_provider_test.php
local/rubricai/tests/lock_manager_test.php
local/rubricai/tests/behat/library.feature
local/rubricai/tests/behat/audit.feature
local/rubricai/tests/behat/ui_protection.feature
local/rubricai/tests/fixtures/mock_server.php
```

### Fase 3
```
.github/workflows/ci.yml
README.md (reemplaza el actual)
CHANGES.md
CONTRIBUTING.md
local/rubricai/pix/icon.svg
```

---

## Lo que NO cambia

- La arquitectura del plugin (steps, session_manager, rag_client, data_provider)
- La integración con Moodle (navegación secundaria, capabilities actuales)
- El backend Python (no es parte del plugin Moodle)
- El nombre de componente `local_rubricai`
