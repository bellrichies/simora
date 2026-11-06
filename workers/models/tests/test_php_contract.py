import json
from pathlib import Path
import shutil
import subprocess
import unittest

from simora_models.distribution import MARKETS


class PhpMarketContractTests(unittest.TestCase):
    @unittest.skipUnless(shutil.which("php"), "PHP CLI required for cross-language contract")
    def test_python_matches_canonical_php_market_registry(self):
        root = Path(__file__).resolve().parents[3]
        response = subprocess.run([
            "php", "-r", "require $argv[1]; echo json_encode(array_keys(App\\Domain\\MarketCatalog::all()));",
            str(root / "src" / "Domain" / "MarketCatalog.php")
        ], check=True, capture_output=True, text=True)
        self.assertEqual(set(json.loads(response.stdout)), set(MARKETS))
