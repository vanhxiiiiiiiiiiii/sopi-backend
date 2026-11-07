<?php

namespace Tests\Unit;

use App\Enums\OrderStatus;
use PHPUnit\Framework\TestCase;

class OrderStatusTest extends TestCase
{
    public function test_order_status_ids_match_the_database_seed_data(): void
    {
        $this->assertSame(1, OrderStatus::Processing->value);
        $this->assertSame(2, OrderStatus::Ready->value);
        $this->assertSame(3, OrderStatus::Delivering->value);
        $this->assertSame(4, OrderStatus::Delivered->value);
        $this->assertSame(5, OrderStatus::Cancelled->value);
        $this->assertSame(6, OrderStatus::Return->value);
    }
}
