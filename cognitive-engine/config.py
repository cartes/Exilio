"""Environment-backed configuration for the cognitive engine."""

from dataclasses import dataclass
import os


@dataclass(frozen=True)
class CuriosityWeights:
    novelty: float = 0.30
    uncertainty: float = 0.25
    learning: float = 0.30
    relevance: float = 0.15

    def __post_init__(self) -> None:
        values = (self.novelty, self.uncertainty, self.learning, self.relevance)
        if any(value < 0 for value in values):
            raise ValueError("Curiosity weights must be non-negative")
        if abs(sum(values) - 1.0) > 1e-9:
            raise ValueError("Curiosity weights must sum to 1")


@dataclass(frozen=True)
class Settings:
    provider: str = "mock"
    monthly_budget_usd: float = 5.0
    curiosity_weights: CuriosityWeights = CuriosityWeights()

    @classmethod
    def from_env(cls) -> "Settings":
        weights = CuriosityWeights(
            novelty=float(os.getenv("CURIOSITY_WEIGHT_NOVELTY", "0.30")),
            uncertainty=float(os.getenv("CURIOSITY_WEIGHT_UNCERTAINTY", "0.25")),
            learning=float(os.getenv("CURIOSITY_WEIGHT_LEARNING", "0.30")),
            relevance=float(os.getenv("CURIOSITY_WEIGHT_RELEVANCE", "0.15")),
        )
        budget = float(os.getenv("AI_MONTHLY_BUDGET_USD", "5.00"))
        if budget < 0:
            raise ValueError("Monthly AI budget must be non-negative")
        return cls(
            provider=os.getenv("AI_PROVIDER", "mock"),
            monthly_budget_usd=budget,
            curiosity_weights=weights,
        )
