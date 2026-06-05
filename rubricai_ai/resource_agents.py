"""
RubricAI — Resource Agents Group
==================================
Agents that analyze course resources and materials:
  - DocumentAgent: Reads and analyzes PDFs, DOCX, PPTX via RAG
  - YouTubeAgent: Extracts transcripts from YouTube video URLs
  - URLResourceAgent: Analyzes external URL resources

These agents run sequentially within the "Resources" parallel branch.
"""

import json
import logging
import os
import re
from typing import List, Dict, Optional

from llm import generate_completion
from tracing import trace_agent, add_run_metadata, add_run_tags

logger = logging.getLogger(__name__)


# ---------------------------------------------------------------------------
# Helpers
# ---------------------------------------------------------------------------

def _extract_resources_by_type(course_data: dict, resource_types: list[str]) -> list[dict]:
    """Extract resources of specific types from course data sections."""
    results = []
    for section in course_data.get("sections", []):
        sec_name = section.get("name", "Sección sin nombre")
        for act in section.get("activities", []):
            if act.get("type", "").lower() in resource_types:
                act_copy = dict(act)
                act_copy["section_name"] = sec_name
                results.append(act_copy)
    return results


def _extract_youtube_id(url: str) -> Optional[str]:
    """Extract YouTube video ID from various URL formats."""
    if not url:
        return None
    patterns = [
        r'(?:youtube\.com/watch\?v=|youtu\.be/|youtube\.com/embed/)([a-zA-Z0-9_-]{11})',
        r'youtube\.com/v/([a-zA-Z0-9_-]{11})',
    ]
    for pattern in patterns:
        match = re.search(pattern, url)
        if match:
            return match.group(1)
    return None


def _get_youtube_transcript(video_id: str) -> Optional[str]:
    """Fetch YouTube transcript using youtube-transcript-api."""
    try:
        from youtube_transcript_api import YouTubeTranscriptApi

        # Try Spanish first, then English, then any available
        transcript_list = YouTubeTranscriptApi.list_transcripts(video_id)

        transcript = None
        # Try manually created transcripts first
        try:
            transcript = transcript_list.find_manually_created_transcript(['es', 'en'])
        except Exception:
            pass

        # Then auto-generated
        if transcript is None:
            try:
                transcript = transcript_list.find_generated_transcript(['es', 'en'])
            except Exception:
                pass

        if transcript is None:
            # Get any available transcript
            try:
                for t in transcript_list:
                    transcript = t
                    break
            except Exception:
                return None

        if transcript is None:
            return None

        entries = transcript.fetch()
        # Combine transcript entries into text
        text_parts = []
        for entry in entries:
            text = entry.get("text", "") if isinstance(entry, dict) else getattr(entry, "text", str(entry))
            if text:
                text_parts.append(text)

        full_text = " ".join(text_parts)
        return full_text if full_text.strip() else None

    except Exception as e:
        logger.warning(f"Could not fetch transcript for YouTube video {video_id}: {e}")
        return None

def _scrape_url(url: str) -> Optional[str]:
    """Fetch external web page content and clean it to extract main text."""
    if not url:
        return None
    try:
        from bs4 import BeautifulSoup
        import urllib.request
        
        # Configure headers to mimic a real browser to avoid blocks
        req = urllib.request.Request(
            url, 
            headers={'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/115.0.0.0 Safari/537.36'}
        )
        
        # Fetch content with 8s timeout to avoid blocking the agent graph
        with urllib.request.urlopen(req, timeout=8) as response:
            html = response.read()
            
        soup = BeautifulSoup(html, "lxml")
        
        # Decompose script, style, nav, footer, header to get clean body text
        for tag in soup(["script", "style", "nav", "footer", "header", "aside"]):
            tag.decompose()
            
        text = soup.get_text(separator="\n", strip=True)
        # Collapse multiple spaces and newlines
        text = re.sub(r'\n{3,}', '\n\n', text)
        text = re.sub(r' +', ' ', text)
        
        return text.strip() if text.strip() else None
    except Exception as e:
        logger.warning(f"Could not scrape URL {url}: {e}")
        return None


# ---------------------------------------------------------------------------
# 1. DocumentAgent
# ---------------------------------------------------------------------------

class DocumentAgent:
    """Reads and analyzes course documents (PDFs, DOCX, PPTX) using RAG index."""

    @trace_agent(name="document_agent")
    def analyze(self, course_data: dict, rubric_data: dict, course_id: int) -> str:
        # Collect all file-type resources
        resources = _extract_resources_by_type(course_data, ["resource", "folder", "book"])
        add_run_metadata({
            "n_resources": len(resources),
            "course_id": course_id,
        })

        # Use RAG to search for criterion-related content in the indexed documents
        rag_findings = []
        criteria = rubric_data.get("criteria", [])

        try:
            from rag.search import search_course
            for crit in criteria:
                name = crit.get("name", "")
                desc = crit.get("description", "")
                query = f"{name} {desc}".strip()
                if query:
                    try:
                        results = search_course(course_id, query, top_k=3)
                        if results:
                            rag_findings.append(f"**Criterio '{name}':**")
                            for res in results:
                                text = res.get("text", "").strip()[:300]
                                filename = res.get("filename", "")
                                sim = res.get("similarity", 0.0)
                                rag_findings.append(
                                    f"  - [{sim:.0%}] \"{text}...\" (Fuente: {filename})"
                                )
                    except Exception as e:
                        logger.warning(f"RAG search error for criterion {name}: {e}")
        except Exception as e:
            logger.warning(f"Could not perform RAG search: {e}")

        add_run_metadata({"n_rag_findings": len(rag_findings)})

        # Build resource list summary
        resource_list = []
        for r in resources:
            resource_list.append({
                "name": r.get("name", "Sin nombre"),
                "section": r.get("section_name", ""),
                "type": r.get("type", "resource"),
            })

        resource_str = json.dumps(resource_list, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)
        rag_context = "\n".join(rag_findings) if rag_findings else "(No se encontraron fragmentos relevantes en el índice RAG)"

        system_prompt = (
            "Eres el Agente de Análisis de Documentos. Tu tarea es evaluar la calidad, relevancia "
            "y cobertura temática de los recursos documentales del curso (PDFs, documentos Word, "
            "presentaciones) contrastándolos con la rúbrica de evaluación."
        )

        prompt = f"""
        Analiza los recursos documentales del curso y su cobertura temática respecto a la rúbrica:

        ### RECURSOS DEL CURSO:
        {resource_str}

        ### FRAGMENTOS EXTRAÍDOS DE LOS DOCUMENTOS (RAG - Búsqueda Semántica):
        {rag_context}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Evalúa si los documentos del curso cubren adecuadamente los criterios de la rúbrica.
        2. Para cada criterio de la rúbrica, indica qué documentos lo cubren y con qué nivel de profundidad.
        3. Identifica vacíos temáticos: ¿hay criterios de la rúbrica sin cobertura documental?
        4. Evalúa la diversidad de recursos (¿hay variedad de formatos?).
        5. Escribe un informe detallado en español con viñetas claras.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de documentos."


# ---------------------------------------------------------------------------
# 2. YouTubeAgent
# ---------------------------------------------------------------------------

class YouTubeAgent:
    """Detects YouTube URLs in course resources and analyzes video transcripts."""

    @trace_agent(name="youtube_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        # Find all URL-type resources and page content that contain YouTube links
        url_resources = _extract_resources_by_type(course_data, ["url"])

        # Also scan page intros for embedded YouTube links
        pages = _extract_resources_by_type(course_data, ["page"])

        youtube_videos = []

        # Check URL resources
        for r in url_resources:
            url = r.get("externalurl", "") or r.get("url", "")
            video_id = _extract_youtube_id(url)
            if video_id:
                youtube_videos.append({
                    "name": r.get("name", "Video sin nombre"),
                    "section": r.get("section_name", ""),
                    "url": url,
                    "video_id": video_id,
                })

        # Check page intros for embedded YouTube
        for p in pages:
            intro = p.get("intro", "") or p.get("content", "") or ""
            # Find all YouTube URLs in the HTML
            yt_urls = re.findall(
                r'(?:https?://)?(?:www\.)?(?:youtube\.com/watch\?v=|youtu\.be/)([a-zA-Z0-9_-]{11})',
                intro
            )
            for vid in yt_urls:
                youtube_videos.append({
                    "name": f"Video embebido en: {p.get('name', 'Página')}",
                    "section": p.get("section_name", ""),
                    "url": f"https://youtube.com/watch?v={vid}",
                    "video_id": vid,
                })

        add_run_metadata({"n_youtube_videos": len(youtube_videos)})

        if not youtube_videos:
            return "No se detectaron videos de YouTube en los recursos del curso. Esto no constituye una deficiencia si el diseño pedagógico del curso no los requiere."

        # Fetch transcripts for each video
        video_analyses = []
        for video in youtube_videos:
            transcript = _get_youtube_transcript(video["video_id"])
            video_analyses.append({
                "name": video["name"],
                "section": video["section"],
                "url": video["url"],
                "has_transcript": transcript is not None,
                "transcript_excerpt": transcript[:1000] if transcript else "(Transcripción no disponible)",
            })

        add_run_metadata({
            "videos_with_transcript": sum(1 for v in video_analyses if v["has_transcript"]),
        })

        videos_str = json.dumps(video_analyses, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente de Análisis de Videos de YouTube. Tu tarea es analizar los videos "
            "de YouTube incluidos en el curso, evaluando su contenido a partir de las "
            "transcripciones y su relevancia respecto a la rúbrica pedagógica."
        )

        prompt = f"""
        Analiza los siguientes videos de YouTube encontrados en el curso:

        ### VIDEOS DE YOUTUBE (CON TRANSCRIPCIONES):
        {videos_str}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Para cada video con transcripción disponible, evalúa:
           - Relevancia del contenido respecto a los criterios de la rúbrica
           - Temas principales cubiertos en el video
           - Calidad del contenido (¿es un recurso educativo de valor?)
        2. Para videos sin transcripción, indica la limitación.
        3. Evalúa la contribución global de los videos a la cobertura de la rúbrica.
        4. Escribe un informe detallado en español.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de videos de YouTube."


# ---------------------------------------------------------------------------
# 3. URLResourceAgent
# ---------------------------------------------------------------------------

class URLResourceAgent:
    """Analyzes external URL resources and their pedagogical relevance."""

    @trace_agent(name="url_resource_agent")
    def analyze(self, course_data: dict, rubric_data: dict) -> str:
        url_resources = _extract_resources_by_type(course_data, ["url"])

        # Filter out YouTube URLs (handled by YouTubeAgent)
        non_youtube_urls = []
        for r in url_resources:
            url = r.get("externalurl", "") or r.get("url", "")
            if not _extract_youtube_id(url):
                non_youtube_urls.append(r)

        add_run_metadata({"n_external_urls": len(non_youtube_urls)})

        if not non_youtube_urls:
            return "No se encontraron enlaces a recursos externos (no YouTube) en el curso. Esto no constituye una deficiencia si el diseño pedagógico del curso no los requiere."

        url_details = []
        for r in non_youtube_urls:
            url = r.get("externalurl", "") or r.get("url", "")
            intro = r.get("intro", "")

            # Fetch the actual content of the web page
            scraped_content = _scrape_url(url)
            scraped_excerpt = scraped_content[:2000] if scraped_content else "(No se pudo extraer el texto de la página o sitio bloqueado)"

            url_details.append({
                "name": r.get("name", "Sin nombre"),
                "section": r.get("section_name", ""),
                "url": url,
                "description": _clean_html_basic(intro)[:300] if intro else "(Sin descripción)",
                "scraped_text_excerpt": scraped_excerpt
            })

        url_str = json.dumps(url_details, indent=2, ensure_ascii=False)
        rubric_str = json.dumps(rubric_data, indent=2, ensure_ascii=False)

        system_prompt = (
            "Eres el Agente de Análisis de URLs Externas. Tu tarea es evaluar los enlaces "
            "a recursos externos del curso, analizando su relevancia pedagógica, diversidad "
            "de fuentes, alineación con la rúbrica y evaluando el contenido real extraído de dichos sitios web."
        )

        prompt = f"""
        Analiza los siguientes enlaces a recursos externos del curso (incluyendo el texto real extraído de ellos):

        ### ENLACES EXTERNOS (CON CONTENIDO WEB EXTRAÍDO):
        {url_str}

        ### RÚBRICA DE REFERENCIA:
        {rubric_str}

        ### TAREA:
        1. Evalúa cada enlace externo:
           - ¿El nombre, descripción y el CONTENIDO REAL del sitio web sugieren relevancia pedagógica?
           - ¿La URL apunta a una fuente confiable (universidades, repositorios educativos, etc.)?
           - Analiza si los temas tratados en el sitio web (texto extraído) están alineados con los criterios de la rúbrica.
        2. Evalúa la diversidad de fuentes externas.
        3. Identifica posibles problemas (enlaces sin descripción, fuentes poco confiables, contenido inadecuado o inaccesible).
        4. Evalúa la alineación global de los recursos externos con la rúbrica.
        5. Escribe un informe detallado en español con viñetas claras.
        """

        res, _ = generate_completion(prompt, system_prompt)
        return res or "No se pudo generar el análisis de URLs externas."


def _clean_html_basic(html_content: str) -> str:
    """Simple HTML tag stripping without BeautifulSoup dependency."""
    if not html_content:
        return ""
    try:
        from bs4 import BeautifulSoup
        soup = BeautifulSoup(html_content, "lxml")
        return soup.get_text(separator=" ", strip=True)
    except Exception:
        return re.sub(r'<[^>]+>', ' ', html_content).strip()
