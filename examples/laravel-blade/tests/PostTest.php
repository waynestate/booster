<?php

declare(strict_types=1);

namespace App\Tests;

use App\Models\Post;
use PHPUnit\Framework\TestCase;

/**
 * A legacy PHPUnit 10 test. Each method carries one pattern that a BOOSTER
 * PHPUnit hop rewrites, so the contract can prove the hop actually changes
 * the file: createMock() on a property that is never mocked (10 to 11),
 * isType() (11 to 12) and the any() matcher (12 to 13).
 */
final class PostTest extends TestCase
{
    private \PHPUnit\Framework\MockObject\MockObject $repository;

    protected function setUp(): void
    {
        $this->repository = $this->createMock(Post::class);
    }

    public function testItFindsAPost(): void
    {
        $this->assertThat([new Post()], $this->isType('array'));
    }

    public function testItAcceptsAnyInvokedCount(): void
    {
        $matcher = $this->any();
        $this->assertNotNull($matcher);
    }
}
