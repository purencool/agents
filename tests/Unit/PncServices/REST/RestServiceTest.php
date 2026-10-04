<?php

namespace Tests\Unit\PncServices\REST;

use PHPUnit\Framework\TestCase;
use App\PncServices\REST\RestService;
use App\PncServices\Contracts\RestServiceInterface;

class RestServiceTest extends TestCase
{
    public function test_it_implements_interface(): void
    {
        $this->assertInstanceOf(RestServiceInterface::class, new RestService());
    }

    public function test_get_returns_array(): void
    {
        $service = new RestService();
        $result  = $service->get('/test');
        $this->assertIsArray($result);
    }

    public function test_post_returns_array(): void
    {
        $service = new RestService();
        $result  = $service->post('/test', ['key' => 'value']);
        $this->assertIsArray($result);
    }
}
