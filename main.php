<?php
require_once('vendor/autoload.php');
use R3bers\BittrexApi\BittrexClient;
use R3bers\BittrexApi\Api\Api;
use GuzzleHttp\Client;
use ProgressBar\Manager;


require_once('config.php');

class V3Api extends Api {

    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    public function getv3(string $uri, array $query = []): array
    {
        $response = $this->client->request('GET', '/v3/'
            . $uri, ['query' => $query]);

        return $this->transformer->transform($response);
    }

    public function getCandles($market, $interval): array
    {
        return $this->getv3('/markets/' . $market . '/candles/' . $interval . '/recent');
        // return $this->getv3('/markets/' . $market . '/candles/HOUR_1/historical/2020/04/01');
        // return $this->getv3('/markets/' . $market . '/candles/HOUR_1/historical/2020/03/01');
        // return $this->getv3('/markets/' . $market . '/candles/HOUR_1/historical/2020/02/01');
        // return $this->getv3('/markets/' . $market . '/candles/HOUR_1/historical/2020/01/01');
    }
    public function getMarketSummaries(): array
    {
        return $this->getv3('/markets/summaries');
    }
}

class MyBittrexClient extends BittrexClient {
    private $publicClient;
    private function createPublicClient(): Client
    {
        return new Client(['base_uri' => 'https://api.bittrex.com']);
    }
    private function getPublicClient(): Client
    {
        return $this->publicClient ?: $this->createPublicClient();
    }
    public function v3Api(): V3Api
    {
        return new V3Api($this->getPublicClient());
    }

}

$client = new MyBittrexClient();
$client->setCredential($config['API_KEY'], $config['API_SECRET']);

function singleMarket($market, $buyPrice, $sellPrice) {
    global $client;
    $candles = $client->v3Api()->getCandles($market, 'HOUR_1');
    return calculate($candles, $buyPrice, $sellPrice);
}

function calculate($candles, $buyPrice, $sellPrice) {

    $val = 1.0;
    $count = 0;
    $state = 'BUY';
    foreach($candles as $candle) {
        if ($state == 'BUY' && $candle['low'] < $buyPrice) {
            $state = 'SELL';
            $count++;
            $val /= $buyPrice;
            $val *= (1.0 - 0.0020);
        } else if ($state == 'SELL' && $candle['high'] > $sellPrice) {
            $state = 'BUY';
            $count++;
            $val *= $sellPrice;
            $val *= (1.0 - 0.0020);
        }
    }
    return array($val, $count);
}

function getBestSingleMarket($symbol, $interval, $minPrice, $maxPrice, $step, $progress=true) {
    global $client;
    $candles = $client->v3Api()->getCandles($symbol, $interval);
    $maxVal = 0.0;
    $maxState = array(0,0,0,0);
    if ($progress) { $progressBar = new Manager(0, -intval($minPrice*10e8)+intval($maxPrice*10e8)); }
    for ($buyPrice = $maxPrice; $buyPrice > $minPrice; $buyPrice -= $step) {
        if ($progress) { $progressBar->update(intval(($maxPrice-$buyPrice)*10e8)); }
        for ($sellPrice = $buyPrice + $step; $sellPrice < $maxPrice; $sellPrice += $step) {
            $retArray = calculate($candles, $buyPrice, $sellPrice);
            $val = $retArray[0]; $count = $retArray[1];
            if ($val > $maxVal) {
                $maxVal = $val;
                $maxState = array($buyPrice, $sellPrice, $val, $count, $symbol);
            }
        }
    }
    return $maxState;
}

function printMarket($marketArray, $volume = false) {
    print($marketArray[4] . ($volume?" (vol: " . $volume . ")":"") . ":\n");
    $maxStateVal = "    [" . $marketArray[0] . ', ' . $marketArray[1] . ']: ' . (($marketArray[2]-1)*100) . " (" . $marketArray[3] . ")\n";
    print ($maxStateVal);
}


function printBestMarkets($interval, $minPrice = 0.98, $maxPrice = 1.02, $step=0.0001) {

    global $client;
    $data = $client->v3Api()->getmarketsummaries();
    foreach($data as $result) {
        $mid = ($result['high']+$result['low'])/2.0;
        if ($mid > $minPrice && $mid < $maxPrice) {
            printMarket(getBestSingleMarket($result['symbol'], $interval, $minPrice, $maxPrice, $step, false), $result['volume']);
        }
    }

}

function cancelAllOrders() {
    global $client;
    $orders = $client->market()->getOpenOrders();
    // cancel old orders
    foreach($orders as $order) {
        printf("Canceling uuid: %s... ", $order['id']);
        $client->market()->cancel($order['id']);
        printf("Done.\n");
    }
}

function getInterval($options) {
    global $client;
    $interval = array_key_exists("interval", $options) ? $options['interval'] : 'h';
    $interval = strtolower($interval);
    $values_map = array(
        'm' => 'MINUTE_1',
        '5m' => 'MINUTE_5',
        'h' => 'HOUR_1',
        'd' => 'DAY_1',
    );
    if (!array_key_exists($interval, $values_map)) {
        printf('ERROR: no interval for %s\n', $interval);
        exit(1);
    }
    return $values_map[$interval];
}
function getMinPrice($options) {
    global $config;
    return array_key_exists("min-price", $options) ? floatval($options['min-price']) : $config['BUY_PRICE'];
}

function getMaxPrice($options) {
    global $config;
    return array_key_exists("max-price", $options) ? floatval($options['max-price']) : $config['SELL_PRICE'];
}

function getStep($options) {
    return array_key_exists("step", $options) ? floatval($options['step']) : 0.0001;
}

function getMarketName($options) {
    global $config;
    return array_key_exists("market", $options) ? $options['market'] : $config['BASE_CURRENCY'] . '-' . $config['MARKET_CURRENCY'];
}

function getResult($value) {
    // useless now
    return $value;
}

// main
if (php_sapi_name() == "cli") {
    $options = getopt("abcns", ['auto', 'single', 'best', 'cancel', 'no-cancel', 'min-price:', 'max-price:', 'interval:', 'step:', 'market:']);
    if (array_key_exists("b", $options) || array_key_exists("best", $options)) {
        $interval = getInterval($options);
        $min = getMinPrice($options);
        $max = getMaxPrice($options);
        $step = getStep($options);
        printf("Searching best markets for interval '%s', and prices from %f to %f with step %f\n", $interval, $min, $max, $step);
        printBestMarkets($interval, $min, $max, $step);
        exit(0);
    }
    if (array_key_exists("s", $options) || array_key_exists("single", $options)) {
        $interval = getInterval($options);
        $min = getMinPrice($options);
        $max = getMaxPrice($options);
        $step = getStep($options);
        $market = getMarketName($options);
        printf("Searching %s for interval '%s', and prices from %f to %f with step %f\n", $market, $interval, $min, $max, $step);
        printMarket(getBestSingleMarket($market, $interval, $min, $max, $step));
        exit(0);
    }
    if (array_key_exists("c", $options) || array_key_exists("cancel", $options)) {
        cancelAllOrders();
        exit(0);
    }

    // reads all params here
    $buyPrice = getMinPrice($options);
    $sellPrice = getMaxPrice($options);
    $capital = $config['CAPITAL'];
    $baseCurrency = $config['BASE_CURRENCY'];
    $marketCurrency = $config['MARKET_CURRENCY'];

    // if auto
    if (array_key_exists("a", $options) || array_key_exists("auto", $options)) {
        $interval = getInterval($options);
        $min = getMinPrice($options);
        $max = getMaxPrice($options);
        $step = getStep($options);
        $market = getMarketName($options);
        printf("Searching %s for interval '%s', and prices from %f to %f with step %f\n", $market, $interval, $min, $max, $step);
        $maxState = getBestSingleMarket($market, $interval, $min, $max, $step);
        printMarket($maxState);
        $buyPrice = $maxState[0];
        $sellPrice = $maxState[1];
    }

    // cancel by default unless n or no-cancel is specified
    if (!array_key_exists("n", $options) && !array_key_exists("no-cancel", $options)) {
        cancelAllOrders();
    } else {
        printf("Not cancelling orders.\n");
    }

    $marketName = $baseCurrency . '-' . $marketCurrency;
    // market infor
    while (true) {
        try {
            $markets = getResult($client->public()->getMarkets());
            $marketParams = array();
            foreach($markets as $m) {
                if ($m['symbol'] == $marketName) {
                    $marketParams = $m;
                break;
                }
            }
            break;
        } catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            echo 'Sleeping 30 seconds... \n';
            sleep(30);
        }
    }

    while (true) {
        // system('cls');
        try {
            $marketSummary = getResult($client->public()->getMarketSummary($marketName));
            $ticker = $client->public()->getTicker($marketName);
            $balanceBaseCurrency = getResult($client->account()->getBalance($baseCurrency));
            $balanceMarketCurrency = getResult($client->account()->getBalance($marketCurrency));
            $total = $balanceMarketCurrency['total'] + $balanceBaseCurrency['total'];
            $lucro = $balanceMarketCurrency['total'] + $balanceBaseCurrency['total'] - $capital;
            $orders = getResult($client->market()->getOpenOrders());
            system('clear');
            printf("==========================================\n");
            printf("SALDO %4s...: %14.8f (%14.8f disponivel)\n", $marketCurrency, $balanceMarketCurrency['total'], $balanceMarketCurrency['available']);
            printf("SALDO %4s...: %14.8f (%14.8f disponivel)\n", $baseCurrency, $balanceBaseCurrency['total'], $balanceBaseCurrency['available']);
            printf("SALDO TOTAL..: %14.8f %s\n", $total, $baseCurrency);
            printf("SALDO INICIAL: %14.8f %s\n", $capital, $baseCurrency);
            printf("LUCRO........: %14.8f %s\n", $lucro, $baseCurrency);
            printf("%s\n", date('Y-m-d H:i:s T', time()));
            printf("==========================================\n");
            printf("Cotação     %s %10.8f\n", $marketName, $ticker['lastTradeRate']);
            printf("Preço Medio %s %10.8f\n", $marketName, $marketSummary['quoteVolume']/$marketSummary['volume']);
            printf("Definidos: compra %10.8f e venda %10.8f\n", $buyPrice, $sellPrice);
            printf("============== Open Orders ===============\n");
            foreach($orders as $order) {
                $type = $order['direction'];
                $price = $order['limit'];
                printf("%s %s %14.8f (of %14.8f) @ %14.8f\n", substr($order['createdAt'], 0, 10), $type, $order['fillQuantity'], $order['quantity'], $price);
            }
            printf("============== Order History =============\n");
            $orderHistory = $client->account()->getOrderHistory($marketName);
            if (count($orderHistory) > 5) {
                $orderHistory = array_slice($orderHistory, 0, 5);
            }
            foreach($orderHistory as $order) {
                $type = $order['direction'];
                $price = $order['limit'];
                $commission = ($order['direction'] == 'SELL') ? - $order['commission'] : $order['commission'];
                $effectivePrice = ($order['proceeds'] + $commission)/$order['quantity'];
                printf("%s %s %14.8f (of %14.8f) @ %.8f (%.8f)\n", substr($order['closedAt'], 0, 10), $type, $order['fillQuantity'], $order['quantity'], $price, $effectivePrice);
                // print_r($order);
            }
            // printf("==========================================\n");

            // open orders with new balance
            $fee = 0.0075;
            $amount = round($balanceMarketCurrency['available']*(1.0-$fee), 8, PHP_ROUND_HALF_DOWN);
            if ($amount > $marketParams['minTradeSize']) {
                printf("BUYING %.8f at %.8f\n",$amount, $buyPrice);
                print_r($client->market()->buyLimit($marketName, $amount, $buyPrice));
            }
            if ($balanceBaseCurrency['available'] > $marketParams['minTradeSize']) {
                printf("SELLING %.8f at %.8f\n", $balanceBaseCurrency['available'], $sellPrice);
                print_r($client->market()->sellLimit($marketName, $balanceBaseCurrency['available'], $sellPrice));
            }

            sleep(10);
        } catch (Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
            echo 'Sleeping 30 seconds... \n';
            sleep(30);
        }
    }
}
?>