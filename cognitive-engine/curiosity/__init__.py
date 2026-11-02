"""Configurable, pure curiosity scoring function."""

from dataclasses import dataclass
import math

from config import CuriosityWeights


@dataclass(frozen=True)
class CuriositySignals:
    novelty: float
    uncertainty: float
    learning: float
    relevance: float

    def __post_init__(self) -> None:
        for name, value in vars(self).items():
            if not math.isfinite(value) or not 0.0 <= value <= 1.0:
                raise ValueError(f"{name} must be finite and normalized to [0, 1]")


def curiosity_score(
    signals: CuriositySignals,
    weights: CuriosityWeights = CuriosityWeights(),
) -> float:
    """Calculate C = .30N + .25I + .30A + .15R with configurable weights."""
    return (
        weights.novelty * signals.novelty
        + weights.uncertainty * signals.uncertainty
        + weights.learning * signals.learning
        + weights.relevance * signals.relevance
    )
