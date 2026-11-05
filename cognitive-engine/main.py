"""Executable entrypoint for local cognitive engine testing and verification."""

import asyncio
import sys

from config import Settings
from curiosity import CuriositySignals, curiosity_score
from personality import EvidenceMemory, evaluate_learning_operation
from providers.mock import MockProvider


async def run_engine() -> int:
    settings = Settings.from_env()
    print("=" * 60)
    print("Proyecto Exilio — Cognitive Engine (Fases 0–3)")
    print("=" * 60)
    print(f"Provider:             {settings.provider}")
    print(f"Monthly AI Budget:    ${settings.monthly_budget_usd:.2f} USD")
    print(
        f"Curiosity Weights:    N={settings.curiosity_weights.novelty:.2f}, "
        f"I={settings.curiosity_weights.uncertainty:.2f}, "
        f"A={settings.curiosity_weights.learning:.2f}, "
        f"R={settings.curiosity_weights.relevance:.2f}"
    )
    print("-" * 60)

    # Calculate sample curiosity score
    sample_signals = CuriositySignals(
        novelty=0.8,
        uncertainty=0.5,
        learning=0.7,
        relevance=0.2,
    )
    score = curiosity_score(sample_signals, settings.curiosity_weights)
    print(
        f"Sample Curiosity Signals (N=0.8, I=0.5, A=0.7, R=0.2) -> Score: {score:.4f}"
    )

    # Verify Fase 3 controlled learning & dream curiosity guidance
    dream_evidence = EvidenceMemory(
        memory_id="mem-dream-001",
        type="oneiric",
        epistemic_status="dream",
        content="Soñé que veía un perro correr en la playa.",
    )
    snapshot = evaluate_learning_operation(
        current_version=1,
        current_personality={},
        current_interests={},
        memories=[dream_evidence],
        interest_topics=[
            {
                "topic": "playas aptas para perros",
                "exploratory_query": "Averiguar cuál es la mejor playa para llevar un perro",
            }
        ],
        summary="Verificación local de semilla onírica de curiosidad",
        weights=settings.curiosity_weights,
    )
    seed = snapshot.interests["playas aptas para perros"]
    print(
        f"Phase 3 Oneiric Seed: topic='{seed['topic']}', "
        f"status={seed['consolidation_status']}, R={seed['acquired_relevance']:.2f}, "
        f"C={seed['curiosity_score']:.4f}"
    )

    # Run mock provider verification
    if settings.provider == "mock":
        provider = MockProvider()
        test_prompt = "Initial cognitive verification ping"
        response = await provider.generate(test_prompt)
        print(f"Mock Provider Output: {response}")
    else:
        print(
            f"Note: Provider '{settings.provider}' configured. Real model calls remain disabled in Phase 3."
        )

    print("-" * 60)
    print("Local cognitive engine verified successfully.")
    print("=" * 60)
    return 0


def main() -> None:
    sys.exit(asyncio.run(run_engine()))


if __name__ == "__main__":
    main()
