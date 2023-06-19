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

    protected array $rules = [];
    protected array $checkoutRules = [];
    protected array $items = [];

    /**
     * Builds a checkout from a set of rules,
     * @param string|array $rules String or array with a set of checkout rules.
     * @param string $ruleDelimiter Char or string used as rule separator (if rules are in single string form).
     * @param string $fieldDelimiter Char or string used as field delimiter within rules.
     * @throws \Exception Could not parse a valid set of rules.
     */

    public function __construct(string | array $rules, string $ruleDelimiter = self::DEFAULT_RULE_DELIMITER, string $fieldDelimiter = self::DEFAULT_FIELD_DELIMITER, bool $validateRules = TRUE)
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
        $this->validateRules = $validateRules;

        if (is_string($rules) || count($rules) > 0) {
            $this->parseRules($rules);
        }
    }

    /**
     * Adds item to current checkout.
     * @param string $item SKU of item
     * @param int $qty Number of items to add (defaults to 1)
     * @throws Exception Item could not be parsed, or we don't have a checkout rule for it.
     */
    public function add(string $item, int $qty = 1): Checkout
    {
        $itemSku = $item;
        $itemQuantity = max((int) $qty, 1);

        // Check that we have a valid item code, otherwise raise exception.
        if (strlen(trim($itemSku)) < 1) {
            // 2DO Write a custom Exception.
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
     * Calculate checkout total based on current rules.
     * @return int Total price
     */
    public function total()
    {
        // No (valid) rules: raise an exception.
        if (count($this->rules) < 1) {
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

    /**
     * Parse a set of checkout rules in string or array form.
     *
     * @param string|array $rules
     */
    protected function parseRules(string | array $rules)
    {
        $parsedRules = [];

        if (is_string($rules)) {
            $rules = explode($this->ruleDelimiter, $rules);
        }

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
                        return true;
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
            $this->addNumericRule($ruleSku, $ruleQuantity, $rulePrice);
        });

        return $parsedRules;
    }

    public function addNumericRule(string $sku, int $quantity, int $price) {
        if ($quantity < 1 || $price < 0) {
            // rule exception
            throw new InvalidRuleException("Checkout rules need quantity and price to be greater than 0");
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
    }
    
    public function addCustomRule(string $sku, callable $priceCallback) {
        // We inspect the callable to make sure we can use it as rule.
        $reflection = new \ReflectionFunction($priceCallback);
        if ('int' != $reflection->getReturnType()) {
            throw new InvalidRuleException("Price rule callbacks must return a integer value");
        }

        if ($reflection->getNumberOfParameters() != 1 || 'int' != $reflection->getParameters()[0]->getType()) {
            throw new InvalidRuleException("Price rule callbacks must accept a single integer parameter");
        }

        $this->rules[$sku] = $priceCallback;
    }

    public function addTotalModifier(callable $totalCallback) {
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
    }
}
