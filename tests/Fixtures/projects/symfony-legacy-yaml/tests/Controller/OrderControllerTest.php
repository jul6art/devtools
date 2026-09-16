<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class OrderControllerTest extends WebTestCase
{
    public function testTheOrderListIsReachable(): void
    {
        $client = static::createClient();
        $client->request('GET', '/orders');

        self::assertResponseIsSuccessful();
    }
}
