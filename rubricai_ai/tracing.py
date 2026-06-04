"""
RubricAI — LangSmith Tracing Module
====================================
Centralizes all LangSmith configuration and provides pre-configured
tracing utilities for every layer of the application:

  - Agent trajectories (multi-agent evaluation workflow)
  - LLM calls (OpenAI-compatible providers)
  - Neo4j database interactions
  - Moodle plugin HTTP communications

Environment variables required (already in .env):
  LANGSMITH_API_KEY, LANGSMITH_PROJECT, LANGSMITH_TRACING=true
"""

import os
import logging
import functools
from datetime import datetime, timezone
from dotenv import load_dotenv

load_dotenv()

logger = logging.getLogger(__name__)

# ---------------------------------------------------------------------------
# 1. LangSmith SDK availability check
# ---------------------------------------------------------------------------
_TRACING_ENABLED = False

try:
    from langsmith import traceable, get_current_run_tree
    from langsmith.wrappers import wrap_openai

    # Only enable if env says so
    if os.getenv("LANGSMITH_TRACING", "").lower() == "true":
        _TRACING_ENABLED = True
        logger.info(
            "LangSmith tracing ENABLED — project: %s",
            os.getenv("LANGSMITH_PROJECT", "default"),
        )
    else:
        logger.info("LangSmith SDK found but LANGSMITH_TRACING != 'true'. Tracing is OFF.")
except ImportError:
    logger.warning(
        "langsmith package not installed. Tracing disabled. "
        "Install with: pip install langsmith"
    )

    # Provide no-op fallbacks so the rest of the code works without langsmith
    def traceable(*args, **kwargs):  # noqa: E303
        """No-op decorator when langsmith is not installed."""
        if args and callable(args[0]):
            return args[0]
        return lambda fn: fn

    def get_current_run_tree():  # noqa: E303
        return None

    def wrap_openai(client, **kwargs):  # noqa: E303
        return client


# ---------------------------------------------------------------------------
# 2. Pre-configured decorator factories
# ---------------------------------------------------------------------------

def trace_agent(name: str, **extra_kwargs):
    """Decorator for agent methods (run_type=chain)."""
    custom_tags = extra_kwargs.pop("tags", [])
    merged_tags = list(set(["agent"] + custom_tags))
    return traceable(
        name=name,
        run_type="chain",
        tags=merged_tags,
        **extra_kwargs,
    )


def trace_llm_call(name: str = "llm_generate", **extra_kwargs):
    """Decorator for direct LLM generation calls."""
    custom_tags = extra_kwargs.pop("tags", [])
    merged_tags = list(set(["llm"] + custom_tags))
    return traceable(
        name=name,
        run_type="llm",
        tags=merged_tags,
        **extra_kwargs,
    )


def trace_db(name: str = "neo4j_operation", **extra_kwargs):
    """Decorator for Neo4j database operations."""
    custom_tags = extra_kwargs.pop("tags", [])
    merged_tags = list(set(["neo4j", "database"] + custom_tags))
    return traceable(
        name=name,
        run_type="tool",
        tags=merged_tags,
        **extra_kwargs,
    )


def trace_moodle_request(name: str = "moodle_api", **extra_kwargs):
    """Decorator for incoming Moodle plugin requests."""
    custom_tags = extra_kwargs.pop("tags", [])
    merged_tags = list(set(["moodle-api"] + custom_tags))
    return traceable(
        name=name,
        run_type="tool",
        tags=merged_tags,
        **extra_kwargs,
    )


def trace_rag(name: str = "rag_operation", **extra_kwargs):
    """Decorator for RAG pipeline operations."""
    custom_tags = extra_kwargs.pop("tags", [])
    merged_tags = list(set(["rag"] + custom_tags))
    return traceable(
        name=name,
        run_type="retriever",
        tags=merged_tags,
        **extra_kwargs,
    )


# ---------------------------------------------------------------------------
# 3. Dynamic metadata helpers
# ---------------------------------------------------------------------------

def add_run_metadata(metadata: dict):
    """
    Adds metadata to the current LangSmith run (if active).
    Safe to call even when tracing is disabled.
    """
    if not _TRACING_ENABLED:
        return
    try:
        rt = get_current_run_tree()
        if rt:
            rt.add_metadata(metadata)
    except Exception as e:
        logger.debug(f"Could not add run metadata: {e}")


def add_run_tags(tags: list):
    """
    Adds tags to the current LangSmith run (if active).
    Safe to call even when tracing is disabled.
    """
    if not _TRACING_ENABLED:
        return
    try:
        rt = get_current_run_tree()
        if rt:
            rt.add_tags(tags)
    except Exception as e:
        logger.debug(f"Could not add run tags: {e}")


# ---------------------------------------------------------------------------
# 4. OpenAI client wrapper
# ---------------------------------------------------------------------------

def get_traced_openai_client(base_client):
    """
    Wraps an OpenAI client instance with LangSmith tracing.
    If tracing is disabled, returns the original client unchanged.
    """
    if not _TRACING_ENABLED:
        return base_client
    try:
        return wrap_openai(base_client)
    except Exception as e:
        logger.warning(f"Failed to wrap OpenAI client for tracing: {e}")
        return base_client


# ---------------------------------------------------------------------------
# 5. FastAPI middleware for tracing incoming HTTP requests
# ---------------------------------------------------------------------------

def create_tracing_middleware():
    """
    Returns a Starlette-compatible middleware that creates a LangSmith
    trace for every incoming HTTP request from the Moodle plugin.
    """
    from starlette.middleware.base import BaseHTTPMiddleware
    from starlette.requests import Request
    from starlette.responses import Response
    import time

    class LangSmithTracingMiddleware(BaseHTTPMiddleware):
        async def dispatch(self, request: Request, call_next):
            if not _TRACING_ENABLED:
                return await call_next(request)

            start_time = time.time()
            endpoint = request.url.path
            method = request.method

            # Extract useful headers
            user_agent = request.headers.get("user-agent", "unknown")
            content_type = request.headers.get("content-type", "")

            # Try to extract course_id from query params
            course_id = request.query_params.get("course_id", None)

            response = await call_next(request)

            duration_ms = (time.time() - start_time) * 1000

            # Log as a lightweight structured entry
            # The actual tracing happens via @traceable on the endpoint functions
            logger.info(
                "[LangSmith Middleware] %s %s -> %s (%.0fms) UA=%s",
                method,
                endpoint,
                response.status_code,
                duration_ms,
                user_agent[:80],
            )

            return response

    return LangSmithTracingMiddleware


# ---------------------------------------------------------------------------
# 6. Public API
# ---------------------------------------------------------------------------

__all__ = [
    "trace_agent",
    "trace_llm_call",
    "trace_db",
    "trace_moodle_request",
    "trace_rag",
    "add_run_metadata",
    "add_run_tags",
    "get_traced_openai_client",
    "create_tracing_middleware",
    "traceable",
    "get_current_run_tree",
    "wrap_openai",
    "_TRACING_ENABLED",
]
