import unittest

from personality import (
    EvidenceMemory,
    MAX_RELEVANCE_DREAM,
    MAX_RELEVANCE_HYPOTHESIS,
    calculate_acquired_relevance,
    evaluate_learning_operation,
    revert_to_version,
)


class ControlledLearningTests(unittest.TestCase):
    def test_rejects_superseded_memory_as_evidence(self):
        with self.assertRaises(ValueError):
            EvidenceMemory(
                memory_id="mem-1",
                type="episodic",
                epistemic_status="fact",
                content="Obsolete observation",
                superseded_at="2026-11-05T10:00:00Z",
            )

    def test_fact_consolidates_personality_and_relevance(self):
        fact = EvidenceMemory(
            memory_id="mem-fact-1",
            type="episodic",
            epistemic_status="fact",
            content="Observación verificada sobre arquitectura de sistemas.",
        )
        snapshot = evaluate_learning_operation(
            current_version=1,
            current_personality={},
            current_interests={},
            memories=[fact],
            trait_updates={"analytical_rigor": 0.75},
            interest_topics=[{"topic": "arquitectura de sistemas"}],
            summary="Aprendizaje desde hecho verificado",
        )
        self.assertEqual(snapshot.version, 2)
        self.assertEqual(snapshot.operation_type, "learning")
        self.assertEqual(
            snapshot.personality["analytical_rigor"]["consolidation_status"],
            "consolidated",
        )
        self.assertEqual(snapshot.personality["analytical_rigor"]["value"], 0.75)
        self.assertEqual(
            snapshot.interests["arquitectura de sistemas"]["acquired_relevance"], 0.25
        )
        self.assertEqual(
            snapshot.interests["arquitectura de sistemas"]["consolidation_status"],
            "consolidated",
        )

    def test_hypothesis_scales_and_caps_tentative_learning(self):
        hyp = EvidenceMemory(
            memory_id="mem-hyp-1",
            type="semantic",
            epistemic_status="hypothesis",
            content="Hipótesis sobre preferencia por respuestas concisas.",
            confidence=0.50,
        )
        snapshot = evaluate_learning_operation(
            current_version=1,
            current_personality={},
            current_interests={},
            memories=[hyp],
            trait_updates={"conciseness_preference": 0.80},
            interest_topics=[{"topic": "comunicación concisa"}],
        )
        self.assertEqual(
            snapshot.personality["conciseness_preference"]["consolidation_status"],
            "tentative",
        )
        self.assertAlmostEqual(
            snapshot.personality["conciseness_preference"]["value"], 0.40
        )
        self.assertAlmostEqual(
            snapshot.interests["comunicación concisa"]["acquired_relevance"], 0.10
        )

        # Verify cap at MAX_RELEVANCE_HYPOTHESIS (0.60)
        r_val, status, _ = calculate_acquired_relevance([hyp], base_relevance=0.58)
        self.assertEqual(status, "tentative")
        self.assertAlmostEqual(r_val, MAX_RELEVANCE_HYPOTHESIS)

    def test_dream_guides_curiosity_and_exploratory_query_but_cannot_alter_traits(self):
        dream = EvidenceMemory(
            memory_id="mem-dream-beach-dog",
            type="oneiric",
            epistemic_status="dream",
            content="Soñé que veía un perro correr libremente en la playa.",
        )

        # 1. Dream must NOT be allowed to consolidate or modify personality traits
        with self.assertRaises(ValueError):
            evaluate_learning_operation(
                current_version=1,
                current_personality={},
                current_interests={},
                memories=[dream],
                trait_updates={"outdoors_affinity": 0.50},
            )

        # 2. Dream CAN guide curiosity by creating an oneiric_seed interest & exploratory query
        snapshot = evaluate_learning_operation(
            current_version=1,
            current_personality={},
            current_interests={},
            memories=[dream],
            interest_topics=[
                {
                    "topic": "playas aptas para perros",
                    "exploratory_query": "Averiguar cuál es la mejor playa para llevar un perro",
                }
            ],
            summary="Semilla de curiosidad originada por sueño",
        )
        interest = snapshot.interests["playas aptas para perros"]
        self.assertEqual(interest["consolidation_status"], "oneiric_seed")
        self.assertEqual(interest["source_epistemic_status"], "dream")
        self.assertEqual(
            interest["exploratory_query"],
            "Averiguar cuál es la mejor playa para llevar un perro",
        )
        self.assertAlmostEqual(interest["acquired_relevance"], 0.15)
        self.assertGreater(interest["curiosity_score"], 0.65)
        self.assertEqual(interest["evidence_memory_ids"], ["mem-dream-beach-dog"])

        # 3. Repeated dream stimuli remain capped at MAX_RELEVANCE_DREAM (0.25)
        r_capped, status, _ = calculate_acquired_relevance([dream], base_relevance=0.20)
        self.assertEqual(status, "oneiric_seed")
        self.assertAlmostEqual(r_capped, MAX_RELEVANCE_DREAM)

    def test_revert_to_previous_version_restores_state_auditably(self):
        v1 = evaluate_learning_operation(
            current_version=1,
            current_personality={},
            current_interests={},
            memories=[
                EvidenceMemory(
                    memory_id="mem-1",
                    type="episodic",
                    epistemic_status="fact",
                    content="Hecho inicial.",
                )
            ],
            trait_updates={"systematic": 0.40},
        )
        v2 = evaluate_learning_operation(
            current_version=v1.version,
            current_personality=v1.personality,
            current_interests=v1.interests,
            memories=[
                EvidenceMemory(
                    memory_id="mem-2",
                    type="episodic",
                    epistemic_status="fact",
                    content="Segundo hecho.",
                )
            ],
            trait_updates={"systematic": 0.90},
        )
        reverted = revert_to_version(
            current_version=v2.version,
            target_snapshot=v1,
            reason="Revertir ajuste excesivo",
        )
        self.assertEqual(reverted.version, 4)
        self.assertEqual(reverted.operation_type, "revert")
        self.assertEqual(reverted.reverted_from_version, v1.version)
        self.assertEqual(reverted.personality["systematic"]["value"], 0.40)


if __name__ == "__main__":
    unittest.main()

