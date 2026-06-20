#!/usr/bin/env python3
"""One-time migration: Memgraph -> Neo4j local."""
import argparse
import logging
from neo4j import GraphDatabase

logging.basicConfig(level=logging.INFO, format="%(asctime)s %(levelname)s %(message)s")
log = logging.getLogger(__name__)


def export_nodes(session):
    result = session.run(
        "MATCH (n) RETURN id(n) AS eid, labels(n) AS labels, properties(n) AS props"
    )
    return [{"eid": r["eid"], "labels": r["labels"], "props": dict(r["props"])} for r in result]


def export_rels(session):
    result = session.run(
        """
        MATCH (a)-[r]->(b)
        RETURN id(a) AS src, id(b) AS tgt,
               type(r) AS rel_type, properties(r) AS props
        """
    )
    return [
        {"src": r["src"], "tgt": r["tgt"], "rel_type": r["rel_type"], "props": dict(r["props"])}
        for r in result
    ]


def import_nodes(session, nodes):
    with session.begin_transaction() as tx:
        for node in nodes:
            labels_str = ":".join(f"`{lbl}`" for lbl in node["labels"])
            props = {**node["props"], "_mig_id": node["eid"]}
            tx.run(f"CREATE (n:{labels_str} $props)", props=props)
        tx.commit()
    log.info("Imported %d nodes", len(nodes))


def import_rels(session, rels):
    with session.begin_transaction() as tx:
        for rel in rels:
            rel_type = f"`{rel['rel_type']}`"
            cypher = (
                f"MATCH (a {{_mig_id: $src}}), (b {{_mig_id: $tgt}}) "
                f"CREATE (a)-[r:{rel_type} $props]->(b)"
            )
            tx.run(cypher, src=rel["src"], tgt=rel["tgt"], props=rel["props"])
        tx.commit()
    log.info("Imported %d relationships", len(rels))


def cleanup_mig_id(session):
    session.run("MATCH (n) WHERE n._mig_id IS NOT NULL REMOVE n._mig_id")
    log.info("Cleaned up _mig_id temporary attributes")


def main():
    parser = argparse.ArgumentParser(description="Migrate Memgraph -> Neo4j local")
    parser.add_argument("--source", required=True, help="Memgraph bolt URI")
    parser.add_argument("--source-user", default="", help="Memgraph username (usually empty)")
    parser.add_argument("--source-pass", default="", help="Memgraph password (usually empty)")
    parser.add_argument("--target", required=True, help="Neo4j bolt URI")
    parser.add_argument("--target-user", required=True)
    parser.add_argument("--target-pass", required=True)
    args = parser.parse_args()

    log.info("Connecting to source (Memgraph)...")
    src_driver = GraphDatabase.driver(args.source, auth=(args.source_user, args.source_pass))

    log.info("Connecting to target (Neo4j local)...")
    tgt_driver = GraphDatabase.driver(args.target, auth=(args.target_user, args.target_pass))

    try:
        src_driver.verify_connectivity()
        log.info("Source connection OK")
        tgt_driver.verify_connectivity()
        log.info("Target connection OK")

        with tgt_driver.session() as session:
            count = session.run("MATCH (n) RETURN count(n) AS cnt").single()["cnt"]
            if count > 0:
                log.error("Target database is not empty (%d nodes). Aborting.", count)
                return

        log.info("Exporting nodes from Memgraph...")
        with src_driver.session() as session:
            nodes = export_nodes(session)
        log.info("  -> %d nodes exported", len(nodes))

        log.info("Exporting relationships from Memgraph...")
        with src_driver.session() as session:
            rels = export_rels(session)
        log.info("  -> %d relationships exported", len(rels))

        log.info("Importing nodes to Neo4j...")
        with tgt_driver.session() as session:
            import_nodes(session, nodes)

        log.info("Importing relationships to Neo4j...")
        with tgt_driver.session() as session:
            import_rels(session, rels)

        log.info("Cleaning up...")
        with tgt_driver.session() as session:
            cleanup_mig_id(session)

        log.info("Migration complete! %d nodes, %d relationships", len(nodes), len(rels))

    finally:
        src_driver.close()
        tgt_driver.close()


if __name__ == "__main__":
    main()
