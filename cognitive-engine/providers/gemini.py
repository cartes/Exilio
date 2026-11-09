"""Gemini language-model provider implementation with budget telemetry policy."""

import os
import urllib.request
import urllib.error
import json
from .base import AIProvider


class GeminiProvider(AIProvider):
    DEFAULT_MODEL = "gemini-2.5-flash-lite"
    PROMPT_TOKEN_COST_USD = 0.000000075
    COMPLETION_TOKEN_COST_USD = 0.00000030

    def __init__(
        self,
        api_key: str | None = None,
        model: str | None = None,
        monthly_budget_usd: float = 5.0,
    ) -> None:
        self.api_key = api_key or os.getenv("GEMINI_API_KEY", "")
        self.model = model or os.getenv("GEMINI_MODEL", self.DEFAULT_MODEL)
        self.monthly_budget_usd = monthly_budget_usd
        self.total_estimated_spend_usd = 0.0
        self.total_tokens_used = 0

    async def generate(self, prompt: str, *, system: str | None = None) -> str:
        """Generate text via Gemini API, enforcing budget guard and fallback."""
        if self.total_estimated_spend_usd >= self.monthly_budget_usd:
            return (
                f"[LÍMITE PRESUPUESTARIO ALCANZADO: US${self.monthly_budget_usd:.2f}/mes] "
                f"Respuesta simulada segura: {prompt}"
            )

        if not self.api_key:
            return f"[mock/offline gemini fallback] {prompt}"

        url = (
            f"https://generativelanguage.googleapis.com/v1beta/models/"
            f"{self.model}:generateContent?key={self.api_key}"
        )

        body: dict = {
            "contents": [
                {
                    "role": "user",
                    "parts": [{"text": prompt}],
                }
            ],
            "generationConfig": {
                "temperature": 0.3,
                "maxOutputTokens": 800,
            },
        }

        if system:
            body["system_instruction"] = {
                "parts": [{"text": system}],
            }

        data_bytes = json.dumps(body).encode("utf-8")
        req = urllib.request.Request(
            url,
            data=data_bytes,
            headers={"Content-Type": "application/json"},
            method="POST",
        )

        try:
            with urllib.request.urlopen(req, timeout=15) as resp:
                resp_data = json.loads(resp.read().decode("utf-8"))
                text = resp_data["candidates"][0]["content"]["parts"][0]["text"]
                usage = resp_data.get("usageMetadata", {})
                prompt_tokens = usage.get("promptTokenCount", 0)
                completion_tokens = usage.get("candidatesTokenCount", 0)
                cost = (prompt_tokens * self.PROMPT_TOKEN_COST_USD) + (
                    completion_tokens * self.COMPLETION_TOKEN_COST_USD
                )
                self.total_estimated_spend_usd += cost
                self.total_tokens_used += prompt_tokens + completion_tokens
                return text.strip()
        except Exception as err:
            return f"[Gemini API fallback por excepción: {err}] {prompt}"

