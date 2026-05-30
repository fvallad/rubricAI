import json
import logging
import re
import os
from typing import Dict, List, Tuple
from neo4j_client import get_neo4j_client
from llm import generate_completion

logger = logging.getLogger(__name__)

def check_has_content(course_data: dict) -> bool:
    """Checks if the course has any actual pedagogical/evaluable content,
    ignoring default forums like 'Avisos', 'Novedades', or 'Announcements'."""
    sections = course_data.get("sections", [])
    evaluable_activities = []
    for s in sections:
        for act in s.get("activities", []):
            name_lower = act.get("name", "").lower()
            type_lower = act.get("type", "").lower()
            # Ignore Moodle default announcement forums
            if type_lower == "forum" and (name_lower in ["avisos", "announcements", "novedades", "news forum"]):
                continue
            evaluable_activities.append(act)
    return len(evaluable_activities) > 0

class PedagogicalHolisticAgent:
    """Agent in charge of assessing pedagogical alignment, Bloom taxonomy matching, and overall content coherence."""
    
    def evaluate(self, course_data: dict, rubric_data: dict, course_id: int = None) -> str:
        # Check if the course has any activities in any sections
        if not check_has_content(course_data):
            return (
                "CRITICAL WARNING: El curso no contiene ninguna actividad ni recurso pedagógico en sus secciones. "
                "No hay contenidos prácticos ni evaluaciones para contrastar contra la rúbrica. "
                "La alineación pedagógica es inexistente (0%) debido a la falta total de actividades."
            )

        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)
        course_str = json.dumps(course_data, indent=2, ensure_ascii=False)
        
        # 1. Fetch relevant RAG chunks for each criterion
        rag_context = ""
        if course_id is not None:
            from rag.search import search_course
            criteria = rubric_data.get("criteria", [])
            rag_findings = []
            for crit in criteria:
                name = crit.get("name", "")
                desc = crit.get("description", "")
                query = f"{name} {desc}".strip()
                if query:
                    try:
                        results = search_course(course_id, query, top_k=3)
                        if results:
                            rag_findings.append(f"Para el criterio '{name}':")
                            for res in results:
                                text = res.get("text", "").strip()
                                filename = res.get("filename", "")
                                sim = res.get("similarity", 0.0)
                                rag_findings.append(f"  - [Similitud: {sim:.1%}] \"{text}\" (Fuente: {filename})")
                    except Exception as e:
                        logger.warning(f"Error performing criteria RAG search for {name}: {e}")
            if rag_findings:
                rag_context = "\n### FRAGMENTOS REALES DE MATERIALES DEL CURSO (RAG):\n" + "\n".join(rag_findings)
        
        system_prompt = (
            "Eres el Agente Holístico Pedagógico. Tu tarea es analizar de forma profunda y educativa "
            "la alineación constructiva entre todos los recursos y actividades del curso frente a una rúbrica de referencia."
        )
        
        prompt = f"""
        Analiza el siguiente curso (con sus recursos y actividades) frente a la rúbrica pedagógica proporcionada:
        
        ### RÚBRICA DE EVALUACIÓN:
        {rubric_str}
        
        ### DATOS DEL CURSO (Estructura, Actividades, Temas):
        {course_str}
        
        {rag_context}
        
        ### TAREA:
        1. Evalúa si los contenidos teóricos provistos en los recursos y actividades cubren adecuadamente los criterios de la rúbrica.
        2. Analiza si el nivel cognitivo de los ítems o preguntas (especialmente en Cuestionarios o Tareas) corresponde al nivel de la Taxonomía de Bloom implícito en la rúbrica.
        3. Identifica vacíos temáticos (gaps) donde falte material o actividades para cumplir con algún criterio.
        4. Escribe un análisis sintético detallado en español. Estructúralo con viñetas claras.
        """
        
        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar la evaluación holística."

class FormatStructureAgent:
    """Agent in charge of auditing formatting, settings, technical consistency, and structure of Moodle course elements."""
    
    def evaluate(self, course_data: dict) -> str:
        # Check if the course has any activities in any sections
        if not check_has_content(course_data):
            return (
                "CRITICAL WARNING: El aula virtual se encuentra vacía. No tiene secciones con actividades "
                "o recursos configurados. Formato e infraestructura técnica incompleta."
            )

        course_str = json.dumps(course_data, indent=2, ensure_ascii=False)
        
        system_prompt = (
            "Eres el Agente de Formato y Estructura. Tu tarea es auditar las configuraciones técnicas, "
            "completitud, configuraciones de cuestionarios, fechas, ponderaciones y estructura general del aula virtual de Moodle."
        )
        
        prompt = f"""
        Analiza el formato y la configuración técnica del siguiente curso de Moodle:
        
        ### DATOS DE CONFIGURACIÓN DEL CURSO:
        {course_str}
        
        ### TAREA:
        1. Audita si las descripciones (intro) de las actividades están completas o si hay elementos vacíos/insuficientes.
        2. Revisa la coherencia técnica (ej: si hay cuestionarios sin preguntas, tareas sin fecha de entrega o en el pasado).
        3. Verifica si la escala o puntaje de calificación es consistente (ej: si hay pesos incoherentes).
        4. Escribe un análisis detallado en español sobre aspectos técnicos y estructurales del curso.
        """
        
        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar la evaluación de formato."

class OntologyAgent:
    """Agent representing the Neo4j ontology. Resolves semantic connections and writes evaluation graph structures."""
    
    def __init__(self):
        self.client = get_neo4j_client()
        
    def sync_course_structure(self, course_id: int, course_title: str, course_data: dict):
        """Syncs the raw course structure nodes into Neo4j."""
        if not self.client or not self.client.initialized:
            logger.warning("Neo4j client not initialized. Skipping course structure sync.")
            return
            
        sections = course_data.get("sections", [])
        activities = []
        resources = []
        
        for sec in sections:
            sec_name = sec.get("name", "Sección")
            for act in sec.get("activities", []):
                act_type = act.get("type", "unknown")
                # Classify into resource or activity
                if act_type in ["resource", "folder", "page", "url", "book", "label"]:
                    resources.append({
                        "id": act.get("name"),
                        "filename": act.get("name"),
                        "name": act.get("name"),
                        "type": act_type
                    })
                else:
                    settings = act.get("settings")
                    if not isinstance(settings, dict):
                        settings = {}
                    activities.append({
                        "id": act.get("id"),
                        "name": act.get("name"),
                        "type": act_type,
                        "description": act.get("description", ""),
                        "duedate": settings.get("duedate", 0)
                    })
                    
        self.client.sync_course_data(course_id, course_title, activities, resources)

    def get_rubric_criteria_analysis(self, course_id: int, rubric_id: str, rubric_data: dict) -> dict:
        """
        For each rubric criterion, searches RAG index for matching activities and files,
        records COVERS relationship in Neo4j, and returns a structured mapping analysis.
        """
        analysis = {}
        criteria = rubric_data.get("criteria", [])
        
        def extract_cm_id(filepath: str) -> str:
            if not filepath:
                return ""
            try:
                parent_name = os.path.basename(os.path.dirname(filepath))
                if "_" in parent_name:
                    parts = parent_name.split("_")
                    if parts[0].isdigit():
                        return parts[0]
            except Exception:
                pass
            return ""

        from rag.search import search_course
        
        # Detach previous COVERS relationships for this course activities
        if self.client and self.client.initialized:
            self.client.query(
                """
                MATCH (a:Activity)-[rel:COVERS]->(c:Criterion)
                WHERE a.id STARTS WITH $prefix
                DELETE rel
                """,
                {"prefix": f"c_{course_id}_"}
            )
            
        for idx, crit in enumerate(criteria):
            crit_name = crit.get("name", "")
            crit_desc = crit.get("description", "")
            crit_id = f"{rubric_id}_crit_{idx}"
            query = f"{crit_name} {crit_desc}".strip()
            
            analysis[crit_name] = {
                "description": crit_desc,
                "activities": []
            }
            
            if not query:
                continue
                
            try:
                results = search_course(course_id, query, top_k=5)
                seen_acts = {}
                for res in results:
                    filepath = res.get("path", "")
                    sim = res.get("similarity", 0.0)
                    cm_id = extract_cm_id(filepath)
                    
                    if cm_id:
                        act_node_id = f"c_{course_id}_act_{cm_id}"
                        if act_node_id not in seen_acts or sim > seen_acts[act_node_id]["similarity"]:
                            seen_acts[act_node_id] = {
                                "id": act_node_id,
                                "name": res.get("filename", "").replace(".txt", ""),
                                "similarity": sim,
                                "text_match": res.get("text", "")[:300] + "..."
                            }
                
                for act_info in seen_acts.values():
                    analysis[crit_name]["activities"].append(act_info)
                    
                    # Link in Neo4j
                    if self.client and self.client.initialized:
                        self.client.query(
                            """
                            MATCH (a:Activity {id: $act_id})
                            MATCH (c:Criterion {id: $crit_id})
                            MERGE (a)-[rel:COVERS]->(c)
                            SET rel.similarity = $similarity,
                                rel.timestamp = timestamp()
                            """,
                            {
                                "act_id": act_info["id"],
                                "crit_id": crit_id,
                                "similarity": act_info["similarity"]
                            }
                        )
            except Exception as e:
                logger.warning(f"Error compiling criteria analysis for {crit_name}: {e}")
                
        return analysis

    def log_evaluation_results(self, course_id: int, rubric_id: str, score: float, recommendations: List[dict]):
        """Logs the evaluation and links recommendations to the course node in Neo4j."""
        if not self.client or not self.client.initialized:
            return
            
        # 1. Create relation Course -> Rubric with score metadata
        self.query_cypher = """
        MATCH (c:Course {id: $course_id})
        MATCH (r:Rubric {id: $rubric_id})
        MERGE (c)-[rel:EVALUATED_WITH]->(r)
        SET rel.score = $score,
            rel.timestamp = timestamp()
        """
        self.client.query(self.query_cypher, {"course_id": course_id, "rubric_id": rubric_id, "score": score})
        
        # 2. Add Recommendation nodes
        # Detach old recommendations first
        self.client.query(
            """
            MATCH (c:Course {id: $course_id})-[rel:HAS_RECOMMENDATION]->(rec:Recommendation)
            DETACH DELETE rec
            """,
            {"course_id": course_id}
        )
        
        for idx, rec in enumerate(recommendations):
            self.client.query(
                """
                MATCH (c:Course {id: $course_id})
                CREATE (rec:Recommendation {
                    id: $rec_id,
                    element: $element,
                    type: $type,
                    issue: $issue,
                    change: $change
                })
                CREATE (c)-[:HAS_RECOMMENDATION {order: $order}]->(rec)
                """,
                {
                    "course_id": course_id,
                    "rec_id": f"c_{course_id}_rec_{idx}",
                    "element": rec.get("element", "General"),
                    "type": rec.get("type", "holistic"),
                    "issue": rec.get("issue", ""),
                    "change": rec.get("change", ""),
                    "order": idx
                }
            )

class SynthesisAgent:
    """Agent in charge of consolidating all reports and creating the structured EvaluateResponse."""
    
    def synthesize(self, course_id: int, rubric_id: str, holistic_report: str, format_report: str, ontology_analysis: dict = None) -> dict:
        is_empty = "CRITICAL WARNING" in (holistic_report or "") or "CRITICAL WARNING" in (format_report or "")
        if is_empty:
            return {
                "course_id": course_id,
                "rubric_id": rubric_id,
                "overall_score": 0.0,
                "holistic_evaluation": holistic_report or "El curso está vacío.",
                "format_evaluation": format_report or "El curso está vacío.",
                "recommendations": [
                    {
                        "element": "General",
                        "type": "format",
                        "issue": "El aula virtual no contiene ninguna sección con actividades o recursos.",
                        "change": "Debe agregar secciones y actividades pedagógicas (como tareas, cuestionarios, foros) y archivos de soporte para poder auditar el formato y la alineación constructiva del curso."
                    }
                ]
            }

        ontology_str = ""
        if ontology_analysis:
            ontology_str = "\n### ANÁLISIS DE COBERTURA ONTOLÓGICA (Similitud Semántica Actividad -> Criterio):\n"
            for crit, info in ontology_analysis.items():
                ontology_str += f"- Criterio: {crit} ({info.get('description', '')})\n"
                acts = info.get("activities", [])
                if acts:
                    for act in acts:
                        ontology_str += f"  * Actividad: '{act['name']}' - Similitud Semántica: {act['similarity']:.1%}\n"
                else:
                    ontology_str += "  * (No se detectaron actividades directamente alineadas semánticamente)\n"

        system_prompt = (
            "Eres el Agente Consolidador de RubricAI. Tu tarea es recibir las evaluaciones de formato "
            "y holísticas, además de los datos de cobertura ontológica, y generar una respuesta JSON final estrictamente estructurada que contenga el puntaje global, "
            "resúmenes de informes y una lista estructurada de recomendaciones accionables de cambio."
        )
        
        prompt = f"""
        Consolida los siguientes informes de evaluación de la calidad de un curso frente a la rúbrica de referencia.
        
        ### REPORTE HOLÍSTICO:
        {holistic_report}
        
        ### REPORTE DE FORMATO:
        {format_report}
        {ontology_str}
        
        ### TAREA:
        1. Calcula una puntuación global de alineación (overall_score) de 0.0 a 100.0 basada en la severidad de los problemas detectados en ambos reportes y en qué tan bien cubren las actividades los criterios de la rúbrica.
        2. Redacta una lista estructurada de recomendaciones específicas para el docente. Cada recomendación debe tener:
           - `element`: la actividad/recurso específica (ej: "Foro N°1" o "General" si aplica a todo).
           - `type`: el tipo de problema, estrictamente uno de los siguientes: "holistic" o "format".
           - `issue`: explicación breve de qué está mal o falta.
           - `change`: instrucción exacta y detallada de qué cambiar en Moodle para solucionarlo.
        
        ### FORMATO DE SALIDA (Responde ÚNICAMENTE en JSON con esta estructura):
        {{
          "overall_score": 85.5,
          "holistic_evaluation": "Resumen consolidado de la alineación pedagógica...",
          "format_evaluation": "Resumen consolidado del estado técnico y estructural del curso...",
          "recommendations": [
            {{
              "element": "Nombre de la Actividad o Recurso",
              "type": "holistic|format",
              "issue": "Problema específico...",
              "change": "Acción recomendada detallada para cambiar en Moodle..."
            }}
          ]
        }}
        """
        
        res, _ = generate_completion(prompt, system_prompt)
        if res is None:
            raise Exception("El modelo de lenguaje (LLM) no devolvió ninguna respuesta (puede ser por Rate Limit/cuota o API key incorrecta). Verifica la configuración de tu archivo .env.")
        
        try:
            # Squeeze to find JSON object
            start_idx = res.find('{')
            end_idx = res.rfind('}')
            if start_idx != -1 and end_idx != -1:
                clean_json = res[start_idx:end_idx+1].strip()
            else:
                clean_json = re.sub(r'^```json|```$', '', res, flags=re.MULTILINE).strip()
            
            data = json.loads(clean_json)
            if not data.get("holistic_evaluation"):
                data["holistic_evaluation"] = holistic_report or "Evaluación holística completada."
            if not data.get("format_evaluation"):
                data["format_evaluation"] = format_report or "Evaluación de formato completada."
            if "overall_score" not in data:
                data["overall_score"] = 75.0
            
            data["course_id"] = course_id
            data["rubric_id"] = rubric_id
            return data
        except Exception as e:
            logger.error(f"Failed to parse consolidated JSON: {e}. Raw response: {res}. Retrying with strict format...")
            
            # Strict format repair prompt
            repair_prompt = f"""
            Tengo un JSON inválido generado para la evaluación de un curso. Corrige los problemas de sintaxis JSON (comas sobrantes, llaves faltantes, caracteres no escapados) y devuélvelo en formato JSON estrictamente válido. No agregues ninguna explicación adicional.
            
            JSON Inválido:
            {res}
            """
            try:
                repaired_res, _ = generate_completion(repair_prompt, "Eres un formateador estricto de JSON. Devuelves únicamente JSON puro.")
                if repaired_res is not None:
                    start_idx = repaired_res.find('{')
                    end_idx = repaired_res.rfind('}')
                    if start_idx != -1 and end_idx != -1:
                        clean_repaired = repaired_res[start_idx:end_idx+1].strip()
                        data = json.loads(clean_repaired)
                        
                        if not data.get("holistic_evaluation"):
                            data["holistic_evaluation"] = holistic_report or "Evaluación holística completada."
                        if not data.get("format_evaluation"):
                            data["format_evaluation"] = format_report or "Evaluación de formato completada."
                        if "overall_score" not in data:
                            data["overall_score"] = 75.0
                            
                        data["course_id"] = course_id
                        data["rubric_id"] = rubric_id
                        return data
            except Exception as e_repair:
                logger.error(f"Repair attempt failed: {e_repair}")
 
            # Fallback structure with regex score extraction if possible
            fallback_score = 50.0
            if res:
                score_match = re.search(r'"overall_score"\s*:\s*([0-9.]+)', res)
                fallback_score = float(score_match.group(1)) if score_match else 50.0
            
            return {
                "course_id": course_id,
                "rubric_id": rubric_id,
                "overall_score": fallback_score,
                "holistic_evaluation": holistic_report or "No disponible.",
                "format_evaluation": format_report or "No disponible.",
                "recommendations": [
                    {
                        "element": "General",
                        "type": "holistic",
                        "issue": "No se pudo estructurar el JSON automáticamente en la consolidación.",
                        "change": "Revisar los reportes individuales provistos."
                    }
                ]
            }

class MultiAgentCoordinator:
    """Coordinator that orchestrates the entire evaluation workflow."""
    
    def __init__(self):
        self.holistic_agent = PedagogicalHolisticAgent()
        self.format_agent = FormatStructureAgent()
        self.ontology_agent = OntologyAgent()
        self.synthesis_agent = SynthesisAgent()
        
    def evaluate_course(self, course_id: int, rubric_id: str, course_data: dict) -> dict:
        # 1. Fetch rubric from Neo4j
        rubric_data = None
        if self.ontology_agent.client and self.ontology_agent.client.initialized:
            rubric_data = self.ontology_agent.client.get_rubric(rubric_id)
            
        if not rubric_data:
            logger.warning(f"Rubric {rubric_id} not found in Neo4j. Using generic mock rubric.")
            rubric_data = {
                "id": rubric_id,
                "title": "Rúbrica Genérica de Calidad",
                "criteria": [
                    {"name": "Alineación Pedagógica", "description": "Grado en que las actividades evalúan los objetivos de aprendizaje.", "weight": 50},
                    {"name": "Claridad y Completitud", "description": "Las instrucciones de las actividades son claras y no tienen campos vacíos.", "weight": 50}
                ]
            }
            
        # 2. Sync Course structure to Neo4j database
        logger.info(f"Syncing course structure in Neo4j for course {course_id}...")
        course_title = course_data.get("fullname", f"Curso {course_id}")
        self.ontology_agent.sync_course_structure(course_id, course_title, course_data)
        
        # 3. Run RAG Criteria Analysis (Ontological Mapping)
        logger.info(f"Running semantic similarity mapping for course {course_id} and rubric {rubric_id}...")
        ontology_analysis = self.ontology_agent.get_rubric_criteria_analysis(course_id, rubric_id, rubric_data)

        # 4. Run agents evaluations
        logger.info("Running holistic pedagogical agent evaluation with RAG...")
        holistic_report = self.holistic_agent.evaluate(course_data, rubric_data, course_id)
        
        logger.info("Running technical format agent evaluation...")
        format_report = self.format_agent.evaluate(course_data)
        
        # 5. Consolidate results via Synthesis Agent
        logger.info("Running synthesis agent to consolidate reports...")
        evaluation_result = self.synthesis_agent.synthesize(
            course_id=course_id,
            rubric_id=rubric_id,
            holistic_report=holistic_report,
            format_report=format_report,
            ontology_analysis=ontology_analysis
        )
        
        # 6. Log evaluation results and recommendations in Neo4j graph database
        logger.info("Logging evaluation results and recommendations in Neo4j graph...")
        self.ontology_agent.log_evaluation_results(
            course_id=course_id,
            rubric_id=rubric_id,
            score=evaluation_result.get("overall_score", 0.0),
            recommendations=evaluation_result.get("recommendations", [])
        )
        
        return evaluation_result

