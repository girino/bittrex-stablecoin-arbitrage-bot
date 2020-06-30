# bittrex-stablecoin-arbitrage-bot

This project is a port to php and bittrex of my previous bot that worked on binance and ran on nodejs: https://github.com/girino/TUSD-USDT-Infinity-Profit-Bot

This one has also some imporvements, such as measurement of best markets and best parameter per market.

Rename the ```config-sample.php``` to ```config.php```, fill in your API key and run with ```php main.php```.

PHP was not a choice, but rather an imposition because existing bittrex API client implementations in both python ans nodejs (my first and second choices) were abandoned for more than 2 years. This php client from "codenix-sv" seems to be well maintained.

some fun parameters to try:

```php main.php -c```

Cancels all open orders

```php main.php -s```

Searches for the most profitable parameters for a single market (by default, the one the config file).

```php main.php -b```

Searches for the most profitable parameters for all markets that have the current price between 0.98 and 1.02. (beware, not all of them are stablecoins. make sure you use only with stablecoins)

Both the ```-b``` and ```-s``` accpet the following params:

```--interval```: the time interval for the candles to be analized (can be 'm', '5m', 'h' or 'd', meaning 1 minute, 5 minutes, 1 hour and 1 day respectively).

```--min-price```: the minimum price to search (defaults to 0.98)

```--max-price```: the maximum price to search (defaults to 1.02)

```--step```: the increment on the values searched (defaults to 0.0001)

```--market```: (only in ```-s```) searches a different market from the one in the config file.
