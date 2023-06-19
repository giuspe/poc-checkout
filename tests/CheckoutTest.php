<?php
namespace giusepperoccazzella\tests; 

use PHPUnit\Framework\TestCase;

use giusepperoccazzella\Checkout;
use giusepperoccazzella\exceptions\InvalidConfigException;
use giusepperoccazzella\exceptions\InvalidRuleException;
use giusepperoccazzella\exceptions\InvalidItemException;



class CheckoutTest extends TestCase
{
    protected string $defaultRulesAsString;
    protected array $defaultRulesAsArrayOfStrings;
    protected array $defaultRulesAsNestedArray;
    protected array $defaultCart;

    public function setUp(): void
    {
        $this->defaultRulesAsString = <<<RULES
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

        $this->defaultRulesAsArrayOfStrings = [
            "A|10",
            "A|20|3",
            "A|40|5",
            "A|80|10",
            "A|150|20",
            "B|5",
            "B|7|2",
            "C|10",
            "C|20|3",
            "B|15|4",
        ];
        $this->defaultRulesAsNestedArray = [
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
        $this->defaultInvalidRules = <<<RULES
            A;
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

        $this->defaultCart = [
            ['A', 1],
            ['A', 3],
            ['B', 2],
            ['C', 1],
            ['A', 1],
            ['B', 1],
        ];
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout rules as a single string.
     */
    public function with_rules_as_string()
    {
        $cart = $this->defaultCart;
        $checkout = new Checkout($this->defaultRulesAsString);
        foreach ($cart as $item) {
            $checkout->add($item[0], $item[1]);
        }
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout rules as array of strings.
     */
    public function with_rules_as_array_of_strings()
    {
        $cart = $this->defaultCart;
        $checkout = new Checkout($this->defaultRulesAsArrayOfStrings);
        foreach ($cart as $item) {
            $checkout->add($item[0], $item[1]);
        }
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout rules as array of arrays.
     */
    public function with_rules_as_array_of_arrays()
    {
        $cart = $this->defaultCart;
        $checkout = new Checkout($this->defaultRulesAsNestedArray);
        foreach ($cart as $item) {
            $checkout->add($item[0], $item[1]);
        }
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout using custom delimiters to parse the rules.
     */
    public function with_custom_rules_delimiters()
    {
        $cart = $this->defaultCart;
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
        foreach ($cart as $item) {
            $checkout->add($item[0], $item[1]);
        }
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Getting total on empty set of rules must fail.
     */
    public function with_empty_rules()
    {
        $this->expectException(InvalidConfigException::class);
        $checkout = new Checkout([]);
        $checkout->total();
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout using invalid item rules and validation must fail.
     */
    public function with_invalid_rules()
    {
        $this->expectException(InvalidRuleException::class);
        $checkout = (new Checkout($this->defaultInvalidRules))->withValidation()->buildRules();
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Checkout works using invalid item rules without validation.
     */
    public function with_invalid_rules_and_no_validation()
    {
        
        $cart = $this->defaultCart;
        $checkout = new Checkout($this->defaultInvalidRules, Checkout::DEFAULT_RULE_DELIMITER, Checkout::DEFAULT_FIELD_DELIMITER);
        foreach ($cart as $item) {
            $checkout->add($item[0], $item[1]);
        }
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Total calculation with unknown item fails.
     */
    public function with_invalid_item()
    {
        $this->expectException(InvalidItemException::class);
        $checkout = (new Checkout($this->defaultRulesAsString))->withValidation();
        $checkout
            ->add('A', 1)
            ->add('A', 3)
            ->add('D', 1);
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Empty cart must return a total of 0.
     */
    public function with_empty_cart()
    {
        $checkout = (new Checkout($this->defaultRulesAsString))->buildRules();
        $this->assertEquals(0, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Is possible to add items by chaining ->add calls.
     */
    public function with_method_chaining()
    {
        $checkout = new Checkout($this->defaultRulesAsString);
        $checkout
            ->add('A', 1)
            ->add('A', 3)
            ->add('B', 2)
            ->add('C', 1)
            ->add('A', 1)
            ->add('B', 1);
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Is possible to add multiple items as array, with chaining.
     */
    public function add_items_with_array_and_chaining()
    {
        $checkout = new Checkout($this->defaultRulesAsString);
        $items = [
            'A',
            ['A', 3],
            ['B', 2],
            ['C'],
            ['A', 1]
        ];
        $checkout->addMultiple($items)->add('B');
        $this->assertEquals(62, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Can use a custom closure as SKU price rule.
     */
    public function with_rule_callbacks()
    {
        $checkout = new Checkout([]);
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
        $checkout
            ->add('A', 40)
            ->add('A', 5)
            ->add('B', 10)
            ->add('A', 10)
            ->add('B', 10);
        $this->assertEquals(165 + 500, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Custom SKU callback must have specific parameters and return value.
     */
    public function with_invalid_rule_callback()
    {
        $this->expectException(InvalidRuleException::class);

        $checkout = new Checkout([]);
        $checkout->addCustomRule('A', function(int $qty): int {
            return 10 * $qty;
        });
        $checkout->addCustomRule('B', function(int $qty) {
            if ($qty >= 50) {
                return (int) $qty * 5;
            } elseif ($qty < 50 && $qty >= 10) {
                return (int) $qty * 25;
            } else {
                return (int) $qty * 50;
            }
        });
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Custom closure can be used as checkout total modifier.
     */
    public function with_total_callbacks()
    {
        $checkout = new Checkout([]);
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
        $checkout
            ->add('A', 40)
            ->add('A', 5)
            ->add('B', 10)
            ->add('A', 10)
            ->add('B', 10);

        $checkout->addTotalModifier(function(int $total, array $items): int {
            if ($total > 500) {
                $total = (int) ($total * 0.70);
            } elseif ($total > 250) {
                $total = (int) ($total * 0.85);
            }
            return $total;
        });
        $checkout->addTotalModifier(function(int $total, array $items): int {
            if (array_key_exists('B', $items) && (int) $items['B'] > 5 && $total > 100) {
                $total = (int) ($total - 20);
            }
            
            if (array_key_exists('B', $items) && array_key_exists('C', $items) && $total > 10) {
                $total = (int) ($total - 5);
            }
            return $total;
        });
        $this->assertEquals(445, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Custom checkout total modifier must have specific input parameters and return value.
     */
    public function with_invalid_total_callback()
    {
        $this->expectException(InvalidRuleException::class);

        $checkout = new Checkout([]);
        $checkout->addCustomRule('A', function(int $qty): int {
            return $qty * 10;
        });

        $checkout->addTotalModifier(function(int $total): int {
            if ($total > 500) {
                $total = (int) ($total * 0.70);
            } elseif ($total > 250) {
                $total = (int) ($total * 0.85);
            }
            return $total;
        });
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Conflicting SKU rules don't invalidate total calculation (last one is used).
     */
    public function with_overwritten_rules()
    {
        $rules = <<<RULES
            A|10;
            A|20|3;
            A|40|5;
            B|5;
            B|7|2;
            C|10;
            A|30|5;
        RULES;

        $checkout = new Checkout($rules);
        $checkout
            ->add('A')
            ->add('A', 3)
            ->add('B', 2)
            ->add('C')
            ->add('A')
            ->add('B');
        $this->assertEquals(52, $checkout->total());
    }

    /**
     * @test
     * @covers Checkout
     * @testdox Missing SKU rule for single unit fails total calculation (if the other rules don't cover item quantity).
     */
    public function with_missing_rule()
    {
        $this->expectException(InvalidConfigException::class);

        // we don't set any rule for a single 'A' item, total calculation will be inconsistemt
        $rules = <<<RULES
            A|20|3;
            A|40|5;
            B|5;
            B|7|2;
            C|10;
            A|30|5;
        RULES;

        $checkout = new Checkout($rules);
        $checkout
            ->add('A')
            ->add('A', 2)
            ->add('C')
            ->add('A')
            ->add('B');
        $checkout->total();
    }
}
