import logging, os, json
import numpy as np
from pathlib import Path
from rag.store import save_index
from rag.utils import extract_pdf, extract_docx, extract_pptx, embed_text_chunks
from langchain_text_splitters import RecursiveCharacterTextSplitter
BASE_PATH = os.getenv("RUBRICAI_SYNC_PATH", os.path.join(os.path.dirname(os.path.dirname(__file__)), "data", "sync"))


def run_ingestion(course_id: int,  chunk_size=1000, overlap=250, progress_callback=None):
    """
    Read course folder, split files into chunks, embed, save index and metadata.
    """
    def update_p(val, msg):
        logging.info(f"[Course {course_id}] {val}% - {msg}")
        if progress_callback: progress_callback(val, msg)

    all_chunks = []
    metadata = []
    
    update_p(5, "Iniciando escaneo de archivos...")
    folder_path = Path(BASE_PATH) / f"course_{course_id}"
    if not folder_path.exists():
        update_p(0, f"Error: Carpeta no encontrada")
        raise FileNotFoundError(f"Folder {folder_path} not found")

    try:
        all_files = list(folder_path.rglob("*"))
    except Exception as e:
        update_p(0, f"Error en rglob")
        return 0

    files_to_process = [f for f in all_files if f.is_file() and f.suffix.lower() in ['.pdf', '.docx', '.pptx', '.txt']]
    total_files = len(files_to_process)
    
    for idx, file_path in enumerate(files_to_process):
        try:
            # Progress 10% to 50% for extraction
            p_val = 10 + int((idx / (total_files or 1)) * 40)
            update_p(p_val, f"Extrayendo texto: {file_path.name}")
            
            ext = file_path.suffix.lower()
            text = ""
            if ext == ".pdf":
                text = extract_pdf(file_path)
            elif ext == ".docx":
                text = extract_docx(file_path)
            elif ext == ".pptx":
                text = extract_pptx(file_path)
            elif ext == ".txt":
                with open(file_path, "r", encoding="utf-8", errors="ignore") as f:
                    text = f.read()
            else:
                continue
        except Exception as e:
            continue

        # Option B: Advanced splitting with larger context and paragraph awareness
        splitter = RecursiveCharacterTextSplitter(
            chunk_size=chunk_size, 
            chunk_overlap=overlap,
            separators=["\n\n", "\n", ".", " ", ""]
        )
        chunks = splitter.split_text(text)


        for i, chunk in enumerate(chunks):
            all_chunks.append(chunk)
            metadata.append({
                "filename": file_path.name,
                "path": str(file_path),
                "chunk_id": i,
                "text": chunk
            })

    # 3. Generate Embeddings (Manual Batching for progress updates)
    batch_size = 32
    total_chunks = len(all_chunks)
    all_embeddings = []
    
    if total_chunks > 0:
        update_p(50, f"Generando vectores para {total_chunks} fragmentos...")
        
        for start_idx in range(0, total_chunks, batch_size):
            end_idx = min(start_idx + batch_size, total_chunks)
            batch = all_chunks[start_idx:end_idx]
            
            # Embed this batch
            batch_embeddings = embed_text_chunks(batch, prefix="passage: ")
            all_embeddings.extend(batch_embeddings)
            
            # Progress 50% to 90%
            progress = 50 + int((end_idx / total_chunks) * 40)
            update_p(progress, f"Embeddings: {end_idx}/{total_chunks}")

        embeddings_np = np.array(all_embeddings).astype("float32")
        
        # 4. Save Index and Metadata
        update_p(90, "Guardando índice en disco...")
        save_index(course_id, embeddings_np, metadata)

        # 5. Save selected_files.json — list of relative paths that were embedded
        selected_files = sorted(set(
            str(Path(m["path"]).relative_to(folder_path))
            for m in metadata
        ))
        selected_files_path = folder_path / "selected_files.json"
        with open(selected_files_path, "w", encoding="utf-8") as f:
            json.dump(selected_files, f, ensure_ascii=False, indent=2)

        update_p(100, "¡Biblioteca construida con éxito!")
        return total_chunks
    else:
        update_p(0, "Error: No se encontró texto para procesar")
        return 0

