<?php

declare(strict_types=1);

namespace Breakfast\Tests\Unit;

use Breakfast\Platform\Forms\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredFieldsFail(): void
    {
        $v = new Validator();
        $ok = $v->validate(['name' => ''], ['name' => ['required' => true]], ['name.required' => 'Name required']);

        $this->assertFalse($ok);
        $this->assertSame('Name required', $v->errors()['name']);
    }

    public function testEmailValidation(): void
    {
        $v = new Validator();
        $this->assertFalse($v->validate(['email' => 'nope'], ['email' => ['type' => 'email', 'required' => true]]));
        $this->assertTrue($v->validate(['email' => 'a@b.co'], ['email' => ['type' => 'email', 'required' => true]]));
    }

    public function testOptionalEmptyPasses(): void
    {
        $v = new Validator();
        $this->assertTrue($v->validate(['company' => ''], ['company' => ['max' => 100]]));
    }

    public function testMaxLengthEnforced(): void
    {
        $v = new Validator();
        $this->assertFalse($v->validate(['message' => str_repeat('x', 20)], ['message' => ['max' => 10]]));
    }

    public function testUrlAcceptsBareDomain(): void
    {
        $v = new Validator();
        $this->assertTrue($v->validate(['website' => 'example.co.uk'], ['website' => ['type' => 'url']]));
        $this->assertFalse($v->validate(['website' => 'not a url'], ['website' => ['type' => 'url']]));
    }

    public function testInConstraint(): void
    {
        $v = new Validator();
        $this->assertFalse($v->validate(['choice' => 'z'], ['choice' => ['in' => ['a', 'b']]]));
        $this->assertTrue($v->validate(['choice' => 'a'], ['choice' => ['in' => ['a', 'b']]]));
    }
}
