import math
import unittest

from config import CuriosityWeights
from curiosity import CuriositySignals, curiosity_score


class CuriosityScoreTests(unittest.TestCase):
    def test_all_zero_is_zero(self):
        self.assertEqual(curiosity_score(CuriositySignals(0, 0, 0, 0)), 0)

    def test_all_one_is_one(self):
        self.assertEqual(curiosity_score(CuriositySignals(1, 1, 1, 1)), 1)

    def test_default_formula(self):
        signals = CuriositySignals(1, 0, 0.5, 1)
        self.assertAlmostEqual(curiosity_score(signals), 0.60)

    def test_custom_weights(self):
        weights = CuriosityWeights(0.5, 0.2, 0.2, 0.1)
        signals = CuriositySignals(1, 0, 0, 0)
        self.assertAlmostEqual(curiosity_score(signals, weights), 0.5)

    def test_rejects_out_of_range_and_non_finite_signals(self):
        for value in (-0.01, 1.01, math.nan, math.inf):
            with self.subTest(value=value), self.assertRaises(ValueError):
                CuriositySignals(value, 0, 0, 0)

    def test_weights_must_sum_to_one(self):
        with self.assertRaises(ValueError):
            CuriosityWeights(0.3, 0.3, 0.3, 0.3)


if __name__ == "__main__":
    unittest.main()
