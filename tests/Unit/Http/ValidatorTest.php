<?php

declare(strict_types=1);

namespace Fabriq\Tests\Unit\Http;

use PHPUnit\Framework\TestCase;
use Fabriq\Http\Validator;

final class ValidatorTest extends TestCase
{
    public function testRequiredRule(): void
    {
        $rules = ['name' => 'required'];

        $this->assertNotEmpty(Validator::validate(['name' => null], $rules));
        $this->assertNotEmpty(Validator::validate(['name' => ''], $rules));
        $this->assertNotEmpty(Validator::validate(['name' => []], $rules));
        $this->assertNotEmpty(Validator::validate([], $rules));
        $this->assertEmpty(Validator::validate(['name' => 'Alice'], $rules));
    }

    public function testStringRule(): void
    {
        $rules = ['val' => 'string'];

        $errors = Validator::validate(['val' => 123], $rules);
        $this->assertArrayHasKey('val', $errors);
        $this->assertStringContainsString('must be a string', $errors['val']);

        $this->assertEmpty(Validator::validate(['val' => 'hello'], $rules));
        $this->assertEmpty(Validator::validate(['val' => null], $rules));
    }

    public function testEmailRule(): void
    {
        $rules = ['email' => 'email'];

        $errors = Validator::validate(['email' => 'not-an-email'], $rules);
        $this->assertArrayHasKey('email', $errors);

        $this->assertEmpty(Validator::validate(['email' => 'user@example.com'], $rules));
        $this->assertEmpty(Validator::validate(['email' => null], $rules));
    }

    public function testIntRule(): void
    {
        $rules = ['age' => 'int'];

        $errors = Validator::validate(['age' => 'abc'], $rules);
        $this->assertArrayHasKey('age', $errors);

        $this->assertEmpty(Validator::validate(['age' => 42], $rules));
        $this->assertEmpty(Validator::validate(['age' => '7'], $rules));
        $this->assertEmpty(Validator::validate(['age' => null], $rules));
    }

    public function testMinMaxRules(): void
    {
        $this->assertNotEmpty(Validator::validate(['s' => 'ab'], ['s' => 'min:3']));
        $this->assertEmpty(Validator::validate(['s' => 'abc'], ['s' => 'min:3']));

        $this->assertNotEmpty(Validator::validate(['s' => 'abcdef'], ['s' => 'max:5']));
        $this->assertEmpty(Validator::validate(['s' => 'abcde'], ['s' => 'max:5']));

        $this->assertNotEmpty(Validator::validate(['n' => 2], ['n' => 'min:3']));
        $this->assertEmpty(Validator::validate(['n' => 3], ['n' => 'min:3']));

        $this->assertNotEmpty(Validator::validate(['n' => 6], ['n' => 'max:5']));
        $this->assertEmpty(Validator::validate(['n' => 5], ['n' => 'max:5']));

        $this->assertNotEmpty(Validator::validate(['a' => [1]], ['a' => 'min:2']));
        $this->assertEmpty(Validator::validate(['a' => [1, 2]], ['a' => 'min:2']));

        $this->assertNotEmpty(Validator::validate(['a' => [1, 2, 3]], ['a' => 'max:2']));
        $this->assertEmpty(Validator::validate(['a' => [1, 2]], ['a' => 'max:2']));
    }

    public function testInRule(): void
    {
        $rules = ['status' => 'in:active,inactive'];

        $errors = Validator::validate(['status' => 'banned'], $rules);
        $this->assertArrayHasKey('status', $errors);
        $this->assertStringContainsString('must be one of', $errors['status']);

        $this->assertEmpty(Validator::validate(['status' => 'active'], $rules));
        $this->assertEmpty(Validator::validate(['status' => 'inactive'], $rules));
    }

    public function testUuidRule(): void
    {
        $rules = ['id' => 'uuid'];

        $errors = Validator::validate(['id' => 'not-a-uuid'], $rules);
        $this->assertArrayHasKey('id', $errors);

        $this->assertEmpty(Validator::validate(
            ['id' => '550e8400-e29b-41d4-a716-446655440000'],
            $rules,
        ));
        $this->assertEmpty(Validator::validate(['id' => null], $rules));
    }

    public function testMultipleRulesWithPipe(): void
    {
        $rules = ['name' => 'required|string|min:3'];

        $errors = Validator::validate([], $rules);
        $this->assertSame('name is required', $errors['name']);

        $errors = Validator::validate(['name' => 123], $rules);
        $this->assertSame('name must be a string', $errors['name']);

        $errors = Validator::validate(['name' => 'ab'], $rules);
        $this->assertSame('name must be at least 3 characters', $errors['name']);

        $this->assertEmpty(Validator::validate(['name' => 'Alice'], $rules));
    }

    public function testNoErrorsOnValidData(): void
    {
        $rules = [
            'name'  => 'required|string|min:2|max:50',
            'email' => 'required|email',
            'age'   => 'int|min:0|max:150',
        ];

        $data = ['name' => 'Alice', 'email' => 'alice@example.com', 'age' => 30];

        $this->assertEmpty(Validator::validate($data, $rules));
    }

    public function testUnknownRuleIgnored(): void
    {
        $rules = ['name' => 'required|banana|string'];

        $this->assertEmpty(Validator::validate(['name' => 'Alice'], $rules));
    }
}
