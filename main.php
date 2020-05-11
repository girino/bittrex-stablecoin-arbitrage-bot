<?php
require_once('vendor/autoload.php');
use Codenixsv\BittrexApi\BittrexClient;
use Codenixsv\BittrexApi\Api\Api;
use GuzzleHttp\Client;

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

    public function getCandles($market): array
    {
        return $this->getv3('/markets/' . $market . '/candles/HOUR_1/recent');
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
    $candles = $client->v3Api()->getCandles($market);
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

function getBestMarkets() {

    global $client;
    $markets = array();

    $data = $client->v3Api()->getmarketsummaries();
    foreach($data as $result) {
        $mid = ($result['high']+$result['low'])/2.0;
        if ($mid > 0.98 && $mid < 1.02) {
            // print($result['symbol'] . ": " . $mid . "\n");
            array_push($markets, $result);
        }
    }

    foreach($markets as $market) {
        $candles = $client->v3Api()->getCandles($market['symbol']);
        print($market['symbol'] . ":\n");
        $maxVal = 0.0;
        $maxStateVal = 'No profit';
        for ($buyPrice = 1.02; $buyPrice > 0.98; $buyPrice -= 0.0001) {
            for ($sellPrice = $buyPrice + 0.0001; $sellPrice < 1.02; $sellPrice += 0.0001) {
                $retArray = calculate($candles, $buyPrice, $sellPrice);
                $val = $retArray[0]; $count = $retArray[1];
                if ($val > $maxVal) {
                    $maxStateVal = "    [" . $buyPrice . ', ' . $sellPrice . ']: ' . (($val-1)*100) . " (" . $count . ")\n";
                    $maxVal = $val;
                }
            }
        }
        print ($maxStateVal);
    }
}

// main
if (php_sapi_name() == "cli") {
    $options = getopt("b", ['best']);
    if (array_key_exists("b", $options) || array_key_exists("best", $options)) {
        getBestMarkets();
        exit(0);
    }
    $marketName = $config['BASE_CURRENCY'] . '-' . $config['MARKET_CURRENCY'];
    $balanceBaseCurrency = $client->account()->getBalance($config['BASE_CURRENCY'])['result'];
    $balanceMarketCurrency = $client->account()->getBalance($config['MARKET_CURRENCY'])['result'];
    // market infor
    $markets = $client->public()->getMarkets()['result'];
    $marketParams = array();
    foreach($markets as $m) {
        if ($m['MarketName'] == $marketName) {
            $marketParams = $m;
        break;
        }
    }
    print_r($marketParams);
    while (true) {
        // system('cls');
        try {
            printf("============ Starting new Cycle ==========\n");
            $marketSummary = $client->public()->getMarketSummary($marketName)['result'];
            $balanceBaseCurrency = $client->account()->getBalance($config['BASE_CURRENCY'])['result'];
            $balanceMarketCurrency = $client->account()->getBalance($config['MARKET_CURRENCY'])['result'];
            $total = $balanceMarketCurrency['Balance'] + $balanceBaseCurrency['Balance'];
            $lucro = $balanceMarketCurrency['Balance'] + $balanceBaseCurrency['Balance'] - $config['CAPITAL'];
            $orders = $client->market()->getOpenOrders()['result'];
            $orderHistory = $client->account()->getOrderHistory($marketName)['result'];
            system('clear');
            printf("==========================================\n");
            printf("SALDO %4s...: %14.8f (%14.8f + %14.8f)\n", $config['MARKET_CURRENCY'], $balanceMarketCurrency['Balance'], $balanceMarketCurrency['Available'], $balanceMarketCurrency['Pending']);
            printf("SALDO %4s...: %14.8f (%14.8f + %14.8f)\n", $config['BASE_CURRENCY'], $balanceBaseCurrency['Balance'], $balanceBaseCurrency['Available'], $balanceBaseCurrency['Pending']);
            printf("SALDO TOTAL..: %14.8f %s\n", $total, $config['BASE_CURRENCY']);
            printf("SALDO INICIAL: %14.8f %s\n", $config['CAPITAL'], $config['BASE_CURRENCY']);
            printf("LUCRO........: %14.8f %s\n", $lucro, $config['BASE_CURRENCY']);
            printf("==========================================\n");
            printf("Cotação     %s %10.8f\n", $marketName, $marketSummary[0]['Last']);
            printf("Preço Medio %s %10.8f\n", $marketName, $marketSummary[0]['BaseVolume']/$marketSummary[0]['Volume']);
            printf("Definidos: compra %10.8f e venda %10.8f\n", $config['BUY_PRICE'], $config['SELL_PRICE']);
            printf("============== Open Orders ===============\n");
            // cancel unmatching orders
            foreach($orders as $order) {
                $type = ($order['OrderType'] == 'LIMIT_SELL') ? 'SELL' : ' BUY';
                $price = $order['PricePerUnit'] > 0 ? $order['PricePerUnit'] : $order['Limit'];
                printf("%s %s %14.8f (was %14.8f) @ %14.8f\n", substr($order['Opened'], 0, 10), $type, $order['QuantityRemaining'], $order['Quantity'], $price);
                // print_r($order);
                if ($order['Exchange'] != $marketName) {
                    print_r($client->market()->cancel($order['uuid']));
                }
            }
            printf("============== Order History =============\n");
            // cancel unmatching orders
            foreach($orderHistory as $order) {
                $type = ($order['OrderType'] == 'LIMIT_SELL') ? 'SELL' : ' BUY';
                $price = $order['PricePerUnit'] > 0 ? $order['PricePerUnit'] : $order['Limit'];
                printf("%s %s %14.8f (was %14.8f) @ %14.8f\n", substr($order['Closed'], 0, 10), $type, $order['QuantityRemaining'], $order['Quantity'], $price);
                // print_r($order);
            }
            printf("==========================================\n");

            // cancel old orders
            foreach($orders as $order) {
                if ($order['Exchange'] != $marketName) {
                    print_r($client->market()->cancel($order['uuid']));
                }
            }
            // open orders with new balance
            $amount = round($balanceBaseCurrency['Available']/$config['BUY_PRICE']*(1.0-0.002), 8, PHP_ROUND_HALF_DOWN);
            if ($amount > $marketParams['MinTradeSize']) {
                printf("BUYING %.8f at %.8f\n",$amount, $config['BUY_PRICE']);
                print_r($client->market()->buyLimit($marketName, $amount, $config['BUY_PRICE']));
            }
            if ($balanceMarketCurrency['Available'] > $marketParams['MinTradeSize']) {
                printf("SELLING %.8f at %.8f\n", $balanceMarketCurrency['Available'], $config['SELL_PRICE']);
                print_r($client->market()->sellLimit($marketName, $balanceMarketCurrency['Available'], $config['SELL_PRICE']));
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