<?php

namespace Tests\Feature;

use App\Http\Controllers\OrderController;
use Tests\TestCase;

/**
 * @see OrderController
 */
final class OrderControllerTest extends TestCase
{
    public function testTheOrdersNeedALogin(): void
    {
        $this->get('/orders')->assertRedirect();
    }
}
