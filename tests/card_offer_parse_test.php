<?php
/**
 * Self-checks for reading cashback rates from a bank card page (CardOfferHelper::parse, no network):
 *
 *   php tests/card_offer_parse_test.php
 *
 * Exits with status 1 when any check fails.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../src/Helpers/CardCycleHelper.php';
require_once __DIR__ . '/../src/Helpers/CardOfferHelper.php';

use App\Helpers\CardOfferHelper as O;

$passed = 0;
$failed = 0;

function check(string $label, $expected, $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        echo "  ok    {$label}\n";
    } else {
        $failed++;
        echo "  FAIL  {$label}\n        expected " . var_export($expected, true) . "\n        got      " . var_export($actual, true) . "\n";
    }
}

$html = '<html><head><title>Cashback Card</title>
    <meta name="description" content="Get 5% cashback on dining and 3% cashback at supermarkets.">
    <meta property="og:image" content="https://bank.example/card.png"></head>
    <body>
      <script>var promo = "15% cashback on fuel";</script>
      <ul>
        <li>Earn 2% cash back on fuel and transport</li>
        <li>1% cashback on all other spends</li>
        <li>Foreign transaction fee 2.99%</li>
        <li>Annual interest rate 3.25% per month</li>
      </ul>
      <p>Pay 0% interest on instalments.</p>
    </body></html>';

$r = O::parse($html);
echo "page meta\n";
check('title', 'Cashback Card', $r['title']);
check('image', 'https://bank.example/card.png', $r['image']);

echo "rates\n";
check('dining → Food', 5.0, $r['rates']['Food'] ?? null);
check('supermarkets → Grocery', 3.0, $r['rates']['Grocery'] ?? null);
check('fuel → Transport', 2.0, $r['rates']['Transport'] ?? null);
check('all other spends → Other', 1.0, $r['rates']['Other'] ?? null);
check('script text ignored (no 15%)', 2.0, $r['rates']['Transport'] ?? null);
check('fee and interest lines ignored', 4, count($r['rates']));
check('offer lines kept', 3, count($r['offers']));

echo "extractRates\n";
check('highest rate per category wins', ['Food' => 10.0], O::extractRates("4% cashback on dining\n10% cashback on restaurants")['rates']);
check('unrealistic rate ignored', [], O::extractRates('Save 50% cashback on dining this weekend')['rates']);
check('no cashback words → nothing', [], O::extractRates('Dining 5% service charge applies')['rates']);

echo "\n";
if ($failed > 0) {
    echo "{$failed} failed, {$passed} passed\n";
    exit(1);
}
echo "OK ({$passed} checks)\n";
