from pydantic import BaseModel
from typing import Any, List, Optional

class SuggestionItem(BaseModel):
    name: str
    why: str
    lim: str

class SuggestionsResponse(BaseModel):
    suggestions: List[SuggestionItem]

class ItemPair(BaseModel):
    premise: str
    answer: str

class InstrumentItem(BaseModel):
    type: str # Must be one of the names in tipos_de_preguntas.json
    objectives: List[str]
    consiga: str # The main question or text
    alternativas: Optional[List[str]] = None # List of options for Multiple Choice
    oraciones: Optional[List[str]] = None # List of sentences for True/False
    difficulty: str # e.g., "Fácil", "Media", "Difícil"
    points: Optional[float] = None # Estimated points (not final)
    correct_index: Optional[int] = None # For Multiple Choice
    correct_boolean: Optional[bool] = None # For True/False
    pairs: Optional[List[ItemPair]] = None # For Matching
    short_answer: Optional[str] = None # For Short Answer
    numerical_value: Optional[float] = None # For Numerical

class InstrumentDesign(BaseModel):
    title: str
    items: List[InstrumentItem]
    justification: str

class RubricLevel(BaseModel):
    label: str
    score: int
    description: str

class RubricCriterion(BaseModel):
    name: str
    description: str
    levels: List[RubricLevel]

class RubricDesign(BaseModel):
    title: str
    criteria: List[RubricCriterion]
    justification: Optional[str] = None

# --- Correction Instrument Schemas (Step 9) ---

class CorrectionKeyItem(BaseModel):
    question: str
    answer: str

class ChecklistItem(BaseModel):
    criterion: str

class RatingScaleItem(BaseModel):
    criterion: str

class CorrectionDesign(BaseModel):
    title: str
    type: Optional[str] = None
    items: Optional[List[Any]] = None
    criteria: Optional[List[Any]] = None
    levels: Optional[List[Any]] = None
    rubric_criteria: Optional[List[Any]] = None
    justification: Optional[str] = None

    class Config:
        extra = 'allow'

class FeedbackClassification(BaseModel):
    is_valid: bool
    reason: Optional[str] = None

class GenerateRequest(BaseModel):
    course_id: int
    course_title: Optional[str] = None
    step: int
    objective: str = ""
    objective_json: Optional[str] = ""
    summary: str = ""
    dimensions: str = ""
    feedback: str = ""
    chosen_instrument: str = ""
    instrument_content: str = ""
    rag_context: str = ""
    d1_content: str = ""
    d3_function: str = ""
    d4_modality: str = ""
    num_items: Optional[int] = 5
    correction_type: str = ""       # clave_correccion, lista_cotejo, escala_valoracion, rubrica
    correction_label: str = ""       # Human-readable label
    quiz_items_json: str = ""        # JSON of quiz items for context
