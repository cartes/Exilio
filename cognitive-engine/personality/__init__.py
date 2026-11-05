"""Controlled, auditable, and reversible learning evaluation (Fase 3)."""

from dataclasses import dataclass
import math
from typing import Any, Sequence

from config import CuriosityWeights
from curiosity import CuriositySignals, curiosity_score

MAX_RELEVANCE_FACT = 1.0
MAX_RELEVANCE_HYPOTHESIS = 0.60
MAX_RELEVANCE_DREAM = 0.25

VALID_MEMORY_TYPES = ("episodic", "semantic", "social", "procedural", "oneiric")
VALID_EPISTEMIC_STATUSES = ("fact", "hypothesis", "dream")


@dataclass(frozen=True)
class EvidenceMemory:
    """Validated memory snapshot used as traceable evidence for learning."""

    memory_id: str
    type: str
    epistemic_status: str
    content: str
    confidence: float | None = None
    superseded_at: str | None = None

    def __post_init__(self) -> None:
        if not self.memory_id or not self.memory_id.strip():
            raise ValueError("memory_id is required for evidence traceability")
        if self.superseded_at is not None:
            raise ValueError(
                f"Superseded memory '{self.memory_id}' cannot be used as learning evidence"
            )
        if self.type not in VALID_MEMORY_TYPES:
            raise ValueError(f"Invalid memory type: {self.type}")
        if self.epistemic_status not in VALID_EPISTEMIC_STATUSES:
            raise ValueError(f"Invalid epistemic_status: {self.epistemic_status}")
        if self.type == "oneiric" and self.epistemic_status != "dream":
            raise ValueError("Oneiric memories must have epistemic_status='dream'")
        if self.type != "oneiric" and self.epistemic_status == "dream":
            raise ValueError("Only oneiric memories can have epistemic_status='dream'")
        if self.epistemic_status == "hypothesis":
            if (
                self.confidence is None
                or not math.isfinite(self.confidence)
                or not 0.0 <= self.confidence <= 1.0
            ):
                raise ValueError("Hypothesis evidence requires confidence in [0, 1]")
        elif self.confidence is not None:
            raise ValueError("Non-hypothesis evidence must have confidence=None")


@dataclass(frozen=True)
class LearningVersionSnapshot:
    """Immutable snapshot of an agent's personality and interests version."""

    version: int
    operation_type: str
    summary: str
    evidence_memory_ids: tuple[str, ...]
    personality: dict[str, dict[str, Any]]
    interests: dict[str, dict[str, Any]]
    reverted_from_version: int | None = None


def calculate_acquired_relevance(
    memories: Sequence[EvidenceMemory],
    base_relevance: float = 0.0,
) -> tuple[float, str, str]:
    """Calculate acquired relevance R in [0, 1] and epistemic consolidation status.

    Returns:
        (relevance_r, consolidation_status, dominant_epistemic_status)
    """
    if not memories:
        raise ValueError("Acquired relevance requires at least one evidence memory")
    if not math.isfinite(base_relevance) or not 0.0 <= base_relevance <= 1.0:
        raise ValueError("base_relevance must be normalized in [0, 1]")

    has_fact = any(m.epistemic_status == "fact" for m in memories)
    has_hypothesis = any(m.epistemic_status == "hypothesis" for m in memories)

    delta = 0.0
    for mem in memories:
        if mem.epistemic_status == "fact":
            delta += 0.25
        elif mem.epistemic_status == "hypothesis":
            assert mem.confidence is not None
            delta += round(0.20 * mem.confidence, 4)
        elif mem.epistemic_status == "dream":
            delta += 0.15

    if has_fact:
        cap = MAX_RELEVANCE_FACT
        consolidation = "consolidated"
        dominant_status = "fact"
    elif has_hypothesis:
        cap = MAX_RELEVANCE_HYPOTHESIS
        consolidation = "tentative"
        dominant_status = "hypothesis"
    else:
        cap = MAX_RELEVANCE_DREAM
        consolidation = "oneiric_seed"
        dominant_status = "dream"

    final_r = min(cap, round(base_relevance + delta, 4))
    return final_r, consolidation, dominant_status


def derive_curiosity_signals_for_interest(
    dominant_status: str,
    acquired_relevance: float,
    average_confidence: float | None = None,
) -> CuriositySignals:
    """Derive normalized curiosity signals (N, I, A, R) according to epistemic status."""
    if dominant_status == "dream":
        # Dreams act as exploratory seeds with high novelty, uncertainty, and learning potential
        return CuriositySignals(
            novelty=0.85,
            uncertainty=0.80,
            learning=0.75,
            relevance=acquired_relevance,
        )
    if dominant_status == "hypothesis":
        conf = average_confidence if average_confidence is not None else 0.5
        uncertainty = round(max(0.15, 1.0 - conf), 4)
        return CuriositySignals(
            novelty=0.65,
            uncertainty=uncertainty,
            learning=0.70,
            relevance=acquired_relevance,
        )
    # Consolidated facts have lower uncertainty and steady learning potential
    return CuriositySignals(
        novelty=0.50,
        uncertainty=0.25,
        learning=0.60,
        relevance=acquired_relevance,
    )


def evaluate_learning_operation(
    current_version: int,
    current_personality: dict[str, dict[str, Any]],
    current_interests: dict[str, dict[str, Any]],
    memories: Sequence[EvidenceMemory],
    trait_updates: dict[str, float] | None = None,
    interest_topics: Sequence[dict[str, str]] | None = None,
    summary: str = "Explicit controlled learning operation",
    weights: CuriosityWeights = CuriosityWeights(),
) -> LearningVersionSnapshot:
    """Evaluate a controlled learning operation and return a new version snapshot."""
    if not memories:
        raise ValueError("Learning operation requires at least one active evidence memory")

    trait_updates = trait_updates or {}
    interest_topics = interest_topics or []

    if not trait_updates and not interest_topics:
        raise ValueError(
            "Learning operation must specify at least one trait update or interest topic"
        )

    has_fact = any(m.epistemic_status == "fact" for m in memories)
    has_hypothesis = any(m.epistemic_status == "hypothesis" for m in memories)
    only_dreams = not has_fact and not has_hypothesis

    if only_dreams and trait_updates:
        raise ValueError(
            "Dreams cannot consolidate or modify personality traits as lived reality; "
            "they can only guide curiosity and exploratory interests."
        )

    evidence_ids = tuple(dict.fromkeys(m.memory_id for m in memories))
    non_dream_ids = tuple(
        dict.fromkeys(m.memory_id for m in memories if m.epistemic_status != "dream")
    )

    hypotheses = [m.confidence for m in memories if m.epistemic_status == "hypothesis" and m.confidence is not None]
    avg_confidence = (sum(hypotheses) / len(hypotheses)) if hypotheses else None

    next_personality = {k: dict(v) for k, v in current_personality.items()}
    for trait_key, target_val in trait_updates.items():
        key = trait_key.strip()
        if not key:
            raise ValueError("Trait key cannot be empty")
        if not math.isfinite(target_val) or not 0.0 <= target_val <= 1.0:
            raise ValueError(f"Trait '{key}' value must be normalized in [0, 1]")

        if has_fact:
            effective_val = round(target_val, 4)
            status = "consolidated"
        else:
            # Tentative hypothesis-backed trait adjustment capped and weighted by confidence
            conf_factor = avg_confidence if avg_confidence is not None else 0.5
            effective_val = round(min(MAX_RELEVANCE_HYPOTHESIS, target_val * conf_factor), 4)
            status = "tentative"

        existing_evidence = tuple(next_personality.get(key, {}).get("evidence_memory_ids", ()))
        merged_evidence = tuple(dict.fromkeys((*existing_evidence, *non_dream_ids)))

        next_personality[key] = {
            "key": key,
            "value": effective_val,
            "consolidation_status": status,
            "evidence_memory_ids": list(merged_evidence),
        }

    next_interests = {k: dict(v) for k, v in current_interests.items()}
    for item in interest_topics:
        topic = item.get("topic", "").strip()
        if not topic:
            raise ValueError("Interest topic cannot be empty")
        existing = next_interests.get(topic, {})
        base_r = float(existing.get("acquired_relevance", 0.0))

        r_val, consolidation, dominant_status = calculate_acquired_relevance(
            memories, base_relevance=base_r
        )
        signals = derive_curiosity_signals_for_interest(
            dominant_status, r_val, average_confidence=avg_confidence
        )
        c_score = round(curiosity_score(signals, weights), 4)

        custom_query = item.get("exploratory_query", "").strip()
        if not custom_query and dominant_status == "dream":
            custom_query = f"Explorar e investigar hechos verificables sobre: {topic}"

        existing_evidence = tuple(existing.get("evidence_memory_ids", ()))
        merged_evidence = tuple(dict.fromkeys((*existing_evidence, *evidence_ids)))

        next_interests[topic] = {
            "topic": topic,
            "acquired_relevance": r_val,
            "consolidation_status": consolidation,
            "source_epistemic_status": dominant_status,
            "exploratory_query": custom_query or existing.get("exploratory_query"),
            "curiosity_signals": {
                "novelty": signals.novelty,
                "uncertainty": signals.uncertainty,
                "learning": signals.learning,
                "relevance": signals.relevance,
            },
            "curiosity_score": c_score,
            "evidence_memory_ids": list(merged_evidence),
        }

    return LearningVersionSnapshot(
        version=current_version + 1,
        operation_type="learning",
        summary=summary,
        evidence_memory_ids=evidence_ids,
        personality=next_personality,
        interests=next_interests,
        reverted_from_version=None,
    )


def revert_to_version(
    current_version: int,
    target_snapshot: LearningVersionSnapshot,
    reason: str = "Explicit audit rollback",
) -> LearningVersionSnapshot:
    """Create a new auditable version snapshot that restores a previous version's state."""
    if target_snapshot.version >= current_version:
        raise ValueError("Can only revert to a strictly previous version")

    return LearningVersionSnapshot(
        version=current_version + 1,
        operation_type="revert",
        summary=f"{reason} (restored from v{target_snapshot.version})",
        evidence_memory_ids=target_snapshot.evidence_memory_ids,
        personality={k: dict(v) for k, v in target_snapshot.personality.items()},
        interests={k: dict(v) for k, v in target_snapshot.interests.items()},
        reverted_from_version=target_snapshot.version,
    )

