"""Anthropic Claude language-model provider implementation with budget telemetry policy."""

import os
import urllib.request
import urllib.error
import json
from .base import AIProvider


class ClaudeProvider(AIProvider):
    DEFAULT_MODEL = "claude-haiku-5-5"
    PROMPT_TOKEN_COST_USD = 0.00000010
    COMPLETION_TOKEN_COST_USD = 0.00000050

    def __init__(
        self,
        api_key: str | None = None,
        model: str | None = None,
        monthly_budget_usd: float = 5.0,
    ) -> None:
        self.api_key = api_key or os.getenv("ANTHROPIC_API_KEY", "")
        self.model = model or os.getenv("ANTHROPIC_MODEL", self.DEFAULT_MODEL)
        self.monthly_budget_usd = monthly_budget_usd
        self.total_estimated_spend_usd = 0.0
        self.total_tokens_used = 0

    async def generate(self, prompt: str, *, system: str | None = None) -> str:
        """Generate text via Anthropic Messages API, enforcing budget guard and fallback."""
        if self.total_estimated_spend_usd >= self.monthly_budget_usd:
            return (
                f"[LÍMITE PRESUPUESTARIO ALCANZADO: US${self.monthly_budget_usd:.2f}/mes] "
                f"Respuesta simulada segura: {prompt}"
            )

        if not self.api_key:
            return f"[mock/offline claude fallback] {prompt}"

        url = "https://api.anthropic.com/v1/messages"

        body: dict = {
            "model": self.model,
            "max_tokens": 800,
            "messages": [
                {
                    "role": "user",
                    "content": prompt,
                }
            ],
        }

        if system:
            body["system"] = system

        data_bytes = json.dumps(body).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=data_bytes,
            headers={
                "Content-Type": "application/json",
                "x-api-key": self.api_key,
                "anthropic-version": "2023-06-01",
            },
            method="POST",
        )

        try:
            with urllib.request.urlopen(req, timeout=20) as resp:
                resp_data = json.loads(resp.read().decode("utf-8"))
                text = resp_data["content"][0]["text"]
                usage = resp_data.get("usage", {})
                prompt_tokens = usage.get("input_tokens", 0)
                completion_tokens = usage.get("output_tokens", 0)
                cost = (prompt_tokens * self.PROMPT_TOKEN_COST_USD) + (
                    completion_tokens * self.COMPLETION_TOKEN_COST_USD
                )
                self.total_estimated_spend_usd += cost
                self.total_tokens_used += prompt_tokens + completion_tokens
                return text.strip()
        except Exception as err:
            return f"[Anthropic Claude API fallback por excepción: {err}] {prompt}"

