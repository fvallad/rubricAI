import os
import logging
import dashscope
from dashscope import Generation
from dotenv import load_dotenv

load_dotenv()

dashscope.api_key = os.getenv("DASHSCOPE_API_KEY")
DEFAULT_MODEL = os.getenv("DASHSCOPE_MODEL", "qwen-plus")

# Fix for workspace-specific endpoints
base_url = os.getenv("DASHSCOPE_BASE_URL")
if base_url:
    dashscope.base_http_api_url = base_url

def generate_completion(prompt: str, system_prompt: str = "Eres un experto en pedagogía y diseño de instrumentos de evaluación."):
    """
    Calls Alibaba DashScope API (Qwen) for text generation.
    Returns (content, usage)
    """
    try:
        messages = [
            {'role': 'system', 'content': system_prompt},
            {'role': 'user', 'content': prompt}
        ]
        response = Generation.call(
            model=DEFAULT_MODEL,
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
            return content, usage
        else:
            logging.error(f"DashScope Error: {response.code} - {response.message}")
            return None, None
    except Exception as e:
        logging.exception("Exception during DashScope call")
        return None, None

def classify_feedback(feedback_text: str) -> str:
    """
    Classifies if user feedback is a valid pedagogical adjustment request.
    Returns a JSON string matching FeedbackClassification schema.
    """
    system_prompt = """Eres un evaluador de entradas de usuario para una IA pedagógica.
  Tu tarea es determinar si el texto del usuario es una solicitud válida de AJUSTE o CORRECCIÓN sobre un material de evaluación (ej: 'hazlo más difícil', 'usa otro caso', 'cambia el tono').
  Si el usuario pide algo fuera de contexto (chistes, insultos, temas no educativos), marca is_valid como false.
  Responde UNICAMENTE con un JSON: {"is_valid": bool, "reason": "breve explicación si es falso"}"""
    
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
