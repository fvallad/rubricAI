import os
import logging
import time
import threading
import re
from abc import ABC, abstractmethod
from dotenv import load_dotenv
from tracing import trace_llm_call, get_traced_openai_client, add_run_metadata

load_dotenv()

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Global Rate Limiter (prevents 429 errors on Gemini Free Tier)
# ---------------------------------------------------------------------------

class _RateLimiter:
    """
    Thread-safe rate limiter that enforces a minimum interval between API
    calls so the total RPM never exceeds the configured limit.

    Free-tier Gemini 2.5 Flash: 10 RPM → min 6s between requests.
    We add a 1s safety margin → 7s effective gap.
    """

    def __init__(self, max_rpm: int | None = None):
        rpm = max_rpm or int(os.getenv("GEMINI_RPM_LIMIT", "10"))
        safety_margin = 1.0  # extra second of buffer
        self._min_interval = (60.0 / rpm) + safety_margin
        self._lock = threading.Lock()
        self._last_request_time = 0.0
        logger.info(
            f"Rate limiter initialised: {rpm} RPM → "
            f"{self._min_interval:.1f}s min gap between requests"
        )

    def wait(self):
        """Block the calling thread until it is safe to fire the next request."""
        with self._lock:
            now = time.monotonic()
            elapsed = now - self._last_request_time
            if elapsed < self._min_interval:
                sleep_time = self._min_interval - elapsed
                logger.info(
                    f"⏳ Rate limiter: waiting {sleep_time:.1f}s before next LLM call"
                )
                time.sleep(sleep_time)
            self._last_request_time = time.monotonic()

    def update_rpm(self, rpm: int):
        """Update the rate limit dynamically when keys are added or rotated."""
        with self._lock:
            safety_margin = 1.0  # extra second of buffer
            self._min_interval = (60.0 / rpm) + safety_margin
            logger.info(
                f"Rate limiter RPM updated to {rpm} → "
                f"{self._min_interval:.1f}s min gap between requests"
            )


# Singleton — shared across all agents / threads
_rate_limiter = _RateLimiter()

class LLMProvider(ABC):
    @abstractmethod
    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        pass

class DashScopeProvider(LLMProvider):
    def __init__(self):
        import dashscope
        from dashscope import Generation
        self.Generation = Generation
        
        dashscope.api_key = os.getenv("DASHSCOPE_API_KEY")
        self.model = os.getenv("DASHSCOPE_MODEL", "qwen-plus")
        
        # Fix for workspace-specific endpoints
        base_url = os.getenv("DASHSCOPE_BASE_URL")
        if base_url:
            dashscope.base_http_api_url = base_url

    @trace_llm_call(name="dashscope_completion", metadata={"ls_provider": "dashscope"})
    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        add_run_metadata({"ls_model_name": self.model})
        try:
            messages = [
                {'role': 'system', 'content': system_prompt},
                {'role': 'user', 'content': prompt}
            ]
            response = self.Generation.call(
                model=self.model,
                messages=messages,
                result_format='message',
            )
            if response.status_code == 200:
                content = response.output.choices[0].message.content
                usage = {
                    "input_tokens": response.usage.input_tokens,
                    "output_tokens": response.usage.output_tokens,
                    "total_tokens": response.usage.total_tokens
                }
                add_run_metadata({"usage": usage})
                return content, usage
            else:
                logging.error(f"DashScope Error: {response.code} - {response.message}")
                return None, None
        except Exception as e:
            logging.exception("Exception during DashScope call")
            return None, None

class OpenAIProvider(LLMProvider):
    def __init__(self):
        import openai
        base_client = openai.OpenAI(api_key=os.getenv("OPENAI_API_KEY"))
        # Wrap with LangSmith tracing — auto-captures all LLM call details
        self.client = get_traced_openai_client(base_client)
        self.model = os.getenv("OPENAI_MODEL", "gpt-4o")

    @trace_llm_call(name="openai_completion", metadata={"ls_provider": "openai"})
    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        add_run_metadata({"ls_model_name": self.model})
        try:
            response = self.client.chat.completions.create(
                model=self.model,
                messages=[
                    {"role": "system", "content": system_prompt},
                    {"role": "user", "content": prompt}
                ]
            )
            content = response.choices[0].message.content
            usage = {
                "input_tokens": response.usage.prompt_tokens,
                "output_tokens": response.usage.completion_tokens,
                "total_tokens": response.usage.total_tokens
            }
            add_run_metadata({"usage": usage})
            return content, usage
        except Exception as e:
            logging.exception("Exception during OpenAI call")
            return None, None

class GoogleProvider(LLMProvider):
    def __init__(self):
        import openai
        # Support comma-separated list of keys for rotation
        raw_keys = os.getenv("GOOGLE_API_KEY", "")
        self.api_keys = [k.strip().strip('"').strip("'") for k in raw_keys.split(",") if k.strip()]
        if not self.api_keys:
            # Fallback to empty if not configured
            self.api_keys = [""]
            
        self.clients = []
        for key in self.api_keys:
            base_client = openai.OpenAI(
                api_key=key,
                base_url="https://generativelanguage.googleapis.com/v1beta/openai/"
            )
            # Wrap each with LangSmith tracing
            self.clients.append(get_traced_openai_client(base_client))

        self.model = os.getenv("GOOGLE_MODEL", "gemini-2.5-flash")
        self.current_key_index = 0
        self._keys_lock = threading.Lock()
        
        # Scale the rate limiter RPM based on the number of keys
        base_rpm = int(os.getenv("GEMINI_RPM_LIMIT", "10"))
        total_rpm = base_rpm * len(self.api_keys)
        _rate_limiter.update_rpm(total_rpm)
        
        logger.info(
            f"GoogleProvider initialized with {len(self.api_keys)} API key(s) for rotation. "
            f"Collective rate limit set to {total_rpm} RPM."
        )

    @trace_llm_call(name="google_gemini_completion", metadata={"ls_provider": "google"})
    def generate_completion(self, prompt: str, system_prompt: str) -> tuple[str, dict]:
        from openai import RateLimitError, InternalServerError, APIConnectionError, APITimeoutError
        import random
        add_run_metadata({"ls_model_name": self.model})
        
        def _parse_retry_delay(error_msg: str) -> float | None:
            # Match "Please retry in 45.376218325s" or similar
            match = re.search(r"retry in\s+([0-9.]+)\s*s", error_msg, re.IGNORECASE)
            if match:
                return float(match.group(1))
            # Match "retryDelay: '45s'" or "retryDelay: 45" or similar
            match2 = re.search(r"retryDelay[\'\"]?\s*:\s*[\'\"]?([0-9.]+)", error_msg, re.IGNORECASE)
            if match2:
                return float(match2.group(1))
            return None

        num_keys = len(self.clients)
        max_retries = max(8, num_keys * 2)
        backoff_factor = 2.0
        
        for attempt in range(max_retries):
            with self._keys_lock:
                active_idx = self.current_key_index % num_keys
                client = self.clients[active_idx]
                active_key = self.api_keys[active_idx]
                masked_key = active_key[:8] + "..." + active_key[-4:] if len(active_key) > 12 else "invalid/empty"

            try:
                # Proactive throttle: wait until RPM budget allows a new request
                _rate_limiter.wait()
                
                logger.info(f"Using Google API Key index {active_idx} ({masked_key})")
                response = client.chat.completions.create(
                    model=self.model,
                    messages=[
                        {"role": "system", "content": system_prompt},
                        {"role": "user", "content": prompt}
                    ]
                )
                content = response.choices[0].message.content
                usage = {
                    "input_tokens": response.usage.prompt_tokens if response.usage else 0,
                    "output_tokens": response.usage.completion_tokens if response.usage else 0,
                    "total_tokens": response.usage.total_tokens if response.usage else 0
                }
                add_run_metadata({"usage": usage, "api_key_index_used": active_idx, "api_keys_count": num_keys})
                return content, usage
            except (RateLimitError, InternalServerError, APIConnectionError, APITimeoutError) as e:
                # Rotate the index to the next key on failure
                with self._keys_lock:
                    self.current_key_index = (self.current_key_index + 1) % num_keys
                    new_idx = self.current_key_index
                    new_key = self.api_keys[new_idx]
                    new_masked = new_key[:8] + "..." + new_key[-4:] if len(new_key) > 12 else "invalid/empty"
                    logger.warning(
                        f"Request failed with key index {active_idx} ({type(e).__name__}). "
                        f"Rotating to key index {new_idx} ({new_masked})."
                    )

                if attempt == max_retries - 1:
                    logger.error(f"Max retries reached for Google Gemini transient error: {e}")
                    raise e
                
                # Default backoff: if we have multiple keys, try the next key quickly (0.5 to 1.5s)
                if num_keys > 1 and attempt < num_keys:
                    sleep_time = random.uniform(0.5, 1.5)
                else:
                    sleep_time = (backoff_factor ** (attempt - num_keys + 1)) + random.uniform(1.0, 3.0)
                
                # If it's a RateLimitError, check if we parsed a specific delay
                if isinstance(e, RateLimitError):
                    error_msg = str(e)
                    parsed_delay = _parse_retry_delay(error_msg)
                    if parsed_delay:
                        if num_keys > 1 and attempt < num_keys:
                            sleep_time = random.uniform(0.5, 1.5)
                        else:
                            sleep_time = parsed_delay + 2.0
                        logger.warning(
                            f"Gemini API Quota/Rate Limit hit for key {active_idx}. Google suggested retrying in {parsed_delay:.2f}s. "
                            f"Sleeping for {sleep_time:.2f}s... (Attempt {attempt+1}/{max_retries})"
                        )
                    else:
                        if num_keys > 1 and attempt < num_keys:
                            sleep_time = random.uniform(0.5, 1.5)
                        else:
                            sleep_time = max(sleep_time, 15.0 * (attempt - num_keys + 2))
                        logger.warning(
                            f"Gemini API Quota/Rate Limit hit for key {active_idx}. Sleeping for {sleep_time:.2f}s... "
                            f"(Attempt {attempt+1}/{max_retries})"
                        )
                else:
                    err_name = type(e).__name__
                    status_str = f"status={e.status_code}" if hasattr(e, "status_code") else "connection/timeout"
                    logger.warning(
                        f"Gemini API transient error ({err_name}, {status_str}) for key {active_idx}. "
                        f"Retrying in {sleep_time:.2f}s... (Attempt {attempt+1}/{max_retries})"
                    )
                
                time.sleep(sleep_time)
            except Exception as e:
                logging.exception(f"Exception during Google Gemini call with key index {active_idx}")
                with self._keys_lock:
                    self.current_key_index = (self.current_key_index + 1) % num_keys
                return None, None

def get_llm_provider() -> LLMProvider:
    provider_name = os.getenv("LLM_PROVIDER", "dashscope").lower()
    if provider_name == "openai":
        return OpenAIProvider()
    if provider_name == "google" or provider_name == "gemini":
        return GoogleProvider()
    # Default to DashScope
    return DashScopeProvider()

# Singleton instance initialized on module load
_llm_instance = get_llm_provider()

@trace_llm_call(name="generate_completion")
def generate_completion(prompt: str, system_prompt: str = "Eres un experto en pedagogía y diseño de instrumentos de evaluación."):
    """
    Calls the configured LLM API (via LLM_PROVIDER env var) for text generation.
    Returns (content, usage)
    """
    return _llm_instance.generate_completion(prompt, system_prompt)

@trace_llm_call(name="classify_feedback", tags=["feedback-classification"])
def classify_feedback(feedback_text: str) -> str:
    """
    Classifies if user feedback is a valid pedagogical adjustment request.
    Returns a JSON string matching FeedbackClassification schema.
    """
    system_prompt = """Eres un evaluador de entradas de usuario para una IA pedagógica.
  Tu tarea es determinar si el texto del usuario es una solicitud válida de AJUSTE o CORRECCIÓN sobre un material de evaluación (ej: 'hazlo más difícil', 'usa otro caso', 'cambia el tono').
  Si el usuario pide algo fuera de contexto (chistes, insultos, temas no educativos), marca is_valid como false.
  Responde UNICAMENTE con un JSON: {"is_valid": bool, "reason": "breve explicación si es falso"}"""
    
    add_run_metadata({"feedback_length": len(feedback_text)})
    res, _ = generate_completion(feedback_text, system_prompt)
    return res

def get_suggestions_prompt(course_summary, objective, dimensions, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES REQUERIDOS POR EL DOCENTE (Prioridad alta):\n{feedback}\n" if feedback else ""
    return f"""
    Tu tarea es proponer 3 instrumentos de evaluación que estén perfectamente alineados con los objetivos y el contexto del curso.

  ### 1. CONTEXTO GENERAL DEL CURSO:
  {course_summary}

  ### 2. OBJETIVOS DE APRENDIZAJE (Taxonomía de Bloom):
  {objective}

  ### 3. DIMENSIONES PEDAGÓGICAS DEFINIDAS:
  {dimensions}

  ### 4. MATERIALES DEL CURSO, DIRECTRICES Y CATÁLOGO DE INSTRUMENTOS:
  {full_context}
  {feedback_sect}

  ### INSTRUCCIONES CRÍTICAS:
  1. Debes elegir exactamente 3 instrumentos de la "LISTA DE INSTRUMENTOS DISPONIBLES" proporcionada arriba. El valor de "name" en tu respuesta debe ser el NOMBRE EXACTO del catálogo.
  2. Basándote en el contexto y las directrices, justifica detalladamente por qué cada uno de estos 3 instrumentos es la mejor opción.
  3. Cada propuesta debe estar justificada pedagógicamente, mencionando cómo se alinea con el nivel de Bloom y qué directriz institucional cumple.
  4. Responde UNICAMENTE en formato JSON:
  {{
    "suggestions": [
      {{
        "name": "Nombre exacto del catálogo",
        "why": "Justificación detallada citando el contexto y la directriz aplicada.",
        "lim": "Limitación técnica del instrumento."
      }}
    ]
  }}"""

def get_design_prompt(chosen_instrument, instrument_desc, structured_materials, num_items=5, valid_types=None, feedback="", current_design=None):
    feedback_sect = f"\n### AJUSTES ESPECÍFICOS SOLICITADOS (Prioridad alta):\n{feedback}\n" if feedback else ""
    
    types_str = ""
    if valid_types:
        types_str = "\n"
        for t in valid_types:
            # 8 spaces of indentation and double newline for clarity
            types_str += f"        - {t['name']}: {t['definition']}\n\n"
    
    current_design_sect = f"### DISEÑO ACTUAL (Contexto para refinamiento):\n{current_design}\n" if current_design else ""
    
    return f"""### TAREA A REALIZAR:
  Diseñar una batería de {num_items} ítems de evaluación para un instrumento de tipo: {chosen_instrument}.

  **Descripción del instrumento:**
  {instrument_desc}

  ### OBJETIVOS DE LA EVALUACIÓN (CON EXTRACTOS Y REFERENCIAS):
  {structured_materials}

  ### TIPOS DE PREGUNTAS PERMITIDOS (DEBES ELEGIR SOLO DE ESTA LISTA):
  {types_str}
  
  
  {feedback_sect}

  {current_design_sect}

  ### REQUISITOS DE CALIDAD Y FORMATO:
  1. Genera exactamente {num_items} ítems.
  2. Cada ítem debe usar OBLIGATORIAMENTE uno de los "TIPOS DE PREGUNTAS PERMITIDOS" listados arriba. El campo "type" debe coincidir EXACTAMENTE con el nombre del tipo.
  3. Para cada ítem, identifica qué objetivos específicos de los listados arriba está cubriendo.
  4. **Estructura JSON por Tipo y Respuestas Correctas**:
      - **Opción múltiple**: Llena `consiga` (enunciado), `alternativas` (mínimo 4) y `correct_index` (índice 0-indexed de la opción correcta).
      - **Verdadero/Falso**: Llena `consiga` (afirmación) y `correct_boolean` (true si es verdadera, false si es falsa).
      - **Emparejamiento / Poner en orden**: Describe la tarea en `consiga` y llena la lista `pairs` con objetos `{{"premise": "...", "answer": "..."}}`.
      - **Respuesta breve / Texto lacunar**: Enuncia la tarea en `consiga` y proporciona la respuesta esperada en `short_answer`.
      - **Numérica**: Enuncia el problema en `consiga` y provee el valor exacto en `numerical_value`.
      - **Ensayo / Respuesta abierta**: Describe las orientaciones en `consiga`. No requiere respuesta predefinida.

  5. Los ítems deben redactarse con rigor pedagógico y coherencia con los extractos de los materiales proporcionados.
  6. Asigna una dificultad ("Fácil", "Media", "Difícil").
  7. **Refinamiento Parcial**: Si en los "AJUSTES ESPECÍFICOS SOLICITADOS" se menciona un ítem en particular (ej: `[Ítem 1] ...`), tu tarea es REGENERAR ese ítem aplicando los cambios solicitados mientras mantienes el resto de los ítems del "DISEÑO ACTUAL" exactamente iguales (o con cambios mínimos de coherencia).

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {{
    "title": "Título descriptivo del instrumento",
    "items": [
      {{
        "type": "Nombre exacto del tipo",
        "objectives": ["Obj 1"],
        "consiga": "...",
        "difficulty": "Media",
        "alternativas": ["op A", "op B"],
        "correct_index": 0,
        "correct_boolean": null,
        "pairs": [ {{"premise": "P1", "answer": "A1"}} ],
        "short_answer": "...",
        "numerical_value": null
      }}
    ],
    "justification": "Explica la coherencia pedagógica de la selección."
  }}"""

def get_rubric_prompt(instrument_content, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES EN LA RÚBRICA:\n{feedback}\n" if feedback else ""
    return f"""Como experto en evaluación, genera una RÚBRICA ANALÍTICA para el siguiente instrumento.

  ### INSTRUMENTO A EVALUAR:
  {instrument_content}

  ### OBJETIVOS DE APRENDIZAJE:
  {objective}

  ### MARCO PEDAGÓGICO Y REGLAS DE RÚBRICAS:
  {full_context}
  {feedback_sect}

  ### REQUISITOS:
  1. Define criterios claros y discriminativos basados en los materiales del curso.
  2. Los descriptores de niveles deben seguir las reglas de redacción de las DIRECTRICES PEDAGÓGICAS.
  3. Asegura una progresión lógica en los puntajes.

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {{
    "title": "Rúbrica de Evaluación",
    "criteria": [
      {{
        "name": "Nombre del criterio",
        "description": "Qué se evalúa",
        "levels": [
          {{
            "label": "Nivel (ej: Destacado)",
            "score": 10,
            "description": "Descriptor de desempeño"
          }}
        ]
      }}
    ]
  }}"""

def get_correction_prompt(correction_type, correction_label, chosen_instrument, instrument_content, quiz_items_json, objective, full_context, feedback=""):
    feedback_sect = f"\n### AJUSTES SOLICITADOS POR EL DOCENTE:\n{feedback}\n" if feedback else ""

    # Type-specific instructions and JSON schema
    type_instructions = {
        "clave_correccion": {
            "desc": "una CLAVE DE CORRECCIÓN (answer key) que proporcione la respuesta correcta para cada pregunta/ítem del instrumento de evaluación",
            "schema": '''{
    "title": "Clave de corrección para ...",
    "type": "clave_correccion",
    "items": [
      {"question": "Texto de la pregunta", "answer": "Respuesta correcta"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "lista_cotejo": {
            "desc": "una LISTA DE COTEJO (checklist) con criterios observables que verifican la presencia o ausencia de componentes requeridos",
            "schema": '''{
    "title": "Lista de cotejo para ...",
    "type": "lista_cotejo",
    "criteria": [
      {"criterion": "Descripción del criterio observable"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "escala_valoracion": {
            "desc": "una ESCALA DE VALORACIÓN con criterios y niveles de logro que permitan graduar el desempeño de forma ágil",
            "schema": '''{
    "title": "Escala de valoración para ...",
    "type": "escala_valoracion",
    "levels": ["Insuficiente", "Suficiente", "Bueno", "Destacado"],
    "criteria": [
      {"criterion": "Descripción del criterio a evaluar"}
    ],
    "justification": "Breve justificación pedagógica"
  }'''
        },
        "rubrica": {
            "desc": "una RÚBRICA ANALÍTICA con criterios y descriptores detallados para cada nivel de logro",
            "schema": '''{
    "title": "Rúbrica analítica para ...",
    "type": "rubrica",
    "levels": ["Insuficiente", "Suficiente", "Bueno", "Destacado"],
    "criteria": [
      {"criterion": "Nombre del criterio"}
    ],
    "rubric_criteria": [
      {
        "name": "Nombre del criterio",
        "description": "Qué se evalúa",
        "levels": [
          {"label": "Destacado", "score": 4, "description": "Descriptor de desempeño"}
        ]
      }
    ],
    "justification": "Justificación pedagógica detallada"
  }'''
        }
    }

    info = type_instructions.get(correction_type, type_instructions["rubrica"])

    return f"""### TAREA A REALIZAR:
  Genera {info['desc']} para un instrumento de evaluación de tipo "{chosen_instrument}".

  ### OBJETIVOS DE APRENDIZAJE:
  {objective}

  ### INSTRUMENTO DE EVALUACIÓN (Ítems generados previamente):
  {instrument_content}

  ### ÍTEMS DEL CUESTIONARIO (JSON):
  {quiz_items_json}

  ### MARCO PEDAGÓGICO Y DIRECTRICES:
  {full_context}
  {feedback_sect}

  ### INSTRUCCIONES CRÍTICAS:
  1. El instrumento de corrección debe estar perfectamente alineado con los ítems de evaluación proporcionados.
  2. Cada criterio debe ser claro, observable y pedagógicamente fundamentado.
  3. Basa los criterios en los objetivos de aprendizaje y los materiales del curso.
  4. Responde ÚNICAMENTE en formato JSON según el esquema indicado abajo.

  ### FORMATO DE RESPUESTA (JSON ÚNICAMENTE):
  {info['schema']}"""
