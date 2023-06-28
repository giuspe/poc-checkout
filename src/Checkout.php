<?php

namespace giusepperoccazzella;

use giusepperoccazzella\exceptions\InvalidRuleException;
use giusepperoccazzella\exceptions\InvalidConfigException;
use giusepperoccazzella\exceptions\InvalidItemException;

class Checkout
{
    public const DEFAULT_RULE_DELIMITER = ';';
    public const DEFAULT_FIELD_DELIMITER = '|';

    protected string $ruleDelimiter;
    protected string $fieldDelimiter;
    protected bool $validateRules;

    protected ?array $rules = NULL;
    protected array $unparsedRules = [];
    protected array $checkoutRules = [];
    protected array $items = [];

    /**
     * Builds a checkout from a set of rules,
     * @param string|array $rules String or array with a set of checkout rules.
     * @param string $ruleDelimiter Char or string used as rule delimiters (if rules are in single string form).
     * @param string $fieldDelimiter Char or string used as field delimiter within rules.
     * @throws \Exception Could not parse a valid set of rules.
     */

    public function __construct(string | array $rules, string $ruleDelimiter = self::DEFAULT_RULE_DELIMITER, string $fieldDelimiter = self::DEFAULT_FIELD_DELIMITER)
    {
        if ($ruleDelimiter != PHP_EOL) {
            $ruleDelimiter = trim($ruleDelimiter);
        }
        if (strlen($ruleDelimiter) == 0) {
            throw new InvalidConfigException("Rule delimiter string cannot be empty");
        }

        $fieldDelimiter = trim($fieldDelimiter);
        if (strlen($fieldDelimiter) == 0) {
            throw new InvalidConfigException("field delimiter string cannot be empty");
        }
        $this->ruleDelimiter = $ruleDelimiter;
        $this->fieldDelimiter = $fieldDelimiter;
        $this->validateRules = FALSE;


        if (is_string($rules)) {
            $rules = explode($this->ruleDelimiter, $rules);
        }
        $this->unparsedRules = $rules;
    }

    /* Public methods */

    public function withValidation(): Checkout {
        $this->validateRules = TRUE;

        return $this;
    }

    public function buildRules(): Checkout {
        if (count($this->unparsedRules) > 0) {
            $this->parseRules($this->unparsedRules);
        } else {
            $this->rules = [];
        }
        return $this;
    }

    /**
     * Adds item to current checkout.
     * 
     * @param string $itemSku SKU of item
     * @param int $qty Number of items to add (defaults to 1)
     * @throws InvalidItemException Item could not be parsed, or we don't have a checkout rule for it.
     */
    public function add(string $itemSku, int $qty = 1): Checkout
    {
        if(!$this->rules) {
            $this->buildRules();
        }

        $itemQuantity = max((int) $qty, 1);

        // Check that we have a valid item code, otherwise raise exception.
        if (strlen(trim($itemSku)) < 1) {
            throw new InvalidItemException("Could not parse item code.");
        }

        // Let's normalize SKUs
        $itemSku = \strtoupper($itemSku);

        // Check that we have a rule for this item, otherwise raise exception.
        if (!array_key_exists($itemSku, $this->rules)) {
            // 2DO Write a custom Exception.
            throw new InvalidItemException("Item with code '$itemSku' is unknown.");
        }

        // Sum the quantity.
        $this->items[$itemSku] = ($this->items[$itemSku] ?? 0) + $itemQuantity;

        // We enable method chaining;
        return $this;
    }

    /**
     * Adds multiple items ([sku, [sku],[sku, qty],...]) to current checkout.
     * 
     * @param array $items array of SKUs, in plain format or with quantity
     * @throws InvalidItemException Item could not be parsed, or we don't have a checkout rule for it.
     */
    public function addMultiple(array $items): Checkout
    {
        if (!$this->rules) {
            $this->buildRules();
        }

        foreach($items as $item) {
            if (is_string($item)) {
                $this->add($item);
            } elseif (is_array($item) && count($item) > 0) {
                $this->add($item[0], (int) ($item[1] ?? 1));
            }
        }
        // We enable method chaining;
        return $this;
    }

    /**
     * Adds a single checkout rule.
     * 
     * @param string $sku SKU of the item to add
     * @param int $price price for the rule
     * @param int $quantity quantity associated to the rule (defaults to 1)
     * @throws InvalidRuleException If SKU, quantity and/or price are invalid.
     */
    public function addRule(string $sku, int $price, int $quantity = 1): Checkout {
        if(!$this->rules) {
            $this->rules = [];
        }

        $sku = trim($sku);

        if (strlen($sku) < 1 || $quantity < 1 || $price < 0) {
            // rule exception
            throw new InvalidRuleException("Checkout rules need a valid SKU, and quantity/price should be greater than 0");
        }

        if (!\array_key_exists ($sku, $this->rules) || \is_callable ($this->rules[$sku])) {
            // No rule for the SKU, or rule is a custom function (and we overwrite it with a rule array)
            $this->rules[$sku] = [
                $quantity => $price,
            ];
        } else {
            // We already have some rules for the SKU.
            $this->rules[$sku][$quantity] = $price;
        }

        // We enable method chaining;
        return $this;
    }
    
    /**
     * Adds a complex item-level price rule, backed by a callback function.
     * The callback function must accept a single int parameter (quantity)
     * and return a single int value (calculated price).
     * 
     * @param string $sku SKU of the item to add
     * @param callable $priceCallback custom logic to calculate price [function (int): int]
     * @throws InvalidRuleException
     */
    public function addCustomRule(string $sku, callable $priceCallback): Checkout {
        // We inspect the callable to make sure we can use it as rule.
        $reflection = new \ReflectionFunction($priceCallback);
        if ('int' != $reflection->getReturnType()) {
            throw new InvalidRuleException("Price rule callbacks must return a integer value");
        }

        if ($reflection->getNumberOfParameters() != 1 || 'int' != $reflection->getParameters()[0]->getType()) {
            throw new InvalidRuleException("Price rule callbacks must accept a single integer parameter");
        }

        $this->rules[$sku] = $priceCallback;

        // We enable method chaining;
        return $this;
    }

    /**
     * Adds a complex cart-level price modifier, backed by a callback function.
     * The callback function must accept two parameters (int currentPrice, array items) and return a int value (updated total)
     * and return a single int value (calculated price).
     * 
     * @param callable $totalCallback custom logic to modify checkout total [function (int, array): int]
     * @throws InvalidRuleException
     */
    public function addTotalModifier(callable $totalCallback): Checkout {
        // We inspect the callable to make sure we can use it as checkout rule.
        $reflection = new \ReflectionFunction($totalCallback);
        if ('int' != $reflection->getReturnType()) {
            throw new InvalidRuleException("Checkout general callbacks must return the updated total as an integer value");
        }

        if (
            $reflection->getNumberOfParameters() != 2 || 
            'int' != $reflection->getParameters()[0]->getType() ||
            'array' != $reflection->getParameters()[1]->getType()
        ) {
            throw new InvalidRuleException("Checkout general callbacks must accept two parameters: int \$currentTotal, array \$items");
        }

        $this->checkoutRules[] = $totalCallback;

        // We enable method chaining;
        return $this;
    }

    /**
     * Calculate checkout total based on current rules and custom total modifiers.
     * @return int Total price
     * @throws InvalidConfigException
     */
    public function total(): int
    {
        // No (valid) rules: raise an exception.
        if (!$this->rules || count($this->rules) < 1) {
            throw new InvalidConfigException("No pricing rules are set.");
        }

        // No items: total is 0.
        if (count($this->items) < 1) {
            return 0;
        }

        $items = collect($this->items);
        $rules = $this->rules;

        $checkoutTotal = $items->reduce(
            function ( ? int $checkoutTotal, int $quantity, string $sku) {
                /*
                Reverse-process the rules by quantity (higher quantities first).
                es: for SKU A we have 4 rules:
                - 1 x 50
                - 3 x 100 ("pay 2 get 3")
                - 5 x 200 ("pay 4 get 5")
                - 10 x 400 ("pay 8 get 10")
                we start parsing for chunks of 10 units, then 5, then 3, then we apply the single item price.
                 */

                if (is_callable($this->rules[$sku])) {
                    $itemTotal = $this->rules[$sku]($quantity);
                } else {
                    $itemQuantity = $quantity;
                    $itemTotal = collect($this->rules[$sku])
                    ->sortByDesc(
                        function (int $price, int $ruleQty) {
                            return $ruleQty;
                        }, SORT_NUMERIC)
                    ->reduce(
                        function ( ? int $itemTotal, int $rulePrice, int $ruleQty) use (&$itemQuantity) {

                            if ($itemQuantity == 0) {
                                // We break the parsing if item quantity is already 0.
                                return $itemTotal;
                            }

                            if ($itemQuantity < $ruleQty) {
                                // We don't have enough items left for this rule, let's skip to the next.
                                return $itemTotal;
                            }

                            $quantityClusters = (int) ($itemQuantity / $ruleQty);
                            $itemQuantity %= $ruleQty;

                            return $itemTotal + (int) ($quantityClusters * $rulePrice);
                        }
                    );

                    // Let's manage rule edge cases.
                    // We have residual quantity: checkout rules are missing some logic
                    if ($itemQuantity > 0) {
                        throw new InvalidConfigException("Cannot fully calculate total for item $sku, please review checkout rules.");
                    }
                }

                return $checkoutTotal + $itemTotal;
            }
        );

        // Do we have global rules?
        if (count($this->checkoutRules) > 0) {
            foreach($this->checkoutRules as $callable) {
                $checkoutTotal = $callable($checkoutTotal, $this->items);
            }
        }

        return (int) $checkoutTotal;
    }


    /* Internal methods */

    /**
     * Parse a set of checkout rules in string or array form.
     *
     * @param string|array $rules
     * @throws InvalidRuleException
     */
    protected function parseRules(array $rules): array
    {
        $parsedRules = [];

        collect($rules)->each(function (string|array $rule) {
            $ruleSku = NULL;
            $rulePrice = NULL;
            $ruleQuantity = NULL;
            if (is_array($rule) && 
                array_key_exists('sku', $rule) &&
                array_key_exists('price', $rule)
            ) {
                $ruleSku = trim($rule['sku']) ?? NULL;
                $rulePrice = (int) trim($rule['price']);
                $ruleQuantity = max((int) ($rule['quantity'] ?? 1), 1);
            } else {
                $rule = trim($rule);
                if(strlen($rule) == 0) {
                    return TRUE;
                }
                $fields = explode($this->fieldDelimiter, $rule);
                if (count($fields) < 2) {
                    if ($this->validateRules == TRUE) {
                        throw new InvalidRuleException("Invalid rule: '$rule'");
                    } else {
                        return TRUE;
                    }
                }
                $ruleSku = trim($fields[0]);
                $rulePrice = (int) trim($fields[1]);
                $ruleQuantity = max((int) ($fields[2] ?? 1), 1);
            }

            if(!$ruleSku || !$rulePrice || $rulePrice < 1) {
                // We don't have a valid rule: we can throw a breaking exception or just discard the rule.
                if ($this->validateRules == TRUE) {
                    if (is_array($rule)) {
                        $rule = json_encode($rule);
                    }
                    throw new InvalidRuleException("Invalid rule: '$rule'");
                }
            }
            // Let's normalize SKUs
            $ruleSku = \strtoupper ($ruleSku);
            $this->addRule($ruleSku, $rulePrice, $ruleQuantity);
        });

        return $parsedRules;
    }
}
