#!/usr/bin/env python3
"""One-time migration script: Neo4j Aura Cloud -> Neo4j local."""
import argparse
import logging
from neo4j import GraphDatabase

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(__name__)


def export_nodes(session):
    result = session.run(
        "MATCH (n) RETURN elementId(n) AS eid, labels(n) AS labels, properties(n) AS props"
    )
    return [{"eid": r["eid"], "labels": r["labels"], "props": dict(r["props"])} for r in result]


def export_rels(session):
    result = session.run(
        """
        MATCH (a)-[r]->(b)
        RETURN elementId(a) AS src, elementId(b) AS tgt,
               type(r) AS rel_type, properties(r) AS props
        """
    )
    return [
        {"src": r["src"], "tgt": r["tgt"], "rel_type": r["rel_type"], "props": dict(r["props"])}
        for r in result
    ]


def import_nodes(session, nodes):
    for node in nodes:
        labels_str = ":".join(node["labels"])
        props = {**node["props"], "_mig_id": node["eid"]}
        session.run(f"CREATE (n:{labels_str} $props)", props=props)
    log.info("Imported %d nodes", len(nodes))


def import_rels(session, rels):
    for rel in rels:
        cypher = (
            f"MATCH (a {{_mig_id: $src}}), (b {{_mig_id: $tgt}}) "
            f"CREATE (a)-[r:{rel['rel_type']} $props]->(b)"
        )
        session.run(cypher, src=rel["src"], tgt=rel["tgt"], props=rel["props"])
    log.info("Imported %d relationships", len(rels))


def cleanup_mig_id(session):
    session.run("MATCH (n) WHERE n._mig_id IS NOT NULL REMOVE n._mig_id")
    log.info("Cleaned up _mig_id temporary attributes")


def main():
    parser = argparse.ArgumentParser(description="Migrate Neo4j Aura -> local")
    parser.add_argument("--source", required=True, help="Aura URI (neo4j+s://...)")
    parser.add_argument("--source-user", required=True, help="Aura username")
    parser.add_argument("--source-pass", required=True, help="Aura password")
    parser.add_argument("--target", required=True, help="Local bolt URI (bolt://localhost:7687)")
    parser.add_argument("--target-user", required=True, help="Local Neo4j username")
    parser.add_argument("--target-pass", required=True, help="Local Neo4j password")
    args = parser.parse_args()

    log.info("Connecting to source (Aura)...")
    src_driver = GraphDatabase.driver(args.source, auth=(args.source_user, args.source_pass))

    log.info("Connecting to target (local)...")
    tgt_driver = GraphDatabase.driver(args.target, auth=(args.target_user, args.target_pass))

    try:
        src_driver.verify_connectivity()
        log.info("Source connection OK")
        tgt_driver.verify_connectivity()
        log.info("Target connection OK")

        log.info("Exporting nodes from Aura...")
        with src_driver.session() as session:
            nodes = export_nodes(session)
        log.info("  -> %d nodes exported", len(nodes))

        log.info("Exporting relationships from Aura...")
        with src_driver.session() as session:
            rels = export_rels(session)
        log.info("  -> %d relationships exported", len(rels))

        log.info("Importing nodes to local...")
        with tgt_driver.session() as session:
            import_nodes(session, nodes)

        log.info("Importing relationships to local...")
        with tgt_driver.session() as session:
            import_rels(session, rels)

        log.info("Cleaning up temporary migration attributes...")
        with tgt_driver.session() as session:
            cleanup_mig_id(session)

        log.info("Migration complete!")
        log.info("Summary: %d nodes, %d relationships migrated", len(nodes), len(rels))

    finally:
        src_driver.close()
        tgt_driver.close()


if __name__ == "__main__":
    main()
