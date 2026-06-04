"""
RubricAI — Activity Agents Group
=================================
Agents that analyze interactive Moodle activities:
  - HTMLContentAgent: Parses HTML intros/descriptions
  - QuizAgent: Analyzes quizzes, questions, and settings
  - AssignmentAgent: Analyzes assignments (tareas)
  - ForumAgent: Analyzes forums and discussions

These agents run sequentially within the "Activities" parallel branch.
"""

import json
import logging
import re
from typing import List, Dict, Optional

from bs4 import BeautifulSoup

from llm import generate_completion
from tracing import trace_agent, add_run_metadata, add_run_tags

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _extract_activities_by_type(course_data: dict, activity_types: list[str]) -> list[dict]:
    """Extract activities of specific types from course data sections."""
    results = []
    for section in course_data.get("sections", []):
        sec_name = section.get("name", "Sección sin nombre")
        for act in section.get("activities", []):
            if act.get("type", "").lower() in activity_types:
                act_copy = dict(act)
                act_copy["section_name"] = sec_name
                results.append(act_copy)
    return results


def _get_all_activities(course_data: dict) -> list[dict]:
    """Extract ALL activities (non-resource types) from course data."""
    resource_types = {"resource", "folder", "page", "url", "book", "label"}
    results = []
    for section in course_data.get("sections", []):
        sec_name = section.get("name", "Sección sin nombre")
        for act in section.get("activities", []):
            act_type = act.get("type", "").lower()
            if act_type not in resource_types:
                act_copy = dict(act)
                act_copy["section_name"] = sec_name
                results.append(act_copy)
    return results


def _clean_html(html_content: str) -> str:
    """Parse HTML and extract clean text using BeautifulSoup."""
    if not html_content:
        return ""
    try:
        soup = BeautifulSoup(html_content, "lxml")
        # Remove script and style elements
        for tag in soup(["script", "style"]):
            tag.decompose()
        text = soup.get_text(separator="\n", strip=True)
        # Collapse multiple newlines
        text = re.sub(r'\n{3,}', '\n\n', text)
        return text.strip()
    except Exception:
        # Fallback: strip HTML tags with regex
        return re.sub(r'<[^>]+>', ' ', html_content).strip()


def _extract_html_structure(html_content: str) -> dict:
    """Analyze HTML structure: images, links, tables, headings."""
    if not html_content:
        return {"images": 0, "links": 0, "tables": 0, "headings": 0, "has_content": False}
    try:
        soup = BeautifulSoup(html_content, "lxml")
        return {
            "images": len(soup.find_all("img")),
            "links": len(soup.find_all("a", href=True)),
            "tables": len(soup.find_all("table")),
            "headings": len(soup.find_all(re.compile(r'^h[1-6]$'))),
            "has_content": bool(soup.get_text(strip=True)),
        }
    except Exception:
        return {"images": 0, "links": 0, "tables": 0, "headings": 0, "has_content": bool(html_content.strip())}


# ---------------------------------------------------------------------------
# 1. HTMLContentAgent
# ---------------------------------------------------------------------------

class HTMLContentAgent:
    """Parses and analyzes the HTML content (intro/description) of all activities."""

    @trace_agent(name="html_content_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        all_activities = _get_all_activities(course_data)
        add_run_metadata({
            "n_activities": len(all_activities),
        })

        if not all_activities:
            return "No se encontraron actividades en el curso para analizar su contenido HTML."

        # Parse all HTML intros and build a structured summary
        parsed_entries = []
        total_images = 0
        total_links = 0
        empty_intros = 0

        for act in all_activities:
            intro_html = act.get("intro", "") or act.get("description", "") or ""
            clean_text = _clean_html(intro_html)
            structure = _extract_html_structure(intro_html)

            total_images += structure["images"]
            total_links += structure["links"]

            if not clean_text or len(clean_text) < 10:
                empty_intros += 1

            parsed_entries.append({
                "name": act.get("name", "Sin nombre"),
                "type": act.get("type", "unknown"),
                "section": act.get("section_name", ""),
                "intro_text": clean_text[:500] if clean_text else "(vacío)",
                "images": structure["images"],
                "links": structure["links"],
                "tables": structure["tables"],
            })

        add_run_metadata({
            "total_images": total_images,
            "total_links": total_links,
            "empty_intros": empty_intros,
        })

        # Build compact summary for LLM
        activities_summary = json.dumps(parsed_entries, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente de Análisis de Contenido HTML. Tu tarea es analizar la calidad descriptiva "
            "de las introducciones y descripciones HTML de las actividades de un aula virtual Moodle."
        )

        prompt = f"""
        Analiza las descripciones (intros HTML ya parseadas) de las siguientes actividades del curso:

        ### ACTIVIDADES PARSEADAS (texto limpio extraído del HTML):
        {activities_summary}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### ESTADÍSTICAS GENERALES:
        - Total de actividades analizadas: {len(parsed_entries)}
        - Actividades con descripción vacía o insuficiente: {empty_intros}
        - Total de imágenes embebidas: {total_images}
        - Total de enlaces: {total_links}

        ### TAREA:
        1. Evalúa la calidad descriptiva de cada actividad: ¿Las instrucciones son claras? ¿Son suficientes para que el alumno sepa qué hacer?
        2. Identifica actividades con descripciones vacías o insuficientes.
        3. Evalúa si el contenido descriptivo está alineado con los criterios de la rúbrica.
        4. Analiza el uso de elementos multimedia (imágenes, tablas, enlaces) en las descripciones.
        5. Escribe un informe detallado en español con viñetas claras.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de contenido HTML."


# ---------------------------------------------------------------------------
# 2. QuizAgent
# ---------------------------------------------------------------------------

class QuizAgent:
    """Analyzes quiz configurations, question types, and pedagogical alignment."""

    @trace_agent(name="quiz_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        quizzes = _extract_activities_by_type(course_data, ["quiz"])
        add_run_metadata({"n_quizzes": len(quizzes)})

        if not quizzes:
            return "No se encontraron cuestionarios (quizzes) en el curso."

        # Extract settings details for each quiz
        quiz_details = []
        for q in quizzes:
            settings = q.get("settings", {})
            if not isinstance(settings, dict):
                settings = {}

            questions = q.get("questions", [])
            question_types = {}
            for qst in questions:
                qtype = qst.get("qtype", "unknown")
                question_types[qtype] = question_types.get(qtype, 0) + 1

            quiz_details.append({
                "name": q.get("name", "Sin nombre"),
                "section": q.get("section_name", ""),
                "intro": _clean_html(q.get("intro", ""))[:300],
                "n_questions": len(questions),
                "question_types": question_types,
                "time_limit": settings.get("timelimit", 0),
                "attempts": settings.get("attempts", 0),
                "grade_method": settings.get("grademethod", ""),
                "shuffle_questions": settings.get("shufflequestions", 0),
                "max_grade": q.get("grade", settings.get("grade", 0)),
                "open_date": settings.get("timeopen", 0),
                "close_date": settings.get("timeclose", 0),
            })

        quiz_str = json.dumps(quiz_details, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente Especialista en Cuestionarios. Tu tarea es analizar los cuestionarios "
            "(quizzes) de Moodle evaluando su configuración técnica, variedad de preguntas, "
            "nivel cognitivo según la Taxonomía de Bloom, y alineación con la rúbrica."
        )

        prompt = f"""
        Analiza los siguientes cuestionarios del curso Moodle:

        ### CUESTIONARIOS:
        {quiz_str}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Para cada cuestionario, evalúa:
           - Variedad de tipos de preguntas (opción múltiple, verdadero/falso, ensayo, etc.)
           - Nivel cognitivo implícito según la Taxonomía de Bloom
           - Configuración técnica: ¿tiene límite de tiempo?, ¿cuántos intentos?, ¿método de calificación?
           - Coherencia de fechas de apertura/cierre
        2. Evalúa si los cuestionarios están alineados con los criterios de la rúbrica.
        3. Identifica debilidades (ej: solo preguntas de memorización, sin límite de intentos, etc.)
        4. Escribe un informe detallado en español.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de cuestionarios."


# ---------------------------------------------------------------------------
# 3. AssignmentAgent
# ---------------------------------------------------------------------------

class AssignmentAgent:
    """Analyzes assignment (tarea) configurations and pedagogical design."""

    @trace_agent(name="assignment_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        assignments = _extract_activities_by_type(course_data, ["assign"])
        add_run_metadata({"n_assignments": len(assignments)})

        if not assignments:
            return "No se encontraron tareas (assignments) en el curso."

        assignment_details = []
        for a in assignments:
            settings = a.get("settings", {})
            if not isinstance(settings, dict):
                settings = {}

            assignment_details.append({
                "name": a.get("name", "Sin nombre"),
                "section": a.get("section_name", ""),
                "intro": _clean_html(a.get("intro", ""))[:400],
                "duedate": settings.get("duedate", 0),
                "cutoffdate": settings.get("cutoffdate", 0),
                "max_grade": a.get("grade", settings.get("grade", 0)),
                "submission_types": {
                    "online_text": settings.get("assignsubmission_onlinetext_enabled", 0),
                    "file": settings.get("assignsubmission_file_enabled", 0),
                },
                "max_files": settings.get("maxfilesubmissions", 0),
                "max_file_size": settings.get("maxsubmissionsizebytes", 0),
                "team_submission": settings.get("teamsubmission", 0),
                "rubric_configured": bool(a.get("advancedgradingmethod", "")),
            })

        assign_str = json.dumps(assignment_details, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente Especialista en Tareas. Tu tarea es analizar las tareas (assignments) "
            "de Moodle evaluando sus instrucciones, configuración de entregas, fechas, y alineación "
            "con la rúbrica pedagógica."
        )

        prompt = f"""
        Analiza las siguientes tareas del curso Moodle:

        ### TAREAS (ASSIGNMENTS):
        {assign_str}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Para cada tarea, evalúa:
           - Claridad y completitud de las instrucciones (intro)
           - Coherencia de fechas de entrega (¿están en el futuro? ¿hay fecha de corte?)
           - Configuración de envío (¿acepta archivos, texto online, o ambos?)
           - Si tiene rúbrica de calificación avanzada configurada
        2. Evalúa la alineación de cada tarea con los criterios de la rúbrica pedagógica.
        3. Identifica tareas con instrucciones vagas, sin fechas, o mal configuradas.
        4. Escribe un informe detallado en español.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de tareas."


# ---------------------------------------------------------------------------
# 4. ForumAgent
# ---------------------------------------------------------------------------

class ForumAgent:
    """Analyzes forum configurations and pedagogical value."""

    @trace_agent(name="forum_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        forums = _extract_activities_by_type(course_data, ["forum"])

        # Filter out default announcement forums
        filtered_forums = []
        for f in forums:
            name_lower = f.get("name", "").lower()
            if name_lower in ["avisos", "announcements", "novedades", "news forum"]:
                continue
            filtered_forums.append(f)

        add_run_metadata({
            "n_forums_total": len(forums),
            "n_forums_filtered": len(filtered_forums),
        })

        if not filtered_forums:
            return "No se encontraron foros pedagógicos en el curso (se excluyeron los foros de avisos/novedades)."

        forum_details = []
        for f in filtered_forums:
            settings = f.get("settings", {})
            if not isinstance(settings, dict):
                settings = {}

            forum_details.append({
                "name": f.get("name", "Sin nombre"),
                "section": f.get("section_name", ""),
                "type": settings.get("type", "general"),
                "intro": _clean_html(f.get("intro", ""))[:400],
                "max_grade": f.get("grade", settings.get("grade", 0)),
                "assessed": settings.get("assessed", 0),
                "due_date": settings.get("duedate", 0),
                "n_discussions": f.get("numdiscussions", 0),
            })

        forum_str = json.dumps(forum_details, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente Especialista en Foros. Tu tarea es analizar los foros de discusión "
            "de Moodle evaluando su diseño pedagógico, si promueven pensamiento crítico, "
            "y su alineación con la rúbrica."
        )

        prompt = f"""
        Analiza los siguientes foros de discusión del curso Moodle:

        ### FOROS:
        {forum_str}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Para cada foro, evalúa:
           - Tipo de foro (debate general, Q&A, blog, etc.) y si es adecuado para los objetivos
           - Claridad de las instrucciones de participación
           - Si las consignas promueven pensamiento crítico y no solo respuestas superficiales
           - Si tiene calificación configurada (assessed)
        2. Evalúa la alineación de los foros con los criterios de la rúbrica.
        3. Identifica oportunidades para mejorar la interacción y el debate.
        4. Escribe un informe detallado en español.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de foros."
