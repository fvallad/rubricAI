import os
import logging
from datetime import datetime
from neo4j import GraphDatabase
from tracing import trace_db, add_run_metadata

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger(__name__)

class Neo4jClient:
    _instance = None

    def __new__(cls, *args, **kwargs):
        if not cls._instance:
            cls._instance = super(Neo4jClient, cls).__new__(cls, *args, **kwargs)
            cls._instance.initialized = False
        return cls._instance

    def __init__(self):
        if self.initialized:
            return
        
        self.uri = os.getenv("NEO4J_URI", "bolt://localhost:7687")
        self.username = os.getenv("NEO4J_USERNAME", "neo4j")
        self.password = os.getenv("NEO4J_PASSWORD", "password")
        self.database = os.getenv("NEO4J_DATABASE", "neo4j")
        
        logger.info(f"Connecting to Neo4j at {self.uri} (Database: {self.database})")
        
        try:
            self.driver = GraphDatabase.driver(self.uri, auth=(self.username, self.password))
            # Test connection
            self.driver.verify_connectivity()
            logger.info("Successfully connected to Neo4j database!")
            self.initialized = True
            # Create constraints and seed default ontology
            self.init_database()
        except Exception as e:
            logger.error(f"Error connecting to Neo4j: {e}")
            self.driver = None

    def close(self):
        if self.driver:
            self.driver.close()
            logger.info("Neo4j connection closed.")

    @trace_db(name="neo4j_query")
    def query(self, cypher, parameters=None):
        # Add query metadata for LangSmith tracing
        add_run_metadata({
            "cypher": cypher[:500] if cypher else "",
            "has_parameters": parameters is not None,
            "parameter_keys": list((parameters or {}).keys()),
        })

        if not self.initialized or not self.driver:
            logger.error("Neo4j driver is not initialized.")
            return []
        
        with self.driver.session(database=self.database) as session:
            try:
                result = session.run(cypher, parameters)
                records = [record.data() for record in result]
                add_run_metadata({"result_count": len(records)})
                return records
            except Exception as e:
                logger.error(f"Error executing Cypher query: {e}\nQuery: {cypher}")
                raise e

    def init_database(self):
        """Create constraints, indexes and seed pedagogical ontology if empty."""
        try:
            # Create constraints
            self.query("CREATE CONSTRAINT unique_rubric_id IF NOT EXISTS FOR (r:Rubric) REQUIRE r.id IS UNIQUE")
            self.query("CREATE CONSTRAINT unique_course_id IF NOT EXISTS FOR (c:Course) REQUIRE c.id IS UNIQUE")
            self.query("CREATE CONSTRAINT unique_bloom_level IF NOT EXISTS FOR (b:BloomLevel) REQUIRE b.level IS UNIQUE")
            self.query("CREATE CONSTRAINT unique_dimension IF NOT EXISTS FOR (d:PedagogicalDimension) REQUIRE d.name IS UNIQUE")
            
            # Seed Bloom Taxonomy levels
            bloom_levels = [
                {"level": "Recordar", "desc": "Recordar hechos y conceptos básicos sin necesidad de entenderlos."},
                {"level": "Comprender", "desc": "Mostrar entendimiento básico de hechos e ideas al organizar y comparar."},
                {"level": "Aplicar", "desc": "Resolver problemas en situaciones nuevas aplicando el conocimiento adquirido."},
                {"level": "Analizar", "desc": "Examinar y descomponer la información en partes identificando causas."},
                {"level": "Evaluar", "desc": "Presentar y defender opiniones realizando juicios basados en criterios."},
                {"level": "Crear", "desc": "Compilar información de manera diferente combinando elementos en un nuevo patrón."}
            ]
            
            for b in bloom_levels:
                self.query(
                    "MERGE (b:BloomLevel {level: $level}) ON CREATE SET b.description = $desc",
                    {"level": b["level"], "desc": b["desc"]}
                )

            # Seed Pedagogical Dimensions
            dimensions = [
                {"name": "Contenidos", "desc": "Conceptos teóricos y prácticos del curso evaluados."},
                {"name": "Procedimiento", "desc": "Habilidades metodológicas e instrumentales aplicadas por el alumno."},
                {"name": "Actitud/Participación", "desc": "Compromiso, etiqueta en foros y calidad del debate interactivo."},
                {"name": "Formato/Estructura", "desc": "Cumplimiento de las reglas técnicas de Moodle (fechas, ponderaciones)."}
            ]
            
            for d in dimensions:
                self.query(
                    "MERGE (d:PedagogicalDimension {name: $name}) ON CREATE SET d.description = $desc",
                    {"name": d["name"], "desc": d["desc"]}
                )
                
            logger.info("Neo4j Database initialized with default constraints and seed ontology.")
        except Exception as e:
            logger.error(f"Failed to initialize database constraints: {e}")

    # --- Rubric Persistence Methods ---

    @trace_db(name="neo4j_save_rubric")
    def save_rubric(self, rubric_data: dict) -> str:
        """Saves a rubric dictionary to Neo4j as a structured graph."""
        rubric_id = rubric_data.get("id") or f"rubric_{int(datetime.now().timestamp())}"
        title = rubric_data.get("title", "Sin título")
        description = rubric_data.get("description", "")
        criteria = rubric_data.get("criteria", [])

        add_run_metadata({
            "rubric_id": rubric_id,
            "title": title,
            "n_criteria": len(criteria),
        })
        
        # 0. Delete ALL existing rubrics, criteria, and levels to ensure only ONE active rubric exists
        self.query(
            """
            MATCH (r:Rubric)
            OPTIONAL MATCH (r)-[:HAS_CRITERION]->(c:Criterion)
            OPTIONAL MATCH (c)-[:HAS_LEVEL]->(l:Level)
            DETACH DELETE r, c, l
            """
        )
        
        # 1. Create Rubric Node
        self.query(
            """
            MERGE (r:Rubric {id: $id})
            SET r.title = $title,
                r.description = $description,
                r.updated_at = timestamp()
            """,
            {"id": rubric_id, "title": title, "description": description}
        )
        
        # 2. Clear old criteria for this rubric (if updating)
        self.query(
            """
            MATCH (r:Rubric {id: $id})-[rel:HAS_CRITERION]->(c:Criterion)
            OPTIONAL MATCH (c)-[lrel:HAS_LEVEL]->(l:Level)
            DETACH DELETE c, l
            """,
            {"id": rubric_id}
        )
        
        # 3. Create new criteria and levels
        for idx, crit in enumerate(criteria):
            crit_name = crit.get("name", f"Criterio {idx+1}")
            crit_desc = crit.get("description", "")
            crit_weight = crit.get("weight", 0)
            dimension = crit.get("dimension", "Contenidos")
            
            # Create Criterion node and link to Rubric
            self.query(
                """
                MATCH (r:Rubric {id: $rubric_id})
                CREATE (c:Criterion {
                    id: $crit_id,
                    name: $name,
                    description: $desc,
                    weight: $weight
                })
                CREATE (r)-[:HAS_CRITERION {order: $order}]->(c)
                WITH c
                MERGE (d:PedagogicalDimension {name: $dim})
                CREATE (c)-[:MAPS_TO]->(d)
                """,
                {
                    "rubric_id": rubric_id,
                    "crit_id": f"{rubric_id}_crit_{idx}",
                    "name": crit_name,
                    "desc": crit_desc,
                    "weight": crit_weight,
                    "order": idx,
                    "dim": dimension
                }
            )
            
            # Create Levels for this criterion
            levels = crit.get("levels", [])
            for l_idx, lvl in enumerate(levels):
                lvl_label = lvl.get("label", "")
                lvl_score = lvl.get("score", 0)
                lvl_desc = lvl.get("description", "")
                
                self.query(
                    """
                    MATCH (c:Criterion {id: $crit_id})
                    CREATE (l:Level {
                        id: $lvl_id,
                        label: $label,
                        score: $score,
                        description: $desc
                    })
                    CREATE (c)-[:HAS_LEVEL {order: $order}]->(l)
                    """,
                    {
                        "crit_id": f"{rubric_id}_crit_{idx}",
                        "lvl_id": f"{rubric_id}_crit_{idx}_lvl_{l_idx}",
                        "label": lvl_label,
                        "score": lvl_score,
                        "desc": lvl_desc,
                        "order": l_idx
                    }
                )
                
        return rubric_id

    @trace_db(name="neo4j_get_rubric")
    def get_rubric(self, rubric_id: str) -> dict:
        """Retrieves a full rubric from Neo4j."""
        add_run_metadata({"rubric_id": rubric_id})

        rubric_res = self.query("MATCH (r:Rubric {id: $id}) RETURN r", {"id": rubric_id})
        if not rubric_res:
            return None
            
        r_node = rubric_res[0]["r"]
        
        # Get criteria
        criteria_res = self.query(
            """
            MATCH (r:Rubric {id: $id})-[rel:HAS_CRITERION]->(c:Criterion)
            OPTIONAL MATCH (c)-[:MAPS_TO]->(d:PedagogicalDimension)
            RETURN c, d.name as dimension
            ORDER BY rel.order ASC
            """,
            {"id": rubric_id}
        )
        
        criteria = []
        for row in criteria_res:
            c_node = row["c"]
            dimension = row["dimension"] or "Contenidos"
            
            # Get levels for this criterion
            levels_res = self.query(
                """
                MATCH (c:Criterion {id: $crit_id})-[rel:HAS_LEVEL]->(l:Level)
                RETURN l
                ORDER BY rel.order ASC
                """,
                {"crit_id": c_node["id"]}
            )
            
            levels = [l_row["l"] for l_row in levels_res]
            
            criteria.append({
                "name": c_node["name"],
                "description": c_node["description"],
                "weight": c_node["weight"],
                "dimension": dimension,
                "levels": levels
            })

        add_run_metadata({"n_criteria_found": len(criteria)})
            
        return {
            "id": r_node["id"],
            "title": r_node["title"],
            "description": r_node.get("description", ""),
            "criteria": criteria
        }

    @trace_db(name="neo4j_list_rubrics")
    def list_rubrics(self) -> list:
        """Lists all rubrics in the database (summary mode)."""
        results = self.query("MATCH (r:Rubric) RETURN r.id as id, r.title as title, r.description as description")
        add_run_metadata({"rubrics_count": len(results)})
        return results

    # --- Course Graphing Methods ---

    @trace_db(name="neo4j_sync_course_data")
    def sync_course_data(self, course_id: int, course_name: str, activities: list, resources: list):
        """Creates nodes for a Moodle Course, its activities, and its resources."""
        add_run_metadata({
            "course_id": course_id,
            "course_name": course_name,
            "n_activities": len(activities),
            "n_resources": len(resources),
        })

        # 1. Merge Course
        self.query(
            "MERGE (c:Course {id: $id}) SET c.name = $name",
            {"id": course_id, "name": course_name}
        )
        
        # 2. Detach old activities and resources
        self.query(
            "MATCH (c:Course {id: $id})-[rel:HAS_ACTIVITY|HAS_RESOURCE]->(x) DETACH DELETE x",
            {"id": course_id}
        )
        
        # 3. Create Activities
        for act in activities:
            self.query(
                """
                MATCH (c:Course {id: $course_id})
                CREATE (a:Activity {
                    id: $id,
                    name: $name,
                    type: $type,
                    description: $desc,
                    duedate: $duedate
                })
                CREATE (c)-[:HAS_ACTIVITY]->(a)
                """,
                {
                    "course_id": course_id,
                    "id": f"c_{course_id}_act_{act.get('id')}",
                    "name": act.get("name", "Actividad"),
                    "type": act.get("type", "unknown"),
                    "desc": act.get("description", "") or act.get("intro", ""),
                    "duedate": act.get("duedate", 0)
                }
            )
            
        # 4. Create Resources
        for res in resources:
            self.query(
                """
                MATCH (c:Course {id: $course_id})
                CREATE (r:Resource {
                    id: $id,
                    name: $name,
                    type: $type,
                    filename: $filename
                })
                CREATE (c)-[:HAS_RESOURCE]->(r)
                """,
                {
                    "course_id": course_id,
                    "id": f"c_{course_id}_res_{res.get('id', res.get('filename'))}",
                    "name": res.get("name", res.get("filename")),
                    "type": res.get("type", "file"),
                    "filename": res.get("filename", "")
                }
            )

    # --- Ontology Export for Frontend Visualization ---

    @trace_db(name="neo4j_get_ontology_graph")
    def get_ontology_graph(self) -> dict:
        """Returns nodes and edges for visualizing the ontology/rubric space in Cytoscape/D3."""
        # 1. Fetch BloomLevels
        bloom_res = self.query("MATCH (b:BloomLevel) RETURN b.level as id, b.level as label, 'bloom' as type, b.description as desc")
        # 2. Fetch Dimensions
        dim_res = self.query("MATCH (d:PedagogicalDimension) RETURN d.name as id, d.name as label, 'dimension' as type, d.description as desc")
        # 3. Fetch Rubrics
        rubric_res = self.query("MATCH (r:Rubric) RETURN r.id as id, r.title as label, 'rubric' as type, r.description as desc")
        # 4. Fetch Criteria
        crits_res = self.query("MATCH (c:Criterion) RETURN c.id as id, c.name as label, 'criterion' as type, c.description as desc")
        # 5. Fetch Courses
        courses_res = self.query("MATCH (c:Course) RETURN c.id as id, c.name as label, 'course' as type")
        # 6. Fetch Activities
        acts_res = self.query("MATCH (a:Activity) RETURN a.id as id, a.name as label, 'activity' as type, a.type as act_type, a.description as desc")
        # 7. Fetch Resources
        res_res = self.query("MATCH (r:Resource) RETURN r.id as id, r.name as label, 'resource' as type, r.type as res_type")
        # 8. Fetch Recommendations
        recs_res = self.query("MATCH (rc:Recommendation) RETURN rc.id as id, rc.element as label, 'recommendation' as type, rc.issue as desc")

        # Consolidate all nodes
        nodes = []
        for n in (bloom_res + dim_res + rubric_res + crits_res + courses_res + acts_res + res_res + recs_res):
            nodes.append({
                "id": str(n["id"]),
                "label": n["label"],
                "type": n["type"],
                "description": n.get("desc", "")
            })

        # Fetch all edges/relationships
        edges = []
        
        # Rubric -> Criterion (HAS_CRITERION)
        rel_rub_crit = self.query("MATCH (r:Rubric)-[rel:HAS_CRITERION]->(c:Criterion) RETURN r.id as source, c.id as target, 'HAS_CRITERION' as relation")
        # Criterion -> Dimension (MAPS_TO)
        rel_crit_dim = self.query("MATCH (c:Criterion)-[rel:MAPS_TO]->(d:PedagogicalDimension) RETURN c.id as source, d.name as target, 'MAPS_TO' as relation")
        # Course -> Activity (HAS_ACTIVITY)
        rel_cour_act = self.query("MATCH (c:Course)-[rel:HAS_ACTIVITY]->(a:Activity) RETURN c.id as source, a.id as target, 'HAS_ACTIVITY' as relation")
        # Course -> Resource (HAS_RESOURCE)
        rel_cour_res = self.query("MATCH (c:Course)-[rel:HAS_RESOURCE]->(r:Resource) RETURN c.id as source, r.id as target, 'HAS_RESOURCE' as relation")
        # Course -> Rubric (EVALUATED_WITH)
        rel_cour_rub = self.query("MATCH (c:Course)-[rel:EVALUATED_WITH]->(r:Rubric) RETURN c.id as source, r.id as target, 'EVALUATED_WITH' as relation, rel.score as score")
        # Course -> Recommendation (HAS_RECOMMENDATION)
        rel_cour_rec = self.query("MATCH (c:Course)-[rel:HAS_RECOMMENDATION]->(rc:Recommendation) RETURN c.id as source, rc.id as target, 'HAS_RECOMMENDATION' as relation")
        # Activity -> Criterion (COVERS)
        rel_act_crit = self.query("MATCH (a:Activity)-[rel:COVERS]->(c:Criterion) RETURN a.id as source, c.id as target, 'COVERS' as relation, rel.similarity as similarity")

        for e in (rel_rub_crit + rel_crit_dim + rel_cour_act + rel_cour_res + rel_cour_rub + rel_cour_rec + rel_act_crit):
            edge = {
                "source": str(e["source"]),
                "target": str(e["target"]),
                "relation": e["relation"]
            }
            if "score" in e and e["score"] is not None:
                edge["score"] = float(e["score"])
            if "similarity" in e and e["similarity"] is not None:
                edge["similarity"] = float(e["similarity"])
            edges.append(edge)

        add_run_metadata({
            "n_nodes": len(nodes),
            "n_edges": len(edges),
        })

        return {"nodes": nodes, "edges": edges}

# Instancia singleton
_neo4j_client_instance = None

def get_neo4j_client():
    global _neo4j_client_instance
    if _neo4j_client_instance is None:
        _neo4j_client_instance = Neo4jClient()
    return _neo4j_client_instance
