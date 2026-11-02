"""Provider-neutral asynchronous language-model contract."""

from abc import ABC, abstractmethod


class AIProvider(ABC):
    @abstractmethod
    async def generate(self, prompt: str, *, system: str | None = None) -> str:
        """Generate a text response. Implementations must enforce budget policy."""
        raise NotImplementedError
