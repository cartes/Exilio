import asyncio
import unittest

from providers.base import AIProvider
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


if __name__ == "__main__":
    unittest.main()

