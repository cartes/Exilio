import asyncio
import unittest

from providers.base import AIProvider
from providers.gemini import GeminiProvider
from providers.claude import ClaudeProvider
from providers.mock import MockProvider


class ProvidersTests(unittest.TestCase):
    def test_cannot_instantiate_abstract_provider(self):
        with self.assertRaises(TypeError):
            AIProvider()

    def test_mock_provider_deterministic_response(self):
        provider = MockProvider()
        response = asyncio.run(provider.generate("Hello Exilio"))
        self.assertEqual(response, "[mock response] Hello Exilio")

    def test_mock_provider_with_system_prompt(self):
        provider = MockProvider()
        response = asyncio.run(
            provider.generate("Input text", system="System instructions")
        )
        self.assertEqual(response, "[mock response] Input text")

    def test_gemini_provider_fallback_without_api_key(self):
        provider = GeminiProvider(api_key="")
        response = asyncio.run(provider.generate("Ping without key"))
        self.assertIn("[mock/offline gemini fallback]", response)

    def test_gemini_provider_budget_guard_enforced(self):
        provider = GeminiProvider(api_key="fake-key", monthly_budget_usd=5.0)
        provider.total_estimated_spend_usd = 5.01
        response = asyncio.run(provider.generate("Ping over budget"))
        self.assertIn("[LÍMITE PRESUPUESTARIO ALCANZADO", response)

    def test_claude_provider_fallback_without_api_key(self):
        provider = ClaudeProvider(api_key="")
        response = asyncio.run(provider.generate("Ping without key"))
        self.assertIn("[mock/offline claude fallback]", response)

    def test_claude_provider_budget_guard_enforced(self):
        provider = ClaudeProvider(api_key="fake-key", monthly_budget_usd=5.0)
        provider.total_estimated_spend_usd = 5.01
        response = asyncio.run(provider.generate("Ping over budget"))
        self.assertIn("[LÍMITE PRESUPUESTARIO ALCANZADO", response)
        self.assertEqual(provider.model, "claude-haiku-5-5")


if __name__ == "__main__":
    unittest.main()

