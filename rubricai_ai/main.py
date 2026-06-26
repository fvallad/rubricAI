from fastapi import FastAPI, BackgroundTasks, Request, UploadFile
from fastapi.middleware.cors import CORSMiddleware
from pydantic import BaseModel
import shutil
import logging
import json
import os

from rag.store import get_index_path, get_metadata_path
from schemas import (
    GenerateRequest, 
    SuggestionsResponse, 
    InstrumentDesign, 
    RubricDesign,
    CorrectionDesign,
    FeedbackClassification,
    EvaluateRequest
)
from rag.utils import get_instrument_list
from tracing import (
    trace_moodle_request,
    trace_rag,
    add_run_metadata,
    add_run_tags,
    create_tracing_middleware,
)
import json
import re


app = FastAPI(title="RubricAI AI Service")
logging.basicConfig(level=logging.INFO)

# Enable CORS so Astro frontend (port 4321) and Moodle (port 8080) can call the API
app.add_middleware(
    CORSMiddleware,
    allow_origins=["*"],
    allow_credentials=True,
    allow_methods=["*"],
    allow_headers=["*"],
)

# Add LangSmith tracing middleware for incoming HTTP requests
try:
    TracingMiddleware = create_tracing_middleware()
    app.add_middleware(TracingMiddleware)
    logging.info("LangSmith HTTP tracing middleware enabled.")
except Exception as e:
    logging.warning(f"Could not add LangSmith tracing middleware: {e}")

@app.on_event("startup")
async def startup_event():
    # Warm up the embedding model
    from rag.utils import get_model
    logging.info("Warming up embedding model (FastEmbed)...")
    get_model()
    
    # Warm up the guidelines index (course 0)
    from rag.store import load_index
    try:
        logging.info("Warming up Guidelines index (course 0)...")
        load_index(0)
        logging.info("Guidelines ready.")
    except Exception as e:
        logging.warning(f"Guidelines index not found at startup: {e}")
    
    # Warm up Neo4j database connection & initialization
    from neo4j_client import get_neo4j_client
    try:
        logging.info("Warming up Neo4j database connection & seeding...")
        get_neo4j_client()
        logging.info("Neo4j client initialized.")
    except Exception as e:
        logging.warning(f"Could not connect to Neo4j at startup: {e}")
    
    logging.info("Service fully ready.")

class SyncRequest(BaseModel):
    course: dict = {}
    files: list = []

# Thread-safe (mostly) dictionary to track background task progress
INGESTION_PROGRESS = {}

# Tracks active evaluate calls: {course_id: {"started_at": float}}
EVALUATION_IN_PROGRESS = {}
EVALUATION_TIMEOUT = 600  # 10 minutes

class IngestRequest(BaseModel):
    course_id: int
    selected_files: list[str] = []

class SearchRequest(BaseModel):
    course_id: int
    query: str

# GenerateRequest moved to schemas.py

@app.get("/")
async def root():
    return {"message": "RubricAI AI Service is running"}


@app.post("/sync")
@trace_moodle_request(name="moodle_sync")
async def sync_course(request: SyncRequest):
    add_run_metadata({"n_files": len(request.files)})
    verified = []
    for f in request.files:
        path = f.get("localpath")
        if path and os.path.exists(path):
            verified.append(f.get("filename"))
    add_run_metadata({"n_verified": len(verified)})
    return {"status": "success", "files_verified": len(verified)}


@app.post("/ingest")
@trace_moodle_request(name="moodle_ingest")
async def ingest_course(request: Request, background_tasks: BackgroundTasks):
    """
    Trigger ingestion for a course folder in the background.
    """
    form_data = await request.form()
    course_id_str = form_data.get("course_id")
    
    if not course_id_str:
        return {"status": "error", "message": "course_id is required"}
        
    course_id = int(course_id_str)
    add_run_metadata({"course_id": course_id})

    from rag.pipeline import run_ingestion
    from rag.store import get_course_dir
    
    course_dir = get_course_dir(course_id)
    if os.path.exists(course_dir):
        shutil.rmtree(course_dir)
    os.makedirs(course_dir, exist_ok=True)
    
    selected_files = []
    for key, value in form_data.multi_items():
        # Check if it's a file by looking for the filename attribute (duck typing)
        filename = getattr(value, 'filename', None)
        if filename:
            rel_path = filename.lstrip('/')
            selected_files.append(rel_path)
            
            dest_path = os.path.join(course_dir, rel_path)
            os.makedirs(os.path.dirname(dest_path), exist_ok=True)
            
            with open(dest_path, "wb") as buffer:
                shutil.copyfileobj(value.file, buffer)
            logging.info(f"Saved uploaded file: {rel_path} to {dest_path}")
    
    if not selected_files:
        logging.warning(f"No files were received in the multipart request for course {course_id}")
    else:
        logging.info(f"Received and saved {len(selected_files)} files for course {course_id}")
    
    add_run_metadata({"n_files": len(selected_files), "files": selected_files[:10]})

    # Initialize progress
    INGESTION_PROGRESS[course_id] = {
        "progress": 0, 
        "message": "Iniciando...",
        "selected_files": selected_files
    }
    
    def progress_callback(val, msg):
        INGESTION_PROGRESS[course_id] = {
            "progress": val, 
            "message": msg,
            "selected_files": selected_files
        }

    background_tasks.add_task(run_ingestion, course_id, progress_callback=progress_callback)
    return {
        "status": "started",
        "message": f"Ingestion triggered in background for course {course_id}"
    }

@app.post("/search")
@trace_moodle_request(name="moodle_search")
async def search_endpoint(request: SearchRequest):
    """
    Query a course embedding for RAG.
    """
    add_run_metadata({"course_id": request.course_id, "query_length": len(request.query)})

    if not request.course_id or not request.query:
        return {"status": "error", "message": "course_id and query required"}

    from rag.search import search_course
    try:
        results = search_course(request.course_id, request.query)
        add_run_metadata({"n_results": len(results)})
        return {"status": "success", "results": results}
    except Exception as e:
        logging.exception("Error searching course")
        return {"status": "error", "message": str(e)}

@app.get("/instruments")
@trace_moodle_request(name="moodle_instruments")
async def list_instruments():
    """
    Returns the full list of instruments from the master document.
    """
    try:
        instruments = get_instrument_list()
        add_run_metadata({"n_instruments": len(instruments)})
        return {"status": "success", "instruments": instruments}
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.post("/preview")
@trace_moodle_request(name="moodle_preview")
async def preview_endpoint(request: GenerateRequest):
    """
    Returns the prompts that would be sent to the LLM.
    """
    add_run_metadata({"course_id": request.course_id, "step": request.step})
    try:
        prompt, system_prompt, _ = await _prepare_prompt_data(request)
        if not prompt:
            return {"status": "error", "message": f"Step {request.step} not supported for preview"}
        
        if isinstance(prompt, dict) and prompt.get("status") == "error":
            return prompt

        return {
            "status": "success",
            "system_prompt": system_prompt,
            "user_prompt": prompt
        }
    except Exception as e:
        logging.exception("Error in /preview")
        return {"status": "error", "message": str(e)}


async def _prepare_prompt_data(request: GenerateRequest):
    """
    Internal helper to search RAG/Guidelines and select the correct prompt templates.
    """
    def _build_structured_materials(course_id, obj_list, all_vectors):
        from rag.search import search_course_by_vector
        materials_parts = []
        seen_texts = set()
        vector_idx = 1
        for i, obj in enumerate(obj_list):
            obj_text = obj.get('text', '')
            bloom = obj.get('bloom', 'GENERAL').upper()
            fragments = []
            if obj_text and vector_idx < len(all_vectors):
                results = search_course_by_vector(course_id, all_vectors[vector_idx], top_k=2)
                vector_idx += 1
                for res in results:
                    txt = " ".join(res['text'].split())
                    if txt and txt not in seen_texts:
                        fragments.append(f"            - Extracto: \"{txt}\"\n            - Referencia: {res.get('filename', 'Archivo desconocido')}")
                        seen_texts.add(txt)
            obj_header = f"- Objetivo {i+1}:\n"
            obj_header += f"        - Nivel taxonómico: {bloom}\n"
            obj_header += f"        - Declaración objetivo: {obj_text}\n"
            obj_header += f"        - Extracto de los materiales:\n"
            if fragments:
                materials_parts.append(obj_header + "\n".join(fragments))
            else:
                materials_parts.append(obj_header + "            (No se encontraron materiales específicos en los recursos seleccionados)")
        return "\n\n".join(materials_parts) if materials_parts else "No se proporcionaron objetivos específicos."

    try:
        from llm import (
            classify_feedback,
            get_suggestions_prompt, 
            get_design_prompt, 
            get_rubric_prompt,
            get_correction_prompt
        )
        from rag.search import search_course, search_guidelines

        if request.feedback and request.feedback.strip() and request.feedback.strip().lower() not in ('undefined', 'null'):
            raw_feedback = classify_feedback(request.feedback.strip())
            if raw_feedback:
                try:
                    # Clean JSON in case LLM added markdown wrappers
                    json_fb = re.sub(r'^```json|```$', '', raw_feedback, flags=re.MULTILINE).strip()
                    fb_data = FeedbackClassification.model_validate_json(json_fb)
                    if not fb_data.is_valid:
                        return {
                            "status": "error", 
                            "message": "Solicitud de ajuste no válida",
                            "reason": fb_data.reason or "El comentario no parece ser una instrucción pedagógica válida."
                        }, None, None
                except Exception as e:
                    logging.warning(f"Failed to parse feedback classification: {e}")
            else:
                logging.warning("Classification failed (LLM returned None)")

        # 1. Collect all queries that need embedding for batch processing
        queries = []
        # Main query for guidelines and fallback RAG
        main_query = request.objective or "evaluación educativa"
        queries.append(main_query)
        
        # Specific objective queries
        obj_list = []
        if request.objective_json:
            try:
                obj_list = json.loads(request.objective_json)
                for obj in obj_list:
                    txt = obj.get('text', '')
                    if txt:
                        queries.append(txt)
            except:
                pass

        # 2. Embed ALL queries in a single batch
        from rag.utils import embed_text_chunks
        all_vectors = embed_text_chunks(queries, prefix="query: ")
        
        # 3. Perform searches using pre-computed vectors
        from rag.search import search_course_by_vector
        
        # Guidelines (only if NOT step 4)
        guidelines_text = ""
        if request.step != 4:
            g_results = search_course_by_vector(0, all_vectors[0], top_k=3)
            guidelines_text = "DIRECTRICES PEDAGÓGICAS (REGLAS GLOBALES):\n" + "\n".join([f"- {res['text']}" for res in g_results[:3]]) + "\n\n"

        # Unified Structured RAG context calculation
        materials_str = _build_structured_materials(request.course_id, obj_list, all_vectors)
        full_context = f"{guidelines_text}CONTEXTO EXTRAÍDO DE MATERIALES DEL CURSO:\n{materials_str}"

        # 3. Build Dimensions String (D1, D3, D4)
        # Use granular fields if available, otherwise fallback to the concatenated string
        dim_parts = []
        if request.d1_content: dim_parts.append(f"Contenido: {request.d1_content}")
        if request.d3_function: dim_parts.append(f"Función: {request.d3_function}")
        if request.d4_modality: dim_parts.append(f"Modalidad: {request.d4_modality}")
        
        dimensions = "\n".join(dim_parts) if dim_parts else request.dimensions

        # 4. Build prompt based on step
        prompt = ""
        course_name = request.course_title or "el curso"
        system_prompt = f"Eres un experto en pedagogía y diseño de instrumentos de evaluación y de apoyo a la corrección en el curso {course_name}."
        schema = None
        
        if request.step == 4:
            # Include the master instrument list in the context for Step 4
            master_instruments = get_instrument_list()
            instr_list_str = "\n".join([f"- {instr['name']}: {instr['definition'][:200]}..." for instr in master_instruments])
            extended_context = f"{full_context}\n\nLISTA DE INSTRUMENTOS DISPONIBLES (ELIGE SOLO DE AQUÍ):\n{instr_list_str}"
            
            prompt = get_suggestions_prompt(request.summary, request.objective, dimensions, extended_context, request.feedback)
            schema = SuggestionsResponse
        elif request.step == 5:
            # 1. Load Instrument Document & Resolve ID
            instrument_desc = ""
            chosen_id = None
            try:
                inst_list = get_instrument_list()
                target_raw = (request.chosen_instrument or "").strip().lower()
                
                for inst in inst_list:
                    # Check ID match first (future-proof) or Name match (legacy support)
                    if inst.get('id') == request.chosen_instrument or inst.get('name', '').strip().lower() == target_raw:
                        instrument_desc = inst.get('definition', '')
                        chosen_id = inst.get('id')
                        break
                
                if not chosen_id:
                    logging.warning(f"Instrument '{request.chosen_instrument}' not found in master catalog.")
            except Exception as e:
                logging.error(f"Error loading instrument context: {e}")

            # 4. Load & Filter Allowed Question Types
            valid_types = []
            try:
                qt_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/tipos_de_preguntas.json")
                if os.path.exists(qt_path):
                    with open(qt_path, "r", encoding="utf-8") as f:
                        valid_types = json.load(f)
                
                encaje_path = os.path.join(os.path.dirname(__file__), "rag/documentos_maestros/encaje_instrumentos_items.json")
                if os.path.exists(encaje_path):
                    with open(encaje_path, "r", encoding="utf-8") as f:
                        encaje_map = json.load(f)
                    
                    # If we have a valid instrument ID, filter types by their IDs
                    if chosen_id and chosen_id in encaje_map:
                        allowed_type_ids = encaje_map[chosen_id]
                        valid_types = [
                            t for t in valid_types 
                            if t.get('id') in allowed_type_ids
                        ]
                    else:
                        logging.warning(f"No specific question types mapped for instrument ID: '{chosen_id}'")
            except Exception as e:
                logging.error(f"Error handling question types: {e}")

            prompt = get_design_prompt(
                chosen_instrument=request.chosen_instrument,
                instrument_desc=instrument_desc,
                structured_materials=materials_str,
                num_items=request.num_items,
                valid_types=valid_types,
                feedback=request.feedback,
                current_design=request.instrument_content
            )
            schema = InstrumentDesign
        elif request.step == 6:
            prompt = get_rubric_prompt(request.instrument_content, request.objective, full_context, request.feedback)
            schema = RubricDesign
        elif request.step == 9:
            prompt = get_correction_prompt(
                correction_type=request.correction_type,
                correction_label=request.correction_label,
                chosen_instrument=request.chosen_instrument,
                instrument_content=request.instrument_content,
                quiz_items_json=request.quiz_items_json,
                objective=request.objective,
                full_context=full_context,
                feedback=request.feedback
            )
            schema = CorrectionDesign
        else:
            return None, None, None

        return prompt, system_prompt, schema
    except Exception as e:
        logging.exception("Error preparing prompt data")
        return {"status": "error", "message": str(e)}, None, None

@app.post("/generate")
@trace_moodle_request(name="moodle_generate")
async def generate_endpoint(request: GenerateRequest):
    """
    Main generative endpoint for Steps 4, 5, and 6.
    """
    add_run_metadata({
        "course_id": request.course_id,
        "step": request.step,
        "chosen_instrument": request.chosen_instrument or None,
        "has_feedback": bool(request.feedback),
    })

    try:
        # from llm import generate_completion
        from llm import _llm_instance
        # Singleton instance initialized on module load
        
        prompt, system_prompt, schema = await _prepare_prompt_data(request)
        
        if not prompt:
            return {"status": "error", "message": f"Step {request.step} not supported for generation"}
        
        if isinstance(prompt, dict) and prompt.get("status") == "error":
            return prompt

        # 4. Call LLM
        response_text, usage = _llm_instance.generate_completion(prompt, system_prompt)
        
        if not response_text:
            return {"status": "error", "message": "AI generation failed"}

        # 5. Parse and Validate JSON
        try:
            clean_json = re.sub(r'^```json|```$', '', response_text, flags=re.MULTILINE).strip()
            validated_data = schema.model_validate_json(clean_json)
            add_run_metadata({"generation_status": "success", "usage": usage})
            return {
                "status": "success", 
                "output": validated_data.model_dump(),
                "usage": usage
            }
        except Exception as e:
            logging.exception("Validation failed for LLM output")
            add_run_tags(["validation-failed"])
            add_run_metadata({"generation_status": "validation_error", "error": str(e)})
            # If validation fails, we try to return the raw text with a warning or just error
            return {
                "status": "error", 
                "message": "La IA generó un formato no válido. Por favor, intenta de nuevo o ajusta tu petición.",
                "details": str(e)
            }

    except Exception as e:
        logging.exception("Error in /generate")
        return {"status": "error", "message": str(e)}
    

@app.get("/status/{course_id}")
def check_status(course_id: int):
    try:
        from rag.store import get_index_path, get_metadata_path, get_course_dir
        import pickle

        index_path = get_index_path(course_id)
        meta_path  = get_metadata_path(course_id)
        sel_path   = f"{get_course_dir(course_id)}/selected_files.json"

        exists = os.path.exists(index_path) and os.path.exists(meta_path)
        chunks = 0
        selected_files = []

        if exists:
            with open(meta_path, "rb") as f:
                meta = pickle.load(f)
            chunks = len(meta)

        if os.path.exists(sel_path):
            with open(sel_path, "r", encoding="utf-8") as f:
                selected_files = json.load(f)

        # 1. Check active background progress first
        if course_id in INGESTION_PROGRESS:
            prog = INGESTION_PROGRESS[course_id]
            # If finished (100%), remove from tracker so next call returns the static state
            if prog.get("progress", 0) >= 100:
                INGESTION_PROGRESS.pop(course_id, None)
            else:
                return {
                    "status": "success", 
                    "data": prog,
                    "selected_files": prog.get("selected_files", [])
                }

        # 2. Check active evaluation
        import time
        evaluation_in_progress = False
        evaluation_elapsed = 0
        eval_info = EVALUATION_IN_PROGRESS.get(course_id)
        if eval_info:
            elapsed = int(time.time() - eval_info["started_at"])
            if elapsed < EVALUATION_TIMEOUT:
                evaluation_in_progress = True
                evaluation_elapsed = elapsed
            else:
                EVALUATION_IN_PROGRESS.pop(course_id, None)

        return {
            "status": "success",
            "embedding_exists": exists,
            "chunks": chunks,
            "selected_files": selected_files,
            "evaluation_in_progress": evaluation_in_progress,
            "evaluation_elapsed": evaluation_elapsed,
            "data": {
                "progress": 100 if exists else 0,
                "message": "Completado" if exists else "Pendiente",
                "embedding_exists": exists,
                "chunks": chunks
            }
        }
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.delete("/ingest/{course_id}")
@trace_moodle_request(name="moodle_delete_embeddings")
async def delete_embeddings(course_id: int):
    """
    Delete the RAG index, metadata and selected_files list for a course.
    """
    add_run_metadata({"course_id": course_id})
    from rag.store import get_index_path, get_metadata_path, get_course_dir
    import os
    for path in [
        get_index_path(course_id),
        get_metadata_path(course_id),
        f"{get_course_dir(course_id)}/selected_files.json",
    ]:
        if os.path.exists(path):
            os.remove(path)
    INGESTION_PROGRESS.pop(course_id, None)
    return {"status": "success", "message": "Embeddings deleted"}


# --- RubricAI Neo4j & Multi-Agent Endpoints ---

@app.post("/rubrics")
@trace_moodle_request(name="moodle_create_rubric")
def create_rubric(rubric: dict):
    """
    Creates or updates a rubric in the Neo4j database.
    """
    from neo4j_client import get_neo4j_client
    client = get_neo4j_client()
    if not client or not client.initialized:
        return {"status": "error", "message": "Neo4j is not connected. Check environment variables."}
    
    try:
        rubric_id = client.save_rubric(rubric)
        add_run_metadata({"rubric_id": rubric_id})
        return {"status": "success", "rubric_id": rubric_id}
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.get("/rubrics")
@trace_moodle_request(name="moodle_list_rubrics")
def list_rubrics():
    """
    Retrieves all rubrics stored in Neo4j.
    """
    from neo4j_client import get_neo4j_client
    client = get_neo4j_client()
    if not client or not client.initialized:
        return []
    
    try:
        return client.list_rubrics()
    except Exception as e:
        return {"error": str(e)}


@app.get("/rubrics/{rubric_id}")
@trace_moodle_request(name="moodle_get_rubric")
def get_rubric(rubric_id: str):
    """
    Retrieves a full rubric from Neo4j.
    """
    add_run_metadata({"rubric_id": rubric_id})
    from neo4j_client import get_neo4j_client
    client = get_neo4j_client()
    if not client or not client.initialized:
        return {"status": "error", "message": "Neo4j not connected"}

    try:
        rubric = client.get_rubric(rubric_id)
        if not rubric:
            return {"status": "error", "message": "Rubric not found"}
        return rubric
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.delete("/rubrics/{rubric_id}")
@trace_moodle_request(name="moodle_delete_rubric")
def delete_rubric(rubric_id: str):
    """
    Deletes a rubric and all associated nodes from Neo4j.
    """
    add_run_metadata({"rubric_id": rubric_id})
    from neo4j_client import get_neo4j_client
    client = get_neo4j_client()
    if not client or not client.initialized:
        return {"status": "error", "message": "Neo4j not connected"}

    try:
        deleted = client.delete_rubric(rubric_id)
        if deleted:
            return {"status": "success"}
        return {"status": "error", "message": "Rubric not found"}
    except Exception as e:
        return {"status": "error", "message": str(e)}


@app.post("/evaluate")
@trace_moodle_request(name="moodle_evaluate")
def evaluate_course(request: EvaluateRequest):
    """
    Triggers multi-agent course comparison against a rubric.
    """
    add_run_metadata({
        "course_id": request.course_id,
        "rubric_id": request.rubric_id,
    })

    import time
    from agents import MultiAgentCoordinator
    course_id = request.course_id
    EVALUATION_IN_PROGRESS[course_id] = {"started_at": time.time()}
    try:
        coordinator = MultiAgentCoordinator()
        result = coordinator.evaluate_course(
            course_id=course_id,
            rubric_id=request.rubric_id,
            course_data=request.course_data
        )
        add_run_metadata({"final_score": result.get("overall_score", 0.0)})
        return result
    except Exception as e:
        logging.exception("Error in /evaluate endpoint")
        return {"status": "error", "message": str(e)}
    finally:
        EVALUATION_IN_PROGRESS.pop(course_id, None)


@app.get("/ontology")
@trace_moodle_request(name="moodle_ontology")
def get_ontology():
    """
    Returns nodes and edges representing the pedagogical ontology from Neo4j.
    """
    from neo4j_client import get_neo4j_client
    client = get_neo4j_client()
    if not client or not client.initialized:
        return {"nodes": [], "edges": []}
    
    try:
        result = client.get_ontology_graph()
        add_run_metadata({
            "n_nodes": len(result.get("nodes", [])),
            "n_edges": len(result.get("edges", [])),
        })
        return result
    except Exception as e:
        return {"status": "error", "message": str(e)}
