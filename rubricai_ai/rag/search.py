import faiss
import pickle
import numpy as np
import os
import logging
from pathlib import Path

from rag.store import get_index_path, get_metadata_path, load_index
from rag.utils import embed_text_chunks
from tracing import trace_rag

from langchain_text_splitters import RecursiveCharacterTextSplitter


RAG_THRESHOLD = float(os.getenv("RAG_THRESHOLD", 0.80))

def map_score_pedagogical(raw_score: float) -> float:
    """
    Maps raw E5 cosine similarities (which compress near 0.82-0.90) 
    into an intuitive 0-100% scale for teachers.
    """
    if raw_score >= 0.90:
        return 0.85 + (raw_score - 0.90) * 1.5
    if raw_score >= 0.84:
        return 0.50 + ((raw_score - 0.84) / 0.06) * 0.35
    if raw_score >= 0.80:
        return 0.05 + ((raw_score - 0.80) / 0.04) * 0.45
    return 0.05

@trace_rag(name="rag_search_course")
def search_course(course_id: int, query: str, top_k=20, threshold=None):
    """
    Search course embeddings and filter by similarity threshold.
    Uses the same E5 model + prefix convention as ingestion.
    """
    query_emb = embed_text_chunks([query], prefix="query: ")[0]
    return search_course_by_vector(course_id, query_emb, top_k, threshold)

def search_course_by_vector(course_id: int, query_vector: np.ndarray, top_k=10, threshold=None):
    """
    Perform search using a pre-computed vector. 
    Useful for batch processing.
    """
    if threshold is None:
        threshold = RAG_THRESHOLD

    try:
        index, metadata = load_index(course_id)
    except Exception as e:
        logging.warning(f"Could not load index for course {course_id}: {e}")
        return []

    if len(query_vector.shape) == 1:
        query_vector = query_vector.reshape(1, -1)
        
    D, I = index.search(query_vector, top_k)
    
    results = []
    for rank, idx in enumerate(I[0]):
        score = float(D[0][rank])
        
        if idx < len(metadata) and score >= threshold:
            entry = dict(metadata[idx])
            # Apply statistical makeup mapping
            entry["similarity"] = map_score_pedagogical(score)
            entry["raw_score"] = score
            entry["rank"] = len(results) + 1
            results.append(entry)
    
    logging.info(f"[RAG Search] course={course_id} vector_search results={len(results)} "
                 f"top_score={float(D[0][0]):.4f} threshold={threshold}")
    return results

@trace_rag(name="rag_search_guidelines")
def search_guidelines(query: str, top_k=5):
    """
    Search specifically in the pedagogical guidelines (course_id=0).
    """
    try:
        return search_course(0, query, top_k=top_k)
    except Exception as e:
        logging.warning(f"Guidelines index not found or error: {e}")
        return []
