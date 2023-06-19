Checkout class PoC
========================

The class implements a simple Proof of Concept for a checkout system that handles pricing schemes such as "apples cost 50 cents, three apples cost $1.30."

The class allows the user to 

  - define multiple pricing rules based on [SKU, quantity];
    - ingest rules as a single string (with customizable rule and field delimiters)
    - ingest rules as array of single-string rules
    - ingest rules as array of structured rules (`['sku' => ..., 'quantity' => ..., 'price' => ...]`)
    - add custom SKU rules as closures (`function(int $quantity): int`)
  - define general total modifiers as closures (`function(int $total, array $items ): int`)
  - compute the total

The class throws specific exceptions in case of invalid configuration, invalid rules or inconsistent cart.

Examples of use
---------------

```php
// declare usage of needed classes.

use giusepperoccazzella\Checkout;
use giusepperoccazzella\exceptions\CheckoutException;
```

```php
// define SKU rules as a string (with default delimiters).
$rules = <<<RULES
    A|10;
    A|20|3;
    A|40|5;
    A|80|10;
    A|150|20;
    B|5;
    B|7|2;
    C|10;
    C|20|3;
    B|15|4;
RULES;

$checkout = new Checkout($rules);

// enable rules validation and validate the rules
// (if not called explicitly, rules are built when adding the first item.
$checkout->withValidation()->buildRules();
```

```php
// define SKU rules as a string (with custom delimiters).
$rules = <<<RULES
    A;10
    A;20;3
    A;40;5
    A;80;10
    A;150;20
    B;5
    B;7;2
    C;10
    C;20;3
    B;15;4
RULES;
$checkout = new Checkout($rules, PHP_EOL, ";");
```

```php
// define SKU rules as a array.
$rules = [
    ["sku" => "A", "price" => 10],
    ["sku" => "A", "price" => 20, "quantity" => 3],
    ["sku" => "A", "price" => 40, "quantity" => 5],
    ["sku" => "A", "price" => 80, "quantity" => 10],
    ["sku" => "A", "price" => 150, "quantity" => 20],
    ["sku" => "B", "price" => 5],
    ["sku" => "B", "price" => 7, "quantity" => 2],
    ["sku" => "C", "price" => 10],
    ["sku" => "C", "price" => 20, "quantity" => 3],
    ["sku" => "B", "price" => 15, "quantity" => 4],
];
$checkout = new Checkout($rules, PHP_EOL, ";");
```

```php
// add simple SKU rules via chainable methods.
$checkout = new Checkout([])
    ->addRule("A", 10)
    ->addRule("A", 20, 3)
    ->addRule("A", 40, 5)
    ->addRule("A", 80, 10)
    ->addRule("A", 150, 20)
    ->addRule("B", 5)
    ->addRule("B", 7, 2)
    ->addRule("C", 10)
    ->addRule("C", 20, 3)
    ->addRule("B", 15, 4);
```

```php
// add complex SKU rules as callbacks.
$checkout->addCustomRule('A', function(int $qty): int {
    if ($qty >= 100) {
        return (int) $qty * 1;
    } elseif ($qty < 100 && $qty >= 50) {
        return (int) $qty * 3;
    } elseif ($qty < 50 && $qty >= 10) {
        return (int) $qty * 10;
    } else {
        return (int) $qty * 20;
    }
});
$checkout->addCustomRule('B', function(int $qty): int {
    if ($qty >= 50) {
        return (int) $qty * 5;
    } elseif ($qty < 50 && $qty >= 10) {
        return (int) $qty * 25;
    } else {
        return (int) $qty * 50;
    }
});
```

```php
// add general checkout total modifiers as callbacks
// (callbacks are processed in order of creation).

// - 30% discount on cart total > 500
// - 15% discount on cart total > 250
$checkout->addTotalModifier(function(int $total, array $items): int {
    if ($total > 500) {
        $total = (int) ($total * 0.70);
    } elseif ($total > 250) {
        $total = (int) ($total * 0.85);
    }
    return $total;
});

// - fixed discount of 20 on total > 100 if at least 6 B items are present
// - fixed discount of 5 on total > 10 if at least a B and a C items are present
$checkout->addTotalModifier(function(int $total, array $items): int {
    if (array_key_exists('B', $items) && (int) $items['B'] > 5 && $total > 100) {
        $total = (int) ($total - 20);
    }
    
    if (array_key_exists('B', $items) && array_key_exists('C', $items) && $total > 10) {
        $total = (int) ($total - 5);
    }
    return $total;
});
```

```php
// add items, in any order
$checkout
  ->add('A')
  ->add('B', 2)
  ->add('C', 5)
  ->add('A', 2)
  ->add('C')
```

```php
// and/or add multiple items
$items = [
    'A',
    'C',
    ['A', 3],
    ['B', 2],
    ['C'],
    ['A', 1]
];
$checkout->addMultiple($items)->add('B', 3);
```

```php
// get the total!
try {
    $cartTotal = $checkout->total();
} catch(CheckoutException $ex) {
    $myLogger.error("Imposible to get checkout total: " . $ex->getMessage());
}
```

Running tests
---------------

```bash
# run composer
composer install

# run the tests with default parameters 
./run-tests.sh

# or with custom parameters
php ./vendor/bin/phpunit --display-warnings
```
