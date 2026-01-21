<?php

namespace Jobby\Tests;

use Jobby\Exception;
use PHPUnit\Framework\TestCase;

/**
 * @covers Jobby\Exception
 */
class ExceptionTest extends TestCase
{
    public function testInheritsBaseException(): void
    {
        $e = new Exception();
        $this->assertTrue($e instanceof \Exception);
    }
}
