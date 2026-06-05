"""
RubricAI — LangGraph Evaluation Graph
=======================================
Orchestrates the multi-agent evaluation workflow using LangGraph.
Two groups of agents run in parallel:
  - Activity Analysis Group (HTML, Quizzes, Assignments, Forums)
  - Resource Analysis Group (Documents/RAG, YouTube, External URLs)

After both groups finish (fan-in), results are consolidated by the
SynthesisAgent and persisted to Neo4j.

Architecture:
  START → classify → [analyze_activities || analyze_resources || ontology] → synthesis → persist → END
"""

import json
import logging
import operator
from typing import TypedDict, Annotated

from langgraph.graph import StateGraph, START, END

from activity_agents import HTMLContentAgent, QuizAgent, AssignmentAgent, ForumAgent
from resource_agents import DocumentAgent, YouTubeAgent, URLResourceAgent
from agents import OntologyAgent, SynthesisAgent, check_has_content
from tracing import trace_agent, add_run_metadata, add_run_tags

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# State Definition
# ---------------------------------------------------------------------------

class EvaluationState(TypedDict):
    """Full state for the evaluation graph."""
    # Inputs (set at invocation time)
    course_id: int
    rubric_id: str
    course_data: dict
    rubric_data: dict

    # Activity group results
    html_analysis: str
    quiz_analysis: str
    assignment_analysis: str
    forum_analysis: str

    # Resource group results
    document_analysis: str
    youtube_analysis: str
    url_analysis: str

    # Ontology/RAG results
    ontology_analysis: dict

    # Final consolidated result
    final_result: dict


# ---------------------------------------------------------------------------
# Node Implementations
# ---------------------------------------------------------------------------

@trace_agent(name="graph_node_classifier")
def classifier_node(state: EvaluationState) -> dict:
    """
    Initial node: validates course data and prepares rubric.
    Fetches rubric from Neo4j if available, otherwise uses a generic fallback.
    """
    course_data = state["course_data"]
    rubric_id = state["rubric_id"]
    course_id = state["course_id"]

    logger.info(f"[Classifier] Starting evaluation for course {course_id}, rubric {rubric_id}")

    # Fetch rubric from Neo4j
    rubric_data = None
    try:
        ontology = OntologyAgent()
        if ontology.client and ontology.client.initialized:
            rubric_data = ontology.client.get_rubric(rubric_id)
    except Exception as e:
        logger.warning(f"Could not fetch rubric from Neo4j: {e}")

    if not rubric_data:
        logger.warning(f"Rubric {rubric_id} not found in Neo4j. Using generic mock rubric.")
        add_run_tags(["mock-rubric"])
        rubric_data = {
            "id": rubric_id,
            "title": "Rúbrica Genérica de Calidad",
            "criteria": [
                {
                    "name": "Alineación Pedagógica",
                    "description": "Grado en que las actividades evalúan los objetivos de aprendizaje.",
                    "weight": 50,
                },
                {
                    "name": "Claridad y Completitud",
                    "description": "Las instrucciones de las actividades son claras y no tienen campos vacíos.",
                    "weight": 50,
                },
            ],
        }

    add_run_metadata({
        "course_id": course_id,
        "rubric_id": rubric_id,
        "rubric_title": rubric_data.get("title", ""),
        "n_criteria": len(rubric_data.get("criteria", [])),
    })

    return {"rubric_data": rubric_data}


@trace_agent(name="graph_node_activities")
def activity_analysis_node(state: EvaluationState) -> dict:
    """
    Runs all activity agents sequentially within this node.
    This entire node runs in PARALLEL with resource_analysis_node.
    """
    course_data = state["course_data"]
    rubric_data = state["rubric_data"]

    logger.info("[Activities Group] Starting activity analysis...")

    # Check for empty course
    if not check_has_content(course_data):
        empty_msg = (
            "CRITICAL WARNING: El curso no contiene actividades pedagógicas. "
            "No hay contenido interactivo para analizar."
        )
        return {
            "html_analysis": empty_msg,
            "quiz_analysis": empty_msg,
            "assignment_analysis": empty_msg,
            "forum_analysis": empty_msg,
        }

    # 1. HTML Content Analysis
    logger.info("[Activities Group] Running HTMLContentAgent...")
    html_agent = HTMLContentAgent()
    html_result = html_agent.analyze(course_data, rubric_data)

    # 2. Quiz Analysis
    logger.info("[Activities Group] Running QuizAgent...")
    quiz_agent = QuizAgent()
    quiz_result = quiz_agent.analyze(course_data, rubric_data)

    # 3. Assignment Analysis
    logger.info("[Activities Group] Running AssignmentAgent...")
    assign_agent = AssignmentAgent()
    assign_result = assign_agent.analyze(course_data, rubric_data)

    # 4. Forum Analysis
    logger.info("[Activities Group] Running ForumAgent...")
    forum_agent = ForumAgent()
    forum_result = forum_agent.analyze(course_data, rubric_data)

    logger.info("[Activities Group] All activity agents completed.")

    return {
        "html_analysis": html_result,
        "quiz_analysis": quiz_result,
        "assignment_analysis": assign_result,
        "forum_analysis": forum_result,
    }


@trace_agent(name="graph_node_resources")
def resource_analysis_node(state: EvaluationState) -> dict:
    """
    Runs all resource agents sequentially within this node.
    This entire node runs in PARALLEL with activity_analysis_node.
    """
    course_data = state["course_data"]
    rubric_data = state["rubric_data"]
    course_id = state["course_id"]

    logger.info("[Resources Group] Starting resource analysis...")

    # 1. Document Analysis (RAG-based)
    logger.info("[Resources Group] Running DocumentAgent...")
    doc_agent = DocumentAgent()
    doc_result = doc_agent.analyze(course_data, rubric_data, course_id)

    # 2. YouTube Video Analysis
    logger.info("[Resources Group] Running YouTubeAgent...")
    yt_agent = YouTubeAgent()
    yt_result = yt_agent.analyze(course_data, rubric_data)

    # 3. External URL Analysis
    logger.info("[Resources Group] Running URLResourceAgent...")
    url_agent = URLResourceAgent()
    url_result = url_agent.analyze(course_data, rubric_data)

    logger.info("[Resources Group] All resource agents completed.")

    return {
        "document_analysis": doc_result,
        "youtube_analysis": yt_result,
        "url_analysis": url_result,
    }


@trace_agent(name="graph_node_ontology")
def ontology_node(state: EvaluationState) -> dict:
    """
    Syncs course structure to Neo4j and runs semantic criterion mapping.
    Runs in PARALLEL with both analysis groups.
    """
    course_data = state["course_data"]
    rubric_data = state["rubric_data"]
    course_id = state["course_id"]
    rubric_id = state["rubric_id"]

    logger.info(f"[Ontology] Syncing course {course_id} structure to Neo4j...")

    ontology_agent = OntologyAgent()

    # 1. Sync course structure
    course_title = course_data.get("fullname", f"Curso {course_id}")
    ontology_agent.sync_course_structure(course_id, course_title, course_data)

    # 2. Run semantic criteria analysis
    logger.info(f"[Ontology] Running semantic criteria mapping for rubric {rubric_id}...")
    ontology_analysis = ontology_agent.get_rubric_criteria_analysis(
        course_id, rubric_id, rubric_data
    )

    logger.info("[Ontology] Ontology sync and criteria mapping completed.")

    return {"ontology_analysis": ontology_analysis}


@trace_agent(name="graph_node_synthesis")
def synthesis_node(state: EvaluationState) -> dict:
    """
    Consolidates all agent reports into a final structured evaluation.
    Runs AFTER all parallel branches complete (fan-in).
    """
    course_id = state["course_id"]
    rubric_id = state["rubric_id"]

    logger.info("[Synthesis] Consolidating all agent reports...")

    # Build comprehensive activity report
    activity_report = f"""
## ANÁLISIS DE CONTENIDO HTML DE ACTIVIDADES:
{state.get('html_analysis', 'No disponible')}

## ANÁLISIS DE CUESTIONARIOS:
{state.get('quiz_analysis', 'No disponible')}

## ANÁLISIS DE TAREAS:
{state.get('assignment_analysis', 'No disponible')}

## ANÁLISIS DE FOROS:
{state.get('forum_analysis', 'No disponible')}
    """.strip()

    # Build comprehensive resource report
    resource_report = f"""
## ANÁLISIS DE DOCUMENTOS Y MATERIALES (RAG):
{state.get('document_analysis', 'No disponible')}

## ANÁLISIS DE VIDEOS DE YOUTUBE:
{state.get('youtube_analysis', 'No disponible')}

## ANÁLISIS DE RECURSOS EXTERNOS (URLs):
{state.get('url_analysis', 'No disponible')}
    """.strip()

    # Use existing SynthesisAgent with the combined reports
    synthesis_agent = SynthesisAgent()
    result = synthesis_agent.synthesize(
        course_id=course_id,
        rubric_id=rubric_id,
        holistic_report=activity_report,
        format_report=resource_report,
        ontology_analysis=state.get("ontology_analysis"),
        course_data=state.get("course_data"),
    )

    logger.info(f"[Synthesis] Final score: {result.get('overall_score', 'N/A')}")

    return {"final_result": result}


@trace_agent(name="graph_node_persist")
def persist_node(state: EvaluationState) -> dict:
    """
    Persists evaluation results and recommendations to Neo4j.
    """
    course_id = state["course_id"]
    rubric_id = state["rubric_id"]
    final_result = state.get("final_result", {})

    logger.info(f"[Persist] Logging results to Neo4j for course {course_id}...")

    ontology_agent = OntologyAgent()
    ontology_agent.log_evaluation_results(
        course_id=course_id,
        rubric_id=rubric_id,
        score=final_result.get("overall_score", 0.0),
        recommendations=final_result.get("recommendations", []),
    )

    logger.info("[Persist] Results persisted to Neo4j.")
    return {}


# ---------------------------------------------------------------------------
# Graph Construction
# ---------------------------------------------------------------------------

def build_evaluation_graph() -> StateGraph:
    """
    Builds and compiles the LangGraph evaluation graph with sequential execution
    to prevent concurrent API rate limits (429/503) on the Gemini Free Tier.

    Graph topology:
      START → classify → ontology → analyze_activities → analyze_resources → synthesis → persist → END
    """
    graph = StateGraph(EvaluationState)

    # Add all nodes
    graph.add_node("classify", classifier_node)
    graph.add_node("analyze_activities", activity_analysis_node)
    graph.add_node("analyze_resources", resource_analysis_node)
    graph.add_node("ontology", ontology_node)
    graph.add_node("synthesis", synthesis_node)
    graph.add_node("persist", persist_node)

    # Sequential edges to guarantee single-threaded LLM requests
    graph.add_edge(START, "classify")
    graph.add_edge("classify", "ontology")
    graph.add_edge("ontology", "analyze_activities")
    graph.add_edge("analyze_activities", "analyze_resources")
    graph.add_edge("analyze_resources", "synthesis")
    graph.add_edge("synthesis", "persist")
    graph.add_edge("persist", END)

    return graph.compile()


# Singleton compiled graph
evaluation_graph = build_evaluation_graph()
