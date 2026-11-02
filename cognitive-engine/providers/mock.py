"""Deterministic provider for local development and tests; performs no network IO."""

from .base import AIProvider


class MockProvider(AIProvider):
    async def generate(self, prompt: str, *, system: str | None = None) -> str:
        return f"[mock response] {prompt}"
