import os
import unittest
from unittest.mock import patch

from config import CuriosityWeights, Settings


class ConfigTests(unittest.TestCase):
    def test_curiosity_weights_defaults(self):
        weights = CuriosityWeights()
        self.assertEqual(weights.novelty, 0.30)
        self.assertEqual(weights.uncertainty, 0.25)
        self.assertEqual(weights.learning, 0.30)
        self.assertEqual(weights.relevance, 0.15)

    def test_curiosity_weights_negative_rejected(self):
        with self.assertRaises(ValueError):
            CuriosityWeights(novelty=-0.1, uncertainty=0.4, learning=0.4, relevance=0.3)

    def test_curiosity_weights_sum_not_one_rejected(self):
        with self.assertRaises(ValueError):
            CuriosityWeights(novelty=0.5, uncertainty=0.5, learning=0.1, relevance=0.0)

    def test_settings_defaults_from_empty_env(self):
        with patch.dict(os.environ, {}, clear=True):
            settings = Settings.from_env()
            self.assertEqual(settings.provider, "mock")
            self.assertEqual(settings.monthly_budget_usd, 5.0)
            self.assertEqual(settings.curiosity_weights, CuriosityWeights())

    def test_settings_custom_from_env(self):
        env = {
            "AI_PROVIDER": "mock-custom",
            "AI_MONTHLY_BUDGET_USD": "10.00",
            "CURIOSITY_WEIGHT_NOVELTY": "0.40",
            "CURIOSITY_WEIGHT_UNCERTAINTY": "0.20",
            "CURIOSITY_WEIGHT_LEARNING": "0.20",
            "CURIOSITY_WEIGHT_RELEVANCE": "0.20",
        }
        with patch.dict(os.environ, env, clear=True):
            settings = Settings.from_env()
            self.assertEqual(settings.provider, "mock-custom")
            self.assertEqual(settings.monthly_budget_usd, 10.0)
            self.assertEqual(
                settings.curiosity_weights,
                CuriosityWeights(0.40, 0.20, 0.20, 0.20),
            )

    def test_settings_negative_budget_rejected(self):
        with patch.dict(os.environ, {"AI_MONTHLY_BUDGET_USD": "-1.0"}, clear=True):
            with self.assertRaises(ValueError):
                Settings.from_env()


if __name__ == "__main__":
    unittest.main()

